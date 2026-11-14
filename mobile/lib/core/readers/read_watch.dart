import '../api/api_failure.dart';
import '../api/nirmaan_api.dart';
import '../sync/clock.dart';

/// How often the phone asks about a read the server is working on: every 3 s
/// for the first 30 s, then every 10 s, and it stops asking after 10 minutes.
/// The screen then says "Still working. Pull to refresh.", and the person is
/// told by a notification when the read is done.
///
/// The server tends a read on every status request, so asking also keeps a
/// watched read moving when its scheduler is not running.
class ReadPollRule {
  const ReadPollRule({
    this.fast = const Duration(seconds: 3),
    this.fastFor = const Duration(seconds: 30),
    this.slow = const Duration(seconds: 10),
    this.giveUpAfter = const Duration(minutes: 10),
  });

  final Duration fast;
  final Duration fastFor;
  final Duration slow;
  final Duration giveUpAfter;

  /// The pause before the next question, [elapsed] after watching began.
  Duration pauseAfter(Duration elapsed) => elapsed < fastFor ? fast : slow;
}

/// What a watch reports. It always ends with one [ReadWatchEnded].
sealed class ReadWatchEvent {
  const ReadWatchEvent();
}

/// The server answered.
final class ReadStatusSeen extends ReadWatchEvent {
  const ReadStatusSeen(this.status);

  final DocumentReadStatus status;
}

/// A question went unanswered: no network, a 429 or a server error. The watch
/// carries on; a screen may show that the phone is offline.
final class ReadStatusMissed extends ReadWatchEvent {
  const ReadStatusMissed(this.failure);

  final ApiFailure failure;
}

/// Why a watch stopped.
enum ReadWatchStop {
  /// The read is no longer being worked on: ready, failed, paused, closed, or
  /// waiting for the person to start it.
  settled,

  /// Ten minutes passed and the read is still working. "Still working. Pull to refresh."
  stillWorking,

  /// The server will not answer this person about this read: the session has
  /// ended, or the read is gone or not theirs.
  refused,
}

/// The last event of a watch.
final class ReadWatchEnded extends ReadWatchEvent {
  const ReadWatchEnded(this.reason, {this.last, this.failure});

  final ReadWatchStop reason;

  /// The last status the server gave, if any.
  final DocumentReadStatus? last;

  /// Set when [reason] is [ReadWatchStop.refused].
  final ApiFailure? failure;
}

/// Watches one read under the [ReadPollRule].
///
/// Listening starts the watch with an immediate question; cancelling the
/// subscription stops it. Nothing is cached and nothing goes through the outbox.
class ReadWatcher {
  ReadWatcher(
    this._api, {
    this._clock = const SystemClock(),
    Future<void> Function(Duration pause)? wait,
    this.rule = const ReadPollRule(),
  }) : _wait = wait ?? Future<void>.delayed;

  final NirmaanApi _api;
  final AppClock _clock;
  final Future<void> Function(Duration pause) _wait;
  final ReadPollRule rule;

  Stream<ReadWatchEvent> watch(int readId) async* {
    final DateTime began = _clock.now();
    DocumentReadStatus? last;

    while (true) {
      Duration? serverAskedFor;
      try {
        final DocumentReadStatus status = await _api.documentRead(readId);
        last = status;
        yield ReadStatusSeen(status);
        if (!status.isWorking) {
          yield ReadWatchEnded(ReadWatchStop.settled, last: status);
          return;
        }
      } on ApiFailure catch (failure) {
        if (_isFinal(failure)) {
          yield ReadWatchEnded(ReadWatchStop.refused, last: last, failure: failure);
          return;
        }
        if (failure is RateLimited) serverAskedFor = failure.retryAfter;
        yield ReadStatusMissed(failure);
      }

      final Duration elapsed = _clock.now().difference(began);
      if (elapsed >= rule.giveUpAfter) {
        yield ReadWatchEnded(ReadWatchStop.stillWorking, last: last);
        return;
      }
      Duration pause = rule.pauseAfter(elapsed);
      if (serverAskedFor != null && serverAskedFor > pause) pause = serverAskedFor;
      await _wait(pause);
    }
  }

  /// Asking again would get the same answer: the session has ended, or this
  /// person may not see this read, or it no longer exists.
  static bool _isFinal(ApiFailure failure) => switch (failure) {
    AuthExpired() => true,
    ServerFailure(:final int? statusCode) => statusCode == 403 || statusCode == 404 || statusCode == 410,
    _ => false,
  };
}
