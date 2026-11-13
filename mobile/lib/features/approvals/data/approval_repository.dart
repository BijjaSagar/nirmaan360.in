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
  /// or a payment sent without its figures, to someone who may not see money,
  /// is the same.
  bool get reviewableHere => switch (docType) {
    'measurement' || 'dpr' => true,
    'ra_bill' => document['net_payable'] != null,
    'bill_note' => document['total_amount'] != null,
    'contractor' => document['amount'] != null,
    _ => false,
  };

  /// Documents the phone may reject outright as well as send back. A rejected
  /// one is closed for good, so the older types keep only "send back" here.
  bool get rejectableHere => docType == 'contractor';

  /// The owner-override rule for a payment ahead of the work done. Its fields
  /// stay false until the server has that rule.
  OwnerOverride get ownerOverride {
    final Object? raw = document['owner_override'];
    if (raw is! Map) return const OwnerOverride();
    final Object? message = raw['message'];
    return OwnerOverride(
      needed: raw['needed'] == true,
      allowed: raw['allowed'] == true,
      message: message is String && message.trim().isNotEmpty ? message.trim() : null,
    );
  }
}

/// Approving is refused unless the owner overrides in writing ([needed]);
/// [allowed] says this person is that owner; [message] says why, in percents.
class OwnerOverride {
  const OwnerOverride({this.needed = false, this.allowed = false, this.message});

  final bool needed;
  final bool allowed;
  final String? message;

  /// Approve is not this person's to give: only the owner may pass it.
  bool get blocksApprove => needed && !allowed;

  /// The server's rule for a reason: at least 20 characters and 4 words.
  static bool isSentence(String reason) {
    final String text = reason.trim();
    final int words = text.split(RegExp(r'\s+')).where((word) => word.isNotEmpty).length;
    return text.runes.length >= 20 && words >= 4;
  }
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
  /// [overrideReason] is the owner's written reason, only when the document
  /// needs an owner override.
  Future<void> approve(int approvalId, {String? remark, String? overrideReason}) =>
      _act(approvalId, 'approve', remark, overrideReason: overrideReason);

  /// Sends the document back for correction. Same retry safety as [approve].
  Future<void> sendBack(int approvalId, {required String remark}) => _act(approvalId, 'return', remark);

  /// Closes the document for good. Same retry safety as [approve].
  Future<void> reject(int approvalId, {required String remark}) => _act(approvalId, 'reject', remark);

  Future<void> _act(int approvalId, String action, String? remark, {String? overrideReason}) async {
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();

    final item = await (_db.select(_db.approvalsInbox)..where((t) => t.id.equals(approvalId))).getSingleOrNull();

    final key = 'idempotency.approval.$approvalId.$action.${item?.currentLevel ?? 0}';
    var clientUuid = await _db.readMeta(key);
    if (clientUuid == null) {
      clientUuid = newUuid();
      await _db.writeMeta(key, clientUuid);
    }

    await _ctx.api.actOnApproval(
      approvalId,
      action: action,
      clientUuid: clientUuid,
      remark: remark,
      overrideReason: overrideReason,
    );

    await _db.deleteMeta(key);
    await (_db.delete(_db.approvalsInbox)..where((t) => t.id.equals(approvalId))).go();
  }
}
