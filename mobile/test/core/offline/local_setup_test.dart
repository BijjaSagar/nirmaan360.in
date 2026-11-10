import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/offline/offline_plan.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/data/project_repository.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/project_form_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

Future<TestApp> _localApp({bool network = true}) async {
  final api = FakeApi()..offlineCompany = true;
  for (final permission in <String>['project.manage', 'boq.manage', 'worker.manage', 'petty_cash.manage']) {
    api.grant(permission);
  }
  final app = await TestApp.create(seed: false, signedIn: false, api: api);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  app.connectivity.setOnline(network);
  return app;
}

void main() {
  setUpAll(loadRealFonts);
  final en = lookupAppLocalizations(const Locale('en'));

  group('a whole site set up with no server', () {
    test('project, BOQ, worker, wallet, and a day of entries', () async {
      final app = await _localApp(network: false);
      addTearDown(app.dispose);
      final services = app.services;

      final ProjectRow project = await services.projects.createProject(name: 'Kalyani', location: 'Pune');
      expect(project.id, lessThan(0));
      expect(project.code, 'L1');
      await services.projects.selectProject(project.id);

      final BoqItemRow line = await services.projects.addBoqItem(
        projectId: project.id,
        description: 'Concrete',
        unit: 'cum',
        qty: Qty.parse('100'),
        rate: Money.parse('5000'),
      );
      expect(line.id, lessThan(0));
      expect(line.code, '1');
      expect(line.balanceQty, Qty.parse('100'));

      final WorkerRow worker = await services.attendance.addWorker(name: 'Ramesh', trade: 'Mason');
      expect(worker.id, lessThan(0));

      final wallet = await services.pettyCash.createWallet(projectId: project.id, name: 'Site cash');
      expect(wallet.id, lessThan(0));

      await services.pettyCash.addTopUp(walletId: wallet.id, amount: Money.parse('1000'), entryDate: '2026-11-08');
      await services.pettyCash.addExpense(
        walletId: wallet.id,
        amount: Money.parse('200'),
        entryDate: '2026-11-08',
        category: 'Food',
      );
      await services.attendance.mark(
        projectId: project.id,
        workerId: worker.id,
        date: '2026-11-08',
        status: 'present',
      );
      final String uuid = await services.measurements.create(
        MeasurementInput(
          projectId: project.id,
          boqItemId: line.id,
          measuredOn: '2026-11-08',
          nos: Qty.fromInt(1),
          length: Qty.parse('10'),
        ),
      );

      final lines = await services.projects.watchBoqItems(project.id).first;
      expect(lines.single.previewBalance, Qty.parse('90'));
      final wallets = await services.pettyCash.watchWallets(project.id).first;
      expect(wallets.single.previewBalance, Money.parse('800'));

      final rows = await services.context.outbox.unfinished();
      final Map<OutboxType, Map<String, dynamic>> byType = <OutboxType, Map<String, dynamic>>{
        for (final row in rows) row.type: (jsonDecode(row.payload) as Map).cast<String, dynamic>(),
      };
      expect(byType[OutboxType.measurement]?['boq_item_id'], line.id);
      expect(byType[OutboxType.attendance]?['worker_id'], worker.id);
      expect(byType[OutboxType.pettyCash]?['wallet_id'], wallet.id);
      expect(rows.where((r) => r.recordUuid == uuid), isNotEmpty);
      expect(rows.every((r) => r.status == OutboxStatus.pending), isTrue);

      expect(app.api.refusedCalls, 0);
      expect(app.api.pullCalls, isEmpty);
    });

    test('ids keep going down and never clash with a server id', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final a = await app.services.projects.createProject(name: 'A');
      final b = await app.services.projects.createProject(name: 'B');
      expect(<int>[a.id, b.id], <int>[-1, -2]);
    });

    test('every local record has its own upload id and keeps what has no column', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final p = await app.services.projects.createProject(name: 'A', contractValue: Money.parse('5000000'));
      final w = await app.services.attendance.addWorker(name: 'R', trade: 'Mason', dailyWage: Money.parse('800'));

      final project = jsonDecode((await app.db.readMeta(MetaKey.offlineRecord(LocalRecordKind.project, p.id)))!) as Map;
      final worker = jsonDecode((await app.db.readMeta(MetaKey.offlineRecord(LocalRecordKind.worker, w.id)))!) as Map;
      expect(project['uuid'], isNotEmpty);
      expect(project['uuid'], isNot(worker['uuid']));
      expect(project['contract_value'], '5000000.00');
      expect(worker['daily_wage'], '800.00');
    });

    test('a BOQ code typed by hand is kept, and the next number skips past it', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final p = await app.services.projects.createProject(name: 'A');
      Future<BoqItemRow> add(String? code) => app.services.projects.addBoqItem(
        projectId: p.id,
        description: 'Work',
        unit: 'cum',
        qty: Qty.parse('1'),
        rate: Money.parse('1'),
        code: code,
      );
      expect((await add('2.1')).code, '2.1');
      expect((await add('7')).code, '7');
      expect((await add(null)).code, '8');
    });

    test('forms get their lists with no first pull', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final Lookups lookups = await app.services.projects.watchLookups().first;
      expect(lookups.units, contains('cum'));
      expect(lookups.trades, contains('Mason'));
      expect(lookups.expenseCategories, contains('Food'));
      expect(lookups.attendanceStatuses, contains('present'));
    });

    test('anything the server would refuse is refused here too', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      await expectLater(
        app.services.projects.createProject(name: 'x' * 161),
        throwsA(isA<ValidationFailure>().having((e) => e.errors.keys, 'fields', contains('name'))),
      );
      await expectLater(
        app.services.projects.createProject(name: 'A', startDate: '2026-11-08', endDate: '2026-11-01'),
        throwsA(isA<ValidationFailure>().having((e) => e.errors.keys, 'fields', contains('end_date'))),
      );
      await expectLater(
        app.services.attendance.addWorker(name: 'y' * 121, trade: 'Mason'),
        throwsA(isA<ValidationFailure>()),
      );
      expect(await app.db.select(app.db.projects).get(), isEmpty);
    });
  });

  group('a normal company', () {
    test('still creates on the server', () async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      app.api.grant('project.manage');

      final ProjectRow project = await app.services.projects.createProject(name: 'Online');

      expect(project.id, greaterThan(0));
      expect(await app.db.readMeta(MetaKey.offlineRecord(LocalRecordKind.project, project.id)), isNull);
    });

    test('still cannot add a worker with no network', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);
      await expectLater(
        app.services.attendance.addWorker(name: 'R', trade: 'Mason'),
        throwsA(isA<OfflineFailure>()),
      );
    });
  });

  group('the setup screens', () {
    for (final locale in <String>['mr', 'hi']) {
      testWidgets('the project form fits and says nothing about network in $locale', (tester) async {
        final app = await _localApp(network: false);
        addTearDown(app.dispose);
        await pumpShellApp(tester, app, location: Routes.projectForm, locale: locale);

        expect(find.byType(ProjectFormScreen), findsOneWidget);
        expect(find.text(lookupAppLocalizations(Locale(locale)).shellProjectOffline), findsNothing);
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('the BOQ form can save with no network', (tester) async {
      final app = await _localApp(network: false);
      addTearDown(app.dispose);
      final p = await app.services.projects.createProject(name: 'A');
      await app.services.projects.selectProject(p.id);

      await pumpShellApp(tester, app, location: Routes.boqForm);

      expect(find.byType(BoqFormScreen), findsOneWidget);
      expect(find.text(en.boqOfflineTitle), findsNothing);
    });
  });

  group('a local project with its detail', () {
    test('its wage and value are still there after a restart', () async {
      final app = await _localApp();
      addTearDown(app.dispose);
      final w = await app.services.attendance.addWorker(name: 'R', trade: 'Mason', dailyWage: Money.parse('800'));
      await app.services.offlinePlan.load();
      expect(await app.db.readMeta(MetaKey.offlineRecord(LocalRecordKind.worker, w.id)), contains('800'));
      expect(app.services.offlinePlan.state?.mode, OfflineMode.local);
    });
  });
}
