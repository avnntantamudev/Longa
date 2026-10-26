/// Formatos em português de Angola. Montantes sempre em Kz inteiros.
String kwanza(int amount) {
  final negative = amount < 0;
  final digits = amount.abs().toString();
  final buffer = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) buffer.write('.');
    buffer.write(digits[i]);
  }
  return '${negative ? '−' : ''}$buffer Kz';
}

/// 800 → "800 m"; 1234 → "1,2 km"
String distance(int meters) {
  if (meters < 1000) return '${(meters / 100).round() * 100} m';
  return '${(meters / 1000).toStringAsFixed(1).replaceAll('.', ',')} km';
}

const _weekdays = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

String _two(int n) => n.toString().padLeft(2, '0');

/// "hoje 14:00", "amanhã 09:30", "sáb, 25/10 14:00"
String when(DateTime value, {DateTime? now}) {
  final local = value.toLocal();
  final today = (now ?? DateTime.now()).toLocal();
  final time = '${_two(local.hour)}:${_two(local.minute)}';
  final day = DateTime(local.year, local.month, local.day);
  final base = DateTime(today.year, today.month, today.day);
  final diff = day.difference(base).inDays;
  if (diff == 0) return 'hoje $time';
  if (diff == 1) return 'amanhã $time';
  return '${_weekdays[local.weekday - 1]}, ${_two(local.day)}/${_two(local.month)} $time';
}

DateTime? parseDate(Object? value) => value is String ? DateTime.tryParse(value) : null;

/// Lê inteiros de JSON que podem chegar como int, double ou texto.
int asInt(Object? value, [int fallback = 0]) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? fallback;
  return fallback;
}
