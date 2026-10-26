import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api/api_client.dart';
import 'storage/secure_store.dart';

/// Substituídos em main.dart (depois de ler o armazenamento seguro) e nos testes.
final tokenStoreProvider = Provider<TokenStore>((ref) => throw UnimplementedError('tokenStoreProvider'));

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(tokens: ref.watch(tokenStoreProvider)));
