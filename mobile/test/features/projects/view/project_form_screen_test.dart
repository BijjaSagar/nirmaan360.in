import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_form_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  Future<TestApp> owner() => TestApp.create(seed: false, api: FakeApi()..grant('project.manage'));

  Future<void> tapCreate(WidgetTester tester) async {
    await tester.tap(find.text(en.shellProjectCreate));
    await settle(tester);
  }

  testWidgets('the name is required', (tester) async {
    final app = await owner();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.projectForm);

    await tapCreate(tester);

    expect(find.text(en.shellProjectNameRequired), findsOneWidget);
    expect(find.byType(ProjectFormScreen), findsOneWidget);
    expect(app.api.projects, isEmpty);
  });

  testWidgets('success selects the project and goes home', (tester) async {
    final app = await owner();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.projectForm);

    await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');
    await tester.enterText(find.byType(TextField).at(1), 'Pune');
    await tester.pump();
    await tapCreate(tester);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(find.text('Riverside Heights'), findsOneWidget);
    final int id = app.api.projects.single['id'] as int;
    expect(await app.services.projects.readSelectedProjectId(), id);
    expect(app.api.projects.single['location'], 'Pune');
  });

  testWidgets('the contract value comes from the keypad, as exact money', (tester) async {
    final app = await owner();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.projectForm);
    await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');

    await tester.tap(find.text(en.shellProjectValueEmpty));
    await settle(tester);
    expect(find.byType(GloveKeypad), findsOneWidget);
    for (final digit in <String>['1', '2', '5', '0']) {
      await tester.tap(find.text(digit).last);
      await tester.pump();
    }
    await tester.tap(find.text(en.commonDone));
    await settle(tester);

    expect(find.text('₹1,250.00'), findsOneWidget);
    await tapCreate(tester);
    expect(app.api.projects.single['contract_value'], '1250.00');
  });

  testWidgets('offline says so plainly before and after trying', (tester) async {
    final app = await owner();
    addTearDown(app.dispose);
    app.api.offline = true;
    app.connectivity.setOnline(false);
    await pumpShellApp(tester, app, location: Routes.projectForm);

    expect(find.text(en.shellProjectOffline), findsOneWidget);

    await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');
    await tester.pump();
    await tapCreate(tester);

    expect(find.text(en.shellProjectOffline), findsOneWidget);
    expect(find.byType(ProjectFormScreen), findsOneWidget);
  });

  testWidgets('the plan limit is said in the app\'s words, with no invitation to upgrade', (tester) async {
    final app = await owner();
    addTearDown(app.dispose);
    app.api.planProjectLimit = 0;
    await pumpShellApp(tester, app, location: Routes.projectForm);

    await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');
    await tester.pump();
    await tapCreate(tester);

    expect(find.text(en.shellProjectPlanTitle), findsOneWidget);
    expect(find.text(en.shellProjectPlanBody), findsOneWidget);
    // The server's text says "Upgrade to add more"; the app never shows it (Apple 3.1.1).
    expect(find.text(FakeApi.planLimitMessage), findsNothing);
    expect(find.textContaining('Upgrade'), findsNothing);
    expect(find.byType(ProjectFormScreen), findsOneWidget);
  });

  testWidgets('create is a big button in the lower half', (tester) async {
    final app = await owner();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.projectForm);

    final Finder button = find.widgetWithText(GloveButton, en.shellProjectCreate);
    expect(tester.getSize(button).height, greaterThanOrEqualTo(N360Size.tapLarge));
    expect(tester.getCenter(button).dy, greaterThan(400));
    expect(tester.takeException(), isNull);
  });
}
