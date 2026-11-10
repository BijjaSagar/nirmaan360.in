import '../api/nirmaan_api.dart';

/// Tells the server which phone belongs to the signed-in person, and which not.
///
/// This is the one place that knows the server's device calls. It uses the
/// existing `POST /devices` and `DELETE /devices` of `NotifyApiController`, which
/// take the FCM token itself. If the real push API is shaped differently, this
/// class is all that changes.
abstract interface class DeviceRegistry {
  /// Registers [token]. Registering a token again updates it. Throws `ApiFailure`.
  Future<void> register({required String token, required String platform, String? deviceName});

  /// Forgets [token] for the signed-in person. Throws `ApiFailure`.
  Future<void> unregister(String token);
}

/// [DeviceRegistry] over the server's `/devices` routes.
class ApiDeviceRegistry implements DeviceRegistry {
  const ApiDeviceRegistry(this._api);

  final NirmaanApi _api;

  @override
  Future<void> register({required String token, required String platform, String? deviceName}) async {
    await _api.registerDevice(token: token, platform: platform, deviceName: deviceName);
  }

  @override
  Future<void> unregister(String token) => _api.unregisterDevice(token);
}
