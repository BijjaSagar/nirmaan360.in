import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_form_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';

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
}
