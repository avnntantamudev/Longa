import '../../core/format.dart';

/// Ações que o participante pode fazer agora (calculadas no servidor; a app só mostra estes botões).
enum SessionAction { pay, start, cancel, noShow, finish, showQr, confirm, confirmWithoutQr, dispute, confirmCash, unknown }

SessionAction _action(String value) => switch (value) {
      'pay' => SessionAction.pay,
      'start' => SessionAction.start,
      'cancel' => SessionAction.cancel,
      'no_show' => SessionAction.noShow,
      'finish' => SessionAction.finish,
      'show_qr' => SessionAction.showQr,
      'confirm' => SessionAction.confirm,
      'confirm_without_qr' => SessionAction.confirmWithoutQr,
      'dispute' => SessionAction.dispute,
      'confirm_cash' => SessionAction.confirmCash,
      _ => SessionAction.unknown,
    };

class Person {
  const Person({this.name, this.photoUrl, this.photoThumbUrl, this.verified = false, this.vip = false, this.bio});

  factory Person.fromJson(Map<String, dynamic>? json) => json == null
      ? const Person()
      : Person(
          name: json['name'] as String?,
          photoUrl: json['photo_url'] as String?,
          photoThumbUrl: json['photo_thumb_url'] as String?,
          verified: json['verified'] == true,
          vip: json['vip'] == true,
          bio: json['bio'] as String?,
        );

  final String? name;
  final String? photoUrl;
  final String? photoThumbUrl;
  final bool verified;
  final bool vip;
  final String? bio;
}

/// Aula vista por um dos participantes (SessionResource).
class TrainingSession {
  TrainingSession({
    required this.id,
    required this.state,
    required this.stateLabel,
    required this.actions,
    required this.title,
    required this.skill,
    required this.scheduledAt,
    required this.durationMinutes,
    required this.paymentMethod,
    required this.amount,
    required this.role,
    required this.modality,
    required this.province,
    required this.municipality,
    required this.neighborhood,
    required this.latitude,
    required this.longitude,
    this.trainerNet,
    this.commissionPercent,
    this.paymentDueAt,
    this.confirmationDeadline,
    this.noShowContestUntil,
    this.counterpart = const Person(),
  });

  factory TrainingSession.fromJson(Map<String, dynamic> json) {
    final role = json['role'] as String? ?? 'student';
    return TrainingSession(
      id: json['id'] as int,
      state: json['state'] as String,
      stateLabel: (json['state_label'] ?? json['state']) as String,
      actions: ((json['actions'] as List?) ?? const []).map((a) => _action(a.toString())).toSet(),
      title: json['title'] as String? ?? '',
      skill: json['skill'] as String? ?? '',
      scheduledAt: DateTime.parse(json['scheduled_at'] as String),
      durationMinutes: asInt(json['duration_minutes'], 60),
      paymentMethod: json['payment_method'] as String? ?? 'electronic',
      amount: asInt(json['amount']),
      trainerNet: json['trainer_net'] == null ? null : asInt(json['trainer_net']),
      commissionPercent: json['commission_percent'] == null ? null : asInt(json['commission_percent']),
      role: role,
      modality: json['modality'] as String? ?? 'in_person',
      province: json['province'] as String? ?? '',
      municipality: json['municipality'] as String? ?? '',
      neighborhood: json['neighborhood'] as String? ?? '',
      latitude: (json['latitude'] as num?)?.toDouble() ?? 0,
      longitude: (json['longitude'] as num?)?.toDouble() ?? 0,
      paymentDueAt: parseDate(json['payment_due_at']),
      confirmationDeadline: parseDate(json['confirmation_deadline']),
      noShowContestUntil: parseDate(json['no_show_contest_until']),
      counterpart: Person.fromJson((role == 'student' ? json['trainer'] : json['student']) as Map<String, dynamic>?),
    );
  }

  final int id;
  final String state;
  final String stateLabel;
  final Set<SessionAction> actions;
  final String title;
  final String skill;
  final DateTime scheduledAt;
  final int durationMinutes;
  final String paymentMethod;
  final int amount;
  final int? trainerNet;
  final int? commissionPercent;
  final String role;
  final String modality;
  final String province;
  final String municipality;
  final String neighborhood;
  final double latitude;
  final double longitude;
  final DateTime? paymentDueAt;
  final DateTime? confirmationDeadline;
  final DateTime? noShowContestUntil;
  final Person counterpart;

  bool get isTrainer => role == 'trainer';
  bool get isCash => paymentMethod == 'cash';
  bool can(SessionAction action) => actions.contains(action);
}

class SessionEventItem {
  SessionEventItem(this.type, this.at);

  factory SessionEventItem.fromJson(Map<String, dynamic> json) => SessionEventItem(json['type'] as String, DateTime.parse(json['created_at'] as String));

  final String type;
  final DateTime at;

  String get label => const {
        'payment_requested': 'Pagamento pedido',
        'payment_confirmed': 'Pagamento recebido (retido pela LONGA)',
        'payment_failed': 'Pagamento falhou',
        'started': 'Aula iniciada',
        'finished': 'Aula terminada',
        'qr_issued': 'QR de confirmação gerado',
        'qr_scanned': 'QR lido pelo aluno',
        'confirmed': 'Aula confirmada',
        'cancelled': 'Aula cancelada',
        'no_show_reported': 'Falta reportada',
        'dispute_opened': 'Disputa aberta',
        'dispute_resolved': 'Disputa decidida',
        'auto_completed': 'Confirmada automaticamente',
        'auto_refunded': 'Reembolsada automaticamente',
        'cash_confirmed': 'Pagamento em mão confirmado',
      }[type] ??
      type.replaceAll('_', ' ');
}

/// Token de QR de um lote (30 s cada, com a hora do servidor).
class QrToken {
  QrToken(this.token, this.code, this.validFrom, this.expiresAt);

  factory QrToken.fromJson(Map<String, dynamic> json) =>
      QrToken(json['token'] as String, json['code'] as String, DateTime.parse(json['valid_from'] as String), DateTime.parse(json['expires_at'] as String));

  final String token;
  final String code;
  final DateTime validFrom;
  final DateTime expiresAt;
}

class QrBatch {
  QrBatch(this.rotationSeconds, this.tokens, this.serverTime);

  factory QrBatch.fromJson(Map<String, dynamic> json) => QrBatch(
        asInt(json['rotation_seconds'], 30),
        ((json['tokens'] as List?) ?? const []).map((t) => QrToken.fromJson(t as Map<String, dynamic>)).toList(),
        DateTime.parse(json['server_time'] as String),
      );

  final int rotationSeconds;
  final List<QrToken> tokens;
  final DateTime serverTime;

  /// Token válido no instante dado (hora do servidor), ou null.
  QrToken? currentAt(DateTime serverNow) {
    for (final t in tokens) {
      final end = t.validFrom.add(Duration(seconds: rotationSeconds));
      if (!serverNow.isBefore(t.validFrom) && serverNow.isBefore(end)) return t;
    }
    return null;
  }

  /// Quanto falta para o lote acabar (pedir outro antes de 1 minuto).
  Duration remainingAt(DateTime serverNow) {
    if (tokens.isEmpty) return Duration.zero;
    final end = tokens.last.validFrom.add(Duration(seconds: rotationSeconds));
    return end.difference(serverNow);
  }
}

/// Conteúdo do QR: "LONGA:{aula}:{token}" (o leitor recusa QR de outra aula antes de enviar).
String qrPayload(int sessionId, String token) => 'LONGA:$sessionId:$token';

({int sessionId, String token})? parseQrPayload(String raw) {
  final match = RegExp(r'^LONGA:(\d+):([A-Za-z0-9_-]{16,64})$').firstMatch(raw.trim());
  if (match == null) return null;
  return (sessionId: int.parse(match.group(1)!), token: match.group(2)!);
}
