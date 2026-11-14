import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';

import 'field_test_support.dart';

Future<List<OutboxRow>> _outbox(TestApp app) {
  return (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();
}

/// Statuses on the day by worker id, read straight from the table: awaiting a
/// watch stream inside a widget test's fake-async zone would hang.
Future<Map<int, String>> _marks(TestApp app) async {
  final rows = await app.db.select(app.db.attendanceMarks).get();
  return {for (final r in rows) r.workerId: r.status};
}

/// Taps the given segment of the first worker's row (0 = P, 3 = A).
Future<void> tapSegment(WidgetTester tester, int rowIndex, int segment) async {
  final Finder row = find.byType(HajiriSegment).at(rowIndex);
  final Rect rect = tester.getRect(row);
  await tester.tapAt(Offset(rect.left + rect.width * (segment + 0.5) / 4, rect.center.dy));
  await tester.pump();
}

void main() {
  testWidgets('tap writes one item; repeated taps coalesce; totals move', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
    await pumpDrift(tester);

    expect(find.text('Ramesh Pawar'), findsOneWidget);
    expect(find.byType(HajiriSegment), findsWidgets);
    expect(find.text('All present (2)'), findsOneWidget);

    await tapSegment(tester, 0, 0);
    await pumpDrift(tester);
    expect(await _outbox(app), hasLength(1));

    // Second and third taps on the same worker stay one outbox row.
    await tapSegment(tester, 0, 1);
    await pumpDrift(tester);
    await tapSegment(tester, 0, 3);
    await pumpDrift(tester);
    expect(await _outbox(app), hasLength(1));

    // The left counter fell; All present now offers only the unmarked worker.
    expect(find.text('All present (1)'), findsOneWidget);
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('All present leaves an absent mark alone', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
    await pumpDrift(tester);

    await tapSegment(tester, 0, 3); // Ramesh absent.
    await pumpDrift(tester);

    await tester.tap(find.text('All present (1)'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 600));
    await settleToasts(tester);

    final marks = await _marks(app);
    expect(marks[1], 'absent');
    expect(marks[2], 'present');
    expect(find.text('Everyone is marked'), findsOneWidget);
  });

  testWidgets('OT shows the hours control without a dialog', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
    await pumpDrift(tester);

    await tapSegment(tester, 0, 2); // OT.
    await pumpDrift(tester);

    expect(find.bySemanticsLabel(RegExp('Overtime hours')), findsOneWidget);
    expect((await _marks(app))[1], 'overtime');
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('add worker sheet says plainly when offline', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
    await pumpDrift(tester);

    await tester.tap(find.text('Add worker'));
    await tester.pumpAndSettle();

    expect(find.text('Adding a worker needs network'), findsOneWidget);
    await tester.pump(const Duration(seconds: 1));
  });
}
