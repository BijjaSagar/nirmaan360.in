import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/measurement/view/measurement_detail_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_form_screen.dart';

import 'book_test_kit.dart';

MeasurementInput _entry({
  String date = '2026-11-09',
  String location = 'Footing F12',
  String nos = '2',
  String? length = '10',
  bool deduction = false,
}) => MeasurementInput(
  projectId: 1,
  boqItemId: 1,
  measuredOn: date,
  location: location,
  nos: Qty.parse(nos),
  length: length == null ? null : Qty.parse(length),
  isDeduction: deduction,
);

Finder _stamp(String label) => find.byWidgetPredicate((w) => w is RubberStamp && w.label == label);

Future<void> settle(WidgetTester tester, {int ms = 500}) async {
  await pumpDrift(tester, 10);
  await tester.pump(Duration(milliseconds: ms));
  await pumpDrift(tester, 10);
}

void main() {
  group('the book', () {
    testWidgets('rows are grouped by date with formula, quantity, stamp and sync state', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await app.services.measurements.create(_entry());
      await app.services.measurements.create(_entry(date: '2026-11-08', location: 'Pier P3'));
      await pumpBook(tester, app, start: Routes.measurements);
      await settle(tester);

      expect(find.text('Today'), findsOneWidget);
      expect(find.text('Yesterday'), findsOneWidget);
      expect(find.text('Footing F12'), findsOneWidget);
      expect(find.text('20.000 cum'), findsNWidgets(2));
      expect(find.textContaining('2 × 10'), findsNWidgets(2));
      expect(find.textContaining('20.000'), findsNWidgets(2));
      expect(_stamp('Draft'), findsNWidgets(2));
      expect(find.textContaining('On phone'), findsNWidgets(2));
    });

    testWidgets('a deduction reads negative in the book', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await app.services.measurements.create(_entry(deduction: true));
      await pumpBook(tester, app, start: Routes.measurements);
      await settle(tester);

      expect(find.textContaining('−2 × 10'), findsOneWidget);
      expect(find.textContaining('−20.000 cum'), findsOneWidget);
    });

    testWidgets('a status filter narrows the rows', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await app.services.measurements.create(_entry());
      final String approved = await app.services.measurements.create(_entry(location: 'Pier P3'));
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(approved), 'APPROVED');
      await app.sync();

      await pumpBook(tester, app, start: Routes.measurements);
      await settle(tester);
      expect(find.textContaining('Footing F12'), findsOneWidget);
      expect(find.textContaining('Pier P3'), findsOneWidget);

      final Finder strip = find.byWidgetPredicate((w) => w is ListView && w.scrollDirection == Axis.horizontal);
      await tester.drag(strip, const Offset(-400, 0));
      await tester.pump();
      await tester.tap(find.descendant(of: find.byType(N360Chip), matching: find.text('Approved')));
      await settle(tester);

      expect(find.textContaining('Footing F12'), findsNothing);
      expect(find.textContaining('Pier P3'), findsOneWidget);
    });

    testWidgets('an empty book invites the first entry', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.measurements);
      await settle(tester);

      expect(find.byType(EmptyLedger), findsOneWidget);
      expect(find.text('No measurements yet'), findsOneWidget);
    });

    testWidgets('add opens the entry page and a row opens its detail', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await app.services.measurements.create(_entry());
      await pumpBook(tester, app, start: Routes.measurements);
      await settle(tester);

      await tester.tap(find.textContaining('Footing F12'));
      await settle(tester);
      expect(find.byType(MeasurementDetailScreen), findsOneWidget);
      expect(find.text('2 × 10 = 20.000 cum'), findsOneWidget);

      await tester.tap(find.text('Back'));
      await settle(tester);
      expect(find.byType(MeasurementDetailScreen), findsNothing);

      await tester.tap(find.byKey(const ValueKey<String>('measurement-add')));
      await settle(tester);
      expect(find.byType(MeasurementFormScreen), findsOneWidget);
      expect(find.byType(GloveKeypad), findsOneWidget);
    });

    testWidgets('with motion on, the row flies into the detail page', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await app.services.measurements.create(_entry());
      await pumpBook(tester, app, start: Routes.measurements, disableAnimations: false);
      await settle(tester, ms: 900);

      expect(find.byType(LedgerHero), findsOneWidget);
      await tester.tap(find.textContaining('Footing F12'));
      await tester.pump();
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 150));
      expect(tester.takeException(), isNull);
      await tester.pump(const Duration(seconds: 1));
      await pumpDrift(tester, 10);
      expect(find.textContaining('Footing F12'), findsWidgets);
      await tester.pump(const Duration(seconds: 1));
    });
  });

  group('the entry detail', () {
    testWidgets('a draft can be edited or sent', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      final String uuid = await app.services.measurements.create(_entry());
      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settle(tester);

      expect(find.byKey(const ValueKey<String>('measurement-edit')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey<String>('measurement-detail-send')));
      await settle(tester, ms: 900);

      final List<OutboxRow> outbox = await app.db.select(app.db.outbox).get();
      expect(outbox.where((o) => o.type == OutboxType.measurementSubmit), hasLength(1));
      await settle(tester);

      // Once queued, it can no longer be changed, and the page says why.
      expect(find.byKey(const ValueKey<String>('measurement-edit')), findsNothing);
      expect(find.text('Waiting to go for approval'), findsOneWidget);
    });

    testWidgets('an approved entry is locked and says how to correct it', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      final String uuid = await app.services.measurements.create(_entry());
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'APPROVED');
      await app.sync();

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settle(tester);

      expect(_stamp('Approved'), findsWidgets);
      expect(find.text('Approved and locked'), findsOneWidget);
      expect(find.textContaining('reversal'), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('measurement-edit')), findsNothing);
      expect(find.byKey(const ValueKey<String>('measurement-detail-send')), findsNothing);
    });

    testWidgets('a returned entry shows the remark first', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      final String uuid = await app.services.measurements.create(_entry());
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'RETURNED', returnRemark: 'Length looks short');
      await app.sync();

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settle(tester);

      expect(find.text('Length looks short'), findsOneWidget);
      expect(find.text('Send again'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey<String>('measurement-edit')));
      await settle(tester);
      expect(tester.widget<MeasurementFormScreen>(find.byType(MeasurementFormScreen)).clientUuid, uuid);
    });

    testWidgets('a rejected entry says what to do next, and offers it', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      final String uuid = await app.services.measurements.create(_entry());
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'REJECTED');
      await app.sync();

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settle(tester);

      expect(find.textContaining('will not count'), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('measurement-new')), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('measurement-edit')), findsNothing);
    });

    testWidgets('an entry in review says who has it', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      final String uuid = await app.services.measurements.create(_entry());
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'IN_REVIEW');
      await app.sync();

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settle(tester);

      expect(find.textContaining('With the approver'), findsOneWidget);
    });

    testWidgets('a missing entry says so', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.measurementDetail('no-such-entry'));
      await settle(tester);
      expect(find.text('Entry not found'), findsOneWidget);
    });
  });
}
