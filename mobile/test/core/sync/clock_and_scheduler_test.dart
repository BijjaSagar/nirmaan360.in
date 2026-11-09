import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/background_sync.dart';
import 'package:nirmaan360/core/sync/clock.dart';
import 'package:nirmaan360/core/sync/ids.dart';
import 'package:nirmaan360/core/sync/sync_scheduler.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../../support/test_app.dart';

void main() {
  group('nextClientStamp', () {
    final now = DateTime.utc(2026, 11, 9, 10);

    test('is now when there is no history', () {
      expect(nextClientStamp(now, const []), now);
    });

    test('drops sub-second precision', () {
      final fractional = now.add(const Duration(milliseconds: 750));
      expect(nextClientStamp(fractional, const []), now);
    });

    test('two edits inside one second still differ', () {
      final first = nextClientStamp(now, const []);
      final second = nextClientStamp(
        now.add(const Duration(milliseconds: 200)),
        [first],
      );
      expect(second, first.add(const Duration(seconds: 1)));
    });

    test('never goes backwards when the clock does', () {
      final previous = now.add(const Duration(hours: 1));
      expect(
        nextClientStamp(now, [previous]),
        previous.add(const Duration(seconds: 1)),
      );
    });

    test('follows the newest of several stamps', () {
      final older = now.subtract(const Duration(hours: 1));
      final newer = now.add(const Duration(hours: 1));
      expect(
        nextClientStamp(now, [older, null, newer]),
        newer.add(const Duration(seconds: 1)),
      );
    });
  });

  group('wire formats', () {
    test('timestamps are India time with whole seconds', () {
      expect(
        toWireTimestamp(DateTime.utc(2026, 11, 9, 4, 30, 1, 500)),
        '2026-11-09T10:00:01+05:30',
      );
    });

    test('a date near midnight belongs to India', () {
      expect(istDate(DateTime.utc(2026, 11, 9, 18, 29)), '2026-11-09');
      expect(istDate(DateTime.utc(2026, 11, 9, 18, 31)), '2026-11-10');
    });

    test('server stamps parse, blanks are null', () {
      expect(
        parseServerTime('2026-11-09T10:00:00+05:30'),
        DateTime.utc(2026, 11, 9, 4, 30),
      );
      expect(parseServerTime(''), isNull);
      expect(parseServerTime(null), isNull);
    });
  });

  group('stableUuid', () {
    test('is stable and differs by type and id', () {
      expect(stableUuid('dpr', 1), stableUuid('dpr', 1));
      expect(stableUuid('dpr', 1), isNot(stableUuid('dpr', 2)));
      expect(stableUuid('dpr', 1), isNot(stableUuid('issue', 1)));
    });

    test('is a valid version 5 uuid', () {
      expect(stableUuid('dpr', 1)[14], '5');
    });
  });

  group('scheduler', () {
    late TestApp app;
    late SyncScheduler scheduler;

    setUp(() async {
      app = await TestApp.create();
    });

    tearDown(() async {
      await scheduler.stop();
      await app.dispose();
    });

    SyncScheduler build() => scheduler = SyncScheduler(
      db: app.db,
      engine: app.services.sync,
      connectivity: app.connectivity,
      random: Random(1),
      debounce: const Duration(milliseconds: 20),
      observeLifecycle: false,
    );

    MeasurementInput entry() => MeasurementInput(
      projectId: 1,
      boqItemId: 1,
      measuredOn: '2026-11-09',
      nos: Qty.parse('1.000'),
    );

    test('a local write triggers a debounced sync', () async {
      build().start();
      await pumpEventQueue();

      await app.services.measurements.create(entry());
      expect(app.api.measurements, isEmpty, reason: 'debounced');

      await Future<void>.delayed(const Duration(milliseconds: 80));
      expect(app.api.measurements, hasLength(1));
    });

    test('rapid writes produce one run', () async {
      build().start();
      await pumpEventQueue();
      final runsBefore = app.api.pullCalls.length;

      for (var i = 0; i < 5; i++) {
        await app.services.measurements.create(entry());
      }
      await Future<void>.delayed(const Duration(milliseconds: 80));

      expect(app.api.measurements, hasLength(5));
      expect(app.api.pullCalls.length, runsBefore + 1);
    });

    test('regaining connectivity triggers a sync', () async {
      app.connectivity.setOnline(false);
      build().start();
      await pumpEventQueue();

      await app.services.measurements.create(entry());
      await Future<void>.delayed(const Duration(milliseconds: 60));
      expect(app.api.measurements, isEmpty, reason: 'offline');

      app.connectivity.setOnline(true);
      await Future<void>.delayed(const Duration(milliseconds: 40));
      expect(app.api.measurements, hasLength(1));
    });

    test('resume triggers a sync', () async {
      build().start();
      await pumpEventQueue();
      final runsBefore = app.api.pullCalls.length;

      scheduler.onResume();
      await Future<void>.delayed(const Duration(milliseconds: 40));

      expect(app.api.pullCalls.length, runsBefore + 1);
    });

    test('backoff grows and is capped, with jitter inside bounds', () {
      build();
      const failure = SyncRunResult(SyncOutcome.serverError);
      for (var i = 0; i < 50; i++) {
        final delay = scheduler.delayAfter(failure);
        expect(delay.inSeconds, inInclusiveRange(24, 36));
      }
    });

    test('success waits the base interval', () {
      build();
      expect(
        scheduler.delayAfter(const SyncRunResult(SyncOutcome.success)),
        const Duration(minutes: 5),
      );
    });
  });

  group('background run', () {
    late TestApp app;

    setUp(() async {
      app = await TestApp.create();
    });

    tearDown(() => app.dispose());

    Future<bool> run() => runBackgroundSyncOnce(
      db: app.db,
      api: app.api,
      attachments: app.attachments,
      connectivity: app.connectivity,
      clock: app.clock,
    );

    test('runs one push and pull with its own engine', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      expect(await run(), isTrue);
      expect(app.api.issues, hasLength(1));
    });

    test('offline is not a failure the OS should retry', () async {
      app.api.offline = true;
      expect(await run(), isTrue);
    });

    test('a server fault asks the OS to retry', () async {
      app.api.failNextPull(const ServerFailure('boom', statusCode: 500));
      expect(await run(), isFalse);
    });

    test('a 429 is not a failure the OS should retry', () async {
      app.api.failNextPull(const RateLimited());
      expect(await run(), isTrue);
    });
  });
}
