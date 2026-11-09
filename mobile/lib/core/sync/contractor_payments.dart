import 'dart:convert';

import '../db/app_database.dart';

/// Adds a payment request the server has just stored to the list kept for its
/// project. Call in the transaction that finishes the outbox row.
class ContractorPayments {
  ContractorPayments(this._db);

  final AppDatabase _db;

  /// With no list kept yet, the next read brings this row with the rest.
  Future<void> remember(int projectId, Map<String, dynamic> payment) async {
    final String key = MetaKey.contractorOverview(projectId);
    final String? raw = await _db.readMeta(key);
    if (raw == null) return;
    final Map<String, dynamic> kept = (jsonDecode(raw) as Map).cast<String, dynamic>();
    final List<Object?> rows = (kept['payments'] as List<Object?>?) ?? const <Object?>[];
    final Object? id = payment['id'];
    if (id != null && rows.any((Object? row) => row is Map && row['id'] == id)) return;
    kept['payments'] = <Object?>[payment, ...rows];
    await _db.writeMeta(key, jsonEncode(kept));
  }
}
