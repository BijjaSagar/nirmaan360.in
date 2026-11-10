import '../api/nirmaan_api.dart';

/// Tells the server which phone belongs to the signed-in person, and which not.
///
/// This is the one place that knows the server's device calls: `POST /devices`
/// and `DELETE /devices` of `NotifyApiController`, which take the FCM token
/// itself. A contractor login reaches the same two under `/contractor-portal`.
abstract interface class DeviceRegistry {
  /// Registers [token], or refreshes it: registering a token again updates it.
  /// [previousToken] is the token it replaces, which the server retires.
  /// Throws `ApiFailure`.
  Future<void> register({
    required String token,
    required String platform,
    String? previousToken,
    String? appVersion,
    String? locale,
    String? deviceName,
  });

  /// Forgets [token] for the signed-in person. Throws `ApiFailure`.
  Future<void> unregister(String token);
}

/// [DeviceRegistry] over the server's `/devices` routes.
class ApiDeviceRegistry implements DeviceRegistry {
  /// [portal] answers whether the person signed in is a contractor login.
  const ApiDeviceRegistry(this._api, {this._portal});

  final NirmaanApi _api;
  final bool Function()? _portal;

  bool get _isPortal => _portal?.call() ?? false;

  @override
  Future<void> register({
    required String token,
    required String platform,
    String? previousToken,
    String? appVersion,
    String? locale,
    String? deviceName,
  }) async {
    await _api.registerDevice(
      token: token,
      platform: platform,
      previousToken: previousToken,
      appVersion: appVersion,
      locale: locale,
      deviceName: deviceName,
      portal: _isPortal,
    );
  }

  @override
  Future<void> unregister(String token) => _api.unregisterDevice(token, portal: _isPortal);
}
