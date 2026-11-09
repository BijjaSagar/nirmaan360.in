import 'package:flutter/foundation.dart';

enum SyncPhase { idle, pushing, uploading, pulling }

/// What stopped the last run, if anything.
enum SyncIssue { none, offline, authExpired, rateLimited, serverError }

/// Progress of the phase in flight, for a progress line.
@immutable
class SyncProgress {
  const SyncProgress({required this.done, required this.total, this.label});

  final int done;
  final int total;

  /// A record's display title, when one is being worked on.
  final String? label;
}

@immutable
class SyncStatus {
  const SyncStatus({
    this.phase = SyncPhase.idle,
    this.online = true,
    this.pendingCount = 0,
    this.attentionCount = 0,
    this.lastSyncedAt,
    this.progress,
    this.lastIssue = SyncIssue.none,
  });

  final SyncPhase phase;
  final bool online;

  /// Items waiting to be sent (pending or in flight, including blocked ones).
  final int pendingCount;

  /// Items that need a person to decide: retry or discard.
  final int attentionCount;

  /// Device time of the last fully successful run.
  final DateTime? lastSyncedAt;

  final SyncProgress? progress;
  final SyncIssue lastIssue;

  bool get isSyncing => phase != SyncPhase.idle;
}

enum SyncOutcome {
  /// Push and pull both finished. Attention items may still exist.
  success,

  /// Another engine holds the lease; this run did nothing.
  busy,

  offline,
  authExpired,

  /// The server asked for a pause. Nothing is wrong with the data.
  rateLimited,

  serverError,
}

@immutable
class SyncRunResult {
  const SyncRunResult(
    this.outcome, {
    this.sent = 0,
    this.error,
    this.retryAfter,
  });

  final SyncOutcome outcome;

  /// Outbox rows that reached a final state in this run.
  final int sent;
  final Object? error;

  /// For [SyncOutcome.rateLimited]: how long the server asked to wait.
  final Duration? retryAfter;

  bool get isSuccess => outcome == SyncOutcome.success;
}
