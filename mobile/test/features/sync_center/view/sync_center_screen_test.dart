import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  testWidgets('shows unsent slips with a live count', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app, location: Routes.outbox);

    expect(find.byType(SlipStack), findsOneWidget);
    expect(find.text(en.syncTypeAttendance), findsOneWidget);
    expect(find.text(en.syncTypePettyCash), findsOneWidget);
    expect(find.byWidgetPredicate((w) => w is RollingNumber && w.value == '3'), findsNWidgets(2));
    expect(find.text(en.syncNow), findsOneWidget);
  });

  testWidgets('sending everything shows the all-sent stamp', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app, location: Routes.outbox);

    await tester.tap(find.text(en.syncNow));
    await settle(tester);

    expect(find.byType(SlipStack), findsNothing);
    expect(find.text(en.shellOutboxAllSentStamp), findsOneWidget);
    expect(find.text(en.syncLastSynced(en.shellTimeJustNow)), findsOneWidget);
  });

  testWidgets('slips leave one by one with the animation, then all-sent appears', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app, location: Routes.outbox, disableAnimations: false);

    await tester.tap(find.text(en.syncNow));
    await pumpDrift(tester, 10);
    // Sent slips tick and play their exit on the tray first.
    expect(find.byType(SlipStack), findsOneWidget);
    await tester.pump(const Duration(milliseconds: 200));
    expect(find.byType(SlipStack), findsOneWidget);
    for (var frame = 0; frame < 60; frame++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(find.byType(SlipStack), findsNothing);
    expect(find.text(en.shellOutboxAllSentStamp), findsOneWidget);
  });

  testWidgets('attention items are separate, translated, with retry and discard', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.api.scriptPushStatuses(<String>['conflict']);
    await app.services.issues.raise(projectId: 1, title: 'Crack');
    await app.sync();
    await pumpShellApp(tester, app, location: Routes.outbox);

    expect(find.text(en.shellOutboxAttention(1)), findsOneWidget);
    expect(find.text(en.syncTypeIssue), findsOneWidget);
    expect(find.text(en.syncReasonApproved), findsOneWidget);
    expect(find.text(en.syncRetry), findsOneWidget);
    expect(find.text(en.syncDiscard), findsOneWidget);

    await tester.tap(find.text(en.syncRetry));
    await settle(tester);
    expect(find.text(en.syncReasonApproved), findsNothing);
  });

  testWidgets('a slip that needs attention never gets a success tick', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await app.services.issues.raise(projectId: 1, title: 'Crack');
    await pumpShellApp(tester, app, location: Routes.outbox, disableAnimations: false);

    expect(find.byType(SlipStack), findsOneWidget);
    app.api.scriptPushStatuses(<String>['conflict']);
    await app.sync();
    await settle(tester, extra: const Duration(milliseconds: 100));

    expect(find.text(en.syncReasonApproved), findsOneWidget);
    expect(find.text(en.statusSynced), findsNothing);
    await tester.pump(const Duration(seconds: 2));
  });

  testWidgets('discard asks once before throwing an entry away', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.api.scriptPushStatuses(<String>['conflict']);
    await app.services.issues.raise(projectId: 1, title: 'Crack');
    await app.sync();
    await pumpShellApp(tester, app, location: Routes.outbox);

    await tester.tap(find.text(en.syncDiscard));
    await settle(tester);
    expect(find.text(en.shellDiscardTitle), findsOneWidget);

    await tester.tap(find.text(en.shellDiscardKeep));
    await settle(tester);
    expect(find.text(en.syncReasonApproved), findsOneWidget);

    await tester.tap(find.text(en.syncDiscard));
    await settle(tester);
    await tester.tap(find.text(en.shellDiscardConfirm));
    await settle(tester);
    expect(find.text(en.syncReasonApproved), findsNothing);
    expect(await app.services.auth.unsentWorkCount(), 0);
  });

  testWidgets('stale results appear in a short log', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await app.services.attendance.mark(projectId: 1, workerId: 1, date: kToday, status: 'present');
    // Someone else's newer mark for the same worker is already on the server.
    app.api.attendance.add(<String, dynamic>{
      'id': 8,
      'client_uuid': 'other-device',
      'project_id': 1,
      'worker_id': 1,
      'attendance_date': kToday,
      'status': 'absent',
      'ot_hours': '0.0',
      'marked_by': 9,
      'client_updated_at': '2030-01-01T00:00:00+05:30',
      'updated_at': '2030-01-01T00:00:00+05:30',
    });
    await app.sync();
    await pumpShellApp(tester, app, location: Routes.outbox);

    expect(find.text(en.shellOutboxLogTitle), findsOneWidget);
    expect(find.text(en.syncStaleLog), findsOneWidget);
  });

  testWidgets('sync now when offline explains why nothing happened', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app, location: Routes.outbox);

    app.api.offline = true;
    await tester.tap(find.text(en.syncNow));
    await settle(tester);

    expect(find.text(en.syncOffline), findsOneWidget);
    await tester.pump(const Duration(seconds: 4));
    await pumpDrift(tester);
  });
}
