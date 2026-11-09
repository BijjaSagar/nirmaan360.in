import '../db/app_database.dart';
import '../db/outbox_dao.dart';

/// How far a record has got on its way to the server.
enum LocalSyncState {
  /// Nothing is waiting. The server has everything.
  synced,

  /// Waiting on the phone to be sent.
  queued,

  /// A send is in flight.
  sending,

  /// Needs a person: the server refused it or it could not be sent.
  attention,
}

/// A record plus its sync badge, for list and detail screens.
class SyncedRecord<T> {
  const SyncedRecord(
    this.record, {
    this.syncState = LocalSyncState.synced,
    this.attentionCode,
    this.attentionReason,
  });

  final T record;
  final LocalSyncState syncState;
  final String? attentionCode;
  final String? attentionReason;

  bool get isUnsent => syncState != LocalSyncState.synced;
}

/// Sync state of each group that has unfinished work, keyed by group uuid.
class SyncStates {
  SyncStates._(this._byGroup);

  static Future<SyncStates> load(AppDatabase db) async {
    final byGroup = <String, _GroupState>{};
    for (final row in await OutboxDao(db).unfinished()) {
      final current = byGroup[row.groupUuid] ?? const _GroupState();
      byGroup[row.groupUuid] = current.merge(row);
    }
    return SyncStates._(byGroup);
  }

  final Map<String, _GroupState> _byGroup;

  SyncedRecord<T> wrap<T>(T record, String groupUuid) {
    final state = _byGroup[groupUuid];
    return SyncedRecord(
      record,
      syncState: state?.state ?? LocalSyncState.synced,
      attentionCode: state?.code,
      attentionReason: state?.reason,
    );
  }
}

class _GroupState {
  const _GroupState({
    this.state = LocalSyncState.synced,
    this.code,
    this.reason,
  });

  final LocalSyncState state;
  final String? code;
  final String? reason;

  _GroupState merge(OutboxRow row) {
    if (row.status == OutboxStatus.attention) {
      return _GroupState(
        state: LocalSyncState.attention,
        code: row.attentionCode,
        reason: row.attentionReason,
      );
    }
    if (state == LocalSyncState.attention) return this;
    if (row.status == OutboxStatus.sending) {
      return const _GroupState(state: LocalSyncState.sending);
    }
    if (state == LocalSyncState.sending) return this;
    return const _GroupState(state: LocalSyncState.queued);
  }
}
