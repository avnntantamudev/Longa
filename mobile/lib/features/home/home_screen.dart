import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import '../auth/auth_controller.dart';
import '../sessions/session_models.dart';
import '../sessions/sessions_repository.dart';

final upcomingSessionsProvider = FutureProvider.autoDispose<List<TrainingSession>>((ref) async {
  final all = await ref.watch(sessionsRepositoryProvider).list();
  const active = {'pending_payment', 'scheduled', 'in_progress', 'awaiting_confirmation'};
  return all.where((s) => active.contains(s.state)).toList();
});

/// Início: saudação, atalhos do perfil e próximas aulas.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authControllerProvider).user;
    final upcoming = ref.watch(upcomingSessionsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('LONGA')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(upcomingSessionsProvider.future),
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(color: LongaColors.navy900, borderRadius: BorderRadius.circular(24)),
            child: Row(children: [
              UserAvatar(name: user?.name, photoPath: user?.photoThumbUrl, size: 56),
              const SizedBox(width: 16),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Olá${(user?.firstName ?? '').isEmpty ? '' : ', ${user!.firstName}'}', style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w700)),
                  const Text('Kulonga = Ensinar', style: TextStyle(color: LongaColors.orange400)),
                ]),
              ),
            ]),
          ),
          const SizedBox(height: 16),
          if (user?.isStudent ?? false)
            FilledButton.icon(onPressed: () => context.go('/pedir'), icon: const Icon(Icons.search), label: const Text('Pedir aula')),
          if (user?.isTrainer ?? false) ...[
            const SizedBox(height: 8),
            OutlinedButton.icon(onPressed: () => context.go('/ofertas'), icon: const Icon(Icons.notifications_active_outlined), label: const Text('Ver pedidos TRIM TRIM')),
          ],
          const SizedBox(height: 24),
          Text('Próximas aulas', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          upcoming.when(
            loading: () => const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
            error: (e, _) => LoadError(e, onRetry: () => ref.invalidate(upcomingSessionsProvider)),
            data: (sessions) => sessions.isEmpty
                ? const LongaCard(child: Text('Ainda não tem aulas marcadas.', style: TextStyle(color: LongaColors.inkMuted)))
                : Column(children: [for (final s in sessions) Padding(padding: const EdgeInsets.only(bottom: 8), child: SessionTile(session: s))]),
          ),
        ]),
      ),
    );
  }
}

/// Linha de uma aula (lista de aulas e início).
class SessionTile extends StatelessWidget {
  const SessionTile({super.key, required this.session});

  final TrainingSession session;

  @override
  Widget build(BuildContext context) {
    final s = session;
    return LongaCard(
      onTap: () => context.push('/aulas/${s.id}'),
      child: Row(children: [
        UserAvatar(name: s.counterpart.name, photoPath: s.counterpart.photoThumbUrl),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(s.title, style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis),
            Text('${when(s.scheduledAt)} · ${s.neighborhood}', style: const TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
          ]),
        ),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          StateBadge(s.stateLabel),
          const SizedBox(height: 4),
          Text(kwanza(s.isTrainer ? (s.trainerNet ?? s.amount) : s.amount), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
        ]),
      ]),
    );
  }
}
