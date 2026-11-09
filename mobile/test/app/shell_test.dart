import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/app_info.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/sync_center/view/sync_center_screen.dart';
import 'package:nirmaan360/features/settings/view/settings_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../support/test_app.dart';
import 'shell_test_helpers.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  testWidgets('going offline shows the banner without moving the page', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    final double before = tester.getTopLeft(find.text('Kalyani Tower B')).dy;
    expect(find.byType(OfflineBanner), findsNothing);

    app.connectivity.setOnline(false);
    await settle(tester);

    expect(find.byType(OfflineBanner), findsOneWidget);
    expect(tester.getTopLeft(find.text('Kalyani Tower B')).dy, before);

    app.connectivity.setOnline(true);
    await settle(tester);
    expect(find.byType(OfflineBanner), findsNothing);
    expect(tester.getTopLeft(find.text('Kalyani Tower B')).dy, before);
  });

  testWidgets('a progress rule shows while syncing', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);
    expect(find.byType(ProgressRule), findsNothing);

    await app.services.issues.raise(projectId: 1, title: 'Crack');
    await settle(tester);
    final gate = app.api.hangNextPush();
    final run = app.sync();
    await pumpDrift(tester, 10);
    await tester.pump();

    expect(find.byType(ProgressRule), findsOneWidget);

    gate.complete();
    await run;
    await settle(tester);
    expect(find.byType(ProgressRule), findsNothing);
  });

  testWidgets('the bottom bar switches between tabs and home is the back target', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    await tester.tap(find.text(en.shellTabOutbox));
    await settle(tester);
    expect(find.byType(SyncCenterScreen), findsOneWidget);
  });

  testWidgets('settings opens from the rail, with a real 56dp target', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    await tester.tap(find.text(en.shellSettings));
    await settle(tester);

    expect(find.byType(SettingsScreen), findsOneWidget);
  });

  test('AppInfo keeps step with pubspec.yaml', () {
    final String pubspec = File('pubspec.yaml').readAsStringSync();
    final String declared = RegExp(r'^version: (.+)$', multiLine: true).firstMatch(pubspec)!.group(1)!.trim();
    expect('${AppInfo.version}+${AppInfo.buildNumber}', declared);
  });

  testWidgets('the shell survives every route path', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final paths = <String>[
      Routes.home,
      Routes.outbox,
      Routes.measurements,
      Routes.measurementForm,
      Routes.measurementDetail('abc'),
      Routes.dprList,
      Routes.dprForm,
      Routes.dprDetail('abc'),
      Routes.attendance,
      Routes.issues,
      Routes.issueForm,
      Routes.issueDetail('abc'),
      Routes.pettyCash,
      Routes.expenseForm,
      Routes.settings,
    ];
    // Reads CurrentUser so that a permission-guarded route resolves.
    expect(CurrentUser.fromJson(app.api.user).name, isNotEmpty);

    for (final path in paths) {
      await pumpShellApp(tester, app, location: path);
      expect(tester.takeException(), isNull, reason: path);
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
  });
}
