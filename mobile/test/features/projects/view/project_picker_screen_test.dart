import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_form_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';
import 'package:nirmaan360/features/settings/view/settings_screen.dart';
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
    // The current site carries a quiet status pill.
    expect(find.text(en.shellPickerCurrent), findsOneWidget);
  });

  group('for someone who can create projects', () {
    testWidgets('an empty account is an invitation, never "ask your office"', (tester) async {
      final app = await TestApp.create(seed: false, api: FakeApi()..grant('project.manage'));
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.text(en.shellPickerFirstButton), findsOneWidget);
      expect(find.text(en.shellPickerEmptyBody), findsNothing);
      expect(find.text(en.shellPickerEmptyTitle), findsNothing);

      await tester.tap(find.text(en.shellPickerFirstButton));
      await settle(tester);
      expect(find.byType(ProjectFormScreen), findsOneWidget);
    });

    testWidgets('a list offers a new project', (tester) async {
      final app = await TestApp.create(seed: false, api: FakeApi()..grant('project.manage'));
      addTearDown(app.dispose);
      app.api
        ..seedDemo()
        ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights');
      await app.sync();
      await pumpShellApp(tester, app);

      expect(find.text(en.shellProjectFormTitle), findsOneWidget);
      await tester.tap(find.text(en.shellProjectFormTitle));
      await settle(tester);
      expect(find.byType(ProjectFormScreen), findsOneWidget);
    });

    testWidgets('an owner with one project reaches the picker from home', (tester) async {
      final app = await TestApp.create(seed: false, api: FakeApi()..grant('project.manage'));
      addTearDown(app.dispose);
      app.api.seedDemo();
      await app.sync();
      await pumpShellApp(tester, app);
      expect(find.byType(HomeScreen), findsOneWidget);

      await tester.tap(find.text('Kalyani Tower B'));
      await settle(tester);

      expect(find.byType(ProjectPickerScreen), findsOneWidget);
      expect(find.text(en.shellProjectFormTitle), findsOneWidget);
    });

    testWidgets('one project on a first run still skips the picker', (tester) async {
      final app = await TestApp.create(seed: false, api: FakeApi()..grant('project.manage'));
      addTearDown(app.dispose);
      app.api.seedDemo();
      await app.sync();
      await pumpShellApp(tester, app, location: Routes.projects);
      expect(find.byType(HomeScreen), findsOneWidget);
    });
  });

  testWidgets('without permission one project keeps the name plain', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    await tester.tap(find.text('Kalyani Tower B'));
    await settle(tester);

    expect(find.byType(ProjectPickerScreen), findsNothing);
  });

  testWidgets('with no projects it says so and offers a retry', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    expect(find.text(en.shellPickerEmptyTitle), findsOneWidget);
    expect(find.text(en.commonRetry), findsOneWidget);
    expect(tester.getSize(find.byType(GloveButton)).height, greaterThanOrEqualTo(N360Size.tap));
  });

  testWidgets('with no projects, settings is one tap away so the wrong account can sign out', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    await tester.tap(find.bySemanticsLabel(en.shellSettings));
    await settle(tester);

    expect(find.byType(SettingsScreen), findsOneWidget);
    expect(find.text(en.shellSignOut), findsOneWidget);
  });
}
