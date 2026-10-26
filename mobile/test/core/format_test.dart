import 'package:flutter_test/flutter_test.dart';
import 'package:longa/core/format.dart';
import 'package:longa/features/auth/user.dart';

void main() {
  test('kwanza com separador de milhares', () {
    expect(kwanza(0), '0 Kz');
    expect(kwanza(999), '999 Kz');
    expect(kwanza(25000), '25.000 Kz');
    expect(kwanza(1999999), '1.999.999 Kz');
    expect(kwanza(-3750), '−3.750 Kz');
  });

  test('distância arredondada', () {
    expect(distance(840), '800 m');
    expect(distance(1234), '1,2 km');
  });

  test('datas relativas', () {
    final now = DateTime(2026, 10, 26, 10);
    expect(when(DateTime(2026, 10, 26, 14, 0), now: now), 'hoje 14:00');
    expect(when(DateTime(2026, 10, 27, 9, 30), now: now), 'amanhã 09:30');
    expect(when(DateTime(2026, 10, 31, 8, 5), now: now), 'sáb, 31/10 08:05');
  });

  test('telefone mascarado', () {
    expect(maskPhone('+244923456789'), '+244 9•• ••• 789');
  });

  test('inteiros de JSON em vários formatos', () {
    expect(asInt(5), 5);
    expect(asInt(5.0), 5);
    expect(asInt('7'), 7);
    expect(asInt(null, 3), 3);
  });
}
