import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/config.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'auth_controller.dart';

/// Número + código (SMS ou WhatsApp). Usado uma vez por aparelho; depois entra com o PIN.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  String _channel = 'sms';
  String? _sentTo;
  String? _error;
  bool _busy = false;
  int _resendIn = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _phone.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _send([String? channel]) async {
    setState(() {
      _busy = true;
      _error = null;
      if (channel != null) _channel = channel;
    });
    try {
      final wait = await ref.read(authControllerProvider.notifier).requestCode(_phone.text.trim(), _channel);
      setState(() => _sentTo = _phone.text.trim());
      _startCountdown(wait);
    } catch (e) {
      setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startCountdown(int seconds) {
    _timer?.cancel();
    setState(() => _resendIn = seconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted || _resendIn <= 1) {
        t.cancel();
        if (mounted) setState(() => _resendIn = 0);
      } else {
        setState(() => _resendIn--);
      }
    });
  }

  Future<void> _verify() async {
    if (_code.text.length != AppConfig.otpLength || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).verifyCode(_sentTo!, _code.text);
    } catch (e) {
      setState(() {
        _error = errorText(e);
        _code.clear();
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final resetPin = ref.watch(authControllerProvider).resetPin;
    final channelLabel = _channel == 'whatsapp' ? 'WhatsApp' : 'SMS';

    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(24), children: [
          const SizedBox(height: 24),
          const Text('LONGA', style: TextStyle(fontSize: 32, fontWeight: FontWeight.w800, color: LongaColors.navy900)),
          const Text('Kulonga = Ensinar', style: TextStyle(color: LongaColors.orange600, fontWeight: FontWeight.w600)),
          const SizedBox(height: 32),
          if (_sentTo == null) ...[
            Text(resetPin ? 'Esqueceu o PIN?' : 'Entrar ou criar conta', style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 4),
            Text(resetPin ? 'Confirme o seu número com um código e crie um PIN novo.' : 'Enviamos um código uma vez; depois entra só com o seu PIN.',
                style: const TextStyle(color: LongaColors.inkMuted)),
            const SizedBox(height: 24),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              autofillHints: const [AutofillHints.telephoneNumber],
              decoration: const InputDecoration(labelText: 'Telefone', prefixText: '+244 ', hintText: '923 456 789'),
            ),
            const SizedBox(height: 16),
            const Text('Receber o código por', style: TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: const [ButtonSegment(value: 'sms', label: Text('SMS')), ButtonSegment(value: 'whatsapp', label: Text('WhatsApp'))],
              selected: {_channel},
              onSelectionChanged: (v) => setState(() => _channel = v.first),
            ),
            const SizedBox(height: 16),
            if (_error != null) ...[Notice(_error!), const SizedBox(height: 12)],
            FilledButton(onPressed: _busy ? null : () => _send(), child: Text(_busy ? 'A enviar…' : 'Receber código')),
          ] else ...[
            Text('Introduza o código', style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 4),
            Text('Enviámos um código de ${AppConfig.otpLength} dígitos por $channelLabel para $_sentTo.', style: const TextStyle(color: LongaColors.inkMuted)),
            const SizedBox(height: 24),
            TextField(
              controller: _code,
              autofocus: true,
              keyboardType: TextInputType.number,
              autofillHints: const [AutofillHints.oneTimeCode],
              maxLength: AppConfig.otpLength,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 28, letterSpacing: 12, fontWeight: FontWeight.w700),
              decoration: const InputDecoration(labelText: 'Código', counterText: ''),
              onChanged: (v) => v.length == AppConfig.otpLength ? _verify() : null,
            ),
            const SizedBox(height: 12),
            if (_error != null) ...[Notice(_error!), const SizedBox(height: 12)],
            FilledButton(onPressed: _busy ? null : _verify, child: Text(_busy ? 'A verificar…' : 'Entrar')),
            const SizedBox(height: 12),
            if (_resendIn > 0)
              Text('Pode pedir outro código dentro de $_resendIn s.', textAlign: TextAlign.center, style: const TextStyle(color: LongaColors.inkMuted))
            else
              Wrap(alignment: WrapAlignment.center, spacing: 8, children: [
                TextButton(onPressed: _busy ? null : () => _send('sms'), child: const Text('Reenviar por SMS')),
                TextButton(onPressed: _busy ? null : () => _send('whatsapp'), child: const Text('Por WhatsApp')),
              ]),
            TextButton(onPressed: () => setState(() => _sentTo = null), child: const Text('Alterar número')),
          ],
        ]),
      ),
    );
  }
}
