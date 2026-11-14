import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/push/push_message.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';
import 'package:nirmaan360/core/push/push_service.dart';
import 'package:nirmaan360/features/approvals/view/approval_detail_screen.dart';
import 'package:nirmaan360/features/approvals/view/approvals_inbox_screen.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_list_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_detail_screen.dart';
import 'package:nirmaan360/features/notifications/view/push_prompt.dart';
import 'package:nirmaan360/features/site_alerts/view/site_dashboard_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/fake_push.dart';
import '../../support/test_app.dart';

PushMessage _tap(Map<String, String> data) => PushMessage(title: 'Title', body: 'Body', data: data);

/// A signed-in phone with push configured, and the app open on Home.
Future<({TestApp app, FakePushMessaging phone})> _open(
  WidgetTester tester, {
  FakeApi? api,
  FakePushMessaging? phone,
  String? locale,
  double textScale = 1,
  bool startPush = true,
}) async {
  final FakePushMessaging fake = phone ?? FakePushMessaging(permissionNow: PushPermission.granted);
  final TestApp app = await TestApp.create(api: api, pushMessaging: fake);
  addTearDown(app.dispose);
  if (startPush) await app.services.startPush();
  await pumpShellApp(tester, app, locale: locale, textScale: textScale);
  return (app: app, phone: fake);
}

void main() {
  setUpAll(loadRealFonts);
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  group('the explanation', () {
    testWidgets('comes once, a moment after arriving on Home, and asks the phone only after yes', (tester) async {
      final (:app, :phone) = await _open(tester, phone: FakePushMessaging());

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text(en.pushPromptTitle), findsNothing);

      await tester.pump(const Duration(seconds: 2));
      await settle(tester);
      expect(find.text(en.pushPromptTitle), findsOneWidget);
      expect(find.text(en.pushPromptBody), findsOneWidget);
      expect(phone.permissionRequests, 0);

      await tester.tap(find.text(en.pushPromptTurnOn));
      await settle(tester);
      expect(phone.permissionRequests, 1);
      expect(app.services.push.state, PushState.on);
      expect(app.api.deviceRegistrations, hasLength(1));
      expect(find.text(en.pushPromptTitle), findsNothing);

      // Back on Home later in the day: it does not come again.
      await tester.pump(const Duration(seconds: 10));
      await settle(tester);
      expect(find.text(en.pushPromptTitle), findsNothing);
      expect(phone.permissionRequests, 1);
    });

    testWidgets('not now keeps the phone\'s own question away, and it never comes again', (tester) async {
      final (:app, :phone) = await _open(tester, phone: FakePushMessaging());
      await tester.pump(const Duration(seconds: 2));
      await settle(tester);

      await tester.tap(find.text(en.pushPromptNotNow));
      await settle(tester);

      expect(app.services.push.state, PushState.off);
      expect(phone.permissionRequests, 0);
      await tester.pump(const Duration(seconds: 10));
      await settle(tester);
      expect(find.text(en.pushPromptTitle), findsNothing);
    });

    testWidgets('closing it without choosing counts as not now', (tester) async {
      final (:app, :phone) = await _open(tester, phone: FakePushMessaging());
      await tester.pump(const Duration(seconds: 2));
      await settle(tester);

      await tester.tapAt(const Offset(180, 40)); // The dimmed page above the sheet.
      await settle(tester);

      expect(find.text(en.pushPromptTitle), findsNothing);
      expect(app.services.push.state, PushState.off);
      expect(phone.permissionRequests, 0);
    });

    testWidgets('a phone that already allows notifications is not asked and sees no explanation', (tester) async {
      final (:app, :phone) = await _open(tester);
      await tester.pump(const Duration(seconds: 3));
      await settle(tester);

      expect(find.text(en.pushPromptTitle), findsNothing);
      expect(phone.permissionRequests, 0);
      expect(app.services.push.state, PushState.on);
    });

    testWidgets('a build with no Firebase settings shows nothing of it', (tester) async {
      final TestApp app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.startPush();
      await pumpShellApp(tester, app);
      await tester.pump(const Duration(seconds: 3));
      await settle(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.byType(PushPromptSheet), findsNothing);
      expect(tester.takeException(), isNull);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('fits 360dp in $locale at a large text size', (tester) async {
        await _open(tester, phone: FakePushMessaging(), locale: locale, textScale: 1.3);
        await tester.pump(const Duration(seconds: 2));
        await settle(tester);

        expect(find.byType(PushPromptSheet), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('in Settings', () {
    testWidgets('is the way back after not now, and asks the phone when tapped', (tester) async {
      final (:app, :phone) = await _open(tester, phone: FakePushMessaging());
      await tester.pump(const Duration(seconds: 2));
      await settle(tester);
      await tester.tap(find.text(en.pushPromptNotNow));
      await settle(tester);

      // Closing the app and opening it on Settings, as the person would later.
      await tester.pumpWidget(const SizedBox());
      await pumpShellApp(tester, app, location: Routes.settings);
      expect(find.text(en.pushSettingsTitle), findsOneWidget);
      expect(find.text(en.pushSettingsOff), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey<String>('settings-push')));
      await settle(tester);

      expect(phone.permissionRequests, 1);
      expect(find.text(en.pushSettingsOn), findsOneWidget);
      expect(app.api.deviceRegistrations, hasLength(1));
    });

    testWidgets('a blocked phone shows the way out and checks again when asked', (tester) async {
      final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.blocked, answerToRequest: PushPermission.blocked);
      final TestApp app = await TestApp.create(pushMessaging: phone);
      addTearDown(app.dispose);
      await app.services.startPush();
      await pumpShellApp(tester, app, location: Routes.settings);

      expect(find.text(en.pushSettingsBlockedShort), findsOneWidget);
      expect(find.text(en.pushSettingsBlocked), findsOneWidget);

      phone.permissionNow = PushPermission.granted;
      phone.answerToRequest = PushPermission.granted;
      await tester.tap(find.byKey(const ValueKey<String>('settings-push')));
      await settle(tester);
      expect(find.text(en.pushSettingsOn), findsOneWidget);
      expect(find.text(en.pushSettingsBlocked), findsNothing);
    });

    testWidgets('has no row at all on a build with no Firebase settings', (tester) async {
      final TestApp app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.startPush();
      await pumpShellApp(tester, app, location: Routes.settings);

      expect(find.byKey(const ValueKey<String>('settings-push')), findsNothing);
      expect(find.text(en.pushSettingsTitle), findsNothing);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('fits 360dp in $locale when blocked, at a large text size', (tester) async {
        final TestApp app = await TestApp.create(pushMessaging: FakePushMessaging(permissionNow: PushPermission.blocked));
        addTearDown(app.dispose);
        await app.services.startPush();
        await pumpShellApp(tester, app, location: Routes.settings, locale: locale, textScale: 1.3);

        expect(find.byKey(const ValueKey<String>('settings-push')), findsOneWidget);
        expect(tester.takeException(), isNull);
      });

      testWidgets('fits 360dp in $locale when on and when off', (tester) async {
        for (final PushPermission permission in <PushPermission>[PushPermission.granted, PushPermission.notAsked]) {
          final TestApp app = await TestApp.create(pushMessaging: FakePushMessaging(permissionNow: permission));
          addTearDown(app.dispose);
          await app.services.startPush();
          await app.services.push.declineForNow();
          await pumpShellApp(tester, app, location: Routes.settings, locale: locale);
          expect(find.byKey(const ValueKey<String>('settings-push')), findsOneWidget);
          expect(tester.takeException(), isNull);
        }
      });
    }
  });

  group('a tapped notification', () {
    testWidgets('an approval opens that approval for someone who can approve', (tester) async {
      final (:app, :phone) = await _open(tester, api: FakeApi()..grant('dpr.approve'));

      phone.tap(_tap(<String, String>{'type': 'approval', 'id': '7'}));
      await settle(tester);

      expect(find.byType(ApprovalDetailScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
      expect(app.services.session.current.isSignedIn, isTrue);
    });

    testWidgets('the server\'s own approval.waiting event opens the inbox', (tester) async {
      final (:app, :phone) = await _open(tester, api: FakeApi()..grant('dpr.approve'));

      phone.tap(_tap(<String, String>{'event': 'approval.waiting', 'url': 'https://example.test/approvals'}));
      await settle(tester);

      expect(find.byType(ApprovalsInboxScreen), findsOneWidget);
      expect(app.api.pullCalls, isNotEmpty);
    });

    testWidgets('someone who cannot approve lands on Home instead', (tester) async {
      final (:app, :phone) = await _open(tester);

      phone.tap(_tap(<String, String>{'type': 'approval', 'id': '7'}));
      await settle(tester);

      expect(find.byType(ApprovalDetailScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(app.services.session.current.isSignedIn, isTrue);
    });

    testWidgets('a returned report opens the report this phone holds, and a missing one its list', (tester) async {
      final (:app, :phone) = await _open(tester);
      final seeded = await seedReturned(app);
      final DprRow row = await (app.db.select(app.db.dprs)..where((t) => t.clientUuid.equals(seeded.dpr))).getSingle();

      phone.tap(_tap(<String, String>{'type': 'dpr', 'id': '${row.serverId}'}));
      await settle(tester);
      expect(find.byType(DprDetailScreen), findsOneWidget);

      phone.tap(_tap(<String, String>{'type': 'dpr', 'id': '987654'}));
      await settle(tester);
      expect(find.byType(DprDetailScreen), findsNothing);
      expect(find.byType(DprListScreen), findsOneWidget);
    });

    testWidgets('a returned measurement opens that measurement', (tester) async {
      final (:app, :phone) = await _open(tester);
      final seeded = await seedReturned(app);
      final MeasurementRow row = await (app.db.select(app.db.measurements)..where((t) => t.clientUuid.equals(seeded.measurement))).getSingle();

      phone.tap(_tap(<String, String>{'type': 'measurement', 'id': '${row.serverId}'}));
      await settle(tester);

      expect(find.byType(MeasurementDetailScreen), findsOneWidget);
    });

    testWidgets('an issue opens that issue, or the issue list when unknown', (tester) async {
      final (:app, :phone) = await _open(tester);
      final String uuid = await app.services.issues.raise(projectId: 1, title: 'Crack in slab');
      await app.sync();
      final IssueRow row = await (app.db.select(app.db.issues)..where((t) => t.clientUuid.equals(uuid))).getSingle();

      phone.tap(_tap(<String, String>{'type': 'issue', 'id': '${row.serverId}'}));
      await settle(tester);
      expect(find.byType(IssueDetailScreen), findsOneWidget);

      phone.tap(_tap(<String, String>{'event': 'issue.assigned'}));
      await settle(tester);
      expect(find.byType(IssueDetailScreen), findsNothing);
      expect(find.byType(IssueListScreen), findsOneWidget);
    });

    testWidgets('an alert opens the alerts for someone who may see them, and Home for anyone else', (tester) async {
      final (:app, :phone) = await _open(tester, api: FakeApi()..grant('alerts.view'));
      phone.tap(_tap(<String, String>{'type': 'alert'}));
      await settle(tester);
      expect(find.byType(SiteDashboardScreen), findsOneWidget);
      expect(app.services.session.current.isSignedIn, isTrue);
    });

    testWidgets('an alert for someone without the permission lands on Home', (tester) async {
      final (:app, :phone) = await _open(tester);
      phone.tap(_tap(<String, String>{'type': 'alert'}));
      await settle(tester);
      expect(find.byType(SiteDashboardScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(app.services.session.current.isSignedIn, isTrue);
    });

    testWidgets('an unknown or broken payload goes to Home and never crashes', (tester) async {
      final (:app, :phone) = await _open(tester, api: FakeApi()..grant('dpr.approve'));

      for (final Map<String, String> data in <Map<String, String>>[
        const <String, String>{},
        <String, String>{'type': 'rocket', 'id': '1'},
        <String, String>{'type': ''},
        <String, String>{'id': '7'},
        <String, String>{'type': 'issue', 'id': 'not a number'},
        <String, String>{'event': '....', 'url': '::::not a url'},
        <String, String>{'type': 'dpr', 'id': '99999999999999999999999'},
        <String, String>{'type': 'project_switch', 'project_id': '-1'},
      ]) {
        phone.tap(PushMessage(data: data));
        await settle(tester);
        expect(tester.takeException(), isNull, reason: '$data');
        expect(find.byType(HomeScreen).evaluate().isNotEmpty || find.byType(IssueListScreen).evaluate().isNotEmpty || find.byType(DprListScreen).evaluate().isNotEmpty, isTrue, reason: '$data');
      }
      expect(app.services.session.current.isSignedIn, isTrue);
    });

    testWidgets('the tap that started the app opens its screen once the app is ready', (tester) async {
      final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.granted)
        ..launch = _tap(<String, String>{'type': 'approval', 'id': '7'});
      await _open(tester, phone: phone, api: FakeApi()..grant('dpr.approve'));
      await settle(tester);

      expect(find.byType(ApprovalDetailScreen), findsOneWidget);
    });

    testWidgets('a tap with nobody signed in is not a way past the sign-in screen', (tester) async {
      final FakePushMessaging phone = FakePushMessaging(permissionNow: PushPermission.granted)
        ..launch = _tap(<String, String>{'type': 'approval', 'id': '7'});
      final TestApp app = await TestApp.create(signedIn: false, seed: false, api: FakeApi()..grant('dpr.approve'), pushMessaging: phone);
      addTearDown(app.dispose);
      app.api.seedDemo();
      await app.services.startPush();
      await pumpShellApp(tester, app);
      expect(find.byType(SignInScreen), findsOneWidget);

      // Signing in afterwards does not replay the old tap.
      await app.services.auth.signIn(email: 'engineer@demo.test', password: 'x');
      await settle(tester);
      expect(find.byType(ApprovalDetailScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });
  });

  group('a message while the app is open', () {
    testWidgets('shows as a short note and starts a sync', (tester) async {
      final (:app, :phone) = await _open(tester);
      final int pulls = app.api.pullCalls.length;

      phone.deliver(const PushMessage(title: 'Approval needed: Slab 4', body: 'Sent by Sachin'));
      await settle(tester);

      expect(find.text('Approval needed: Slab 4 · Sent by Sachin'), findsOneWidget);
      expect(app.api.pullCalls.length, greaterThan(pulls));
      await tester.pump(const Duration(seconds: 6));
      await tester.pump(const Duration(seconds: 1));
      expect(find.text('Approval needed: Slab 4 · Sent by Sachin'), findsNothing);
    });

    testWidgets('a message with nothing to show changes nothing', (tester) async {
      final (:app, :phone) = await _open(tester);

      phone.deliver(const PushMessage(data: <String, String>{'type': 'issue'}));
      await settle(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
