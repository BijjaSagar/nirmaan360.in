import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_form_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';

import '../../../core/design/test_helpers.dart' show expectTapHeight;
import '../../attendance/view/field_test_support.dart';

void main() {
  testWidgets('list shows open work with priority words and opens detail', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await app.services.issues.raise(
      projectId: 1,
      title: 'Crack in column C3',
      priority: 'critical',
      dueDate: '2026-11-01',
    );

    await tester.pumpWidget(
      fieldRouterApp(
        app.services,
        initial: Routes.issues,
        routes: {
          Routes.issues: (_) => const IssueListScreen(),
          Routes.issueForm: (_) => const IssueFormScreen(),
          '/issues/:uuid': (s) => IssueDetailScreen(clientUuid: s.pathParameters['uuid']!),
        },
      ),
    );
    await pumpDrift(tester);

    expect(find.text('Crack in column C3'), findsOneWidget);
    expect(find.text('Critical'), findsOneWidget);
    expect(find.textContaining('Overdue by'), findsOneWidget);
    expect(find.text('Raise issue'), findsOneWidget);

    await tester.tap(find.text('Crack in column C3'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 600));
    expect(find.text('Start work'), findsOneWidget);
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('list shows the empty ledger when there are no issues', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldRouterApp(
      app.services,
      initial: Routes.issues,
      routes: {Routes.issues: (_) => const IssueListScreen()},
    ));
    await pumpDrift(tester);

    expect(find.text('No issues on this site'), findsOneWidget);
  });

  testWidgets('form requires a title and saves, honest about being offline', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, const IssueFormScreen()));
    await pumpDrift(tester);

    await tester.tap(find.text('Save issue'));
    await pumpDrift(tester);
    expect(find.text('Write what is wrong.'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'Water leak');
    await tester.tap(find.text('Save issue'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Saved on phone. Sends when network returns.'), findsOneWidget);
    expect((await app.db.select(app.db.issues).get()).single.title, 'Water leak');
    await settleToasts(tester);
  });

  testWidgets('detail status change works offline and keeps the action bar', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final uuid = await app.services.issues.raise(projectId: 1, title: 'Leak');
    await app.sync();
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
    await pumpDrift(tester);

    await tester.tap(find.text('Start work'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Mark resolved'), findsOneWidget);
    expect(find.text('Saved on phone. Sends when network returns.'), findsOneWidget);
    expect((await app.db.select(app.db.issues).get()).single.status, 'in_progress');
    await settleToasts(tester);
  });

  group('list', () {
    testWidgets('an unsent issue carries its stamp under the title, not beside it', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.issues.raise(
        projectId: 1,
        title: 'Shuttering panels short at slab S2, need forty more before Thursday',
        priority: 'medium',
      );

      await tester.pumpWidget(fieldApp(app.services, const IssueListScreen()));
      await pumpDrift(tester);

      // Read the label from the widget: the stamp's letter case is the design
      // system's business, not this screen's.
      expect(tester.widget<RubberStamp>(find.byType(RubberStamp)).label, 'On phone');
      final Rect title = tester.getRect(find.textContaining('Shuttering panels'));
      final Rect stamp = tester.getRect(find.byType(RubberStamp));
      expect(stamp.top, greaterThanOrEqualTo(title.bottom), reason: 'the title keeps the whole width');
    });

    testWidgets('a finished issue says when it was resolved instead of when it was due', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Scaffold tie loose',
        dueDate: '2026-11-05',
      );
      await app.services.issues.changeStatus(uuid, 'resolved');
      await app.services.issues.raise(projectId: 1, title: 'Leak', dueDate: '2026-11-14');

      await tester.pumpWidget(fieldApp(app.services, const IssueListScreen()));
      await pumpDrift(tester);

      expect(find.textContaining('Resolved '), findsOneWidget);
      expect(find.text('Due 5 Nov'), findsNothing);
      expect(find.text('Due 14 Nov'), findsOneWidget, reason: 'open work still shows its due date');
    });

    testWidgets('shows a skeleton while loading, then the rows', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.issues.raise(projectId: 1, title: 'Leak');

      await tester.pumpWidget(fieldApp(app.services, const IssueListScreen()));
      expect(find.byType(LedgerSkeleton), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);

      await pumpDrift(tester);
      expect(find.byType(LedgerSkeleton), findsNothing);
      expect(find.text('Leak'), findsOneWidget);
    });

    testWidgets('pulling to refresh with no network says why nothing changed', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.issues.raise(projectId: 1, title: 'Leak');
      app.connectivity.setOnline(false);

      await tester.pumpWidget(fieldApp(app.services, const IssueListScreen()));
      await pumpDrift(tester);
      expect(find.byType(LedgerRefresh), findsOneWidget);

      await tester.fling(find.byType(CustomScrollView), const Offset(0, 400), 1000);
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));

      expect(find.text('Could not update. Showing what is on this phone.'), findsOneWidget);
      await settleToasts(tester);
    });

    testWidgets('pulling to refresh online stays quiet', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await app.services.issues.raise(projectId: 1, title: 'Leak');

      await tester.pumpWidget(fieldApp(app.services, const IssueListScreen()));
      await pumpDrift(tester);

      await tester.fling(find.byType(CustomScrollView), const Offset(0, 400), 1000);
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));

      expect(find.text('Could not update. Showing what is on this phone.'), findsNothing);
      await settleToasts(tester);
    });
  });

  group('detail', () {
    testWidgets('the status stamp lands when the status changes, not when the page opens', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      final uuid = await app.services.issues.raise(projectId: 1, title: 'Leak');
      await app.sync();

      await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
      await pumpDrift(tester);
      expect(tester.widget<RubberStamp>(find.byType(RubberStamp)).animate, isFalse);

      await tester.tap(find.text('Start work'));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 300));

      expect(tester.widget<RubberStamp>(find.byType(RubberStamp).first).animate, isTrue);
      await settleToasts(tester);
    });

    testWidgets('the description comes before who and when', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      final uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Crack',
        description: 'Two millimetres wide on the east face.',
      );

      await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
      await pumpDrift(tester);

      final double description = tester.getTopLeft(find.text('Two millimetres wide on the east face.')).dy;
      final double priority = tester.getTopLeft(find.text('Priority')).dy;
      expect(description, lessThan(priority));
    });

    testWidgets('status actions are in the lower half and big enough for a glove', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      final uuid = await app.services.issues.raise(projectId: 1, title: 'Leak');

      await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
      await pumpDrift(tester);

      expectTapHeight(tester, find.widgetWithText(GloveButton, 'Start work'), min: N360Size.tapLarge);
      expectTapHeight(tester, find.widgetWithText(GloveButton, 'Mark resolved'));
      expect(tester.getCenter(find.text('Start work')).dy, greaterThan(400));
    });
  });

  group('form', () {
    testWidgets('says what is optional, and how to clear the day once one is chosen', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const IssueFormScreen()));
      await pumpDrift(tester);

      expect(find.text('Where on site? (optional)'), findsOneWidget);
      expect(find.text('Who will fix it? (optional)'), findsOneWidget);
      expect(find.text('Fix by (optional)'), findsOneWidget);
      expect(find.text('Tap the chosen day again to clear it.'), findsNothing);

      await tester.tap(find.text('3 days'));
      await tester.pump();
      expect(find.text('Tap the chosen day again to clear it.'), findsOneWidget);

      await tester.tap(find.text('3 days'));
      await tester.pump();
      expect(find.text('Tap the chosen day again to clear it.'), findsNothing);
    });

    testWidgets('the person field opens a sheet of people and shows the one chosen', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.api.users.add({'id': 7, 'name': 'Prakash More', 'role': 'pm'});
      await app.sync();

      await tester.pumpWidget(fieldApp(app.services, const IssueFormScreen()));
      await pumpDrift(tester);

      final Finder field = find.ancestor(
        of: find.byIcon(Icons.expand_more),
        matching: find.byType(PressTarget),
      );
      expectTapHeight(tester, field);

      await tester.tap(field);
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 500));
      expect(find.text('Assign to'), findsOneWidget);
      expect(find.byType(BottomSheet), findsOneWidget);

      await tester.tap(find.text('Prakash More'));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 500));

      expect(find.byType(BottomSheet), findsNothing);
      expect(find.text('Prakash More'), findsOneWidget);
    });

    testWidgets('a failure sits above the save button, outside the scrolling form', (tester) async {
      setPhone(tester);
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const IssueFormScreen()));
      await pumpDrift(tester);
      await tester.enterText(find.byType(TextField).first, 'Water leak');
      await tester.tap(find.text('Save issue'));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byType(ErrorSlip), findsOneWidget);
      expect(find.descendant(of: find.byType(ListView), matching: find.byType(ErrorSlip)), findsNothing);
      expect(
        tester.getBottomLeft(find.byType(ErrorSlip)).dy,
        lessThanOrEqualTo(tester.getTopLeft(find.widgetWithText(GloveButton, 'Save issue')).dy),
      );
    });

    testWidgets('hints read as examples, so an empty field never looks filled', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const IssueFormScreen()));
      await pumpDrift(tester);

      expect(find.text('e.g. Crack in column C3'), findsOneWidget);
      expect(find.text('e.g. Grid C, third floor'), findsOneWidget);
    });
  });
}
