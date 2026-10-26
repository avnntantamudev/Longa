/// Configuração da app, definida na compilação:
///   flutter run --dart-define=API_URL=http://192.168.10.72:8000 --dart-define=REVERB_HOST=192.168.10.72
///
/// No emulador Android, `10.0.2.2` é o computador onde corre o Laravel.
class AppConfig {
  const AppConfig._();

  static const apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000');

  static String get apiBase => '$apiUrl/api/v1';

  static const reverbHost = String.fromEnvironment('REVERB_HOST', defaultValue: '10.0.2.2');
  static const reverbPort = int.fromEnvironment('REVERB_PORT', defaultValue: 8081);
  static const reverbKey = String.fromEnvironment('REVERB_APP_KEY', defaultValue: 'longa-key');
  static const reverbTls = bool.fromEnvironment('REVERB_TLS', defaultValue: false);

  /// Pedir o PIN de novo ao fim de X minutos sem uso (igual à PWA).
  static const pinIdle = Duration(minutes: 60);

  /// Código de acesso (OTP) com 6 dígitos.
  static const otpLength = 6;
}
