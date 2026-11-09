import 'package:flutter/foundation.dart';

/// The signed-in user, as `/me` and `/sync/pull` describe them.
@immutable
class CurrentUser {
  const CurrentUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.roleLabel,
    required this.locale,
    required this.organizationId,
    required this.organizationName,
    required this.features,
    required this.permissions,
  });

  factory CurrentUser.fromJson(Map<String, dynamic> json) {
    final organization = (json['organization'] as Map?) ?? const {};
    final can = (json['can'] as Map?) ?? const {};
    return CurrentUser(
      id: (json['id'] as num).toInt(),
      name: json['name'] as String,
      email: json['email'] as String? ?? '',
      role: json['role'] as String? ?? '',
      roleLabel: json['role_label'] as String? ?? '',
      locale: json['locale'] as String? ?? 'en',
      organizationId: (organization['id'] as num?)?.toInt(),
      organizationName: organization['name'] as String? ?? '',
      features: ((organization['features'] as List?) ?? const [])
          .map((e) => e.toString())
          .toSet(),
      permissions: can.map((k, v) => MapEntry(k.toString(), v == true)),
    );
  }

  final int id;
  final String name;
  final String email;
  final String role;
  final String roleLabel;
  final String locale;
  final int? organizationId;
  final String organizationName;
  final Set<String> features;
  final Map<String, bool> permissions;

  /// True when the server granted [permission], e.g. `dpr.create`.
  bool can(String permission) => permissions[permission] == true;

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'email': email,
    'role': role,
    'role_label': roleLabel,
    'locale': locale,
    'organization': {
      'id': organizationId,
      'name': organizationName,
      'features': features.toList(),
    },
    'can': permissions,
  };
}
