import 'dart:convert';

import 'package:cryptography/cryptography.dart' show SimplePublicKey;
import 'package:cryptography/dart.dart' show DartEd25519;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/offline/licence_token.dart';
import 'package:nirmaan360/core/offline/offline_plan.dart';
import 'package:nirmaan360/core/session/current_user.dart';

import '../../support/test_app.dart';

const _deviceLimit = ServerFailure(
  'Every slot is taken.',
  statusCode: 409,
  code: 'device_limit',
  data: <String, dynamic>{'manage_url': 'https://example.test/offline/devices'},
);
const _revoked = ServerFailure('Freed.', statusCode: 410, code: 'licence_revoked');
const _missing = ServerFailure('No licence.', statusCode: 404, code: 'licence_missing');
const _notOffline = ServerFailure('Sync as usual.', statusCode: 403, code: 'not_offline_plan');

Future<TestApp> _offlineApp() async {
  final app = await TestApp.create(seed: false, signedIn: false, api: FakeApi()..offlineCompany = true);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  return app;
}

void main() {
  group('the licence token', () {
    late DartEd25519 ed;
    late String goodKey;

    Future<String> sign(Map<String, Object> claims, {bool breakIt = false}) async {
      final keys = await ed.newKeyPair();
      final SimplePublicKey public = await keys.extractPublicKey();
      goodKey = base64.encode(public.bytes);
      final payload = utf8.encode(jsonEncode(claims));
      final signature = await ed.sign(payload, keyPair: keys);
      String b(List<int> bytes) => base64Url.encode(bytes).replaceAll('=', '');
      if (breakIt) {
        final tampered = utf8.encode(jsonEncode(<String, Object>{...claims, 'plan': 'pro'}));
        return 'v1.${b(tampered)}.${b(signature.bytes)}';
      }
      return 'v1.${b(payload)}.${b(signature.bytes)}';
    }

    const claims = <String, Object>{
      'lic': 3,
      'org': 9,
      'dev': 'phone-0001',
      'plan': 'offline',
      'features': <String>['site', 'labour'],
      'exp': 1893456000,
    };

    setUp(() => ed = DartEd25519());

    test('a genuine token gives its claims', () async {
      final token = await sign(claims);
      final found = await verifyLicenceToken(token, publicKeyBase64: goodKey, deviceId: 'phone-0001');
      expect(found?.licenceId, 3);
      expect(found?.organizationId, 9);
      expect(found?.plan, 'offline');
      expect(found?.features, <String>{'site', 'labour'});
      expect(found?.expiresAt, DateTime.fromMillisecondsSinceEpoch(1893456000 * 1000, isUtc: true));
    });

    test('a changed payload is refused', () async {
      final token = await sign(claims, breakIt: true);
      expect(await verifyLicenceToken(token, publicKeyBase64: goodKey, deviceId: 'phone-0001'), isNull);
    });

    test('another phone\'s token is refused', () async {
      final token = await sign(claims);
      expect(await verifyLicenceToken(token, publicKeyBase64: goodKey, deviceId: 'phone-0002'), isNull);
    });

    test('the wrong key is refused', () async {
      final token = await sign(claims);
      final other = await ed.newKeyPair();
      final SimplePublicKey public = await other.extractPublicKey();
      expect(await verifyLicenceToken(token, publicKeyBase64: base64.encode(public.bytes), deviceId: 'phone-0001'), isNull);
    });

    test('rubbish is refused without throwing', () async {
      await sign(claims);
      for (final bad in <String>['', 'v1.a', 'v2.YQ.YQ', 'v1.!!!.@@@', 'v1.YQ.YQ']) {
        expect(await verifyLicenceToken(bad, publicKeyBase64: goodKey, deviceId: 'phone-0001'), isNull, reason: bad);
      }
    });
  });

  group('a company on a normal plan', () {
    test('signs in, syncs, and stores nothing about the offline plan', () async {
      final api = FakeApi()..seedDemo();
      final app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);

      await app.services.auth.signIn(email: 'pm@demo.test', password: 'x');

      expect(api.offlineStatusCalls, 1);
      expect(api.activateCalls, 0);
      expect(api.refreshCalls, 0);
      expect(api.pullCalls, isNotEmpty, reason: 'the first pull still happens');
      expect(app.services.offlinePlan.isLocalOnly, isFalse);
      expect(await app.db.readMeta(MetaKey.offlinePlan), isNull);
      expect(await app.db.readMeta(MetaKey.offlineDeviceId), isNull);
    });

    for (final (String name, ApiFailure failure) in <(String, ApiFailure)>[
      ('no network', const OfflineFailure()),
      ('an old server with no such route', const ServerFailure('Not found.', statusCode: 404)),
      ('a server error', const ServerFailure('Oops.', statusCode: 500)),
      ('a rate limit', const RateLimited()),
    ]) {
      test('still syncs when the plan question meets $name', () async {
        final api = FakeApi()
          ..seedDemo()
          ..failNextOfflineStatus = failure;
        final app = await TestApp.create(seed: false, signedIn: false, api: api);
        addTearDown(app.dispose);

        await app.services.auth.signIn(email: 'pm@demo.test', password: 'x');

        expect(api.pullCalls, isNotEmpty);
        expect(app.services.offlinePlan.isLocalOnly, isFalse);
        expect(await app.db.readMeta(MetaKey.offlinePlan), isNull);
      });
    }

    test('is not asked again until the interval has passed', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      final OfflinePlan plan = app.services.offlinePlan;

      await plan.check();
      await plan.check();
      expect(app.api.offlineStatusCalls, 1);

      app.clock.advance(const Duration(hours: 7));
      await plan.check();
      expect(app.api.offlineStatusCalls, 2);
      expect(plan.isLocalOnly, isFalse);
    });

    test('a contractor login is never asked', () async {
      final app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      final Map<String, dynamic> json = <String, dynamic>{...app.api.user, 'kind': CurrentUser.contractorKind};
      await app.services.session.startSession('t', CurrentUser.fromJson(json));

      await app.services.offlinePlan.check(force: true);

      expect(app.api.offlineStatusCalls, 0);
    });
  });

  group('a phone on the offline plan', () {
    test('takes a licence at sign-in and checks its signature', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final plan = app.services.offlinePlan;

      expect(plan.isLocalOnly, isTrue);
      expect(plan.state?.mode, OfflineMode.local);
      expect(plan.state?.hasToken, isTrue);
      expect(plan.state?.standing(app.clock.now()), LicenceStanding.active);
      expect(app.api.activateCalls, 1);
      expect(app.api.lastDeviceId, startsWith('n360-'));

      final OfflinePlanState? stored = await OfflinePlan.readStored(app.db);
      expect(stored?.token, plan.state?.token);
      expect(stored?.expiresAt, plan.state?.expiresAt);
    });

    test('keeps one device id through a restart and refreshes with it', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final String? first = app.api.lastDeviceId;

      await app.services.offlinePlan.load();
      app.clock.advance(const Duration(hours: 2));
      await app.services.offlinePlan.check();

      expect(app.api.refreshCalls, 1);
      expect(app.api.lastDeviceId, first);
    });

    test('a forged token is never stored', () async {
      final api = FakeApi()
        ..offlineCompany = true
        ..forgeLicence = true;
      final app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);

      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

      expect(app.services.offlinePlan.state?.hasToken, isFalse);
      expect(app.services.offlinePlan.state?.issue, LicenceIssue.invalid);
    });

    test('a forged refresh leaves the working licence alone', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final String? good = app.services.offlinePlan.state?.token;

      app.api.forgeLicence = true;
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.state?.token, good);
      expect(app.services.offlinePlan.state?.issue, LicenceIssue.none);
    });

    test('a token changed in storage is caught on load, and nothing is lost', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final state = app.services.offlinePlan.state!;
      await app.db.writeMeta(
        MetaKey.offlinePlan,
        jsonEncode(state.copyWith(token: '${state.token}x').toJson()),
      );

      await app.services.offlinePlan.load();

      expect(app.services.offlinePlan.isLocalOnly, isTrue);
      expect(app.services.offlinePlan.state?.standing(app.clock.now()), LicenceStanding.ended);
    });

    test('at the device limit it shows where to free a phone', () async {
      final api = FakeApi()
        ..offlineCompany = true
        ..activateFailure = _deviceLimit;
      final app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);

      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

      final state = app.services.offlinePlan.state!;
      expect(state.issue, LicenceIssue.deviceLimit);
      expect(state.manageUrl, 'https://example.test/offline/devices');
      expect(state.hasToken, isFalse);
    });

    test('a freed phone is sent to the address the server names', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);

      app.api.refreshFailure = const ServerFailure(
        'Freed.',
        statusCode: 410,
        code: 'licence_revoked',
        data: <String, dynamic>{'code': 'licence_revoked', 'manage_url': 'https://example.test/offline/devices?from=server'},
      );
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.state?.issue, LicenceIssue.revoked);
      expect(app.services.offlinePlan.state?.manageUrl, 'https://example.test/offline/devices?from=server');
    });

    for (final (String name, Map<String, dynamic>? data) in <(String, Map<String, dynamic>?)>[
      ('no data at all', null),
      ('no manage_url', <String, dynamic>{'code': 'licence_revoked'}),
      ('an empty manage_url', <String, dynamic>{'manage_url': ''}),
      ('a manage_url that is not an address', <String, dynamic>{'manage_url': 'javascript:alert(1)'}),
      ('a manage_url that is not text', <String, dynamic>{'manage_url': 42}),
    ]) {
      test('a freed phone falls back to the built address when the server sends $name', () async {
        final app = await _offlineApp();
        addTearDown(app.dispose);

        app.api.refreshFailure = ServerFailure('Freed.', statusCode: 410, code: 'licence_revoked', data: data);
        await app.services.offlinePlan.check(force: true);

        expect(app.services.offlinePlan.state?.issue, LicenceIssue.revoked);
        expect(app.services.offlinePlan.state?.manageUrl, endsWith('/offline/devices'));
        expect(app.services.offlinePlan.state?.manageUrl, startsWith('http'));
      });
    }

    test('the full plan is sent to the address the server names, and falls back the same way', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);

      app.api.refreshFailure = const ServerFailure('Full.', statusCode: 409, code: 'device_limit', data: <String, dynamic>{'manage_url': 42});
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.state?.issue, LicenceIssue.deviceLimit);
      expect(app.services.offlinePlan.state?.manageUrl, endsWith('/offline/devices'));
    });

    test('a freed phone keeps its token and does not take the slot back', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final String? token = app.services.offlinePlan.state?.token;

      app.api.refreshFailure = _revoked;
      await app.services.offlinePlan.check(force: true);

      final state = app.services.offlinePlan.state!;
      expect(state.issue, LicenceIssue.revoked);
      expect(state.token, token);
      expect(state.manageUrl, endsWith('/offline/devices'));
      expect(app.api.activateCalls, 1, reason: 'only the first activation');

      await app.services.offlinePlan.check(force: true);
      expect(app.api.refreshCalls, 1, reason: 'no point asking again');

      app.api.refreshFailure = null;
      await app.services.offlinePlan.activateAgain();
      expect(app.services.offlinePlan.state?.issue, LicenceIssue.none);
    });

    test('a missing licence is activated', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);

      app.api.refreshFailure = _missing;
      await app.services.offlinePlan.check(force: true);

      expect(app.api.activateCalls, 2);
      expect(app.services.offlinePlan.state?.issue, LicenceIssue.none);
    });

    test('no network leaves everything as it was', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final before = app.services.offlinePlan.state;

      app.api.offline = true;
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.state, before);
    });

    test('with nothing on the phone it just becomes normal', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);

      app.api.offlineCompany = false;
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.isLocalOnly, isFalse);
      expect(await app.db.readMeta(MetaKey.offlinePlan), isNull);
    });

    test('a release gives the slot back and keeps the token', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final String? token = app.services.offlinePlan.state?.token;

      await app.services.offlinePlan.release();

      expect(app.api.releaseCalls, 1);
      expect(app.services.offlinePlan.state?.token, token);
      expect(app.services.offlinePlan.state?.issue, LicenceIssue.revoked);
    });

    test('the device id survives a wipe of the local data', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final String? first = await app.db.readMeta(MetaKey.offlineDeviceId);
      expect(first, isNotNull);

      // Leave the plan first: on it, a sign-out wipes nothing at all.
      await app.services.offlinePlan.leave();
      await app.services.auth.signOut(discardUnsent: true);

      expect(await app.services.auth.unsentWorkCount(), 0);
      expect(await app.db.readMeta(MetaKey.offlineDeviceId), first);
    });

    test('blocks only what the server would refuse', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);
      final OfflinePlan plan = app.services.offlinePlan;

      for (final path in <String>['/sync/pull', '/sync/push', '/sync/upload', '/projects', '/workers', '/approvals', 'https://example.test/x']) {
        expect(plan.blocksPath(path), isTrue, reason: path);
      }
      // Deleting the account, and signing up anew, must work from an offline-plan phone.
      for (final path in <String>[
        '/me',
        '/auth/login',
        '/auth/logout',
        '/auth/register',
        '/auth/account/deletion',
        '/offline/status',
        '/offline/devices/refresh',
      ]) {
        expect(plan.blocksPath(path), isFalse, reason: path);
      }
    });

    test('not_offline_plan on refresh counts as the plan having changed', () async {
      final app = await _offlineApp();
      addTearDown(app.dispose);

      app.api.refreshFailure = _notOffline;
      await app.services.offlinePlan.check(force: true);

      // Nothing on the phone, so nothing to ask about.
      expect(app.services.offlinePlan.isLocalOnly, isFalse);
    });
  });
}
