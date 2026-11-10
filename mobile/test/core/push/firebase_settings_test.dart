import 'package:firebase_messaging/firebase_messaging.dart' show AuthorizationStatus;
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/push/firebase_push_messaging.dart';
import 'package:nirmaan360/core/push/firebase_settings.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';

// Every value below is made up. None is, or resembles, a real key.
const FirebaseSettings _both = FirebaseSettings(
  projectId: 'test-project',
  senderId: '111111111111',
  storageBucket: 'test-project.example',
  androidApiKey: 'android-key-for-tests',
  androidAppId: '1:111111111111:android:aaaa',
  iosApiKey: 'ios-key-for-tests',
  iosAppId: '1:111111111111:ios:bbbb',
  iosBundleId: 'in.nirmaan360.app',
);

void main() {
  group('a build with no Firebase settings', () {
    test('has nothing for either phone platform', () {
      const FirebaseSettings none = FirebaseSettings();
      expect(none.forPlatform(TargetPlatform.android), isNull);
      expect(none.forPlatform(TargetPlatform.iOS), isNull);
    });

    test('the settings given to this test run are empty, because no defines are passed', () {
      expect(FirebaseSettings.fromBuild.forPlatform(TargetPlatform.android), isNull);
      expect(FirebaseSettings.fromBuild.forPlatform(TargetPlatform.iOS), isNull);
    });

    test('push is not configured, and starting it does nothing and does not throw', () async {
      final FirebasePushMessaging messaging = FirebasePushMessaging(settings: const FirebaseSettings());
      expect(messaging.isConfigured, isFalse);
      await messaging.initialise();
    });

    test('the stand-in for it is inert', () async {
      const PushMessaging none = NoPushMessaging();
      expect(none.isConfigured, isFalse);
      expect(await none.token(), isNull);
      expect(await none.launchedBy(), isNull);
      expect(await none.permission(), PushPermission.notAsked);
      await none.setEnabled(true);
      await none.deleteToken();
      expect(await none.tokenRefreshes.isEmpty, isTrue);
    });
  });

  group('a build with the settings', () {
    test('Android gets its own key and app id, and iOS its own', () {
      final FirebasePlatformSettings android = _both.forPlatform(TargetPlatform.android)!;
      final FirebasePlatformSettings ios = _both.forPlatform(TargetPlatform.iOS)!;
      expect(android.apiKey, 'android-key-for-tests');
      expect(android.appId, '1:111111111111:android:aaaa');
      expect(ios.apiKey, 'ios-key-for-tests');
      expect(ios.appId, '1:111111111111:ios:bbbb');
      expect(android.senderId, ios.senderId);
      expect(android.projectId, 'test-project');
      expect(android.iosBundleId, isNull);
      expect(ios.iosBundleId, 'in.nirmaan360.app');
    });

    test('a platform whose half is missing stays off, the other stays on', () {
      const FirebaseSettings androidOnly = FirebaseSettings(
        projectId: 'test-project',
        senderId: '111111111111',
        androidApiKey: 'android-key-for-tests',
        androidAppId: '1:111111111111:android:aaaa',
      );
      expect(androidOnly.forPlatform(TargetPlatform.android), isNotNull);
      expect(androidOnly.forPlatform(TargetPlatform.iOS), isNull);
    });

    test('a key with no project or sender is not enough', () {
      const FirebaseSettings partial = FirebaseSettings(androidApiKey: 'k', androidAppId: 'a');
      expect(partial.forPlatform(TargetPlatform.android), isNull);
    });

    test('only Android and iOS are ever configured', () {
      expect(_both.forPlatform(TargetPlatform.macOS), isNull);
      expect(_both.forPlatform(TargetPlatform.windows), isNull);
      expect(_both.forPlatform(TargetPlatform.linux), isNull);
    });

    test('printing the settings never shows a value', () {
      final FirebasePlatformSettings android = _both.forPlatform(TargetPlatform.android)!;
      for (final String text in <String>[_both.toString(), android.toString()]) {
        expect(text, isNot(contains('android-key-for-tests')));
        expect(text, isNot(contains('111111111111')));
        expect(text, isNot(contains('test-project')));
      }
    });
  });

  group('the phone\'s permission, from Firebase\'s words', () {
    test('allowed, even provisionally, is granted', () {
      for (final TargetPlatform platform in <TargetPlatform>[TargetPlatform.android, TargetPlatform.iOS]) {
        expect(pushPermissionFrom(AuthorizationStatus.authorized, platform), PushPermission.granted);
        expect(pushPermissionFrom(AuthorizationStatus.provisional, platform), PushPermission.granted);
      }
    });

    test('never asked is not asked', () {
      expect(pushPermissionFrom(AuthorizationStatus.notDetermined, TargetPlatform.iOS), PushPermission.notAsked);
      expect(pushPermissionFrom(AuthorizationStatus.notDetermined, TargetPlatform.android), PushPermission.notAsked);
    });

    test('a refusal is final on iOS, but may be asked once more on Android', () {
      expect(pushPermissionFrom(AuthorizationStatus.denied, TargetPlatform.iOS), PushPermission.blocked);
      expect(pushPermissionFrom(AuthorizationStatus.denied, TargetPlatform.android), PushPermission.denied);
      expect(pushPermissionFrom(AuthorizationStatus.deniedPermanently, TargetPlatform.android), PushPermission.blocked);
    });
  });
}
