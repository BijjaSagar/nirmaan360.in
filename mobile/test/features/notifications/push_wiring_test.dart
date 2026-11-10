import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';
import 'package:nirmaan360/core/push/push_service.dart';

import '../../support/fake_push.dart';
import '../../support/test_app.dart';

void main() {
  test('a member of staff who signs in is offered push', () async {
    final FakePushMessaging phone = FakePushMessaging();
    final TestApp app = await TestApp.create(pushMessaging: phone);
    addTearDown(app.dispose);

    await app.services.startPush();

    expect(app.services.push.state, PushState.askFirst);
    expect(phone.permissionRequests, 0);
  });

  test('a signed-out phone is offered nothing, then push begins when someone signs in', () async {
    final FakePushMessaging phone = FakePushMessaging();
    final TestApp app = await TestApp.create(signedIn: false, seed: false, pushMessaging: phone);
    addTearDown(app.dispose);
    await app.services.startPush();
    expect(app.services.push.state, PushState.unavailable);
    expect(phone.permissionReads, 0);

    await app.services.auth.signIn(email: 'engineer@demo.test', password: 'x');
    await pumpEventQueue();

    expect(app.services.push.state, PushState.askFirst);
  });

  test('a contractor login gets push for its own payments and bills, under its portal', () async {
    final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.granted);
    final TestApp withPush = await TestApp.create(
      seed: false,
      signedIn: false,
      api: FakeApi()..becomeContractor(),
      pushMessaging: phone,
    );
    addTearDown(withPush.dispose);
    await withPush.services.auth.signIn(email: 'contractor@demo.test', password: 'password');
    await withPush.services.startPush();
    await pumpEventQueue();

    expect(withPush.services.session.user!.isContractor, isTrue);
    expect(withPush.services.push.state, PushState.on);
    expect(phone.permissionRequests, 0, reason: 'the phone already allows it');
    final registration = withPush.api.deviceRegistrations.single;
    expect(registration.portal, isTrue);
    expect(registration.appVersion, isNotEmpty);
    expect(<String>['en', 'mr', 'hi'], contains(registration.locale));
  });

  test('choosing another language sends the registration again in it', () async {
    final TestApp app = await TestApp.create(pushMessaging: FakePushMessaging(permissionNow: PushPermission.granted));
    addTearDown(app.dispose);
    await app.services.startPush();
    await pumpEventQueue();
    final String? first = app.api.deviceRegistrations.single.locale;

    final String other = first == 'hi' ? 'mr' : 'hi';
    await app.services.auth.setLocale(other);
    expect(app.api.deviceRegistrations.map((r) => r.locale), <String?>[first, other]);

    // The same choice again sends nothing.
    await app.services.auth.setLocale(other);
    expect(app.api.deviceRegistrations, hasLength(2));
  });

  test('a company on the offline plan is never offered push', () async {
    final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.granted);
    final FakeApi api = FakeApi()..offlineCompany = true;
    final TestApp app = await TestApp.create(seed: false, signedIn: false, api: api, pushMessaging: phone);
    addTearDown(app.dispose);
    await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
    await app.services.startPush();
    await pumpEventQueue();

    expect(app.services.offlinePlan.isLocalOnly, isTrue);
    expect(app.services.push.state, PushState.unavailable);
    expect(phone.permissionReads, 0);
    expect(api.deviceRegistrations, isEmpty);
  });

  test('signing out removes this phone from the server while the sign-in still works', () async {
    final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.granted);
    final TestApp app = await TestApp.create(pushMessaging: phone);
    addTearDown(app.dispose);
    await app.services.startPush();
    await pumpEventQueue();
    expect(app.api.pushTokens, <String>['fake-fcm-token-1']);

    await app.services.auth.signOut();

    expect(app.api.deviceRemovals, <String>['fake-fcm-token-1']);
    expect(app.api.pushTokens, isEmpty);
    expect(app.api.logoutCalls, 1);
    expect(phone.deleteCalls, 1);
    expect(phone.enabled, isFalse);
    expect(app.services.push.state, PushState.unavailable);
  });

  test('signing out with no network still signs out', () async {
    final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.granted);
    final TestApp app = await TestApp.create(pushMessaging: phone);
    addTearDown(app.dispose);
    await app.services.startPush();
    await pumpEventQueue();
    app.api.offline = true;
    phone.failToDelete = true;

    await app.services.auth.signOut();

    expect(app.services.session.current.isSignedIn, isFalse);
    expect(phone.enabled, isFalse);
  });

  test('having been asked is remembered through a sign-out, and the next person is not asked again', () async {
    final FakePushMessaging phone = FakePushMessaging();
    final TestApp app = await TestApp.create(pushMessaging: phone);
    addTearDown(app.dispose);
    await app.services.startPush();
    await app.services.push.declineForNow();

    await app.services.auth.signOut();

    expect(await app.db.readMeta(MetaKey.pushExplained), isNotNull);
    await app.services.auth.signIn(email: 'engineer@demo.test', password: 'x');
    await pumpEventQueue();
    expect(app.services.push.state, PushState.off);
    expect(phone.permissionRequests, 0);
  });

  test('a build with no Firebase settings runs the whole sign-in and sign-out untouched', () async {
    final TestApp app = await TestApp.create();
    addTearDown(app.dispose);
    await app.services.startPush();

    expect(app.services.push.available, isFalse);
    expect(app.services.push.state, PushState.unavailable);

    await app.services.auth.signOut();
    await app.services.auth.signIn(email: 'engineer@demo.test', password: 'x');

    expect(app.services.session.current.isSignedIn, isTrue);
    expect(app.api.deviceRegistrations, isEmpty);
    expect(app.api.deviceRemovals, isEmpty);
  });
}
