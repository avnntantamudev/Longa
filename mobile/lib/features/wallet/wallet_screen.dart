import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/realtime/realtime.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import '../auth/auth_controller.dart';
import 'wallet_repository.dart';

/// Carteira do formador (só leitura; levantamentos numa fase seguinte).
class WalletScreen extends ConsumerStatefulWidget {
  const WalletScreen({super.key});

  @override
  ConsumerState<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends ConsumerState<WalletScreen> {
  WalletSummary? _summary;
  final _movements = <WalletMovement>[];
  String? _next;
  Object? _error;
  bool _loadingMore = false;
  StreamSubscription<RealtimeEvent>? _realtime;

  WalletRepository get _repo => ref.read(walletRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
    final userId = ref.read(authControllerProvider).user?.id;
    if (userId != null) {
      _realtime = ref.read(realtimeProvider).privateChannel('user.$userId').listen((e) {
        if (e.name == 'wallet.updated') _load();
      });
    }
  }

  @override
  void dispose() {
    _realtime?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final results = await Future.wait([_repo.summary(), _repo.movements()]);
      final page = results[1] as ({List<WalletMovement> items, String? next});
      if (!mounted) return;
      setState(() {
        _summary = results[0] as WalletSummary;
        _movements
          ..clear()
          ..addAll(page.items);
        _next = page.next;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _more() async {
    if (_next == null) return;
    setState(() => _loadingMore = true);
    try {
      final page = await _repo.movements(cursor: _next);
      setState(() {
        _movements.addAll(page.items);
        _next = page.next;
      });
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _summary;
    return Scaffold(
      appBar: AppBar(title: const Text('Carteira')),
      body: s == null
          ? (_error == null ? const Center(child: CircularProgressIndicator()) : LoadError(_error!, onRetry: _load))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(color: LongaColors.navy900, borderRadius: BorderRadius.circular(24)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Text('Disponível', style: TextStyle(color: LongaColors.navy200)),
                    Text(kwanza(s.available), style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w800)),
                    const Text('Levantamentos disponíveis brevemente.', style: TextStyle(color: LongaColors.navy200, fontSize: 13)),
                    const SizedBox(height: 12),
                    Wrap(spacing: 8, children: [
                      ActionChip(label: const Text('⭐ Formador VIP'), onPressed: () => context.push('/vip')),
                      ActionChip(label: const Text('Os meus ganhos'), onPressed: () => context.push('/carteira/ganhos')),
                    ]),
                  ]),
                ),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(child: LongaCard(child: _Stat('Pendente', kwanza(s.pending), 'Aulas pagas por confirmar'))),
                  const SizedBox(width: 12),
                  Expanded(child: LongaCard(child: _Stat('Total ganho', kwanza(s.totalEarned), null))),
                ]),
                if (s.commissionDue > 0) ...[
                  const SizedBox(height: 12),
                  LongaCard(
                    child: Text('Comissões de aulas pagas em mão por entregar: ${kwanza(s.commissionDue)}. São descontadas automaticamente dos próximos pagamentos pela LONGA.'),
                  ),
                ],
                const SizedBox(height: 16),
                Text('Movimentos', style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 8),
                if (_movements.isEmpty) const LongaCard(child: Text('Ainda não há movimentos.', style: TextStyle(color: LongaColors.inkMuted))),
                for (final m in _movements)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(m.label),
                    subtitle: Text(when(m.at)),
                    onTap: m.sessionId == null ? null : () => context.push('/aulas/${m.sessionId}'),
                    trailing: Text(
                      // Na dívida, um lançamento negativo aumenta o que o formador deve
                      m.bucket == 'commission_due' ? 'Dívida ${m.amount < 0 ? '+' : '−'}${kwanza(m.amount.abs())}' : '${m.amount > 0 ? '+' : '−'}${kwanza(m.amount.abs())}',
                      style: TextStyle(fontWeight: FontWeight.w700, color: m.bucket == 'available' && m.amount > 0 ? LongaColors.success : LongaColors.ink),
                    ),
                  ),
                if (_next != null) OutlinedButton(onPressed: _loadingMore ? null : _more, child: const Text('Ver mais')),
              ]),
            ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat(this.label, this.value, this.hint);

  final String label;
  final String value;
  final String? hint;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
        Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        if (hint != null) Text(hint!, style: const TextStyle(color: LongaColors.inkMuted, fontSize: 12)),
      ]);
}
