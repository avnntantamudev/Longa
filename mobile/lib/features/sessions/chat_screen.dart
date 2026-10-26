import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/realtime/realtime.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import '../auth/auth_controller.dart';
import 'sessions_repository.dart';

/// Chat da aula. Os contactos pessoais escritos são escondidos pelo servidor nesta fase.
class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({super.key, required this.sessionId});

  final int sessionId;

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  final _text = TextEditingController();
  List<ChatMessage> _messages = const [];
  Object? _error;
  bool _sending = false;
  Timer? _poll;
  StreamSubscription<RealtimeEvent>? _realtime;

  @override
  void initState() {
    super.initState();
    _load();
    _realtime = ref.read(realtimeProvider).privateChannel('session.${widget.sessionId}').listen((e) {
      if (e.name.startsWith('message')) _load();
    });
    _poll = Timer.periodic(const Duration(seconds: 10), (_) => _load());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _realtime?.cancel();
    _text.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final list = await ref.read(sessionsRepositoryProvider).messages(widget.sessionId);
      if (mounted) setState(() => _messages = list);
    } catch (e) {
      if (mounted && _messages.isEmpty) setState(() => _error = e);
    }
  }

  Future<void> _send() async {
    final text = _text.text.trim();
    if (text.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      // client_id evita duplicados se a rede falhar e o utilizador repetir
      final message = await ref.read(sessionsRepositoryProvider).send(widget.sessionId, text, ApiClient.newIdempotencyKey());
      _text.clear();
      setState(() => _messages = [..._messages, message]);
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final me = ref.watch(authControllerProvider).user?.id;

    return Scaffold(
      appBar: AppBar(title: const Text('Chat da aula')),
      body: Column(children: [
        Expanded(
          child: _error != null && _messages.isEmpty
              ? LoadError(_error!, onRetry: _load)
              : ListView.builder(
                  reverse: true,
                  padding: const EdgeInsets.all(12),
                  itemCount: _messages.length,
                  itemBuilder: (_, i) {
                    final m = _messages[_messages.length - 1 - i];
                    final mine = m.senderId == me;
                    return Align(
                      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
                      child: Container(
                        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
                        margin: const EdgeInsets.symmetric(vertical: 4),
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(color: mine ? LongaColors.orange500 : Colors.white, borderRadius: BorderRadius.circular(16), border: mine ? null : Border.all(color: LongaColors.border)),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(m.body, style: TextStyle(color: mine ? Colors.white : LongaColors.ink)),
                          const SizedBox(height: 2),
                          Text(when(m.at), style: TextStyle(fontSize: 11, color: mine ? Colors.white70 : LongaColors.inkMuted)),
                        ]),
                      ),
                    );
                  },
                ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
            child: Row(children: [
              Expanded(child: TextField(controller: _text, minLines: 1, maxLines: 4, maxLength: 2000, decoration: const InputDecoration(hintText: 'Escreva uma mensagem', counterText: ''))),
              const SizedBox(width: 8),
              IconButton.filled(onPressed: _sending ? null : _send, icon: const Icon(Icons.send)),
            ]),
          ),
        ),
      ]),
    );
  }
}
