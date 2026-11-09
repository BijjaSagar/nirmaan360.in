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
    this.kind = staffKind,
    this.contractorPartyId,
    this.contractorName,
  });

  factory CurrentUser.fromJson(Map<String, dynamic> json) {
    final organization = (json['organization'] as Map?) ?? const {};
    final can = (json['can'] as Map?) ?? const {};
    // Older copies and older servers have no `kind`: that is a member of staff.
    final contractor = json['contractor'];
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
      kind: json['kind'] == contractorKind ? contractorKind : staffKind,
      contractorPartyId: contractor is Map ? (contractor['party_id'] as num?)?.toInt() : null,
      contractorName: contractor is Map ? contractor['name'] as String? : null,
    );
  }

  /// `kind` of a member of the company's own team.
  static const String staffKind = 'staff';

  /// `kind` of a login that belongs to a subcontractor. It reaches the
  /// contractor portal and nothing else.
  static const String contractorKind = 'contractor';

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

  /// [staffKind] or [contractorKind].
  final String kind;

  /// For a contractor login: the subcontractor it belongs to. Null when the
  /// server could not link the login to a live one.
  final int? contractorPartyId;
  final String? contractorName;

  bool get isContractor => kind == contractorKind;

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
    'kind': kind,
    'contractor': contractorPartyId == null && contractorName == null
        ? null
        : {'party_id': contractorPartyId, 'name': contractorName},
  };
}
