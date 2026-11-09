import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/view/widgets/add_work_sheet.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/measurement/view/widgets/boq_picker_sheet.dart';
import 'package:nirmaan360/features/issues/view/widgets/group_slice.dart';
import 'package:nirmaan360/features/projects/view/boq_missing_prompt.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../../../core/design/test_helpers.dart' show expectTapHeight;
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
    expect(find.text('Balance'), findsOneWidget);
    expect(find.text('620.000'), findsOneWidget);
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
    expect(find.byType(TextField), findsNothing, reason: 'nothing to search yet');
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

  group('list', () {
    Future<TestApp> twoLines() async {
      final api = FakeApi();
      final app = await TestApp.create(seed: false, api: api);
      api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      await api.addBoqItem(id: 1, projectId: 1, code: '2.1', qty: '620.000');
      await api.addBoqItem(id: 2, projectId: 1, code: 'FIN-4.1', qty: '12.000');
      await app.sync();
      return app;
    }

    testWidgets('a line measured past its quantity says so in words, with an icon', (tester) async {
      setPhone(tester);
      final app = await twoLines();
      addTearDown(app.dispose);
      await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 2,
          measuredOn: '2026-11-09',
          nos: Qty.parse('1.000'),
          length: Qty.parse('14.000'),
        ),
      );

      await _openBoq(tester, app);

      expect(find.text('Over by'), findsOneWidget);
      expect(find.text('2.000'), findsOneWidget);
      expect(find.text('Balance'), findsOneWidget, reason: 'the other line is still within its quantity');
      expect(find.byIcon(Icons.warning_amber), findsOneWidget);
    });

    testWidgets('balances line up on the right edge, whatever their length', (tester) async {
      setPhone(tester);
      final app = await twoLines();
      addTearDown(app.dispose);

      await _openBoq(tester, app);

      // 620.000 and 12.000 differ in length; their right edges are the same,
      // so the decimal points sit in one column down the list.
      expect(
        tester.getTopRight(find.text('620.000')).dx,
        tester.getTopRight(find.text('12.000')).dx,
      );
    });

    testWidgets('sections are white groups with a heading, a count and hairlines', (tester) async {
      setPhone(tester);
      final app = await twoLines();
      addTearDown(app.dispose);

      await _openBoq(tester, app);

      expect(find.text('Superstructure'), findsOneWidget);
      // One slice for the heading and one for each line; only the last closes
      // the group, and only the heading opens it.
      final slices = tester.widgetList<GroupSlice>(find.byType(GroupSlice)).toList();
      expect(slices, hasLength(3));
      expect(slices.map((s) => s.first), <bool>[true, false, false]);
      expect(slices.map((s) => s.last), <bool>[false, false, true]);
      expect(find.byType(RuledDivider), findsWidgets);
    });

    testWidgets('a BOQ of 2,000 lines builds only the rows on screen', (tester) async {
      setPhone(tester);
      final api = FakeApi();
      final app = await TestApp.create(seed: false, api: api);
      addTearDown(app.dispose);
      api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      for (var id = 1; id <= 2000; id++) {
        await api.addBoqItem(id: id, projectId: 1, code: '$id', qty: '100.000');
      }
      // Two sections, so headings are in the list as well as lines.
      for (var i = 1000; i < 2000; i++) {
        api.boqItems[i]['section'] = 'Finishing';
      }
      await app.sync();

      await _openBoq(tester, app);

      final int built = tester.widgetList(find.byType(SquarePercent)).length;
      expect(built, greaterThan(3), reason: 'the first lines are there');
      expect(built, lessThan(30), reason: 'one per visible line, not one per BOQ line');
      expect(find.text('Item 1'), findsOneWidget);
      expect(find.text('Item 2000'), findsNothing);
      expect(find.text('Item 1500'), findsNothing);

      // Scrolling to the very end builds the end, and still only what shows.
      await tester.scrollUntilVisible(
        find.text('Item 2000'),
        4000,
        scrollable: find.byType(Scrollable).first,
        maxScrolls: 400,
      );
      await tester.pump();
      expect(find.text('Item 2000'), findsOneWidget);
      expect(find.text('Item 1'), findsNothing);
      expect(tester.widgetList(find.byType(SquarePercent)).length, lessThan(30));
    });

    testWidgets('shows a skeleton while loading, then the lines', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const BoqScreen()));
      expect(find.byType(LedgerSkeleton), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);

      await pumpDrift(tester);
      expect(find.byType(LedgerSkeleton), findsNothing);
      expect(find.text('RCC work 2.1'), findsOneWidget);
    });

    testWidgets('pulling to refresh with no network says why nothing changed', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);

      await tester.pumpWidget(fieldApp(app.services, const BoqScreen()));
      await pumpDrift(tester);
      expect(find.byType(LedgerRefresh), findsOneWidget);

      await tester.fling(find.byType(CustomScrollView), const Offset(0, 400), 1000);
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));

      expect(find.text('Could not update. Showing what is on this phone.'), findsOneWidget);
      await settleToasts(tester);
    });

    testWidgets('the add button is a large, full-width target at the foot', (tester) async {
      setPhone(tester);
      final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
      addTearDown(app.dispose);

      await _openBoq(tester, app);

      expectTapHeight(tester, find.widgetWithText(GloveButton, 'Add item'), min: N360Size.tapLarge);
      expect(tester.getCenter(find.text('Add item')).dy, greaterThan(600));
    });
  });

  group('form', () {
    testWidgets('hints read as examples, so an empty field never looks filled', (tester) async {
      setPhone(tester);
      final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const BoqFormScreen()));
      await pumpDrift(tester);

      expect(find.text('e.g. M25 concrete in slab'), findsOneWidget);
      expect(find.text('e.g. Superstructure'), findsOneWidget);
    });

    testWidgets('units are chips with a full-size target, and picking one selects it', (tester) async {
      setPhone(tester);
      final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const BoqFormScreen()));
      await pumpDrift(tester);

      expectTapHeight(tester, find.widgetWithText(N360Chip, 'cum'));
      expect(tester.widget<N360Chip>(find.widgetWithText(N360Chip, 'cum')).selected, isFalse);

      await tester.tap(find.widgetWithText(N360Chip, 'cum'));
      await tester.pump();

      expect(tester.widget<N360Chip>(find.widgetWithText(N360Chip, 'cum')).selected, isTrue);
    });

    testWidgets('the save buttons are large targets and the figures step has its keypad', (tester) async {
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

      expectTapHeight(
        tester,
        find.widgetWithText(GloveButton, 'Save and add another'),
        min: N360Size.tapLarge,
      );
      expectTapHeight(
        tester,
        find.widgetWithText(GloveButton, 'Save and finish'),
        min: N360Size.tapLarge,
      );
      expect(find.byType(GloveKeypad), findsOneWidget);
    });
  });

  group('missing prompt', () {
    testWidgets('offers the BOQ as a large button and says why, to someone who can add lines', (tester) async {
      setPhone(tester);
      final app = await _emptyProject();
      addTearDown(app.dispose);

      await tester.pumpWidget(
        fieldApp(app.services, Scaffold(body: BoqMissingPrompt(services: app.services))),
      );
      await pumpDrift(tester);

      expect(find.text('This project has no BOQ lines yet'), findsOneWidget);
      expectTapHeight(tester, find.widgetWithText(GloveButton, 'Open BOQ'), min: N360Size.tapLarge);
    });
  });
}
