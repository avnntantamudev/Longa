import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../shared/widgets.dart';
import '../home/home_screen.dart';
import 'session_models.dart';
import 'sessions_repository.dart';

final sessionsListProvider = FutureProvider.autoDispose<List<TrainingSession>>((ref) => ref.watch(sessionsRepositoryProvider).list());

class SessionsScreen extends ConsumerWidget {
  const SessionsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sessions = ref.watch(sessionsListProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Aulas')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(sessionsListProvider.future),
        child: sessions.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => LoadError(e, onRetry: () => ref.invalidate(sessionsListProvider)),
          data: (list) => list.isEmpty
              ? ListView(padding: const EdgeInsets.all(16), children: const [
                  LongaCard(child: Text('Ainda não tem aulas.', style: TextStyle(color: LongaColors.inkMuted))),
                ])
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (_, i) => SessionTile(session: list[i]),
                ),
        ),
      ),
    );
  }
}
