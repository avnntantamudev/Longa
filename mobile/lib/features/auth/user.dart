/// Utilizador autenticado (UserResource da API).
class AppUser {
  const AppUser({
    required this.id,
    required this.phone,
    required this.roles,
    required this.needsOnboarding,
    required this.pinSet,
    this.name,
    this.photoUrl,
    this.photoThumbUrl,
  });

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
        id: json['id'] as int,
        name: json['name'] as String?,
        phone: (json['phone'] ?? '') as String,
        roles: ((json['roles'] as List?) ?? const []).map((e) => e.toString()).toList(),
        needsOnboarding: json['needs_onboarding'] == true,
        pinSet: json['pin_set'] == true,
        photoUrl: json['photo_url'] as String?,
        photoThumbUrl: json['photo_thumb_url'] as String?,
      );

  final int id;
  final String? name;
  final String phone;
  final List<String> roles;
  final bool needsOnboarding;
  final bool pinSet;
  final String? photoUrl;
  final String? photoThumbUrl;

  bool get isStudent => roles.contains('student');
  bool get isTrainer => roles.contains('trainer');
  bool get isCompanyRh => roles.contains('company_rh');

  String get firstName => (name ?? '').split(' ').firstWhere((p) => p.isNotEmpty, orElse: () => '');

  /// +244923456789 → "+244 9•• ••• 789"
  String get maskedPhone => maskPhone(phone);
}

String maskPhone(String phone) {
  final digits = phone.replaceAll(RegExp(r'\D'), '');
  if (digits.length < 9) return phone;
  final local = digits.substring(digits.length - 9);
  return '+244 ${local[0]}•• ••• ${local.substring(6)}';
}
