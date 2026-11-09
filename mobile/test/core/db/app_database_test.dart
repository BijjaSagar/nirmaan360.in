import 'dart:io';

import 'package:drift/drift.dart' hide isNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/db/connection.dart';
import 'package:nirmaan360/core/db/outbox_dao.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../../support/test_app.dart';

void main() {
  group('schema', () {
    late AppDatabase db;

    setUp(() => db = newTestDatabase());
    tearDown(() => db.close());

    test('decimal columns round-trip exactly as wire text', () async {
      await db.into(db.wallets).insert(
        WalletsCompanion.insert(
          id: const Value(1),
          projectId: 1,
          name: 'Main',
          balance: Money.parse('12500.05'),
        ),
      );

      expect((await db.select(db.wallets).getSingle()).balance.toWire(), '12500.05');

      final raw = await db.customSelect('SELECT balance FROM wallets').getSingle();
      expect(raw.read<String>('balance'), '12500.05');
    });

    test('the indexes screens depend on exist', () async {
      final rows = await db
          .customSelect("SELECT name FROM sqlite_master WHERE type = 'index'")
          .get();
      final names = rows.map((r) => r.read<String>('name')).toSet();

      expect(
        names,
        containsAll([
          'boq_items_project_sort',
          'dprs_project_date',
          'dpr_photos_dpr',
          'measurements_project_status_date',
          'issues_project_status',
          'attendance_project_date',
          'petty_wallet_date',
          'outbox_status_id',
          'outbox_group',
        ]),
      );
    });

    test('one mark per worker per date is enforced', () async {
      AttendanceMarksCompanion mark(String uuid) =>
          AttendanceMarksCompanion.insert(
            clientUuid: uuid,
            projectId: 1,
            workerId: 1,
            attendanceDate: '2026-11-09',
            status: 'present',
            otHours: Hours.zero,
          );

      await db.into(db.attendanceMarks).insert(mark('a'));
      await expectLater(
        db.into(db.attendanceMarks).insert(mark('b')),
        throwsA(anything),
      );
    });
  });

  group('one transaction per user action', () {
    test('a failed outbox write leaves no local row', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.db.customStatement('DROP TABLE outbox');

      await expectLater(
        app.services.measurements.create(
          MeasurementInput(
            projectId: 1,
            boqItemId: 1,
            measuredOn: '2026-11-09',
            nos: Qty.parse('1.000'),
          ),
        ),
        throwsA(anything),
      );

      expect(await app.db.select(app.db.measurements).get(), isEmpty);
    });

    test('concurrent taps on one worker stay one row and one item', () async {
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await Future.wait([
        for (var i = 0; i < 40; i++)
          app.services.attendance.mark(
            projectId: 1,
            workerId: 1,
            date: '2026-11-09',
            status: i.isEven ? 'present' : 'absent',
          ),
      ]);

      expect(await app.db.select(app.db.attendanceMarks).get(), hasLength(1));
      expect(await app.db.select(app.db.outbox).get(), hasLength(1));
    });
  });

  group('on disk', () {
    late Directory temp;
    late File file;

    setUp(() async {
      temp = await Directory.systemTemp.createTemp('n360db');
      file = File('${temp.path}/app.sqlite');
    });

    tearDown(() => temp.delete(recursive: true));

    AppDatabase open() =>
        AppDatabase.forTesting(NativeDatabase(file, setup: configureSqlite));

    test('uses WAL and full durability', () async {
      final db = open();
      addTearDown(db.close);

      final mode = await db.customSelect('PRAGMA journal_mode').getSingle();
      final sync = await db.customSelect('PRAGMA synchronous').getSingle();

      expect(mode.read<String>('journal_mode'), 'wal');
      expect(sync.read<int>('synchronous'), 2);
    });

    test('unsent work survives closing and reopening', () async {
      final first = open();
      await first.transaction(() async {
        await first.into(first.issues).insert(
          IssuesCompanion.insert(
            clientUuid: 'i1',
            projectId: 1,
            title: 'Crack',
          ),
        );
        await OutboxDao(first).enqueue(
          type: OutboxType.issue,
          wireUuid: 'i1',
          recordUuid: 'i1',
          groupUuid: 'i1',
          projectId: 1,
          data: const {'title': 'Crack'},
          createdAt: DateTime.utc(2026, 11, 9),
        );
      });
      await first.close();

      final second = open();
      addTearDown(second.close);

      expect(await second.select(second.issues).get(), hasLength(1));
      expect(
        (await second.select(second.outbox).getSingle()).status,
        OutboxStatus.pending,
      );
    });
  });
}
