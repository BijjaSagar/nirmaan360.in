import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/data/attendance_repository.dart';

import '../../../support/test_app.dart';

const day = '2026-11-09';

void main() {
  late TestApp app;
  late AttendanceRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.attendance;
  });

  tearDown(() => app.dispose());

  Future<void> mark(int worker, String status, {Hours? ot}) => repo.mark(
    projectId: 1,
    workerId: worker,
    date: day,
    status: status,
    otHours: ot,
  );

  test('a day joins workers to marks', () async {
    await mark(1, 'present');

    final lines = await repo.watchDay(1, day).first;

    expect(lines, hasLength(2));
    expect(lines.first.worker.name, 'Ramesh Pawar');
    expect(lines.first.mark!.status, 'present');
    expect(lines.last.mark, isNull);
  });

  test('marks are per project and date', () async {
    await mark(1, 'present');
    expect((await repo.watchDay(1, '2026-11-08').first).first.mark, isNull);
    expect((await repo.watchDay(2, day).first).first.mark, isNull);
  });

  test('a deactivated worker still shows on days they were marked', () async {
    await mark(1, 'present');
    app.api.workers.first['is_active'] = false;
    app.api.workers.first['updated_at'] = app.api.stampNow();
    await app.sync();

    final today = await repo.watchDay(1, day).first;
    final other = await repo.watchDay(1, '2026-11-08').first;

    expect(today.where((l) => l.worker.id == 1), hasLength(1));
    expect(other.where((l) => l.worker.id == 1), isEmpty);
  });

  test('re-marking updates the one row, not a second', () async {
    await mark(1, 'present');
    await mark(1, 'absent');

    expect(await app.db.select(app.db.attendanceMarks).get(), hasLength(1));
  });

  test('markAllPresent marks only the unmarked', () async {
    await mark(1, 'absent');

    final added = await repo.markAllPresent(1, day);

    expect(added, 1);
    final lines = await repo.watchDay(1, day).first;
    expect(lines.first.mark!.status, 'absent');
    expect(lines.last.mark!.status, 'present');
  });

  test('day totals count statuses and sum overtime exactly', () async {
    await mark(1, 'overtime', ot: Hours.parse('1.5'));
    await mark(2, 'half', ot: Hours.parse('0.5'));

    final totals = await repo.watchDayTotals(1, day).first;

    expect(totals.overtime, 1);
    expect(totals.half, 1);
    expect(totals.unmarked, 0);
    expect(totals.marked, 2);
    expect(totals.otHours, Hours.parse('2.0'));
  });

  test('a future date is refused', () {
    expect(
      () => repo.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-10',
        status: 'present',
      ),
      throwsA(isA<ValidationFailure>()),
    );
  });

  group('add worker', () {
    test('is online only', () async {
      app.connectivity.setOnline(false);
      await expectLater(
        repo.addWorker(name: 'New', trade: 'Mason'),
        throwsA(isA<OfflineFailure>()),
      );
    });

    test('adds to the local list', () async {
      final worker = await repo.addWorker(name: 'New', trade: 'Mason');

      expect(worker.name, 'New');
      final lines = await repo.watchDay(1, day).first;
      expect(lines.map((l) => l.worker.name), contains('New'));
    });

    test('a retry after a lost response creates one worker', () async {
      final before = app.api.workers.length;
      app.api.offline = true;
      await expectLater(
        repo.addWorker(name: 'New', trade: 'Mason'),
        throwsA(isA<OfflineFailure>()),
      );
      app.api.offline = false;

      await repo.addWorker(name: 'New', trade: 'Mason');

      expect(app.api.workers.length, before + 1);
    });
  });
}
