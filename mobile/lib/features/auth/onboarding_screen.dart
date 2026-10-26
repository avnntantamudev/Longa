import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'auth_controller.dart';

/// Registo: nome e perfil(is). O perfil completo de formador/empresa continua na PWA nesta versão.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final _name = TextEditingController();
  final _profiles = <String>{'student'};
  bool _accept = false;
  bool _busy = false;
  String? _error;

  static const _options = {
    'student': ('Quero aprender', 'Peço aulas a formadores perto de mim.'),
    'trainer': ('Quero ensinar', 'Recebo pedidos de alunos (TRIM TRIM).'),
    'company_rh': ('Sou de uma empresa (RH)', 'Acompanho as formações da equipa.'),
  };

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_accept) {
      setState(() => _error = 'Aceite os termos e a política de privacidade.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).completeOnboarding(_name.text.trim(), _profiles.toList());
    } catch (e) {
      setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Bem-vindo à LONGA')),
        body: ListView(padding: const EdgeInsets.all(20), children: [
          TextField(controller: _name, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Nome completo')),
          const SizedBox(height: 20),
          const Text('Como vai usar a LONGA?', style: TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          for (final entry in _options.entries)
            CheckboxListTile(
              value: _profiles.contains(entry.key),
              onChanged: (v) => setState(() => v == true ? _profiles.add(entry.key) : _profiles.remove(entry.key)),
              title: Text(entry.value.$1),
              subtitle: Text(entry.value.$2),
              contentPadding: EdgeInsets.zero,
            ),
          CheckboxListTile(
            value: _accept,
            onChanged: (v) => setState(() => _accept = v ?? false),
            title: const Text('Aceito os termos de utilização e a política de privacidade.'),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
          ),
          if (_profiles.contains('trainer'))
            const Padding(
              padding: EdgeInsets.only(bottom: 12),
              child: Text('Formadores: depois completam o perfil (foto, áreas, local e BI) para receberem pedidos.', style: TextStyle(color: LongaColors.inkMuted)),
            ),
          if (_error != null) ...[Notice(_error!), const SizedBox(height: 12)],
          FilledButton(onPressed: _busy || _profiles.isEmpty ? null : _submit, child: Text(_busy ? 'A guardar…' : 'Continuar')),
        ]),
      );
}
