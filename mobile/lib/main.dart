import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/providers.dart';
import 'core/storage/secure_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final tokens = TokenStore(SecureKeyValueStore());
  await tokens.load();

  runApp(ProviderScope(overrides: [tokenStoreProvider.overrideWithValue(tokens)], child: const LongaApp()));
}
