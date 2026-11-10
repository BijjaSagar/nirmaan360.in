import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/pull_applier.dart';

export '../../../core/db/app_database.dart' show ApprovalRow;

extension ApprovalReview on ApprovalRow {
  /// Whether this phone can show the whole document. Anything else is decided
  /// on the website, because nobody should sign off on a title alone. A bill
  /// sent without its figures, to someone who may not see money, is the same.
  bool get reviewableHere => switch (docType) {
    'measurement' || 'dpr' => true,
    'ra_bill' => document['net_payable'] != null,
    'bill_note' => document['total_amount'] != null,
    _ => false,
  };
}

/// Approvals are online only, by design. The inbox is a cache of the last
/// pull or refresh; acting on an item goes straight to the server.
class ApprovalRepository {
  ApprovalRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// Newest first.
  Stream<List<ApprovalRow>> watchInbox() {
    return (_db.select(_db.approvalsInbox)..orderBy([(t) => OrderingTerm.desc(t.submittedAt)])).watch();
  }

  Stream<int> watchCount() => watchInbox().map((items) => items.where((i) => i.canAct).length);

  /// Fetches the inbox. Throws [OfflineFailure] when offline.
  Future<void> refresh() async {
    final response = await _ctx.api.approvals();
    await _db.transaction(() async {
      await _db.delete(_db.approvalsInbox).go();
      await _db.batch((batch) {
        batch.insertAll(_db.approvalsInbox, response.items.map(approvalCompanion).toList());
      });
    });
  }

  /// Throws [OfflineFailure] when offline. Safe to retry after any failure:
  /// the same `client_uuid` is reused until the server has answered.
  Future<void> approve(int approvalId, {String? remark}) => _act(approvalId, 'approve', remark);

  /// Sends the document back for correction. Same retry safety as [approve].
  Future<void> sendBack(int approvalId, {required String remark}) => _act(approvalId, 'return', remark);

  Future<void> _act(int approvalId, String action, String? remark) async {
    _ctx.assertCanWrite();
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();

    final item = await (_db.select(_db.approvalsInbox)..where((t) => t.id.equals(approvalId))).getSingleOrNull();

    final key = 'idempotency.approval.$approvalId.$action.${item?.currentLevel ?? 0}';
    var clientUuid = await _db.readMeta(key);
    if (clientUuid == null) {
      clientUuid = newUuid();
      await _db.writeMeta(key, clientUuid);
    }

    await _ctx.api.actOnApproval(approvalId, action: action, clientUuid: clientUuid, remark: remark);

    await _db.deleteMeta(key);
    await (_db.delete(_db.approvalsInbox)..where((t) => t.id.equals(approvalId))).go();
  }
}
