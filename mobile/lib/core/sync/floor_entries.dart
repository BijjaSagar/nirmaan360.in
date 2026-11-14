import 'dart:convert';

import '../api/nirmaan_api.dart';
import '../db/app_database.dart';

typedef EntryJson = Map<String, dynamic>;

/// The progress entries the server lists for each floor, kept per project so
/// the recent entries of a floor open with no network.
///
/// The server lists entries oldest first, 100 to a page, so what a floor
/// recently did is on the last page. Only that end is read, and kept.
class FloorEntries {
  FloorEntries({required this._db, required this._api});

  final AppDatabase _db;
  final NirmaanApi _api;

  /// The most entries kept for one floor.
  static const int keep = 200;

  /// A last page shorter than this is read together with the one before it.
  static const int _window = 30;

  /// What the phone needs of an entry. Photos are left out.
  static const List<String> _fields = <String>[
    'id',
    'project_id',
    'boq_item_id',
    'project_floor_id',
    'work_date',
    'qty_done',
    'remark',
    'is_pending',
    'corrects_entry_id',
    'resolves_entry_id',
    'entered_by_name',
    'created_at',
  ];

  /// The last list this phone kept for [floorId], oldest first.
  Future<List<EntryJson>> cached(int projectId, int floorId) async {
    final Object? list = (await _readAll(projectId))['$floorId'];
    return list is List ? _rows(list) : const <EntryJson>[];
  }

  /// Reads the end of the floor's list from the server and keeps it. Throws an
  /// `ApiFailure` when the server cannot be reached, leaving the old list.
  Future<void> refresh(int projectId, int floorId) async {
    final EntryJson first = await _api.progressEntries(projectId, floorId: floorId);
    final int last = (first['last_page'] as num?)?.toInt() ?? 1;

    var rows = _rows(first['data']);
    if (last > 1) {
      rows = _rows((await _api.progressEntries(projectId, floorId: floorId, page: last))['data']);
      if (rows.length < _window) {
        final List<EntryJson> before = last == 2
            ? _rows(first['data'])
            : _rows((await _api.progressEntries(projectId, floorId: floorId, page: last - 1))['data']);
        rows = <EntryJson>[...before, ...rows];
      }
    }
    await _write(projectId, floorId, rows);
  }

  /// For the sync engine, once the server has stored an entry or a correction:
  /// lists it, so a floor shows it at once and it can be corrected. Call in the
  /// transaction that finishes the outbox row.
  Future<void> remember(int projectId, EntryJson entry) async {
    final int? floorId = (entry['project_floor_id'] as num?)?.toInt();
    final int? id = (entry['id'] as num?)?.toInt();
    if (floorId == null || id == null) return;
    final List<EntryJson> list = await cached(projectId, floorId);
    if (list.any((EntryJson known) => known['id'] == id)) return;
    await _write(projectId, floorId, <EntryJson>[...list, entry]);
  }

  List<EntryJson> _rows(Object? value) => value is List
      ? <EntryJson>[
          for (final Object? row in value)
            if (row is Map) <String, dynamic>{for (final String key in _fields) key: row[key]},
        ]
      : const <EntryJson>[];

  Future<Map<String, dynamic>> _readAll(int projectId) async {
    final String? raw = await _db.readMeta(MetaKey.floorEntries(projectId));
    return raw == null ? <String, dynamic>{} : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  Future<void> _write(int projectId, int floorId, List<EntryJson> rows) {
    final List<EntryJson> kept = _rows(rows.length > keep ? rows.sublist(rows.length - keep) : rows);
    return _db.transaction(() async {
      final Map<String, dynamic> all = await _readAll(projectId);
      all['$floorId'] = kept;
      await _db.writeMeta(MetaKey.floorEntries(projectId), jsonEncode(all));
    });
  }
}
