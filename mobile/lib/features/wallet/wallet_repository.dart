import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/providers.dart';

final walletRepositoryProvider = Provider<WalletRepository>((ref) => WalletRepository(ref.watch(apiClientProvider)));

class WalletSummary {
  WalletSummary(this.available, this.pending, this.totalEarned, this.commissionDue);

  factory WalletSummary.fromJson(Map<String, dynamic> json) =>
      WalletSummary(asInt(json['available']), asInt(json['pending']), asInt(json['total_earned']), asInt(json['commission_due']));

  final int available;
  final int pending;
  final int totalEarned;
  final int commissionDue;
}

class WalletMovement {
  WalletMovement(this.id, this.bucket, this.label, this.amount, this.at, this.sessionId);

  factory WalletMovement.fromJson(Map<String, dynamic> json) => WalletMovement(
        json['id'] as int,
        json['bucket'] as String,
        json['label'] as String,
        asInt(json['amount']),
        DateTime.parse(json['created_at'] as String),
        json['session_id'] == null ? null : asInt(json['session_id']),
      );

  final int id;

  /// available (saldo) ou commission_due (dívida de comissões em mão).
  final String bucket;
  final String label;
  final int amount;
  final DateTime at;
  final int? sessionId;
}

class EarningsMonth {
  EarningsMonth(this.month, this.lessons, this.cashLessons, this.credited, this.commissions, this.cashReceived);

  factory EarningsMonth.fromJson(Map<String, dynamic> json) => EarningsMonth(
        asInt(json['month']),
        asInt(json['lessons']),
        asInt(json['cash_lessons']),
        asInt(json['credited']),
        asInt(json['commissions']),
        asInt(json['cash_received']),
      );

  final int month;
  final int lessons;
  final int cashLessons;
  final int credited;
  final int commissions;
  final int cashReceived;
}

class VipStatus {
  VipStatus({required this.isVip, required this.price, required this.durationDays, required this.commissionPercent, required this.standardCommissionPercent, this.until});

  factory VipStatus.fromJson(Map<String, dynamic> json) => VipStatus(
        isVip: json['is_vip'] == true,
        until: parseDate(json['vip_until']),
        price: asInt(json['price']),
        durationDays: asInt(json['duration_days'], 365),
        commissionPercent: asInt(json['commission_percent'], 10),
        standardCommissionPercent: asInt(json['standard_commission_percent'], 15),
      );

  final bool isVip;
  final DateTime? until;
  final int price;
  final int durationDays;
  final int commissionPercent;
  final int standardCommissionPercent;
}

class WalletRepository {
  WalletRepository(this.api);

  final ApiClient api;

  Future<WalletSummary> summary() async => WalletSummary.fromJson((await api.get('/wallet'))['data'] as Map<String, dynamic>);

  Future<({List<WalletMovement> items, String? next})> movements({String? cursor}) async {
    final body = await api.get('/wallet/movements', query: {if (cursor != null) 'cursor': cursor});
    final items = (body['data'] as List).map((m) => WalletMovement.fromJson(m as Map<String, dynamic>)).toList();
    return (items: items, next: (body['meta'] as Map?)?['next_cursor'] as String?);
  }

  Future<List<EarningsMonth>> earnings(int year) async {
    final data = (await api.get('/me/reports/earnings', query: {'year': year}))['data'] as Map;
    return (data['months'] as List).map((m) => EarningsMonth.fromJson(m as Map<String, dynamic>)).toList();
  }

  Future<VipStatus> vip() async => VipStatus.fromJson((await api.get('/me/vip'))['data'] as Map<String, dynamic>);

  /// Comprar/renovar (exige PIN recente: o cliente da API pede-o se for preciso).
  Future<void> buyVip(String method, String idempotencyKey) => api.post('/me/vip/purchase', data: {'method': method, 'accept_terms': true}, idempotencyKey: idempotencyKey);
}
