import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'offers_repository.dart';

/// Uma oferta: contagem (pedido de agora) ou prazo (agendado), valor para o formador, aceitar/recusar.
class OfferScreen extends ConsumerStatefulWidget {
  const OfferScreen({super.key, required this.offerId});

  final int offerId;

  @override
  ConsumerState<OfferScreen> createState() => _OfferScreenState();
}

class _OfferScreenState extends ConsumerState<OfferScreen> {
  Offer? _offer;
  Object? _error;
  String? _outcome;
  bool _busy = false;
  DateTime _loadedAt = DateTime.now();
  Timer? _tick;

  OffersRepository get _repo => ref.read(offersRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) => mounted ? setState(() {}) : null);
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final offer = await _repo.show(widget.offerId);
      if (offer.isOpen && offer.state == 'sent') unawaited(_repo.markViewed(offer.id).catchError((_) {}));
      setState(() {
        _offer = offer;
        _loadedAt = DateTime.now();
      });
    } catch (e) {
      setState(() => _error = e);
    }
  }

  int get _left => _offer == null ? 0 : (_offer!.secondsLeft - DateTime.now().difference(_loadedAt).inSeconds).clamp(0, 1 << 30);

  Future<void> _accept() async {
    setState(() => _busy = true);
    try {
      final sessionId = await _repo.accept(widget.offerId);
      if (mounted) context.pushReplacement('/aulas/$sessionId');
    } on ApiError catch (e) {
      // Outro formador aceitou primeiro, ou a oferta expirou
      setState(() => _outcome = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _decline() async {
    setState(() => _busy = true);
    try {
      await _repo.decline(widget.offerId);
      if (mounted) Navigator.of(context).pop();
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final o = _offer;
    if (o == null) {
      return Scaffold(appBar: AppBar(title: const Text('Pedido')), body: _error == null ? const Center(child: CircularProgressIndicator()) : LoadError(_error!, onRetry: _load));
    }
    final open = o.isOpen && (o.scheduled || _left > 0) && _outcome == null;

    return Scaffold(
      backgroundColor: LongaColors.navy900,
      appBar: AppBar(title: Text(o.scheduled ? 'Pedido agendado' : 'TRIM TRIM')),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        Text(o.skill, style: const TextStyle(color: LongaColors.orange400, fontWeight: FontWeight.w600)),
        Text(o.title, style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        Text('${kwanza(o.trainerNet)} para si', style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800)),
        Text('Valor ${kwanza(o.amount)} · comissão LONGA ${o.commissionPercent}%', style: const TextStyle(color: LongaColors.navy200)),
        const SizedBox(height: 12),
        Text('${when(o.scheduledAt)} · ${o.modality == 'online' ? 'Online' : '${o.neighborhood} · ${distance(o.distanceM)}'}', style: const TextStyle(color: Colors.white)),
        if (o.description != null && o.description!.isNotEmpty) ...[const SizedBox(height: 8), Text(o.description!, style: const TextStyle(color: LongaColors.navy100))],
        const SizedBox(height: 24),
        if (open && !o.scheduled)
          Center(
            child: SizedBox(
              width: 120,
              height: 120,
              child: Stack(fit: StackFit.expand, children: [
                CircularProgressIndicator(value: _left / 60, strokeWidth: 8, color: _left <= 10 ? LongaColors.danger : LongaColors.orange500, backgroundColor: Colors.white12),
                Center(child: Text('${_left}s', style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800))),
              ]),
            ),
          ),
        if (open && o.scheduled) Text('Aceite até ${when(o.expiresAt)}', textAlign: TextAlign.center, style: const TextStyle(color: Colors.white)),
        if (!open)
          Notice(_outcome ?? (o.state == 'accepted' ? 'Ficou com esta aula.' : 'Esta oferta já não está disponível.'), success: o.state == 'accepted'),
        const SizedBox(height: 24),
        if (open) ...[
          FilledButton(onPressed: _busy ? null : _accept, child: Text(_busy ? 'A aceitar…' : 'Aceitar aula')),
          const SizedBox(height: 8),
          OutlinedButton(
            style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38)),
            onPressed: _busy ? null : _decline,
            child: const Text('Recusar'),
          ),
        ] else if (o.sessionId != null)
          FilledButton(onPressed: () => context.pushReplacement('/aulas/${o.sessionId}'), child: const Text('Abrir aula')),
      ]),
    );
  }
}
