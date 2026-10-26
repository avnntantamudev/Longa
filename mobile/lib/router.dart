import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'app.dart';
import 'features/auth/auth_controller.dart';
import 'features/home/home_screen.dart';
import 'features/home/shell.dart';
import 'features/profile/profile_screen.dart';
import 'features/requests/create_request_screen.dart';
import 'features/requests/request_status_screen.dart';
import 'features/sessions/chat_screen.dart';
import 'features/sessions/dispute_screen.dart';
import 'features/sessions/qr_screen.dart';
import 'features/sessions/scan_screen.dart';
import 'features/sessions/session_screen.dart';
import 'features/sessions/sessions_screen.dart';
import 'features/trainer/offer_screen.dart';
import 'features/trainer/offers_screen.dart';
import 'features/wallet/earnings_screen.dart';
import 'features/wallet/vip_screen.dart';
import 'features/wallet/wallet_screen.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>();

/// Avisa o GoRouter quando o estado de autenticação muda (para refazer o redirect).
class _AuthListenable extends ChangeNotifier {
  _AuthListenable(Ref ref) {
    ref.listen<AuthState>(authControllerProvider, (_, __) => notifyListeners());
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _AuthListenable(ref);
  ref.onDispose(refresh.dispose);

  int id(GoRouterState s) => int.parse(s.pathParameters['id']!);

  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/',
    refreshListenable: refresh,
    // Sem sessão desbloqueada, a app mostra os ecrãs de entrada (AuthGate) e nada mais
    redirect: (context, state) {
      final signedIn = ref.read(authControllerProvider).stage == AuthStage.signedIn;
      if (!signedIn) return state.matchedLocation == '/entrar' ? null : '/entrar';
      if (state.matchedLocation == '/entrar') return '/';
      return null;
    },
    routes: [
      GoRoute(path: '/entrar', builder: (_, __) => const AuthGate()),
      ShellRoute(
        builder: (context, state, child) => AppShell(location: state.matchedLocation, child: child),
        routes: [
          GoRoute(path: '/', builder: (_, __) => const HomeScreen()),
          GoRoute(path: '/pedir', builder: (_, __) => const CreateRequestScreen()),
          GoRoute(path: '/ofertas', builder: (_, __) => const OffersScreen()),
          GoRoute(path: '/aulas', builder: (_, __) => const SessionsScreen()),
          GoRoute(path: '/carteira', builder: (_, __) => const WalletScreen()),
          GoRoute(path: '/perfil', builder: (_, __) => const ProfileScreen()),
        ],
      ),
      GoRoute(path: '/pedidos/:id', builder: (_, s) => RequestStatusScreen(requestId: id(s))),
      GoRoute(path: '/ofertas/:id', builder: (_, s) => OfferScreen(offerId: id(s))),
      GoRoute(path: '/aulas/:id', builder: (_, s) => SessionScreen(sessionId: id(s))),
      GoRoute(path: '/aulas/:id/qr', builder: (_, s) => QrScreen(sessionId: id(s))),
      GoRoute(path: '/aulas/:id/confirmar', builder: (_, s) => ScanScreen(sessionId: id(s))),
      GoRoute(path: '/aulas/:id/disputa', builder: (_, s) => DisputeScreen(sessionId: id(s), contest: s.uri.queryParameters['contestar'] == '1')),
      GoRoute(path: '/aulas/:id/chat', builder: (_, s) => ChatScreen(sessionId: id(s))),
      GoRoute(path: '/carteira/ganhos', builder: (_, __) => const EarningsScreen()),
      GoRoute(path: '/vip', builder: (_, __) => const VipScreen()),
    ],
  );
});
