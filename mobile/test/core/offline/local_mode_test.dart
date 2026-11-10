import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/core/sync/background_sync.dart';
import 'package:nirmaan360/core/sync/failures.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/offline_plan/view/offline_plan_screen.dart';
import 'package:nirmaan360/features/petty_cash/data/petty_cash_repository.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

/// A phone with a project on it, on the offline plan.
Future<TestApp> _localApp() async {
  final api = FakeApi()..offlineCompany = true;
  final app = await TestApp.create(seed: false, signedIn: false, api: api);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  await app.db.into(app.db.projects).insert(ProjectsCompanion.insert(id: const Value(-1), code: 'L1', name: 'Local site'));
  await app.services.projects.selectProject(-1);
  return app;
}

void main() {
  setUpAll(loadRealFonts);
  final en = lookupAppLocalizations(const Locale('en'));

  group('sync is off', () {
    test('the first sign-in makes no pull', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      expect(app.api.pullCalls, isEmpty);
      expect(app.api.pushCalls, isEmpty);
      expect(app.api.refusedCalls, 0);
    });

    test('a sync does nothing and queued work stays queued', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final uuid = await app.services.issues.raise(projectId: -1, title: 'Crack');
      final before = await app.services.context.outbox.unsentCount();
      expect(before, greaterThan(0));

      final SyncRunResult result = await app.services.sync.syncNow();

      expect(result.isSuccess, isTrue);
      expect(app.api.refusedCalls, 0);
      expect(await app.services.context.outbox.unsentCount(), before);
      final rows = await app.services.context.outbox.unfinished();
      expect(rows.every((r) => r.status == OutboxStatus.pending), isTrue);
      expect(rows.single.recordUuid, uuid);
    });

    test('the background task stops before the network', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final outcome = await runBackgroundSyncOnce(
        db: app.db,
        api: app.api,
        attachments: app.attachments,
        connectivity: app.connectivity,
      );
      expect(outcome, isTrue);
      expect(app.api.refusedCalls, 0);
    });

    test('a company moved to the plan: the refused sync harms no queued row', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      app.api.offlineCompany = true;

      final SyncRunResult result = await app.services.sync.syncNow();

      expect(result.isSuccess, isTrue);
      expect(app.services.offlinePlan.isLocalOnly, isTrue);
      final rows = await app.services.context.outbox.unfinished();
      expect(rows, isNotEmpty);
      expect(rows.every((r) => r.status == OutboxStatus.pending), isTrue, reason: 'no row is sent to attention');
    });

    test('screens are told there is nothing to wait for', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);
      expect(app.services.connectivity.isOnline, isTrue);
    });

    test('a normal company still sees the real connection', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);
      expect(app.services.connectivity.isOnline, isFalse);
    });

    test('a saved report waits for no approver', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final String uuid = await app.services.dpr.saveDraft(
        DprInput(projectId: -1, reportDate: '2026-11-08'),
      );
      await app.services.dpr.send(uuid);

      final rows = await app.services.context.outbox.unfinished();
      expect(rows.map((r) => r.type), <OutboxType>[OutboxType.dpr]);

      // And it can still be changed.
      await app.services.dpr.saveDraft(DprInput(projectId: -1, reportDate: '2026-11-08', summary: 'Later note'), clientUuid: uuid);
    });

    test('records say nothing about waiting to be sent', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final wallet = await app.db.into(app.db.wallets).insertReturning(
        WalletsCompanion.insert(id: const Value(-1), projectId: -1, name: 'Site', balance: Money.zero),
      );
      await app.services.pettyCash.addTopUp(walletId: wallet.id, amount: Money.parse('100'), entryDate: '2026-11-08');

      final List<SyncedRecord<PettyCashEntryRow>> rows = await app.services.pettyCash.watchEntries(wallet.id).first;
      expect(rows.single.syncState, LocalSyncState.synced);
    });
  });

  group('the data is never lost', () {
    test('a 401 keeps the person working', () async {
      final app = await _localApp();
      addTearDown(app.dispose);

      await app.services.session.markExpired();

      expect(app.services.session.current.status, SessionStatus.signedIn);
    });

    test('a 401 on a normal company still ends the session', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await app.services.session.markExpired();

      expect(app.services.session.current.status, SessionStatus.expired);
    });

    test('the server dropping the sign-in is noted, not obeyed', () async {
      final app = await _localApp();
      addTearDown(app.dispose);

      app.api.unauthorized = true;
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.state?.needsSignIn, isTrue);
      expect(app.services.session.current.status, SessionStatus.signedIn);
      expect(await app.db.select(app.db.projects).get(), isNotEmpty);
    });

    test('a restart with no token stays open', () async {
      final app = await _localApp();
      addTearDown(app.dispose);

      await app.tokens.clear();
      await app.services.auth.restoreSession();
      expect(app.services.session.current.status, SessionStatus.expired);
      app.services.session.resumeLocally();

      expect(app.services.session.current.status, SessionStatus.signedIn);
    });

    test('signing out keeps every record, and the same person can return', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await app.services.issues.raise(projectId: -1, title: 'Crack');

      await app.services.auth.signOut();

      expect(app.services.session.current.status, SessionStatus.expired);
      expect(app.services.offlinePlan.state?.signedOut, isTrue);
      expect(await app.db.select(app.db.projects).get(), hasLength(1));
      expect(await app.services.context.outbox.unsentCount(), 1);

      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

      expect(app.services.offlinePlan.state?.signedOut, isFalse);
      expect(await app.db.select(app.db.projects).get(), hasLength(1));
      expect(await app.services.context.outbox.unsentCount(), 1);
    });

    test('someone else signing in is refused and nothing is wiped', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await app.services.auth.signOut();

      app.api.user = <String, dynamic>{...app.api.user, 'id': 99};
      await expectLater(
        app.services.auth.signIn(email: 'other@example.test', password: 'x'),
        throwsA(isA<DifferentUserFailure>()),
      );

      expect(await app.db.select(app.db.projects).get(), hasLength(1));
    });

    test('negative ids are stored and read like any other', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final row = await app.services.projects.getProject(-1);
      expect(row?.name, 'Local site');
    });
  });

  group('the gate', () {
    testWidgets('modules outside the plan are not reachable', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      for (final permission in <String>['measurement.approve', 'dpr.approve', 'material_control.view', 'progress.view', 'contractor.view']) {
        app.api.grant(permission);
      }
      await app.services.session.updateUser(CurrentUser.fromJson(app.api.user));
      await pumpShellApp(tester, app);
      final BuildContext context = tester.element(find.byType(HomeScreen));
      final GoRouter router = GoRouter.of(context);
      final AppGate gate = context.read<AppGate>();

      expect(gate.state.canApprove, isFalse);
      expect(find.text(en.shellTabApprovals), findsNothing);
      expect(find.text(en.shellTabOutbox), findsNothing);

      for (final path in <String>[Routes.approvals, Routes.outbox, Routes.material, Routes.progress, Routes.contractors, Routes.siteDashboard, Routes.prices]) {
        router.go(path);
        await settle(tester);
        expect(find.byType(HomeScreen), findsOneWidget, reason: path);
      }
    });

    testWidgets('home says the data is only here', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.byKey(const ValueKey<String>('home-on-this-phone')), findsOneWidget);
      expect(find.text(en.offlineOnPhoneTag), findsOneWidget);
    });

    testWidgets('a normal company sees none of it', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.projects.selectProject(1);
      await pumpShellApp(tester, app);

      expect(find.byKey(const ValueKey<String>('home-on-this-phone')), findsNothing);
      expect(find.text(en.offlineOnPhoneTag), findsNothing);
      expect(find.text(en.shellTabOutbox), findsOneWidget);

      GoRouter.of(tester.element(find.byType(HomeScreen))).go(Routes.offlinePlan);
      await settle(tester);
      expect(find.byType(OfflinePlanScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('a phone with no licence and nothing on it waits at the plan screen', (tester) async {
      final api = FakeApi()
        ..offlineCompany = true
        ..activateFailure = const ServerFailure(
          'Full.',
          statusCode: 409,
          code: 'device_limit',
          data: <String, dynamic>{'manage_url': 'https://example.test/offline/devices'},
        );
      final app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

      await pumpShellApp(tester, app);

      expect(find.byType(OfflinePlanScreen), findsOneWidget);
      expect(find.text(en.offlineDeviceLimitTitle), findsOneWidget);
      expect(find.text('https://example.test/offline/devices'), findsOneWidget);
    });

    testWidgets('data on the phone is never hidden behind a licence problem', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      app.api.activateFailure = const ServerFailure('Full.', statusCode: 409, code: 'device_limit');
      await app.services.offlinePlan.activateAgain();

      await pumpShellApp(tester, app);

      expect(find.byType(HomeScreen), findsOneWidget);
    });
  });

  group('the plan screen', () {
    Future<void> show(WidgetTester tester, TestApp app, String locale) async {
      await pumpShellApp(tester, app, location: Routes.offlinePlan, locale: locale);
      expect(find.byType(OfflinePlanScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    }

    for (final locale in <String>['mr', 'hi']) {
      testWidgets('fits 360dp in $locale when active', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await show(tester, app, locale);
      });

      testWidgets('fits 360dp in $locale when the licence has ended', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        app.clock.advance(const Duration(days: 120));
        await show(tester, app, locale);
      });

      testWidgets('fits 360dp in $locale when freed', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        app.api.refreshFailure = const ServerFailure('Freed.', statusCode: 410, code: 'licence_revoked');
        await app.services.offlinePlan.check(force: true);
        await show(tester, app, locale);
      });

      testWidgets('fits 360dp in $locale when sign-in is needed', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        app.api.unauthorized = true;
        await app.services.offlinePlan.check(force: true);
        app.api.unauthorized = false;
        await show(tester, app, locale);
      });
    }

    testWidgets('says plainly when the licence has ended, and keeps the data', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      app.clock.advance(const Duration(days: 120));
      await show(tester, app, 'en');

      expect(find.text(en.offlineLicenceEndedTitle), findsOneWidget);
      expect(find.text(en.offlineLicenceEndedBody), findsOneWidget);
      expect(find.text(en.offlineOnlyHereTitle), findsOneWidget);
      expect(await app.db.select(app.db.projects).get(), hasLength(1));
    });
  });
}

