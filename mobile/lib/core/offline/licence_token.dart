import 'dart:convert';

import 'package:cryptography/cryptography.dart' show KeyPairType, Signature, SimplePublicKey;
import 'package:cryptography/dart.dart' show DartEd25519;
import 'package:flutter/foundation.dart';

/// What a genuine device licence says.
@immutable
class LicenceClaims {
  const LicenceClaims({
    required this.licenceId,
    required this.organizationId,
    required this.deviceId,
    required this.plan,
    required this.features,
    required this.expiresAt,
    this.issuedAt,
  });

  final int licenceId;
  final int organizationId;
  final String deviceId;
  final String plan;
  final Set<String> features;
  final DateTime expiresAt;

  /// When the server signed it. Signed along with the rest, so unlike the
  /// phone's clock it can be believed: no later moment can come before it.
  final DateTime? issuedAt;
}

/// Checks a `v1.<payload>.<signature>` token with the public key, and returns
/// its claims only when the signature is genuine and it names [deviceId].
///
/// The signature is over the decoded payload bytes, so it is verified first
/// and the JSON is read after. Whether the token has run out is the caller's
/// question: an expired licence is still a real one, and the app treats it
/// differently from a forged one.
///
/// This is the plain Dart implementation on purpose. The `Ed25519()` factory
/// can pick a platform plugin, and this needs no native code on either phone.
Future<LicenceClaims?> verifyLicenceToken(
  String token, {
  required String publicKeyBase64,
  required String deviceId,
}) async {
  try {
    final List<String> parts = token.split('.');
    if (parts.length != 3 || parts[0] != 'v1') return null;

    final Uint8List payload = base64Url.decode(base64Url.normalize(parts[1]));
    final Uint8List signature = base64Url.decode(base64Url.normalize(parts[2]));
    final Uint8List key = base64.decode(publicKeyBase64);
    if (signature.length != 64 || key.length != 32) return null;

    final bool genuine = await DartEd25519().verify(
      payload,
      signature: Signature(signature, publicKey: SimplePublicKey(key, type: KeyPairType.ed25519)),
    );
    if (!genuine) return null;

    final Object? decoded = jsonDecode(utf8.decode(payload));
    if (decoded is! Map) return null;
    final claims = decoded.cast<String, dynamic>();
    final Object? exp = claims['exp'];
    final Object? licence = claims['lic'];
    final Object? organization = claims['org'];
    if (exp is! int || licence is! num || organization is! num) return null;
    if (claims['dev'] != deviceId) return null;
    final Object? iat = claims['iat'];

    return LicenceClaims(
      licenceId: licence.toInt(),
      organizationId: organization.toInt(),
      deviceId: deviceId,
      plan: claims['plan'] as String? ?? '',
      features: ((claims['features'] as List?) ?? const <Object?>[]).map((e) => e.toString()).toSet(),
      expiresAt: DateTime.fromMillisecondsSinceEpoch(exp * 1000, isUtc: true),
      issuedAt: iat is int ? DateTime.fromMillisecondsSinceEpoch(iat * 1000, isUtc: true) : null,
    );
  } on Object catch (e) {
    // A token that cannot even be read is not a licence.
    debugPrint('Licence token could not be read: ${e.runtimeType}');
    return null;
  }
}
