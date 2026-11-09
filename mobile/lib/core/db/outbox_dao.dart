import 'dart:convert';

import 'package:drift/drift.dart';

import 'app_database.dart';

/// Live counts for the sync status line.
class OutboxCounts {
  const OutboxCounts({this.pending = 0, this.attention = 0});

  final int pending;
  final int attention;
}

/// All reads and writes of the outbox.
class OutboxDao {
  OutboxDao(this.db);

  final AppDatabase db;

  /// Queues one operation. Call inside the same transaction as the local row.
  ///
  /// With [coalesce], an existing `pending` or `attention` row for the same
  /// record and type is replaced in place, keeping its queue position: the
  /// newest value is what matters, and a user edit is what resolves an
  /// attention item. A row that is `sending` is never touched, because its
  /// result is about to be applied. A new row is appended behind it instead,
  /// otherwise the later value would be marked sent and lost.
  Future<void> enqueue({
    required OutboxType type,
    required String wireUuid,
    required String recordUuid,
    required String groupUuid,
    required int projectId,
    required Map<String, dynamic> data,
    required DateTime createdAt,
    DateTime? clientUpdatedAt,
    String? filePath,
    String? parentUuid,
    String title = '',
    String? subtitle,
    bool coalesce = false,
  }) async {
    final payload = jsonEncode(data);

    if (coalesce) {
      final replaced =
          await (db.update(db.outbox)..where(
                (t) =>
                    t.recordUuid.equals(recordUuid) &
                    t.type.equals(type.name) &
                    t.status.isIn([
                      OutboxStatus.pending.name,
                      OutboxStatus.attention.name,
                    ]),
              ))
              .write(
                OutboxCompanion(
                  payload: Value(payload),
                  clientUpdatedAt: Value(clientUpdatedAt),
                  status: const Value(OutboxStatus.pending),
                  attentionCode: const Value(null),
                  attentionReason: const Value(null),
                  attentionErrors: const Value(null),
                  resultStatus: const Value(null),
                  attempts: const Value(0),
                  notFoundCount: const Value(0),
                  title: Value(title),
                  subtitle: Value(subtitle),
                ),
              );
      if (replaced > 0) return;
    }

    await db
        .into(db.outbox)
        .insert(
          OutboxCompanion.insert(
            type: type,
            wireUuid: wireUuid,
            recordUuid: recordUuid,
            groupUuid: groupUuid,
            projectId: projectId,
            payload: payload,
            clientUpdatedAt: Value(clientUpdatedAt),
            filePath: Value(filePath),
            parentUuid: Value(parentUuid),
            title: Value(title),
            subtitle: Value(subtitle),
            createdAt: createdAt,
          ),
        );
  }

  /// Every row not yet finished, oldest first.
  Future<List<OutboxRow>> unfinished() {
    return (db.select(db.outbox)
          ..where((t) => t.status.equals(OutboxStatus.done.name).not())
          ..orderBy([(t) => OrderingTerm.asc(t.id)]))
        .get();
  }

  /// Rows that may be sent now: the oldest unfinished row of each group, when
  /// that row is `pending`. A group whose head is `attention` is blocked.
  Future<List<OutboxRow>> eligibleHeads({
    Set<String> skipGroups = const {},
  }) async {
    final seenGroups = <String>{};
    final heads = <OutboxRow>[];
    for (final row in await unfinished()) {
      if (!seenGroups.add(row.groupUuid)) continue;
      if (skipGroups.contains(row.groupUuid)) continue;
      if (row.status == OutboxStatus.pending) heads.add(row);
    }
    return heads;
  }

  /// The ids of rows waiting on something other than their own turn.
  Set<int> blockedIds(List<OutboxRow> unfinished) {
    final attentionGroups = <String>{};
    final blocked = <int>{};
    for (final row in unfinished) {
      if (row.status == OutboxStatus.attention) {
        attentionGroups.add(row.groupUuid);
      } else if (attentionGroups.contains(row.groupUuid)) {
        blocked.add(row.id);
      }
    }
    return blocked;
  }

  Future<void> markSending(Iterable<int> ids, DateTime now) {
    return db.customUpdate(
      'UPDATE outbox SET status = ?, attempts = attempts + 1, '
      'last_attempt_at = ? '
      'WHERE id IN (${ids.join(',')})',
      variables: [
        Variable<String>(OutboxStatus.sending.name),
        Variable<DateTime>(now),
      ],
      updates: {db.outbox},
    );
  }

  /// Returns rows that were `sending` to `pending`. Used when a send failed.
  Future<void> releaseSending(Iterable<int> ids) {
    return (db.update(db.outbox)..where(
          (t) =>
              t.id.isIn(ids) & t.status.equals(OutboxStatus.sending.name),
        ))
        .write(const OutboxCompanion(status: Value(OutboxStatus.pending)));
  }

  /// Puts every `sending` row back to `pending` after a crash or kill.
  ///
  /// Safe because the server is idempotent on `client_uuid`: a row that did
  /// reach the server before the kill comes back as `duplicate`.
  Future<int> recoverInterrupted() {
    return (db.update(db.outbox)
          ..where((t) => t.status.equals(OutboxStatus.sending.name)))
        .write(const OutboxCompanion(status: Value(OutboxStatus.pending)));
  }

  /// Finishes a row that is still `sending`. Returns false if it moved on.
  Future<bool> markDone(int id, String resultStatus, DateTime now) async {
    final updated =
        await (db.update(db.outbox)..where(
              (t) =>
                  t.id.equals(id) & t.status.equals(OutboxStatus.sending.name),
            ))
            .write(
              OutboxCompanion(
                status: const Value(OutboxStatus.done),
                resultStatus: Value(resultStatus),
                doneAt: Value(now),
              ),
            );
    return updated > 0;
  }

  /// Parks a row for a person to decide. Never retried automatically.
  Future<bool> markAttention(
    int id, {
    required String resultStatus,
    required String code,
    required String reason,
    Map<String, List<String>>? errors,
  }) async {
    final updated =
        await (db.update(db.outbox)..where(
              (t) =>
                  t.id.equals(id) & t.status.equals(OutboxStatus.sending.name),
            ))
            .write(
              OutboxCompanion(
                status: const Value(OutboxStatus.attention),
                resultStatus: Value(resultStatus),
                attentionCode: Value(code),
                attentionReason: Value(reason),
                attentionErrors: Value(
                  errors == null ? null : jsonEncode(errors),
                ),
              ),
            );
    return updated > 0;
  }

  /// Sends an attention row again after a person asked for it.
  Future<void> retry(int id) {
    return (db.update(db.outbox)..where(
          (t) =>
              t.id.equals(id) & t.status.equals(OutboxStatus.attention.name),
        ))
        .write(
          const OutboxCompanion(
            status: Value(OutboxStatus.pending),
            attentionCode: Value(null),
            attentionReason: Value(null),
            attentionErrors: Value(null),
            resultStatus: Value(null),
            attempts: Value(0),
            notFoundCount: Value(0),
          ),
        );
  }

  /// Returns a `sending` upload to `pending` after a 404 for its parent.
  Future<void> releaseAfterNotFound(int id) {
    return db.customUpdate(
      'UPDATE outbox SET status = ?, not_found_count = not_found_count + 1 '
      'WHERE id = ? AND status = ?',
      variables: [
        Variable<String>(OutboxStatus.pending.name),
        Variable<int>(id),
        Variable<String>(OutboxStatus.sending.name),
      ],
      updates: {db.outbox},
    );
  }

  /// Removes finished rows older than [cutoff]; recent ones form the sync log.
  Future<void> pruneDone(DateTime cutoff) {
    return (db.delete(db.outbox)..where(
          (t) =>
              t.status.equals(OutboxStatus.done.name) &
              t.doneAt.isSmallerThanValue(cutoff),
        ))
        .go();
  }

  /// True when the record still has work that has not reached the server.
  Future<bool> hasUnsent(String recordUuid) async {
    final row =
        await (db.select(db.outbox)
              ..where(
                (t) =>
                    t.recordUuid.equals(recordUuid) &
                    t.status.equals(OutboxStatus.done.name).not(),
              )
              ..limit(1))
            .getSingleOrNull();
    return row != null;
  }

  /// Record uuids with unfinished rows, for the pull merge.
  Future<Set<String>> unsentRecordUuids() async {
    final query = db.selectOnly(db.outbox, distinct: true)
      ..addColumns([db.outbox.recordUuid])
      ..where(db.outbox.status.equals(OutboxStatus.done.name).not());
    final rows = await query.get();
    return rows.map((r) => r.read(db.outbox.recordUuid)!).toSet();
  }

  Future<int> unsentCount() async {
    final count = db.outbox.id.count();
    final query = db.selectOnly(db.outbox)
      ..addColumns([count])
      ..where(db.outbox.status.equals(OutboxStatus.done.name).not());
    final row = await query.getSingle();
    return row.read(count) ?? 0;
  }

  /// Live pending and attention counts.
  Stream<OutboxCounts> watchCounts() {
    return db
        .customSelect(
          'SELECT status, COUNT(*) AS c FROM outbox GROUP BY status',
          readsFrom: {db.outbox},
        )
        .watch()
        .map((rows) {
          var pending = 0;
          var attention = 0;
          for (final row in rows) {
            final status = row.read<String>('status');
            final count = row.read<int>('c');
            if (status == OutboxStatus.attention.name) {
              attention = count;
            } else if (status != OutboxStatus.done.name) {
              pending += count;
            }
          }
          return OutboxCounts(pending: pending, attention: attention);
        });
  }
}
