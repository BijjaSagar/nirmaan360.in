import 'dart:io';
import 'dart:math';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/clock.dart';
import 'package:nirmaan360/core/sync/sync_scheduler.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../../support/test_app.dart';

MeasurementInput entry({String nos = '2.000'}) => MeasurementInput(
  projectId: 1,
  boqItemId: 1,
  measuredOn: '2026-11-09',
  nos: Qty.parse(nos),
);

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)]))
        .get();

/// Behaviour pinned to app/Services/SyncService.php.
void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  group('retry of a version already applied', () {
    test('a lost dpr response is duplicate even after the row moved to '
        'IN_REVIEW', () async {
      final uuid = await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      await app.services.dpr.send(uuid);

      app.api.loseNextPushResponse();
      await app.services.sync.syncNow();
      expect(app.api.dprs, hasLength(1));

      // The first attempt landed, and the document has since gone for review.
      app.api.setServerStatus(app.api.dprByUuid(uuid), 'IN_REVIEW');

      final result = await app.services.sync.syncNow();
      expect(result.isSuccess, isTrue);

      final rows = await outbox(app);
      expect(rows.first.resultStatus, 'duplicate');
      expect(rows.every((r) => r.status == OutboxStatus.done), isTrue);
      expect(app.api.dprs, hasLength(1));
    });

    test('a real edit against an approved row is still a conflict', () async {
      final uuid = await app.services.measurements.create(entry());
      await app.sync();
      final row = app.api.measurementByUuid(uuid);
      app.api.setServerStatus(row, 'RETURNED');
      await app.sync();

      await app.services.measurements.edit(uuid, entry(nos: '5.000'));
      app.api.setServerStatus(row, 'APPROVED');
      await app.sync();

      final last = (await outbox(app)).last;
      expect(last.status, OutboxStatus.attention);
      expect(last.attentionCode, AttentionCode.approved);
    });
  });

  group('issue_status', () {
    test('compares against the stored stamp, so a status changed offline is '
        'not stale once the issue itself has synced', () async {
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Crack in beam',
      );
      await app.services.issues.changeStatus(uuid, 'in_progress');

      await app.sync();

      final rows = await outbox(app);
      expect(rows.map((r) => r.resultStatus), ['created', 'updated']);
      expect(app.api.issueByUuid(uuid)['status'], 'in_progress');
    });

    test('a lost response is duplicate', () async {
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Crack',
      );
      await app.sync();
      await app.services.issues.changeStatus(uuid, 'resolved');

      app.api.loseNextPushResponse();
      await app.services.sync.syncNow();
      await app.services.sync.syncNow();

      expect((await outbox(app)).last.resultStatus, 'duplicate');
    });

    test('a web edit decides by updated_at', () async {
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Crack',
      );
      await app.sync();
      await app.services.issues.changeStatus(uuid, 'resolved');

      // The web edited the issue after this phone's stamp.
      app.clock.set(DateTime.utc(2026, 11, 9, 4, 30));
      app.api.editOnWeb(app.api.issueByUuid(uuid), {'status': 'closed'});
      await app.sync();

      expect(app.api.issueByUuid(uuid)['status'], 'closed');
      expect((await outbox(app)).last.resultStatus, 'stale');
    });
  });

  group('deleted', () {
    test('a measurement draft deleted on the web disappears locally',
        () async {
      final uuid = await app.services.measurements.create(entry());
      await app.sync();
      expect(await app.db.select(app.db.measurements).get(), hasLength(1));

      app.api.deleteDraftOnWeb(app.api.measurementByUuid(uuid));
      await app.sync();

      expect(await app.db.select(app.db.measurements).get(), isEmpty);
    });

    test('a local row with an unsent item is never deleted', () async {
      final uuid = await app.services.measurements.create(entry());
      await app.sync();
      final row = app.api.measurementByUuid(uuid);

      // The edit is unsent when the pull carrying the deletion is applied.
      app.api.deleteDraftOnWeb(row);
      app.api.beforeNextPull = () =>
          app.services.measurements.edit(uuid, entry(nos: '6.000'));
      await app.sync();

      final local = await app.db.select(app.db.measurements).getSingle();
      expect(local.nos, Qty.parse('6.000'));
      expect((await outbox(app)).last.status, OutboxStatus.pending);

      // Its push brings the entry back rather than losing the work.
      await app.sync();
      expect(app.api.measurements, hasLength(1));
      expect(app.api.measurements.single['nos'], '6.000');
    });

    test('a soft-deleted DPR answers duplicate, and goes once its queue '
        'is cleared', () async {
      final uuid = await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      await app.services.dpr.send(uuid);
      await app.sync();
      final row = app.api.dprByUuid(uuid);
      app.api.setServerStatus(row, 'RETURNED');
      await app.sync();

      // Deleted on the web while the engineer is correcting it offline.
      app.api.softDeleteOnWeb('dprs', row);
      app.api.beforeNextPull = () async {
        await app.services.dpr.editReturned(
          uuid,
          const DprInput(
            projectId: 1,
            reportDate: '2026-11-09',
            summary: 'Corrected',
          ),
        );
        await app.services.dpr.send(uuid);
      };
      await app.sync();
      expect(await app.db.select(app.db.dprs).get(), hasLength(1));

      await app.sync();

      final rows = await outbox(app);
      expect(rows[rows.length - 2].resultStatus, 'duplicate');
      final submit = rows.last;
      expect(submit.type, OutboxType.dprSubmit);
      expect(submit.status, OutboxStatus.attention);
      expect(await app.db.select(app.db.dprs).get(), hasLength(1));

      // Nothing can bring it back, so the sync screen clears the item.
      await app.services.syncCenter.discard(submit.id);
      await app.sync();

      expect(await app.db.select(app.db.dprs).get(), isEmpty);
    });

    test('a status change on a soft-deleted issue is attention, and the '
        'local copy goes once it is discarded', () async {
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Crack',
      );
      await app.sync();

      app.api.softDeleteOnWeb('issues', app.api.issueByUuid(uuid));
      app.api.beforeNextPull = () =>
          app.services.issues.changeStatus(uuid, 'resolved');
      await app.sync();
      expect(await app.db.select(app.db.issues).get(), hasLength(1));

      await app.sync();
      final row = (await outbox(app)).last;
      expect(row.status, OutboxStatus.attention);
      expect(await app.db.select(app.db.issues).get(), hasLength(1));

      await app.services.syncCenter.discard(row.id);
      await app.sync();

      expect(await app.db.select(app.db.issues).get(), isEmpty);
    });

    test('a restored row is kept', () async {
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Crack',
      );
      await app.sync();
      final row = app.api.issueByUuid(uuid);

      app.api.softDeleteOnWeb('issues', row);
      app.api.beforeNextPull = () =>
          app.services.issues.changeStatus(uuid, 'resolved');
      await app.sync();

      row['deleted_at'] = null;
      row['updated_at'] = app.api.stampNow();
      await app.sync();
      await app.sync();

      expect(await app.db.select(app.db.issues).get(), hasLength(1));
    });
  });

  group('full refresh', () {
    test('a stale result re-reads the row without losing later deletions',
        () async {
      final kept = await app.services.measurements.create(entry());
      final removed = await app.services.measurements.create(entry(nos: '3.000'));
      await app.sync();
      final since = await app.db.readMeta(MetaKey.watermark);

      final keptRow = app.api.measurementByUuid(kept);
      app.api.editOnWeb(keptRow, {'nos': '8.000', 'qty': '8.000'});
      app.api.deleteDraftOnWeb(app.api.measurementByUuid(removed));
      await app.services.measurements.edit(kept, entry(nos: '4.000'));

      // The phone's stamp is older than the web edit, so the push is stale.
      await app.sync();

      expect((await outbox(app)).last.resultStatus, 'stale');
      final local = await app.db.select(app.db.measurements).get();
      expect(local, hasLength(1), reason: 'the deletion was applied');
      expect(local.single.nos, Qty.parse('8.000'), reason: 'server copy back');
      expect(app.api.pullCalls.reversed.take(2), [isNull, since]);
    });

    test('the watermark stays at the delta pull', () async {
      final uuid = await app.services.measurements.create(entry());
      await app.sync();
      await app.services.measurements.edit(uuid, entry(nos: '4.000'));
      app.api.editOnWeb(app.api.measurementByUuid(uuid), {'nos': '8.000'});

      await app.sync();
      final watermark = await app.db.readMeta(MetaKey.watermark);
      await app.sync();

      expect(app.api.pullCalls.last, watermark);
    });
  });

  group('pull figures', () {
    test('a first pull takes the ledger from boq_items', () async {
      final fresh = FakeApi();
      fresh.seedDemo();
      fresh.measurements.add({
        'id': 9,
        'client_uuid': null,
        'project_id': 1,
        'boq_item_id': 1,
        'work_order_id': null,
        'measured_on': '2026-11-01',
        'description': null,
        'location': null,
        'nos': '1.000',
        'length': '20.000',
        'breadth': null,
        'depth': null,
        'is_deduction': false,
        'qty': '20.000',
        'status': 'APPROVED',
        'deviation': false,
        'remarks': null,
        'return_remark': null,
        'created_by': 4,
        'client_updated_at': null,
        'updated_at': fresh.stampNow(),
      });
      final other = await TestApp.create(api: fresh, seed: false);
      addTearDown(other.dispose);
      await other.sync();

      final body = await fresh.pull();
      expect(body['boq_balances'], isEmpty);
      expect((body['deleted'] as Map)['measurements'], isEmpty);

      final item = await other.db.select(other.db.boqItems).getSingle();
      expect(item.measuredQty, Qty.parse('20.000'));
      expect(item.approvedQty, Qty.parse('20.000'));
      expect(item.balanceQty, Qty.parse('600.000'));
    });

    test('a later pull updates the ledger from boq_balances', () async {
      await app.services.measurements.create(entry(nos: '5.000'));
      await app.sync();

      final item = await app.db.select(app.db.boqItems).getSingle();
      expect(item.measuredQty, Qty.parse('5.000'));
      expect(item.balanceQty, Qty.parse('615.000'));
    });
  });

  group('forbidden', () {
    test('carries the permission reason', () async {
      (app.api.user['can'] as Map)['measurement.create'] = false;
      await app.services.measurements.create(entry());

      await app.sync();

      final row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.forbidden);
    });

    test('carries the plan feature reason', () async {
      ((app.api.user['organization'] as Map)['features'] as List)
          .remove('measurement');
      await app.services.measurements.create(entry());

      await app.sync();

      final row = (await outbox(app)).single;
      expect(row.attentionCode, AttentionCode.planFeature);
      expect(row.attentionReason, contains('plan'));
    });
  });

  group('future dates', () {
    test('the app refuses a future measured_on before queuing', () async {
      expect(
        () => app.services.measurements.create(
          MeasurementInput(
            projectId: 1,
            boqItemId: 1,
            measuredOn: '2026-11-10',
            nos: Qty.parse('1.000'),
          ),
        ),
        throwsA(isA<ValidationFailure>()),
      );
      expect(await outbox(app), isEmpty);
    });

    test('the fake server refuses one that gets past the app', () async {
      await app.services.sync.syncNow();
      app.clock.set(DateTime.utc(2027, 1, 1));
      await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 1,
          measuredOn: '2026-12-31',
          nos: Qty.parse('1.000'),
        ),
      );

      await app.sync();

      final row = (await outbox(app)).single;
      expect(row.attentionCode, AttentionCode.validation);
      expect(app.api.measurements, isEmpty);
    });
  });

  group('rate limiting', () {
    late Directory temp;
    late DirectoryAttachmentStore store;
    late TestApp files;
    late File source;

    setUp(() async {
      temp = await Directory.systemTemp.createTemp('n360');
      store = DirectoryAttachmentStore(() async => temp.path);
      files = await TestApp.create(attachments: store);
      source = File('${temp.path}/source.jpg')..writeAsBytesSync([1, 2, 3]);
    });

    tearDown(() async {
      await files.dispose();
      await temp.delete(recursive: true);
    });

    Future<String> queuedReportWithPhoto() async {
      final uuid = await files.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      await files.services.dpr.addPhoto(
        dprClientUuid: uuid,
        sourcePath: source.path,
      );
      await files.services.dpr.send(uuid);
      return uuid;
    }

    test('a 429 on an upload leaves it pending, never attention', () async {
      await queuedReportWithPhoto();
      files.api.failNextUpload(
        const RateLimited(retryAfter: Duration(seconds: 30)),
      );

      final result = await files.services.sync.syncNow();

      expect(result.outcome, SyncOutcome.rateLimited);
      expect(result.retryAfter, const Duration(seconds: 30));

      final rows = await outbox(files);
      expect(rows[1].status, OutboxStatus.pending);
      expect(rows.where((r) => r.status == OutboxStatus.attention), isEmpty);
    });

    test('a 429 on push leaves items pending', () async {
      await files.services.measurements.create(entry());
      files.api.failNextPush(const RateLimited(retryAfter: Duration(seconds: 5)));

      final result = await files.services.sync.syncNow();

      expect(result.outcome, SyncOutcome.rateLimited);
      expect((await outbox(files)).single.status, OutboxStatus.pending);
    });

    test('nothing is sent until Retry-After has passed', () async {
      final uuid = await queuedReportWithPhoto();
      files.api.failNextUpload(
        const RateLimited(retryAfter: Duration(seconds: 30)),
      );
      await files.services.sync.syncNow();
      final uploadsBefore = files.api.uploadCalls.length;
      final pullsBefore = files.api.pullCalls.length;

      files.clock.advance(const Duration(seconds: 29));
      final tooSoon = await files.services.sync.syncNow();

      expect(tooSoon.outcome, SyncOutcome.rateLimited);
      expect(tooSoon.retryAfter, const Duration(seconds: 1));
      expect(files.api.uploadCalls.length, uploadsBefore);
      expect(files.api.pullCalls.length, pullsBefore);

      files.clock.advance(const Duration(seconds: 1));
      final after = await files.services.sync.syncNow();

      expect(after.isSuccess, isTrue);
      expect(files.api.uploads, hasLength(1));
      expect(files.api.dprByUuid(uuid)['status'], 'IN_REVIEW');
    });

    test('the file is kept across a 429', () async {
      await queuedReportWithPhoto();
      final photo = await files.db.select(files.db.dprPhotos).getSingle();
      files.api.failNextUpload(const RateLimited());

      await files.services.sync.syncNow();

      expect(await store.exists(photo.relativePath!), isTrue);
    });

    test('repeated 429s never use up the missing-parent allowance', () async {
      await queuedReportWithPhoto();

      for (var i = 0; i < 25; i++) {
        files.api.failNextUpload(
          const RateLimited(retryAfter: Duration(seconds: 1)),
        );
        await files.services.sync.syncNow();
        files.clock.advance(const Duration(seconds: 2));
      }

      files.api.failNextUpload(
        const ServerFailure('Parent not found.', statusCode: 404),
      );
      await files.services.sync.syncNow();

      expect((await outbox(files))[1].status, OutboxStatus.pending);
    });

    test('with no Retry-After the wait is the rate window', () async {
      await queuedReportWithPhoto();
      files.api.failNextUpload(const RateLimited());

      final result = await files.services.sync.syncNow();

      expect(result.retryAfter, const Duration(seconds: 60));
    });

    test('the scheduler never retries before Retry-After', () {
      final scheduler = SyncScheduler(
        db: files.db,
        engine: files.services.sync,
        connectivity: files.connectivity,
        random: Random(7),
        observeLifecycle: false,
      );
      const result = SyncRunResult(
        SyncOutcome.rateLimited,
        retryAfter: Duration(seconds: 30),
      );

      for (var i = 0; i < 200; i++) {
        final delay = scheduler.delayAfter(result);
        expect(delay, greaterThanOrEqualTo(const Duration(seconds: 30)));
      }
    });
  });

  group('uploads', () {
    test('an upload already stored stays duplicate on an approved parent',
        () async {
      final temp = await Directory.systemTemp.createTemp('n360');
      addTearDown(() => temp.delete(recursive: true));
      final store = DirectoryAttachmentStore(() async => temp.path);
      final files = await TestApp.create(attachments: store);
      addTearDown(files.dispose);

      final uuid = await files.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      final source = File('${temp.path}/s.jpg')..writeAsBytesSync([1]);
      await files.services.dpr.addPhoto(
        dprClientUuid: uuid,
        sourcePath: source.path,
      );
      await files.services.dpr.send(uuid);

      files.api.loseNextUploadResponse();
      await files.services.sync.syncNow();
      files.api.setServerStatus(files.api.dprByUuid(uuid), 'APPROVED');

      await files.services.sync.syncNow();

      final rows = await outbox(files);
      expect(rows[1].resultStatus, 'duplicate');
      expect(rows[1].status, OutboxStatus.done);
    });
  });

  test('today uses India time for the future-date check', () {
    // 19:00 UTC on the 9th is already the 10th in India.
    expect(istDate(DateTime.utc(2026, 11, 9, 19)), '2026-11-10');
  });
}
