import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_form_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  Future<TestApp> owner({bool seed = false, bool money = true}) {
    final api = FakeApi()..grant('project.manage');
    (api.user['can'] as Map)['money.view'] = money;
    return TestApp.create(seed: seed, api: api);
  }

  Future<void> tap(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pump();
    await tester.tap(finder);
    await settle(tester);
  }

  /// Opens the keypad from [field] and types [digits].
  Future<void> enterAmount(WidgetTester tester, Finder field, String digits) async {
    await tap(tester, field);
    expect(find.byType(GloveKeypad), findsOneWidget);
    for (final digit in digits.split('')) {
      await tester.tap(find.descendant(of: find.byType(GloveKeypad), matching: find.text(digit)).last);
      await tester.pump();
    }
    await tester.tap(find.text(en.commonDone));
    await settle(tester);
  }

  Finder emptyAmount() => find.text(en.shellProjectValueEmpty).first;

  String textOf(WidgetTester tester, String key) => tester.widget<Text>(find.byKey(ValueKey<String>(key))).data!;

  group('a new project, phase by phase', () {
    testWidgets('"Add phase" splits the value and works out each share', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectForm);
      await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');

      // The one figure becomes the first phase.
      await enterAmount(tester, emptyAmount(), '400');
      await tap(tester, find.text(en.projectPhaseAdd));
      expect(find.text(en.projectPhaseNumbered(1)), findsOneWidget);
      expect(find.text(en.projectPhaseNumbered(2)), findsOneWidget);

      await tester.enterText(find.byType(TextField).at(2), 'Foundation');
      await tester.enterText(find.byType(TextField).at(3), 'Slab');
      await enterAmount(tester, emptyAmount(), '600');

      expect(textOf(tester, 'project-phase-share-1'), '40.00%');
      expect(textOf(tester, 'project-phase-share-2'), '60.00%');
      expect(textOf(tester, 'project-phase-total'), '₹1,000.00');

      // A third phase of nothing yet changes no share.
      await tap(tester, find.text(en.projectPhaseAdd));
      expect(textOf(tester, 'project-phase-share-3'), '0.00%');
      await tester.enterText(find.byType(TextField).at(4), 'Finishing');
      await enterAmount(tester, emptyAmount(), '1000');
      expect(textOf(tester, 'project-phase-share-1'), '20.00%');
      expect(textOf(tester, 'project-phase-share-2'), '30.00%');
      expect(textOf(tester, 'project-phase-share-3'), '50.00%');
      expect(textOf(tester, 'project-phase-total'), '₹2,000.00');

      await tap(tester, find.text(en.shellProjectCreate));

      expect(find.byType(HomeScreen), findsOneWidget);
      final project = app.api.projects.single;
      expect(project['contract_value'], '2000.00');
      expect(project['phases'], [
        {'name': 'Foundation', 'amount': '400.00'},
        {'name': 'Slab', 'amount': '600.00'},
        {'name': 'Finishing', 'amount': '1000.00'},
      ]);
    });

    testWidgets('thirds still add up to 100.00%', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectForm);

      await enterAmount(tester, emptyAmount(), '100');
      await tap(tester, find.text(en.projectPhaseAdd));
      await enterAmount(tester, emptyAmount(), '100');
      await tap(tester, find.text(en.projectPhaseAdd));
      await enterAmount(tester, emptyAmount(), '100');

      expect(
        <String>[for (var i = 1; i <= 3; i++) textOf(tester, 'project-phase-share-$i')],
        <String>['33.34%', '33.33%', '33.33%'],
      );
    });

    testWidgets('each phase needs a name and an amount, and nothing is sent until it has them', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectForm);
      await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');

      await enterAmount(tester, emptyAmount(), '500');
      await tap(tester, find.text(en.projectPhaseAdd));
      await tester.enterText(find.byType(TextField).at(3), 'Slab');
      await tester.pump();
      await tap(tester, find.text(en.shellProjectCreate));

      expect(find.text(en.projectPhaseNameRequired), findsOneWidget);
      expect(find.text(en.projectPhaseAmountRequired), findsOneWidget);
      expect(find.byType(ProjectFormScreen), findsOneWidget);
      expect(app.api.projects, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a phase left wholly blank is dropped, and removing phases goes back to one figure', (tester) async {
      final app = await owner();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectForm);
      await tester.enterText(find.byType(TextField).at(0), 'Riverside Heights');

      await enterAmount(tester, emptyAmount(), '750');
      await tap(tester, find.text(en.projectPhaseAdd));
      // Take the first phase away: the second, still blank, is the only one left.
      await tap(tester, find.bySemanticsLabel(en.projectPhaseRemove(en.projectPhaseNumbered(1))));
      expect(find.text(en.projectPhaseNumbered(2)), findsNothing);
      expect(find.text(en.projectPhaseNumbered(1)), findsOneWidget);

      await tap(tester, find.bySemanticsLabel(en.projectPhaseRemove(en.projectPhaseNumbered(1))));
      expect(find.text(en.projectPhaseNumbered(1)), findsNothing);
      expect(find.text(en.shellProjectValueEmpty), findsOneWidget);

      await enterAmount(tester, emptyAmount(), '900');
      await tap(tester, find.text(en.projectPhaseAdd));
      await tap(tester, find.text(en.shellProjectCreate));

      // The blank second phase is dropped; the first still needs its name.
      expect(find.text(en.projectPhaseNameRequired), findsOneWidget);
      await tester.enterText(find.byType(TextField).at(2), 'Whole job');
      await tester.pump();
      await tap(tester, find.text(en.shellProjectCreate));

      expect(app.api.projects.single['phases'], [
        {'name': 'Whole job', 'amount': '900.00'},
      ]);
      expect(app.api.projects.single['contract_value'], '900.00');
    });
  });

  group('changing a project', () {
    testWidgets('Home offers it to someone who may manage projects', (tester) async {
      final app = await owner(seed: true);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      await tap(tester, find.text(en.projectEditTitle));
      expect(find.byType(ProjectFormScreen), findsOneWidget);
      expect(find.widgetWithText(GloveButton, en.projectEditSave), findsOneWidget);
    });

    testWidgets('Home does not offer it to the site team, and the address sends them home', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text(en.projectEditTitle), findsNothing);

      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));
      expect(find.byType(ProjectFormScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('the form opens filled in, and a new name reaches the server and this phone', (tester) async {
      final app = await owner(seed: true);
      addTearDown(app.dispose);
      app.api.projects.single
        ..['location'] = 'Baner'
        ..['start_date'] = '2026-11-01';
      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));

      expect(find.text(en.projectEditTitle), findsOneWidget);
      expect(tester.widget<TextField>(find.byType(TextField).at(0)).controller!.text, 'Kalyani Tower B');
      expect(tester.widget<TextField>(find.byType(TextField).at(1)).controller!.text, 'Baner');

      await tester.enterText(find.byType(TextField).at(0), 'Kalyani Tower C');
      await tester.pump();
      await tap(tester, find.text(en.projectEditSave));

      final update = app.api.projectUpdates.single;
      expect(update['name'], 'Kalyani Tower C');
      expect(update['location'], 'Baner');
      expect(update['start_date'], '2026-11-01');
      // One figure: no phases, and the value as it was.
      expect(update['phases'], isEmpty);
      expect(update['contract_value'], '0.00');

      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text('Kalyani Tower C'), findsOneWidget);
      final projects = await tester.runAsync(() => app.services.projects.watchProjects().first);
      expect(projects!.single.name, 'Kalyani Tower C');
    });

    testWidgets('saved phases come back with their shares, and a new phase is sent with the rest', (tester) async {
      final app = await owner(seed: true);
      addTearDown(app.dispose);
      app.api.projects.single
        ..['contract_value'] = '20000000.00'
        ..['phases'] = <Map<String, dynamic>>[
          {'name': 'Foundation', 'amount': '4000000.00'},
          {'name': 'Slab', 'amount': '16000000.00'},
        ];
      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));

      expect(tester.widget<TextField>(find.byType(TextField).at(2)).controller!.text, 'Foundation');
      expect(textOf(tester, 'project-phase-share-1'), '20.00%');
      expect(textOf(tester, 'project-phase-share-2'), '80.00%');
      expect(textOf(tester, 'project-phase-total'), '₹2,00,00,000.00');

      await tap(tester, find.text(en.projectPhaseAdd));
      await tester.enterText(find.byType(TextField).at(4), 'Finishing');
      await enterAmount(tester, emptyAmount(), '5000000');
      expect(textOf(tester, 'project-phase-share-1'), '16.00%');
      expect(textOf(tester, 'project-phase-share-2'), '64.00%');
      expect(textOf(tester, 'project-phase-share-3'), '20.00%');

      await tap(tester, find.text(en.projectEditSave));

      final update = app.api.projectUpdates.single;
      expect(update['phases'], [
        {'name': 'Foundation', 'amount': '4000000.00'},
        {'name': 'Slab', 'amount': '16000000.00'},
        {'name': 'Finishing', 'amount': '5000000.00'},
      ]);
      expect(update.containsKey('contract_value'), isFalse);
      expect(app.api.projects.single['contract_value'], '25000000.00');
    });

    testWidgets('someone who may not see money changes the rest and is not asked for money', (tester) async {
      final app = await owner(seed: true, money: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));

      expect(find.text(en.projectPhaseAdd), findsNothing);
      expect(find.text(en.shellProjectValue), findsNothing);

      await tester.enterText(find.byType(TextField).at(1), 'Wakad');
      await tester.pump();
      await tap(tester, find.text(en.projectEditSave));

      final update = app.api.projectUpdates.single;
      expect(update['location'], 'Wakad');
      expect(update.containsKey('phases'), isFalse);
      expect(update.containsKey('contract_value'), isFalse);
    });

    testWidgets('without network the form says so and offers to try again', (tester) async {
      final app = await owner(seed: true);
      addTearDown(app.dispose);
      app.api.offline = true;
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));

      expect(find.text(en.projectEditOffline), findsOneWidget);
      expect(find.byType(TextField), findsNothing);

      app.api.offline = false;
      app.connectivity.setOnline(true);
      await tap(tester, find.text(en.commonRetry));

      expect(tester.widget<TextField>(find.byType(TextField).at(0)).controller!.text, 'Kalyani Tower B');
      expect(find.text(en.projectEditOffline), findsNothing);
    });

    testWidgets('taking the only phase away makes the value one figure again', (tester) async {
      final app = await owner(seed: true);
      addTearDown(app.dispose);
      app.api.projects.single
        ..['contract_value'] = '1000.00'
        ..['phases'] = <Map<String, dynamic>>[
          {'name': 'Slab', 'amount': '1000.00'},
        ];
      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));

      await tap(tester, find.bySemanticsLabel(en.projectPhaseRemove(en.projectPhaseNumbered(1))));
      await enterAmount(tester, emptyAmount(), '1500');
      await tap(tester, find.text(en.projectEditSave));

      final update = app.api.projectUpdates.single;
      expect(update['phases'], isEmpty);
      expect(update['contract_value'], '1500.00');
      expect(app.api.projects.single['contract_value'], '1500.00');
      expect(app.api.projects.single['phases'], isEmpty);
    });

    testWidgets('the server\'s refusal is shown on the field it names', (tester) async {
      final app = await owner(seed: true);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectEditOf(1));

      // A blank name is caught here, before anything is sent.
      await tester.enterText(find.byType(TextField).at(0), '   ');
      await tester.pump();
      await tap(tester, find.text(en.projectEditSave));
      expect(find.text(en.shellProjectNameRequired), findsOneWidget);
      expect(app.api.projectUpdates, isEmpty);

      const String taken = 'The phases.0.name field must not be greater than 120 characters.';
      app.api.projectUpdateFailure = const ValidationFailure(taken, errors: {
        'phases.0.name': [taken],
      });
      await tester.enterText(find.byType(TextField).at(0), 'Kalyani Tower B');
      await enterAmount(tester, emptyAmount(), '100');
      await tap(tester, find.text(en.projectPhaseAdd));
      await tester.enterText(find.byType(TextField).at(2), 'Slab');
      await tester.pump();
      await tap(tester, find.text(en.projectEditSave));

      expect(find.text(taken), findsOneWidget);
      expect(find.byType(ProjectFormScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
