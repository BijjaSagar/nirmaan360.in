import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_form_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_list_screen.dart';

import '../../../support/test_app.dart';
import 'dpr_test_support.dart';

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create(seed: false, api: demoApi());
    await app.sync();
  });

  tearDown(() => app.dispose());

  Future<void> show(WidgetTester tester) async {
    usePhone(tester);
    await tester.pumpWidget(hostApp(app.services, const DprListScreen()));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 500));
  }

  Future<String> report(String date, {int workers = 3}) {
    return app.services.dpr.saveDraft(
      DprInput(
        projectId: 1,
        reportDate: date,
        manpower: [ManpowerLine(trade: 'Mason', count: workers)],
      ),
    );
  }

  testWidgets('an empty book says so and offers today', (tester) async {
    await show(tester);
    expect(find.byType(EmptyLedger), findsOneWidget);
    expect(find.text('No reports yet'), findsOneWidget);
    expect(find.text("Write today's report"), findsOneWidget);
  });

  testWidgets('newest first, with totals and a stamp on every row', (tester) async {
    await report('2026-11-07', workers: 8);
    await report('2026-11-08', workers: 12);
    await show(tester);

    final Offset newer = tester.getTopLeft(find.text('Sun, 8 Nov'));
    final Offset older = tester.getTopLeft(find.text('Sat, 7 Nov'));
    expect(newer.dy, lessThan(older.dy));

    expect(find.textContaining('12 workers'), findsOneWidget);
    expect(find.byType(RubberStamp), findsNWidgets(2));
  });

  testWidgets('reports are grouped by month in one list', (tester) async {
    await report(today);
    await report('2026-10-31');
    await show(tester);

    expect(find.text('November 2026'), findsOneWidget);
    expect(find.text('October 2026'), findsOneWidget);
    expect(find.byType(LedgerListTile), findsNWidgets(2));
    expect(find.byType(LedgerCard), findsNWidgets(3), reason: 'month card and one list per month');
  });

  testWidgets('the month card counts days with a report', (tester) async {
    await report(today);
    await report('2026-11-08');
    await report('2026-10-31');
    await show(tester);

    expect(find.text('Reports this month'), findsOneWidget);
    final RollingNumber figure = tester.widget(find.byType(RollingNumber));
    expect(figure.value, '2', reason: 'October is not this month');
    expect(find.text('of 9 days'), findsOneWidget);
  });

  testWidgets('a recent missed day can be written from the list', (tester) async {
    await report('2026-11-08');
    await show(tester);

    expect(find.text('Missed days'), findsOneWidget);

    await tester.tap(find.text('7 Nov'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await pumpDrift(tester, 10);

    expect(find.byType(DprFormScreen), findsOneWidget);
    expect(find.textContaining('7 Nov'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('sync state is visible on each row', (tester) async {
    final draft = await report('2026-11-08');
    final sent = await report('2026-11-07');
    await app.services.dpr.send(sent);
    expect(draft, isNotEmpty);

    await show(tester);

    expect(find.textContaining('Phone only'), findsOneWidget);
    expect(find.textContaining('Not sent yet'), findsOneWidget);
  });

  testWidgets('a report already written today changes the action', (tester) async {
    await report(today);
    await show(tester);
    expect(find.text("Open today's report"), findsOneWidget);
    expect(find.textContaining('Today'), findsOneWidget);
  });

  testWidgets('a draft row opens the form, a sent one the detail', (tester) async {
    final uuid = await report('2026-11-08');
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(uuid), 'IN_REVIEW');
    await app.sync();
    await report('2026-11-07');
    await show(tester);

    await tester.tap(find.text('Sun, 8 Nov'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await pumpDrift(tester, 10);
    expect(find.byType(DprDetailScreen), findsOneWidget);

    await tester.tap(find.text('Back'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await pumpDrift(tester, 5);

    await tester.tap(find.text('Sat, 7 Nov'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await pumpDrift(tester, 10);
    expect(find.byType(DprFormScreen), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a row flies into the detail masthead', (tester) async {
    final uuid = await report('2026-11-08');
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(uuid), 'IN_REVIEW');
    await app.sync();

    usePhone(tester);
    await tester.pumpWidget(
      hostApp(app.services, const DprListScreen(), disableAnimations: false),
    );
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 700));

    await tester.tap(find.text('Sun, 8 Nov'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 60));

    // Mid-flight there are two heroes on screen, one on each route.
    expect(find.byType(Hero), findsNWidgets(2));

    await tester.pump(const Duration(milliseconds: 600));
    await pumpDrift(tester, 5);
    expect(find.byType(DprDetailScreen), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('rows stay on screen and readable in Marathi', (tester) async {
    await report('2026-11-08');
    usePhone(tester);
    await tester.pumpWidget(
      hostApp(app.services, const DprListScreen(), locale: const Locale('mr'), textScale: 1.3),
    );
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text('दैनिक अहवाल'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
