import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/realtime/realtime.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'qr_rotation.dart';
import 'session_models.dart';
import 'sessions_repository.dart';

/// QR de confirmação (formador). Lote de tokens de 30 s com a hora do servidor; sem rede continua a
/// mostrar o lote que tem e pede outro antes de acabar. Os tokens nunca são gravados no aparelho.
class QrScreen extends ConsumerStatefulWidget {
  const QrScreen({super.key, required this.sessionId});

  final int sessionId;

  @override
  ConsumerState<QrScreen> createState() => _QrScreenState();
}

class _QrScreenState extends ConsumerState<QrScreen> {
  final _rotation = QrRotation();
  TrainingSession? _session;
  bool _confirmed = false;
  String? _error;
  Timer? _ticker;
  Timer? _poll;
  StreamSubscription<RealtimeEvent>? _realtime;

  @override
  void initState() {
    super.initState();
    _fetch();
    _ticker = Timer.periodic(const Duration(milliseconds: 500), (_) {
      if (!mounted) return;
      setState(() {});
      if (!_confirmed && _rotation.needsNewBatch(DateTime.now())) _fetch();
    });
    _realtime = ref.read(realtimeProvider).privateChannel('session.${widget.sessionId}').listen((event) {
      if (event.name == 'session.confirmed') _markConfirmed();
    });
    _poll = Timer.periodic(const Duration(seconds: 10), (_) => _checkConfirmed());
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _poll?.cancel();
    _realtime?.cancel();
    super.dispose();
  }

  Future<void> _fetch() async {
    if (!_rotation.beginFetch(DateTime.now())) return;
    try {
      final result = await ref.read(sessionsRepositoryProvider).qr(widget.sessionId);
      _rotation.setBatch(result.batch, DateTime.now());
      setState(() {
        _session = result.session;
        _error = null;
      });
    } on ApiError catch (e) {
      if (!e.isNetwork) _rotation.stop();
      setState(() => _error = e.isNetwork ? 'Sem rede: a mostrar os códigos que já tem.' : e.message);
      if (!e.isNetwork) unawaited(_checkConfirmed());
    } finally {
      _rotation.endFetch();
    }
  }

  Future<void> _checkConfirmed() async {
    if (_confirmed) return;
    try {
      final s = await ref.read(sessionsRepositoryProvider).show(widget.sessionId);
      _session = s;
      if (s.state == 'completed') _markConfirmed();
    } catch (_) {}
  }

  void _markConfirmed() {
    if (mounted) setState(() => _confirmed = true);
  }

  @override
  Widget build(BuildContext context) {
    if (_confirmed) {
      final s = _session;
      return Scaffold(
        appBar: AppBar(title: const Text('Aula confirmada')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const CircleAvatar(radius: 48, backgroundColor: LongaColors.success, child: Icon(Icons.check, color: Colors.white, size: 56)),
              const SizedBox(height: 16),
              Text('Aula confirmada', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(s == null || s.isCash ? 'O aluno confirmou. O pagamento é feito em mão.' : '${kwanza(s.trainerNet ?? s.amount)} na sua carteira',
                  textAlign: TextAlign.center, style: const TextStyle(fontSize: 18)),
              const SizedBox(height: 24),
              FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Voltar à aula')),
            ]),
          ),
        ),
      );
    }

    final current = _rotation.current(DateTime.now());
    final seconds = _rotation.secondsLeft(DateTime.now());
    final width = MediaQuery.sizeOf(context).width;

    return Scaffold(
      appBar: AppBar(title: const Text('QR da aula')),
      backgroundColor: Colors.white,
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(24), children: [
          const Text('Mostre este QR ao aluno', textAlign: TextAlign.center, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          const Text('O aluno abre a aula na app e toca em "Escanear QR para confirmar".', textAlign: TextAlign.center, style: TextStyle(color: LongaColors.inkMuted)),
          const SizedBox(height: 24),
          Center(
            child: Opacity(
              opacity: current == null ? 0.25 : 1,
              child: QrImageView(
                data: current == null ? 'LONGA' : qrPayload(widget.sessionId, current.token),
                size: (width - 48).clamp(200, 320).toDouble(),
                eyeStyle: const QrEyeStyle(eyeShape: QrEyeShape.square, color: LongaColors.navy900),
                dataModuleStyle: const QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: LongaColors.navy900),
              ),
            ),
          ),
          const SizedBox(height: 16),
          if (current != null) ...[
            const Text('Ou diga o código', textAlign: TextAlign.center, style: TextStyle(color: LongaColors.inkMuted)),
            Text('${current.code.substring(0, 3)} ${current.code.substring(3)}',
                key: const ValueKey('qr-code'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 40, fontWeight: FontWeight.w800, letterSpacing: 6)),
            const SizedBox(height: 8),
            LinearProgressIndicator(value: seconds / _rotation.rotationSeconds, color: LongaColors.orange500, backgroundColor: LongaColors.navy50),
            const SizedBox(height: 4),
            Text('Muda em $seconds s', textAlign: TextAlign.center, style: const TextStyle(color: LongaColors.inkMuted)),
          ] else
            const Text('A gerar códigos…', textAlign: TextAlign.center),
          if (_error != null) ...[const SizedBox(height: 12), Notice(_error!)],
          const SizedBox(height: 12),
          const Text('Aumente o brilho do ecrã se o aluno tiver dificuldade em ler.', textAlign: TextAlign.center, style: TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
        ]),
      ),
    );
  }
}
