import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/sync_engine.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../../support/test_app.dart';

MeasurementInput entryInput({
  String nos = '2.000',
  String length = '10.000',
  String measuredOn = '2026-11-09',
}) {
  return MeasurementInput(
    projectId: 1,
    boqItemId: 1,
    measuredOn: measuredOn,
    nos: Qty.parse(nos),
    length: Qty.parse(length),
  );
}

Future<List<OutboxRow>> allOutbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)]))
        .get();

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  group('exactly once', () {
    test('create offline, then a timeout whose response was lost, leaves '
        'exactly one server row', () async {
      app.api.offline = true;
      final uuid = await app.services.measurements.create(entryInput());
      await app.sync();
      expect(app.api.measurements, isEmpty);
      expect((await allOutbox(app)).single.status, OutboxStatus.pending);

      app.api.offline = false;
      app.api.loseNextPushResponse();
      final first = await app.services.sync.syncNow();
      expect(first.outcome, SyncOutcome.offline);
      expect(app.api.measurements, hasLength(1));
      expect((await allOutbox(app)).single.status, OutboxStatus.pending);

      final second = await app.services.sync.syncNow();
      expect(second.isSuccess, isTrue);
      expect(app.api.measurements, hasLength(1));
      expect(app.api.measurementByUuid(uuid)['status'], 'DRAFT');

      final row = (await allOutbox(app)).single;
      expect(row.status, OutboxStatus.done);
      expect(row.resultStatus, 'duplicate');
    });

    test('process killed mid-send, then restart, leaves exactly one row',
        () async {
      await app.services.measurements.create(entryInput());
      app.api.hangNextPush();

      // Fire the run without awaiting it, as a process killed mid-request.
      // ignore: unawaited_futures
      app.services.sync.syncNow();
      await pumpEventQueue();
      expect((await allOutbox(app)).single.status, OutboxStatus.sending);
      expect(app.api.measurements, hasLength(1));

      // A new engine in a new process over the same database file. The
      // lease from the dead process runs out before the first sync.
      app.clock.advance(const Duration(minutes: 2));
      final restarted = SyncEngine(
        db: app.db,
        api: app.api,
        clock: app.clock,
        attachments: app.attachments,
        connectivity: app.connectivity,
      );
      addTearDown(restarted.dispose);

      expect(await restarted.recoverInterrupted(), 1);
      expect((await allOutbox(app)).single.status, OutboxStatus.pending);

      final result = await restarted.syncNow();
      expect(result.isSuccess, isTrue);
      expect(app.api.measurements, hasLength(1));
      expect((await allOutbox(app)).single.resultStatus, 'duplicate');
    });

    test('two engines never send the same row at the same time', () async {
      await app.services.measurements.create(entryInput());
      final gate = app.api.hangNextPush();

      final firstRun = app.services.sync.syncNow();
      await pumpEventQueue();

      final background = SyncEngine(
        db: app.db,
        api: app.api,
        clock: app.clock,
        attachments: app.attachments,
        connectivity: app.connectivity,
      );
      addTearDown(background.dispose);

      final second = await background.syncNow();
      expect(second.outcome, SyncOutcome.busy);
      expect(app.api.pushCalls, hasLength(1));

      gate.complete();
      expect((await firstRun).isSuccess, isTrue);
      expect(app.api.measurements, hasLength(1));
    });

    test('syncNow is safe to call concurrently', () async {
      await app.services.measurements.create(entryInput());
      await Future.wait([
        app.services.sync.syncNow(),
        app.services.sync.syncNow(),
        app.services.sync.syncNow(),
      ]);
      expect(app.api.pushCalls, hasLength(1));
      expect(app.api.measurements, hasLength(1));
    });

    test('a second call during a run gets one more pass', () async {
      await app.services.measurements.create(entryInput());
      final gate = app.api.hangNextPush();
      final first = app.services.sync.syncNow();
      await pumpEventQueue();

      // Written during the first run, after its batch was built.
      await app.services.measurements.create(entryInput(nos: '3.000'));
      // ignore: unawaited_futures
      app.services.sync.syncNow();
      gate.complete();
      await first;

      expect(app.api.measurements, hasLength(2));
    });
  });

  group('crash recovery', () {
    test('rows stuck in sending return to pending at start-up', () async {
      await app.services.measurements.create(entryInput());
      await app.db.update(app.db.outbox).write(
        const OutboxCompanion(status: Value(OutboxStatus.sending)),
      );

      final recovered = await app.services.sync.recoverInterrupted();

      expect(recovered, 1);
      expect((await allOutbox(app)).single.status, OutboxStatus.pending);
    });
  });

  group('ordering', () {
    test('DPR, then photos, then submit, in order and in dependency',
        () async {
      final temp = await Directory.systemTemp.createTemp('n360');
      addTearDown(() => temp.delete(recursive: true));
      final store = DirectoryAttachmentStore(() async => temp.path);
      final realFiles = await TestApp.create(attachments: store);
      addTearDown(realFiles.dispose);

      final repo = realFiles.services.dpr;
      final uuid = await repo.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09', summary: 'Pour'),
      );
      final source = File('${temp.path}/source.jpg')..writeAsBytesSync([1, 2, 3]);
      await repo.addPhoto(dprClientUuid: uuid, sourcePath: source.path);
      await repo.addPhoto(dprClientUuid: uuid, sourcePath: source.path);
      await repo.send(uuid);

      final queued = await allOutbox(realFiles);
      expect(queued.map((r) => r.type), [
        OutboxType.dpr,
        OutboxType.dprPhoto,
        OutboxType.dprPhoto,
        OutboxType.dprSubmit,
      ]);

      final api = realFiles.api;
      await realFiles.sync();

      expect(api.pushCalls.map((c) => c.single.type), ['dpr', 'dpr_submit']);
      expect(api.uploadCalls, hasLength(2));
      expect(
        (await allOutbox(realFiles)).every(
          (r) => r.status == OutboxStatus.done,
        ),
        isTrue,
      );
      expect(api.dprByUuid(uuid)['status'], 'IN_REVIEW');
      expect(api.dprByUuid(uuid)['photos'], hasLength(2));
    });

    test('a failed parent blocks its dependants without sending them',
        () async {
      final uuid = await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      app.services.dpr.send(uuid);
      await pumpEventQueue();

      final dpr = (await allOutbox(app)).first;
      await (app.db.update(app.db.outbox)
            ..where((t) => t.id.equals(dpr.id)))
          .write(
            const OutboxCompanion(
              status: Value(OutboxStatus.attention),
              attentionCode: Value(AttentionCode.validation),
            ),
          );

      await app.sync();

      expect(app.api.pushCalls.expand((c) => c), isEmpty);
      final rows = await allOutbox(app);
      expect(rows.last.status, OutboxStatus.pending);

      final blocked = await app.services.syncCenter.watchOutbox().first;
      expect(blocked.last.isBlocked, isTrue);
    });
  });

  group('pull merge', () {
    test('a pull during unsent local edits does not overwrite them', () async {
      final uuid = await app.services.measurements.create(
        entryInput(nos: '2.000'),
      );
      await app.sync();
      final serverRow = app.api.measurementByUuid(uuid);

      app.api.editOnWeb(serverRow, {'nos': '5.000', 'qty': '50.000'});

      // The engineer edits while the pull request is on the wire, so the row
      // is unsent when the response is applied.
      app.api.beforeNextPull = () =>
          app.services.measurements.edit(uuid, entryInput(nos: '9.000'));
      await app.services.sync.syncNow();

      final local = await (app.db.select(app.db.measurements)
            ..where((t) => t.clientUuid.equals(uuid)))
          .getSingle();
      expect(local.nos, Qty.parse('9.000'));
    });

    test('a project taken away leaves the phone, except work not yet sent', () async {
      app.api.addProject(id: 2, code: 'OT-1', name: 'Other site');
      app.api.boqItems.add({
        ...app.api.boqItems.first,
        'id': 2,
        'project_id': 2,
        'code': '9.9',
        'updated_at': app.api.stampNow(),
      });

      final sent = await app.services.measurements.create(entryInput());
      final draft = await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09', summary: 'Draft'),
      );
      await app.sync();
      final stuck = await app.services.measurements.create(entryInput(nos: '3.000'));
      _scriptedResults(app.api, ['error_project']);
      await app.sync();

      final project = app.api.projects.firstWhere((p) => p['id'] == 1);
      app.api.softDeleteOnWeb('projects', project);
      app.api.projects.remove(project);
      await app.sync();

      expect((await app.db.select(app.db.projects).get()).map((p) => p.id), [2]);
      final boq = await app.db.select(app.db.boqItems).get();
      expect(boq.map((b) => b.projectId), [2]);
      expect(await app.db.select(app.db.sites).get(), isEmpty);

      final entries = await app.db.select(app.db.measurements).get();
      expect(entries.map((m) => m.clientUuid), [stuck]);
      expect(entries.map((m) => m.clientUuid), isNot(contains(sent)));

      final dprs = await app.db.select(app.db.dprs).get();
      expect(dprs.map((d) => d.clientUuid), [draft], reason: 'an unsent draft is the engineer\'s own work');

      final attention = (await allOutbox(app)).firstWhere((r) => r.status == OutboxStatus.attention);
      await app.services.syncCenter.discard(attention.id);
      await app.sync();
      expect(await app.db.select(app.db.measurements).get(), isEmpty);
    });

    test('a DPR draft with no outbox item survives a pull', () async {
      final uuid = await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09', summary: 'Pour'),
      );
      await app.services.dpr.send(uuid);
      await app.sync();
      final serverRow = app.api.dprByUuid(uuid);
      app.api.setServerStatus(serverRow, 'RETURNED', returnRemark: 'Fix');
      await app.sync();

      await app.services.dpr.editReturned(
        uuid,
        const DprInput(
          projectId: 1,
          reportDate: '2026-11-09',
          summary: 'Local edit',
        ),
      );

      app.api.editOnWeb(serverRow, {'summary': 'Web edit'});
      await app.sync();

      final local = await (app.db.select(app.db.dprs)
            ..where((t) => t.clientUuid.equals(uuid)))
          .getSingle();
      expect(local.summary, 'Local edit');
      expect(local.status, DocStatus.returned);
    });

    test('an approved record is never overwritten and the edit surfaces '
        'as attention', () async {
      final uuid = await app.services.measurements.create(entryInput());
      await app.sync();
      final serverRow = app.api.measurementByUuid(uuid);

      // Returned, then edited on the phone while offline.
      app.api.setServerStatus(serverRow, 'RETURNED');
      await app.sync();
      app.api.offline = true;
      await app.services.measurements.edit(uuid, entryInput(nos: '7.000'));

      // Meanwhile approved on the web. Back online.
      app.api.setServerStatus(serverRow, 'APPROVED');
      serverRow['nos'] = '2.000';
      app.api.offline = false;
      await app.sync();

      expect(serverRow['nos'], '2.000', reason: 'never merged on the server');
      expect(serverRow['status'], 'APPROVED');

      final row = (await allOutbox(app)).last;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.approved);
      expect(row.attentionReason, isNotEmpty);

      final local = await (app.db.select(app.db.measurements)
            ..where((t) => t.clientUuid.equals(uuid)))
          .getSingle();
      expect(local.status, DocStatus.approved, reason: 'status follows server');
      expect(local.nos, Qty.parse('7.000'), reason: 'local content kept');
    });

    test('attendance from another device merges on the natural key', () async {
      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'present',
      );

      app.api.attendance.add({
        'id': 77,
        'client_uuid': '11111111-1111-4111-8111-111111111111',
        'project_id': 1,
        'worker_id': 1,
        'attendance_date': '2026-11-09',
        'status': 'absent',
        'ot_hours': '0.0',
        'marked_by': 9,
        'client_updated_at': null,
        'updated_at': app.api.stampNow(),
      });

      await app.sync();

      final marks = await app.db.select(app.db.attendanceMarks).get();
      expect(marks, hasLength(1), reason: 'one mark per worker and date');
    });

    test('a server row with no client_uuid gets a stable UUID v5', () async {
      app.api.measurements.add({
        'id': 55,
        'client_uuid': null,
        'project_id': 1,
        'boq_item_id': 1,
        'work_order_id': null,
        'measured_on': '2026-11-08',
        'description': null,
        'location': null,
        'nos': '1.000',
        'length': '2.000',
        'breadth': null,
        'depth': null,
        'is_deduction': false,
        'qty': '2.000',
        'status': 'RETURNED',
        'deviation': false,
        'remarks': null,
        'return_remark': 'Check depth',
        'created_by': 4,
        'client_updated_at': null,
        'updated_at': app.api.stampNow(),
      });

      await app.sync();
      final first = (await app.db.select(app.db.measurements).get()).single;

      await app.db.delete(app.db.measurements).go();
      await app.db.deleteMeta(MetaKey.watermark);
      await app.sync();
      final second = (await app.db.select(app.db.measurements).get()).single;

      expect(second.clientUuid, first.clientUuid);
      expect(second.serverId, 55);
    });

    test('later pushes send the v5 uuid plus server_id', () async {
      final uuid = await _seedReturnedEntry(app);
      await app.services.measurements.edit(uuid, entryInput(nos: '4.000'));
      await app.sync();

      final item = app.api.pushCalls.last.single;
      expect(item.clientUuid, uuid);
      expect(item.serverId, 55);
      expect(app.api.measurements.single['client_uuid'], uuid);
    });
  });

  group('watermark', () {
    test('does not advance after a failed pull', () async {
      final before = await app.db.readMeta(MetaKey.watermark);
      expect(before, isNotNull);

      app.api.failNextPull(const ServerFailure('boom', statusCode: 500));
      final result = await app.services.sync.syncNow();
      expect(result.outcome, SyncOutcome.serverError);

      expect(await app.db.readMeta(MetaKey.watermark), before);
    });

    test('a pull that fails while applying leaves nothing applied', () async {
      final before = await app.db.readMeta(MetaKey.watermark);
      final projectsBefore = await app.db.select(app.db.projects).get();

      app.api.pullBodyOverride = {
        'server_time': '2026-11-09T20:00:00+05:30',
        'projects': [
          {
            'id': 2,
            'code': 'NEW',
            'name': 'New project',
            'status': 'active',
            'updated_at': '',
          },
        ],
        // A row missing its id blows up partway through.
        'measurements': [
          <String, dynamic>{'qty': '1.000'},
        ],
      };

      final result = await app.services.sync.syncNow();
      expect(result.isSuccess, isFalse);

      expect(await app.db.readMeta(MetaKey.watermark), before);
      expect(await app.db.select(app.db.projects).get(), projectsBefore);
    });

    test('moves after a good pull and is sent back byte for byte', () async {
      await app.sync();
      final watermark = await app.db.readMeta(MetaKey.watermark);
      await app.sync();
      expect(app.api.pullCalls.last, watermark);
    });
  });

  group('session', () {
    test('a permission granted on the website applies after the next sync', () async {
      expect(app.services.session.user!.can('alerts.view'), isFalse);
      app.api.grant('alerts.view');

      await app.sync();

      expect(app.services.session.user!.can('alerts.view'), isTrue);
    });

    test('a 401 keeps all unsent work', () async {
      final uuid = await app.services.measurements.create(entryInput());
      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'present',
      );
      await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );

      app.api.unauthorized = true;
      final result = await app.services.sync.syncNow();

      expect(result.outcome, SyncOutcome.authExpired);
      expect(app.services.session.current.status, SessionStatus.expired);

      final rows = await allOutbox(app);
      expect(rows, hasLength(2));
      expect(rows.every((r) => r.status == OutboxStatus.pending), isTrue);
      expect(
        await (app.db.select(app.db.measurements)
              ..where((t) => t.clientUuid.equals(uuid)))
            .getSingleOrNull(),
        isNotNull,
      );
      expect(await app.db.select(app.db.dprs).get(), hasLength(1));
      expect(await app.tokens.read(), isNull);
    });
  });

  group('client_updated_at', () {
    test('strictly increases per record, even within one second', () async {
      final uuid = await app.services.measurements.create(entryInput());
      final stamps = <DateTime>[];

      Future<void> capture() async {
        final row = await (app.db.select(app.db.measurements)
              ..where((t) => t.clientUuid.equals(uuid)))
            .getSingle();
        stamps.add(row.clientUpdatedAt!);
      }

      await capture();
      for (var i = 0; i < 4; i++) {
        await app.services.measurements.edit(
          uuid,
          entryInput(nos: '${i + 3}.000'),
        );
        await capture();
      }

      for (var i = 1; i < stamps.length; i++) {
        expect(stamps[i].isAfter(stamps[i - 1]), isTrue);
      }
    });

    test('moves past the server row even with a slow phone clock', () async {
      final uuid = await _seedReturnedEntry(app);
      app.clock.set(DateTime.utc(2020, 1, 1));

      await app.services.measurements.edit(
        uuid,
        entryInput(nos: '4.000', measuredOn: '2019-12-31'),
      );
      await app.sync();

      expect(app.api.measurements.single['nos'], '4.000');
    });

    test('a re-mark never reuses a stamp, so it cannot be a false duplicate',
        () async {
      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'present',
      );
      app.api.loseNextPushResponse();
      await app.services.sync.syncNow();
      expect(app.api.attendance.single['status'], 'present');

      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'absent',
      );
      await app.services.sync.syncNow();

      expect(app.api.attendance.single['status'], 'absent');
    });
  });

  group('attendance coalescing', () {
    test('rapid taps become one outbox item holding the last value', () async {
      for (final status in ['present', 'absent', 'half', 'present', 'absent']) {
        await app.services.attendance.mark(
          projectId: 1,
          workerId: 1,
          date: '2026-11-09',
          status: status,
        );
      }

      final rows = await allOutbox(app);
      expect(rows, hasLength(1));
      expect(jsonDecode(rows.single.payload)['status'], 'absent');

      await app.sync();
      expect(app.api.attendance, hasLength(1));
      expect(app.api.attendance.single['status'], 'absent');
    });

    test('a tap during a send queues a new row rather than changing the '
        'one in flight', () async {
      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'present',
      );
      final gate = app.api.hangNextPush();
      final run = app.services.sync.syncNow();
      await pumpEventQueue();

      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'absent',
      );
      expect(await allOutbox(app), hasLength(2));

      gate.complete();
      await run;

      await app.sync();
      expect(app.api.attendance.single['status'], 'absent');
      final rows = await allOutbox(app);
      expect(rows.every((r) => r.status == OutboxStatus.done), isTrue);
    });
  });

  group('push statuses', () {
    test('stale is done and logged', () async {
      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'present',
      );
      app.api.attendance.add({
        'id': 8,
        'client_uuid': 'other-device',
        'project_id': 1,
        'worker_id': 1,
        'attendance_date': '2026-11-09',
        'status': 'absent',
        'ot_hours': '0.0',
        'marked_by': 9,
        'client_updated_at': '2030-01-01T00:00:00+05:30',
        'updated_at': '2030-01-01T00:00:00+05:30',
      });

      await app.sync();

      final row = (await allOutbox(app)).single;
      expect(row.status, OutboxStatus.done);
      expect(row.resultStatus, 'stale');

      final log = await app.services.syncCenter.watchLog().first;
      expect(log.single.resultStatus, 'stale');
    });

    test('conflict, error and forbidden need attention and are not retried '
        'automatically', () async {
      await app.services.measurements.create(entryInput());
      await app.services.measurements.create(entryInput(nos: '3.000'));
      await app.services.measurements.create(entryInput(nos: '4.000'));

      final results = <String>['conflict', 'error', 'forbidden'];
      _scriptedResults(app.api, results);

      await app.sync();

      final rows = await allOutbox(app);
      expect(rows.map((r) => r.status), [
        OutboxStatus.attention,
        OutboxStatus.attention,
        OutboxStatus.attention,
      ]);
      expect(rows.map((r) => r.attentionCode), [
        AttentionCode.approved,
        AttentionCode.validation,
        AttentionCode.forbidden,
      ]);
      expect(rows[1].attentionReason, contains('Nos must be more than zero'));

      final calls = app.api.pushCalls.length;
      await app.sync();
      expect(app.api.pushCalls.length, calls);
    });

    test('an entry for a project taken away says so, and can be retried', () async {
      await app.services.measurements.create(entryInput());
      _scriptedResults(app.api, ['error_project']);

      await app.sync();

      final row = (await allOutbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.projectGone);
      expect(row.attentionReason, contains('no longer have access'));

      await app.services.syncCenter.retry(row.id);
      await app.sync();
      expect((await allOutbox(app)).single.status, OutboxStatus.done);
    });

    test('retry sends an attention item again', () async {
      final uuid = await app.services.measurements.create(entryInput());
      _scriptedResults(app.api, ['error']);
      await app.sync();

      final row = (await allOutbox(app)).single;
      expect(row.status, OutboxStatus.attention);

      await app.services.syncCenter.retry(row.id);
      await app.sync();

      expect(app.api.measurementByUuid(uuid), isNotNull);
      expect((await allOutbox(app)).single.status, OutboxStatus.done);
    });

    test('discard removes a record the server never saw', () async {
      final uuid = await app.services.measurements.create(entryInput());
      _scriptedResults(app.api, ['error']);
      await app.sync();

      await app.services.syncCenter.discard((await allOutbox(app)).single.id);

      expect(await allOutbox(app), isEmpty);
      expect(
        await (app.db.select(app.db.measurements)
              ..where((t) => t.clientUuid.equals(uuid)))
            .getSingleOrNull(),
        isNull,
      );
    });

    test('discard of an edit brings back the server copy', () async {
      final uuid = await app.services.measurements.create(entryInput());
      await app.sync();
      final serverRow = app.api.measurementByUuid(uuid);

      app.api.setServerStatus(serverRow, 'RETURNED');
      await app.sync();
      await app.services.measurements.edit(uuid, entryInput(nos: '7.000'));
      app.api.setServerStatus(serverRow, 'APPROVED');
      await app.sync();

      final row = (await allOutbox(app)).last;
      expect(row.status, OutboxStatus.attention);
      await app.services.syncCenter.discard(row.id);
      await app.sync();

      final local = await (app.db.select(app.db.measurements)
            ..where((t) => t.clientUuid.equals(uuid)))
          .getSingle();
      expect(local.nos, Qty.parse('2.000'));
      expect(local.status, DocStatus.approved);
    });

    test('a batch refused whole is split so one bad row cannot block the '
        'rest', () async {
      await app.services.measurements.create(entryInput());
      await app.services.measurements.create(entryInput(nos: '3.000'));
      app.api.failNextPush(
        const ValidationFailure('bad', errors: {'items.0.data': ['Invalid']}),
      );

      await app.sync();

      expect(app.api.measurements, hasLength(2));
    });

    test('a network failure leaves items pending', () async {
      await app.services.measurements.create(entryInput());
      app.api.failNextPush(const OfflineFailure());

      final result = await app.services.sync.syncNow();

      expect(result.outcome, SyncOutcome.offline);
      expect((await allOutbox(app)).single.status, OutboxStatus.pending);
    });

    test('a batch is at most 50 items', () async {
      await app.db.transaction(() async {
        for (var i = 0; i < 120; i++) {
          await app.services.measurements.create(entryInput());
        }
      });

      await app.sync();

      expect(app.api.measurements, hasLength(120));
      for (final call in app.api.pushCalls) {
        expect(call.length, lessThanOrEqualTo(50));
      }
    });
  });

  group('photos', () {
    late Directory temp;
    late DirectoryAttachmentStore store;
    late TestApp files;
    late String dprUuid;
    late File source;

    setUp(() async {
      temp = await Directory.systemTemp.createTemp('n360');
      store = DirectoryAttachmentStore(() async => temp.path);
      files = await TestApp.create(attachments: store);
      dprUuid = await files.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      source = File('${temp.path}/source.jpg')..writeAsBytesSync([1, 2, 3]);
    });

    tearDown(() async {
      await files.dispose();
      await temp.delete(recursive: true);
    });

    Future<DprPhotoRow> onlyPhoto() =>
        files.db.select(files.db.dprPhotos).getSingle();

    test('stores a relative path, resolved at use time', () async {
      await files.services.dpr.addPhoto(
        dprClientUuid: dprUuid,
        sourcePath: source.path,
      );

      final photo = await onlyPhoto();
      expect(photo.relativePath, isNotNull);
      expect(photo.relativePath!.startsWith('/'), isFalse);
      expect(await store.exists(photo.relativePath!), isTrue);
    });

    test('the file survives a failed upload and goes after success', () async {
      await files.services.dpr.addPhoto(
        dprClientUuid: dprUuid,
        sourcePath: source.path,
      );
      final path = (await onlyPhoto()).relativePath!;
      await files.services.dpr.send(dprUuid);

      files.api.failNextUpload(const OfflineFailure());
      final failed = await files.services.sync.syncNow();
      expect(failed.outcome, SyncOutcome.offline);
      expect(await store.exists(path), isTrue);

      await files.services.sync.syncNow();
      expect(await store.exists(path), isFalse);
      expect((await onlyPhoto()).serverId, isNotNull);
    });

    test('a lost upload response creates one server photo', () async {
      await files.services.dpr.addPhoto(
        dprClientUuid: dprUuid,
        sourcePath: source.path,
      );
      await files.services.dpr.send(dprUuid);

      files.api.loseNextUploadResponse();
      await files.services.sync.syncNow();
      await files.services.sync.syncNow();

      expect(files.api.uploads, hasLength(1));
      expect(files.api.dprByUuid(dprUuid)['photos'], hasLength(1));
      expect(await files.db.select(files.db.dprPhotos).get(), hasLength(1));
    });

    test('a missing file is attention, and blocks the submit', () async {
      await files.services.dpr.addPhoto(
        dprClientUuid: dprUuid,
        sourcePath: source.path,
      );
      await files.services.dpr.send(dprUuid);
      await store.delete((await onlyPhoto()).relativePath!);

      await files.services.sync.syncNow();

      final rows = await allOutbox(files);
      expect(rows[1].attentionCode, AttentionCode.fileMissing);
      expect(rows[2].status, OutboxStatus.pending);
      expect(files.api.dprByUuid(dprUuid)['status'], 'DRAFT');
    });

    test('a 404 parent is retried later, not failed', () async {
      await files.services.dpr.addPhoto(
        dprClientUuid: dprUuid,
        sourcePath: source.path,
      );
      await files.services.dpr.send(dprUuid);
      files.api.failNextUpload(
        const ServerFailure('Parent not found.', statusCode: 404),
      );

      await files.services.sync.syncNow();

      final rows = await allOutbox(files);
      expect(rows[1].status, OutboxStatus.pending);

      await files.services.sync.syncNow();
      expect((await allOutbox(files))[1].status, OutboxStatus.done);
    });

    test('an approved parent is attention', () async {
      await files.services.dpr.addPhoto(
        dprClientUuid: dprUuid,
        sourcePath: source.path,
      );
      await files.services.dpr.send(dprUuid);
      files.api.failNextUpload(
        const ServerFailure('Approved.', statusCode: 409),
      );

      await files.services.sync.syncNow();

      final rows = await allOutbox(files);
      expect(rows[1].status, OutboxStatus.attention);
      expect(rows[1].attentionCode, AttentionCode.approved);
    });
  });

  group('status stream', () {
    test('carries counts, phase and last synced time', () async {
      await app.services.measurements.create(entryInput());

      final statuses = <SyncStatus>[];
      final subscription = app.services.sync.status.listen(statuses.add);
      addTearDown(subscription.cancel);

      await pumpEventQueue();
      expect(statuses.last.pendingCount, 1);
      expect(statuses.last.isSyncing, isFalse);

      await app.services.sync.syncNow();
      await pumpEventQueue();

      expect(statuses.any((s) => s.phase == SyncPhase.pushing), isTrue);
      expect(statuses.last.pendingCount, 0);
      expect(statuses.last.lastSyncedAt, isNotNull);
    });
  });
}

Future<String> _seedReturnedEntry(TestApp app) async {
  app.api.measurements.add({
    'id': 55,
    'client_uuid': null,
    'project_id': 1,
    'boq_item_id': 1,
    'work_order_id': null,
    'measured_on': '2026-11-08',
    'description': null,
    'location': null,
    'nos': '1.000',
    'length': '2.000',
    'breadth': null,
    'depth': null,
    'is_deduction': false,
    'qty': '2.000',
    'status': 'RETURNED',
    'deviation': false,
    'remarks': null,
    'return_remark': 'Check depth',
    'created_by': 4,
    'client_updated_at': null,
    'updated_at': app.api.stampNow(),
  });
  await app.sync();
  final row = (await app.db.select(app.db.measurements).get()).single;
  return row.clientUuid;
}

/// The next push answers with one scripted status per item.
void _scriptedResults(FakeApi api, List<String> statuses) {
  api.scriptPushStatuses(statuses);
}
