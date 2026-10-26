import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/theme.dart';

class LongaApp extends ConsumerWidget {
  const LongaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return MaterialApp(
      title: 'LONGA',
      theme: buildLongaTheme(),
      debugShowCheckedModeBanner: false,
      home: const Scaffold(body: Center(child: Text('LONGA'))),
    );
  }
}
