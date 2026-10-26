import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/providers.dart';
import 'session_models.dart';

final sessionsRepositoryProvider = Provider<SessionsRepository>((ref) => SessionsRepository(ref.watch(apiClientProvider)));

/// Aulas: execução, pagamento, QR, cancelamentos, disputas e chat.
class SessionsRepository {
  SessionsRepository(this.api);

  final ApiClient api;

  Future<List<TrainingSession>> list() async {
    final body = await api.get('/sessions');
    return ((body['data'] as List?) ?? const []).map((s) => TrainingSession.fromJson(s as Map<String, dynamic>)).toList();
  }

  Future<TrainingSession> show(int id) async => TrainingSession.fromJson((await api.get('/sessions/$id'))['data'] as Map<String, dynamic>);

  Future<List<SessionEventItem>> events(int id) async {
    final body = await api.get('/sessions/$id/events');
    return ((body['data'] as List?) ?? const []).map((e) => SessionEventItem.fromJson(e as Map<String, dynamic>)).toList().reversed.toList();
  }

  /// Ações com dinheiro ou irreversíveis levam Idempotency-Key (a mesma em repetições).
  Future<TrainingSession> act(int id, String action, {Map<String, dynamic>? data, required String idempotencyKey}) async {
    final body = await api.post('/sessions/$id/$action', data: data, idempotencyKey: idempotencyKey);
    return TrainingSession.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<TrainingSession> start(int id) async => TrainingSession.fromJson((await api.post('/sessions/$id/start'))['data'] as Map<String, dynamic>);

  /// Terminar (formador) devolve o primeiro lote de QR.
  Future<QrBatch> finish(int id) async => QrBatch.fromJson({...((await api.post('/sessions/$id/finish'))['qr'] as Map<String, dynamic>)});

  Future<({TrainingSession session, QrBatch batch})> qr(int id) async {
    final body = await api.post('/sessions/$id/qr');
    return (session: TrainingSession.fromJson(body['data'] as Map<String, dynamic>), batch: QrBatch.fromJson(body['qr'] as Map<String, dynamic>));
  }

  /// Confirmar com QR (token) ou código de 6 dígitos. Devolve a mensagem do servidor.
  Future<String> confirm(int id, {String? token, String? code, double? latitude, double? longitude, required String idempotencyKey}) async {
    final body = await api.post('/sessions/$id/confirm', data: {
      if (token != null) 'token': token,
      if (code != null) 'code': code,
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
    }, idempotencyKey: idempotencyKey);
    return (body['message'] ?? 'Aula confirmada.') as String;
  }

  Future<void> openDispute(int id, {required String reason, required String description, required List<String> filePaths, required String idempotencyKey}) async {
    final form = FormData.fromMap({
      'reason': reason,
      'description': description,
      'attachments[]': [for (final path in filePaths) await MultipartFile.fromFile(path)],
    });
    await api.post('/sessions/$id/disputes', data: form, idempotencyKey: idempotencyKey);
  }

  Future<List<ChatMessage>> messages(int id) async {
    final body = await api.get('/sessions/$id/messages');
    return ((body['data'] as List?) ?? const []).map((m) => ChatMessage.fromJson(m as Map<String, dynamic>)).toList();
  }

  Future<ChatMessage> send(int id, String text, String clientId) async =>
      ChatMessage.fromJson((await api.post('/sessions/$id/messages', data: {'body': text, 'client_id': clientId}))['data'] as Map<String, dynamic>);
}

class ChatMessage {
  ChatMessage({required this.id, required this.senderId, required this.senderName, required this.body, required this.at});

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    final sender = (json['sender'] as Map?) ?? const {};
    return ChatMessage(
      id: json['id'] as int,
      senderId: (sender['id'] as num?)?.toInt() ?? 0,
      senderName: sender['name'] as String?,
      body: (json['body'] ?? (json['attachment'] != null ? '📎 PDF anexado' : '')) as String,
      at: DateTime.parse(json['created_at'] as String),
    );
  }

  final int id;
  final int senderId;
  final String? senderName;
  final String body;
  final DateTime at;
}
