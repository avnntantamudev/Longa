import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/providers.dart';

final offersRepositoryProvider = Provider<OffersRepository>((ref) => OffersRepository(ref.watch(apiClientProvider)));

/// Oferta TRIM TRIM ou pedido agendado, vista pelo formador (TrainerOfferResource).
class Offer {
  Offer({
    required this.id,
    required this.state,
    required this.scheduled,
    required this.title,
    required this.skill,
    required this.amount,
    required this.trainerNet,
    required this.commissionPercent,
    required this.scheduledAt,
    required this.modality,
    required this.neighborhood,
    required this.distanceM,
    required this.expiresAt,
    required this.secondsLeft,
    this.description,
    this.sessionId,
  });

  factory Offer.fromJson(Map<String, dynamic> json) => Offer(
        id: json['id'] as int,
        state: json['state'] as String,
        scheduled: json['scheduled'] == true,
        title: json['title'] as String? ?? '',
        description: json['description'] as String?,
        skill: json['skill'] as String? ?? '',
        amount: asInt(json['amount']),
        trainerNet: asInt(json['trainer_net']),
        commissionPercent: asInt(json['commission_percent'], 15),
        scheduledAt: DateTime.parse(json['scheduled_at'] as String),
        modality: json['modality'] as String? ?? 'in_person',
        neighborhood: json['neighborhood'] as String? ?? '',
        distanceM: asInt(json['distance_m']),
        expiresAt: DateTime.parse(json['expires_at'] as String),
        secondsLeft: asInt(json['seconds_left']),
        sessionId: json['session_id'] == null ? null : asInt(json['session_id']),
      );

  final int id;
  final String state;
  final bool scheduled;
  final String title;
  final String? description;
  final String skill;
  final int amount;
  final int trainerNet;
  final int commissionPercent;
  final DateTime scheduledAt;
  final String modality;
  final String neighborhood;
  final int distanceM;
  final DateTime expiresAt;
  final int secondsLeft;
  final int? sessionId;

  bool get isOpen => state == 'sent' || state == 'viewed';
}

class OffersRepository {
  OffersRepository(this.api);

  final ApiClient api;

  Future<List<Offer>> list() async => ((await api.get('/offers'))['data'] as List).map((o) => Offer.fromJson(o as Map<String, dynamic>)).toList();

  Future<Offer> show(int id) async => Offer.fromJson((await api.get('/offers/$id'))['data'] as Map<String, dynamic>);

  Future<void> markViewed(int id) => api.post('/offers/$id/view');

  /// Aceitar: devolve o id da aula criada (o primeiro formador a aceitar fica com a aula).
  Future<int> accept(int id) async => asInt(((await api.post('/offers/$id/accept'))['data'] as Map)['id']);

  Future<void> decline(int id) => api.post('/offers/$id/decline');

  Future<bool> availability() async => ((await api.get('/me/trainer-profile'))['data'] as Map)['is_available'] == true;

  Future<bool> setAvailability(bool available) async =>
      ((await api.put('/me/trainer-profile/availability', data: {'is_available': available}))['data'] as Map)['is_available'] == true;
}
