import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/providers.dart';

final profileRepositoryProvider = Provider<ProfileRepository>((ref) => ProfileRepository(ref.watch(apiClientProvider)));

class TrustedDeviceInfo {
  TrustedDeviceInfo(this.id, this.name, this.lastUsedAt, this.current);

  factory TrustedDeviceInfo.fromJson(Map<String, dynamic> json) =>
      TrustedDeviceInfo(json['id'] as String, json['name'] as String?, parseDate(json['last_used_at']), json['current'] == true);

  final String id;
  final String? name;
  final DateTime? lastUsedAt;
  final bool current;
}

class CompanyLink {
  CompanyLink(this.id, this.company, this.consentStatus);

  factory CompanyLink.fromJson(Map<String, dynamic> json) => CompanyLink(json['id'] as int, json['company'] as String, json['consent_status'] as String);

  final int id;
  final String company;

  /// pending | accepted | declined | revoked
  final String consentStatus;
}

class ProfileRepository {
  ProfileRepository(this.api);

  final ApiClient api;

  /// Foto de perfil (o servidor recorta, recodifica e remove metadados como a localização).
  Future<void> uploadPhoto(String path) => api.post('/me/photo', data: FormData.fromMap({'photo': MultipartFile.fromFileSync(path)}));

  Future<List<TrustedDeviceInfo>> devices() async =>
      ((await api.get('/auth/devices'))['data'] as List).map((d) => TrustedDeviceInfo.fromJson(d as Map<String, dynamic>)).toList();

  Future<void> removeDevice(String id) => api.delete('/auth/devices/$id');

  Future<List<CompanyLink>> companyLinks() async =>
      ((await api.get('/me/company-links'))['data'] as List).map((l) => CompanyLink.fromJson(l as Map<String, dynamic>)).toList();

  Future<void> decideCompanyLink(int id, String decision) => api.post('/me/company-links/$id', data: {'decision': decision});
}
