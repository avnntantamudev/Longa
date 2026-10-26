import 'session_models.dart';

/// Lógica (testável) do QR rotativo do formador:
///  - acerta o relógio pelo do servidor (o relógio do telemóvel pode estar errado);
///  - escolhe o token da janela de 30 s atual;
///  - pede um lote novo quando falta menos de 1 minuto, no máximo uma vez a cada 10 s;
///  - pára de pedir depois de um erro definitivo (ex.: janela do QR fechada).
class QrRotation {
  QrBatch? _batch;
  Duration _offset = Duration.zero;
  DateTime? _lastAttempt;
  bool _fetching = false;
  bool _stopped = false;

  int get rotationSeconds => _batch?.rotationSeconds ?? 30;

  void setBatch(QrBatch batch, DateTime deviceNow) {
    _batch = batch;
    _offset = batch.serverTime.difference(deviceNow);
  }

  DateTime serverNow(DateTime deviceNow) => deviceNow.add(_offset);

  QrToken? current(DateTime deviceNow) => _batch?.currentAt(serverNow(deviceNow));

  int secondsLeft(DateTime deviceNow) {
    final token = current(deviceNow);
    if (token == null) return 0;
    final end = token.validFrom.add(Duration(seconds: rotationSeconds));
    final left = end.difference(serverNow(deviceNow)).inMilliseconds / 1000;
    return left <= 0 ? 0 : left.ceil();
  }

  bool needsNewBatch(DateTime deviceNow) {
    if (_stopped || _fetching) return false;
    if (_lastAttempt != null && deviceNow.difference(_lastAttempt!) < const Duration(seconds: 10)) return false;
    final batch = _batch;
    return batch == null || batch.remainingAt(serverNow(deviceNow)) < const Duration(minutes: 1);
  }

  /// Marca o início de um pedido de lote (false se já houver um em curso ou estiver parado).
  bool beginFetch(DateTime deviceNow) {
    if (_fetching || _stopped) return false;
    _fetching = true;
    _lastAttempt = deviceNow;
    return true;
  }

  void endFetch() => _fetching = false;

  void stop() => _stopped = true;
}
