import 'dart:async';

import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

import '../config.dart';
import '../storage/secure_store.dart';
import 'api_error.dart';

/// Pede o PIN ao utilizador (devolve true se o servidor o confirmou).
typedef PinRequester = Future<bool> Function();

/// Cliente da API /api/v1.
///
///  - Bearer token de acesso em todos os pedidos; `X-Device-Token` para as regras do PIN.
///  - 401 → renova a sessão com o refresh token (uma vez, em série) e repete o pedido.
///  - 423 `pin_required` (pagamentos) → pede o PIN e repete o mesmo pedido, com a mesma
///    Idempotency-Key (o servidor não cobra duas vezes).
///  - Todos os erros chegam como [ApiError].
class ApiClient {
  ApiClient({required this.tokens, Dio? dio, Dio? refreshDio, this.onSessionExpired, PinRequester? pinRequester})
      : dio = dio ?? Dio(_options()),
        _refreshDio = refreshDio ?? Dio(_options()) {
    this.pinRequester = pinRequester;
    this.dio.interceptors.add(_AuthInterceptor(this));
  }

  final TokenStore tokens;
  final Dio dio;
  final Dio _refreshDio;

  /// Chamado quando a sessão não pode ser renovada (voltar ao ecrã de entrada).
  void Function()? onSessionExpired;

  /// Ligado pela UI (janela do PIN).
  PinRequester? pinRequester;

  static BaseOptions _options() => BaseOptions(
        baseUrl: AppConfig.apiBase,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 30),
        headers: {'Accept': 'application/json'},
      );

  /// Uma chave por ação do utilizador; reutilizar ao repetir depois de falha de rede.
  static String newIdempotencyKey() => const Uuid().v4();

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) => _send(() => dio.get<dynamic>(path, queryParameters: query));

  Future<Map<String, dynamic>> post(String path, {Object? data, String? idempotencyKey}) =>
      _send(() => dio.post<dynamic>(path, data: data, options: _withKey(idempotencyKey)));

  Future<Map<String, dynamic>> put(String path, {Object? data}) => _send(() => dio.put<dynamic>(path, data: data));

  Future<Map<String, dynamic>> patch(String path, {Object? data}) => _send(() => dio.patch<dynamic>(path, data: data));

  Future<Map<String, dynamic>> delete(String path) => _send(() => dio.delete<dynamic>(path));

  Options? _withKey(String? key) => key == null ? null : Options(headers: {'Idempotency-Key': key});

  Future<Map<String, dynamic>> _send(Future<Response<dynamic>> Function() call) async {
    try {
      final response = await call();
      final body = response.data;
      if (body is Map<String, dynamic>) return body;
      if (body is Map) return body.map((k, v) => MapEntry(k.toString(), v));
      return <String, dynamic>{};
    } on DioException catch (e) {
      throw toApiError(e);
    }
  }

  static ApiError toApiError(DioException e) {
    if (e.error is ApiError) return e.error! as ApiError;
    final response = e.response;
    if (response == null) return ApiError.network();
    return ApiError.fromBody(response.statusCode ?? 0, response.data);
  }

  /// Renova a sessão. Devolve false se o refresh token já não servir.
  Future<bool> refreshSession() async {
    final refresh = tokens.refreshToken;
    if (refresh == null) return false;
    try {
      final response = await _refreshDio.post<dynamic>('/auth/token/refresh', data: {'refresh_token': refresh});
      final body = response.data as Map;
      await tokens.saveSession(access: body['access_token'] as String, refresh: body['refresh_token'] as String);
      return true;
    } on DioException {
      return false;
    }
  }
}

class _AuthInterceptor extends QueuedInterceptor {
  _AuthInterceptor(this.client);

  final ApiClient client;

  static const _retriedKey = 'longa_retried';
  static const _pinRetriedKey = 'longa_pin_retried';

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final access = client.tokens.accessToken;
    if (access != null && !options.headers.containsKey('Authorization')) {
      options.headers['Authorization'] = 'Bearer $access';
    }
    final device = client.tokens.deviceToken;
    if (device != null) options.headers['X-Device-Token'] = device;
    handler.next(options);
  }

  @override
  Future<void> onError(DioException err, ErrorInterceptorHandler handler) async {
    final response = err.response;
    final options = err.requestOptions;
    final isAuthCall = options.path.startsWith('/auth/otp') || options.path.startsWith('/auth/pin/login') || options.path.startsWith('/auth/token');

    // Sessão expirada: renovar uma vez e repetir
    if (response?.statusCode == 401 && !isAuthCall && options.extra[_retriedKey] != true) {
      if (await client.refreshSession()) {
        options.extra[_retriedKey] = true;
        options.headers['Authorization'] = 'Bearer ${client.tokens.accessToken}';
        return _retry(options, handler, err);
      }
      await client.tokens.clearSession();
      client.onSessionExpired?.call();
    }

    // Pagamento: pedir o PIN e repetir o mesmo pedido
    final error = response == null ? null : ApiError.fromBody(response.statusCode ?? 0, response.data);
    if (response?.statusCode == 423 && error?.code == 'pin_required' && options.extra[_pinRetriedKey] != true && client.pinRequester != null) {
      final confirmed = await client.pinRequester!();
      if (confirmed) {
        options.extra[_pinRetriedKey] = true;
        return _retry(options, handler, err);
      }
      return handler.reject(DioException(requestOptions: options, response: response, error: ApiError(423, 'pin_cancelled', 'Operação cancelada.')));
    }

    handler.next(err);
  }

  /// Repete com o Dio sem interceptores: repetir pelo mesmo Dio a partir deste interceptor em série
  /// bloquearia se o pedido repetido também falhasse.
  Future<void> _retry(RequestOptions options, ErrorInterceptorHandler handler, DioException original) async {
    try {
      final response = await client._refreshDio.fetch<dynamic>(options);
      handler.resolve(response);
    } on DioException catch (e) {
      handler.next(e);
    }
  }
}
