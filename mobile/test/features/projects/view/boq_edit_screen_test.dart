import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/view/boq_edit_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../attendance/view/field_test_support.dart';

/// The demo seed's line 1: code 2.1, "RCC work 2.1", 620 cum at ₹500.
Future<TestApp> _app({bool manage = true, bool owner = false, bool money = true}) async {
  final FakeApi api = FakeApi();
  if (manage) api.grant('boq.manage');
  api.user['is_owner'] = owner;
  (api.user['can'] as Map)['money.view'] = money;
  return TestApp.create(api: api);
}

Future<void> _open(WidgetTester tester, TestApp app, {Locale locale = const Locale('en')}) async {
  setPhone(tester, height: 900);
  await tester.pumpWidget(
    fieldRouterApp(
      app.services,
      initial: Routes.boq,
      locale: locale,
      routes: <String, Widget Function(dynamic)>{
        Routes.boq: (_) => const BoqScreen(),
        '/boq/:item/edit': (s) => BoqEditScreen(boqItemId: int.parse(s.pathParameters['item']!)),
      },
    ),
  );
  await pumpDrift(tester);
}

Future<void> _openEdit(WidgetTester tester, TestApp app, {Locale locale = const Locale('en')}) async {
  await _open(tester, app, locale: locale);
  await tester.tap(find.byKey(const ValueKey<String>('boq-edit-1')));
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
}

Future<void> _type(WidgetTester tester, String key, String text) async {
  await tester.enterText(find.byKey(ValueKey<String>(key)), text);
  await tester.pump();
}

Future<void> _tap(WidgetTester tester, String key) async {
  final Finder target = find.byKey(ValueKey<String>(key));
  final Finder form = find.descendant(of: find.byType(BoqEditScreen), matching: find.byType(Scrollable));
  if (target.evaluate().isEmpty && form.evaluate().isNotEmpty) {
    await tester.scrollUntilVisible(target, 200, scrollable: form.first);
  }
  await tester.ensureVisible(target);
  await tester.pump();
  await tester.tap(target);
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
}

String _field(WidgetTester tester, String key) => tester
    .widget<TextField>(find.descendant(of: find.byKey(ValueKey<String>(key)), matching: find.byType(TextField)))
    .controller!
    .text;

void main() {
  testWidgets('without boq.manage a line has no Edit item', (tester) async {
    final TestApp app = await _app(manage: false);
    addTearDown(app.dispose);

    await _open(tester, app);

    expect(find.text('RCC work 2.1'), findsOneWidget);
    expect(find.text('Edit item'), findsNothing);
  });

  testWidgets('a manager opens a line filled in, with no delete when not the owner', (tester) async {
    final TestApp app = await _app();
    addTearDown(app.dispose);

    await _openEdit(tester, app);

    expect(find.text('Edit BOQ item'), findsOneWidget);
    expect(_field(tester, 'boq-edit-code'), '2.1');
    expect(_field(tester, 'boq-edit-description'), 'RCC work 2.1');
    expect(_field(tester, 'boq-edit-qty'), '620.000');
    expect(_field(tester, 'boq-edit-rate'), '500.00');
    expect(find.byKey(const ValueKey<String>('boq-edit-delete')), findsNothing);
    expect(find.text('Delete this item'), findsNothing);
  });

  testWidgets('a save needs a reason, then goes to the office and back to the BOQ', (tester) async {
    final TestApp app = await _app();
    addTearDown(app.dispose);
    await _openEdit(tester, app);

    await _type(tester, 'boq-edit-description', 'RCC M30 in slab');
    await _type(tester, 'boq-edit-qty', '650.5');
    await _tap(tester, 'boq-edit-save');

    expect(find.text('Write why it is changing.'), findsOneWidget);
    expect(app.api.boqEdits, isEmpty);

    await _type(tester, 'boq-edit-reason', 'Revised drawing R3');
    await _tap(tester, 'boq-edit-save');

    final Map<String, Object?> sent = app.api.boqEdits.single;
    expect(sent['description'], 'RCC M30 in slab');
    expect(sent['qty'], '650.500');
    expect(sent['rate'], '500.00');
    expect(sent['reason'], 'Revised drawing R3');
    expect(find.byType(BoqEditScreen), findsNothing);
    expect(find.text('BOQ item saved.'), findsOneWidget);
    expect(find.text('RCC M30 in slab'), findsOneWidget);
    await settleToasts(tester);
  });

  testWidgets('a quantity that is not a number is caught before sending', (tester) async {
    final TestApp app = await _app();
    addTearDown(app.dispose);
    await _openEdit(tester, app);

    await _type(tester, 'boq-edit-qty', '12.3456');
    await _type(tester, 'boq-edit-reason', 'Typo');
    await _tap(tester, 'boq-edit-save');

    expect(find.textContaining('up to 3 decimals'), findsOneWidget);
    expect(app.api.boqEdits, isEmpty);
  });

  testWidgets('the server refusing a quantity below what is measured shows on the quantity', (tester) async {
    final TestApp app = await _app();
    addTearDown(app.dispose);
    await app.services.measurements.create(
      MeasurementInput(projectId: 1, boqItemId: 1, measuredOn: '2026-11-09', nos: Qty.parse('30')),
    );
    await app.sync();
    await _openEdit(tester, app);

    expect(find.textContaining('Measured so far: 30.000 cum'), findsOneWidget);
    await _type(tester, 'boq-edit-qty', '20');
    await _type(tester, 'boq-edit-reason', 'Cut back');
    await _tap(tester, 'boq-edit-save');

    expect(find.text('Quantity cannot be lower than the quantity already measured (30.000).'), findsOneWidget);
    expect(find.byType(BoqEditScreen), findsOneWidget);
  });

  testWidgets('a line changed in the office meanwhile is shown afresh and the reason is kept', (tester) async {
    final TestApp app = await _app();
    addTearDown(app.dispose);
    await _openEdit(tester, app);
    app.api.editOnWeb(app.api.boqItems.first, <String, dynamic>{'description': 'Changed in the office'});

    await _type(tester, 'boq-edit-description', 'Changed on site');
    await _type(tester, 'boq-edit-reason', 'Revised drawing');
    await _tap(tester, 'boq-edit-save');

    expect(find.textContaining('Someone changed this item after you opened it'), findsOneWidget);
    expect(_field(tester, 'boq-edit-description'), 'Changed in the office');
    expect(_field(tester, 'boq-edit-reason'), 'Revised drawing');
    expect(app.api.boqItems.first['description'], 'Changed in the office');
  });

  testWidgets('offline the save is off and the banner says so', (tester) async {
    final TestApp app = await _app(owner: true);
    addTearDown(app.dispose);
    await _openEdit(tester, app);

    app.connectivity.setOnline(false);
    await pumpDrift(tester);

    expect(find.textContaining('BOQ items can be changed only online.', findRichText: true), findsOneWidget);
    await _tap(tester, 'boq-edit-save');
    await _tap(tester, 'boq-edit-delete');
    expect(app.api.boqEdits, isEmpty);
    expect(find.text('Delete item 2.1?'), findsNothing);
  });

  testWidgets('someone who may not see money gets no rate field and sends no rate', (tester) async {
    final TestApp app = await _app(money: false);
    addTearDown(app.dispose);
    await _openEdit(tester, app);

    expect(find.byKey(const ValueKey<String>('boq-edit-rate')), findsNothing);
    await _type(tester, 'boq-edit-reason', 'Wording');
    await _tap(tester, 'boq-edit-save');

    expect(app.api.boqEdits.single['rate'], isNull);
    await settleToasts(tester);
  });

  testWidgets('the owner deletes after a confirmation that names the line and asks why', (tester) async {
    final TestApp app = await _app(owner: true);
    addTearDown(app.dispose);
    await _openEdit(tester, app);

    expect(find.textContaining('Only the company owner can delete BOQ items'), findsOneWidget);
    await _tap(tester, 'boq-edit-delete');

    expect(find.text('Delete item 2.1?'), findsOneWidget);
    expect(find.text('RCC work 2.1 · 620.000 cum'), findsOneWidget);
    expect(find.textContaining('cannot be brought back from the app'), findsOneWidget);

    await _tap(tester, 'boq-delete-confirm');
    expect(find.text('Write why it is being deleted.'), findsOneWidget);
    expect(app.api.boqDeletes, isEmpty);

    await _tap(tester, 'boq-delete-keep');
    expect(find.text('Delete item 2.1?'), findsNothing);
    expect(app.api.boqDeletes, isEmpty);

    await _tap(tester, 'boq-edit-delete');
    await _type(tester, 'boq-delete-reason', 'Added twice');
    await _tap(tester, 'boq-delete-confirm');

    expect(app.api.boqDeletes.single, (id: 1, reason: 'Added twice'));
    expect(find.byType(BoqEditScreen), findsNothing);
    expect(find.text('Item 2.1 deleted from the BOQ.'), findsOneWidget);
    expect(find.text('RCC work 2.1'), findsNothing);
    await settleToasts(tester);
  });

  testWidgets('a refused delete lists every reason the office gave, and the line stays', (tester) async {
    final TestApp app = await _app(owner: true);
    addTearDown(app.dispose);
    app.api.boqItemUses[1] = <String>[
      'This item is in a tender and cannot be removed.',
      'This item has work in daily reports and cannot be removed.',
    ];
    await _openEdit(tester, app);

    await _tap(tester, 'boq-edit-delete');
    await _type(tester, 'boq-delete-reason', 'Added twice');
    await _tap(tester, 'boq-delete-confirm');

    expect(find.text('This item cannot be deleted'), findsOneWidget);
    expect(find.textContaining('This item is in a tender and cannot be removed.'), findsOneWidget);
    expect(find.textContaining('This item has work in daily reports and cannot be removed.'), findsOneWidget);
    expect(find.byType(BoqEditScreen), findsOneWidget);
    expect(await app.services.projects.getBoqItem(1), isNotNull);
  });

  testWidgets('work on this phone not yet sent stops a delete with a clear message', (tester) async {
    final TestApp app = await _app(owner: true);
    addTearDown(app.dispose);
    await app.services.measurements.create(
      MeasurementInput(projectId: 1, boqItemId: 1, measuredOn: '2026-11-09', nos: Qty.parse('5')),
    );
    await _openEdit(tester, app);

    await _tap(tester, 'boq-edit-delete');
    await _type(tester, 'boq-delete-reason', 'Added twice');
    await _tap(tester, 'boq-delete-confirm');

    expect(find.textContaining('has not been sent yet'), findsOneWidget);
    expect(app.api.boqDeletes, isEmpty);
  });

  testWidgets('the server refusing a non-owner says only the owner can delete', (tester) async {
    // The phone believes it is the owner; the office no longer agrees.
    final TestApp app = await _app(owner: true);
    addTearDown(app.dispose);
    await _openEdit(tester, app);
    app.api.user['is_owner'] = false;

    await _tap(tester, 'boq-edit-delete');
    await _type(tester, 'boq-delete-reason', 'Added twice');
    await _tap(tester, 'boq-delete-confirm');

    expect(find.text('Only the company owner can delete a BOQ item.'), findsOneWidget);
    expect(await app.services.projects.getBoqItem(1), isNotNull);
  });

  testWidgets('the form reads in Marathi', (tester) async {
    final TestApp app = await _app(owner: true);
    addTearDown(app.dispose);
    await _openEdit(tester, app, locale: const Locale('mr'));

    expect(find.text('BOQ आयटम बदला'), findsOneWidget);
    expect(find.text('हे का बदलत आहे?'), findsOneWidget);
    expect(find.text('हा आयटम काढा'), findsOneWidget);
  });

  group('the edit address', () {
    testWidgets('needs boq.manage', (tester) async {
      final TestApp app = await _app(manage: false);
      addTearDown(app.dispose);

      await pumpShellApp(tester, app, location: Routes.boqEdit(1));

      expect(find.byType(BoqEditScreen), findsNothing);
      expect(find.byType(BoqScreen), findsOneWidget);
    });

    testWidgets('opens with boq.manage', (tester) async {
      final TestApp app = await _app();
      addTearDown(app.dispose);

      await pumpShellApp(tester, app, location: Routes.boqEdit(1));

      expect(find.byType(BoqEditScreen), findsOneWidget);
    });
  });
}
