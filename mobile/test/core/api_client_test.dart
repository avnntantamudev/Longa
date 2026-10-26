import 'package:flutter_test/flutter_test.dart';
import 'package:longa/core/api/api_client.dart';
import 'package:longa/core/api/api_error.dart';
import 'package:longa/core/storage/secure_store.dart';

import '../support/fake_adapter.dart';

void main() {
  late TokenStore tokens;

  setUp(() async {
    tokens = TokenStore(MemoryKeyValueStore());
    await tokens.saveSession(access: 'old-access', refresh: 'refresh-1');
    await tokens.saveDevice('d' * 64);
  });

  test('envia o token de acesso e o token do aparelho', () async {
    final adapter = FakeAdapter((_) => FakeReply(200, {'data': {'ok': true}}));
    final client = ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter));

    final body = await client.get('/me');

    expect(body['data'], {'ok': true});
    expect(adapter.requests.single.headers['Authorization'], 'Bearer old-access');
    expect(adapter.requests.single.headers['X-Device-Token'], 'd' * 64);
  });

  test('401: renova a sessão uma vez e repete o pedido com o token novo', () async {
    final adapter = FakeAdapter((o) {
      if (o.path == '/auth/token/refresh') return FakeReply(200, {'access_token': 'new-access', 'refresh_token': 'refresh-2'});
      return o.headers['Authorization'] == 'Bearer new-access' ? FakeReply(200, {'data': 1}) : FakeReply(401, {'error': {'code': 'unauthenticated', 'message': 'É necessário iniciar sessão.'}});
    });
    final client = ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter));

    final body = await client.get('/sessions');

    expect(body['data'], 1);
    expect(tokens.accessToken, 'new-access');
    expect(tokens.refreshToken, 'refresh-2');
    expect(adapter.requests.where((r) => r.path == '/auth/token/refresh').length, 1);
  });

  test('refresh recusado: limpa a sessão, avisa e devolve o erro', () async {
    var expired = false;
    final adapter = FakeAdapter((o) => o.path == '/auth/token/refresh'
        ? FakeReply(401, {'error': {'code': 'refresh_token_invalid', 'message': 'A sessão expirou.'}})
        : FakeReply(401, {'error': {'code': 'unauthenticated', 'message': 'É necessário iniciar sessão.'}}));
    final client = ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter), onSessionExpired: () => expired = true);

    await expectLater(client.get('/sessions'), throwsA(isA<ApiError>().having((e) => e.status, 'status', 401)));
    expect(expired, isTrue);
    expect(tokens.accessToken, isNull);
    expect(tokens.deviceToken, 'd' * 64); // o aparelho continua de confiança
  });

  test('423 pin_required: pede o PIN e repete com a mesma Idempotency-Key', () async {
    var asked = 0;
    final adapter = FakeAdapter((o) {
      final pinConfirmed = o.extra['longa_pin_retried'] == true;
      return pinConfirmed ? FakeReply(200, {'data': {'state': 'scheduled'}}) : FakeReply(423, {'error': {'code': 'pin_required', 'message': 'Confirme com o seu PIN para continuar.'}});
    });
    final client = ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter), pinRequester: () async {
      asked++;
      return true;
    });

    final body = await client.post('/sessions/7/pay', data: {'method': 'multicaixa_express'}, idempotencyKey: 'key-123');

    expect(asked, 1);
    expect((body['data'] as Map)['state'], 'scheduled');
    expect(adapter.requests.map((r) => r.headers['Idempotency-Key']).toSet(), {'key-123'});
  });

  test('PIN cancelado: erro pin_cancelled sem repetir', () async {
    final adapter = FakeAdapter((_) => FakeReply(423, {'error': {'code': 'pin_required', 'message': 'Confirme com o seu PIN.'}}));
    final client = ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter), pinRequester: () async => false);

    await expectLater(client.post('/me/vip/purchase', idempotencyKey: 'k'), throwsA(isA<ApiError>().having((e) => e.code, 'code', 'pin_cancelled')));
    expect(adapter.requests.length, 1);
  });

  test('erros de validação chegam por campo', () async {
    final adapter = FakeAdapter((_) => FakeReply(422, {
          'error': {'code': 'validation_failed', 'message': 'Os dados enviados são inválidos.', 'details': {'pin': ['Não use números seguidos (ex.: 123456).']}},
        }));
    final client = ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter));

    try {
      await client.post('/auth/pin/setup', data: {'pin': '123456'});
      fail('devia falhar');
    } on ApiError catch (e) {
      expect(e.fieldErrors['pin'], 'Não use números seguidos (ex.: 123456).');
      expect(e.userMessage, 'Não use números seguidos (ex.: 123456).');
    }
  });
}
