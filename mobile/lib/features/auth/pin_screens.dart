import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../shared/pin_pad.dart';
import '../../shared/widgets.dart';
import 'auth_controller.dart';

/// Entrar só com o PIN (aparelho de confiança) ou desbloquear após 1 hora sem uso.
class PinLoginScreen extends ConsumerStatefulWidget {
  const PinLoginScreen({super.key, required this.unlock});

  final bool unlock;

  @override
  ConsumerState<PinLoginScreen> createState() => _PinLoginScreenState();
}

class _PinLoginScreenState extends ConsumerState<PinLoginScreen> {
  final _pad = GlobalKey<PinPadState>();
  String? _error;
  bool _busy = false;

  Future<void> _submit(String pin) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final auth = ref.read(authControllerProvider.notifier);
    try {
      widget.unlock ? await auth.unlock(pin) : await auth.loginWithPin(pin);
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
      _pad.currentState?.clear();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(authControllerProvider);
    final name = state.user?.name ?? state.name;
    final first = (name ?? '').split(' ').first;
    final auth = ref.read(authControllerProvider.notifier);

    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(children: [
              UserAvatar(name: name, photoPath: state.user?.photoThumbUrl, size: 72),
              const SizedBox(height: 12),
              Text(first.isEmpty ? 'Olá' : 'Olá, $first', style: Theme.of(context).textTheme.headlineSmall),
              Text(state.user?.maskedPhone ?? state.maskedPhone ?? '', style: const TextStyle(color: LongaColors.inkMuted)),
              const SizedBox(height: 8),
              Text(widget.unlock ? 'Por segurança, confirme o seu PIN.' : 'Introduza o seu PIN para entrar.'),
              const SizedBox(height: 24),
              PinPad(key: _pad, onComplete: _submit, error: _error, enabled: !_busy),
              const SizedBox(height: 16),
              TextButton(onPressed: auth.forgotPin, child: const Text('Esqueci o PIN')),
              if (widget.unlock)
                TextButton(onPressed: auth.logout, child: const Text('Sair', style: TextStyle(color: LongaColors.inkMuted)))
              else
                TextButton(onPressed: auth.otherAccount, child: const Text('Usar outra conta', style: TextStyle(color: LongaColors.inkMuted))),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Criar o PIN (escrever e confirmar). Recusa PINs fáceis no servidor.
class PinSetupScreen extends ConsumerStatefulWidget {
  const PinSetupScreen({super.key});

  @override
  ConsumerState<PinSetupScreen> createState() => _PinSetupScreenState();
}

class _PinSetupScreenState extends ConsumerState<PinSetupScreen> {
  final _pad = GlobalKey<PinPadState>();
  String? _first;
  String? _error;
  bool _busy = false;

  Future<void> _onComplete(String pin) async {
    if (_first == null) {
      setState(() {
        _first = pin;
        _error = null;
      });
      _pad.currentState?.clear();
      return;
    }
    if (pin != _first) {
      setState(() {
        _first = null;
        _error = 'Os dois PIN não coincidem. Comece de novo.';
      });
      _pad.currentState?.clear();
      return;
    }

    setState(() => _busy = true);
    try {
      await ref.read(authControllerProvider.notifier).setupPin(pin);
    } catch (e) {
      setState(() {
        _first = null;
        _error = errorText(e);
      });
      _pad.currentState?.clear();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final reset = ref.watch(authControllerProvider).resetPin;

    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(children: [
              Text(reset ? 'Crie um PIN novo' : 'Crie o seu PIN', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 4),
              Text(_first == null ? 'Da próxima vez entra só com estes 6 dígitos, sem código SMS.' : 'Escreva o mesmo PIN outra vez.',
                  textAlign: TextAlign.center, style: const TextStyle(color: LongaColors.inkMuted)),
              const SizedBox(height: 24),
              PinPad(key: _pad, onComplete: _onComplete, error: _error, enabled: !_busy),
              const SizedBox(height: 16),
              const Text('Evite datas de nascimento, números seguidos (123456) ou repetidos (111111). Não partilhe o PIN.',
                  textAlign: TextAlign.center, style: TextStyle(color: LongaColors.inkMuted, fontSize: 13)),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Janela para confirmar o PIN antes de pagar (ligada ao cliente da API: 423 pin_required).
Future<bool> showPinPrompt(BuildContext context, Future<void> Function(String pin) verify) async {
  final result = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.white,
    builder: (_) => _PinPromptSheet(verify: verify),
  );
  return result ?? false;
}

class _PinPromptSheet extends StatefulWidget {
  const _PinPromptSheet({required this.verify});

  final Future<void> Function(String pin) verify;

  @override
  State<_PinPromptSheet> createState() => _PinPromptSheetState();
}

class _PinPromptSheetState extends State<_PinPromptSheet> {
  final _pad = GlobalKey<PinPadState>();
  String? _error;
  bool _busy = false;

  Future<void> _submit(String pin) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.verify(pin);
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      setState(() => _error = errorText(e));
      _pad.currentState?.clear();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 12),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('Confirme com o seu PIN', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 4),
            const Text('Por segurança, pedimos o PIN antes de pagar.', style: TextStyle(color: LongaColors.inkMuted)),
            const SizedBox(height: 16),
            PinPad(key: _pad, onComplete: _submit, error: _error, enabled: !_busy),
            TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancelar')),
          ]),
        ),
      );
}
