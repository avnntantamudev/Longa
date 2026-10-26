// Lê a saída de `flutter test --machine` (stdin) e escreve cada teste falhado como anotação do GitHub
// Actions (`::error`), para os erros serem visíveis sem descarregar os registos. Sai com 1 se algo falhou.
import 'dart:convert';
import 'dart:io';

Future<void> main() async {
  final names = <int, String>{};
  final errors = <int, StringBuffer>{};
  var failed = false;

  await for (final line in stdin.transform(utf8.decoder).transform(const LineSplitter())) {
    if (!line.startsWith('{')) continue;
    final Object? decoded;
    try {
      decoded = jsonDecode(line);
    } catch (_) {
      continue;
    }
    if (decoded is! Map<String, dynamic>) continue;
    final event = decoded;

    switch (event['type']) {
      case 'testStart':
        final test = event['test'] as Map<String, dynamic>;
        names[test['id'] as int] = test['name'] as String;
      case 'error':
        errors.putIfAbsent(event['testID'] as int, StringBuffer.new)
          ..writeln(event['error'])
          ..writeln(event['stackTrace']);
      case 'testDone':
        final id = event['testID'] as int;
        if (event['result'] != 'success' && event['hidden'] != true) {
          failed = true;
          final message = (errors[id]?.toString() ?? '').split('\n').take(25).join('%0A');
          stdout.writeln('::error title=${names[id] ?? 'teste'}::$message');
        }
    }
  }

  exit(failed ? 1 : 0);
}
