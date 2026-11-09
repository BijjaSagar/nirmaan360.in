import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/home/data/home_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../../../support/test_app.dart';

const today = '2026-11-09';

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  Future<HomeSummary> summary() =>
      app.services.home.watchHomeSummary(1).first;

  test('an empty day', () async {
    final s = await summary();
    expect(s.dprState, DprDayState.notStarted);
    expect(s.attendanceTotal, 2);
    expect(s.attendanceMarked, 0);
    expect(s.measurementsToday, 0);
    expect(s.expensesToday, Money.zero);
    expect(s.pendingSync, 0);
  });

  test('tracks the DPR through its states', () async {
    final uuid = await app.services.dpr.saveDraft(
      const DprInput(projectId: 1, reportDate: today),
    );
    expect((await summary()).dprState, DprDayState.draft);

    await app.services.dpr.send(uuid);
    expect((await summary()).dprState, DprDayState.queued);

    await app.sync();
    expect((await summary()).dprState, DprDayState.inReview);

    app.api.setServerStatus(app.api.dprByUuid(uuid), 'APPROVED');
    await app.sync();
    expect((await summary()).dprState, DprDayState.approved);
  });

  test('counts attendance, measurements and sums expenses exactly', () async {
    await app.services.attendance.mark(
      projectId: 1,
      workerId: 1,
      date: today,
      status: 'present',
    );
    await app.services.attendance.mark(
      projectId: 1,
      workerId: 2,
      date: today,
      status: 'absent',
    );
    await app.services.measurements.create(
      MeasurementInput(
        projectId: 1,
        boqItemId: 1,
        measuredOn: today,
        nos: Qty.parse('1.000'),
      ),
    );
    for (final amount in ['0.10', '0.20', '100.05']) {
      await app.services.pettyCash.addExpense(
        walletId: 1,
        amount: Money.parse(amount),
        entryDate: today,
        category: 'Fuel',
      );
    }

    final s = await summary();

    expect(s.attendancePresent, 1);
    expect(s.attendanceMarked, 2);
    expect(s.measurementsToday, 1);
    expect(s.expensesToday.toWire(), '100.35');
    expect(s.pendingSync, 6);
  });

  test('counts what was sent back to this user', () async {
    final uuid = await app.services.measurements.create(
      MeasurementInput(
        projectId: 1,
        boqItemId: 1,
        measuredOn: today,
        nos: Qty.parse('1.000'),
      ),
    );
    await app.sync();
    app.api.setServerStatus(app.api.measurementByUuid(uuid), 'RETURNED');
    await app.sync();

    expect((await summary()).returnedToMe, 1);
  });

  test('updates live', () async {
    final seen = <int>[];
    final subscription = app.services.home
        .watchHomeSummary(1)
        .listen((s) => seen.add(s.pendingSync));
    addTearDown(subscription.cancel);
    await pumpEventQueue();

    await app.services.issues.raise(projectId: 1, title: 'Crack');
    await pumpEventQueue();

    expect(seen.last, 1);
  });
}
