import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/realtime/realtime.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'requests_repository.dart';

/// Acompanhar um pedido: a procurar (TRIM TRIM ou agendado), aceite, sem formadores, cancelado.
class RequestStatusScreen extends ConsumerStatefulWidget {
  const RequestStatusScreen({super.key, required this.requestId});

  final int requestId;

  @override
  ConsumerState<RequestStatusScreen> createState() => _RequestStatusScreenState();
}

class _RequestStatusScreenState extends ConsumerState<RequestStatusScreen> {
  TrainingRequest? _request;
  Object? _error;
  Timer? _poll;
  Timer? _tick;
  StreamSubscription<RealtimeEvent>? _realtime;
  DateTime _loadedAt = DateTime.now();
  bool _busy = false;

  RequestsRepository get _repo => ref.read(requestsRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
    _realtime = ref.read(realtimeProvider).privateChannel('request.${widget.requestId}').listen((_) => _load());
    _poll = Timer.periodic(const Duration(seconds: 5), (_) => _request?.state == 'searching' ? _load() : null);
    _tick = Timer.periodic(const Duration(seconds: 1), (_) => mounted ? setState(() {}) : null);
  }

  @override
  void dispose() {
    _poll?.cancel();
    _tick?.cancel();
    _realtime?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await _repo.show(widget.requestId);
      if (!mounted) return;
      setState(() {
        _request = r;
        _loadedAt = DateTime.now();
        _error = null;
      });
    } catch (e) {
      if (mounted && _request == null) setState(() => _error = e);
    }
  }

  Future<void> _do(Future<Object?> Function() action) async {
    setState(() => _busy = true);
    try {
      await action();
      await _load();
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = _request;
    if (r == null) {
      return Scaffold(appBar: AppBar(title: const Text('Pedido')), body: _error == null ? const Center(child: CircularProgressIndicator()) : LoadError(_error!, onRetry: _load));
    }

    final left = r.secondsLeft == null ? 0 : (r.secondsLeft! - DateTime.now().difference(_loadedAt).inSeconds).clamp(0, 1 << 30);
    final mmss = '${left ~/ 60}:${(left % 60).toString().padLeft(2, '0')}';

    return Scaffold(
      appBar: AppBar(title: const Text('Pedido')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        LongaCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${r.skill} · ${kwanza(r.amount)}', style: const TextStyle(fontWeight: FontWeight.w700)),
            Text('${when(r.scheduledAt)} · ${r.neighborhood}', style: const TextStyle(color: LongaColors.inkMuted)),
          ]),
        ),
        const SizedBox(height: 16),
        switch (r.state) {
          'searching' => Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(color: LongaColors.navy900, borderRadius: BorderRadius.circular(24)),
              child: Column(children: [
                const SizedBox(width: 64, height: 64, child: CircularProgressIndicator(color: LongaColors.orange500, strokeWidth: 5)),
                const SizedBox(height: 16),
                Text(r.scheduled ? 'Pedido enviado aos formadores' : 'A procurar formadores perto de si…',
                    textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                Text(
                  r.scheduled
                      ? 'Aula noutro dia: formadores perto de si e formadores VIP da província podem aceitar${r.expiresAt != null ? ' até ${when(r.expiresAt!)}' : ''}. Avisamos quando alguém aceitar.'
                      : (r.openOffers > 0 ? '${r.openOffers} formador(es) a ver o seu pedido agora · tempo restante $mmss' : 'A preparar o próximo envio · tempo restante $mmss'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: LongaColors.navy200),
                ),
                const SizedBox(height: 16),
                OutlinedButton(
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
                  onPressed: _busy ? null : () => _do(() => _repo.cancel(r.id)),
                  child: const Text('Cancelar pedido'),
                ),
              ]),
            ),
          'matched' => LongaCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('Formador encontrado! 🎉', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 18)),
                const SizedBox(height: 8),
                const Text('Abra a aula para pagar (se for pela LONGA) e combinar os detalhes no chat.'),
                const SizedBox(height: 12),
                if (r.sessionId != null) FilledButton(onPressed: () => context.pushReplacement('/aulas/${r.sessionId}'), child: const Text('Abrir aula')),
              ]),
            ),
          'expired' => LongaCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('Nenhum formador aceitou a tempo', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 18)),
                const SizedBox(height: 8),
                const Text('Pode publicar de novo (talvez com outro valor ou horário).'),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: _busy
                      ? null
                      : () => _do(() async {
                            final again = await _repo.republish(r.id);
                            if (mounted) context.pushReplacement('/pedidos/${again.id}');
                            return null;
                          }),
                  child: const Text('Publicar de novo'),
                ),
              ]),
            ),
          _ => const LongaCard(child: Text('Pedido cancelado.')),
        },
      ]),
    );
  }
}
