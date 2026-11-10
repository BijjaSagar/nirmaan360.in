import 'dart:convert';

import 'package:drift/drift.dart';

import '../db/app_database.dart';

/// What the phone keeps of inspections: the copy the server last sent for a
/// project, and the inspections being filled in that no server has seen.
class InspectionCache {
  InspectionCache(this._db);

  final AppDatabase _db;

  /// Adds an inspection the server has just stored to the copy kept for its
  /// project, with its items. Call in the transaction that finishes the outbox
  /// row, so a sent inspection never vanishes before the next read.
  ///
  /// With no copy kept yet, the next read brings this row with the rest.
  Future<void> remember(int projectId, Map<String, dynamic> inspection) async {
    final String key = MetaKey.inspections(projectId);
    final String? raw = await _db.readMeta(key);
    if (raw == null) return;
    final Object? decoded = jsonDecode(raw);
    if (decoded is! Map || decoded['refused'] == true) return;
    final Map<String, dynamic> kept = decoded.cast<String, dynamic>();
    final Object? id = inspection['id'];
    if (id == null) return;

    final List<Object?> rows = (kept['inspections'] as List<Object?>?) ?? const <Object?>[];
    final Map<String, dynamic> summary = <String, dynamic>{
      for (final MapEntry<String, dynamic> entry in inspection.entries)
        if (entry.key != 'items') entry.key: entry.value,
    };
    kept['inspections'] = <Object?>[
      summary,
      ...rows.where((Object? row) => !(row is Map && row['id'] == id)),
    ];
    final Object? details = kept['details'];
    kept['details'] = <String, Object?>{
      if (details is Map) ...details.cast<String, Object?>(),
      '$id': inspection,
    };
    await _db.writeMeta(key, jsonEncode(kept));
  }

  /// How many inspections are being filled in on this phone, across projects.
  /// A sign-out would lose them, so it counts them as unsent work.
  static Future<int> draftCount(AppDatabase db) async {
    final List<KeyValueRow> rows = await (db.select(
      db.keyValues,
    )..where((t) => t.key.like('${MetaKey.inspectionDraftsPrefix}%'))).get();
    var count = 0;
    for (final KeyValueRow row in rows) {
      try {
        final Object? decoded = jsonDecode(row.value);
        count += decoded is Map ? decoded.length : 0;
      } on FormatException {
        // A damaged copy holds nothing anyone could open.
      }
    }
    return count;
  }
}
