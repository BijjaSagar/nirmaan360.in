import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/offline/offline_plan.dart';
import 'package:nirmaan360/features/attendance/data/selfie_models.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/inspections/data/inspection_models.dart';
import 'package:nirmaan360/features/machine_logs/data/machine_models.dart';
import 'package:nirmaan360/features/material_control/data/material_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/offline_plan/data/offline_upload.dart';

import '../../support/test_app.dart';

/// A phone on the offline plan with a site set up and some work done, all made
/// while the licence was good.
class _Phone {
  _Phone(this.app, this.project, this.line, this.worker, this.wallet, this.dpr, this.issue, this.measurement);

  final TestApp app;
  final ProjectRow project;
  final BoqItemRow line;
  final WorkerRow worker;
  final WalletRow wallet;
  final String dpr;
  final String issue;
  final String measurement;

  OfflinePlan get plan => app.services.offlinePlan;

  /// Moves the phone's clock and the server's together, as real time does.
  void pass(Duration by) {
    app.clock.advance(by);
    app.api.advanceServerTime(by);
  }

  /// Runs out the licence: it was given for 90 days.
  Future<void> runOut() async {
    pass(const Duration(days: 91));
    await plan.tick();
  }
}

Future<_Phone> _phone({FakeApi? api}) async {
  final fake = api ?? FakeApi();
  fake.offlineCompany = true;
  for (final permission in <String>['project.manage', 'boq.manage', 'worker.manage', 'petty_cash.manage', 'money.view']) {
    fake.grant(permission);
  }
  final app = await TestApp.create(seed: false, signedIn: false, api: fake);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  final services = app.services;
  final ProjectRow project = await services.projects.createProject(name: 'Kalyani');
  await services.projects.selectProject(project.id);
  final BoqItemRow line = await services.projects.addBoqItem(
    projectId: project.id,
    description: 'Concrete',
    unit: 'cum',
    qty: Qty.parse('100'),
    rate: Money.parse('5000'),
  );
  final WorkerRow worker = await services.attendance.addWorker(name: 'Ramesh', trade: 'Mason');
  final WalletRow wallet = await services.pettyCash.createWallet(projectId: project.id, name: 'Site cash');
  await services.pettyCash.addTopUp(walletId: wallet.id, amount: Money.parse('1000'), entryDate: '2026-11-08');
  final String dpr = await services.dpr.saveDraft(DprInput(projectId: project.id, reportDate: '2026-11-08'));
  final String issue = await services.issues.raise(projectId: project.id, title: 'Crack in slab');
  final String measurement = await services.measurements.create(
    MeasurementInput(
      projectId: project.id,
      boqItemId: line.id,
      measuredOn: '2026-11-08',
      nos: Qty.fromInt(1),
      length: Qty.parse('10'),
    ),
  );
  return _Phone(app, project, line, worker, wallet, dpr, issue, measurement);
}

/// Every table's row count and every kept value but the remembered time, so a
/// refused write can be shown to have changed nothing at all.
Future<String> _snapshot(AppDatabase db) async {
  final StringBuffer out = StringBuffer();
  for (final table in db.allTables) {
    final row = await db.customSelect('SELECT COUNT(*) AS c FROM ${table.actualTableName}').getSingle();
    out.writeln('${table.actualTableName}: ${row.read<int>('c')}');
  }
  final meta = await db.customSelect("SELECT key, value FROM key_values WHERE key <> '${MetaKey.offlineSeen}' ORDER BY key").get();
  for (final row in meta) {
    out.writeln('${row.read<String>('key')} = ${row.read<String>('value')}');
  }
  return out.toString();
}

/// Every write on the list is refused for the licence, and none changes anything.
Future<void> _expectEveryWriteRefused(_Phone p) async {
  expect(p.plan.licence.entriesBlocked, isTrue);
  final String before = await _snapshot(p.app.db);
  for (final entry in _writes().entries) {
    await expectLater(
      () async => entry.value(p),
      throwsA(isA<LicenceEnded>()),
      reason: '${entry.key} must be blocked, not just hidden',
    );
  }
  expect(await _snapshot(p.app.db), before, reason: 'a refusal stores nothing and deletes nothing');
}

/// What a phone looks like that never got a valid token: the plan state with no
/// token, no key and no end date, as a failed activation leaves it.
Future<void> _forgetTheLicence(_Phone p) async {
  final Map<String, dynamic> stored = jsonDecode((await p.app.db.readMeta(MetaKey.offlinePlan))!) as Map<String, dynamic>;
  for (final key in <String>['token', 'public_key', 'key_id', 'expires_at', 'issued_at']) {
    stored[key] = null;
  }
  await p.app.db.writeMeta(MetaKey.offlinePlan, jsonEncode(stored));
  await p.plan.load();
}

/// Every call that adds or changes a record on the phone, by repository. The
/// arguments are valid, so a refusal can only be the licence.
Map<String, Future<Object?> Function(_Phone)> _writes() {
  final order = OpenOrder(id: 1, number: 'PO-1', lines: const <OrderLine>[]);
  final machine = const Machine(id: 1, code: 'EX-1', name: 'Excavator');
  const consent = SelfieConsent(version: 'selfie-v1', text: 'The worker agrees.');
  final draft = InspectionDraft(
    uuid: 'draft-1',
    projectId: 1,
    templateId: 1,
    templateName: 'Pour check',
    category: 'general',
    items: const <String>['Level'],
    inspectedOn: '2026-11-08',
    startedAt: '2026-11-08T10:00:00Z',
  );
  final template = const ChecklistTemplate(id: 1, name: 'Pour check', category: 'general', items: <String>['Level']);

  return <String, Future<Object?> Function(_Phone)>{
    'projects.createProject': (p) => p.app.services.projects.createProject(name: 'Second site'),
    'projects.addBoqItem': (p) => p.app.services.projects.addBoqItem(
      projectId: p.project.id,
      description: 'Steel',
      unit: 'kg',
      qty: Qty.parse('10'),
      rate: Money.parse('60'),
    ),
    'attendance.addWorker': (p) => p.app.services.attendance.addWorker(name: 'Suresh', trade: 'Helper'),
    'attendance.mark': (p) => p.app.services.attendance.mark(projectId: p.project.id, workerId: p.worker.id, date: '2026-11-08', status: 'present'),
    'attendance.markAllPresent': (p) => p.app.services.attendance.markAllPresent(p.project.id, '2026-11-08'),
    'attendance.scan': (p) => p.app.services.attendance.scan(p.project.id, 'N360W-1-1-aaaaaaaaaaaaaaaaaaaa'),
    'selfies.accept': (p) => p.app.services.selfies.accept(consent),
    'selfies.submit': (p) => p.app.services.selfies.submit(
      projectId: p.project.id,
      siteId: 1,
      worker: p.worker,
      photoPath: '/none.jpg',
      fix: const SelfieFix(lat: '18.5', lng: '73.8', accuracy: '5'),
      capturedAt: DateTime.utc(2026, 11, 8),
      consent: consent,
    ),
    'dpr.saveDraft': (p) => p.app.services.dpr.saveDraft(DprInput(projectId: p.project.id, reportDate: '2026-11-08', summary: 'Later'), clientUuid: p.dpr),
    'dpr.saveDraft (a new day)': (p) => p.app.services.dpr.saveDraft(DprInput(projectId: p.project.id, reportDate: '2026-11-07')),
    'dpr.editReturned': (p) => p.app.services.dpr.editReturned(p.dpr, DprInput(projectId: p.project.id, reportDate: '2026-11-08')),
    'dpr.copyManpowerFromYesterday': (p) => p.app.services.dpr.copyManpowerFromYesterday(p.project.id, '2026-11-08'),
    'dpr.addPhoto': (p) => p.app.services.dpr.addPhoto(dprClientUuid: p.dpr, sourcePath: '/none.jpg'),
    'dpr.removePhoto': (p) => p.app.services.dpr.removePhoto('no-such-photo'),
    'dpr.send': (p) => p.app.services.dpr.send(p.dpr),
    'issues.raise': (p) => p.app.services.issues.raise(projectId: p.project.id, title: 'Leak'),
    'issues.changeStatus': (p) => p.app.services.issues.changeStatus(p.issue, 'resolved'),
    'issues.addPhotos': (p) => p.app.services.issues.addPhotos(p.issue, const <String>['/none.jpg'], kind: 'photo'),
    'measurements.create': (p) => p.app.services.measurements.create(
      MeasurementInput(projectId: p.project.id, boqItemId: p.line.id, measuredOn: '2026-11-08', nos: Qty.fromInt(2), length: Qty.parse('3')),
    ),
    'measurements.edit': (p) => p.app.services.measurements.edit(
      p.measurement,
      MeasurementInput(projectId: p.project.id, boqItemId: p.line.id, measuredOn: '2026-11-08', nos: Qty.fromInt(3), length: Qty.parse('3')),
    ),
    'measurements.addPhotos': (p) => p.app.services.measurements.addPhotos(p.measurement, const <String>['/none.jpg']),
    'measurements.sendForApproval': (p) => p.app.services.measurements.sendForApproval(p.measurement),
    'pettyCash.createWallet': (p) => p.app.services.pettyCash.createWallet(projectId: p.project.id, name: 'Second wallet'),
    'pettyCash.addTopUp': (p) => p.app.services.pettyCash.addTopUp(walletId: p.wallet.id, amount: Money.parse('50'), entryDate: '2026-11-08'),
    'pettyCash.addExpense': (p) => p.app.services.pettyCash.addExpense(walletId: p.wallet.id, amount: Money.parse('50'), entryDate: '2026-11-08', category: 'Food'),
    'material.receive': (p) => p.app.services.material.receive(
      projectId: p.project.id,
      materialId: 1,
      qty: Qty.parse('5'),
      date: '2026-11-08',
      title: 'Cement',
    ),
    'material.use': (p) => p.app.services.material.use(projectId: p.project.id, materialId: 1, qty: Qty.parse('5'), date: '2026-11-08', title: 'Cement'),
    'material.count': (p) => p.app.services.material.count(projectId: p.project.id, materialId: 1, counted: Qty.parse('5'), date: '2026-11-08', title: 'Cement'),
    'material.receiveAgainstOrder': (p) => p.app.services.material.receiveAgainstOrder(
      projectId: p.project.id,
      order: order,
      date: '2026-11-08',
      lines: const <ReceiptEntry>[],
      title: 'PO-1',
    ),
    'material.issueFromStore': (p) => p.app.services.material.issueFromStore(
      projectId: p.project.id,
      storeId: 1,
      date: '2026-11-08',
      lines: const <IssueEntry>[],
      title: 'Issue',
    ),
    'progress.addEntry': (p) => p.app.services.progress.addEntry(projectId: p.project.id, boqItemId: p.line.id, workDate: '2026-11-08', qty: Qty.parse('1'), title: 'Slab'),
    'progress.correctEntry': (p) => p.app.services.progress.correctEntry(
      projectId: p.project.id,
      entryId: 1,
      boqItemId: p.line.id,
      serverQty: Qty.parse('2'),
      rightQty: Qty.parse('1'),
      remark: 'Typed twice',
      title: 'Slab',
    ),
    'progress.resolvePending': (p) => p.app.services.progress.resolvePending(projectId: p.project.id, entryId: 1, title: 'Slab'),
    'progress.setStage': (p) => p.app.services.progress.setStage(
      projectId: p.project.id,
      floorId: 1,
      trade: 'masonry',
      stage: 'brickwork',
      status: 'done',
      title: 'Floor 1',
    ),
    'machines.log': (p) => p.app.services.machines.log(
      uuid: 'log-1',
      projectId: p.project.id,
      machine: machine,
      date: '2026-11-08',
      hours: Hours.fromInt(5),
    ),
    'inspections.startDraft': (p) => p.app.services.inspections.startDraft(projectId: p.project.id, template: template),
    'inspections.saveDraft': (p) => p.app.services.inspections.saveDraft(draft),
    'inspections.discardDraft': (p) => p.app.services.inspections.discardDraft(p.project.id, 'draft-1'),
    'inspections.finish': (p) => p.app.services.inspections.finish(draft),
    'contractors.requestPayment': (p) => p.app.services.contractors.requestPayment(
      uuid: 'pay-1',
      projectId: p.project.id,
      workOrderId: 1,
      amount: Money.parse('100'),
      paidOn: '2026-11-08',
      mode: 'cash',
      title: 'Advance',
    ),
    'approvals.approve': (p) => p.app.services.approvals.approve(1),
    'approvals.sendBack': (p) => p.app.services.approvals.sendBack(1, remark: 'Redo'),
    'syncCenter.discard': (p) => p.app.services.syncCenter.discard(1),
    'context.createLocal': (p) => p.app.services.context.createLocal<void>(kind: 'project', table: 'projects', insert: (_) async {}),
    'context.createOnline': (p) => p.app.services.context.createOnline<void>(
      identity: <Object?>['x'],
      send: (_) async => <String, dynamic>{},
      store: (_) async {},
    ),
  };
}

void main() {
  group('the last week', () {
    test('is a calm warning from seven days before the end, and not before', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final DateTime end = p.plan.state!.expiresAt!;

      p.app.clock.set(end.subtract(const Duration(days: 7, seconds: 1)));
      expect(p.plan.licence.standing, LicenceStanding.active);
      expect(p.plan.licence.isEndingSoon, isFalse);

      p.app.clock.set(end.subtract(const Duration(days: 7)));
      expect(p.plan.licence.standing, LicenceStanding.endingSoon);
      expect(p.plan.licence.isEndingSoon, isTrue);

      p.app.clock.set(end.subtract(const Duration(seconds: 1)));
      expect(p.plan.licence.standing, LicenceStanding.endingSoon);
      expect(p.plan.licence.entriesBlocked, isFalse, reason: 'a warning blocks nothing');
      p.plan.assertCanWrite();
      await p.app.services.issues.raise(projectId: p.project.id, title: 'Still allowed');
    });

    test('says the end date from the signed token', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      expect(p.plan.licence.endsAt, p.plan.state!.expiresAt);
      final DateTime server = DateTime.utc(2026, 11, 9, 4, 30);
      expect(p.plan.licence.endsAt, server.add(const Duration(days: 90)));
    });
  });

  group('after the end', () {
    test('the end itself is the end', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final DateTime end = p.plan.state!.expiresAt!;

      p.app.clock.set(end);

      expect(p.plan.licence.standing, LicenceStanding.ended);
      expect(p.plan.licence.entriesBlocked, isTrue);
      expect(p.plan.assertCanWrite, throwsA(isA<LicenceEnded>()));
    });

    test('every path that adds or changes a record is refused, and nothing changes', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final writes = _writes();

      // While the licence is good the calls get past the guard: whatever they say
      // next, it is not the licence.
      for (final entry in writes.entries) {
        try {
          await entry.value(p);
        } on LicenceEnded {
          fail('${entry.key} was refused while the licence was good');
        } on Object {
          // The data is made up to get past the guard; other refusals do not matter here.
        }
      }

      await p.runOut();
      await _expectEveryWriteRefused(p);
    });

    test('every repository method that writes is in the matrix above, or is known not to add or change an entry', () {
      // Reads the source, so a new write path added without the guard, or without a
      // line in the matrix, fails here and names the file and the method.
      final listed = _writes().keys.map((name) => name.split(' ').first.split('.').last).toSet();
      final unguarded = <String>[];
      final untested = <String>[];
      for (final method in _repositoryMethods()) {
        final String id = '${method.file}: ${method.name}';
        if (method.writes && !method.guarded && !_notAnEntry.contains(method.name)) unguarded.add(id);
        if (method.guarded && !listed.contains(method.name)) untested.add(id);
      }
      expect(unguarded, isEmpty, reason: 'These write to the phone without asking the licence: $unguarded');
      expect(untested, isEmpty, reason: 'These guard the licence but the matrix never calls them: $untested');
    });

    test('a refused write is announced, so the app can open the renewal page', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      final refusals = <LicenceEnded>[];
      final sub = p.plan.refusals.listen(refusals.add);
      addTearDown(sub.cancel);

      await expectLater(p.app.services.issues.raise(projectId: p.project.id, title: 'No'), throwsA(isA<LicenceEnded>()));
      await pumpEventQueue();

      expect(refusals, hasLength(1));
      expect(refusals.single.endedAt, p.plan.state!.expiresAt);
    });

    test('everything already there can still be read', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      final services = p.app.services;

      expect((await services.projects.getProject(p.project.id))?.name, 'Kalyani');
      expect(await services.projects.watchBoqItems(p.project.id).first, hasLength(1));
      expect(await services.pettyCash.watchWallets(p.project.id).first, hasLength(1));
      expect(await services.issues.watchByProject(p.project.id).first, hasLength(1));
      expect(await services.measurements.watchByProject(p.project.id).first, hasLength(1));
      expect(await services.dpr.watchList(p.project.id).first, hasLength(1));
      expect((await services.home.watchHomeSummary(p.project.id).first).attendanceTotal, greaterThan(0));
      expect((await p.plan.work()).projects, 1);
    });

    test('signing out still works and keeps everything', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();

      await p.app.services.auth.signOut();

      expect(await p.app.db.select(p.app.db.projects).get(), hasLength(1));
      expect(await p.app.services.context.outbox.unsentCount(), greaterThan(0));
    });

    test('a token changed in storage is no licence, and entries stop', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final state = p.plan.state!;
      await p.app.db.writeMeta(MetaKey.offlinePlan, jsonEncode(state.copyWith(token: '${state.token}x').toJson()));

      await p.plan.load();

      expect(p.plan.licence.entriesBlocked, isTrue);
      await expectLater(p.app.services.issues.raise(projectId: p.project.id, title: 'No'), throwsA(isA<LicenceEnded>()));
    });

    test('an end date with its token taken out is no licence either', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final Map<String, dynamic> stored = jsonDecode((await p.app.db.readMeta(MetaKey.offlinePlan))!) as Map<String, dynamic>;
      stored['token'] = null;
      await p.app.db.writeMeta(MetaKey.offlinePlan, jsonEncode(stored));

      await p.plan.load();

      expect(p.plan.licence.entriesBlocked, isTrue);
    });
  });

  group('a clock that is wrong', () {
    test('set back after the end, it buys nothing', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      expect(p.plan.licence.entriesBlocked, isTrue);

      p.app.clock.set(DateTime.utc(2026, 11, 10));

      expect(p.plan.licence.entriesBlocked, isTrue);
      await expectLater(p.app.services.issues.raise(projectId: p.project.id, title: 'No'), throwsA(isA<LicenceEnded>()));
    });

    test('set back and the app restarted, the latest time seen is remembered', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      expect(await p.app.db.readMeta(MetaKey.offlineSeen), isNotNull);

      p.app.clock.set(DateTime.utc(2026, 11, 10));
      final restarted = OfflinePlan(
        db: p.app.db,
        api: p.app.api,
        clock: p.app.clock,
        connectivity: p.app.connectivity,
        session: p.app.services.session,
      );
      addTearDown(restarted.dispose);
      await restarted.load();

      expect(restarted.licence.entriesBlocked, isTrue);
      expect(restarted.now().isAfter(p.plan.state!.expiresAt!), isTrue);
    });

    test('set back before a token was signed, it cannot be earlier than the signing', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final DateTime signed = p.plan.state!.issuedAt!;

      p.app.clock.set(signed.subtract(const Duration(days: 30)));

      expect(p.plan.now(), signed);
    });

    test('the time is saved when it has moved a minute, not on every look', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final String? first = await p.app.db.readMeta(MetaKey.offlineSeen);

      p.app.clock.advance(const Duration(seconds: 20));
      await p.plan.rememberTime();
      expect(await p.app.db.readMeta(MetaKey.offlineSeen), first);

      p.app.clock.advance(const Duration(minutes: 2));
      await p.plan.rememberTime();
      expect(await p.app.db.readMeta(MetaKey.offlineSeen), isNot(first));
    });

    test('a clock that once ran far ahead is put right by the next renewal', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final DateTime real = p.app.clock.now();

      p.app.clock.set(real.add(const Duration(days: 700)));
      await p.plan.tick();
      expect(p.plan.licence.entriesBlocked, isTrue);

      // The date is fixed on the phone, but the remembered time is still far ahead.
      p.app.clock.set(real);
      expect(p.plan.licence.entriesBlocked, isTrue, reason: 'an offline phone cannot tell, so it stays shut');

      final LicenceRenewal result = await p.plan.renewNow();

      expect(result, LicenceRenewal.renewed);
      expect(p.plan.licence.entriesBlocked, isFalse);
      p.plan.assertCanWrite();
    });
  });

  group('renewing', () {
    test('a fresh token lifts the block at once, with no restart', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      final changes = <LicenceStatus>[];
      final sub = p.plan.licenceChanges.listen(changes.add);
      addTearDown(sub.cancel);
      await pumpEventQueue();
      expect(changes.last.entriesBlocked, isTrue);

      final LicenceRenewal result = await p.plan.renewNow();
      await pumpEventQueue();

      expect(result, LicenceRenewal.renewed);
      expect(changes.last.entriesBlocked, isFalse);
      expect(p.plan.state!.expiresAt!.isAfter(p.app.clock.now()), isTrue);
      await p.app.services.issues.raise(projectId: p.project.id, title: 'Back at work');
    });

    test('the moment the phone is online it renews by itself', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      p.app.connectivity.setOnline(false);
      await p.runOut();
      expect(p.plan.licence.entriesBlocked, isTrue);
      final int refreshes = p.app.api.refreshCalls;

      p.app.connectivity.setOnline(true);
      await pumpEventQueue();

      expect(p.app.api.refreshCalls, refreshes + 1);
      expect(p.plan.licence.entriesBlocked, isFalse);
    });

    test('with no network it says so and asks nobody', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.connectivity.setOnline(false);
      final int calls = p.app.api.refreshCalls;

      expect(await p.plan.renewNow(), LicenceRenewal.offline);

      expect(p.app.api.refreshCalls, calls);
      expect(p.plan.licence.entriesBlocked, isTrue);
    });

    test('a server that does not answer leaves it shut and says so', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offline = true;

      expect(await p.plan.renewNow(), LicenceRenewal.noAnswer);
      expect(p.plan.licence.entriesBlocked, isTrue);

      p.app.api.offline = false;
      expect(await p.plan.renewNow(), LicenceRenewal.renewed);
    });

    test('a company whose own plan has run out gets a licence that has ended too', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.licenceSeconds = -3600;

      expect(await p.plan.renewNow(), LicenceRenewal.stillEnded);
      expect(p.plan.licence.entriesBlocked, isTrue);
    });

    test('a freed phone is told, and does not take the slot back by itself', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.refreshFailure = const ServerFailure('Freed.', statusCode: 410, code: 'licence_revoked');

      expect(await p.plan.renewNow(), LicenceRenewal.freed);
      expect(p.plan.licence.entriesBlocked, isTrue);
    });

    test('a lapsed sign-in is told, because only a fresh one can renew', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.unauthorized = true;

      expect(await p.plan.renewNow(), LicenceRenewal.needsSignIn);
      expect(p.plan.licence.entriesBlocked, isTrue);
    });

    test('in the last week and after the end it tries at every chance, not once an hour', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final int calls = p.app.api.refreshCalls;

      p.pass(const Duration(minutes: 5));
      await p.plan.check();
      expect(p.app.api.refreshCalls, calls, reason: 'a long licence is renewed hourly');

      await p.runOut();
      p.app.api.refreshFailure = const ServerFailure('Down.', statusCode: 500);
      final int before = p.app.api.refreshCalls;
      p.pass(const Duration(seconds: 31));
      await p.plan.check();
      expect(p.app.api.refreshCalls, before + 1);
    });
  });

  group('who is not stopped', () {
    test('a company on a normal plan, whatever its clock says', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.clock.advance(const Duration(days: 5000));

      expect(app.services.offlinePlan.licence, LicenceStatus.none);
      app.services.offlinePlan.assertCanWrite();
      await app.services.issues.raise(projectId: 1, title: 'As ever');
      expect(await app.db.readMeta(MetaKey.offlinePlan), isNull);
      expect(await app.db.readMeta(MetaKey.offlineSeen), isNull, reason: 'nothing about the offline plan is stored');
    });

    test('the usual writes work on a normal plan, even with the clock far ahead', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.clock.advance(const Duration(days: 5000));
      final services = app.services;

      await services.issues.raise(projectId: 1, title: 'Crack');
      await services.pettyCash.addTopUp(walletId: 1, amount: Money.parse('10'), entryDate: '2026-11-08');
      await services.attendance.mark(projectId: 1, workerId: 1, date: '2026-11-08', status: 'present');
      final String uuid = await services.dpr.saveDraft(const DprInput(projectId: 1, reportDate: '2026-11-08'));
      await services.dpr.send(uuid);

      expect(await services.context.outbox.unsentCount(), greaterThan(0));
    });

  });

  group('a phone that keeps its data local, whatever the server now says about the plan', () {
    test('a good licence is not held back in any mode: the term is paid for', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      expect(p.plan.state!.mode, OfflineMode.asking);
      p.plan.assertCanWrite();

      await p.plan.decline();
      expect(p.plan.state!.mode, OfflineMode.declined);
      p.plan.assertCanWrite();
      await p.app.services.issues.raise(projectId: p.project.id, title: 'Still allowed on a good token');
    });

    test('after the end, and the plan has gone online, every write is still refused, asked or declined', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      expect(p.plan.state!.mode, OfflineMode.asking);
      expect(p.plan.licence.planSyncs, isTrue);
      await _expectEveryWriteRefused(p);

      await p.plan.decline();
      expect(p.plan.state!.mode, OfflineMode.declined);
      expect(p.plan.licence.planSyncs, isTrue);
      await _expectEveryWriteRefused(p);
    });

    test('saying no to the upload gains nothing: a lapsed trial on a free plan cannot work offline for ever', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      await p.plan.decline();
      p.plan.assertCanWrite();

      await p.runOut();

      expect(p.plan.licence.entriesBlocked, isTrue);
      await expectLater(p.app.services.issues.raise(projectId: p.project.id, title: 'No'), throwsA(isA<LicenceEnded>()));
    });

    test('it can still be read and exported while blocked', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      await p.plan.decline();

      expect((await p.app.services.projects.getProject(p.project.id))?.name, 'Kalyani');
      expect(await p.app.services.issues.watchByProject(p.project.id).first, hasLength(1));
      expect((await p.plan.work()).projects, 1);
    });

    test('there is no licence to renew for a plan that syncs, and trying says so', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      await p.plan.decline();

      expect(await p.plan.renewNow(), LicenceRenewal.planChanged);
      expect(p.plan.licence.entriesBlocked, isTrue);
    });

    test('a fresh token lifts it, if the plan goes back to offline', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      await p.plan.decline();
      expect(p.plan.licence.entriesBlocked, isTrue);

      p.app.api.offlineCompany = true;
      expect(await p.plan.renewNow(), LicenceRenewal.renewed);

      expect(p.plan.state!.mode, OfflineMode.local);
      expect(p.plan.licence.entriesBlocked, isFalse);
      await p.app.services.issues.raise(projectId: p.project.id, title: 'Back at work');
    });

    test('a finished upload lifts it, and the upload itself is not held back, saved reports included', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      expect(p.plan.licence.entriesBlocked, isTrue);

      final OfflineUploader uploader = p.app.services.offlineUploader;
      final UploadReport report = await uploader.run();
      expect(report.isReady, isTrue);
      expect(p.plan.state!.mode, OfflineMode.uploading);
      expect(p.plan.licence.entriesBlocked, isFalse, reason: 'nothing else is reachable while it runs');
      await uploader.finish(p.app.services.sync);

      expect(p.plan.isLocalOnly, isFalse);
      expect(p.plan.licence, LicenceStatus.none);
      expect(p.app.api.dprs, hasLength(1), reason: 'the saved report was queued by the upload and went up');
      expect(await p.app.services.context.outbox.unsentCount(), 0);
      final int server = (await p.app.db.select(p.app.db.projects).get()).single.id;
      await p.app.services.issues.raise(projectId: server, title: 'A normal company again');
    });

    test('an upload that stops part way does not leave the phone open if the owner then says no', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await p.runOut();
      p.app.api.offlineCompany = false;
      await p.plan.check(force: true);
      await p.plan.beginUpload();
      expect(p.plan.licence.entriesBlocked, isFalse);

      await p.plan.decline();

      expect(p.plan.state!.mode, OfflineMode.declined);
      await _expectEveryWriteRefused(p);
    });
  });

  group('a phone with no valid token at all', () {
    test('every write is refused, and everything can still be read', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await _forgetTheLicence(p);

      expect(p.plan.state!.hasToken, isFalse);
      expect(p.plan.licence.standing, LicenceStanding.none);
      expect(p.plan.licence.hasNoLicence, isTrue);
      await _expectEveryWriteRefused(p);
      expect((await p.app.services.projects.getProject(p.project.id))?.name, 'Kalyani');
    });

    test('the plan being full: blocked, the server\'s manage_url kept, and it says so when asked', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await _forgetTheLicence(p);
      p.app.api.activateFailure = const ServerFailure(
        'Full.',
        statusCode: 409,
        code: 'device_limit',
        data: <String, dynamic>{'manage_url': 'https://example.test/offline/devices'},
      );

      expect(await p.plan.renewNow(), LicenceRenewal.deviceLimit);

      expect(p.plan.state!.hasToken, isFalse);
      expect(p.plan.state!.issue, LicenceIssue.deviceLimit);
      expect(p.plan.state!.manageUrl, 'https://example.test/offline/devices');
      await _expectEveryWriteRefused(p);
    });

    test('a first sign-in that meets a full plan can add nothing at all', () async {
      final api = FakeApi()
        ..offlineCompany = true
        ..activateFailure = const ServerFailure('Full.', statusCode: 409, code: 'device_limit');
      final app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

      expect(app.services.offlinePlan.state!.hasToken, isFalse);
      expect(app.services.offlinePlan.licence.hasNoLicence, isTrue);
      await expectLater(() async => app.services.projects.createProject(name: 'First'), throwsA(isA<LicenceEnded>()));
      expect(await app.db.select(app.db.projects).get(), isEmpty);
    });

    test('a phone that could not reach the server to activate is blocked, then given a licence the moment it can', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await _forgetTheLicence(p);
      p.app.api.activateFailure = const OfflineFailure();

      expect(await p.plan.renewNow(), LicenceRenewal.noAnswer);
      await _expectEveryWriteRefused(p);

      p.app.api.activateFailure = null;
      expect(await p.plan.renewNow(), LicenceRenewal.renewed);

      expect(p.plan.state!.hasToken, isTrue);
      expect(p.plan.licence.entriesBlocked, isFalse);
      await p.app.services.issues.raise(projectId: p.project.id, title: 'Licensed at last');
    });

    test('the moment it is online it asks for its first licence by itself', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      p.app.connectivity.setOnline(false);
      await _forgetTheLicence(p);
      expect(p.plan.licence.entriesBlocked, isTrue);

      p.pass(const Duration(minutes: 1));
      p.app.connectivity.setOnline(true);
      await pumpEventQueue();

      expect(p.plan.state!.hasToken, isTrue);
      expect(p.plan.licence.entriesBlocked, isFalse);
    });

    test('a first reply that fails its signature leaves it blocked', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      await _forgetTheLicence(p);
      p.app.api.forgeLicence = true;

      expect(await p.plan.renewNow(), LicenceRenewal.stillEnded);

      expect(p.plan.state!.hasToken, isFalse);
      expect(p.plan.state!.issue, LicenceIssue.invalid);
      await _expectEveryWriteRefused(p);
    });

    test('a token with its key taken away is not a licence', () async {
      final p = await _phone();
      addTearDown(p.app.dispose);
      final Map<String, dynamic> stored = jsonDecode((await p.app.db.readMeta(MetaKey.offlinePlan))!) as Map<String, dynamic>;
      stored['public_key'] = null;
      await p.app.db.writeMeta(MetaKey.offlinePlan, jsonEncode(stored));

      await p.plan.load();

      expect(p.plan.state!.issue, LicenceIssue.invalid);
      await _expectEveryWriteRefused(p);
    });

    test('a company on a normal plan is never in this state, so it is never held', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);

      expect(app.services.offlinePlan.state, isNull);
      expect(app.services.offlinePlan.licence.entriesBlocked, isFalse);
      app.services.offlinePlan.assertCanWrite();
    });
  });
}

/// Public repository methods that touch the database but add or change nothing a
/// person entered: they keep a copy of what the server said, or a preference.
const Set<String> _notAnEntry = <String>{
  'refresh', // the server's copy of a list, kept for reading offline
  'refreshContract',
  'refreshInspection',
  'loadConsent', // the consent note the server sent
  'forget', // drops cached prices when the permission is lost
  'bytesFor', // a project's photo, kept so it opens offline
  'selectProject', // which project is open
  'setLocale', // the language
};

class _RepoMethod {
  const _RepoMethod(this.file, this.name, this.writes, this.guarded);

  final String file;
  final String name;

  /// Its body adds, changes or deletes something in the database.
  final bool writes;

  /// Its body asks the licence first.
  final bool guarded;
}

/// Every public method of every repository, read from the source.
List<_RepoMethod> _repositoryMethods() {
  const markers = <String>[
    '.enqueue(',
    'createLocal(',
    'createOnline(',
    '.into(',
    '_db.update(',
    '_db.delete(',
    '.transaction(',
    'writeMeta(',
    'photos.add(',
    'photos.queue(',
    '_act(',
  ];
  final signature = RegExp(r'^  (?:Future|Stream|void|bool|int|String)[^\n(]*?\s(\w+)\(', multiLine: true);
  final methods = <_RepoMethod>[];
  final files = Directory('lib/features')
      .listSync(recursive: true)
      .whereType<File>()
      .where((f) => f.path.endsWith('_repository.dart') && f.path.contains('/data/'));
  for (final file in files) {
    final String source = file.readAsStringSync();
    for (final RegExpMatch match in signature.allMatches(source)) {
      final String name = match.group(1)!;
      if (name.startsWith('_') || name.startsWith('watch')) continue;
      var i = match.end;
      var depth = 1;
      while (depth > 0) {
        final String c = source[i++];
        if (c == '(') depth++;
        if (c == ')') depth--;
      }
      final String rest = source.substring(i);
      final int k = RegExp(r'\s*(async\s*)?').matchAsPrefix(rest)!.end;
      String body;
      if (rest.startsWith('=>', k)) {
        body = rest.substring(k, rest.indexOf(';', k));
      } else if (rest[k] == '{') {
        var d = 0;
        var j = k;
        while (true) {
          if (rest[j] == '{') d++;
          if (rest[j] == '}' && --d == 0) break;
          j++;
        }
        body = rest.substring(k, j);
      } else {
        continue;
      }
      methods.add(
        _RepoMethod(
          file.path.split('/').last,
          name,
          markers.any(body.contains),
          body.contains('assertCanWrite') || body.contains('_act('),
        ),
      );
    }
  }
  return methods;
}
