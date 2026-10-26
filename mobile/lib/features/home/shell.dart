import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../auth/auth_controller.dart';

class _Tab {
  const _Tab(this.path, this.label, this.icon);

  final String path;
  final String label;
  final IconData icon;
}

/// Barra de navegação inferior, com as abas de cada perfil (como a PWA).
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.location, required this.child});

  final String location;
  final Widget child;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authControllerProvider).user;
    final tabs = <_Tab>[
      const _Tab('/', 'Início', Icons.home_outlined),
      if (user?.isStudent ?? false) const _Tab('/pedir', 'Pedir aula', Icons.search),
      if (user?.isTrainer ?? false) const _Tab('/ofertas', 'TRIM TRIM', Icons.notifications_active_outlined),
      const _Tab('/aulas', 'Aulas', Icons.calendar_month_outlined),
      if (user?.isTrainer ?? false) const _Tab('/carteira', 'Carteira', Icons.account_balance_wallet_outlined),
      const _Tab('/perfil', 'Perfil', Icons.person_outline),
    ];
    final index = tabs.indexWhere((t) => t.path == location);

    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index < 0 ? 0 : index,
        onDestinationSelected: (i) => context.go(tabs[i].path),
        destinations: [for (final t in tabs) NavigationDestination(icon: Icon(t.icon), label: t.label)],
      ),
    );
  }
}
