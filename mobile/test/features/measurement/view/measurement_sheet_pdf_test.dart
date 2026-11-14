import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/config/app_config.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/reports/report_share.dart';
import 'package:share_plus/share_plus.dart';

import 'book_test_kit.dart';

void main() {
  late List<ShareParams> shared;

  setUp(() => shared = <ShareParams>[]);
  tearDown(ReportShare.resetForTesting);

  Future<TestApp> start({bool reports = true, FakeConnectivity? connectivity}) async {
    final FakeApi api = FakeApi();
    if (reports) api.grantReports();
    final TestApp app = await bookApp(api: api, connectivity: connectivity);
    ReportShare.tempDirectory = () async => app.tempDirectory.path;
    ReportShare.shareOut = (params) async => shared.add(params);
    return app;
  }

  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 8; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 20)));
      await tester.pump();
    }
    await pumpDrift(tester, 10);
  }

  Future<void> openSheet(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey<String>('measurement-sheet-pdf')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
  }

  GloveButton shareButton(WidgetTester tester) => tester.widget<GloveButton>(find.byKey(const ValueKey<String>('sheet-share')));

  testWidgets('the book offers the sheet PDF on a plan with reports', (tester) async {
    final TestApp app = await start();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurements);
    await settle(tester);

    expect(find.byKey(const ValueKey<String>('measurement-sheet-pdf')), findsOneWidget);
    expect(find.text('Sheet PDF'), findsOneWidget);
  });

  testWidgets('no sheet PDF without reports on the plan, as on the website', (tester) async {
    final TestApp app = await start(reports: false);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurements);
    await settle(tester);

    expect(find.byKey(const ValueKey<String>('measurement-sheet-pdf')), findsNothing);
  });

  testWidgets('the sheet runs from the first of the month to today, like the website, and is shared as a PDF', (tester) async {
    final TestApp app = await start();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurements);
    await settle(tester);
    await openSheet(tester);

    expect(find.text('Measurement sheet PDF'), findsOneWidget);
    expect(find.text('Approved measurements for the dates you choose, grouped by BOQ item.'), findsOneWidget);
    expect(find.text('1 Nov'), findsOneWidget);
    expect(find.text('Today'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey<String>('sheet-share')));
    await settle(tester);

    expect(app.api.measurementSheetReads.single, (projectId: 1, from: '2026-11-01', to: '2026-11-09'));
    final String path = '${app.tempDirectory.path}/${AppConfig.reportFolder}/measurement-sheet-1-20261101-20261109.pdf';
    expect(File(path).existsSync(), isTrue);
    expect(shared.single.files!.single.path, path);
    expect(shared.single.files!.single.mimeType, 'application/pdf');
    // Done: the sheet closes.
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text('Measurement sheet PDF'), findsNothing);
  });

  testWidgets('the start date can be moved back, never past the end', (tester) async {
    final TestApp app = await start();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurements);
    await settle(tester);
    await openSheet(tester);

    await tester.tap(find.byKey(const ValueKey<String>('sheet-from')));
    await tester.pumpAndSettle();
    final DatePickerDialog picker = tester.widget(find.byType(DatePickerDialog));
    expect(picker.lastDate, DateTime(2026, 11, 9));
    await tester.tap(find.text('5'));
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();

    expect(find.text('5 Nov'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey<String>('sheet-share')));
    await settle(tester);
    expect(app.api.measurementSheetReads.single.from, '2026-11-05');
  });

  testWidgets('too many entries for the period says to choose a shorter one', (tester) async {
    final TestApp app = await start();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurements);
    await settle(tester);
    await openSheet(tester);
    app.api.reportFailure = const ValidationFailure('Too many.', errors: <String, List<String>>{'to': <String>['Too many.']});

    await tester.tap(find.byKey(const ValueKey<String>('sheet-share')));
    await settle(tester);

    expect(find.text('Too many measurements in these dates. Choose a shorter period.'), findsOneWidget);
    expect(find.text('Measurement sheet PDF'), findsOneWidget);
    expect(shared, isEmpty);
    await tester.pump(const Duration(seconds: 3));
  });

  testWidgets('offline, the sheet waits for internet', (tester) async {
    final FakeConnectivity connectivity = FakeConnectivity();
    final TestApp app = await start(connectivity: connectivity);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurements);
    await settle(tester);
    connectivity.setOnline(false);
    await tester.pump();
    await openSheet(tester);

    expect(find.text('The sheet needs internet.'), findsOneWidget);
    expect(shareButton(tester).onPressed, isNull);

    connectivity.setOnline(true);
    await tester.pump();
    await tester.pump();
    expect(shareButton(tester).onPressed, isNotNull);
  });
}
