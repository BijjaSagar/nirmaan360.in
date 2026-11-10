import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/push/device_registry.dart';
import 'package:nirmaan360/core/push/push_message.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';
import 'package:nirmaan360/core/push/push_service.dart';

import '../../support/fake_push.dart';
import '../../support/test_app.dart';

/// Push on its own: a fake phone, a fake server and a real in-memory database.
class _Rig {
  _Rig._(this.db, this.api, this.messaging, this.connectivity, this.push);

  final AppDatabase db;
  final FakeApi api;
  final FakePushMessaging messaging;
  final FakeConnectivity connectivity;
  final PushService push;

  /// The app's language, as the push service reads it.
  static String language = 'mr';

  static _Rig build({
    FakePushMessaging? messaging,
    AppDatabase? db,
    FakeApi? api,
    FakeConnectivity? connectivity,
    bool contractor = false,
  }) {
    final AppDatabase database = db ?? newTestDatabase();
    final FakeApi server = api ?? FakeApi();
    final FakePushMessaging phone = messaging ?? FakePushMessaging();
    final FakeConnectivity network = connectivity ?? FakeConnectivity();
    final PushService push = PushService(
      messaging: phone,
      devices: ApiDeviceRegistry(server, portal: () => contractor),
      db: database,
      platform: 'android',
      deviceName: 'Test phone',
      appVersion: '1.0.0 (14)',
      locale: () async => language,
      connectivity: network,
      patience: const Duration(milliseconds: 200),
    );
    return _Rig._(database, server, phone, network, push);
  }

  Future<void> dispose() async {
    await push.dispose();
    await db.close();
  }
}

Future<_Rig> _started({
  FakePushMessaging? messaging,
  bool eligible = true,
  AppDatabase? db,
  FakeApi? api,
  FakeConnectivity? connectivity,
  bool contractor = false,
}) async {
  final _Rig rig = _Rig.build(messaging: messaging, db: db, api: api, connectivity: connectivity, contractor: contractor);
  await rig.push.start();
  if (eligible) await rig.push.setEligible(true);
  return rig;
}

void main() {
  setUp(() => _Rig.language = 'mr');

  group('asking for permission', () {
    test('a person never asked is offered the explanation, and the phone is not asked yet', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);

      expect(rig.push.state, PushState.askFirst);
      expect(rig.messaging.permissionRequests, 0);
      // Until the person agrees, Firebase learns nothing about this phone.
      expect(rig.messaging.enabledCalls, isEmpty);
      expect(rig.messaging.tokenReads, 0);
      expect(rig.api.deviceRegistrations, isEmpty);
    });

    test('yes asks the phone once, switches push on and registers the token', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);

      expect(await rig.push.turnOn(), PushState.on);

      expect(rig.messaging.permissionRequests, 1);
      expect(rig.messaging.enabled, isTrue);
      expect(rig.api.deviceRegistrations.single.token, 'fake-fcm-token-1');
    });

    test('not now never asks the phone, and the explanation does not come back', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);

      await rig.push.declineForNow();
      expect(rig.push.state, PushState.off);
      expect(rig.messaging.permissionRequests, 0);

      // A fresh start of the app, on the same phone.
      final _Rig later = await _started(db: rig.db, messaging: FakePushMessaging());
      addTearDown(later.push.dispose);
      expect(later.push.state, PushState.off);
      expect(later.messaging.permissionRequests, 0);
    });

    test('the phone refusing leaves push off and registers nothing', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(answerToRequest: PushPermission.blocked));
      addTearDown(rig.dispose);

      expect(await rig.push.turnOn(), PushState.blocked);

      expect(rig.messaging.enabledCalls, isEmpty);
      expect(rig.api.deviceRegistrations, isEmpty);
      expect(rig.push.state, PushState.blocked);
    });

    test('Settings can ask again after not now, and after a first refusal on Android', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(answerToRequest: PushPermission.denied));
      addTearDown(rig.dispose);
      await rig.push.declineForNow();
      expect(await rig.push.turnOn(), PushState.blocked);

      rig.messaging.answerToRequest = PushPermission.granted;
      expect(await rig.push.turnOn(), PushState.on);
      expect(rig.messaging.permissionRequests, 2);
    });

    test('coming back from the phone\'s Settings with it allowed switches push on', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.blocked));
      addTearDown(rig.dispose);
      expect(rig.push.state, PushState.blocked);

      rig.messaging.permissionNow = PushPermission.granted;
      await rig.push.refresh();

      expect(rig.push.state, PushState.on);
      expect(rig.messaging.permissionRequests, 0);
      expect(rig.api.deviceRegistrations, hasLength(1));
    });

    test('a phone that already allows notifications is not asked and is not shown the explanation', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      expect(rig.push.state, PushState.on);
      expect(rig.messaging.permissionRequests, 0);
      expect(rig.api.deviceRegistrations, hasLength(1));
    });

    test('push is offered to nobody who has not signed in as staff', () async {
      final _Rig rig = await _started(eligible: false);
      addTearDown(rig.dispose);

      expect(rig.push.state, PushState.unavailable);
      expect(rig.messaging.permissionReads, 0);
      expect(rig.messaging.permissionRequests, 0);
      expect(await rig.push.turnOn(), PushState.unavailable);
      expect(rig.messaging.permissionRequests, 0);
      expect(rig.api.deviceRegistrations, isEmpty);
    });

    test('a person who stops being eligible is no longer offered it', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);
      await rig.push.setEligible(false);
      expect(rig.push.state, PushState.unavailable);

      rig.messaging.refreshToken('fake-fcm-token-2');
      await pumpEventQueue();
      expect(rig.api.deviceRegistrations, hasLength(1));
    });
  });

  group('the token', () {
    test('goes to the server with the platform and the device name', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      final registration = rig.api.deviceRegistrations.single;
      expect(registration.token, 'fake-fcm-token-1');
      expect(registration.platform, 'android');
      expect(registration.deviceName, 'Test phone');
      expect(registration.appVersion, '1.0.0 (14)');
      expect(registration.locale, 'mr');
      expect(registration.previousToken, isNull);
      expect(registration.portal, isFalse);
      expect(await rig.db.readMeta(MetaKey.pushToken), 'fake-fcm-token-1');
    });

    test('a renewed token is sent with the one it replaces, which the server retires', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      rig.messaging.refreshToken('fake-fcm-token-2');
      await pumpEventQueue();

      expect(rig.api.deviceRegistrations.map((r) => r.token), <String>['fake-fcm-token-1', 'fake-fcm-token-2']);
      expect(rig.api.deviceRegistrations.last.previousToken, 'fake-fcm-token-1');
      expect(rig.api.deviceRemovals, isEmpty, reason: 'one call does both');
      expect(rig.api.pushTokens, <String>['fake-fcm-token-2']);
      expect(await rig.db.readMeta(MetaKey.pushToken), 'fake-fcm-token-2');
    });

    test('every start of the app registers again, as the server asks', () async {
      final AppDatabase db = newTestDatabase();
      final FakeApi api = FakeApi();
      final _Rig first = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), db: db, api: api);
      await first.push.dispose();

      final _Rig second = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), db: db, api: api);
      addTearDown(second.dispose);

      expect(api.deviceRegistrations.map((r) => r.token), <String>['fake-fcm-token-1', 'fake-fcm-token-1']);
      expect(api.deviceRegistrations.last.previousToken, isNull, reason: 'the same token replaces nothing');
    });

    test('a new language is sent at once, so push text follows it', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      _Rig.language = 'hi';
      await rig.push.languageChanged();

      expect(rig.api.deviceRegistrations.map((r) => r.locale), <String?>['mr', 'hi']);
      expect(rig.api.deviceRegistrations.last.previousToken, isNull);

      // The same language again sends nothing.
      await rig.push.languageChanged();
      expect(rig.api.deviceRegistrations, hasLength(2));
    });

    test('a language change before push is on sends nothing', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);

      await rig.push.languageChanged();
      expect(rig.api.deviceRegistrations, isEmpty);
    });

    test('a contractor login registers and signs out under its portal', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), contractor: true);
      addTearDown(rig.dispose);

      expect(rig.api.deviceRegistrations.single.portal, isTrue);
      await rig.push.forgetThisPhone();
      expect(rig.api.deviceRemovals, <String>['fake-fcm-token-1']);
    });

    test('a token that arrives before the person agreed is ignored', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);

      rig.messaging.refreshToken('fake-fcm-token-9');
      await pumpEventQueue();

      expect(rig.api.deviceRegistrations, isEmpty);
    });

    test('the same token arriving again is not sent twice', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      rig.messaging.refreshToken('fake-fcm-token-1');
      await pumpEventQueue();

      expect(rig.api.deviceRegistrations, hasLength(1));
      expect(rig.api.deviceRemovals, isEmpty);
    });

    test('is not sent twice just because the app came back to the front', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      await rig.push.refresh();
      await rig.push.refresh();

      expect(rig.api.deviceRegistrations, hasLength(1));
    });

    test('with no network it is kept, and sent when the network is back', () async {
      final FakeApi api = FakeApi()..offline = true;
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), api: api);
      addTearDown(rig.dispose);
      expect(rig.push.state, PushState.on);
      expect(api.deviceRegistrations, isEmpty);

      api.offline = false;
      rig.connectivity.setOnline(false);
      rig.connectivity.setOnline(true);
      await pumpEventQueue();

      expect(api.deviceRegistrations.single.token, 'fake-fcm-token-1');
    });

    test('a server error is tried again by the next sync', () async {
      final FakeApi api = FakeApi()..failNextDeviceRegistration = const ServerFailure('down', statusCode: 503);
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), api: api);
      addTearDown(rig.dispose);
      expect(api.deviceRegistrations, isEmpty);

      await rig.push.retryPending();

      expect(api.deviceRegistrations, hasLength(1));
      // Nothing left to send.
      await rig.push.retryPending();
      expect(api.deviceRegistrations, hasLength(1));
    });

    test('a refusal that would repeat is not retried', () async {
      final FakeApi api = FakeApi()..failNextDeviceRegistration = const AuthExpired();
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), api: api);
      addTearDown(rig.dispose);

      await rig.push.retryPending();

      expect(api.deviceRegistrations, isEmpty);
    });

    test('a phone that cannot give a token yet is not an error', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted, tokenNow: null));
      addTearDown(rig.dispose);

      expect(rig.push.state, PushState.on);
      expect(rig.api.deviceRegistrations, isEmpty);

      rig.messaging.refreshToken('fake-fcm-token-3');
      await pumpEventQueue();
      expect(rig.api.deviceRegistrations.single.token, 'fake-fcm-token-3');
    });
  });

  group('signing out', () {
    test('takes the token off the server, off the phone, and switches push off again', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);

      await rig.push.forgetThisPhone();

      expect(rig.api.deviceRemovals, <String>['fake-fcm-token-1']);
      expect(rig.api.pushTokens, isEmpty);
      expect(rig.messaging.deleteCalls, 1);
      expect(rig.messaging.enabled, isFalse);
      expect(await rig.db.readMeta(MetaKey.pushToken), isNull);
      expect(rig.push.state, PushState.unavailable);
    });

    test('works with no network: it never throws, and the phone still lets go of the token', () async {
      final FakeApi api = FakeApi();
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted), api: api);
      addTearDown(rig.dispose);
      api.offline = true;
      rig.messaging.failToDelete = true;

      await rig.push.forgetThisPhone();

      expect(rig.messaging.deleteCalls, 1);
      expect(rig.messaging.enabled, isFalse);
      expect(await rig.db.readMeta(MetaKey.pushToken), isNull);
    });

    test('a token that comes back after signing out is not sent for the next person', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);
      await rig.push.forgetThisPhone();

      rig.messaging.refreshToken('fake-fcm-token-7');
      await rig.push.retryPending();
      await pumpEventQueue();

      expect(rig.api.deviceRegistrations.map((r) => r.token), <String>['fake-fcm-token-1']);
    });

    test('a phone nobody registered has nothing to remove, and signing out still works', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);

      await rig.push.forgetThisPhone();

      expect(rig.api.deviceRemovals, isEmpty);
      expect(rig.push.state, PushState.unavailable);
    });
  });

  group('a build with no Firebase settings', () {
    test('does nothing at all and never throws', () async {
      final FakePushMessaging none = FakePushMessaging(configured: false);
      final _Rig rig = await _started(messaging: none);
      addTearDown(rig.dispose);

      expect(rig.push.available, isFalse);
      expect(rig.push.state, PushState.unavailable);
      expect(none.initialiseCalls, 0);
      expect(none.permissionReads, 0);
      expect(await rig.push.turnOn(), PushState.unavailable);
      await rig.push.refresh();
      await rig.push.forgetThisPhone();
      await rig.push.retryPending();
      expect(none.permissionRequests, 0);
      expect(none.deleteCalls, 0);
      expect(none.enabledCalls, isEmpty);
      expect(rig.api.deviceRegistrations, isEmpty);
      expect(rig.api.deviceRemovals, isEmpty);
    });

    test('Firebase failing to start leaves the app as if push did not exist', () async {
      final FakePushMessaging broken = FakePushMessaging()..failToStart = true;
      final _Rig rig = await _started(messaging: broken);
      addTearDown(rig.dispose);

      expect(rig.push.available, isFalse);
      expect(rig.push.state, PushState.unavailable);
      expect(await rig.push.turnOn(), PushState.unavailable);
      expect(broken.permissionRequests, 0);
    });
  });

  group('what arrives', () {
    test('a message while the app is open reaches the foreground stream', () async {
      final _Rig rig = await _started(messaging: FakePushMessaging(permissionNow: PushPermission.granted));
      addTearDown(rig.dispose);
      final List<PushMessage> seen = <PushMessage>[];
      final StreamSubscription<PushMessage> sub = rig.push.foreground.listen(seen.add);
      addTearDown(sub.cancel);

      rig.messaging.deliver(const PushMessage(title: 'Approval needed', data: <String, String>{'type': 'approval', 'id': '7'}));
      await pumpEventQueue();

      expect(seen.single.title, 'Approval needed');
    });

    test('a tap while the app is in the background reaches the taps stream', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);
      final List<PushMessage> seen = <PushMessage>[];
      final StreamSubscription<PushMessage> sub = rig.push.taps.listen(seen.add);
      addTearDown(sub.cancel);

      rig.messaging.tap(const PushMessage(data: <String, String>{'type': 'issue', 'id': '9'}));
      await pumpEventQueue();

      expect(seen.single.data['id'], '9');
    });

    test('the tap that started the app is kept for a listener that arrives later', () async {
      final FakePushMessaging phone = FakePushMessaging()
        ..launch = const PushMessage(data: <String, String>{'type': 'dpr', 'id': '12'});
      final _Rig rig = await _started(messaging: phone);
      addTearDown(rig.dispose);
      await pumpEventQueue();

      final PushMessage first = await rig.push.taps.first;
      expect(first.data['type'], 'dpr');
    });

    test('a listener that comes and goes can be replaced by another', () async {
      final _Rig rig = await _started();
      addTearDown(rig.dispose);
      final List<String> seen = <String>[];
      final StreamSubscription<PushMessage> first = rig.push.taps.listen((m) => seen.add('first ${m.data['id']}'));
      rig.messaging.tap(const PushMessage(data: <String, String>{'id': '1'}));
      await pumpEventQueue();
      await first.cancel();

      rig.messaging.tap(const PushMessage(data: <String, String>{'id': '2'}));
      await pumpEventQueue();
      final StreamSubscription<PushMessage> second = rig.push.taps.listen((m) => seen.add('second ${m.data['id']}'));
      addTearDown(second.cancel);
      await pumpEventQueue();
      rig.messaging.tap(const PushMessage(data: <String, String>{'id': '3'}));
      await pumpEventQueue();

      // The tap that came while nobody listened is not lost.
      expect(seen, <String>['first 1', 'second 2', 'second 3']);
    });
  });
}
