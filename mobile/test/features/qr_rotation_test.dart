import 'package:flutter_test/flutter_test.dart';
import 'package:longa/features/sessions/qr_rotation.dart';
import 'package:longa/features/sessions/session_models.dart';

QrBatch batchAt(DateTime serverStart, {int count = 10, int rotation = 30}) => QrBatch(
      rotation,
      [
        for (var i = 0; i < count; i++)
          QrToken('token-$i-abcdefghijkl', '${100000 + i}', serverStart.add(Duration(seconds: i * rotation)), serverStart.add(Duration(seconds: i * rotation + rotation + 15))),
      ],
      serverStart,
    );

void main() {
  final server = DateTime.utc(2026, 10, 26, 18, 0, 0);

  test('escolhe o token da janela de 30 s, com o relógio acertado pelo do servidor', () {
    final rotation = QrRotation();
    // O telemóvel está 2 minutos adiantado
    final device = server.add(const Duration(minutes: 2));
    rotation.setBatch(batchAt(server), device);

    expect(rotation.current(device)?.code, '100000');
    expect(rotation.current(device.add(const Duration(seconds: 31)))?.code, '100001');
    expect(rotation.current(device.add(const Duration(seconds: 299)))?.code, '100009');
    expect(rotation.current(device.add(const Duration(seconds: 301))), isNull);
  });

  test('segundos até mudar', () {
    final rotation = QrRotation()..setBatch(batchAt(server), server);

    expect(rotation.secondsLeft(server), 30);
    expect(rotation.secondsLeft(server.add(const Duration(seconds: 12))), 18);
  });

  test('pede lote novo quando falta menos de 1 minuto, no máximo a cada 10 s e nunca depois de parar', () {
    final rotation = QrRotation();
    expect(rotation.needsNewBatch(server), isTrue); // ainda sem lote

    expect(rotation.beginFetch(server), isTrue);
    expect(rotation.beginFetch(server), isFalse); // já há um pedido em curso
    rotation.setBatch(batchAt(server), server);
    rotation.endFetch();

    expect(rotation.needsNewBatch(server.add(const Duration(minutes: 3))), isFalse); // faltam 2 minutos
    expect(rotation.needsNewBatch(server.add(const Duration(seconds: 250))), isTrue); // falta < 1 minuto

    rotation.beginFetch(server.add(const Duration(seconds: 250)));
    rotation.endFetch();
    expect(rotation.needsNewBatch(server.add(const Duration(seconds: 255))), isFalse); // < 10 s depois

    rotation.stop();
    expect(rotation.needsNewBatch(server.add(const Duration(seconds: 290))), isFalse);
  });

  test('o QR leva a aula e o token; recusa QR de outra app', () {
    final payload = qrPayload(42, 'Abc_DEF-ghi1234567890');

    expect(payload, 'LONGA:42:Abc_DEF-ghi1234567890');
    expect(parseQrPayload(payload), (sessionId: 42, token: 'Abc_DEF-ghi1234567890'));
    expect(parseQrPayload('https://exemplo.com'), isNull);
    expect(parseQrPayload('LONGA:42:curto'), isNull);
  });
}
