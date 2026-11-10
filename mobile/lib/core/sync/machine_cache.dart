import 'dart:convert';

import '../db/app_database.dart';

/// Adds a machine log the server has just stored to the copy kept for its
/// project. Call in the transaction that finishes the outbox row, so a sent log
/// is listed from that moment and nobody types it a second time.
class MachineCache {
  MachineCache(this._db);

  final AppDatabase _db;

  /// With no copy kept yet, the next read brings this row with the rest.
  Future<void> remember(int projectId, Map<String, dynamic> log) async {
    final String key = MetaKey.machines(projectId);
    final String? raw = await _db.readMeta(key);
    if (raw == null) return;
    final Object? decoded = jsonDecode(raw);
    if (decoded is! Map || decoded['refused'] == true) return;
    final Map<String, dynamic> kept = decoded.cast<String, dynamic>();
    final Object? id = log['id'];
    if (id == null) return;
    final List<Object?> rows = (kept['logs'] as List<Object?>?) ?? const <Object?>[];
    kept['logs'] = <Object?>[log, ...rows.where((Object? row) => !(row is Map && row['id'] == id))];
    await _db.writeMeta(key, jsonEncode(kept));
  }
}
