import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/dpr/view/dpr_form_screen.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/measurement/view/measurement_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';
import 'package:nirmaan360/features/sync_center/view/sync_center_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../core/design/test_helpers.dart' show loadRealFonts;
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  // The placeholder test font draws every Devanagari code point as a full
  // square, which triples the width of a Marathi word. Layout here is judged
  // with the fonts the app ships.
  setUpAll(loadRealFonts);

  Finder rolling(String value) => find.byWidgetPredicate((w) => w is RollingNumber && w.value == value);

  Finder tile(String title) => find.byWidgetPredicate((w) => w is StatTile && w.title == title);

  testWidgets("shows the project and today's figures", (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app);

    expect(find.text('Kalyani Tower B'), findsOneWidget);
    expect(find.text(en.shellReportNotStarted), findsOneWidget);
    expect(find.text('1/2'), findsOneWidget);
    expect(find.text('₹1,240'), findsOneWidget);
    expect(tester.widget<StatTile>(tile(en.shellTileMeasure)).value, '1');
    expect(tester.widget<StatTile>(tile(en.shellTileDaily)).emphasized, isTrue);
    expect(find.byType(StatTile), findsNWidgets(5));
    expect(tester.takeException(), isNull);
  });

  testWidgets('tiles follow permission, and approvals are counted on the bar too', (tester) async {
    final app = await TestApp.create(seed: false, signedIn: false);
    addTearDown(app.dispose);
    final can = app.api.user['can'] as Map;
    can['dpr.approve'] = true;
    can['issue.view'] = false;
    await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
    app.api.seedDemo();
    await app.sync();
    await pumpShellApp(tester, app);

    expect(tile(en.shellTileDaily), findsOneWidget);
    expect(tile(en.shellTileExpenses), findsOneWidget);
    expect(tile(en.shellTileIssues), findsNothing);
    expect(tile(en.shellTileApprovals), findsOneWidget);
    expect(find.descendant(of: find.byType(N360BottomBar), matching: find.text(en.shellTabApprovals)), findsOneWidget);
  });

  testWidgets('pending sync is visible and opens the outbox', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app);

    expect(find.text(en.shellPillWaiting), findsOneWidget);
    // The count is also on the Outbox bar item.
    expect(find.descendant(of: find.byType(N360BottomBar), matching: find.text('3')), findsOneWidget);

    await tester.tap(find.text(en.shellPillWaiting));
    await settle(tester);
    expect(find.byType(SyncCenterScreen), findsOneWidget);
  });

  testWidgets('returned work is listed with its remark and goes straight to fixing', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await pumpShellApp(tester, app);

    expect(find.byType(RubberStamp), findsNWidgets(2));
    expect(find.text('Add the steel consumption figure before resubmitting.'), findsOneWidget);
    expect(find.text('Depth does not match the site register.'), findsOneWidget);

    // Returned work outranks everything else on the page.
    final returned = tester.getTopLeft(find.text('Depth does not match the site register.')).dy;
    final progress = tester.getTopLeft(find.text(en.shellProgressTitle)).dy;
    expect(returned, lessThan(progress));

    await tester.tap(find.text('Add the steel consumption figure before resubmitting.'));
    await settle(tester);
    expect(find.byType(DprFormScreen), findsOneWidget);
    expect(tester.widget<DprFormScreen>(find.byType(DprFormScreen)).clientUuid, isNotNull);
  });

  testWidgets('a returned measurement opens its form', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await pumpShellApp(tester, app);

    await tester.tap(find.text('Depth does not match the site register.'));
    await settle(tester);

    expect(find.byType(MeasurementFormScreen), findsOneWidget);
    expect(tester.widget<MeasurementFormScreen>(find.byType(MeasurementFormScreen)).clientUuid, isNotNull);
  });

  testWidgets('a correction on its way leaves the returned list', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final seeded = await seedReturned(app);
    await pumpShellApp(tester, app);
    expect(find.byType(RubberStamp), findsNWidgets(2));

    await app.services.measurements.sendForApproval(seeded.measurement);
    await settle(tester);

    expect(find.byType(RubberStamp), findsOneWidget);
  });

  group('progress card', () {
    testWidgets('shows the percent, the dates and a bar with today marked', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await seedProgress(app);
      await pumpShellApp(tester, app);

      expect(rolling('70.50'), findsOneWidget);
      final bar = tester.widget<HatchedProgressBar>(find.byType(HatchedProgressBar));
      expect(bar.markerLabel, en.commonToday);
      expect(bar.fraction, closeTo(0.705, 0.0001));
      expect(tester.widget<BuildingProgressArt>(find.byType(BuildingProgressArt)).fraction, closeTo(0.705, 0.0001));
      expect(find.text(en.shellProgressEnd), findsOneWidget);
      expect(find.text('31 Dec 2027'), findsOneWidget);
      expect(find.text(en.shellProgressDaysLeft), findsOneWidget);
      expect(find.text('417'), findsOneWidget);
    });

    testWidgets('is exact over several lines with different rates', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      app.api
        ..addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B')
        ..addBoqItem(id: 1, projectId: 1, code: '1.1', qty: '100.000', rate: '10.10')
        ..addBoqItem(id: 2, projectId: 1, code: '1.2', qty: '1.000', rate: '999.99');
      await app.sync();
      final uuid = await app.services.measurements.create(
        MeasurementInput(projectId: 1, boqItemId: 1, measuredOn: '2026-11-07', nos: Qty.fromInt(1), length: Qty.parse('33.333')),
      );
      await app.services.measurements.sendForApproval(uuid);
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'APPROVED');
      await app.sync();

      await pumpShellApp(tester, app);

      // 33.333 × 10.10 ÷ (100 × 10.10 + 1 × 999.99) = 16.749…%
      expect(rolling('16.75'), findsOneWidget);
    });

    testWidgets('with no BOQ it says so calmly instead of showing a zero', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      await app.sync();

      await pumpShellApp(tester, app);

      expect(find.text(en.shellProgressEmptyTitle), findsOneWidget);
      expect(rolling('0.00'), findsNothing);
      expect(find.byType(HatchedProgressBar), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a BOQ of zero value is treated as no BOQ', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      app.api
        ..addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B')
        ..addBoqItem(id: 1, projectId: 1, code: '1.1', qty: '100.000', rate: '0.00');
      await app.sync();

      await pumpShellApp(tester, app);

      expect(find.text(en.shellProgressEmptyTitle), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('set up your site', () {
    Future<TestApp> owner() async {
      final app = await TestApp.create(seed: false, api: FakeApi()..grant('boq.manage'));
      app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      return app;
    }

    Finder square(String label) => find.byWidgetPredicate((w) => w is SquarePercent && w.label == label);

    testWidgets('a new site shows three steps at 0 of 3, and the first opens', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      await app.sync();
      await pumpShellApp(tester, app);

      expect(find.text(en.shellSetupTitle), findsOneWidget);
      expect(square('0/3'), findsOneWidget);
      expect(find.text(en.shellSetupBoq), findsOneWidget);
      expect(find.text(en.shellSetupWorkers), findsOneWidget);
      expect(find.text(en.shellSetupWallet), findsOneWidget);
      expect(find.text(en.shellSetupNext(en.shellSetupBoq)), findsOneWidget);
      // The setup card already says the BOQ is missing.
      expect(find.text(en.shellProgressEmptyTitle), findsNothing);

      await tester.tap(find.text(en.shellSetupBoq));
      await settle(tester);
      expect(find.byType(BoqScreen), findsOneWidget);
    });

    testWidgets('done steps count, and the next one is named', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      app.api
        ..addBoqItem(id: 1, projectId: 1, code: '1.1')
        ..addWorker2(id: 1, name: 'Ramesh Pawar');
      await app.sync();
      await pumpShellApp(tester, app);

      expect(square('2/3'), findsOneWidget);
      expect(find.byIcon(Icons.check_circle), findsNWidgets(2));
      expect(find.text(en.shellSetupNext(en.shellSetupWallet)), findsOneWidget);
    });

    testWidgets('it goes away when everything is done', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      app.api
        ..addBoqItem(id: 1, projectId: 1, code: '1.1')
        ..addWorker2(id: 1, name: 'Ramesh Pawar')
        ..addWallet(id: 1, projectId: 1, balance: '0.00');
      await app.sync();
      await pumpShellApp(tester, app);

      expect(find.text(en.shellSetupTitle), findsNothing);
      expect(find.byType(HatchedProgressBar), findsOneWidget);
    });

    testWidgets('only steps the user may complete are shown', (tester) async {
      // worker.manage and petty_cash.manage are granted by default, boq.manage is not.
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      await app.sync();
      await pumpShellApp(tester, app);

      expect(square('0/2'), findsOneWidget);
      expect(find.text(en.shellSetupBoq), findsNothing);
      expect(find.text(en.shellProgressEmptyTitle), findsOneWidget);
    });

    testWidgets('someone who can manage nothing incomplete sees no card', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      (app.api.user['can'] as Map)
        ..['worker.manage'] = false
        ..['petty_cash.manage'] = false;
      await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
      app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      await app.sync();
      await pumpShellApp(tester, app);

      expect(find.text(en.shellSetupTitle), findsNothing);
    });

    testWidgets('steps are tall enough for a gloved thumb', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      await app.sync();
      await pumpShellApp(tester, app);

      final Finder step = find.byWidgetPredicate((w) => w is PressTarget && w.minHeight == N360Size.tap + N360Space.lg);
      expect(step, findsNWidgets(3));
      expect(tester.getSize(step.first).height, greaterThanOrEqualTo(N360Size.tap));
      expect(tester.takeException(), isNull);
    });
  });

  testWidgets('it fits at larger text without overflow', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await pumpShellApp(tester, app, textScale: 1.3, locale: 'mr');
    expect(tester.takeException(), isNull);
  });

  testWidgets('large system text keeps the tiles readable', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, textScale: 1.3);

    expect(tester.takeException(), isNull);
    final BuildContext tileText = tester.element(find.text(en.shellTileAttendance));
    expect(MediaQuery.textScalerOf(tileText).scale(1), 1.3);
  });
}
