import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';
import 'package:nirmaan360/features/notifications/data/notification_preferences.dart';
import 'package:nirmaan360/features/notifications/view/notification_settings_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/fake_push.dart';
import '../../support/test_app.dart';

/// What the live server answers for a site engineer with email connected.
Map<String, dynamic> _staffSwitches() => <String, dynamic>{
  'in_app': <String, dynamic>{'enabled': true, 'connected': true},
  'email': <String, dynamic>{'enabled': true, 'connected': true},
  'push': <String, dynamic>{
    'enabled': true,
    'connected': true,
    'categories': <Map<String, dynamic>>[
      <String, dynamic>{'key': 'alerts', 'label': 'Site alerts', 'hint': '', 'enabled': true},
      <String, dynamic>{'key': 'approvals', 'label': 'Approvals', 'hint': '', 'enabled': true},
      <String, dynamic>{'key': 'material', 'label': 'Material checks', 'hint': '', 'enabled': true},
      <String, dynamic>{'key': 'issues', 'label': 'Issues', 'hint': '', 'enabled': true},
    ],
    'quiet_hours': <String, dynamic>{'enabled': false, 'from': '22:00', 'until': '07:00', 'timezone': 'Asia/Kolkata'},
  },
  'whatsapp': <String, dynamic>{'enabled': false, 'connected': false},
};

Future<TestApp> _openSettings(WidgetTester tester, {FakeApi? api, String? locale, double textScale = 1}) async {
  final FakeApi server = api ?? (FakeApi()..notificationSwitches = _staffSwitches());
  final TestApp app = await TestApp.create(api: server, pushMessaging: FakePushMessaging(permissionNow: PushPermission.granted));
  addTearDown(app.dispose);
  await app.services.startPush();
  await pumpShellApp(tester, app, location: Routes.notificationSettings, locale: locale, textScale: textScale);
  return app;
}

Finder _switchIn(String key) =>
    find.descendant(of: find.byKey(ValueKey<String>(key)), matching: find.byType(Switch));

bool _isOn(WidgetTester tester, String key) => tester.widget<Switch>(_switchIn(key)).value;

void main() {
  setUpAll(loadRealFonts);
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  test('reading the server\'s answer never throws, whatever is missing', () {
    final NotificationPreferences empty = NotificationPreferences.fromJson(const <String, dynamic>{});
    expect(empty.pushEnabled, isFalse);
    expect(empty.categories, isEmpty);
    expect(empty.quietHours.from, '22:00');
    expect(empty.email, isNull);

    final NotificationPreferences odd = NotificationPreferences.fromJson(<String, dynamic>{
      'push': <String, dynamic>{
        'enabled': 'yes',
        'categories': <Object?>['alerts', null, <String, dynamic>{'label': 'no key'}],
        'quiet_hours': <String, dynamic>{'enabled': true, 'from': '25:00', 'until': 7},
      },
      'email': 'on',
    });
    expect(odd.pushEnabled, isFalse);
    expect(odd.categories, isEmpty);
    expect(odd.quietHours.enabled, isTrue);
    expect(odd.quietHours.from, '22:00');
    expect(odd.quietHours.until, '07:00');
    expect(odd.email, isNull);
  });

  testWidgets('Settings opens it from the push row once push is on', (tester) async {
    final FakeApi api = FakeApi()..notificationSwitches = _staffSwitches();
    final TestApp app = await TestApp.create(api: api, pushMessaging: FakePushMessaging(permissionNow: PushPermission.granted));
    addTearDown(app.dispose);
    await app.services.startPush();
    await pumpShellApp(tester, app, location: Routes.settings);

    await tester.tap(find.byKey(const ValueKey<String>('settings-push')));
    await settle(tester);

    expect(find.byType(NotificationSettingsScreen), findsOneWidget);
    expect(find.text(en.pushPrefsApprovals), findsOneWidget);
  });

  testWidgets('shows each kind in the app\'s words, and only channels the office has', (tester) async {
    await _openSettings(tester);

    expect(find.text(en.pushPrefsPush), findsOneWidget);
    expect(find.text(en.pushPrefsAlerts), findsOneWidget);
    expect(find.text(en.pushPrefsAlertsHint), findsOneWidget);
    expect(find.text(en.pushPrefsMaterial), findsOneWidget);
    expect(find.text(en.pushPrefsPayments), findsNothing, reason: 'the server did not offer it');
    await tester.scrollUntilVisible(find.text(en.pushPrefsEmail), 200);
    expect(find.text(en.pushPrefsEmail), findsOneWidget);
    expect(find.text(en.pushPrefsWhatsapp), findsNothing, reason: 'WhatsApp is not connected');
    expect(find.text(en.pushPrefsNotConnected), findsNothing);
  });

  testWidgets('a switch saves at once and sends only what changed', (tester) async {
    final TestApp app = await _openSettings(tester);

    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-material')));
    await settle(tester);

    expect(app.api.preferenceChanges.single.changes, <String, Object?>{
      'categories': <String, bool>{'material': false},
    });
    expect(app.api.preferenceChanges.single.portal, isFalse);
    expect(_isOn(tester, 'push-prefs-material'), isFalse);
    expect(_isOn(tester, 'push-prefs-alerts'), isTrue);
  });

  testWidgets('turning push off greys every kind below it', (tester) async {
    final TestApp app = await _openSettings(tester);

    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-push')));
    await settle(tester);

    expect(app.api.preferenceChanges.single.changes, <String, Object?>{'push': false});
    expect(tester.widget<Switch>(_switchIn('push-prefs-alerts')).onChanged, isNull);
  });

  testWidgets('quiet hours show their times once on, and a picked time is saved', (tester) async {
    final TestApp app = await _openSettings(tester);
    expect(find.byKey(const ValueKey<String>('push-prefs-quiet-from')), findsNothing);

    await tester.scrollUntilVisible(find.byKey(const ValueKey<String>('push-prefs-quiet')), 200);
    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-quiet')));
    await settle(tester);
    expect(app.api.preferenceChanges.last.changes, <String, Object?>{
      'quiet_hours': <String, Object?>{'enabled': true},
    });

    await tester.scrollUntilVisible(find.byKey(const ValueKey<String>('push-prefs-quiet-until')), 200);
    expect(find.text('10:00 PM'), findsOneWidget);
    expect(find.text('7:00 AM'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-quiet-until')));
    await settle(tester);
    expect(find.byType(TimePickerDialog), findsOneWidget);
    await tester.tap(find.text('OK'));
    await settle(tester);
    // The same time again sends nothing.
    expect(app.api.preferenceChanges, hasLength(1));
  });

  testWidgets('a change the server does not take is undone and said once', (tester) async {
    final TestApp app = await _openSettings(tester);
    app.api.failNextPreferenceChange = const ServerFailure('down', statusCode: 500);

    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-issues')));
    await settle(tester);

    expect(_isOn(tester, 'push-prefs-issues'), isTrue);
    expect(find.text(en.pushPrefsSaveFailed), findsOneWidget);
  });

  testWidgets('offline, it says the settings need the internet and can try again', (tester) async {
    final FakeApi api = FakeApi()..notificationSwitches = _staffSwitches();
    final TestApp app = await TestApp.create(api: api, pushMessaging: FakePushMessaging(permissionNow: PushPermission.granted));
    addTearDown(app.dispose);
    api.offline = true;
    await pumpShellApp(tester, app, location: Routes.notificationSettings);

    expect(find.text(en.pushPrefsOfflineTitle), findsOneWidget);
    api.offline = false;
    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-retry')));
    await settle(tester);
    expect(find.text(en.pushPrefsApprovals), findsOneWidget);
  });

  testWidgets('says so when the office has not connected push yet', (tester) async {
    final Map<String, dynamic> switches = _staffSwitches();
    (switches['push'] as Map<String, dynamic>)['connected'] = false;
    await _openSettings(tester, api: FakeApi()..notificationSwitches = switches);

    expect(find.text(en.pushPrefsNotConnected), findsOneWidget);
  });

  testWidgets('a contractor login reads and changes its one kind under the portal', (tester) async {
    final FakeApi api = FakeApi()
      ..becomeContractor()
      ..notificationSwitches = <String, dynamic>{
        'push': <String, dynamic>{
          'enabled': true,
          'connected': true,
          'categories': <Map<String, dynamic>>[
            <String, dynamic>{'key': 'contractor', 'label': 'My payments and bills', 'hint': '', 'enabled': true},
          ],
          'quiet_hours': <String, dynamic>{'enabled': false, 'from': '22:00', 'until': '07:00', 'timezone': 'Asia/Kolkata'},
        },
      };
    final TestApp app = await TestApp.create(api: api, seed: false, signedIn: false, pushMessaging: FakePushMessaging(permissionNow: PushPermission.granted));
    addTearDown(app.dispose);
    await app.services.auth.signIn(email: 'contractor@demo.test', password: 'password');
    await app.services.startPush();
    await pumpShellApp(tester, app, location: Routes.notificationSettings);

    expect(find.byType(NotificationSettingsScreen), findsOneWidget, reason: 'the gate lets a contractor in');
    expect(find.text(en.pushPrefsContractor), findsOneWidget);
    expect(find.text(en.pushPrefsOtherSection), findsNothing);

    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-contractor')));
    await settle(tester);
    expect(api.preferenceChanges.single.portal, isTrue);
  });

  for (final String locale in <String>['mr', 'hi']) {
    testWidgets('fits 360dp in $locale at a large text size', (tester) async {
      final Map<String, dynamic> switches = _staffSwitches();
      ((switches['push'] as Map<String, dynamic>)['quiet_hours'] as Map<String, dynamic>)['enabled'] = true;
      await _openSettings(tester, api: FakeApi()..notificationSwitches = switches, locale: locale, textScale: 1.3);

      expect(find.byType(NotificationSettingsScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.scrollUntilVisible(find.byKey(const ValueKey<String>('push-prefs-email')), 300);
      expect(tester.takeException(), isNull);
    });
  }
}
