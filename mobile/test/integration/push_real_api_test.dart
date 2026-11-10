// Registers and removes a made-up device token on a real local Laravel server.
//
// Skipped by default. Use only against local demo data, never production:
//   php artisan serve --port=8361
//   flutter test test/integration/push_real_api_test.dart \
//     --dart-define=REAL_API_URL=http://127.0.0.1:8361/api/v1
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/push/device_registry.dart';
import 'package:nirmaan360/core/session/token_store.dart';

const _url = String.fromEnvironment('REAL_API_URL');

void main() {
  test(
    'POST and DELETE /devices take the FCM token the way the app sends it',
    () async {
      final InMemoryTokenStore tokens = InMemoryTokenStore();
      final DioNirmaanApi api = DioNirmaanApi(readToken: tokens.read, baseUrl: _url, dio: Dio());
      final LoginResult login = await api.login(email: 'engineer@demo.test', password: 'password', deviceName: 'push test');
      await tokens.write(login.token);
      addTearDown(api.logout);

      const String made = 'not-a-real-fcm-token-for-the-contract-test';
      final DeviceRegistry registry = ApiDeviceRegistry(api);

      // First time and again: both succeed, and the reply says whether the server can send yet.
      final DeviceRegistration first = await api.registerDevice(token: made, platform: 'android', deviceName: 'Test phone');
      expect(first.pushConnected, isFalse);
      await registry.register(token: made, platform: 'android', deviceName: 'Test phone');

      // The server refuses a platform it does not know, as a validation failure.
      await expectLater(api.registerDevice(token: made, platform: 'blackberry'), throwsA(isA<ValidationFailure>()));

      await registry.unregister(made);
      // Taking back a token the server no longer holds is not an error.
      await registry.unregister(made);
    },
    skip: _url.isEmpty ? 'set REAL_API_URL to run against a local server' : null,
  );
}
