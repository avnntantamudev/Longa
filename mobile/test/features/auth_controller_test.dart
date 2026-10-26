import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:longa/core/api/api_client.dart';
import 'package:longa/core/providers.dart';
import 'package:longa/core/storage/secure_store.dart';
import 'package:longa/features/auth/auth_controller.dart';

import '../support/fake_adapter.dart';

Map<String, dynamic> userJson({bool pinSet = true, bool onboarding = false}) => {
      'id': 1,
      'name': 'Ana Silva',
      'phone': '+244923456789',
      'roles': ['student'],
      'needs_onboarding': onboarding,
      'pin_set': pinSet,
    };

/// Contentor com armazenamento em memória e API simulada.
Future<(ProviderContainer, TokenStore, FakeAdapter)> setup(FakeReply Function(String path, Map<String, dynamic>? body) handler, {Future<void> Function(TokenStore)? seed}) async {
  final tokens = TokenStore(MemoryKeyValueStore());
  if (seed != null) await seed(tokens);
  final adapter = FakeAdapter((o) => handler(o.path, o.data is Map ? (o.data as Map).cast<String, dynamic>() : null));
  final container = ProviderContainer(overrides: [
    tokenStoreProvider.overrideWithValue(tokens),
    apiClientProvider.overrideWithValue(ApiClient(tokens: tokens, dio: fakeDio(adapter), refreshDio: fakeDio(adapter))),
  ]);
  addTearDown(container.dispose);
  return (container, tokens, adapter);
}

Future<AuthState> settle(ProviderContainer c) async {
  c.read(authControllerProvider);
  for (var i = 0; i < 200 && c.read(authControllerProvider).stage == AuthStage.loading; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 5));
  }
  return c.read(authControllerProvider);
}

void main() {
  test('sem nada guardado: número + código', () async {
    final (c, _, _) = await setup((_, __) => FakeReply(500));
    expect((await settle(c)).stage, AuthStage.signedOut);
  });

  test('aparelho de confiança sem sessão: entra com PIN e mostra o nome guardado', () async {
    final (c, _, _) = await setup((_, __) => FakeReply(500), seed: (t) async {
      await t.saveDevice('d' * 64);
      await t.saveIdentity(name: 'Ana Silva', phone: '+244 9•• ••• 789');
    });

    final state = await settle(c);
    expect(state.stage, AuthStage.pinLogin);
    expect(state.name, 'Ana Silva');
  });

  test('primeiro código: guarda tokens e o aparelho e pede para criar o PIN; depois entra', () async {
    var pinSet = false;
    final (c, tokens, _) = await setup((path, body) => switch (path) {
          '/auth/otp/verify' => FakeReply(201, {'access_token': 'a1', 'refresh_token': 'r1', 'device_token': 'x' * 64, 'pin_set': false, 'created': true, 'user': userJson(pinSet: false)}),
          '/auth/pin/setup' => () {
              pinSet = true;
              return FakeReply(200, {'data': {'pin_set': true}});
            }(),
          '/me' => FakeReply(200, {'data': userJson(pinSet: pinSet)}),
          _ => FakeReply(404),
        });
    await settle(c);

    await c.read(authControllerProvider.notifier).verifyCode('+244923456789', '123456');
    expect(c.read(authControllerProvider).stage, AuthStage.pinSetup);
    expect(tokens.deviceToken, 'x' * 64);
    expect(tokens.accessToken, 'a1');

    await c.read(authControllerProvider.notifier).setupPin('482916');
    expect(c.read(authControllerProvider).stage, AuthStage.signedIn);
  });

  test('sessão guardada mas mais de 1 hora sem uso: pede o PIN; desbloquear entra', () async {
    final (c, _, _) = await setup((path, _) => switch (path) {
          '/me' => FakeReply(200, {'data': userJson()}),
          '/auth/pin/verify' => FakeReply(200, {'data': {'verified': true}}),
          _ => FakeReply(404),
        }, seed: (t) async {
      await t.saveSession(access: 'a', refresh: 'r');
      await t.touch(DateTime.now().subtract(const Duration(hours: 2)));
    });

    expect((await settle(c)).stage, AuthStage.locked);
    await c.read(authControllerProvider.notifier).unlock('482916');
    expect(c.read(authControllerProvider).stage, AuthStage.signedIn);
  });

  test('aparelho revogado ao entrar com PIN: esquece o aparelho e volta ao código', () async {
    final (c, tokens, _) = await setup((path, _) => path == '/auth/pin/login'
        ? FakeReply(401, {'error': {'code': 'device_not_trusted', 'message': 'Por segurança, entre com o código SMS.'}})
        : FakeReply(404), seed: (t) => t.saveDevice('d' * 64));
    await settle(c);

    await expectLater(c.read(authControllerProvider.notifier).loginWithPin('000000'), throwsA(anything));
    expect(c.read(authControllerProvider).stage, AuthStage.signedOut);
    expect(tokens.deviceToken, isNull);
  });

  test('"Esqueci o PIN": depois do código vai criar um PIN novo mesmo já tendo PIN', () async {
    final (c, _, _) = await setup((path, _) => path == '/auth/otp/verify'
        ? FakeReply(200, {'access_token': 'a', 'refresh_token': 'r', 'device_token': 'y' * 64, 'pin_set': true, 'created': false, 'user': userJson()})
        : FakeReply(404));
    await settle(c);

    c.read(authControllerProvider.notifier).forgotPin();
    await c.read(authControllerProvider.notifier).verifyCode('+244923456789', '123456');

    expect(c.read(authControllerProvider).stage, AuthStage.pinSetup);
    expect(c.read(authControllerProvider).resetPin, isTrue);
  });
}
