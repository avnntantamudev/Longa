import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'wallet_repository.dart';

final _earningsProvider = FutureProvider.autoDispose.family<List<EarningsMonth>, int>((ref, year) => ref.watch(walletRepositoryProvider).earnings(year));

/// Os meus ganhos por mês (o PDF e o Excel descarregam-se na PWA nesta versão).
class EarningsScreen extends ConsumerStatefulWidget {
  const EarningsScreen({super.key});

  @override
  ConsumerState<EarningsScreen> createState() => _EarningsScreenState();
}

class _EarningsScreenState extends ConsumerState<EarningsScreen> {
  int _year = DateTime.now().year;

  static const _names = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

  @override
  Widget build(BuildContext context) {
    final months = ref.watch(_earningsProvider(_year));
    final years = [for (var y = DateTime.now().year; y >= 2026; y--) y];

    return Scaffold(
      appBar: AppBar(title: const Text('Os meus ganhos'), actions: [
        DropdownButton<int>(
          value: _year,
          dropdownColor: LongaColors.navy700,
          style: const TextStyle(color: Colors.white),
          underline: const SizedBox.shrink(),
          items: [for (final y in years) DropdownMenuItem(value: y, child: Text('$y'))],
          onChanged: (y) => setState(() => _year = y ?? _year),
        ),
        const SizedBox(width: 12),
      ]),
      body: months.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => LoadError(e, onRetry: () => ref.invalidate(_earningsProvider(_year))),
        data: (list) {
          int sum(int Function(EarningsMonth m) f) => list.fold(0, (a, m) => a + f(m));
          return ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              Expanded(child: LongaCard(child: _Total('Aulas dadas', '${sum((m) => m.lessons)}'))),
              const SizedBox(width: 12),
              Expanded(child: LongaCard(child: _Total('Na carteira', kwanza(sum((m) => m.credited))))),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: LongaCard(child: _Total('Recebido em mão', kwanza(sum((m) => m.cashReceived))))),
              const SizedBox(width: 12),
              Expanded(child: LongaCard(child: _Total('Comissões LONGA', kwanza(sum((m) => m.commissions))))),
            ]),
            const SizedBox(height: 16),
            LongaCard(
              padding: EdgeInsets.zero,
              child: Column(children: [
                for (final m in list)
                  ListTile(
                    dense: true,
                    title: Text(_names[m.month]),
                    subtitle: Text('${m.lessons} aula(s)${m.cashLessons > 0 ? ' · ${m.cashLessons} em mão' : ''}'),
                    trailing: Text(kwanza(m.credited + m.cashReceived), style: const TextStyle(fontWeight: FontWeight.w600)),
                  ),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
        Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
      ]);
}
