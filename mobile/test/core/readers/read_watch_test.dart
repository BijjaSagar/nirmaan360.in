import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/readers/read_watch.dart';
import 'package:nirmaan360/core/sync/clock.dart';

import '../../support/fake_api.dart';

/// A clock that only moves when the watcher waits, so a ten-minute watch runs at once.
class SteppingClock implements AppClock {
  DateTime _now = DateTime.utc(2026, 11, 13, 9);
  final List<Duration> pauses = <Duration>[];

  @override
  DateTime now() => _now;

  Future<void> wait(Duration pause) async {
    pauses.add(pause);
    _now = _now.add(pause);
  }
}

Map<String, dynamic> status(String state, {int read = 0}) => <String, dynamic>{
  'kind': 'slip',
  'status': state,
  'pages_total': 1,
  'pages_read': read,
};

void main() {
  late FakeApi api;
  late SteppingClock clock;
  late ReadWatcher watcher;

  setUp(() {
    api = FakeApi();
    clock = SteppingClock();
    watcher = ReadWatcher(api, clock: clock, wait: clock.wait);
  });

  test('asks every 3 s for 30 s, then every 10 s', () {
    const ReadPollRule rule = ReadPollRule();

    expect(rule.pauseAfter(Duration.zero), const Duration(seconds: 3));
    expect(rule.pauseAfter(const Duration(seconds: 29)), const Duration(seconds: 3));
    expect(rule.pauseAfter(const Duration(seconds: 30)), const Duration(seconds: 10));
    expect(rule.pauseAfter(const Duration(minutes: 9)), const Duration(seconds: 10));
  });

  test('asks at once, and stops when the read is ready', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[
      status('running'),
      status('running'),
      status('ready', read: 1),
    ];

    final List<ReadWatchEvent> events = await watcher.watch(7).toList();

    expect(api.readerCalls, ['status 7', 'status 7', 'status 7']);
    expect(clock.pauses, [const Duration(seconds: 3), const Duration(seconds: 3)]);
    expect(events.whereType<ReadStatusSeen>().map((e) => e.status.status), ['running', 'running', 'ready']);
    final ReadWatchEnded end = events.last as ReadWatchEnded;
    expect(end.reason, ReadWatchStop.settled);
    expect(end.last!.pagesRead, 1);
  });

  test('a read that is paused or waiting to start is not watched', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[status('paused')];
    api.readStatusScript[8] = <Map<String, dynamic>>[status('quoted')];

    final ReadWatchEnded paused = (await watcher.watch(7).last) as ReadWatchEnded;
    final ReadWatchEnded quoted = (await watcher.watch(8).last) as ReadWatchEnded;

    expect(paused.reason, ReadWatchStop.settled);
    expect(paused.last!.isPaused, isTrue);
    expect(quoted.reason, ReadWatchStop.settled);
    expect(clock.pauses, isEmpty);
  });

  test('gives up after ten minutes with the read still working', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[status('running')];

    final List<ReadWatchEvent> events = await watcher.watch(7).toList();

    // At 0, 3 ... 30 s, then every 10 s up to 600 s: 11 + 57 questions.
    expect(api.readerCalls.length, 68);
    expect(clock.pauses.take(10), everyElement(const Duration(seconds: 3)));
    expect(clock.pauses.skip(10), everyElement(const Duration(seconds: 10)));
    final ReadWatchEnded end = events.last as ReadWatchEnded;
    expect(end.reason, ReadWatchStop.stillWorking);
    expect(end.last!.isWorking, isTrue);
  });

  test('keeps asking through a lost network', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[status('running'), status('ready', read: 1)];
    api.failNextReaderCall(const OfflineFailure());

    final List<ReadWatchEvent> events = await watcher.watch(7).toList();

    expect(events.first, isA<ReadStatusMissed>());
    expect((events.first as ReadStatusMissed).failure, isA<OfflineFailure>());
    expect((events.last as ReadWatchEnded).reason, ReadWatchStop.settled);
  });

  test('waits as long as a 429 asks', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[status('ready', read: 1)];
    api.failNextReaderCall(const RateLimited(retryAfter: Duration(seconds: 20)));

    await watcher.watch(7).toList();

    expect(clock.pauses, [const Duration(seconds: 20)]);
  });

  test('stops when the session has ended', () async {
    api.failNextReaderCall(const AuthExpired());

    final List<ReadWatchEvent> events = await watcher.watch(7).toList();

    final ReadWatchEnded end = events.single as ReadWatchEnded;
    expect(end.reason, ReadWatchStop.refused);
    expect(end.failure, isA<AuthExpired>());
  });

  test('stops when the read is gone or not this person\'s', () async {
    api.failNextReaderCall(const ServerFailure('Not found.', statusCode: 404));

    final ReadWatchEnded end = (await watcher.watch(7).last) as ReadWatchEnded;

    expect(end.reason, ReadWatchStop.refused);
    expect(api.readerCalls, ['status 7']);
  });

  test('a server error is a missed question, not the end', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[status('failed')];
    api.failNextReaderCall(const ServerFailure('Server error.', statusCode: 500));

    final List<ReadWatchEvent> events = await watcher.watch(7).toList();

    expect(events.first, isA<ReadStatusMissed>());
    expect((events.last as ReadWatchEnded).last!.isFinished, isTrue);
  });

  test('cancelling the subscription stops the questions', () async {
    api.readStatusScript[7] = <Map<String, dynamic>>[status('running')];
    final List<ReadWatchEvent> seen = <ReadWatchEvent>[];

    await for (final ReadWatchEvent event in watcher.watch(7)) {
      seen.add(event);
      if (seen.length == 2) break;
    }

    expect(api.readerCalls, ['status 7', 'status 7']);
  });
}
