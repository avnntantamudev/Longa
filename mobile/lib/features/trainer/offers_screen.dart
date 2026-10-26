import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/realtime/realtime.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import '../auth/auth_controller.dart';
import 'offers_repository.dart';

/// TRIM TRIM: disponibilidade, pedidos de agora (60 s) e pedidos agendados (outro dia).
class OffersScreen extends ConsumerStatefulWidget {
  const OffersScreen({super.key});

  @override
  ConsumerState<OffersScreen> createState() => _OffersScreenState();
}

class _OffersScreenState extends ConsumerState<OffersScreen> {
  List<Offer> _offers = const [];
  bool? _available;
  Object? _error;
  DateTime _loadedAt = DateTime.now();
  Timer? _poll;
  Timer? _tick;
  StreamSubscription<RealtimeEvent>? _realtime;

  OffersRepository get _repo => ref.read(offersRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
    final userId = ref.read(authControllerProvider).user?.id;
    if (userId != null) {
      _realtime = ref.read(realtimeProvider).privateChannel('user.$userId').listen((e) {
        if (e.name.startsWith('offer')) _load();
      });
    }
    _poll = Timer.periodic(const Duration(seconds: 10), (_) => _load());
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
      final results = await Future.wait([_repo.list(), if (_available == null) _repo.availability()]);
      if (!mounted) return;
      setState(() {
        _offers = results[0] as List<Offer>;
        if (results.length > 1) _available = results[1] as bool;
        _loadedAt = DateTime.now();
        _error = null;
      });
    } catch (e) {
      if (mounted && _offers.isEmpty) setState(() => _error = e);
    }
  }

  Future<void> _toggle(bool value) async {
    try {
      final result = await _repo.setAvailability(value);
      setState(() => _available = result);
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    }
  }

  int _secondsLeft(Offer o) => (o.secondsLeft - DateTime.now().difference(_loadedAt).inSeconds).clamp(0, 1 << 30);

  @override
  Widget build(BuildContext context) {
    final now = _offers.where((o) => !o.scheduled && _secondsLeft(o) > 0).toList();
    final scheduled = _offers.where((o) => o.scheduled).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('TRIM TRIM')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          LongaCard(
            child: SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _available ?? false,
              onChanged: _available == null ? null : _toggle,
              title: Text(_available == true ? 'Disponível para receber pedidos' : 'Indisponível', style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: const Text('Os pedidos de agora duram 60 segundos: ative as notificações para não os perder.'),
            ),
          ),
          if (_error != null) ...[const SizedBox(height: 12), LoadError(_error!, onRetry: _load)],
          const SizedBox(height: 16),
          Text('Pedidos em aberto', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          if (now.isEmpty)
            const LongaCard(child: Text('Sem pedidos neste momento. Quando chegar um, aparece aqui.', style: TextStyle(color: LongaColors.inkMuted))),
          for (final o in now)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: LongaCard(
                onTap: () => context.push('/ofertas/${o.id}').then((_) => _load()),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${o.skill} · ${kwanza(o.trainerNet)} para si', style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text('${when(o.scheduledAt)} · ${o.neighborhood} · ${distance(o.distanceM)}', style: const TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
                    ]),
                  ),
                  StateBadge('${_secondsLeft(o)}s', color: Colors.white, background: LongaColors.orange500),
                ]),
              ),
            ),
          if (scheduled.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('Pedidos agendados', style: Theme.of(context).textTheme.titleLarge),
            const Text('Aulas para outro dia. O primeiro formador a aceitar fica com a aula.', style: TextStyle(color: LongaColors.inkMuted)),
            const SizedBox(height: 8),
            for (final o in scheduled)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: LongaCard(
                  onTap: () => context.push('/ofertas/${o.id}').then((_) => _load()),
                  child: Row(children: [
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${o.skill} · ${kwanza(o.trainerNet)} para si', style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text('${when(o.scheduledAt)} · ${o.neighborhood} · ${distance(o.distanceM)}', style: const TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
                      ]),
                    ),
                    StateBadge('até ${when(o.expiresAt)}'),
                  ]),
                ),
              ),
          ],
        ]),
      ),
    );
  }
}
