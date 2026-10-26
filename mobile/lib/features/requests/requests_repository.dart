import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/providers.dart';

final requestsRepositoryProvider = Provider<RequestsRepository>((ref) => RequestsRepository(ref.watch(apiClientProvider)));

class Skill {
  Skill(this.id, this.name, this.category);

  factory Skill.fromJson(Map<String, dynamic> json) => Skill(json['id'] as int, json['name'] as String, (json['category'] ?? '') as String);

  final int id;
  final String name;
  final String category;
}

class Zone {
  Zone(this.province, this.municipality, this.neighborhood, this.lat, this.lng);

  factory Zone.fromJson(Map<String, dynamic> json) => Zone(
        (json['province'] ?? 'Luanda') as String,
        json['municipality'] as String,
        json['neighborhood'] as String,
        (json['lat'] as num).toDouble(),
        (json['lng'] as num).toDouble(),
      );

  final String province;
  final String municipality;
  final String neighborhood;
  final double lat;
  final double lng;
}

/// Pedido de aula do aluno (StudentRequestResource).
class TrainingRequest {
  TrainingRequest({
    required this.id,
    required this.state,
    required this.title,
    required this.skill,
    required this.amount,
    required this.scheduledAt,
    required this.scheduled,
    required this.neighborhood,
    required this.openOffers,
    required this.wave,
    this.expiresAt,
    this.secondsLeft,
    this.sessionId,
  });

  factory TrainingRequest.fromJson(Map<String, dynamic> json) => TrainingRequest(
        id: json['id'] as int,
        state: json['state'] as String,
        title: json['title'] as String? ?? '',
        skill: ((json['skill'] as Map?)?['name'] ?? '') as String,
        amount: asInt(json['amount']),
        scheduledAt: DateTime.parse(json['scheduled_at'] as String),
        scheduled: json['scheduled'] == true,
        neighborhood: json['neighborhood'] as String? ?? '',
        openOffers: asInt(json['open_offers']),
        wave: asInt(json['wave']),
        expiresAt: parseDate(json['expires_at']),
        secondsLeft: json['seconds_left'] == null ? null : asInt(json['seconds_left']),
        sessionId: json['session_id'] == null ? null : asInt(json['session_id']),
      );

  final int id;
  final String state;
  final String title;
  final String skill;
  final int amount;
  final DateTime scheduledAt;
  final bool scheduled;
  final String neighborhood;
  final int openOffers;
  final int wave;
  final DateTime? expiresAt;
  final int? secondsLeft;
  final int? sessionId;
}

/// Dados para publicar um pedido.
class NewRequest {
  NewRequest({
    required this.skillId,
    required this.title,
    required this.amount,
    required this.scheduledAt,
    required this.durationMinutes,
    required this.paymentMethod,
    required this.modality,
    required this.province,
    required this.municipality,
    required this.neighborhood,
    required this.latitude,
    required this.longitude,
    this.description,
  });

  final int skillId;
  final String title;
  final String? description;
  final int amount;
  final DateTime scheduledAt;
  final int durationMinutes;
  final String paymentMethod;
  final String modality;
  final String province;
  final String municipality;
  final String neighborhood;
  final double latitude;
  final double longitude;

  Map<String, dynamic> toJson() => {
        'skill_id': skillId,
        'title': title,
        if (description != null && description!.isNotEmpty) 'description': description,
        'amount': amount,
        'scheduled_at': scheduledAt.toUtc().toIso8601String(),
        'duration_minutes': durationMinutes,
        'payment_method': paymentMethod,
        'modality': modality,
        'province': province,
        'municipality': municipality,
        'neighborhood': neighborhood,
        'latitude': latitude,
        'longitude': longitude,
      };
}

class RequestsRepository {
  RequestsRepository(this.api);

  final ApiClient api;

  Future<List<Skill>> skills() async => ((await api.get('/skills'))['data'] as List).map((s) => Skill.fromJson(s as Map<String, dynamic>)).toList();

  Future<List<Zone>> zones() async => ((await api.get('/zones'))['data'] as List).map((z) => Zone.fromJson(z as Map<String, dynamic>)).toList();

  Future<List<String>> provinces() async => ((await api.get('/provinces'))['data'] as List).map((p) => p.toString()).toList();

  Future<TrainingRequest> create(NewRequest request) async =>
      TrainingRequest.fromJson((await api.post('/training-requests', data: request.toJson()))['data'] as Map<String, dynamic>);

  Future<TrainingRequest> show(int id) async => TrainingRequest.fromJson((await api.get('/training-requests/$id'))['data'] as Map<String, dynamic>);

  Future<void> cancel(int id) => api.post('/training-requests/$id/cancel');

  Future<TrainingRequest> republish(int id) async => TrainingRequest.fromJson((await api.post('/training-requests/$id/republish'))['data'] as Map<String, dynamic>);
}
