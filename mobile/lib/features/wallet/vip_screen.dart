import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'wallet_repository.dart';

/// Formador VIP: estado, vantagens e compra/renovação (PIN pedido antes de pagar; sem reembolso).
class VipScreen extends ConsumerStatefulWidget {
  const VipScreen({super.key});

  @override
  ConsumerState<VipScreen> createState() => _VipScreenState();
}

class _VipScreenState extends ConsumerState<VipScreen> {
  VipStatus? _vip;
  Object? _error;
  String _method = 'multicaixa_express';
  bool _accept = false;
  bool _busy = false;
  String? _notice;
  String? _key;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final vip = await ref.read(walletRepositoryProvider).vip();
      setState(() {
        _vip = vip;
        _error = null;
      });
    } catch (e) {
      setState(() => _error = e);
    }
  }

  Future<void> _buy() async {
    if (!_accept) {
      showSnack(context, 'Confirme que leu as condições.', error: true);
      return;
    }
    setState(() {
      _busy = true;
      _notice = null;
    });
    _key ??= ApiClient.newIdempotencyKey();
    try {
      await ref.read(walletRepositoryProvider).buyVip(_method, _key!);
      _key = null;
      setState(() => _notice = 'Pagamento confirmado. Já é formador VIP!');
      await _load();
    } on ApiError catch (e) {
      if (!e.isNetwork) _key = null; // sem rede: repetir com a mesma chave não cobra duas vezes
      if (mounted && e.code != 'pin_cancelled') showSnack(context, e.userMessage, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final v = _vip;
    return Scaffold(
      appBar: AppBar(title: const Text('Formador VIP')),
      body: v == null
          ? (_error == null ? const Center(child: CircularProgressIndicator()) : LoadError(_error!, onRetry: _load))
          : ListView(padding: const EdgeInsets.all(16), children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(color: LongaColors.navy900, borderRadius: BorderRadius.circular(24)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(v.isVip ? 'É VIP' : 'Ainda não é VIP', style: const TextStyle(color: LongaColors.orange400, fontWeight: FontWeight.w700)),
                  if (v.isVip && v.until != null) Text('Até ${when(v.until!)}', style: const TextStyle(color: Colors.white, fontSize: 18)),
                  const SizedBox(height: 4),
                  Text('${kwanza(v.price)} / ${v.durationDays} dias', style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
                ]),
              ),
              const SizedBox(height: 12),
              LongaCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Vantagens', style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  const Text('⭐ Pedidos de outro dia em toda a província, não só a 5 km.'),
                  const Text('⚡ Prioridade: recebe os pedidos TRIM TRIM antes dos outros.'),
                  Text('💰 Comissão de ${v.commissionPercent}% em vez de ${v.standardCommissionPercent}% nas aulas aceites enquanto for VIP.'),
                  const Text('🏅 Selo VIP visível aos alunos.'),
                ]),
              ),
              const SizedBox(height: 12),
              LongaCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(v.isVip ? 'Renovar' : 'Tornar-me VIP', style: const TextStyle(fontWeight: FontWeight.w700)),
                  if (v.isVip) Text('Renovar agora soma ${v.durationDays} dias ao fim do seu VIP atual.', style: const TextStyle(color: LongaColors.inkMuted)),
                  const SizedBox(height: 8),
                  SegmentedButton<String>(
                    segments: const [ButtonSegment(value: 'multicaixa_express', label: Text('Multicaixa Express')), ButtonSegment(value: 'reference', label: Text('Referência'))],
                    selected: {_method},
                    onSelectionChanged: (s) => setState(() => _method = s.first),
                  ),
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    controlAffinity: ListTileControlAffinity.leading,
                    value: _accept,
                    onChanged: (x) => setState(() => _accept = x ?? false),
                    title: Text('Li e aceito: a subscrição dura ${v.durationDays} dias e não é reembolsável a meio do período.'),
                  ),
                  if (_notice != null) ...[Notice(_notice!, success: true), const SizedBox(height: 8)],
                  FilledButton(onPressed: _busy ? null : _buy, child: Text(_busy ? 'A processar…' : 'Pagar ${kwanza(v.price)}')),
                ]),
              ),
            ]),
    );
  }
}
