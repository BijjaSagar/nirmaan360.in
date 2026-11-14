import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/site_progress/data/progress_repository.dart';
import 'package:nirmaan360/features/site_progress/view/widgets/entry_widgets.dart';
import 'package:nirmaan360/features/sync_center/view/sync_labels.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../support/test_app.dart';
import 'site_progress_test.dart' show outbox, progressApp, pumpProgress, settle;

typedef Json = Map<String, dynamic>;

Future<List<OutboxRow>> openRows(TestApp app) async =>
    (await outbox(app)).where((r) => r.status != OutboxStatus.done).toList();

/// What the floor lists, with unsent corrections laid over it.
Future<RecentEntries> recent(TestApp app, {int floorId = 11}) => app.services.progress.watchFloorEntries(1, floorId).first;

/// An entry the server already holds on the first floor, read onto the phone.
Future<FloorEntry> serverEntry(TestApp app, {String qty = '60.000', int floorId = 11}) async {
  final Json row = app.api.addProgressEntry(boqItemId: 1, floorId: floorId, qty: qty, remark: 'Slab pour');
  await app.services.progress.refreshFloorEntries(1, floorId);
  return (await recent(app, floorId: floorId)).entries.firstWhere((e) => e.id == row['id']);
}

Future<void> correct(TestApp app, FloorEntry entry, String right, {String why = 'Wrong bag count'}) {
  return app.services.progress.correctEntry(
    projectId: 1,
    entryId: entry.id,
    boqItemId: entry.boqItemId,
    serverQty: entry.net,
    rightQty: Qty.parse(right),
    remark: why,
    title: '2.1 RCC slab',
  );
}

/// Scrolls an entry clear of the action bar and opens its correction.
Future<void> openCorrection(WidgetTester tester, String figure) async {
  await tester.ensureVisible(find.text(figure));
  await tester.pump();
  await tester.tap(find.text(figure));
  await settle(tester);
}

void main() {
  group('reading', () {
    late TestApp app;

    setUp(() async => app = await progressApp());
    tearDown(() => app.dispose());

    test('the floor lists its entries newest first, and a correction is folded into the one it corrects', () async {
      final Json first = app.api.addProgressEntry(boqItemId: 1, floorId: 11, qty: '60.000', remark: 'Slab pour');
      app.api.addProgressEntry(boqItemId: 1, floorId: 11, qty: '5.000');
      app.api.progressEntryRows.add(<String, dynamic>{
        ...first,
        'id': 990,
        'qty_done': '-10.000',
        'corrects_entry_id': first['id'],
        'remark': 'Typo',
      });
      app.api.addProgressEntry(boqItemId: 1, floorId: 21, qty: '9.000');
      app.api.addProgressEntry(boqItemId: 1, floorId: 11, qty: '0.000', remark: 'Shuttering left');
      await app.services.progress.refreshFloorEntries(1, 11);

      final List<FloorEntry> seen = (await recent(app)).entries;

      expect(seen.map((e) => e.entered.toWire()), <String>['5.000', '60.000'], reason: 'no correction row, no zero row, no other floor');
      expect(seen.last.net.toWire(), '50.000');
      expect(seen.last.entered.toWire(), '60.000');
      expect(seen.last.wasCorrected, isTrue);
      expect(seen.first.wasCorrected, isFalse);
    });

    test('only the last pages are read, and two when the last is short', () async {
      app.api.progressPageSize = 4;
      for (var i = 1; i <= 10; i++) {
        app.api.addProgressEntry(boqItemId: 1, floorId: 11, qty: '$i.000');
      }

      await app.services.progress.refreshFloorEntries(1, 11);

      expect(app.api.progressEntryReads.map((r) => r.$2), <int>[1, 3, 2], reason: 'page 1 for the count, the last page, and the one before it');
      expect((await recent(app)).entries.map((e) => e.entered.toWire()), <String>[
        '10.000', '9.000', '8.000', '7.000', '6.000', '5.000', //
      ], reason: 'the newest six, from the last two pages');
    });

    test('a floor with a single page is read once', () async {
      app.api.addProgressEntry(boqItemId: 1, floorId: 11);
      await app.services.progress.refreshFloorEntries(1, 11);
      expect(app.api.progressEntryReads, hasLength(1));
    });

    test('the list is kept for when there is no network', () async {
      await serverEntry(app);
      app.api.offline = true;

      await expectLater(app.services.progress.refreshFloorEntries(1, 11), throwsA(isA<OfflineFailure>()));
      expect((await recent(app)).entries, hasLength(1));
    });

    test('a removed project takes the lists off the phone', () async {
      await serverEntry(app);
      expect(await app.db.readMeta(MetaKey.floorEntries(1)), isNotNull);

      final Json project = app.api.projects.firstWhere((p) => p['id'] == 1);
      app.api.softDeleteOnWeb('projects', project);
      app.api.projects.remove(project);
      await app.sync();

      expect(await app.db.readMeta(MetaKey.floorEntries(1)), isNull);
    });

    test('an entry sent from this phone is listed at once and can be corrected', () async {
      await app.services.progress.addEntry(
        projectId: 1,
        boqItemId: 1,
        floorId: 11,
        workDate: app.services.context.today,
        qty: Qty.parse('7.000'),
        title: '2.1 RCC slab',
      );
      expect((await recent(app)).entries, isEmpty, reason: 'not on the server yet, so nothing to correct');

      await app.sync();

      final FloorEntry sent = (await recent(app)).entries.single;
      expect(sent.net.toWire(), '7.000');
      expect(app.api.progressEntryReads.where((r) => r.$1 == 11).length, 0, reason: 'listed from the reply, not read back');
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await progressApp());
    tearDown(() => app.dispose());

    test('a correction waits in the outbox, shows at once, and counts in the totals', () async {
      final FloorEntry entry = await serverEntry(app);

      await correct(app, entry, '50');

      final OutboxRow row = (await openRows(app)).single;
      expect(row.type, OutboxType.progressCorrection);
      expect(row.recordUuid, 'correct:${entry.id}');
      expect(row.payload, contains('"qty_done":"50.000"'));
      final FloorEntry shown = (await recent(app)).entries.single;
      expect(shown.shown.toWire(), '50.000');
      expect(shown.net.toWire(), '60.000', reason: 'the server still counts 60');
      expect(shown.pending, isNotNull);

      final ProgressOverview base = (await app.services.progress.watchCached(1).first)!;
      final ProgressOverview laid = base.withUnsent(await app.services.progress.watchUnsent(1).first);
      expect(laid.item(1)!.done.toWire(), '30.000', reason: '40 less the 10 the correction takes off');
      expect(laid.unsent, 1);
    });

    test('it goes to the server as what the entry should have been, with the reason', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50', why: 'Counted twice');

      await app.sync();

      final Json posted = app.api.correctionPosts.values.single;
      expect(posted['corrects_entry_id'], entry.id);
      expect(posted['qty_done'], '-10.000', reason: 'the server holds the difference');
      expect(posted['remark'], 'Counted twice');
      expect((await openRows(app)), isEmpty);
      final FloorEntry after = (await recent(app)).entries.single;
      expect(after.net.toWire(), '50.000');
      expect(after.pending, isNull);
      expect(after.corrections, 1);
    });

    test('a reply that is lost corrects the entry once', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');

      app.api.loseNextProgressReply = true;
      await app.sync();
      expect(await openRows(app), hasLength(1));
      await app.sync();

      expect(app.api.correctionPosts, hasLength(1));
      expect(app.api.progressEntryRows.where((r) => r['corrects_entry_id'] == entry.id), hasLength(1));
      expect(await openRows(app), isEmpty);
    });

    test('a newer correction replaces one still waiting, and sets the quantity not the difference', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');
      await correct(app, entry, '45', why: 'Recounted');

      final OutboxRow row = (await openRows(app)).single;
      expect(row.payload, contains('"qty_done":"45.000"'));
      expect(row.payload, contains('"net_before":"60.000"'));

      await app.sync();
      expect(app.api.correctionPosts.values.single['qty_done'], '-15.000');
    });

    test('putting it back to what the server counts takes a waiting correction back', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');

      await correct(app, entry, '60');

      expect(await openRows(app), isEmpty);
      expect((await recent(app)).entries.single.pending, isNull);
    });

    test('a correction can be made again after the first was taken', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');
      await app.sync();

      final FloorEntry again = (await recent(app)).entries.single;
      await correct(app, again, '55', why: 'Half a bag more');
      await app.sync();

      expect(app.api.correctionPosts, hasLength(2));
      expect((await recent(app)).entries.single.net.toWire(), '55.000');
    });

    test('what the server refuses waits for a person: the same quantity, a correction, a missing reason', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');
      // Another phone corrected it to 50 first.
      app.api.progressEntryRows.add(<String, dynamic>{
        ...app.api.progressEntryRows.first,
        'id': 991,
        'qty_done': '-10.000',
        'corrects_entry_id': entry.id,
      });

      await app.sync();

      final OutboxRow row = (await outbox(app)).last;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, contains('already the quantity'));
      expect(app.api.correctionPosts, isEmpty);
    });

    test('an entry that is itself a correction is refused', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');
      await app.sync();
      final int correctionId = app.api.correctionPosts.values.single['id'] as int;
      // The phone never offers this, so the server is the one to refuse it.
      await app.services.progress.correctEntry(
        projectId: 1,
        entryId: correctionId,
        boqItemId: 1,
        serverQty: Qty.parse('-10.000'),
        rightQty: Qty.parse('3'),
        remark: 'x',
        title: '',
      );

      await app.sync();

      expect((await outbox(app)).last.attentionCode, AttentionCode.validation);
      expect((await outbox(app)).last.attentionReason, contains('Correct the original entry'));
    });

    test('no permission and an entry that has gone both go to the sync screen', () async {
      final FloorEntry entry = await serverEntry(app);
      await correct(app, entry, '50');
      app.api.failNextProgressPost = const ServerFailure('Forbidden.', statusCode: 403);
      await app.sync();
      expect((await outbox(app)).last.attentionCode, AttentionCode.forbidden);

      await correct(app, entry, '48', why: 'Again');
      app.api.progressEntryRows.clear();
      await app.sync();
      expect((await outbox(app)).last.attentionCode, AttentionCode.parentMissing);
    });

    test('the phone refuses what it can see is wrong, before anything is queued', () async {
      final FloorEntry entry = await serverEntry(app);

      await expectLater(correct(app, entry, '50', why: '   '), throwsA(isA<ValidationFailure>()));
      await expectLater(correct(app, entry, '-1'), throwsA(isA<ValidationFailure>()));
      await expectLater(correct(app, entry, '100000000'), throwsA(isA<ValidationFailure>()));
      await expectLater(correct(app, entry, '60'), throwsA(isA<ValidationFailure>()), reason: 'nothing to change');

      expect(await openRows(app), isEmpty);
    });

    test('the sync log names a correction', () {
      expect(syncTypeIcon(OutboxType.progressCorrection), Icons.edit_note);
    });
  });

  group('screens', () {
    Future<TestApp> floorApp({bool canCreate = true}) async {
      final TestApp app = await progressApp(canCreate: canCreate);
      addTearDown(app.dispose);
      app.api.addProgressEntry(boqItemId: 1, floorId: 11, qty: '60.000', remark: 'Slab pour');
      app.api.addProgressEntry(boqItemId: 1, floorId: 11, qty: '5.000', workDate: '2026-11-08');
      return app;
    }

    testWidgets('the floor shows its recent entries and a way to correct each', (tester) async {
      final TestApp app = await floorApp();
      await pumpProgress(tester, app, start: Routes.progressFloor(11));
      await tester.ensureVisible(find.text('Recent entries on this floor'));

      expect(find.text('Recent entries on this floor'), findsOneWidget);
      expect(find.text('60.000 cum'), findsOneWidget);
      expect(find.text('5.000 cum'), findsOneWidget);
      expect(find.text('Correct'), findsNWidgets(2));
      expect(find.textContaining('Slab pour'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('someone who may not record progress sees the entries but no way to correct them', (tester) async {
      final TestApp app = await floorApp(canCreate: false);
      await pumpProgress(tester, app, start: Routes.progressFloor(11));
      await tester.ensureVisible(find.text('Recent entries on this floor'));

      expect(find.text('60.000 cum'), findsOneWidget);
      expect(find.text('Correct'), findsNothing);
    });

    testWidgets('correcting asks for the new quantity and a reason, then waits to send', (tester) async {
      final TestApp app = await floorApp();
      await pumpProgress(tester, app, start: Routes.progressFloor(11));
      await openCorrection(tester, '60.000 cum');

      expect(find.text('Correct this entry'), findsOneWidget);
      expect(find.text('Counted now: 60.000 cum'), findsOneWidget);

      // The same quantity and no reason are told apart, and nothing is queued.
      await tester.tap(find.byKey(const ValueKey<String>('progress-correct-save')));
      await settle(tester);
      expect(find.text('That is already the quantity'), findsOneWidget);
      expect(find.text('Say why it is being corrected'), findsOneWidget);
      expect(await openRows(app), isEmpty);

      await tester.enterText(
        find.descendant(of: find.byKey(const ValueKey<String>('progress-correct-qty')), matching: find.byType(TextField)),
        '50',
      );
      await tester.enterText(
        find.descendant(of: find.byKey(const ValueKey<String>('progress-correct-remark')), matching: find.byType(TextField)),
        'Counted twice',
      );
      await tester.tap(find.byKey(const ValueKey<String>('progress-correct-save')));
      await settle(tester);

      final OutboxRow row = (await openRows(app)).single;
      expect(row.payload, contains('"remark":"Counted twice"'));
      expect(find.text('50.000 cum'), findsOneWidget, reason: 'the list agrees with the totals at once');
      expect(find.text('Correction not sent yet'), findsOneWidget);
      expect(find.text('Correction saved. Sending now.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('with no network the correction says it will wait', (tester) async {
      final TestApp app = await floorApp();
      await pumpProgress(tester, app, start: Routes.progressFloor(11));
      app.connectivity.setOnline(false);
      await openCorrection(tester, '60.000 cum');
      await tester.enterText(
        find.descendant(of: find.byKey(const ValueKey<String>('progress-correct-qty')), matching: find.byType(TextField)),
        '55',
      );
      await tester.enterText(
        find.descendant(of: find.byKey(const ValueKey<String>('progress-correct-remark')), matching: find.byType(TextField)),
        'Recount',
      );
      await tester.tap(find.byKey(const ValueKey<String>('progress-correct-save')));
      await settle(tester);

      expect(find.text('Correction saved on this phone. It will send when you are online.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 5));
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('the floor and the correction sheet fit a small phone in $locale', (tester) async {
        final TestApp app = await floorApp();
        app.api.addProgressEntry(
          boqItemId: 1,
          floorId: 11,
          qty: '1234567.500',
          remark: 'Slab pour on the third floor near the staircase, second lift',
        );
        await pumpProgress(tester, app, start: Routes.progressFloor(11), locale: locale);
        final AppLocalizations l = await AppLocalizations.delegate.load(Locale(locale));
        await tester.ensureVisible(find.text(l.progressRecentEntries));
        expect(tester.takeException(), isNull, reason: 'floor');
        expect(find.text(l.progressCorrect), findsNWidgets(3));

        await tester.ensureVisible(find.text(l.progressCorrect).first);
        await tester.pump();
        await tester.tap(find.text(l.progressCorrect).first);
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'sheet');
        expect(find.text(l.progressCorrectTitle), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey<String>('progress-correct-save')));
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'errors');
        expect(find.text(l.progressCorrectSame), findsOneWidget);
        expect(find.text(l.progressCorrectRemarkRequired), findsOneWidget);
      });
    }

    testWidgets('the sheet controls are at least 56dp high', (tester) async {
      final TestApp app = await floorApp();
      await pumpProgress(tester, app, start: Routes.progressFloor(11));
      await openCorrection(tester, '60.000 cum');

      for (final Key key in <Key>[
        const ValueKey<String>('progress-correct-qty'),
        const ValueKey<String>('progress-correct-remark'),
        const ValueKey<String>('progress-correct-save'),
      ]) {
        expect(tester.getSize(find.byKey(key)).height, greaterThanOrEqualTo(56), reason: '$key');
      }
      expect(tester.getSize(find.byType(RecentEntryTile).first).height, greaterThanOrEqualTo(56));
    });
  });
}
