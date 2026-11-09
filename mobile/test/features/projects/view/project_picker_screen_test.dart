import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  testWidgets('shows code, name and location; tapping opens that project', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api
      ..seedDemo()
      ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights');
    await app.sync();
    await pumpShellApp(tester, app);

    expect(find.byType(ProjectPickerScreen), findsOneWidget);
    expect(find.text('Kalyani Tower B'), findsOneWidget);
    expect(find.text('KT-B · Pune'), findsOneWidget);
    expect(find.text('MH-07 · Pune'), findsOneWidget);

    await tester.tap(find.text('Mahalaxmi Heights'));
    await settle(tester);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(await app.services.projects.readSelectedProjectId(), 2);
  });

  testWidgets('tapping the name on home opens the picker', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api
      ..seedDemo()
      ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights');
    await app.sync();
    await app.services.projects.selectProject(1);
    await pumpShellApp(tester, app);

    await tester.tap(find.text('Kalyani Tower B'));
    await settle(tester);

    expect(find.byType(ProjectPickerScreen), findsOneWidget);
    // Stamps set Latin text in capitals.
    expect(find.text(en.shellPickerCurrent.toUpperCase()), findsOneWidget);
  });

  testWidgets('with no projects it says so and offers a retry', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    expect(find.text(en.shellPickerEmptyTitle), findsOneWidget);
    expect(find.text(en.commonRetry), findsOneWidget);
    expect(tester.getSize(find.byType(GloveButton)).height, greaterThanOrEqualTo(N360Size.tap));
  });
}
