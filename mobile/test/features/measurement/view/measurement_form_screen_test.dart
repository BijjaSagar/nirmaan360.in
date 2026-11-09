import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/measurement/view/measurement_form_screen.dart';

import 'book_test_kit.dart';

const Key _qty = ValueKey<String>('measurement-qty');
const Key _balance = ValueKey<String>('measurement-balance');
const Key _save = ValueKey<String>('measurement-save');
const Key _send = ValueKey<String>('measurement-send');

Finder _formula(String text) => find.text(text);

Future<void> _pickLine(WidgetTester tester, {String code = '2.1'}) async {
  await tester.tap(find.text('Choose BOQ line'));
  await tester.pump();
  await pumpDrift(tester, 8);
  await tester.pump(const Duration(milliseconds: 100));
  await tester.tap(find.text(code).last);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
  await pumpDrift(tester, 8);
}

Future<void> _typeEntry(WidgetTester tester) async {
  await typeKeys(tester, '2');
  await pressNext(tester);
  await typeKeys(tester, '10');
  await pressNext(tester);
  await typeKeys(tester, '5');
  await pressNext(tester);
  await typeKeys(tester, '0.5');
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  testWidgets('BOQ 100.000, entry 2 × 10 × 5 × 0.5 shows 50.000 and balance 50.000', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);

    await _typeEntry(tester);

    expect(rollingValue(tester, _qty), '50.000');
    expect(rollingValue(tester, _balance), '50.000');
    expect(_formula('2 × 10 × 5 × 0.5 = 50.000 cum'), findsOneWidget);
  });

  testWidgets('blank dimensions are skipped, a zero is not', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);

    await typeKeys(tester, '3');
    await pressNext(tester);
    await typeKeys(tester, '12.5');
    await tester.pump(const Duration(milliseconds: 300));

    expect(rollingValue(tester, _qty), '37.500');
    expect(_formula('3 × 12.5 = 37.500 cum'), findsOneWidget);
  });

  testWidgets('the live figure rolls with each digit', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);

    await typeKeys(tester, '2');
    expect(rollingValue(tester, _qty), '2.000');
    await pressNext(tester);
    await typeKeys(tester, '1');
    expect(rollingValue(tester, _qty), '2.000');
    await typeKeys(tester, '0');
    expect(rollingValue(tester, _qty), '20.000');
    expect(rollingValue(tester, _balance), '80.000');
  });

  testWidgets('a deduction shows negative, in laterite, and gives the balance back', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);
    await _typeEntry(tester);

    await tester.tap(find.text('Deduct'));
    await tester.pump(const Duration(milliseconds: 300));

    expect(rollingValue(tester, _qty), '−50.000');
    expect(rollingValue(tester, _balance), '150.000');
    expect(_formula('−2 × 10 × 5 × 0.5 = −50.000 cum'), findsOneWidget);
    final RollingNumber number = tester.widget(
      find.descendant(of: find.byKey(_qty), matching: find.byType(RollingNumber)),
    );
    expect(number.style?.color, N360Colors.laterite);

    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    final List<MeasurementRow> rows = await app.db.select(app.db.measurements).get();
    expect(rows.single.isDeduction, isTrue);
    expect(rows.single.qty, Qty.parse('-50.000'));
  });

  testWidgets('a very large figure stays whole and is refused past what the server holds', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);

    await typeKeys(tester, '99999999');
    await pressNext(tester);
    await typeKeys(tester, '99999999');
    await tester.pump(const Duration(milliseconds: 300));
    expect(tester.takeException(), isNull);
    expect(find.textContaining('99999999 × 99999999'), findsOneWidget);

    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    expect(find.text('This figure is too large.'), findsOneWidget);
    expect(await app.db.select(app.db.measurements).get(), isEmpty);
  });

  testWidgets('the next key moves Nos, L, B, D and stops there', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);

    DimensionTile tile(String field) => tester.widget(find.byKey(ValueKey<String>('tile-$field')));

    expect(tile('nos').active, isTrue);
    await pressNext(tester);
    expect(tile('length').active, isTrue);
    await pressNext(tester);
    expect(tile('breadth').active, isTrue);
    await pressNext(tester);
    expect(tile('depth').active, isTrue);
    final GloveKeypad keypad = tester.widget(find.byType(GloveKeypad));
    expect(keypad.onNext, isNull);
  });

  testWidgets('figures are never typed on the phone keyboard', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('going past BOQ plus tolerance warns but does not block', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);

    await typeKeys(tester, '105');
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.textContaining('PM approval'), findsNothing);

    await typeKeys(tester, '.001');
    await tester.pump(const Duration(milliseconds: 600));
    expect(find.textContaining('PM approval'), findsOneWidget);
    expect(find.byType(RubberStamp), findsOneWidget);

    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    expect((await app.db.select(app.db.measurements).get()).single.qty, Qty.parse('105.001'));
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('save keeps the BOQ line, clears the dimensions and moves the balance', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);
    await _typeEntry(tester);

    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 400));

    DimensionTile tile(String field) => tester.widget(find.byKey(ValueKey<String>('tile-$field')));
    expect(tile('nos').value, isEmpty);
    expect(tile('depth').value, isEmpty);
    expect(tile('nos').active, isTrue);
    expect(find.text('RCC M25 slab at level 6'), findsOneWidget);
    expect(rollingValue(tester, _qty), '0.000');
    expect(rollingValue(tester, _balance), '50.000');
    expect(find.textContaining('1 row saved'), findsOneWidget);
    expect(await app.db.select(app.db.measurements).get(), hasLength(1));
  });

  testWidgets('Nos is required, and nothing is saved without it', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);
    await _pickLine(tester);

    await pressNext(tester);
    await typeKeys(tester, '10');
    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);

    expect(find.text('Enter Nos. It is required.'), findsOneWidget);
    expect(await app.db.select(app.db.measurements).get(), isEmpty);
    final DimensionTile nos = tester.widget(find.byKey(const ValueKey<String>('tile-nos')));
    expect(nos.active, isTrue);
  });

  testWidgets('save without a BOQ line opens the picker', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.measurementForm);

    await typeKeys(tester, '2');
    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 400));

    expect(find.text('Search code or description'), findsOneWidget);
    expect(await app.db.select(app.db.measurements).get(), isEmpty);
  });

  testWidgets('the picker searches and puts recent lines first', (tester) async {
    final TestApp app = await bookApp();
    addTearDown(app.dispose);
    app.api
      ..addBoqItem(id: 2, projectId: 1, code: '3.4', qty: '420.000', unit: 'sqm')
      ..addBoqItem(id: 3, projectId: 1, code: '4.2', qty: '980.500', unit: 'rmt');
    app.api.boqItems[1]['description'] = 'Brick masonry';
    app.api.boqItems[2]['description'] = 'Plaster coat';
    await app.sync();
    await app.services.measurements.create(
      MeasurementInput(projectId: 1, boqItemId: 3, measuredOn: '2026-11-09', nos: Qty.parse('1')),
    );

    await pumpBook(tester, app, start: Routes.measurementForm);
    await tester.tap(find.text('Choose BOQ line'));
    await tester.pump();
    await pumpDrift(tester, 8);

    expect(find.text('RECENT'), findsOneWidget);
    expect(find.text('Plaster coat'), findsOneWidget);
    expect(find.text('Brick masonry'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'brick');
    await tester.pump();
    await pumpDrift(tester, 8);
    expect(find.text('Plaster coat'), findsNothing);
    expect(find.text('Brick masonry'), findsOneWidget);

    await tester.enterText(find.byType(TextField), '4.2');
    await tester.pump();
    await pumpDrift(tester, 8);
    expect(find.text('Plaster coat'), findsOneWidget);
  });

  testWidgets('send for approval saves, queues the submit, and stamps', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    final router = await pumpBook(tester, app, start: Routes.measurements);
    router.push(Routes.measurementForm);
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester);

    await _pickLine(tester);
    await _typeEntry(tester);
    await tester.tap(find.byKey(_send));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester, 8);

    final MeasurementRow row = (await app.db.select(app.db.measurements).get()).single;
    final List<OutboxRow> outbox = await app.db.select(app.db.outbox).get();
    expect(row.qty, Qty.parse('50.000'));
    expect(outbox.map((o) => o.type), containsAll(<OutboxType>[OutboxType.measurement, OutboxType.measurementSubmit]));
    expect(find.byType(MeasurementFormScreen), findsNothing);
  });

  testWidgets('rows saved in this sitting go with the send', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    final router = await pumpBook(tester, app, start: Routes.measurements);
    router.push(Routes.measurementForm);
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester);
    await _pickLine(tester);

    await typeKeys(tester, '1');
    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    await typeKeys(tester, '2');
    await tester.tap(find.byKey(_save));
    await pumpDrift(tester, 8);
    expect(find.text('Send 2 rows'), findsOneWidget);

    await tester.tap(find.byKey(_send));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester, 8);

    final List<OutboxRow> outbox = await app.db.select(app.db.outbox).get();
    final int submits = outbox.where((o) => o.type == OutboxType.measurementSubmit).length;
    expect(submits, 2);
  });

  testWidgets('offline, the stamp says on phone and the toast says what happens next', (tester) async {
    final FakeConnectivity connectivity = FakeConnectivity();
    final TestApp app = await bookApp(qty: '100.000', connectivity: connectivity);
    addTearDown(app.dispose);
    connectivity.setOnline(false);
    final router = await pumpBook(tester, app, start: Routes.measurements);
    router.push(Routes.measurementForm);
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester);

    expect(find.textContaining('go when network returns'), findsWidgets);

    await _pickLine(tester);
    await _typeEntry(tester);
    await tester.tap(find.byKey(_send));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 700));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.textContaining('It will go for approval when network returns'), findsOneWidget);
    await tester.pump(const Duration(seconds: 4));
  });

  testWidgets('a returned entry shows the remark, edits, and goes again', (tester) async {
    final TestApp app = await bookApp(qty: '100.000');
    addTearDown(app.dispose);
    final String uuid = await app.services.measurements.create(
      MeasurementInput(
        projectId: 1,
        boqItemId: 1,
        measuredOn: '2026-11-08',
        nos: Qty.parse('2'),
        length: Qty.parse('10'),
      ),
    );
    await app.sync();
    app.api.setServerStatus(app.api.measurementByUuid(uuid), 'RETURNED', returnRemark: 'Length looks short');
    await app.sync();

    final router = await pumpBook(tester, app, start: Routes.measurements);
    router.push('${Routes.measurementForm}?uuid=$uuid');
    await pumpDrift(tester, 12);
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester, 8);

    expect(find.textContaining('Length looks short'), findsOneWidget);
    // The row already counts in the balance, so unchanged figures leave it alone.
    expect(rollingValue(tester, _qty), '20.000');
    expect(rollingValue(tester, _balance), '80.000');

    await typeKeys(tester, '0');
    await tester.pump(const Duration(milliseconds: 300));
    expect(rollingValue(tester, _qty), '200.000');
    expect(rollingValue(tester, _balance), '−100.000');
    await tester.tap(find.descendant(of: find.byType(GloveKeypad), matching: find.text('Delete')));
    await tester.pump();

    await tester.tap(find.byKey(const ValueKey<String>('tile-length')));
    await tester.pump();
    await typeKeys(tester, '.5');
    await tester.pump(const Duration(milliseconds: 300));
    expect(rollingValue(tester, _qty), '21.000');

    await tester.tap(find.byKey(_send));
    await pumpDrift(tester, 8);
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester, 8);

    final MeasurementRow row = (await app.db.select(app.db.measurements).get()).single;
    expect(row.qty, Qty.parse('21.000'));
    final List<OutboxRow> outbox = await app.db.select(app.db.outbox).get();
    expect(outbox.where((o) => o.type == OutboxType.measurementSubmit), hasLength(1));
  });
}
