import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_list_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_list_screen.dart';
import 'package:nirmaan360/features/offline_plan/view/licence_ended_screen.dart';
import 'package:nirmaan360/features/offline_plan/view/offline_plan_screen.dart';
import 'package:nirmaan360/features/offline_plan/view/widgets/licence_card.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ValueKey<String> _notice = ValueKey<String>('licence-notice');
const ValueKey<String> _tryNow = ValueKey<String>('licence-try-now');

/// A phone on the offline plan with a project on it.
Future<TestApp> _localApp() async {
  final api = FakeApi()..offlineCompany = true;
  final app = await TestApp.create(seed: false, signedIn: false, api: api);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  await app.db.into(app.db.projects).insert(ProjectsCompanion.insert(id: const Value(-1), code: 'L1', name: 'Local site'));
  await app.services.projects.selectProject(-1);
  return app;
}

/// Puts the phone [left] before the end of its licence (negative: after it).
Future<void> _leave(TestApp app, Duration left) async {
  final DateTime end = app.services.offlinePlan.state!.expiresAt!;
  app.clock.set(end.subtract(left));
  await app.services.offlinePlan.tick();
}

/// The first frames of a route, however it was reached.
Future<void> _go(WidgetTester tester, String path) async {
  GoRouter.of(tester.element(find.byType(Scaffold).first)).go(path);
  await settle(tester);
}

void main() {
  setUpAll(loadRealFonts);
  final en = lookupAppLocalizations(const Locale('en'));

  group('Home', () {
    testWidgets('says nothing until the last week', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, const Duration(days: 8));

      await pumpShellApp(tester, app);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.byKey(_notice), findsNothing);
    });

    testWidgets('in the last week, calmly says the date and what to do', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, const Duration(days: 6));
      final String date = licenceDate(app.services.offlinePlan.state!.expiresAt!, 'en');

      await pumpShellApp(tester, app);

      expect(find.byKey(_notice), findsOneWidget);
      expect(find.text(en.offlineNoticeEnding(date)), findsOneWidget);
      expect(find.text(en.offlineNoticeEnded(date)), findsNothing);
    });

    testWidgets('after the end, says nothing can be added, and a tap opens the renewal page', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      final String date = licenceDate(app.services.offlinePlan.state!.expiresAt!, 'en');

      await pumpShellApp(tester, app);

      expect(find.text(en.offlineNoticeEnded(date)), findsOneWidget);
      await tester.tap(find.byKey(_notice));
      await settle(tester);

      expect(find.byType(LicenceEndedScreen), findsOneWidget);
      expect(find.text(en.offlineLicenceEndedTitle), findsOneWidget);
      expect(find.text(en.licenceTryNow), findsOneWidget);
    });

    testWidgets('the notice turns up the moment the time passes, with the app open', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, const Duration(days: 8));
      await pumpShellApp(tester, app);
      expect(find.byKey(_notice), findsNothing);

      await _leave(app, const Duration(days: 3));
      await settle(tester);
      expect(find.text(en.offlineNoticeEnding(licenceDate(app.services.offlinePlan.state!.expiresAt!, 'en'))), findsOneWidget);

      await _leave(app, -const Duration(minutes: 1));
      await settle(tester);
      expect(app.services.offlinePlan.licence.entriesBlocked, isTrue);
      expect(find.text(en.offlineNoticeEnded(licenceDate(app.services.offlinePlan.state!.expiresAt!, 'en'))), findsOneWidget);
    });

    testWidgets('a company on a normal plan sees nothing of it, and cannot reach the renewal page', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.projects.selectProject(1);
      app.clock.advance(const Duration(days: 4000));

      await pumpShellApp(tester, app);

      expect(find.byKey(_notice), findsNothing);
      await _go(tester, Routes.licenceEnded);
      expect(find.byType(LicenceEndedScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });
  });

  group('after the end', () {
    const forms = <String>[
      Routes.dprForm,
      Routes.measurementForm,
      Routes.issueForm,
      Routes.expenseForm,
      Routes.walletForm,
      Routes.topUp,
      Routes.projectForm,
      Routes.boqForm,
    ];

    for (final path in forms) {
      testWidgets('$path leads to the renewal page', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _leave(app, -const Duration(days: 1));
        await pumpShellApp(tester, app);

        await _go(tester, path);

        expect(find.byType(LicenceEndedScreen), findsOneWidget, reason: path);
      });
    }

    testWidgets('every entry form on the list leads there, not just these', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      await pumpShellApp(tester, app);
      final AppGate gate = tester.element(find.byType(HomeScreen)).read<AppGate>();

      for (final path in Routes.entryForms) {
        expect(gate.redirect(Uri.parse(path)), Routes.licenceEnded, reason: path);
      }
      for (final path in <String>[Routes.dprList, Routes.measurements, Routes.issues, Routes.pettyCash, Routes.attendance, Routes.home]) {
        expect(Routes.isEntryForm(path), isFalse, reason: '$path only reads');
      }
    });

    testWidgets('the pages that only read stay open', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      await pumpShellApp(tester, app);

      for (final (String path, Type screen) in <(String, Type)>[
        (Routes.dprList, DprListScreen),
        (Routes.measurements, MeasurementListScreen),
        (Routes.issues, IssueListScreen),
        (Routes.pettyCash, PettyCashScreen),
        (Routes.attendance, AttendanceScreen),
        (Routes.offlinePlan, OfflinePlanScreen),
      ]) {
        await _go(tester, path);
        expect(find.byType(screen), findsOneWidget, reason: path);
        expect(find.byType(LicenceEndedScreen), findsNothing, reason: path);
      }
    });

    testWidgets('the plan page leads to the renewal page and still holds the export', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      await pumpShellApp(tester, app, location: Routes.offlinePlan);

      expect(find.text(en.offlineExportButton), findsOneWidget);
      await tester.tap(find.byKey(_notice));
      await settle(tester);

      expect(find.byType(LicenceEndedScreen), findsOneWidget);
      expect(find.text(en.offlineExportButton), findsOneWidget, reason: 'the copy of the data is offered here too');
    });

    testWidgets('a write that is refused opens the renewal page, wherever it came from', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);
      expect(find.byType(HomeScreen), findsOneWidget);

      // The licence runs out while the app is open and nothing has told the screens yet.
      app.clock.set(app.services.offlinePlan.state!.expiresAt!.add(const Duration(hours: 1)));
      await expectLater(app.services.issues.raise(projectId: -1, title: 'No'), throwsA(isA<LicenceEnded>()));
      await settle(tester);

      expect(find.byType(LicenceEndedScreen), findsOneWidget);
      expect(await app.db.select(app.db.issues).get(), isEmpty);
    });

    testWidgets('trying with no network says so', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      await pumpShellApp(tester, app, location: Routes.licenceEnded);
      app.connectivity.setOnline(false);
      final int calls = app.api.refreshCalls;

      await tester.tap(find.byKey(_tryNow));
      await settle(tester);

      expect(find.text(en.licenceResultOffline), findsOneWidget);
      expect(app.api.refreshCalls, calls);
      expect(app.services.offlinePlan.licence.entriesBlocked, isTrue);
    });

    testWidgets('trying while online renews it, and the person carries on, with no restart', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      app.api.advanceServerTime(const Duration(days: 100));
      await pumpShellApp(tester, app);
      await tester.tap(find.byKey(_notice));
      await settle(tester);
      expect(find.byKey(_tryNow), findsOneWidget);

      await tester.tap(find.byKey(_tryNow));
      await settle(tester);

      expect(find.byKey(const ValueKey<String>('licence-renewed-card')), findsOneWidget);
      expect(find.text(en.licenceRenewedTitle), findsOneWidget);
      expect(app.services.offlinePlan.licence.entriesBlocked, isFalse);

      await tester.tap(find.byKey(const ValueKey<String>('licence-continue')));
      await settle(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.byKey(_notice), findsNothing);
      await _go(tester, Routes.issueForm);
      expect(find.byType(LicenceEndedScreen), findsNothing, reason: 'the form opens again');
      await app.services.issues.raise(projectId: -1, title: 'Back at work');
    });

    testWidgets('a licence that is still over after trying says so', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      app.api.licenceSeconds = -60;
      await pumpShellApp(tester, app, location: Routes.licenceEnded);

      await tester.tap(find.byKey(_tryNow));
      await settle(tester);

      expect(find.text(en.licenceResultStillEnded), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('licence-ended-card')), findsOneWidget);
    });

    testWidgets('a phone that was freed is told, with the link and the button', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      app.api.refreshFailure = const ServerFailure(
        'Freed.',
        statusCode: 410,
        code: 'licence_revoked',
        data: <String, dynamic>{'manage_url': 'https://example.test/offline/devices'},
      );
      await pumpShellApp(tester, app, location: Routes.licenceEnded);

      await tester.tap(find.byKey(_tryNow));
      await settle(tester);

      expect(find.text(en.offlineRevokedTitle), findsOneWidget);
      expect(find.text(en.offlineActivateAgain), findsOneWidget);
    });

    testWidgets('look at my data goes Home, where everything can be read', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _leave(app, -const Duration(days: 1));
      await pumpShellApp(tester, app, location: Routes.licenceEnded);

      await tester.ensureVisible(find.byKey(const ValueKey<String>('licence-look-at-data')));
      await tester.tap(find.byKey(const ValueKey<String>('licence-look-at-data')));
      await settle(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
    });
  });

  group('at 360dp in Marathi and Hindi, nothing overflows', () {
    for (final locale in <String>['mr', 'hi']) {
      testWidgets('the renewal page ($locale)', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _leave(app, -const Duration(days: 1));
        await pumpShellApp(tester, app, location: Routes.licenceEnded, locale: locale);
        expect(find.byType(LicenceEndedScreen), findsOneWidget);
        expect(tester.takeException(), isNull);

        for (final (String name, void Function() arrange) in <(String, void Function())>[
          ('offline', () => app.connectivity.setOnline(false)),
          ('still ended', () {
            app.connectivity.setOnline(true);
            app.api.licenceSeconds = -60;
          }),
        ]) {
          arrange();
          await tester.tap(find.byKey(_tryNow));
          await settle(tester);
          expect(tester.takeException(), isNull, reason: name);
        }
      });

      testWidgets('the renewal page when freed or when sign-in is needed ($locale)', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _leave(app, -const Duration(days: 1));
        app.api.refreshFailure = const ServerFailure(
          'Freed.',
          statusCode: 410,
          code: 'licence_revoked',
          data: <String, dynamic>{'manage_url': 'https://example.test/offline/devices'},
        );
        await pumpShellApp(tester, app, location: Routes.licenceEnded, locale: locale);
        await tester.tap(find.byKey(_tryNow));
        await settle(tester);
        expect(tester.takeException(), isNull);

        app.api.refreshFailure = null;
        app.api.unauthorized = true;
        await app.services.offlinePlan.activateAgain();
        await app.services.offlinePlan.check(force: true);
        await settle(tester);
        expect(tester.takeException(), isNull);
      });

      testWidgets('the renewal page once renewed ($locale)', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _leave(app, -const Duration(days: 1));
        app.api.advanceServerTime(const Duration(days: 100));
        await pumpShellApp(tester, app, location: Routes.licenceEnded, locale: locale);
        await tester.tap(find.byKey(_tryNow));
        await settle(tester);
        expect(find.byKey(const ValueKey<String>('licence-renewed-card')), findsOneWidget);
        expect(tester.takeException(), isNull);
      });

      testWidgets('Home with the warning and with the end ($locale)', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _leave(app, const Duration(days: 5));
        await pumpShellApp(tester, app, locale: locale);
        expect(find.byKey(_notice), findsOneWidget);
        expect(tester.takeException(), isNull);

        await _leave(app, -const Duration(days: 1));
        await settle(tester);
        expect(find.byKey(_notice), findsOneWidget);
        expect(tester.takeException(), isNull);
      });

      for (final path in <String>[Routes.issueForm, Routes.dprForm, Routes.measurementForm, Routes.expenseForm, Routes.walletForm, Routes.topUp, Routes.projectForm, Routes.boqForm]) {
        testWidgets('$path carries the warning ($locale)', (tester) async {
          final app = await _localApp();
          addTearDown(app.dispose);
          await _leave(app, const Duration(days: 5));
          for (final permission in <String>['project.manage', 'boq.manage', 'petty_cash.manage']) {
            app.api.grant(permission);
          }
          await app.services.session.updateUser(CurrentUser.fromJson(app.api.user));
          await pumpShellApp(tester, app, location: path, locale: locale);

          expect(find.byKey(_notice), findsOneWidget, reason: path);
          expect(tester.takeException(), isNull, reason: path);
        });
      }

      testWidgets('the muster carries the warning ($locale)', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _leave(app, const Duration(days: 5));
        await pumpShellApp(tester, app, location: Routes.attendance, locale: locale);

        expect(find.byKey(_notice), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
