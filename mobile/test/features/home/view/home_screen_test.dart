import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/dpr/view/dpr_form_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_form_screen.dart';
import 'package:nirmaan360/features/sync_center/view/sync_center_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  Finder rolling(String value) => find.byWidgetPredicate((w) => w is RollingNumber && w.value == value);

  testWidgets("shows the project and today's figures", (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app);

    expect(find.text('Kalyani Tower B'), findsOneWidget);
    expect(find.text(en.shellReportNotStarted), findsOneWidget);
    expect(rolling('1/2'), findsOneWidget);
    expect(rolling('1'), findsOneWidget);
    expect(rolling('1,240'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('tiles follow permission, with the approvals count', (tester) async {
    final app = await TestApp.create(seed: false, signedIn: false);
    addTearDown(app.dispose);
    final can = app.api.user['can'] as Map;
    can['dpr.approve'] = true;
    can['issue.view'] = false;
    await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
    app.api.seedDemo();
    await app.sync();
    await pumpShellApp(tester, app);

    expect(find.text(en.shellTileDaily), findsOneWidget);
    expect(find.text(en.shellTilePetty), findsOneWidget);
    expect(find.text(en.shellTileIssues), findsNothing);
    expect(find.widgetWithText(ActionTile, en.shellTileApprovals), findsOneWidget);
  });

  testWidgets('pending sync is visible and opens the outbox', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app);

    expect(find.text(en.shellPillWaiting), findsOneWidget);

    await tester.tap(find.byType(SyncPill));
    await settle(tester);
    expect(find.byType(SyncCenterScreen), findsOneWidget);
  });

  testWidgets('returned work is listed with its remark and goes straight to fixing', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await pumpShellApp(tester, app);

    // Section labels set Latin text in capitals.
    expect(find.text(en.shellReturnedTitle.toUpperCase()), findsOneWidget);
    expect(find.byType(RubberStamp), findsNWidgets(2));
    expect(find.text('Add the steel consumption figure before resubmitting.'), findsOneWidget);
    expect(find.text('Depth does not match the site register.'), findsOneWidget);

    // Returned work outranks Today.
    final returned = tester.getTopLeft(find.text(en.shellReturnedTitle.toUpperCase())).dy;
    final today = tester.getTopLeft(find.text(en.commonToday.toUpperCase())).dy;
    expect(returned, lessThan(today));

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
    final BuildContext tileText = tester.element(find.text(en.shellTileAttendance).last);
    expect(MediaQuery.textScalerOf(tileText).scale(1), 1.3);
  });
}
