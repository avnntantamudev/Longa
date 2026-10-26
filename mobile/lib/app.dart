import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/providers.dart';
import 'core/theme.dart';
import 'features/auth/auth_controller.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/onboarding_screen.dart';
import 'features/auth/pin_screens.dart';
import 'router.dart';

class LongaApp extends ConsumerStatefulWidget {
  const LongaApp({super.key});

  @override
  ConsumerState<LongaApp> createState() => _LongaAppState();
}

class _LongaAppState extends ConsumerState<LongaApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Pagamentos: o cliente da API pede o PIN numa janela e repete o pedido
    ref.read(apiClientProvider).pinRequester = () async {
      final context = rootNavigatorKey.currentContext;
      if (context == null) return false;
      return showPinPrompt(context, ref.read(authRepositoryProvider).verifyPin);
    };
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Ao voltar à app: pedir o PIN se passou mais de 1 hora; ao sair: registar o último uso.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final auth = ref.read(authControllerProvider.notifier);
    if (state == AppLifecycleState.resumed) auth.checkIdle();
    if (state == AppLifecycleState.paused && ref.read(authControllerProvider).stage == AuthStage.signedIn) auth.touch();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'LONGA',
      theme: buildLongaTheme(),
      debugShowCheckedModeBanner: false,
      routerConfig: ref.watch(routerProvider),
      // Qualquer toque conta como uso (para o bloqueio de 1 hora)
      builder: (context, child) => Listener(
        behavior: HitTestBehavior.translucent,
        onPointerDown: (_) => _throttledTouch(),
        child: child ?? const SizedBox.shrink(),
      ),
    );
  }

  DateTime _lastTouch = DateTime.fromMillisecondsSinceEpoch(0);

  void _throttledTouch() {
    final now = DateTime.now();
    if (now.difference(_lastTouch) > const Duration(minutes: 1) && ref.read(authControllerProvider).stage == AuthStage.signedIn) {
      _lastTouch = now;
      ref.read(authControllerProvider.notifier).touch();
    }
  }
}

/// Ecrãs de entrada conforme o estado (número+código, PIN, criar PIN, desbloquear, registo).
class AuthGate extends ConsumerWidget {
  const AuthGate({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return switch (ref.watch(authControllerProvider).stage) {
      AuthStage.loading => const Scaffold(body: Center(child: CircularProgressIndicator())),
      AuthStage.signedOut => const LoginScreen(),
      AuthStage.pinLogin => const PinLoginScreen(unlock: false),
      AuthStage.locked => const PinLoginScreen(unlock: true),
      AuthStage.pinSetup => const PinSetupScreen(),
      AuthStage.onboarding => const OnboardingScreen(),
      AuthStage.signedIn => const Scaffold(body: Center(child: CircularProgressIndicator())),
    };
  }
}
