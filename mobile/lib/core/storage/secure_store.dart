import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Armazenamento simples chave/valor (permite trocar por memória nos testes).
abstract class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
}

class SecureKeyValueStore implements KeyValueStore {
  SecureKeyValueStore([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage(aOptions: AndroidOptions(encryptedSharedPreferences: true));

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String? value) => value == null ? _storage.delete(key: key) : _storage.write(key: key, value: value);
}

class MemoryKeyValueStore implements KeyValueStore {
  final Map<String, String> values = {};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String? value) async => value == null ? values.remove(key) : values[key] = value;
}

/// Tokens da sessão e do aparelho de confiança. Nunca guardamos o PIN.
///  - access/refresh: sessão atual (o refresh roda a cada uso);
///  - device_token: permite entrar só com o PIN neste aparelho;
///  - nome e telefone mascarado: para o ecrã "Olá, Ana" antes de entrar.
class TokenStore {
  TokenStore(this._store);

  final KeyValueStore _store;

  static const _access = 'access_token';
  static const _refresh = 'refresh_token';
  static const _device = 'device_token';
  static const _name = 'user_name';
  static const _phone = 'user_phone_masked';

  String? accessToken;
  String? refreshToken;
  String? deviceToken;
  String? userName;
  String? maskedPhone;

  Future<void> load() async {
    accessToken = await _store.read(_access);
    refreshToken = await _store.read(_refresh);
    deviceToken = await _store.read(_device);
    userName = await _store.read(_name);
    maskedPhone = await _store.read(_phone);
  }

  Future<void> saveSession({required String access, required String refresh}) async {
    accessToken = access;
    refreshToken = refresh;
    await _store.write(_access, access);
    await _store.write(_refresh, refresh);
  }

  Future<void> saveDevice(String token) async {
    deviceToken = token;
    await _store.write(_device, token);
  }

  Future<void> saveIdentity({String? name, String? phone}) async {
    userName = name;
    maskedPhone = phone;
    await _store.write(_name, name);
    await _store.write(_phone, phone);
  }

  Future<void> clearSession() async {
    accessToken = null;
    refreshToken = null;
    await _store.write(_access, null);
    await _store.write(_refresh, null);
  }

  /// "Usar outra conta" ou aparelho revogado: esquece tudo.
  Future<void> clearAll() async {
    await clearSession();
    deviceToken = null;
    await _store.write(_device, null);
    await saveIdentity();
  }
}
