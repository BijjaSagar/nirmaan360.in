import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/bloc/dpr_form_cubit.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_form_screen.dart';

import '../../../support/test_app.dart';
import 'dpr_test_support.dart';

/// Past the autosave debounce and any landing motion.
const Duration _settle = Duration(milliseconds: 450);

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create(seed: false, api: demoApi());
    await app.sync();
  });

  tearDown(() => app.dispose());

  Future<void> show(WidgetTester tester, {String? uuid, String? date = today, bool offline = false}) async {
    usePhone(tester);
    app.connectivity.setOnline(!offline);
    await tester.pumpWidget(hostApp(app.services, DprFormScreen(clientUuid: uuid, date: date)));
    await pumpDrift(tester, 10);
    await tester.pump(_settle);
  }

  Future<void> settle(WidgetTester tester) async {
    await tester.pump(_settle);
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 100));
  }

  /// Scrolls the sheet until [finder] exists: rows off screen are not built.
  Future<void> reveal(WidgetTester tester, Finder finder) async {
    await tester.scrollUntilVisible(finder, 240, scrollable: find.byType(Scrollable).first);
    await tester.pump();
  }

  Future<void> tapAndSettle(WidgetTester tester, Finder finder) async {
    await tester.tap(finder);
    await tester.pump();
    await settle(tester);
  }

  Future<void> insertYesterday() async {
    await app.db.into(app.db.dprs).insert(
      DprsCompanion.insert(
        clientUuid: 'yesterday',
        projectId: 1,
        reportDate: yesterday,
        status: const Value(DocStatus.approved),
        manpower: const [
          ManpowerLine(trade: 'Mason', count: 12),
          ManpowerLine(trade: 'Helper', count: 18),
        ],
        machinery: const [],
        workItems: const [],
      ),
    );
  }

  Future<List<OutboxRow>> outbox() =>
      (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

  RollingNumber totalFigure(WidgetTester tester) {
    return tester.widgetList<RollingNumber>(find.byType(RollingNumber)).firstWhere(
      (w) => w.suffix.contains('worker'),
    );
  }

  testWidgets('every control on a new sheet is at least 56dp tall', (tester) async {
    await show(tester);
    for (final finder in <Finder>[
      find.text('Send report'),
      find.text('Back'),
      find.byType(GloveStepper).first,
    ]) {
      expect(tester.getSize(finder.hitTestable()).height, greaterThanOrEqualTo(14));
    }
    final Rect send = tester.getRect(find.byType(GloveButton).last);
    expect(send.height, greaterThanOrEqualTo(N360Size.tap));
    expect(send.top, greaterThan(800 / 2), reason: 'primary action sits in the lower half');
  });

  testWidgets('steppers roll the total and autosave', (tester) async {
    await show(tester);
    expect(totalFigure(tester).value, '0');

    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();

    expect(totalFigure(tester).value, '2');
    await settle(tester);

    final rows = await app.db.select(app.db.dprs).get();
    expect(rows.single.manpower.single.count, 2);
    expect(await outbox(), isEmpty, reason: 'autosave adds nothing to the outbox');
    expect(find.text('Saved on phone'), findsOneWidget);
  });

  testWidgets('Same as yesterday is shown only when there is a yesterday', (tester) async {
    await show(tester);
    expect(find.textContaining('Same as yesterday'), findsNothing);
  });

  testWidgets('Same as yesterday fills the counts in one tap', (tester) async {
    await insertYesterday();
    await show(tester);

    await tapAndSettle(tester, find.text('Same as yesterday (30)'));

    expect(totalFigure(tester).value, '30');
    expect(find.textContaining('Same as yesterday'), findsNothing);
    expect(find.text('Filled from yesterday'), findsOneWidget);

    final saved = await app.db.select(app.db.dprs).get();
    expect(saved.firstWhere((d) => d.reportDate == today).manpower, hasLength(2));
    await tester.pump(const Duration(seconds: 3));
  });

  testWidgets('add work: search BOQ, enter on keypad, row appears', (tester) async {
    await show(tester);

    await reveal(tester, find.text('Add work'));
    await tapAndSettle(tester, find.text('Add work').first);
    expect(find.text('Search BOQ'), findsOneWidget);
    expect(find.text('M25 RCC in slab and beams, sixth floor'), findsOneWidget);

    await tapAndSettle(tester, find.text('M25 RCC in slab and beams, sixth floor'));

    for (final digit in const <String>['1', '5', '.', '5']) {
      await tester.tap(find.text(digit).last);
      await tester.pump();
    }
    await tester.tap(find.text('Add').last);
    await tester.pump();
    await settle(tester);

    final saved = await app.db.select(app.db.dprs).get();
    expect(saved.single.workItems.single.qty, Qty.parse('15.5'));
    expect(saved.single.workItems.single.boqItemId, 1);
    expect(find.text('Remove'), findsOneWidget);

    await tapAndSettle(tester, find.text('Remove'));
    expect(find.text('Remove'), findsNothing);
    expect((await app.db.select(app.db.dprs).get()).single.workItems, isEmpty);

    // A gloved mis-tap must not cost the figure.
    await tapAndSettle(tester, find.text('Undo'));
    expect(find.text('Remove'), findsOneWidget);
    expect(
      (await app.db.select(app.db.dprs).get()).single.workItems.single.qty,
      Qty.parse('15.5'),
    );
    expect(find.text('Undo'), findsNothing);
  });

  testWidgets('the date chip says Today so the wrong day is hard to miss', (tester) async {
    await show(tester);
    expect(find.textContaining('Today'), findsOneWidget);
  });

  testWidgets('a filled section shows a tick', (tester) async {
    await show(tester);
    expect(find.byIcon(Icons.check_circle), findsNothing);

    await tester.tap(find.text('Rain'));
    await tester.pump();

    expect(find.byIcon(Icons.check_circle), findsOneWidget);
  });

  testWidgets('a closed site has a way forward from the workers message', (tester) async {
    await show(tester);
    await tapAndSettle(tester, find.text('Send report'));

    await tapAndSettle(tester, find.text('Site closed? Write the reason'));
    await settle(tester);

    expect(find.text('Hindrances'), findsOneWidget);
  });

  testWidgets('review points out what is missing but still allows sending', (tester) async {
    await show(tester);
    await tester.tap(find.text('Rain'));
    await tester.pump();
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();
    await settle(tester);

    await tapAndSettle(tester, find.text('Send report'));

    expect(find.textContaining('No work added.'), findsOneWidget);
    expect(find.textContaining('No photos added.'), findsOneWidget);
    expect(find.widgetWithText(GloveButton, 'Send'), findsOneWidget);
  });

  testWidgets('photos: camera saves the draft first, then attaches and can remove', (tester) async {
    stubCamera(tester);
    await show(tester);

    await reveal(tester, find.text('Take photo'));
    await tapAndSettle(tester, find.text('Take photo'));
    await settle(tester);

    final photos = await app.db.select(app.db.dprPhotos).get();
    expect(photos, hasLength(1));
    expect(await app.db.select(app.db.dprs).get(), hasLength(1));
    expect(find.text('Remove'), findsOneWidget);

    await tapAndSettle(tester, find.text('Remove'));
    expect(await app.db.select(app.db.dprPhotos).get(), isEmpty);
  });

  testWidgets('hindrances stay collapsed until asked for', (tester) async {
    await show(tester);
    await reveal(tester, find.text('Add hindrances or notes'));
    expect(find.text('Hindrances'), findsNothing);

    await tapAndSettle(tester, find.text('Add hindrances or notes'));

    expect(find.text('Hindrances'), findsOneWidget);
    await reveal(tester, find.text('Notes'));
    expect(find.text('Notes'), findsOneWidget);
  });

  testWidgets('validation names the section and stops the send', (tester) async {
    await show(tester);

    await tapAndSettle(tester, find.text('Send report'));

    expect(find.text("Choose today's weather."), findsOneWidget);
    expect(find.textContaining('Enter the workers who came'), findsOneWidget);
    expect(find.text('Check and send'), findsNothing);
    expect(await outbox(), isEmpty);
  });

  testWidgets('filled to sent is at most 3 taps and queues dpr, photos, submit', (tester) async {
    stubCamera(tester);
    await show(tester, offline: true);

    await tester.tap(find.text('Rain'));
    await tester.pump();
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();
    await reveal(tester, find.text('Take photo'));
    await tapAndSettle(tester, find.text('Take photo'));
    await settle(tester);

    // From a filled sheet, count only the taps that send it.
    var taps = 0;
    await tapAndSettle(tester, find.text('Send report'));
    taps++;
    expect(find.text('Check and send'), findsOneWidget);
    expect(
      find.textContaining('The report stays on this phone'),
      findsOneWidget,
      reason: 'offline is said plainly before sending',
    );

    await tester.tap(find.widgetWithText(GloveButton, 'Send'));
    taps++;
    await tester.pump();
    await settle(tester);

    expect(taps, lessThanOrEqualTo(3));
    final rows = await outbox();
    expect(rows.map((r) => r.type), [OutboxType.dpr, OutboxType.dprPhoto, OutboxType.dprSubmit]);

    // The stamp lands, then the honest message: on the phone, not at the office.
    await tester.pump(const Duration(milliseconds: 900));
    await pumpDrift(tester, 5);
    expect(find.text('Saved on phone, will send when network returns'), findsOneWidget);
    expect(find.textContaining('reached'), findsNothing);
    await tester.pump(const Duration(seconds: 5));
  });

  testWidgets('online send still claims only the phone', (tester) async {
    await show(tester);
    await tester.tap(find.text('Rain'));
    await tester.pump();
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();
    await settle(tester);
    await tapAndSettle(tester, find.text('Send report'));
    await tester.tap(find.widgetWithText(GloveButton, 'Send'));
    await tester.pump();
    await settle(tester);
    await tester.pump(const Duration(milliseconds: 900));
    await pumpDrift(tester, 5);
    expect(find.text('Saved on phone, sending now'), findsOneWidget);
    await tester.pump(const Duration(seconds: 5));
  });

  testWidgets('the whole send runs with full motion', (tester) async {
    usePhone(tester);
    await tester.pumpWidget(
      hostApp(app.services, const DprFormScreen(date: today), disableAnimations: false),
    );
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 700));

    await tester.tap(find.text('Rain'));
    await tester.pump();
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump(const Duration(milliseconds: 400));
    await pumpDrift(tester, 5);

    await tester.tap(find.text('Send report'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await pumpDrift(tester, 5);
    await tester.tap(find.widgetWithText(GloveButton, 'Send'));
    await tester.pump();

    // The sheet closes and the stamp lands.
    for (var i = 0; i < 14; i++) {
      await tester.pump(const Duration(milliseconds: 100));
      await pumpDrift(tester, 1);
    }
    expect(find.text('Saved on phone, sending now'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pump(const Duration(seconds: 5));
  });

  testWidgets('a report that has gone for approval opens read-only', (tester) async {
    final uuid = await app.services.dpr.saveDraft(
      const DprInput(
        projectId: 1,
        reportDate: today,
        weather: 'Clear',
        manpower: [ManpowerLine(trade: 'Mason', count: 2)],
      ),
    );
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(uuid), 'IN_REVIEW');
    await app.sync();

    await show(tester);

    expect(find.byType(DprDetailScreen), findsOneWidget);
    expect(find.text('Send report'), findsNothing);
  });

  testWidgets('a RETURNED report shows the remark on top and can go again', (tester) async {
    final uuid = await app.services.dpr.saveDraft(
      const DprInput(
        projectId: 1,
        reportDate: today,
        weather: 'Clear',
        manpower: [ManpowerLine(trade: 'Mason', count: 2)],
      ),
    );
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(uuid), 'RETURNED', returnRemark: 'Add helpers');
    await app.sync();

    await show(tester, uuid: uuid);

    expect(find.text('Add helpers'), findsOneWidget);
    expect(find.text("Approver's remark"), findsOneWidget);
    expect(find.text('Send again'), findsOneWidget);

    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();
    await settle(tester);
    await tapAndSettle(tester, find.text('Send again'));
    await tester.tap(find.widgetWithText(GloveButton, 'Send again').last);
    await tester.pump();
    await settle(tester);
    await tester.pump(const Duration(milliseconds: 900));
    await pumpDrift(tester, 5);

    await app.sync();
    expect(app.api.dprByUuid(uuid)['status'], 'IN_REVIEW');
    await tester.pump(const Duration(seconds: 5));
  });

  testWidgets('Marathi at large text fits with no overflow', (tester) async {
    usePhone(tester);
    await tester.pumpWidget(
      hostApp(
        app.services,
        const DprFormScreen(date: today),
        locale: const Locale('mr'),
        textScale: 1.3,
      ),
    );
    await pumpDrift(tester, 10);
    await tester.pump(_settle);
    expect(find.text('अहवाल पाठवा'), findsOneWidget);
    expect(tester.takeException(), isNull, reason: 'no overflow at the phone text scale');
  });

  testWidgets('a pending edit survives closing the screen', (tester) async {
    await show(tester);
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.pump();
    await tester.pumpWidget(hostApp(app.services, const SizedBox.shrink()));
    await pumpDrift(tester, 10);

    final rows = await app.db.select(app.db.dprs).get();
    expect(rows.single.manpower.single.count, 1);
  });

  test('DprFormState total adds the counts', () {
    const state = DprFormState(counts: {'Mason': 3, 'Helper': 4});
    expect(state.totalWorkers, 7);
  });
}
