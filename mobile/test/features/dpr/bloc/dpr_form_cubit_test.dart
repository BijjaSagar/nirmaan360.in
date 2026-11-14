import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/bloc/dpr_form_cubit.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';

import '../../../support/test_app.dart';

const String today = '2026-11-09';
const String yesterday = '2026-11-08';

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  Future<void> insertYesterday({int? count = 12, int? boqItemId}) async {
    await app.db.into(app.db.dprs).insert(
      DprsCompanion.insert(
        clientUuid: 'yesterday',
        projectId: 1,
        reportDate: yesterday,
        status: const Value(DocStatus.approved),
        manpower: count == null ? const [] : [ManpowerLine(trade: 'Mason', count: count)],
        machinery: const [],
        workItems: [
          if (boqItemId != null) WorkLine(boqItemId: boqItemId, qty: Qty.parse('1')),
        ],
      ),
    );
  }

  /// A cubit with no save delay, so a test can just pump the event queue.
  Future<DprFormCubit> form({String? uuid, String? date = today}) async {
    final cubit = DprFormCubit(
      services: app.services,
      clientUuid: uuid,
      date: date,
      saveDelay: Duration.zero,
    );
    addTearDown(() async {
      if (!cubit.isClosed) await cubit.close();
    });
    await cubit.load();
    await pumpEventQueue();
    return cubit;
  }

  Future<List<OutboxRow>> outbox() =>
      (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

  Future<DprRow?> savedRow() async {
    final rows = await app.db.select(app.db.dprs).get();
    return rows.where((r) => r.reportDate == today).firstOrNull;
  }

  group('load', () {
    test('a new date starts blank and offers nothing to copy', () async {
      final cubit = await form();
      expect(cubit.state.phase, DprFormPhase.editing);
      expect(cubit.state.reportDate, today);
      expect(cubit.state.clientUuid, isNull);
      expect(cubit.state.yesterdayTotal, isNull);
      expect(cubit.state.weatherOptions, contains('Rain'));
    });

    test('an existing draft for the date opens that record', () async {
      final uuid = await app.services.dpr.saveDraft(
        const DprInput(
          projectId: 1,
          reportDate: today,
          weather: 'Hot',
          manpower: [ManpowerLine(trade: 'Helper', count: 4)],
        ),
      );
      final cubit = await form();
      expect(cubit.state.clientUuid, uuid);
      expect(cubit.state.weather, 'Hot');
      expect(cubit.state.count('Helper'), 4);
    });

    test('trades with counts sort first', () async {
      await app.services.dpr.saveDraft(
        const DprInput(
          projectId: 1,
          reportDate: today,
          manpower: [ManpowerLine(trade: 'Carpenter', count: 2)],
        ),
      );
      final cubit = await form();
      expect(cubit.state.trades.first, 'Carpenter');
    });

    test('a report that has gone for approval is locked', () async {
      final uuid = await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: today, weather: 'Clear'),
      );
      await app.services.dpr.send(uuid);
      final cubit = await form();
      expect(cubit.state.phase, DprFormPhase.locked);
      expect(cubit.state.clientUuid, uuid);
    });
  });

  group('autosave', () {
    test('saves on change, adds nothing to the outbox', () async {
      final cubit = await form();
      cubit.setWeather('Rain');
      await pumpEventQueue();
      expect(cubit.state.save, DprSaveStatus.saved);
      expect((await savedRow())!.weather, 'Rain');
      expect(await outbox(), isEmpty);
    });

    test('a pending change is written when the cubit closes', () async {
      final cubit = DprFormCubit(
        services: app.services,
        date: today,
        saveDelay: const Duration(hours: 1),
      );
      await cubit.load();
      cubit.setCount('Mason', 3);
      await cubit.close();
      expect((await savedRow())!.manpower.single.count, 3);
    });

    test('a later session picks up where it left off', () async {
      final first = await form();
      first
        ..setWeather('Windy')
        ..setCount('Mason', 7);
      await pumpEventQueue();
      await first.close();

      final second = await form();
      expect(second.state.weather, 'Windy');
      expect(second.state.count('Mason'), 7);
    });

    test('keeps exact decimal quantities', () async {
      final cubit = await form();
      cubit.putWork(WorkLine(boqItemId: 1, qty: Qty.parse('12.345')));
      await pumpEventQueue();
      expect((await savedRow())!.workItems.single.qty.toWire(), '12.345');
    });
  });

  group('counting', () {
    test('never reorders or folds rows under the thumb', () async {
      final cubit = await form();
      final before = List<String>.of(cubit.state.trades);
      final shown = cubit.state.visibleTradeCount;

      cubit.setCount(before.last, 5);

      expect(cubit.state.trades, before);
      expect(cubit.state.visibleTradeCount, shown);
    });

    test('counting to zero removes the line', () async {
      final cubit = await form();
      cubit.setCount('Mason', 2);
      cubit.setCount('Mason', 0);
      await pumpEventQueue();
      expect((await savedRow())!.manpower, isEmpty);
    });
  });

  group('same as yesterday', () {
    test('offered only when there is a yesterday with workers', () async {
      await insertYesterday();
      final cubit = await form();
      expect(cubit.state.canCopyYesterday, isTrue);
      expect(cubit.state.yesterdayTotal, 12);
    });

    test('fills the counts in one call and sorts them first', () async {
      await insertYesterday(count: 9);
      final cubit = await form();
      final copied = await cubit.copyYesterday();
      await pumpEventQueue();

      expect(copied, 9);
      expect(cubit.state.count('Mason'), 9);
      expect(cubit.state.trades.first, 'Mason');
      expect(cubit.state.canCopyYesterday, isFalse);
      expect((await savedRow())!.manpower.single.count, 9);
    });

    test('yesterday with no workers is not offered', () async {
      await insertYesterday(count: null);
      final cubit = await form();
      expect(cubit.state.canCopyYesterday, isFalse);
    });

    test('remembers what was worked on yesterday', () async {
      await insertYesterday(boqItemId: 1);
      final cubit = await form();
      expect(cubit.state.yesterdayWorkIds, {1});
    });
  });

  group('validation', () {
    test('points at the weather and the workers sections', () async {
      final cubit = await form();
      expect(cubit.validate(), isFalse);
      expect(cubit.state.problems, {DprFormProblem.weather, DprFormProblem.workers});
    });

    test('a message clears as soon as the section is answered', () async {
      final cubit = await form();
      cubit.validate();
      cubit.setWeather('Clear');
      expect(cubit.state.problems, {DprFormProblem.workers});
      cubit.setCount('Mason', 1);
      expect(cubit.state.problems, isEmpty);
    });

    test('no workers is fine when a hindrance explains it', () async {
      final cubit = await form();
      cubit
        ..setWeather('Rain')
        ..setHindrances('Site closed for rain.');
      expect(cubit.validate(), isTrue);
    });

    test('a report for a future date is refused plainly', () async {
      final cubit = await form(date: '2026-11-10');
      cubit
        ..setWeather('Clear')
        ..setCount('Mason', 1);
      expect(await cubit.send(), DprSendResult.futureDate);
      expect(await outbox(), isEmpty);
    });
  });

  group('send', () {
    test('queues the report, then the submission', () async {
      final cubit = await form();
      cubit
        ..setWeather('Clear')
        ..setCount('Mason', 2);

      expect(await cubit.send(), DprSendResult.sent);

      final rows = await outbox();
      expect(rows.map((r) => r.type), [OutboxType.dpr, OutboxType.dprSubmit]);
    });

    test('a second send does nothing', () async {
      final cubit = await form();
      cubit
        ..setWeather('Clear')
        ..setCount('Mason', 2);
      await cubit.send();
      await cubit.send();
      expect(await outbox(), hasLength(2));
    });

    test('a RETURNED report goes through editReturned', () async {
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

      final cubit = await form(uuid: uuid);
      expect(cubit.state.isReturned, isTrue);
      expect(cubit.state.returnRemark, 'Add helpers');

      cubit.setCount('Helper', 5);
      await pumpEventQueue();
      expect(await cubit.send(), DprSendResult.sent);
      await app.sync();

      final row = app.api.dprByUuid(uuid);
      expect(row['status'], 'IN_REVIEW');
      expect((row['manpower'] as List), hasLength(2));
    });
  });
}
