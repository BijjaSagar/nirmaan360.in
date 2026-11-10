import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/offline/offline_plan.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/offline_plan/data/offline_upload.dart';
import 'package:nirmaan360/features/offline_plan/view/offline_upload_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const _owner = <String>['project.manage', 'boq.manage', 'worker.manage', 'petty_cash.manage', 'money.view'];

Future<TestApp> _localApp({AttachmentStore? attachments}) async {
  final api = FakeApi()..offlineCompany = true;
  for (final permission in _owner) {
    api.grant(permission);
  }
  final app = await TestApp.create(seed: false, signedIn: false, api: api, attachments: attachments);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  return app;
}

/// One of everything, made on the phone.
Future<({ProjectRow project, BoqItemRow line, WorkerRow worker, WalletRow wallet})> _build(TestApp app) async {
  final s = app.services;
  final ProjectRow project = await s.projects.createProject(name: 'Kalyani', contractValue: Money.parse('900000'));
  await s.projects.selectProject(project.id);
  final BoqItemRow line = await s.projects.addBoqItem(
    projectId: project.id,
    description: 'Concrete',
    unit: 'cum',
    qty: Qty.parse('100'),
    rate: Money.parse('5000'),
  );
  final WorkerRow worker = await s.attendance.addWorker(name: 'Ramesh', trade: 'Mason', dailyWage: Money.parse('800'));
  final WalletRow wallet = await s.pettyCash.createWallet(projectId: project.id, name: 'Site cash');
  await s.attendance.mark(projectId: project.id, workerId: worker.id, date: '2026-11-08', status: 'present');
  await s.measurements.create(
    MeasurementInput(projectId: project.id, boqItemId: line.id, measuredOn: '2026-11-08', nos: Qty.fromInt(1), length: Qty.parse('10')),
  );
  await s.pettyCash.addTopUp(walletId: wallet.id, amount: Money.parse('1000'), entryDate: '2026-11-08');
  await s.dpr.saveDraft(
    DprInput(
      projectId: project.id,
      reportDate: '2026-11-08',
      workItems: <WorkLine>[WorkLine(boqItemId: line.id, qty: Qty.parse('2'))],
    ),
  );
  return (project: project, line: line, worker: worker, wallet: wallet);
}

/// The plan changes while the phone holds work: the server stops saying offline.
Future<void> _planChanges(TestApp app) async {
  app.api.offlineCompany = false;
  await app.services.offlinePlan.check(force: true);
}

Future<void> _pending(TestApp app) async {
  final rows = await app.services.context.outbox.unfinished();
  expect(rows.every((r) => r.status == OutboxStatus.pending), isTrue);
}

void main() {
  setUpAll(loadRealFonts);

  group('asking first', () {
    test('nothing is uploaded until the owner says yes', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);

      await _planChanges(app);
      await app.services.sync.syncNow();
      app.clock.advance(const Duration(hours: 3));
      await app.services.offlinePlan.check(force: true);
      await app.services.sync.syncNow();

      expect(app.services.offlinePlan.state?.mode, OfflineMode.asking);
      expect(app.api.setupStatuses, isEmpty, reason: 'no project, line, worker or wallet was created');
      expect(app.api.pushCalls, isEmpty);
      expect(app.api.pullCalls, isEmpty);
      expect(app.api.uploadCalls, isEmpty);
      await _pending(app);
    });

    test('a no is remembered, work carries on, and it is not asked again', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final built = await _build(app);
      await _planChanges(app);

      await app.services.offlinePlan.decline();
      app.clock.advance(const Duration(hours: 3));
      await app.services.offlinePlan.check(force: true);
      await app.services.sync.syncNow();

      expect(app.services.offlinePlan.state?.mode, OfflineMode.declined);
      expect(app.api.setupStatuses, isEmpty);
      expect(app.api.pushCalls, isEmpty);

      await app.services.pettyCash.addTopUp(walletId: built.wallet.id, amount: Money.parse('50'), entryDate: '2026-11-08');
      await _pending(app);
    });

    test('the plan going back to offline stops the question', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);

      app.api.offlineCompany = true;
      await app.services.offlinePlan.check(force: true);

      expect(app.services.offlinePlan.state?.mode, OfflineMode.local);
    });
  });

  group('the upload', () {
    test('creates everything once, moves every id, and sends the queue', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);

      final OfflineUploader uploader = app.services.offlineUploader;
      final UploadReport report = await uploader.run();
      expect(report.isReady, isTrue);
      await uploader.finish(app.services.sync);

      expect(app.services.offlinePlan.isLocalOnly, isFalse);
      expect(await app.db.readMeta(MetaKey.offlinePlan), isNull);

      for (final (String table, int count) in <(String, int)>[('projects', 0), ('boq_items', 0), ('workers', 0), ('wallets', 0)]) {
        final rows = await app.db.customSelect('SELECT COUNT(*) AS c FROM $table WHERE id < 0').getSingle();
        expect(rows.read<int>('c'), count, reason: table);
      }
      expect(app.api.projects.single['name'], 'Kalyani');
      expect(app.api.projects.single['contract_value'], '900000.00');
      expect(await app.services.context.outbox.unsentCount(), 0, reason: 'everything queued went up');
      expect(app.api.measurements, hasLength(1));
      expect(app.api.attendance, hasLength(1));
      expect(app.api.pettyEntries, hasLength(1));
      expect(app.api.dprs, hasLength(1));

      // Nothing that reached the server still carries a local id.
      final pushed = app.api.pushCalls.expand((batch) => batch).toList();
      expect(pushed, isNotEmpty);
      expect(pushed.every((item) => item.projectId > 0), isTrue);
      void noLocalIds(Object? value, String path) {
        if (value is Map) {
          value.forEach((k, v) => noLocalIds(v, '$path.$k'));
        } else if (value is List) {
          for (final v in value) {
            noLocalIds(v, path);
          }
        } else if (value is num) {
          expect(value, greaterThanOrEqualTo(0), reason: path);
        }
      }

      for (final item in pushed) {
        noLocalIds(item.data, item.type);
      }
      final boq = pushed.firstWhere((item) => item.type == 'measurement').data['boq_item_id'];
      expect(boq, app.api.boqItems.single['id']);
      expect(pushed.firstWhere((item) => item.type == 'attendance').data['worker_id'], app.api.workers.single['id']);
      expect(pushed.firstWhere((item) => item.type == 'petty_cash').data['wallet_id'], app.api.wallets.single['id']);
      final work = pushed.firstWhere((item) => item.type == 'dpr').data['work'] as List;
      expect((work.single as Map)['boq_item_id'], app.api.boqItems.single['id']);

      expect(await app.services.projects.readSelectedProjectId(), greaterThan(0));
      final locals = await app.db.select(app.db.keyValues).get();
      expect(locals.where((r) => r.key.startsWith('offline.record.')), isEmpty);
    });

    test('a lost reply creates nothing twice', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      final OfflineUploader uploader = app.services.offlineUploader;

      app.api.loseNextSetupResponse();
      final UploadReport first = await uploader.run();
      expect(first.stop, UploadStop.noNetwork);
      expect(app.services.offlinePlan.state?.mode, OfflineMode.uploading);
      expect(app.api.projects, hasLength(1));

      final UploadReport second = await uploader.run();
      expect(second.isReady, isTrue);
      expect(app.api.projects, hasLength(1));
      expect(app.api.setupStatuses.where((s) => s == 200), hasLength(1), reason: 'the retry was recognised');
    });

    test('an upload stopped part way carries on from where it was', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await app.services.projects.createProject(name: 'Second');
      app.api.planProjectLimit = 1;
      await _planChanges(app);
      final OfflineUploader uploader = app.services.offlineUploader;

      final UploadReport first = await uploader.run();
      expect(first.stop, UploadStop.planLimit);
      expect(app.api.projects, hasLength(1));
      expect(await app.services.offlinePlan.work().then((w) => w.projects), 1, reason: 'the other project is still here');
      await _pending(app);

      app.api.planProjectLimit = null;
      final UploadReport second = await uploader.run();
      expect(second.isReady, isTrue);
      await uploader.finish(app.services.sync);
      expect(app.api.projects, hasLength(2));
      expect(app.api.measurements, hasLength(1));
    });

    test('a restart in the middle resumes with the same ids', () async {
      final store = DirectoryAttachmentStore(() async => Directory.systemTemp.path);
      final app = await _localApp(attachments: store);
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      await app.services.offlineUploader.run();
      expect(app.services.offlinePlan.state?.mode, OfflineMode.uploading);

      // A fresh start reads the same stored state.
      await app.services.offlinePlan.load();
      expect(app.services.offlinePlan.state?.mode, OfflineMode.uploading);
      expect(await app.services.offlineUploader.run().then((r) => r.isReady), isTrue);
      expect(app.api.projects, hasLength(1));
    });

    test('the server saying offline again stops it safely', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      app.api.offlineCompany = true;

      final UploadReport report = await app.services.offlineUploader.run();

      expect(report.stop, UploadStop.stillOffline);
      expect(app.services.offlinePlan.state?.mode, OfflineMode.local);
      expect(app.api.setupStatuses, isEmpty);
    });

    test('the sign-in having gone says so, and loses nothing', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      app.api.unauthorized = true;

      final UploadReport report = await app.services.offlineUploader.run();

      expect(report.stop, UploadStop.signIn);
      await _pending(app);
    });

    test('-3 is never mistaken for -31', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final s = app.services;
      final p = await s.projects.createProject(name: 'A');
      await s.projects.selectProject(p.id);
      BoqItemRow? third;
      BoqItemRow? thirtyFirst;
      for (var i = 1; i <= 31; i++) {
        final line = await s.projects.addBoqItem(projectId: p.id, description: 'L$i', unit: 'cum', qty: Qty.parse('1'), rate: Money.parse('1'));
        if (i == 3) third = line;
        if (i == 31) thirtyFirst = line;
      }
      await s.measurements.create(MeasurementInput(projectId: p.id, boqItemId: third!.id, measuredOn: '2026-11-08', nos: Qty.fromInt(1), length: Qty.parse('1')));
      await s.measurements.create(MeasurementInput(projectId: p.id, boqItemId: thirtyFirst!.id, measuredOn: '2026-11-08', nos: Qty.fromInt(1), length: Qty.parse('1')));
      await _planChanges(app);

      expect((await s.offlineUploader.run()).isReady, isTrue);

      final byBoq = <int?>{for (final m in await app.db.select(app.db.measurements).get()) m.boqItemId};
      expect(byBoq, hasLength(2));
      final rows = await s.context.outbox.unfinished();
      for (final row in rows) {
        final payload = jsonDecode(row.payload) as Map<String, dynamic>;
        if (payload.containsKey('boq_item_id')) expect(payload['boq_item_id'], greaterThan(0));
      }
    });
  });

  group('on screen', () {
    final en = lookupAppLocalizations(const Locale('en'));

    testWidgets('the gate holds the app at the question', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);

      await pumpShellApp(tester, app);

      expect(find.byType(OfflineUploadScreen), findsOneWidget);
      expect(find.text(en.offlineUploadAskTitle), findsOneWidget);
    });

    testWidgets('saying no goes home, and stays there', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      await pumpShellApp(tester, app, size: const Size(360, 1200));

      await tester.tap(find.byKey(const ValueKey<String>('offline-upload-no')));
      await settle(tester);

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(app.services.offlinePlan.state?.mode, OfflineMode.declined);
      expect(app.api.setupStatuses, isEmpty);

      GoRouter.of(tester.element(find.byType(HomeScreen))).go(Routes.home);
      await settle(tester);
      expect(find.byType(OfflineUploadScreen), findsNothing);
    });

    testWidgets('a declined upload can still be chosen later', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      await app.services.offlinePlan.decline();

      await pumpShellApp(tester, app, location: Routes.offlinePlan);

      expect(find.byKey(const ValueKey<String>('offline-upload-later')), findsOneWidget);
    });

    testWidgets('an offer only appears once the server stops saying offline', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);

      await pumpShellApp(tester, app, location: Routes.offlineUpload);

      expect(find.byType(OfflineUploadScreen), findsNothing);
    });

    for (final locale in <String>['mr', 'hi']) {
      testWidgets('the question fits 360dp in $locale', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _build(app);
        await _planChanges(app);

        await pumpShellApp(tester, app, locale: locale);

        expect(find.byType(OfflineUploadScreen), findsOneWidget);
        expect(tester.takeException(), isNull);
      });

      testWidgets('the resume screen fits 360dp in $locale', (tester) async {
        final app = await _localApp();
        addTearDown(app.dispose);
        await _build(app);
        await _planChanges(app);
        await app.services.offlinePlan.beginUpload();

        await pumpShellApp(tester, app, locale: locale);

        expect(find.text(lookupAppLocalizations(Locale(locale)).offlineUploadInterruptedTitle), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('accepting runs the upload and the app goes back to normal', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      await _planChanges(app);
      await pumpShellApp(tester, app, size: const Size(360, 1200));

      await tester.tap(find.byKey(const ValueKey<String>('offline-upload-yes')));
      await settle(tester, extra: const Duration(seconds: 2));
      await settle(tester, extra: const Duration(seconds: 2));

      expect(app.services.offlinePlan.isLocalOnly, isFalse);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(app.api.measurements, hasLength(1));
      await tester.pump(const Duration(seconds: 10));
    });

    testWidgets('a stop says so and keeps the buttons', (tester) async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await _build(app);
      app.api.failNextOfflineStatus = const OfflineFailure();
      await _planChanges(app);
      await app.services.offlinePlan.check(force: true);
      await pumpShellApp(tester, app, size: const Size(360, 1200));
      app.api.offline = true;

      await tester.tap(find.byKey(const ValueKey<String>('offline-upload-yes')));
      await settle(tester);

      expect(find.text(en.offlineUploadNoNetwork), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('offline-upload-yes')), findsOneWidget);
    });
  });
}
