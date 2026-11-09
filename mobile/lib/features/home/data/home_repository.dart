import 'package:drift/drift.dart';

import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/record_sync.dart';

/// Where today's report stands.
enum DprDayState {
  /// No report for today yet.
  notStarted,

  /// Saved on the phone, not queued.
  draft,

  /// Queued or being sent.
  queued,

  /// Reached the server as a draft.
  saved,

  inReview,
  approved,
  returned,
  rejected,

  /// Stuck: needs a decision in the sync screen.
  attention,
}

class HomeSummary {
  const HomeSummary({
    required this.dprState,
    this.dprClientUuid,
    required this.attendancePresent,
    required this.attendanceMarked,
    required this.attendanceTotal,
    required this.measurementsToday,
    required this.expensesToday,
    required this.returnedToMe,
    required this.pendingSync,
    required this.attentionCount,
  });

  final DprDayState dprState;
  final String? dprClientUuid;

  /// Counted as on site: present, half day and overtime.
  final int attendancePresent;
  final int attendanceMarked;
  final int attendanceTotal;
  final int measurementsToday;
  final Money expensesToday;

  /// DPRs and measurements sent back to this user.
  final int returnedToMe;
  final int pendingSync;
  final int attentionCount;
}

class HomeRepository {
  HomeRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// One live view of a project's day. Recomputes on any relevant change.
  Stream<HomeSummary> watchHomeSummary(int projectId) {
    return _db.watchDerived(
      {
        _db.dprs,
        _db.attendanceMarks,
        _db.workers,
        _db.measurements,
        _db.pettyCashEntries,
        _db.wallets,
        _db.outbox,
      },
      () => _load(projectId),
    );
  }

  Future<HomeSummary> _load(int projectId) async {
    final today = _ctx.today;
    final me = _ctx.currentUserId;

    final dprs =
        await (_db.select(_db.dprs)
              ..where(
                (t) =>
                    t.projectId.equals(projectId) & t.reportDate.equals(today),
              )
              ..orderBy([
                (t) => OrderingTerm.desc(t.localDraft),
                (t) => OrderingTerm.desc(t.updatedAt),
              ])
              ..limit(1))
            .get();
    final dpr = dprs.isEmpty ? null : dprs.first;

    final activeWorkers =
        await (_db.select(_db.workers)..where((t) => t.isActive.equals(true)))
            .get();
    final marks =
        await (_db.select(_db.attendanceMarks)..where(
              (t) =>
                  t.projectId.equals(projectId) &
                  t.attendanceDate.equals(today),
            ))
            .get();
    final onSite = marks.where(
      (m) => m.status != AttendanceStatus.absent,
    ).length;

    final measurementsToday =
        await (_db.select(_db.measurements)..where(
              (t) =>
                  t.projectId.equals(projectId) & t.measuredOn.equals(today),
            ))
            .get();

    final wallets =
        await (_db.select(_db.wallets)
              ..where((t) => t.projectId.equals(projectId)))
            .get();
    final expenses =
        await (_db.select(_db.pettyCashEntries)..where(
              (t) =>
                  t.walletId.isIn(wallets.map((w) => w.id)) &
                  t.entryDate.equals(today) &
                  t.type.equals('expense'),
            ))
            .get();
    var expenseTotal = Money.zero;
    for (final entry in expenses) {
      expenseTotal = expenseTotal + entry.amount;
    }

    var returnedToMe = 0;
    if (me != null) {
      returnedToMe +=
          (await (_db.select(_db.dprs)..where(
                    (t) =>
                        t.projectId.equals(projectId) &
                        t.status.equals(DocStatus.returned) &
                        t.createdBy.equals(me),
                  ))
                  .get())
              .length;
      returnedToMe +=
          (await (_db.select(_db.measurements)..where(
                    (t) =>
                        t.projectId.equals(projectId) &
                        t.status.equals(DocStatus.returned) &
                        t.createdBy.equals(me),
                  ))
                  .get())
              .length;
    }

    final unfinished = await _ctx.outbox.unfinished();
    final pending =
        unfinished.where((r) => r.status != OutboxStatus.attention).length;
    final attention = unfinished.length - pending;

    return HomeSummary(
      dprState: dpr == null ? DprDayState.notStarted : await _dprState(dpr),
      dprClientUuid: dpr?.clientUuid,
      attendancePresent: onSite,
      attendanceMarked: marks.length,
      attendanceTotal: activeWorkers.length,
      measurementsToday: measurementsToday.length,
      expensesToday: expenseTotal,
      returnedToMe: returnedToMe,
      pendingSync: pending,
      attentionCount: attention,
    );
  }

  Future<DprDayState> _dprState(DprRow dpr) async {
    final states = await SyncStates.load(_db);
    final record = states.wrap(dpr, dpr.clientUuid);
    if (record.syncState == LocalSyncState.attention) {
      return DprDayState.attention;
    }
    if (record.isUnsent) return DprDayState.queued;
    if (dpr.localDraft) return DprDayState.draft;
    return switch (dpr.status) {
      DocStatus.inReview => DprDayState.inReview,
      DocStatus.approved => DprDayState.approved,
      DocStatus.returned => DprDayState.returned,
      DocStatus.rejected => DprDayState.rejected,
      _ => DprDayState.saved,
    };
  }
}
