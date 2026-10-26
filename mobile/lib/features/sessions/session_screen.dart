import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_error.dart';
import '../../core/format.dart';
import '../../core/realtime/realtime.dart';
import '../../core/theme.dart';
import '../../shared/widgets.dart';
import 'session_models.dart';
import 'sessions_repository.dart';

/// Ecrã da aula: estado, próximos passos (só as ações que o servidor permite), histórico e chat.
class SessionScreen extends ConsumerStatefulWidget {
  const SessionScreen({super.key, required this.sessionId});

  final int sessionId;

  @override
  ConsumerState<SessionScreen> createState() => _SessionScreenState();
}

class _SessionScreenState extends ConsumerState<SessionScreen> {
  TrainingSession? _session;
  List<SessionEventItem> _events = const [];
  Object? _error;
  String? _notice;
  bool _busy = false;
  Timer? _poll;
  StreamSubscription<RealtimeEvent>? _realtime;

  /// Uma chave por ação; mantida se a rede falhar, para repetir sem duplicar.
  final _keys = <String, String>{};

  SessionsRepository get _repo => ref.read(sessionsRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
    // Tempo real (Reverb) quando disponível; atualização periódica de segurança
    _realtime = ref.read(realtimeProvider).privateChannel('session.${widget.sessionId}').listen((_) => _load());
    _poll = Timer.periodic(const Duration(seconds: 20), (_) => _load());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _realtime?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final results = await Future.wait([_repo.show(widget.sessionId), _repo.events(widget.sessionId)]);
      if (!mounted) return;
      setState(() {
        _session = results[0] as TrainingSession;
        _events = results[1] as List<SessionEventItem>;
        _error = null;
      });
    } catch (e) {
      if (mounted && _session == null) setState(() => _error = e);
    }
  }

  Future<void> _run(String action, {Map<String, dynamic>? data, String? success}) async {
    setState(() {
      _busy = true;
      _notice = null;
    });
    final key = _keys.putIfAbsent(action, ApiClient.newIdempotencyKey);
    try {
      final updated = await _repo.act(widget.sessionId, action, data: data, idempotencyKey: key);
      _keys.remove(action);
      setState(() {
        _session = updated;
        _notice = success;
      });
      unawaited(_load());
    } on ApiError catch (e) {
      if (mounted) showSnack(context, e.userMessage, error: true);
      // Resposta definitiva do servidor: a próxima tentativa é uma ação nova; sem rede, repete com a mesma chave
      if (!e.isNetwork) _keys.remove(action);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _start() async {
    setState(() => _busy = true);
    try {
      final s = await _repo.start(widget.sessionId);
      setState(() {
        _session = s;
        _notice = 'Aula iniciada. Bom trabalho!';
      });
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _finish() async {
    setState(() => _busy = true);
    try {
      await _repo.finish(widget.sessionId);
      if (mounted) await context.push('/aulas/${widget.sessionId}/qr');
      await _load();
    } catch (e) {
      if (mounted) showSnack(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _confirmDialog(String title, String body, {String ok = 'Confirmar'}) async =>
      await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text(title),
          content: Text(body),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Voltar')),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(ok)),
          ],
        ),
      ) ??
      false;

  Future<void> _pay() async {
    final method = await showModalBottomSheet<String>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const ListTile(title: Text('Como quer pagar?', style: TextStyle(fontWeight: FontWeight.w700))),
          ListTile(leading: const Icon(Icons.phone_android), title: const Text('Multicaixa Express'), onTap: () => Navigator.pop(c, 'multicaixa_express')),
          ListTile(leading: const Icon(Icons.receipt_long), title: const Text('Referência'), onTap: () => Navigator.pop(c, 'reference')),
        ]),
      ),
    );
    // O servidor pede o PIN (423) e o cliente da API mostra o teclado e repete o pedido
    if (method != null) await _run('pay', data: {'method': method}, success: 'Pagamento confirmado. A aula está agendada.');
  }

  Future<void> _cancel(TrainingSession s) async {
    final late = s.scheduledAt.difference(DateTime.now()) < const Duration(hours: 6);
    final body = s.isTrainer
        ? 'O aluno é reembolsado na totalidade. Cancelamentos em cima da hora afetam a sua pontuação.'
        : late
            ? 'Faltam menos de 6 horas: o formador fica com 25% do valor e recebe o reembolso do resto.'
            : 'Recebe o reembolso total.';
    if (await _confirmDialog('Cancelar aula?', body, ok: 'Cancelar aula')) {
      await _run('cancel', data: {'reason': null}, success: 'Aula cancelada.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _session;
    if (s == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Aula')),
        body: _error == null ? const Center(child: CircularProgressIndicator()) : LoadError(_error!, onRetry: _load),
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('Aula'), actions: [
        IconButton(tooltip: 'Chat', icon: const Icon(Icons.chat_bubble_outline), onPressed: () => context.push('/aulas/${s.id}/chat')),
      ]),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          _Counterpart(session: s),
          const SizedBox(height: 12),
          LongaCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(s.title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
                StateBadge(s.stateLabel),
              ]),
              const SizedBox(height: 12),
              _Info('Quando', '${when(s.scheduledAt)} · ${s.durationMinutes} min'),
              _Info('Onde', s.modality == 'online' ? 'Online' : '${s.neighborhood}, ${s.municipality}'),
              _Info('Valor', kwanza(s.amount)),
              if (s.isTrainer && s.trainerNet != null) _Info('Recebe', '${kwanza(s.trainerNet!)}${s.commissionPercent != null ? ' (comissão ${s.commissionPercent}%)' : ''}'),
              _Info('Pagamento', s.isCash ? 'Em mão' : 'Pela LONGA'),
            ]),
          ),
          const SizedBox(height: 12),
          if (_notice != null) ...[Notice(_notice!, success: true), const SizedBox(height: 12)],
          _NextSteps(session: s, busy: _busy, onPay: _pay, onStart: _start, onFinish: _finish, onCancel: () => _cancel(s), onRun: _run, confirmDialog: _confirmDialog),
          if (_events.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text('Histórico', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            for (final e in _events)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.circle, size: 10, color: LongaColors.orange500),
                title: Text(e.label),
                subtitle: Text(when(e.at)),
              ),
          ],
        ]),
      ),
    );
  }
}

class _Info extends StatelessWidget {
  const _Info(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 96, child: Text(label, style: const TextStyle(color: LongaColors.inkMuted))),
          Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600))),
        ]),
      );
}

class _Counterpart extends StatelessWidget {
  const _Counterpart({required this.session});

  final TrainingSession session;

  @override
  Widget build(BuildContext context) {
    final p = session.counterpart;
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(color: LongaColors.navy900, borderRadius: BorderRadius.circular(24)),
      child: Row(children: [
        UserAvatar(name: p.name, photoPath: p.photoUrl, size: 64),
        const SizedBox(width: 16),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(session.isTrainer ? 'Aluno' : 'Formador', style: const TextStyle(color: LongaColors.orange400, fontWeight: FontWeight.w600)),
            Text(p.name ?? '', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Wrap(spacing: 6, runSpacing: 4, children: [
              StateBadge(session.skill, color: Colors.white, background: Colors.white24),
              if (p.verified) const StateBadge('Verificado LONGA', color: LongaColors.success, background: LongaColors.successSoft),
              if (p.vip) const StateBadge('⭐ VIP', color: Colors.white, background: LongaColors.orange500),
            ]),
          ]),
        ),
      ]),
    );
  }
}

/// Próximos passos conforme o estado e as ações permitidas.
class _NextSteps extends StatelessWidget {
  const _NextSteps({
    required this.session,
    required this.busy,
    required this.onPay,
    required this.onStart,
    required this.onFinish,
    required this.onCancel,
    required this.onRun,
    required this.confirmDialog,
  });

  final TrainingSession session;
  final bool busy;
  final VoidCallback onPay;
  final VoidCallback onStart;
  final VoidCallback onFinish;
  final VoidCallback onCancel;
  final Future<void> Function(String action, {Map<String, dynamic>? data, String? success}) onRun;
  final Future<bool> Function(String title, String body, {String ok}) confirmDialog;

  @override
  Widget build(BuildContext context) {
    final s = session;
    final children = <Widget>[];
    void text(String value, {bool bold = false}) => children.add(Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.normal, color: bold ? LongaColors.ink : LongaColors.inkMuted)),
        ));
    void button(String label, VoidCallback? onPressed, {bool outlined = false}) => children.add(Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: outlined ? OutlinedButton(onPressed: busy ? null : onPressed, child: Text(label)) : FilledButton(onPressed: busy ? null : onPressed, child: Text(label)),
        ));

    switch (s.state) {
      case 'pending_payment':
        if (s.isTrainer) {
          text('À espera do pagamento do aluno (até 10 minutos).');
        } else {
          text('Pague para garantir a aula', bold: true);
          text('O valor fica retido pela LONGA e só é libertado ao formador depois de confirmar a aula.');
          if (s.can(SessionAction.pay)) {
            button('Pagar ${kwanza(s.amount)}', onPay);
          } else {
            text('O prazo de pagamento terminou.');
          }
        }
      case 'scheduled':
        text(s.isTrainer ? 'Inicie a aula quando chegar (a partir de 15 minutos antes).' : 'O formador inicia a aula quando chegar.');
        if (s.can(SessionAction.start)) button('Iniciar aula', onStart);
      case 'in_progress':
        text('Aula a decorrer', bold: true);
        text(s.isTrainer ? 'No fim, toque em "Terminar aula" e mostre o QR ao aluno.' : 'No fim, o formador mostra-lhe um QR para confirmar a aula.');
        if (s.can(SessionAction.finish)) button('Terminar aula', onFinish);
      case 'awaiting_confirmation':
        if (s.isTrainer) {
          text('À espera da confirmação do aluno', bold: true);
          if (s.trainerNet != null) text('${kwanza(s.trainerNet!)} pendentes, libertados quando o aluno ler o QR.');
          if (s.can(SessionAction.showQr)) button('Mostrar QR', () => context.push('/aulas/${s.id}/qr'));
        } else {
          text('A aula aconteceu?', bold: true);
          if (s.confirmationDeadline != null) text('Prazo para confirmar: ${when(s.confirmationDeadline!)}.');
          button('Escanear QR para confirmar', () => context.push('/aulas/${s.id}/confirmar'));
          if (s.can(SessionAction.confirmWithoutQr)) {
            button('Confirmar sem QR', () async {
              if (await confirmDialog('Confirmar a aula?', 'O valor é libertado ao formador e não pode ser revertido.')) {
                await onRun('confirm-without-qr', success: 'Aula confirmada. Obrigado!');
              }
            }, outlined: true);
          }
          children.add(TextButton(
            onPressed: () => context.push('/aulas/${s.id}/disputa'),
            child: const Text('A aula não aconteceu ou houve um problema', style: TextStyle(color: LongaColors.danger)),
          ));
        }
      case 'completed':
        text('Aula concluída', bold: true);
        if (s.isTrainer && !s.isCash && s.trainerNet != null) text('${kwanza(s.trainerNet!)} na sua carteira.');
        if (s.can(SessionAction.confirmCash)) {
          text('Confirme que pagou ${kwanza(s.amount)} ao formador em mão.');
          button('Confirmei que paguei', () => onRun('confirm-cash', success: 'Pagamento em mão confirmado.'));
        }
      case 'no_show_trainer' || 'no_show_student':
        text(s.state == 'no_show_trainer' ? 'Falta do formador reportada' : 'Falta do aluno reportada', bold: true);
        if (s.noShowContestUntil != null) text('Pode ser contestada até ${when(s.noShowContestUntil!)}.');
        if (s.can(SessionAction.dispute)) button('Contestar a falta', () => context.push('/aulas/${s.id}/disputa?contestar=1'));
      case 'disputed':
        text('Disputa em análise pela LONGA. O valor fica retido até à decisão.');
      default:
        text('${s.stateLabel}.');
    }

    if (s.can(SessionAction.cancel)) button('Cancelar aula', onCancel, outlined: true);
    if (s.can(SessionAction.noShow)) {
      children.add(TextButton(
        onPressed: busy
            ? null
            : () async {
                if (await confirmDialog('Reportar falta?', 'A outra parte tem 24 horas para contestar. Sem contestação, aplica-se a política de faltas.', ok: 'Reportar falta')) {
                  await onRun('no-show', data: const {}, success: 'Falta reportada.');
                }
              },
        child: Text(s.isTrainer ? 'O aluno não apareceu' : 'O formador não apareceu', style: const TextStyle(color: LongaColors.danger)),
      ));
    }

    return LongaCard(child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children));
  }
}
