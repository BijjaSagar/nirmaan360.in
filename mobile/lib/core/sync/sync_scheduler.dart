import 'dart:async';
import 'dart:math';

import 'package:flutter/widgets.dart';

import '../db/app_database.dart';
import 'connectivity.dart';
import 'sync_engine.dart';
import 'sync_status.dart';

/// Decides when to sync: after local writes (debounced), when connectivity
/// returns, when the app resumes, and on a foreground timer with backoff.
class SyncScheduler {
  SyncScheduler({
    required this._db,
    required this._engine,
    required this._connectivity,
    Random? random,
    this.debounce = const Duration(seconds: 2),
    this.baseInterval = const Duration(minutes: 5),
    this.minBackoff = const Duration(seconds: 30),
    this.maxBackoff = const Duration(minutes: 15),
    this.observeLifecycle = true,
  }) : _random = random ?? Random();

  final AppDatabase _db;
  final SyncEngine _engine;
  final ConnectivityMonitor _connectivity;
  final Random _random;

  final Duration debounce;
  final Duration baseInterval;
  final Duration minBackoff;
  final Duration maxBackoff;
  final bool observeLifecycle;

  /// Runs before each sync without holding it up. Failures are its own.
  Future<void> Function()? beforeRun;

  StreamSubscription<_OutboxSnapshot>? _outboxSub;
  StreamSubscription<bool>? _connectivitySub;
  AppLifecycleListener? _lifecycle;
  Timer? _debounceTimer;
  Timer? _intervalTimer;

  _OutboxSnapshot? _lastSnapshot;
  int _consecutiveFailures = 0;
  bool _started = false;

  void start() {
    if (_started) return;
    _started = true;

    _outboxSub = _watchOutbox().listen(_onOutboxChanged);
    _connectivitySub = _connectivity.changes.listen((online) {
      if (online) _runSoon();
    });
    if (observeLifecycle) {
      _lifecycle = AppLifecycleListener(onResume: onResume);
    }
    _scheduleNext(baseInterval);
  }

  Future<void> stop() async {
    _started = false;
    _debounceTimer?.cancel();
    _intervalTimer?.cancel();
    _lifecycle?.dispose();
    _lifecycle = null;
    await _outboxSub?.cancel();
    await _connectivitySub?.cancel();
    _outboxSub = null;
    _connectivitySub = null;
    _lastSnapshot = null;
  }

  /// The app came back to the foreground.
  void onResume() => _runSoon();

  Stream<_OutboxSnapshot> _watchOutbox() {
    return _db
        .customSelect(
          "SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM outbox "
          "WHERE status = 'pending'",
          readsFrom: {_db.outbox},
        )
        .watchSingle()
        .map((row) {
          return _OutboxSnapshot(row.read<int>('c'), row.read<int>('m'));
        });
  }

  void _onOutboxChanged(_OutboxSnapshot snapshot) {
    final previous = _lastSnapshot;
    _lastSnapshot = snapshot;

    // Rows moving from pending to done shrink the count. Only growth is work
    // that was just created, and coalesced taps need no extra run.
    final isNewWork =
        previous == null ||
        snapshot.pending > previous.pending ||
        snapshot.maxPendingId > previous.maxPendingId;
    if (isNewWork && snapshot.pending > 0) {
      _debounceTimer?.cancel();
      _debounceTimer = Timer(debounce, _runIfOnline);
    }
  }

  void _runSoon() {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(Duration.zero, _runIfOnline);
  }

  Future<void> _runIfOnline() async {
    if (!_connectivity.isOnline) {
      _scheduleNext(_backoffDelay(SyncOutcome.offline));
      return;
    }
    unawaited(beforeRun?.call());
    SyncRunResult result;
    try {
      result = await _engine.syncNow();
    } catch (error) {
      // This runs from a timer, so nothing above could handle it.
      result = SyncRunResult(SyncOutcome.serverError, error: error);
    }
    if (!_started) return;

    if (result.isSuccess) {
      _consecutiveFailures = 0;
    } else if (result.outcome != SyncOutcome.rateLimited &&
        result.outcome != SyncOutcome.busy) {
      _consecutiveFailures++;
    }
    _scheduleNext(delayAfter(result));
  }

  /// When to try again after [result].
  @visibleForTesting
  Duration delayAfter(SyncRunResult result) {
    if (result.isSuccess) return baseInterval;
    if (result.outcome == SyncOutcome.rateLimited) {
      final wait = result.retryAfter ?? const Duration(seconds: 60);
      return wait + _addedJitter(wait);
    }
    return _backoffDelay(result.outcome);
  }

  /// Only ever adds time, so a retry can never land before `Retry-After`.
  Duration _addedJitter(Duration wait) {
    final spread = wait.inMilliseconds ~/ 5;
    return Duration(milliseconds: _random.nextInt(spread + 1000));
  }

  /// Exponential with jitter, so a fleet of phones does not retry in step
  /// when a tower or the server comes back.
  Duration _backoffDelay(SyncOutcome outcome) {
    if (outcome == SyncOutcome.busy) return _jitter(const Duration(seconds: 60));
    final exponent = min(_consecutiveFailures, 6);
    final delay = minBackoff * (1 << exponent);
    return _jitter(delay > maxBackoff ? maxBackoff : delay);
  }

  Duration _jitter(Duration delay) {
    // Plus or minus twenty percent, computed in milliseconds.
    final spread = delay.inMilliseconds ~/ 5;
    final offset = _random.nextInt(2 * spread + 1) - spread;
    return Duration(milliseconds: delay.inMilliseconds + offset);
  }

  void _scheduleNext(Duration delay) {
    if (!_started) return;
    _intervalTimer?.cancel();
    _intervalTimer = Timer(delay, _runIfOnline);
  }
}

class _OutboxSnapshot {
  const _OutboxSnapshot(this.pending, this.maxPendingId);

  final int pending;
  final int maxPendingId;
}
