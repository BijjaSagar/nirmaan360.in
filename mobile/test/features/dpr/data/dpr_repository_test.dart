import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/failures.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';

import '../../../support/test_app.dart';

const today = '2026-11-09';

DprInput form({String? summary, List<ManpowerLine> manpower = const []}) =>
    DprInput(
      projectId: 1,
      reportDate: today,
      summary: summary,
      manpower: manpower,
    );

void main() {
  late TestApp app;
  late DprRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.dpr;
  });

  tearDown(() => app.dispose());

  Future<List<OutboxRow>> outbox() =>
      (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)]))
          .get();

  group('saveDraft', () {
    test('writes no outbox row', () async {
      await repo.saveDraft(form(summary: 'Pour'));
      expect(await outbox(), isEmpty);
    });

    test('reuses the open draft for the date', () async {
      final first = await repo.saveDraft(form(summary: 'One'));
      final second = await repo.saveDraft(form(summary: 'Two'));
      expect(second, first);
      expect((await app.db.select(app.db.dprs).get()).single.summary, 'Two');
    });

    test('survives a restart', () async {
      final uuid = await repo.saveDraft(form(summary: 'Pour'));
      final details = await repo.watchOne(uuid).first;
      expect(details!.dpr.summary, 'Pour');
      expect(details.dpr.localDraft, isTrue);
    });
  });

  group('copyManpowerFromYesterday', () {
    test('copies into the draft for the date', () async {
      await app.db.into(app.db.dprs).insert(
        DprsCompanion.insert(
          clientUuid: 'yesterday',
          projectId: 1,
          reportDate: '2026-11-08',
          manpower: const [ManpowerLine(trade: 'Mason', count: 12)],
          machinery: const [],
          workItems: const [],
        ),
      );
      final draft = await repo.saveDraft(form(summary: 'Keep me'));

      final copied = await repo.copyManpowerFromYesterday(1, today);

      expect(copied.single.trade, 'Mason');
      final row = (await repo.watchOne(draft).first)!.dpr;
      expect(row.manpower.single.count, 12);
      expect(row.summary, 'Keep me');
    });

    test('crosses a month boundary', () async {
      await app.db.into(app.db.dprs).insert(
        DprsCompanion.insert(
          clientUuid: 'oct31',
          projectId: 1,
          reportDate: '2026-10-31',
          manpower: const [ManpowerLine(trade: 'Helper', count: 3)],
          machinery: const [],
          workItems: const [],
        ),
      );

      final copied = await repo.copyManpowerFromYesterday(1, '2026-11-01');

      expect(copied.single.trade, 'Helper');
    });

    test('returns nothing when yesterday has no report', () async {
      expect(await repo.copyManpowerFromYesterday(1, today), isEmpty);
    });
  });

  group('photos', () {
    test('add and remove a draft photo', () async {
      final uuid = await repo.saveDraft(form());
      final photo = await repo.addPhoto(
        dprClientUuid: uuid,
        sourcePath: '/camera/one.jpg',
      );
      expect((await repo.watchOne(uuid).first)!.photos, hasLength(1));

      await repo.removePhoto(photo);

      expect((await repo.watchOne(uuid).first)!.photos, isEmpty);
    });

    test('removing a photo leaves its stored file', () async {
      final uuid = await repo.saveDraft(form());
      final photo = await repo.addPhoto(
        dprClientUuid: uuid,
        sourcePath: '/camera/one.jpg',
      );
      final path = (await repo.watchOne(uuid).first)!.photos.single.relativePath!;

      await repo.removePhoto(photo);

      expect(await app.attachments.exists(path), isTrue);
    });

    test('a queued photo cannot be removed', () async {
      final uuid = await repo.saveDraft(form());
      final photo = await repo.addPhoto(
        dprClientUuid: uuid,
        sourcePath: '/camera/one.jpg',
      );
      await repo.send(uuid);

      await expectLater(repo.removePhoto(photo), throwsA(isA<RecordLocked>()));
    });
  });

  group('send', () {
    test('queues dpr, photos and submit in one transaction', () async {
      final uuid = await repo.saveDraft(form());
      await repo.addPhoto(dprClientUuid: uuid, sourcePath: '/a.jpg');
      await repo.addPhoto(dprClientUuid: uuid, sourcePath: '/b.jpg');

      await repo.send(uuid);

      final rows = await outbox();
      expect(rows.map((r) => r.type), [
        OutboxType.dpr,
        OutboxType.dprPhoto,
        OutboxType.dprPhoto,
        OutboxType.dprSubmit,
      ]);
      expect(rows.map((r) => r.groupUuid).toSet(), {uuid});
    });

    test('a double tap queues once', () async {
      final uuid = await repo.saveDraft(form());
      await repo.send(uuid);
      await repo.send(uuid);
      expect(await outbox(), hasLength(2));
    });

    test('sends the contract field names', () async {
      final uuid = await repo.saveDraft(
        DprInput(
          projectId: 1,
          reportDate: today,
          weather: 'Clear',
          machinery: [MachineryLine(name: 'JCB', hours: Hours.parse('2.5'))],
          workItems: [WorkLine(boqItemId: 1, qty: Qty.parse('68'))],
        ),
      );
      await repo.send(uuid);

      final data = jsonDecode((await outbox()).first.payload) as Map;
      expect(data['report_date'], today);
      expect((data['machinery'] as List).single['hours'], '2.5');
      expect((data['work'] as List).single['qty'], '68.000');
    });

    test('a draft is no longer a draft once queued', () async {
      final uuid = await repo.saveDraft(form());
      await repo.send(uuid);
      expect((await repo.watchOne(uuid).first)!.dpr.localDraft, isFalse);
    });

    test('nothing can be edited after sending', () async {
      final uuid = await repo.saveDraft(form());
      await repo.send(uuid);
      await expectLater(
        repo.saveDraft(form(summary: 'Late'), clientUuid: uuid),
        throwsA(isA<RecordLocked>()),
      );
    });

    test('a RETURNED report can be edited and sent again', () async {
      final uuid = await repo.saveDraft(form(summary: 'First'));
      await repo.send(uuid);
      await app.sync();
      app.api.setServerStatus(
        app.api.dprByUuid(uuid),
        'RETURNED',
        returnRemark: 'Add manpower',
      );
      await app.sync();

      final returned = (await repo.watchOne(uuid).first)!.dpr;
      expect(returned.status, DocStatus.returned);
      expect(returned.returnRemark, 'Add manpower');

      await repo.editReturned(uuid, form(summary: 'Fixed'));
      await repo.send(uuid);
      await app.sync();

      expect(app.api.dprs, hasLength(1));
      expect(app.api.dprByUuid(uuid)['summary'], 'Fixed');
      expect(app.api.dprByUuid(uuid)['status'], 'IN_REVIEW');
    });

    test('only a RETURNED report can use editReturned', () async {
      final uuid = await repo.saveDraft(form());
      await expectLater(
        repo.editReturned(uuid, form()),
        throwsA(isA<RecordLocked>()),
      );
    });
  });

  group('watch', () {
    test('list carries the sync badge', () async {
      final uuid = await repo.saveDraft(form());
      await repo.send(uuid);

      final list = await repo.watchList(1).first;

      expect(list.single.syncState, LocalSyncState.queued);
    });

    test('list updates live', () async {
      final seen = <int>[];
      final subscription = repo.watchList(1).listen((l) => seen.add(l.length));
      addTearDown(subscription.cancel);
      await pumpEventQueue();

      await repo.saveDraft(form());
      await pumpEventQueue();

      expect(seen.last, 1);
    });

    test('photoBytes falls back to the server once uploaded', () async {
      final uuid = await repo.saveDraft(form());
      await repo.addPhoto(dprClientUuid: uuid, sourcePath: '/a.jpg');
      await repo.send(uuid);
      await app.sync();

      final photo = (await repo.watchOne(uuid).first)!.photos.single;
      expect(photo.relativePath, isNull);

      expect(await repo.photoBytes(photo), [1, 2, 3]);
    });

    test('a photo the server added arrives under a stable uuid', () async {
      final uuid = await repo.saveDraft(form());
      await repo.send(uuid);
      await app.sync();

      final row = app.api.dprByUuid(uuid);
      (row['photos'] as List).add({'id': 900, 'caption': 'From web'});
      row['updated_at'] = app.api.stampNow();
      await app.sync();
      await app.sync();

      final photos = (await repo.watchOne(uuid).first)!.photos;
      expect(photos.single.serverId, 900);
      expect(photos.single.caption, 'From web');
    });
  });
}
