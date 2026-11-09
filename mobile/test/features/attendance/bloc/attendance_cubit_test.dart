import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart' show HajiriValue;
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/bloc/add_worker_cubit.dart';
import 'package:nirmaan360/features/attendance/bloc/attendance_cubit.dart';

import '../view/field_test_support.dart';

Future<List<OutboxRow>> _outbox(TestApp app) {
  return (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();
}

Future<AttendanceCubit> _readyCubit(TestApp app) async {
  final cubit = AttendanceCubit(app.services);
  await cubit.stream.firstWhere((s) => s.load == AttendanceLoad.ready);
  return cubit;
}

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  test('a tap writes one outbox item and shows immediately', () async {
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);

    final Future<void> write = cubit.mark(1, HajiriValue.present);
    // Optimistic: visible before the database write finishes.
    expect(cubit.state.marks[1]?.value, HajiriValue.present);
    await write;

    final rows = await _outbox(app);
    expect(rows, hasLength(1));
    expect(rows.single.type, OutboxType.attendance);
    final payload = jsonDecode(rows.single.payload) as Map<String, dynamic>;
    expect(payload['status'], 'present');
    expect(payload['worker_id'], 1);
  });

  test('a tap leaves the roster untouched, so the list does not rebuild', () async {
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);
    final before = cubit.state.groups;

    await cubit.mark(1, HajiriValue.present);
    // Let the database report the write back as well.
    await Future<void>.delayed(const Duration(milliseconds: 50));

    expect(cubit.state.marks[1]?.value, HajiriValue.present);
    expect(identical(cubit.state.groups, before), isTrue);
  });

  test('repeated taps coalesce into one item with the last value', () async {
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);

    await cubit.mark(1, HajiriValue.present);
    await cubit.mark(1, HajiriValue.half);
    await cubit.mark(1, HajiriValue.absent);

    final rows = await _outbox(app);
    expect(rows, hasLength(1));
    final payload = jsonDecode(rows.single.payload) as Map<String, dynamic>;
    expect(payload['status'], 'absent');

    final day = await app.services.attendance.watchDay(1, cubit.state.date).first;
    expect(day.singleWhere((l) => l.worker.id == 1).mark!.status, 'absent');
  });

  test('all present fills unmarked workers and leaves an absent mark alone', () async {
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);

    await cubit.mark(1, HajiriValue.absent);
    await cubit.markAllPresent(animate: false);

    expect(cubit.state.marks[2]?.value, HajiriValue.present);

    final day = await app.services.attendance.watchDay(1, cubit.state.date).first;
    expect(day.singleWhere((l) => l.worker.id == 1).mark!.status, 'absent');
    expect(day.singleWhere((l) => l.worker.id == 2).mark!.status, 'present');
    expect(await _outbox(app), hasLength(2));
  });

  test('the all-present wave reveals rows in turn and respects a mid-wave tap', () async {
    app.api.addWorker2(id: 3, name: 'Third Worker', trade: 'Mason');
    await app.sync();
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);
    if (cubit.state.workerCount < 3) {
      await cubit.stream.firstWhere((s) => s.workerCount == 3);
    }

    final Future<void> all = cubit.markAllPresent(animate: true);
    // Written straight away but held back on screen, so the rows can fill in turn.
    expect(cubit.state.marks, isEmpty);

    // A tap during the wave wins over the wave.
    await cubit.mark(3, HajiriValue.absent);
    await all;
    if (cubit.state.marks.length < 3) {
      await cubit.stream.firstWhere((s) => s.marks.length == 3);
    }

    expect(cubit.state.marks[1]?.value, HajiriValue.present);
    expect(cubit.state.marks[2]?.value, HajiriValue.present);
    expect(cubit.state.marks[3]?.value, HajiriValue.absent);

    final day = await app.services.attendance.watchDay(1, cubit.state.date).first;
    expect(day.singleWhere((l) => l.worker.id == 3).mark!.status, 'absent');
  });

  test('overtime defaults to two hours and can be adjusted', () async {
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);

    await cubit.mark(1, HajiriValue.overtime);
    expect(cubit.state.marks[1], DayMark(HajiriValue.overtime, Hours.fromInt(2)));

    await cubit.setOvertimeHours(1, Hours.parse('3.5'));
    expect(cubit.state.marks[1]?.otHours, Hours.parse('3.5'));

    final day = await app.services.attendance.watchDay(1, cubit.state.date).first;
    expect(day.singleWhere((l) => l.worker.id == 1).mark!.otHours, Hours.parse('3.5'));

    // Moving off overtime clears the hours.
    await cubit.mark(1, HajiriValue.present);
    expect(cubit.state.marks[1]?.otHours, Hours.zero);
  });

  test('a future date cannot be selected', () async {
    final cubit = await _readyCubit(app);
    addTearDown(cubit.close);
    final String today = cubit.state.today;

    cubit.selectDate('2099-01-01');

    expect(cubit.state.date, today);
    expect(cubit.state.recentDates.first, today);
  });

  test('add worker is refused offline with a clear problem', () async {
    app.connectivity.setOnline(false);
    final cubit = AddWorkerCubit(app.services);
    addTearDown(cubit.close);

    cubit.setName('New Mason');
    cubit.setTrade('Mason');
    cubit.next();
    expect(cubit.state.step, 1);
    await cubit.save();

    expect(cubit.state.problem, AddWorkerProblem.offline);
    expect(cubit.state.done, isFalse);
  });

  test('someone who may not see money adds a worker without a wage step', () async {
    final api = FakeApi();
    (api.user['can'] as Map)['money.view'] = false;
    final site = await TestApp.create(api: api);
    addTearDown(site.dispose);
    final cubit = AddWorkerCubit(site.services);
    addTearDown(cubit.close);

    cubit.setName('New Helper');
    cubit.setTrade('Helper');
    cubit.setWage('650');
    cubit.next();
    await Future<void>.delayed(const Duration(milliseconds: 50));

    expect(cubit.state.step, 0);
    expect(cubit.state.done, isTrue);
    expect(api.workers.where((w) => w['name'] == 'New Helper').single['daily_wage'], '0.00');
  });

  test('add worker online creates the worker and sends the wage', () async {
    final cubit = AddWorkerCubit(app.services);
    addTearDown(cubit.close);

    cubit.setName('New Mason');
    cubit.setTrade('Mason');
    cubit.next();
    cubit.setWage('650');
    await cubit.save();

    expect(cubit.state.done, isTrue);
    final added = app.api.workers.where((w) => w['name'] == 'New Mason');
    expect(added, hasLength(1));
    expect(added.single['daily_wage'], '650.00');
  });
}
