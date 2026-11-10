import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/db/app_database.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/sync_status.dart';

export '../../../core/db/app_database.dart' show OutboxStatus, OutboxType;
export '../../../core/sync/sync_status.dart';

/// One queued or finished item, ready for the sync screen.
class OutboxItem {
  const OutboxItem({
    required this.row,
    required this.isBlocked,
    this.title = '',
  });

  final OutboxRow row;

  /// True when an earlier failed item in the same record holds this one back.
  final bool isBlocked;

  /// Display title, with attendance resolved to the worker's name.
  final String title;

  int get id => row.id;
  OutboxType get type => row.type;
  OutboxStatus get status => row.status;
  String? get subtitle => row.subtitle;
  String? get attentionCode => row.attentionCode;
  String? get attentionReason => row.attentionReason;

  /// `stale`, `conflict`, and so on, once the server has answered.
  String? get resultStatus => row.resultStatus;

  Map<String, List<String>>? get validationErrors {
    final raw = row.attentionErrors;
    if (raw == null) return null;
    return (jsonDecode(raw) as Map).map(
      (key, value) => MapEntry(
        key as String,
        (value as List).map((e) => e.toString()).toList(),
      ),
    );
  }

  bool get needsAttention => row.status == OutboxStatus.attention;
}

class SyncRepository {
  SyncRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  Stream<SyncStatus> get status => _ctx.sync.status;

  SyncStatus get currentStatus => _ctx.sync.currentStatus;

  /// Runs a sync now. Safe to call at any time.
  Future<SyncRunResult> syncNow() => _ctx.sync.syncNow();

  /// Everything not yet finished, oldest first.
  Stream<List<OutboxItem>> watchOutbox() {
    return _db.watchDerived({_db.outbox, _db.workers}, () async {
      final rows = await _ctx.outbox.unfinished();
      final blocked = _ctx.outbox.blockedIds(rows);
      return _toItems(rows, blocked);
    });
  }

  /// The recent finished items, newest first, including `stale` results the
  /// user should be told about.
  Stream<List<OutboxItem>> watchLog({int limit = 100}) {
    return _db.watchDerived({_db.outbox, _db.workers}, () async {
      final rows =
          await (_db.select(_db.outbox)
                ..where((t) => t.status.equals(OutboxStatus.done.name))
                ..orderBy([(t) => OrderingTerm.desc(t.doneAt)])
                ..limit(limit))
              .get();
      return _toItems(rows, const {});
    });
  }

  /// Sends an attention item again.
  Future<void> retry(int outboxId) => _ctx.outbox.retry(outboxId);

  /// Gives up on an attention item and everything that depends on it.
  ///
  /// Local changes the server never accepted are removed, and the next pull
  /// restores the server's copy. Stored files are left on disk, because a
  /// file is only ever deleted after its upload has succeeded.
  Future<void> discard(int outboxId) {
    return _db.transaction(() async {
      final row =
          await (_db.select(_db.outbox)..where((t) => t.id.equals(outboxId)))
              .getSingleOrNull();
      if (row == null || row.status != OutboxStatus.attention) return;

      await _discardOutboxRows(row);
      await _restoreRecord(row);
    });
  }

  /// Removes this row and, for a record's main write, the rows that depend
  /// on it. A failed photo alone does not cancel the rest of its report.
  Future<void> _discardOutboxRows(OutboxRow row) async {
    final dependants = switch (row.type) {
      OutboxType.dpr ||
      OutboxType.measurement ||
      OutboxType.issue ||
      OutboxType.pettyCash ||
      OutboxType.progressEntry ||
      OutboxType.stageCell => true,
      _ => false,
    };

    if (dependants) {
      await (_db.delete(_db.outbox)..where(
            (t) =>
                t.groupUuid.equals(row.groupUuid) &
                t.id.isBiggerOrEqualValue(row.id) &
                t.status.equals(OutboxStatus.done.name).not(),
          ))
          .go();
    } else {
      await (_db.delete(_db.outbox)..where((t) => t.id.equals(row.id))).go();
    }
  }

  Future<void> _restoreRecord(OutboxRow row) async {
    switch (row.type) {
      case OutboxType.dpr:
        final dpr =
            await (_db.select(_db.dprs)
                  ..where((t) => t.clientUuid.equals(row.recordUuid)))
                .getSingleOrNull();
        if (dpr?.serverId == null) {
          await (_db.delete(_db.dprs)
                ..where((t) => t.clientUuid.equals(row.recordUuid)))
              .go();
          await (_db.delete(_db.dprPhotos)
                ..where((t) => t.dprClientUuid.equals(row.recordUuid)))
              .go();
        } else {
          await _resyncRecord();
        }
      case OutboxType.measurement:
        await _removeOrResync(
          _db.measurements.clientUuid,
          _db.measurements,
          row.recordUuid,
          (await (_db.select(_db.measurements)
                    ..where((t) => t.clientUuid.equals(row.recordUuid)))
                  .getSingleOrNull())
              ?.serverId,
        );
      case OutboxType.issue:
        await _removeOrResync(
          _db.issues.clientUuid,
          _db.issues,
          row.recordUuid,
          (await (_db.select(_db.issues)
                    ..where((t) => t.clientUuid.equals(row.recordUuid)))
                  .getSingleOrNull())
              ?.serverId,
        );
      case OutboxType.attendance:
        await _removeOrResync(
          _db.attendanceMarks.clientUuid,
          _db.attendanceMarks,
          row.recordUuid,
          (await (_db.select(_db.attendanceMarks)
                    ..where((t) => t.clientUuid.equals(row.recordUuid)))
                  .getSingleOrNull())
              ?.serverId,
        );
      case OutboxType.pettyCash:
        await _removeOrResync(
          _db.pettyCashEntries.clientUuid,
          _db.pettyCashEntries,
          row.recordUuid,
          (await (_db.select(_db.pettyCashEntries)
                    ..where((t) => t.clientUuid.equals(row.recordUuid)))
                  .getSingleOrNull())
              ?.serverId,
        );
      case OutboxType.issueStatus:
      case OutboxType.dprSubmit:
      case OutboxType.measurementSubmit:
        await _resyncRecord();
      case OutboxType.dprPhoto:
        await (_db.delete(_db.dprPhotos)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .go();
      case OutboxType.pettyBill:
        await (_db.update(_db.pettyCashEntries)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .write(
              const PettyCashEntriesCompanion(
                hasBill: Value(false),
                billRelativePath: Value(null),
              ),
            );
      // Nothing on the phone besides the outbox row: the screens lay unsent
      // site work over the server's copy, so removing the row is enough.
      case OutboxType.progressEntry:
      case OutboxType.progressResolve:
      case OutboxType.progressPhoto:
      case OutboxType.stageCell:
      case OutboxType.stagePhoto:
      case OutboxType.materialChallan:
      case OutboxType.materialUse:
      case OutboxType.stockCount:
      case OutboxType.issuePhoto:
      case OutboxType.measurementPhoto:
      case OutboxType.progressCorrection:
      case OutboxType.contractorPayment:
      case OutboxType.inspection:
        break;
    }
  }

  Future<void> _removeOrResync(
    GeneratedColumn<String> uuidColumn,
    TableInfo<Table, Object?> table,
    String recordUuid,
    int? serverId,
  ) async {
    if (serverId == null) {
      await (_db.delete(table)..where((_) => uuidColumn.equals(recordUuid))).go();
    } else {
      await _resyncRecord();
    }
  }

  /// The server's copy may be older than the watermark, so ask the next sync
  /// to re-read it with a full refresh.
  Future<void> _resyncRecord() => _db.writeMeta(MetaKey.fullRefresh, '1');

  Future<List<OutboxItem>> _toItems(
    List<OutboxRow> rows,
    Set<int> blocked,
  ) async {
    final workerIds = <int>{};
    for (final row in rows) {
      if (row.type == OutboxType.attendance) {
        final id = (jsonDecode(row.payload) as Map)['worker_id'];
        if (id is int) workerIds.add(id);
      }
    }
    final workers = workerIds.isEmpty
        ? const <WorkerRow>[]
        : await (_db.select(_db.workers)..where((t) => t.id.isIn(workerIds)))
            .get();
    final workerNames = {for (final w in workers) w.id: w.name};

    return [
      for (final row in rows)
        OutboxItem(
          row: row,
          isBlocked: blocked.contains(row.id),
          title: row.type == OutboxType.attendance
              ? workerNames[(jsonDecode(row.payload) as Map)['worker_id']] ??
                  row.title
              : row.title,
        ),
    ];
  }
}
