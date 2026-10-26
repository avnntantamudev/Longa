import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_error.dart';
import '../../core/config.dart';
import '../../core/providers.dart';
import 'auth_repository.dart';
import 'user.dart';

enum AuthStage {
  /// A ler o armazenamento / a validar a sessão.
  loading,

  /// Sem sessão e sem aparelho de confiança: número + código.
  signedOut,

  /// Aparelho de confiança: entra só com o PIN.
  pinLogin,

  /// Acabou de entrar com o código e ainda não tem PIN (ou pediu para repor).
  pinSetup,

  /// Sessão válida mas mais de 1 hora sem uso: pedir o PIN.
  locked,

  /// Falta o registo (nome e perfil).
  onboarding,

  signedIn,
}

class AuthState {
  const AuthState(this.stage, {this.user, this.name, this.maskedPhone, this.resetPin = false});

  final AuthStage stage;
  final AppUser? user;

  /// Para "Olá, Ana" no ecrã do PIN antes de haver sessão.
  final String? name;
  final String? maskedPhone;

  /// Veio de "Esqueci o PIN": depois do código cria um PIN novo.
  final bool resetPin;

  AuthState copyWith({AuthStage? stage, AppUser? user, bool? resetPin}) =>
      AuthState(stage ?? this.stage, user: user ?? this.user, name: name, maskedPhone: maskedPhone, resetPin: resetPin ?? this.resetPin);
}

final authRepositoryProvider = Provider<AuthRepository>((ref) => AuthRepository(ref.watch(apiClientProvider), ref.watch(tokenStoreProvider)));

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Ciclo de vida da autenticação na app (espelha a PWA: código uma vez por aparelho, depois PIN;
/// PIN ao fim de 1 hora sem uso).
class AuthController extends Notifier<AuthState> {
  AuthRepository get _repo => ref.read(authRepositoryProvider);

  @override
  AuthState build() {
    final api = ref.read(apiClientProvider);
    api.onSessionExpired = _onSessionExpired;
    Future.microtask(restore);
    return const AuthState(AuthStage.loading);
  }

  /// Arranque: decide o primeiro ecrã.
  Future<void> restore() async {
    final tokens = ref.read(tokenStoreProvider);
    if (tokens.accessToken == null) {
      state = _signedOutState();
      return;
    }

    try {
      final user = await _repo.me();
      final last = await tokens.lastActive();
      final idle = last == null || DateTime.now().difference(last) > AppConfig.pinIdle;
      state = _afterLogin(user, locked: idle && user.pinSet);
    } on ApiError {
      // Sessão inválida ou sem rede: ecrã de entrada (com PIN, se o aparelho for de confiança)
      state = _signedOutState();
    }
  }

  Future<int> requestCode(String phone, String channel) => _repo.requestCode(phone, channel: channel);

  Future<void> verifyCode(String phone, String code) async {
    final login = await _repo.verifyCode(phone, code);
    final needsPin = !login.pinSet || state.resetPin;
    await ref.read(tokenStoreProvider).touch();
    state = needsPin ? AuthState(AuthStage.pinSetup, user: login.user, resetPin: state.resetPin) : _afterLogin(login.user);
  }

  Future<void> setupPin(String pin) async {
    await _repo.setupPin(pin);
    final user = await _repo.me();
    await ref.read(tokenStoreProvider).touch();
    state = _afterLogin(user);
  }

  Future<void> loginWithPin(String pin) async {
    try {
      final user = await _repo.loginWithPin(pin);
      await ref.read(tokenStoreProvider).touch();
      state = _afterLogin(user);
    } on ApiError catch (e) {
      // Aparelho já não é de confiança: volta ao número + código
      if (e.code == 'device_not_trusted') {
        await ref.read(tokenStoreProvider).clearAll();
        state = const AuthState(AuthStage.signedOut);
      }
      rethrow;
    }
  }

  Future<void> unlock(String pin) async {
    await _repo.verifyPin(pin);
    await ref.read(tokenStoreProvider).touch();
    state = state.copyWith(stage: state.user?.needsOnboarding == true ? AuthStage.onboarding : AuthStage.signedIn);
  }

  /// Chamado pelo ciclo de vida da app ao voltar ao primeiro plano.
  Future<void> checkIdle() async {
    if (state.stage != AuthStage.signedIn) return;
    final last = await ref.read(tokenStoreProvider).lastActive();
    if (last != null && DateTime.now().difference(last) > AppConfig.pinIdle) {
      state = state.copyWith(stage: AuthStage.locked);
    }
  }

  /// Regista uso (chamado ao navegar/tocar).
  Future<void> touch() => ref.read(tokenStoreProvider).touch();

  Future<void> completeOnboarding(String name, List<String> profiles) async {
    final user = await _repo.completeOnboarding(name: name, profiles: profiles);
    state = AuthState(AuthStage.signedIn, user: user);
  }

  Future<void> refreshUser() async {
    final user = await _repo.me();
    state = state.copyWith(user: user);
  }

  /// "Esqueci o PIN": volta ao número + código e, depois, cria um PIN novo.
  void forgotPin() => state = const AuthState(AuthStage.signedOut, resetPin: true);

  /// "Usar outra conta": este aparelho deixa de entrar só com o PIN.
  Future<void> otherAccount() async {
    await ref.read(tokenStoreProvider).clearAll();
    state = const AuthState(AuthStage.signedOut);
  }

  Future<void> logout() async {
    await _repo.logout().catchError((_) {});
    state = _signedOutState();
  }

  AuthState _afterLogin(AppUser user, {bool locked = false}) {
    if (!user.pinSet) return AuthState(AuthStage.pinSetup, user: user);
    if (locked) return AuthState(AuthStage.locked, user: user, name: user.name, maskedPhone: user.maskedPhone);
    if (user.needsOnboarding) return AuthState(AuthStage.onboarding, user: user);
    return AuthState(AuthStage.signedIn, user: user);
  }

  AuthState _signedOutState() {
    final tokens = ref.read(tokenStoreProvider);
    return tokens.deviceToken != null
        ? AuthState(AuthStage.pinLogin, name: tokens.userName, maskedPhone: tokens.maskedPhone)
        : const AuthState(AuthStage.signedOut);
  }

  void _onSessionExpired() => state = _signedOutState();
}
