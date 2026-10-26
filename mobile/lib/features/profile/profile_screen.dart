import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/format.dart';
import '../../core/theme.dart';
import '../../shared/pin_pad.dart';
import '../../shared/widgets.dart';
import '../auth/auth_controller.dart';
import 'profile_repository.dart';

final _devicesProvider = FutureProvider.autoDispose<List<TrustedDeviceInfo>>((ref) => ref.watch(profileRepositoryProvider).devices());
final _companyLinksProvider = FutureProvider.autoDispose<List<CompanyLink>>((ref) => ref.watch(profileRepositoryProvider).companyLinks());

/// Perfil: foto, perfis, empresas (consentimento), segurança (alterar PIN, aparelhos) e sair.
class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  bool _uploading = false;

  static const _roleLabels = {'student': 'Aluno', 'trainer': 'Formador', 'company_rh': 'Empresa (RH)', 'admin': 'Administrador'};

  Future<void> _changePhoto() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(leading: const Icon(Icons.photo_camera), title: const Text('Tirar foto'), onTap: () => Navigator.pop(c, ImageSource.camera)),
          ListTile(leading: const Icon(Icons.photo_library), title: const Text('Escolher da galeria'), onTap: () => Navigator.pop(c, ImageSource.gallery)),
        ]),
      ),
    );
    if (source == null) return;
    final file = await ImagePicker().pickImage(source: source, maxWidth: 1024, maxHeight: 1024, imageQuality: 90, preferredCameraDevice: CameraDevice.front);
    if (file == null) return;

    setState(() => _uploading = true);
    try {
      await ref.read(profileRepositoryProvider).uploadPhoto(file.path);
      await ref.read(authControllerProvider.notifier).refreshUser();
      if (mounted) showSnack(context, 'Foto atualizada.');
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _changePin() async {
    final done = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, backgroundColor: Colors.white, builder: (_) => const _ChangePinSheet());
    if (done == true && mounted) {
      showSnack(context, 'PIN alterado. Os outros aparelhos saíram da conta.');
      ref.invalidate(_devicesProvider);
    }
  }

  Future<void> _decide(CompanyLink link, String decision) async {
    try {
      await ref.read(profileRepositoryProvider).decideCompanyLink(link.id, decision);
      ref.invalidate(_companyLinksProvider);
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authControllerProvider).user;
    final devices = ref.watch(_devicesProvider);
    final links = ref.watch(_companyLinksProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Perfil')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        LongaCard(
          child: Row(children: [
            Stack(children: [
              UserAvatar(name: user?.name, photoPath: user?.photoUrl, size: 80),
              if (_uploading) const Positioned.fill(child: CircularProgressIndicator()),
            ]),
            const SizedBox(width: 16),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(user?.name ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                Text(user?.maskedPhone ?? '', style: const TextStyle(color: LongaColors.inkMuted)),
                const SizedBox(height: 6),
                Wrap(spacing: 6, children: [for (final r in user?.roles ?? const <String>[]) StateBadge(_roleLabels[r] ?? r)]),
                TextButton(onPressed: _uploading ? null : _changePhoto, child: Text(user?.photoUrl == null ? 'Carregar foto' : 'Trocar foto')),
              ]),
            ),
          ]),
        ),
        if (user?.isTrainer == true && user?.photoUrl == null) ...[
          const SizedBox(height: 8),
          const Notice('Carregue a sua foto de perfil. Sem foto deixa de receber pedidos TRIM TRIM.'),
        ],
        links.maybeWhen(
          data: (list) => list.isEmpty
              ? const SizedBox.shrink()
              : Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: LongaCard(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      const Text('Empresas', style: TextStyle(fontWeight: FontWeight.w700)),
                      for (final l in list) ...[
                        const SizedBox(height: 8),
                        Text(switch (l.consentStatus) {
                          'pending' => '${l.company} quer acompanhar as suas formações (aulas, horas e custos; nunca o chat).',
                          'accepted' => '${l.company} acompanha as suas formações.',
                          _ => '${l.company} não tem acesso às suas formações.',
                        }),
                        const SizedBox(height: 4),
                        Row(children: [
                          if (l.consentStatus != 'accepted') ...[
                            Expanded(child: FilledButton(onPressed: () => _decide(l, 'accepted'), child: const Text('Autorizar'))),
                            if (l.consentStatus == 'pending') ...[const SizedBox(width: 8), Expanded(child: OutlinedButton(onPressed: () => _decide(l, 'declined'), child: const Text('Recusar')))],
                          ] else
                            Expanded(child: OutlinedButton(onPressed: () => _decide(l, 'revoked'), child: const Text('Retirar autorização'))),
                        ]),
                      ],
                    ]),
                  ),
                ),
          orElse: () => const SizedBox.shrink(),
        ),
        const SizedBox(height: 12),
        LongaCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('Segurança', style: TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _changePin, child: const Text('Alterar PIN')),
            const SizedBox(height: 12),
            const Text('Aparelhos que entram só com o PIN', style: TextStyle(fontWeight: FontWeight.w600)),
            devices.when(
              loading: () => const Padding(padding: EdgeInsets.all(12), child: Center(child: CircularProgressIndicator())),
              error: (e, _) => Text(errorText(e)),
              data: (list) => Column(children: [
                for (final d in list)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text('${d.name ?? 'Aparelho'}${d.current ? ' (este)' : ''}'),
                    subtitle: d.lastUsedAt == null ? null : Text('Último uso: ${when(d.lastUsedAt!)}'),
                    trailing: d.current
                        ? null
                        : TextButton(
                            onPressed: () async {
                              await ref.read(profileRepositoryProvider).removeDevice(d.id);
                              ref.invalidate(_devicesProvider);
                            },
                            child: const Text('Remover', style: TextStyle(color: LongaColors.danger)),
                          ),
                  ),
              ]),
            ),
          ]),
        ),
        const SizedBox(height: 12),
        OutlinedButton(onPressed: () => ref.read(authControllerProvider.notifier).logout(), child: const Text('Sair')),
        TextButton(onPressed: () => ref.read(authControllerProvider.notifier).otherAccount(), child: const Text('Usar outra conta neste aparelho')),
      ]),
    );
  }
}

/// Alterar PIN: atual → novo → confirmar.
class _ChangePinSheet extends ConsumerStatefulWidget {
  const _ChangePinSheet();

  @override
  ConsumerState<_ChangePinSheet> createState() => _ChangePinSheetState();
}

class _ChangePinSheetState extends ConsumerState<_ChangePinSheet> {
  final _pad = GlobalKey<PinPadState>();
  String? _current;
  String? _next;
  String? _error;
  bool _busy = false;

  String get _title => _current == null ? 'PIN atual' : (_next == null ? 'PIN novo' : 'Confirme o PIN novo');

  Future<void> _onComplete(String pin) async {
    _pad.currentState?.clear();
    if (_current == null) {
      setState(() => _current = pin);
      return;
    }
    if (_next == null) {
      setState(() => _next = pin);
      return;
    }
    if (pin != _next) {
      setState(() {
        _next = null;
        _error = 'Os dois PIN não coincidem.';
      });
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(authRepositoryProvider).changePin(_current!, pin);
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      setState(() {
        _current = null;
        _next = null;
        _error = errorText(e);
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(_title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 16),
            PinPad(key: _pad, onComplete: _onComplete, error: _error, enabled: !_busy),
            TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancelar')),
          ]),
        ),
      );
}
