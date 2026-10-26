import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

/// Resposta simulada: estado + corpo JSON.
class FakeReply {
  FakeReply(this.status, [this.body = const <String, Object?>{}]);

  final int status;
  final Object? body;
}

/// Adaptador HTTP de teste: responde por ordem às regras e regista os pedidos feitos.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.handler);

  final FakeReply Function(RequestOptions options) handler;
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    final reply = handler(options);
    return ResponseBody.fromString(jsonEncode(reply.body), reply.status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}

Dio fakeDio(FakeAdapter adapter) => Dio(BaseOptions(baseUrl: 'http://api.test/api/v1'))..httpClientAdapter = adapter;
