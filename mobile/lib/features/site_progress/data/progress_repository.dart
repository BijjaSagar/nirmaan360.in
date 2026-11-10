import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/floor_entries.dart';
import '../../../core/sync/ids.dart';
import 'progress_models.dart';

export 'progress_models.dart';

/// Site progress works for a week without network. What the server last said
/// is kept as JSON, and work done since sits in the outbox until it is sent.
class ProgressRepository {
  ProgressRepository(this._ctx);

  final DataContext _ctx;

  /// A sync that sent nothing need not read progress more often than this.
  static const Duration _freshFor = Duration(minutes: 10);

  static const List<OutboxType> _types = <OutboxType>[
    OutboxType.progressEntry,
    OutboxType.progressResolve,
    OutboxType.progressCorrection,
    OutboxType.stageCell,
  ];

  AppDatabase get _db => _ctx.db;

  late final FloorEntries _entries = FloorEntries(db: _ctx.db, api: _ctx.api);

  Stream<ProgressOverview?> watchCached(int projectId) =>
      _db.watchMeta(MetaKey.progressOverview(projectId)).map(_decode);

  /// Oldest first, so the newest change to a stage wins.
  Stream<List<OutboxRow>> watchUnsent(int projectId) {
    final names = _types.map((type) => type.name).toList();
    return (_db.select(_db.outbox)
          ..where(
            (t) =>
                t.projectId.equals(projectId) &
                t.type.isIn(names) &
                t.status.equals(OutboxStatus.done.name).not(),
          )
          ..orderBy([(t) => OrderingTerm.asc(t.id)]))
        .watch();
  }

  /// The last copy on this phone with unsent work laid over it, read once.
  Future<ProgressOverview?> readOverview(int projectId) async {
    final ProgressOverview? cached = _decode(await _db.readMeta(MetaKey.progressOverview(projectId)));
    if (cached == null) return null;
    final names = _types.map((type) => type.name).toList();
    final unsent = await (_db.select(_db.outbox)
          ..where(
            (t) =>
                t.projectId.equals(projectId) &
                t.type.isIn(names) &
                t.status.equals(OutboxStatus.done.name).not(),
          )
          ..orderBy([(t) => OrderingTerm.asc(t.id)]))
        .get();
    return cached.withUnsent(unsent);
  }

  ProgressOverview? _decode(String? raw) {
    if (raw == null) return null;
    return ProgressOverview.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
  }

  /// Fetches everything the screens need. Throws an [ApiFailure] when the
  /// server cannot be reached, leaving the last copy in place.
  Future<void> refresh(int projectId) async {
    final api = _ctx.api;
    final results = await Future.wait<Object>(<Future<Object>>[
      api.progressSummary(projectId),
      api.progressFloors(projectId),
      api.progressItems(projectId),
      api.progressPending(projectId),
      api.stageMatrix(projectId),
    ]);
    final old = await _readRaw(projectId);
    await _write(projectId, <String, dynamic>{
      'summary': results[0],
      'floors': results[1],
      'items': results[2],
      'pending': results[3],
      'matrix': results[4],
      'floor_details': old['floor_details'] ?? const <String, dynamic>{},
      'fetched_at': _ctx.clock.now().toUtc().toIso8601String(),
    });
  }

  /// For the sync engine: keeps the selected project ready for a day offline.
  Future<void> refreshCurrent({bool force = false}) async {
    final user = _ctx.session.user;
    if (user == null || !user.can('progress.view') || !user.features.contains('site_control')) return;
    final int? projectId = int.tryParse(await _db.readMeta(MetaKey.selectedProject) ?? '');
    if (projectId == null) return;
    // A project taken away is not read again. It would only be refused.
    final known = await (_db.select(_db.projects)..where((t) => t.id.equals(projectId))).getSingleOrNull();
    if (known == null) return;
    if (!force) {
      final DateTime? last = DateTime.tryParse(((await _readRaw(projectId))['fetched_at'] as String?) ?? '');
      if (last != null && _ctx.clock.now().difference(last) < _freshFor) return;
    }
    await refresh(projectId);
  }

  Future<Map<String, dynamic>> _readRaw(int projectId) async {
    final raw = await _db.readMeta(MetaKey.progressOverview(projectId));
    return raw == null ? <String, dynamic>{} : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  Future<void> _write(int projectId, Map<String, dynamic> data) =>
      _db.writeMeta(MetaKey.progressOverview(projectId), jsonEncode(data));

  /// One floor's lines, from the server when it can be reached and from this
  /// phone's last copy when not. Null when neither has it.
  Future<FloorDetail?> floorDetail(int projectId, int floorId) async {
    try {
      final body = await _ctx.api.floorProgress(floorId);
      final data = await _readRaw(projectId);
      if (data.isNotEmpty) {
        data['floor_details'] = <String, dynamic>{...?(data['floor_details'] as Map?)?.cast<String, dynamic>(), '$floorId': body};
        await _write(projectId, data);
      }
      return FloorDetail.fromJson(body);
    } on ApiFailure {
      final cached = ((await _readRaw(projectId))['floor_details'] as Map?)?['$floorId'];
      return cached is Map ? FloorDetail.fromJson(cached.cast<String, dynamic>()) : null;
    }
  }

  /// The latest entries on a floor that can be corrected, from the last copy on
  /// this phone with corrections still waiting to send laid over it.
  Stream<RecentEntries> watchFloorEntries(int projectId, int floorId) {
    return _db.watchDerived({_db.keyValues, _db.outbox}, () async {
      final rows = await _entries.cached(projectId, floorId);
      final unsent = await (_db.select(_db.outbox)
            ..where(
              (t) =>
                  t.projectId.equals(projectId) &
                  t.type.equals(OutboxType.progressCorrection.name) &
                  t.status.equals(OutboxStatus.done.name).not(),
            )
            ..orderBy([(t) => OrderingTerm.asc(t.id)]))
          .get();
      return RecentEntries.build(rows, unsent);
    });
  }

  /// Reads the floor's latest entries from the server. Throws an [ApiFailure]
  /// when it cannot be reached, leaving the last list in place.
  Future<void> refreshFloorEntries(int projectId, int floorId) => _entries.refresh(projectId, floorId);

  /// Sets what an entry should have been. The first entry stays; the server
  /// adds one holding the difference. Waits in the outbox like the other
  /// progress writes, and a newer correction of the same entry replaces one
  /// still waiting.
  ///
  /// [serverQty] is what the server counts for the entry now, not what the
  /// screen shows, so the difference is right however often it is changed.
  Future<void> correctEntry({
    required int projectId,
    required int entryId,
    required int boqItemId,
    required Qty serverQty,
    required Qty rightQty,
    required String remark,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertCanWrite();
    final String note = remark.trim();
    if (note.isEmpty) {
      throw const ValidationFailure(
        'Say why the entry is being corrected.',
        errors: <String, List<String>>{
          'remark': <String>['Say why the entry is being corrected.'],
        },
      );
    }
    if (rightQty.isNegative || rightQty > Qty.fromInt(_maxQty)) {
      throw const ValidationFailure(
        'Enter a quantity from zero up.',
        errors: <String, List<String>>{
          'qty_done': <String>['Enter a quantity from zero up.'],
        },
      );
    }

    final String record = 'correct:$entryId';
    await _db.transaction(() async {
      if (rightQty == serverQty) {
        // Back to what the server already has. A correction still waiting is
        // taken back; with none waiting there is nothing to correct.
        final int taken = await (_db.delete(_db.outbox)..where(
              (t) =>
                  t.recordUuid.equals(record) &
                  t.type.equals(OutboxType.progressCorrection.name) &
                  t.status.isIn(<String>[OutboxStatus.pending.name, OutboxStatus.attention.name]),
            ))
            .go();
        if (taken == 0) {
          throw const ValidationFailure(
            'That is already the quantity on this entry.',
            errors: <String, List<String>>{
              'qty_done': <String>['That is already the quantity on this entry.'],
            },
          );
        }
        return;
      }
      await _ctx.outbox.enqueue(
        type: OutboxType.progressCorrection,
        wireUuid: newUuid(),
        recordUuid: record,
        groupUuid: record,
        projectId: projectId,
        data: <String, dynamic>{
          'entry_id': entryId,
          'boq_item_id': boqItemId,
          'qty_done': rightQty.toWire(),
          'net_before': serverQty.toWire(),
          'remark': note,
        },
        createdAt: _ctx.clock.now(),
        title: title,
        subtitle: subtitle,
        coalesce: true,
      );
    });
  }

  /// The server refuses a quantity above this.
  static const int _maxQty = 99999999;

  /// The floor and item chosen last time, to save two taps on the next entry.
  Future<({int? floorId, int? itemId})> lastPick(int projectId) async {
    final raw = await _db.readMeta(MetaKey.progressLastPick(projectId));
    if (raw == null) return (floorId: null, itemId: null);
    final data = (jsonDecode(raw) as Map).cast<String, dynamic>();
    return (floorId: (data['floor'] as num?)?.toInt(), itemId: (data['item'] as num?)?.toInt());
  }

  Future<String> _importPhoto(String sourcePath, String folder, String uuid) {
    final extension = p.extension(sourcePath);
    return _ctx.attachments.importFile(
      sourcePath,
      folder: folder,
      fileName: '$uuid${extension.isEmpty ? '.jpg' : extension}',
    );
  }

  /// Records today's work on the phone and queues it, photos behind the entry.
  Future<String> addEntry({
    required int projectId,
    required int boqItemId,
    int? floorId,
    int? workOrderId,
    required String workDate,
    Qty? qty,
    String? remark,
    bool isPending = false,
    List<String> photoPaths = const <String>[],
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertCanWrite();
    _ctx.assertNotFuture(workDate, 'work_date');

    final String uuid = newUuid();
    final List<(String, String)> photos = <(String, String)>[
      for (final String path in photoPaths)
        () {
          final String photoUuid = newUuid();
          return (photoUuid, path);
        }(),
    ];
    final Map<String, String> stored = <String, String>{
      for (final (String photoUuid, String path) in photos)
        photoUuid: await _importPhoto(path, 'progress_photos', photoUuid),
    };

    await _db.transaction(() async {
      final now = _ctx.clock.now();
      await _ctx.outbox.enqueue(
        type: OutboxType.progressEntry,
        wireUuid: uuid,
        recordUuid: uuid,
        groupUuid: uuid,
        projectId: projectId,
        data: <String, dynamic>{
          'boq_item_id': boqItemId,
          'project_floor_id': floorId,
          'work_order_id': workOrderId,
          'work_date': workDate,
          'qty_done': qty?.toWire(),
          'remark': remark,
          'is_pending': isPending,
        },
        createdAt: now,
        title: title,
        subtitle: subtitle,
      );
      for (final MapEntry<String, String> photo in stored.entries) {
        await _ctx.outbox.enqueue(
          type: OutboxType.progressPhoto,
          wireUuid: photo.key,
          recordUuid: photo.key,
          groupUuid: uuid,
          projectId: projectId,
          data: <String, dynamic>{'taken_at': now.toUtc().toIso8601String()},
          filePath: photo.value,
          parentUuid: uuid,
          createdAt: now,
          title: title,
          subtitle: subtitle,
        );
      }
      await _db.writeMeta(
        MetaKey.progressLastPick(projectId),
        jsonEncode(<String, dynamic>{'floor': floorId, 'item': boqItemId}),
      );
    });
    return uuid;
  }

  Future<void> resolvePending({
    required int projectId,
    required int entryId,
    String? remark,
    required String title,
  }) {
    _ctx.assertCanWrite();
    return _db.transaction(() async {
      final String record = 'resolve:$entryId';
      await _ctx.outbox.enqueue(
        type: OutboxType.progressResolve,
        wireUuid: newUuid(),
        recordUuid: record,
        groupUuid: record,
        projectId: projectId,
        data: <String, dynamic>{'entry_id': entryId, 'remark': remark},
        createdAt: _ctx.clock.now(),
        title: title,
        coalesce: true,
      );
    });
  }

  /// Sets a stage's status. A newer change to the same stage replaces one
  /// still waiting to send.
  Future<void> setStage({
    required int projectId,
    required int floorId,
    required String trade,
    required String stage,
    required String status,
    String? remark,
    List<String> photoPaths = const <String>[],
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertCanWrite();
    final String record = 'stage:$floorId:$trade:$stage';
    final Map<String, String> stored = <String, String>{};
    for (final String path in photoPaths) {
      final String photoUuid = newUuid();
      stored[photoUuid] = await _importPhoto(path, 'stage_photos', photoUuid);
    }

    await _db.transaction(() async {
      final now = _ctx.clock.now();
      await _ctx.outbox.enqueue(
        type: OutboxType.stageCell,
        wireUuid: newUuid(),
        recordUuid: record,
        groupUuid: record,
        projectId: projectId,
        data: <String, dynamic>{
          'project_floor_id': floorId,
          'trade': trade,
          'stage': stage,
          'status': status,
          'remark': remark,
          'occurred_at': now.toUtc().toIso8601String(),
        },
        createdAt: now,
        title: title,
        subtitle: subtitle,
        coalesce: true,
      );
      for (final MapEntry<String, String> photo in stored.entries) {
        await _ctx.outbox.enqueue(
          type: OutboxType.stagePhoto,
          wireUuid: photo.key,
          recordUuid: photo.key,
          groupUuid: record,
          projectId: projectId,
          data: <String, dynamic>{'taken_at': now.toUtc().toIso8601String()},
          filePath: photo.value,
          parentUuid: record,
          createdAt: now,
          title: title,
          subtitle: subtitle,
        );
      }
    });
  }
}
