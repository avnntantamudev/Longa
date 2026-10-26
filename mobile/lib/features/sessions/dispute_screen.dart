import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_error.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'sessions_repository.dart';

/// Abrir disputa (aluno durante a confirmação) ou contestar uma falta. Evidências: até 5 imagens ou
/// PDF de 5 MB (validado também no servidor, pelo conteúdo).
class DisputeScreen extends ConsumerStatefulWidget {
  const DisputeScreen({super.key, required this.sessionId, required this.contest});

  final int sessionId;
  final bool contest;

  @override
  ConsumerState<DisputeScreen> createState() => _DisputeScreenState();
}

class _DisputeScreenState extends ConsumerState<DisputeScreen> {
  static const _maxFiles = 5;
  static const _maxBytes = 5 * 1024 * 1024;

  final _description = TextEditingController();
  final _files = <PlatformFile>[];
  final _key = ApiClient.newIdempotencyKey();
  late String _reason = widget.contest ? 'no_show_contest' : 'lesson_not_happened';
  String? _error;
  bool _sending = false;

  Map<String, String> get _reasons => widget.contest
      ? const {'no_show_contest': 'Eu estive presente / a falta não é verdade'}
      : const {'lesson_not_happened': 'A aula não aconteceu', 'quality': 'Problema com a aula', 'other': 'Outro motivo'};

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final result = await FilePicker.platform.pickFiles(allowMultiple: true, type: FileType.custom, allowedExtensions: const ['jpg', 'jpeg', 'png', 'webp', 'pdf']);
    if (result == null) return;
    final rejected = result.files.where((f) => f.size > _maxBytes || f.path == null).length;
    setState(() {
      _files.addAll(result.files.where((f) => f.size <= _maxBytes && f.path != null));
      if (_files.length > _maxFiles) _files.removeRange(_maxFiles, _files.length);
      _error = rejected > 0 ? 'Só imagens (JPG, PNG, WebP) ou PDF até 5 MB.' : null;
    });
  }

  Future<void> _submit() async {
    if (_description.text.trim().length < 10) {
      setState(() => _error = 'Descreva o que aconteceu (pelo menos 10 caracteres).');
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await ref.read(sessionsRepositoryProvider).openDispute(
            widget.sessionId,
            reason: _reason,
            description: _description.text.trim(),
            filePaths: [for (final f in _files) f.path!],
            idempotencyKey: _key,
          );
      if (mounted) {
        showSnack(context, 'Disputa enviada. A LONGA vai analisar.');
        Navigator.of(context).pop();
      }
    } on ApiError catch (e) {
      setState(() => _error = e.userMessage);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.contest ? 'Contestar falta' : 'Abrir disputa')),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          const Text('A LONGA analisa e decide. Até lá, o valor da aula fica retido e ninguém o recebe.', style: TextStyle(color: LongaColors.inkMuted)),
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            value: _reason,
            decoration: const InputDecoration(labelText: 'Motivo'),
            items: [for (final r in _reasons.entries) DropdownMenuItem(value: r.key, child: Text(r.value))],
            onChanged: (v) => setState(() => _reason = v ?? _reason),
          ),
          const SizedBox(height: 12),
          TextField(controller: _description, maxLines: 5, maxLength: 2000, decoration: const InputDecoration(labelText: 'O que aconteceu?')),
          const SizedBox(height: 8),
          OutlinedButton.icon(onPressed: _files.length >= _maxFiles ? null : _pick, icon: const Icon(Icons.attach_file), label: const Text('Juntar evidências (opcional)')),
          for (final f in _files)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: Icon(f.extension == 'pdf' ? Icons.picture_as_pdf : Icons.image),
              title: Text(f.name, overflow: TextOverflow.ellipsis),
              trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => setState(() => _files.remove(f))),
            ),
          const SizedBox(height: 12),
          if (_error != null) ...[Notice(_error!), const SizedBox(height: 12)],
          FilledButton(onPressed: _sending ? null : _submit, child: Text(_sending ? 'A enviar…' : 'Enviar')),
        ]),
      );
}
