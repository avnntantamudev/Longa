import '../../core/api/api_client.dart';
import '../../core/storage/secure_store.dart';
import 'user.dart';

/// Resultado de entrar com o código: o aparelho fica de confiança.
class OtpLogin {
  OtpLogin(this.user, {required this.pinSet, required this.created});

  final AppUser user;
  final bool pinSet;
  final bool created;
}

/// Chamadas de autenticação (OTP, PIN, sessão).
class AuthRepository {
  AuthRepository(this.api, this.tokens);

  final ApiClient api;
  final TokenStore tokens;

  static const deviceName = 'App LONGA';

  /// Envia o código por SMS ou WhatsApp. Devolve segundos até poder pedir outro.
  Future<int> requestCode(String phone, {String channel = 'sms'}) async {
    final body = await api.post('/auth/otp/request', data: {'phone': phone, 'channel': channel});
    return (body['resend_in'] as num?)?.toInt() ?? 60;
  }

  Future<OtpLogin> verifyCode(String phone, String code) async {
    final body = await api.post('/auth/otp/verify', data: {'phone': phone, 'code': code, 'device_name': deviceName});
    await tokens.saveSession(access: body['access_token'] as String, refresh: body['refresh_token'] as String);
    await tokens.saveDevice(body['device_token'] as String);
    final user = AppUser.fromJson(body['user'] as Map<String, dynamic>);
    await tokens.saveIdentity(name: user.name, phone: user.maskedPhone);

    return OtpLogin(user, pinSet: body['pin_set'] == true, created: body['created'] == true);
  }

  /// Entrar só com o PIN neste aparelho.
  Future<AppUser> loginWithPin(String pin) async {
    final body = await api.post('/auth/pin/login', data: {'device_token': tokens.deviceToken, 'pin': pin, 'device_name': deviceName});
    await tokens.saveSession(access: body['access_token'] as String, refresh: body['refresh_token'] as String);
    final user = AppUser.fromJson(body['user'] as Map<String, dynamic>);
    await tokens.saveIdentity(name: user.name, phone: user.maskedPhone);

    return user;
  }

  Future<void> setupPin(String pin) => api.post('/auth/pin/setup', data: {'pin': pin, 'pin_confirmation': pin});

  /// Confirmar o PIN (desbloquear a app ou autorizar um pagamento durante 5 minutos).
  Future<void> verifyPin(String pin) => api.post('/auth/pin/verify', data: {'pin': pin});

  Future<void> changePin(String current, String pin) => api.post('/auth/pin/change', data: {'current_pin': current, 'pin': pin, 'pin_confirmation': pin});

  Future<AppUser> me() async => AppUser.fromJson((await api.get('/me'))['data'] as Map<String, dynamic>);

  Future<AppUser> completeOnboarding({required String name, required List<String> profiles}) async {
    final body = await api.post('/onboarding', data: {'name': name, 'profiles': profiles, 'accept_terms': true, 'accept_privacy': true});
    return AppUser.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } finally {
      await tokens.clearSession();
    }
  }
}
