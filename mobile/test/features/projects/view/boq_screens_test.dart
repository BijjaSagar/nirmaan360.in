import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/dpr/view/widgets/add_work_sheet.dart';
import 'package:nirmaan360/features/measurement/view/widgets/boq_picker_sheet.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../../attendance/view/field_test_support.dart';

Future<TestApp> _emptyProject({bool manage = true}) async {
  final api = FakeApi();
  if (manage) api.grant('boq.manage');
  final app = await TestApp.create(seed: false, api: api);
  api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  await app.sync();
  return app;
}


Future<void> _openBoq(WidgetTester tester, TestApp app) async {
  await tester.pumpWidget(
    fieldRouterApp(
      app.services,
      initial: Routes.boq,
      routes: {
        Routes.boq: (_) => const BoqScreen(),
        Routes.boqForm: (_) => const BoqFormScreen(),
      },
    ),
  );
  await pumpDrift(tester);
}

Future<void> _tapKeys(WidgetTester tester, String keys) async {
  for (final String key in keys.split('')) {
    await tester.tap(find.text(key).last);
    await tester.pump();
  }
}

void main() {
  testWidgets('shows lines by section, read-only without boq.manage', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await _openBoq(tester, app);

    expect(find.text('Superstructure'), findsOneWidget);
    expect(find.text('RCC work 2.1'), findsOneWidget);
    expect(find.text('Balance 620.000 cum'), findsOneWidget);
    expect(find.text('Add item'), findsNothing);
  });

  testWidgets('with boq.manage, Add item opens the form', (tester) async {
    setPhone(tester);
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);

    await _openBoq(tester, app);
    await tester.tap(find.text('Add item'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 500));

    expect(find.text('Add BOQ item'), findsOneWidget);
  });

  testWidgets('empty BOQ explains itself and offers Add item', (tester) async {
    setPhone(tester);
    final app = await _emptyProject();
    addTearDown(app.dispose);

    await _openBoq(tester, app);

    expect(find.text('No BOQ lines yet'), findsOneWidget);
    expect(find.textContaining('list of work'), findsOneWidget);
    expect(find.text('Add item'), findsOneWidget);
  });

  testWidgets('empty BOQ without the permission says who to ask', (tester) async {
    setPhone(tester);
    final app = await _emptyProject(manage: false);
    addTearDown(app.dispose);

    await _openBoq(tester, app);

    expect(find.textContaining('Ask the office'), findsOneWidget);
    expect(find.text('Add item'), findsNothing);
  });

  testWidgets('search narrows the list', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await _openBoq(tester, app);
    await tester.enterText(find.byType(TextField), 'no such work');
    await tester.pump();

    expect(find.text('Nothing found'), findsOneWidget);
    expect(find.text('RCC work 2.1'), findsNothing);
  });

  testWidgets('adding lines keeps a count and shows the live amount', (tester) async {
    setPhone(tester);
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const BoqFormScreen()));
    await pumpDrift(tester);

    await tester.enterText(find.byType(TextField).first, 'M25 slab');
    await tester.tap(find.text('cum'));
    await tester.pump();
    await tester.tap(find.text('Next'));
    await tester.pump();

    await _tapKeys(tester, '10');
    await tester.tap(find.text('Next'));
    await tester.pump();
    await _tapKeys(tester, '500');

    final RollingNumber amount = tester.widget(
      find.descendant(of: find.byType(RollingMoney), matching: find.byType(RollingNumber)),
    );
    expect(amount.value, '5,000.00');

    await tester.tap(find.text('Save and add another'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('1 line added'), findsOneWidget);
    expect(app.api.boqItems.where((b) => b['description'] == 'M25 slab'), hasLength(1));
    expect(
      tester.widget<TextField>(find.byType(TextField).first).controller!.text,
      isEmpty,
      reason: 'the description is cleared for the next line',
    );
    await settleToasts(tester);
  });

  testWidgets('offline, the form says so and will not save', (tester) async {
    setPhone(tester);
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, const BoqFormScreen()));
    await pumpDrift(tester);

    expect(find.byType(OfflineBanner), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'M25 slab');
    await tester.tap(find.text('cum'));
    await tester.pump();
    await tester.tap(find.text('Next'));
    await tester.pump();

    final button = tester.widget<GloveButton>(
      find.widgetWithText(GloveButton, 'Save and add another'),
    );
    expect(button.onPressed, isNull);
  });

  group('BOQ pickers on an empty project', () {
    Widget host(TestApp app, void Function(BuildContext) open) {
      return fieldRouterApp(
        app.services,
        initial: '/start',
        routes: {
          '/start': (_) => Scaffold(
            body: Center(
              child: Builder(
                builder: (context) => GloveButton(label: 'Open', onPressed: () => open(context)),
              ),
            ),
          ),
          Routes.boq: (_) => const BoqScreen(),
        },
      );
    }

    Future<void> show(WidgetTester tester, TestApp app, void Function(BuildContext) open) async {
      await tester.pumpWidget(host(app, open));
      await pumpDrift(tester);
      await tester.tap(find.text('Open'));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 500));
    }

    testWidgets('measurement picker offers the BOQ with boq.manage', (tester) async {
      setPhone(tester);
      final app = await _emptyProject();
      addTearDown(app.dispose);

      await show(tester, app, (c) => showBoqPicker(c, services: app.services, projectId: 1));

      expect(find.text('This project has no BOQ lines yet'), findsOneWidget);
      await tester.tap(find.text('Open BOQ'));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('No BOQ lines yet'), findsOneWidget);
      expect(find.text('Open BOQ'), findsNothing);
    });

    testWidgets('measurement picker has no button without the permission', (tester) async {
      setPhone(tester);
      final app = await _emptyProject(manage: false);
      addTearDown(app.dispose);

      await show(tester, app, (c) => showBoqPicker(c, services: app.services, projectId: 1));

      expect(find.textContaining('Ask the office'), findsOneWidget);
      expect(find.text('Open BOQ'), findsNothing);
    });

    testWidgets('daily report picker says the same and offers the BOQ', (tester) async {
      setPhone(tester);
      final app = await _emptyProject();
      addTearDown(app.dispose);

      await show(
        tester,
        app,
        (c) => showDprAddWorkSheet(
          c,
          services: app.services,
          projectId: 1,
          yesterdayIds: const {},
          added: const {},
        ),
      );

      expect(find.text('This project has no BOQ lines yet'), findsOneWidget);
      expect(find.text('Open BOQ'), findsOneWidget);
    });
  });
}
