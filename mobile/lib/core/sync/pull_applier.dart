import 'dart:convert';

import 'package:drift/drift.dart';

import '../db/app_database.dart';
import '../db/outbox_dao.dart';
import '../money/money.dart';
import 'clock.dart';
import 'ids.dart';

typedef Json = Map<String, dynamic>;

const _tombstoneDpr = 'dpr';
const _tombstoneIssue = 'issue';

/// Applies one `GET /sync/pull` body, all of it or none of it.
///
/// Masters are upserted unconditionally. A field row with unsent local work
/// keeps its local content and takes only `server_id` and an APPROVED or
/// IN_REVIEW status; every other field row is replaced by the server's.
/// The watermark is written in the same transaction, so it can only move
/// after a fully applied pull.
class PullApplier {
  PullApplier(this._db) : _outbox = OutboxDao(_db);

  final AppDatabase _db;
  final OutboxDao _outbox;

  /// With [moveWatermark] false the rows are applied but the watermark stays,
  /// for a full refresh layered on top of a delta pull.
  Future<void> apply(Json body, {bool moveWatermark = true}) async {
    final serverTime = body['server_time'] as String;

    await _db.transaction(() async {
      final unsent = await _outbox.unsentRecordUuids();

      await _applyMasters(body);
      await _applyDprs(_list(body, 'dprs'), unsent);
      await _applyMeasurements(_list(body, 'measurements'), unsent);
      await _applyIssues(_list(body, 'issues'), unsent);
      await _applyAttendance(_list(body, 'attendance'), unsent);
      await _applyPettyCash(_list(body, 'petty_cash_entries'), unsent);
      await _applyApprovals(_list(body, 'approvals'));
      await _applyDeleted(
        (body['deleted'] as Map?)?.cast<String, dynamic>() ?? const {},
        unsent,
        incomingDprIds: _ids(body, 'dprs'),
        incomingIssueIds: _ids(body, 'issues'),
      );

      final meta = body['meta'];
      if (meta is Map) {
        await _db.writeMeta(MetaKey.lookups, jsonEncode(meta));
      }
      final user = body['user'];
      if (user is Map) {
        await _db.writeMeta(MetaKey.sessionUser, jsonEncode(user));
      }

      if (moveWatermark) {
        await _db.writeMeta(MetaKey.watermark, serverTime);
      }
    });
  }

  Set<int> _ids(Json body, String key) =>
      _list(body, key).map((row) => _int(row['id'])).toSet();

  List<Json> _list(Json body, String key) =>
      ((body[key] as List?) ?? const [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList();

  Future<void> _applyMasters(Json body) async {
    final projects = _list(body, 'projects');
    final sites = _list(body, 'sites');
    final boqItems = _list(body, 'boq_items');
    final workers = _list(body, 'workers');
    final users = _list(body, 'users');
    final wallets = _list(body, 'wallets');

    await _db.batch((batch) {
      batch.insertAllOnConflictUpdate(
        _db.projects,
        projects.map(projectCompanion).toList(),
      );
      batch.insertAllOnConflictUpdate(
        _db.sites,
        sites.map(_siteCompanion).toList(),
      );
      batch.insertAllOnConflictUpdate(
        _db.boqItems,
        boqItems.map(boqItemCompanion).toList(),
      );
      batch.insertAllOnConflictUpdate(
        _db.workers,
        workers.map(_workerCompanion).toList(),
      );
      batch.insertAllOnConflictUpdate(
        _db.users,
        users.map(_userCompanion).toList(),
      );
    });

    for (final balance in _list(body, 'boq_balances')) {
      await (_db.update(_db.boqItems)
            ..where((t) => t.id.equals((balance['id'] as num).toInt())))
          .write(
            BoqItemsCompanion(
              measuredQty: Value(Qty.fromWire(_str(balance['measured_qty']))),
              approvedQty: Value(Qty.fromWire(_str(balance['approved_qty']))),
              balanceQty: Value(Qty.fromWire(_str(balance['balance_qty']))),
            ),
          );
    }

    // `wallets` is always the full list.
    await _db.delete(_db.wallets).go();
    await _db.batch((batch) {
      batch.insertAll(_db.wallets, wallets.map(walletCompanion).toList());
    });
  }

  /// One row in the `projects` pull shape, also returned by `POST /projects`.
  static ProjectsCompanion projectCompanion(Json j) => ProjectsCompanion(
    id: Value(_int(j['id'])),
    code: Value(j['code'] as String),
    name: Value(j['name'] as String),
    location: Value(j['location'] as String?),
    status: Value(j['status'] as String? ?? 'active'),
    startDate: Value(_date(j['start_date'])),
    endDate: Value(_date(j['end_date'])),
    measurementTolerancePct: Value(
      _str(j['measurement_tolerance_pct']) ?? '5.00',
    ),
    updatedAt: Value(parseServerTime(j['updated_at'])),
    imageUrl: Value(j['image_url'] as String?),
  );

  SitesCompanion _siteCompanion(Json j) => SitesCompanion(
    id: Value(_int(j['id'])),
    projectId: Value(_int(j['project_id'])),
    name: Value(j['name'] as String),
    address: Value(j['address'] as String?),
    updatedAt: Value(parseServerTime(j['updated_at'])),
  );

  static BoqItemsCompanion boqItemCompanion(Json j) => BoqItemsCompanion(
    id: Value(_int(j['id'])),
    projectId: Value(_int(j['project_id'])),
    section: Value(j['section'] as String?),
    code: Value(j['code'] as String),
    description: Value(j['description'] as String),
    unit: Value(j['unit'] as String),
    qty: Value(Qty.fromWire(_str(j['qty']))),
    rate: Value(Money.fromWire(_str(j['rate']))),
    sort: Value((j['sort'] as num?)?.toInt() ?? 0),
    measuredQty: Value(Qty.fromWire(_str(j['measured_qty']))),
    approvedQty: Value(Qty.fromWire(_str(j['approved_qty']))),
    balanceQty: Value(Qty.fromWire(_str(j['balance_qty']))),
    updatedAt: Value(parseServerTime(j['updated_at'])),
  );

  WorkersCompanion _workerCompanion(Json j) => WorkersCompanion(
    id: Value(_int(j['id'])),
    name: Value(j['name'] as String),
    phone: Value(j['phone'] as String?),
    trade: Value(j['trade'] as String? ?? ''),
    type: Value(j['type'] as String? ?? 'own'),
    isActive: Value(j['is_active'] as bool? ?? true),
    updatedAt: Value(parseServerTime(j['updated_at'])),
  );

  UsersCompanion _userCompanion(Json j) => UsersCompanion(
    id: Value(_int(j['id'])),
    name: Value(j['name'] as String),
    role: Value(j['role'] as String?),
  );

  static WalletsCompanion walletCompanion(Json j) => WalletsCompanion(
    id: Value(_int(j['id'])),
    projectId: Value(_int(j['project_id'])),
    name: Value(j['name'] as String),
    userId: Value((j['user_id'] as num?)?.toInt()),
    balance: Value(Money.fromWire(_str(j['balance']))),
    updatedAt: Value(parseServerTime(j['updated_at'])),
  );

  // Field tables ------------------------------------------------------------

  Future<void> _applyDprs(List<Json> rows, Set<String> unsent) async {
    if (rows.isEmpty) return;
    final existing = await _db.select(_db.dprs).get();
    final byServerId = {
      for (final row in existing)
        if (row.serverId != null) row.serverId!: row,
    };
    final byUuid = {for (final row in existing) row.clientUuid: row};

    for (final j in rows) {
      final serverId = _int(j['id']);
      final remoteUuid = j['client_uuid'] as String?;
      final local =
          byServerId[serverId] ?? (remoteUuid == null ? null : byUuid[remoteUuid]);
      final uuid =
          local?.clientUuid ?? remoteUuid ?? stableUuid(RecordType.dpr, serverId);

      if (local != null && (local.localDraft || unsent.contains(uuid))) {
        final status = j['status'] as String;
        await (_db.update(_db.dprs)
              ..where((t) => t.clientUuid.equals(uuid)))
            .write(
              DprsCompanion(
                serverId: Value(serverId),
                status: _keepsServerStatus(status)
                    ? Value(status)
                    : const Value.absent(),
                updatedAt: Value(parseServerTime(j['updated_at'])),
              ),
            );
        continue;
      }

      await _db.into(_db.dprs).insertOnConflictUpdate(
        DprsCompanion(
          clientUuid: Value(uuid),
          serverId: Value(serverId),
          projectId: Value(_int(j['project_id'])),
          siteId: Value((j['site_id'] as num?)?.toInt()),
          reportDate: Value(j['report_date'] as String),
          weather: Value(j['weather'] as String?),
          summary: Value(j['summary'] as String?),
          hindrances: Value(j['hindrances'] as String?),
          status: Value(j['status'] as String),
          localDraft: const Value(false),
          createdBy: Value((j['created_by'] as num?)?.toInt()),
          createdByName: Value(j['created_by_name'] as String?),
          manpower: Value(
            _maps(j['manpower']).map(ManpowerLine.fromJson).toList(),
          ),
          machinery: Value(
            _maps(j['machinery']).map(MachineryLine.fromJson).toList(),
          ),
          workItems: Value(
            _maps(j['work_items']).map(WorkLine.fromJson).toList(),
          ),
          returnRemark: Value(j['return_remark'] as String?),
          clientUpdatedAt: Value(parseServerTime(j['client_updated_at'])),
          updatedAt: Value(parseServerTime(j['updated_at'])),
        ),
      );

      await _applyDprPhotos(uuid, _maps(j['photos']));
    }
  }

  /// Only reached for a DPR with no unsent work, so every local photo that
  /// has a server id is the server's to manage. Photos still waiting for
  /// upload have no server id and are left alone.
  Future<void> _applyDprPhotos(String dprUuid, List<Json> photos) async {
    final local =
        await (_db.select(_db.dprPhotos)
              ..where((t) => t.dprClientUuid.equals(dprUuid)))
            .get();
    final byServerId = {
      for (final row in local)
        if (row.serverId != null) row.serverId!: row,
    };
    final incomingIds = photos.map((p) => _int(p['id'])).toSet();

    for (final photo in photos) {
      final serverId = _int(photo['id']);
      final existing = byServerId[serverId];
      if (existing != null) {
        await (_db.update(_db.dprPhotos)
              ..where((t) => t.clientUuid.equals(existing.clientUuid)))
            .write(DprPhotosCompanion(caption: Value(photo['caption'] as String?)));
      } else {
        await _db.into(_db.dprPhotos).insertOnConflictUpdate(
          DprPhotosCompanion(
            clientUuid: Value(stableUuid(RecordType.dprPhoto, serverId)),
            serverId: Value(serverId),
            dprClientUuid: Value(dprUuid),
            caption: Value(photo['caption'] as String?),
          ),
        );
      }
    }

    for (final row in local) {
      if (row.serverId != null && !incomingIds.contains(row.serverId)) {
        await (_db.delete(_db.dprPhotos)
              ..where((t) => t.clientUuid.equals(row.clientUuid)))
            .go();
      }
    }
  }

  Future<void> _applyMeasurements(List<Json> rows, Set<String> unsent) async {
    if (rows.isEmpty) return;
    final existing = await _db.select(_db.measurements).get();
    final byServerId = {
      for (final row in existing)
        if (row.serverId != null) row.serverId!: row,
    };
    final byUuid = {for (final row in existing) row.clientUuid: row};

    for (final j in rows) {
      final serverId = _int(j['id']);
      final remoteUuid = j['client_uuid'] as String?;
      final local =
          byServerId[serverId] ?? (remoteUuid == null ? null : byUuid[remoteUuid]);
      final uuid =
          local?.clientUuid ??
          remoteUuid ??
          stableUuid(RecordType.measurement, serverId);
      final serverQty = Qty.fromWire(_str(j['qty']));

      if (local != null && unsent.contains(uuid)) {
        final status = j['status'] as String;
        await (_db.update(_db.measurements)
              ..where((t) => t.clientUuid.equals(uuid)))
            .write(
              MeasurementsCompanion(
                serverId: Value(serverId),
                status: _keepsServerStatus(status)
                    ? Value(status)
                    : const Value.absent(),
                serverQty: Value(serverQty),
                updatedAt: Value(parseServerTime(j['updated_at'])),
              ),
            );
        continue;
      }

      await _db.into(_db.measurements).insertOnConflictUpdate(
        MeasurementsCompanion(
          clientUuid: Value(uuid),
          serverId: Value(serverId),
          projectId: Value(_int(j['project_id'])),
          boqItemId: Value(_int(j['boq_item_id'])),
          workOrderId: Value((j['work_order_id'] as num?)?.toInt()),
          measuredOn: Value(j['measured_on'] as String),
          description: Value(j['description'] as String?),
          location: Value(j['location'] as String?),
          nos: Value(_qtyOrNull(j['nos'])),
          length: Value(_qtyOrNull(j['length'])),
          breadth: Value(_qtyOrNull(j['breadth'])),
          depth: Value(_qtyOrNull(j['depth'])),
          isDeduction: Value(j['is_deduction'] as bool? ?? false),
          qty: Value(serverQty),
          serverQty: Value(serverQty),
          status: Value(j['status'] as String),
          deviation: Value(j['deviation'] as bool? ?? false),
          remarks: Value(j['remarks'] as String?),
          returnRemark: Value(j['return_remark'] as String?),
          createdBy: Value((j['created_by'] as num?)?.toInt()),
          clientUpdatedAt: Value(parseServerTime(j['client_updated_at'])),
          updatedAt: Value(parseServerTime(j['updated_at'])),
        ),
      );
    }
  }

  Future<void> _applyIssues(List<Json> rows, Set<String> unsent) async {
    if (rows.isEmpty) return;
    final existing = await _db.select(_db.issues).get();
    final byServerId = {
      for (final row in existing)
        if (row.serverId != null) row.serverId!: row,
    };
    final byUuid = {for (final row in existing) row.clientUuid: row};

    for (final j in rows) {
      final serverId = _int(j['id']);
      final remoteUuid = j['client_uuid'] as String?;
      final local =
          byServerId[serverId] ?? (remoteUuid == null ? null : byUuid[remoteUuid]);
      final uuid =
          local?.clientUuid ??
          remoteUuid ??
          stableUuid(RecordType.issue, serverId);

      if (local != null && unsent.contains(uuid)) {
        await (_db.update(_db.issues)
              ..where((t) => t.clientUuid.equals(uuid)))
            .write(
              IssuesCompanion(
                serverId: Value(serverId),
                updatedAt: Value(parseServerTime(j['updated_at'])),
              ),
            );
        continue;
      }

      await _db.into(_db.issues).insertOnConflictUpdate(
        IssuesCompanion(
          clientUuid: Value(uuid),
          serverId: Value(serverId),
          projectId: Value(_int(j['project_id'])),
          siteId: Value((j['site_id'] as num?)?.toInt()),
          title: Value(j['title'] as String),
          description: Value(j['description'] as String?),
          priority: Value(j['priority'] as String? ?? 'medium'),
          status: Value(j['status'] as String? ?? 'open'),
          locationNote: Value(j['location_note'] as String?),
          assignedTo: Value((j['assigned_to'] as num?)?.toInt()),
          raisedBy: Value((j['raised_by'] as num?)?.toInt()),
          dueDate: Value(_date(j['due_date'])),
          resolvedAt: Value(parseServerTime(j['resolved_at'])),
          clientUpdatedAt: Value(parseServerTime(j['client_updated_at'])),
          updatedAt: Value(parseServerTime(j['updated_at'])),
        ),
      );
    }
  }

  Future<void> _applyAttendance(List<Json> rows, Set<String> unsent) async {
    if (rows.isEmpty) return;
    final existing = await _db.select(_db.attendanceMarks).get();
    final byServerId = {
      for (final row in existing)
        if (row.serverId != null) row.serverId!: row,
    };
    final byUuid = {for (final row in existing) row.clientUuid: row};
    String key(int project, int worker, String date) =>
        '$project|$worker|$date';
    final byNaturalKey = {
      for (final row in existing)
        key(row.projectId, row.workerId, row.attendanceDate): row,
    };

    for (final j in rows) {
      final serverId = _int(j['id']);
      final remoteUuid = j['client_uuid'] as String?;
      final projectId = _int(j['project_id']);
      final workerId = _int(j['worker_id']);
      final date = j['attendance_date'] as String;

      // Attendance is keyed on (project, worker, date) everywhere, so the
      // server row may carry another device's uuid for this same mark.
      final local =
          byServerId[serverId] ??
          (remoteUuid == null ? null : byUuid[remoteUuid]) ??
          byNaturalKey[key(projectId, workerId, date)];
      final uuid =
          local?.clientUuid ??
          remoteUuid ??
          stableUuid(RecordType.attendance, serverId);

      if (local != null && unsent.contains(uuid)) {
        await (_db.update(_db.attendanceMarks)
              ..where((t) => t.clientUuid.equals(uuid)))
            .write(
              AttendanceMarksCompanion(
                serverId: Value(serverId),
                updatedAt: Value(parseServerTime(j['updated_at'])),
              ),
            );
        continue;
      }

      await _db.into(_db.attendanceMarks).insertOnConflictUpdate(
        AttendanceMarksCompanion(
          clientUuid: Value(uuid),
          serverId: Value(serverId),
          projectId: Value(projectId),
          workerId: Value(workerId),
          attendanceDate: Value(date),
          status: Value(j['status'] as String),
          otHours: Value(Hours.fromWire(_str(j['ot_hours']))),
          markedBy: Value((j['marked_by'] as num?)?.toInt()),
          clientUpdatedAt: Value(parseServerTime(j['client_updated_at'])),
          updatedAt: Value(parseServerTime(j['updated_at'])),
        ),
      );
    }
  }

  Future<void> _applyPettyCash(List<Json> rows, Set<String> unsent) async {
    if (rows.isEmpty) return;
    final existing = await _db.select(_db.pettyCashEntries).get();
    final byServerId = {
      for (final row in existing)
        if (row.serverId != null) row.serverId!: row,
    };
    final byUuid = {for (final row in existing) row.clientUuid: row};

    for (final j in rows) {
      final serverId = _int(j['id']);
      final remoteUuid = j['client_uuid'] as String?;
      final local =
          byServerId[serverId] ?? (remoteUuid == null ? null : byUuid[remoteUuid]);
      final uuid =
          local?.clientUuid ??
          remoteUuid ??
          stableUuid(RecordType.pettyCash, serverId);

      if (local != null && unsent.contains(uuid)) {
        await (_db.update(_db.pettyCashEntries)
              ..where((t) => t.clientUuid.equals(uuid)))
            .write(
              PettyCashEntriesCompanion(
                serverId: Value(serverId),
                countedInBalance: const Value(true),
                updatedAt: Value(parseServerTime(j['updated_at'])),
              ),
            );
        continue;
      }

      await _db.into(_db.pettyCashEntries).insertOnConflictUpdate(
        PettyCashEntriesCompanion(
          clientUuid: Value(uuid),
          serverId: Value(serverId),
          walletId: Value(_int(j['petty_cash_wallet_id'])),
          type: Value(j['type'] as String? ?? 'expense'),
          amount: Value(Money.fromWire(_str(j['amount']))),
          category: Value(j['category'] as String?),
          description: Value(j['description'] as String?),
          entryDate: Value(j['entry_date'] as String),
          createdBy: Value((j['created_by'] as num?)?.toInt()),
          hasBill: Value(j['has_bill'] as bool? ?? false),
          countedInBalance: const Value(true),
          updatedAt: Value(parseServerTime(j['updated_at'])),
        ),
      );
    }
  }

  /// `approvals` is always the full inbox.
  Future<void> _applyApprovals(List<Json> rows) async {
    await _db.delete(_db.approvalsInbox).go();
    await _db.batch((batch) {
      batch.insertAll(_db.approvalsInbox, rows.map(approvalCompanion).toList());
    });
  }

  Future<void> _applyDeleted(
    Json deleted,
    Set<String> unsent, {
    required Set<int> incomingDprIds,
    required Set<int> incomingIssueIds,
  }) async {
    Iterable<int> ids(String key) =>
        ((deleted[key] as List?) ?? const []).map((e) => (e as num).toInt());

    for (final id in ids('projects')) {
      await (_db.delete(_db.projects)..where((t) => t.id.equals(id))).go();
      await _db.deleteMeta(MetaKey.progressOverview(id));
      await _db.deleteMeta(MetaKey.progressLastPick(id));
      await _db.deleteMeta(MetaKey.materialOverview(id));
      await _db.deleteMeta(MetaKey.siteOverview(id));
      await _db.deleteMeta(MetaKey.recordPhotos(id));
      await _db.deleteMeta(MetaKey.floorEntries(id));
    }
    for (final id in ids('boq_items')) {
      await (_db.delete(_db.boqItems)..where((t) => t.id.equals(id))).go();
    }
    for (final id in ids('workers')) {
      await (_db.delete(_db.workers)..where((t) => t.id.equals(id))).go();
    }

    final tombstones = await _readTombstones();
    final before = tombstones.length;

    for (final id in ids('issues')) {
      final row =
          await (_db.select(_db.issues)..where((t) => t.serverId.equals(id)))
              .getSingleOrNull();
      if (row == null) continue;
      if (unsent.contains(row.clientUuid)) {
        tombstones[row.clientUuid] = _tombstoneIssue;
      } else {
        await _deleteIssue(row.clientUuid);
      }
    }

    for (final id in ids('dprs')) {
      final row =
          await (_db.select(_db.dprs)..where((t) => t.serverId.equals(id)))
              .getSingleOrNull();
      if (row == null) continue;
      if (row.localDraft || unsent.contains(row.clientUuid)) {
        tombstones[row.clientUuid] = _tombstoneDpr;
      } else {
        await _deleteDpr(row.clientUuid);
      }
    }

    // Drafts hard-deleted on the web. A row with unsent work stays, and its
    // push creates the entry again. No tombstone, for the same reason.
    for (final id in ids('measurements')) {
      final row =
          await (_db.select(_db.measurements)
                ..where((t) => t.serverId.equals(id)))
              .getSingleOrNull();
      if (row != null && !unsent.contains(row.clientUuid)) {
        await (_db.delete(_db.measurements)
              ..where((t) => t.clientUuid.equals(row.clientUuid)))
            .go();
      }
    }

    final swept = await _sweepTombstones(
      tombstones,
      unsent,
      incomingDprIds: incomingDprIds,
      incomingIssueIds: incomingIssueIds,
    );
    if (swept || tombstones.length != before) {
      if (tombstones.isEmpty) {
        await _db.deleteMeta(MetaKey.tombstones);
      } else {
        await _db.writeMeta(MetaKey.tombstones, jsonEncode(tombstones));
      }
    }

    await _dropRemovedProjectData(unsent);
  }

  /// Clears what the phone still holds for projects it no longer has, whether
  /// deleted or taken away from this person. Work that never reached the
  /// server stays until it is sent or discarded, then the next pull clears it.
  Future<void> _dropRemovedProjectData(Set<String> unsent) async {
    final known = _db.selectOnly(_db.projects)..addColumns([_db.projects.id]);

    await (_db.delete(_db.sites)..where((t) => t.projectId.isNotInQuery(known))).go();
    await (_db.delete(_db.boqItems)..where((t) => t.projectId.isNotInQuery(known))).go();
    await (_db.delete(_db.wallets)..where((t) => t.projectId.isNotInQuery(known))).go();

    await (_db.delete(_db.measurements)
          ..where((t) => t.projectId.isNotInQuery(known) & t.clientUuid.isNotIn(unsent)))
        .go();
    await (_db.delete(_db.issues)
          ..where((t) => t.projectId.isNotInQuery(known) & t.clientUuid.isNotIn(unsent)))
        .go();
    await (_db.delete(_db.attendanceMarks)
          ..where((t) => t.projectId.isNotInQuery(known) & t.clientUuid.isNotIn(unsent)))
        .go();
    await (_db.delete(_db.pettyCashEntries)
          ..where((t) => t.projectId.isNotInQuery(known) & t.clientUuid.isNotIn(unsent)))
        .go();

    final dprs = await (_db.select(_db.dprs)
          ..where(
            (t) =>
                t.projectId.isNotInQuery(known) &
                t.localDraft.equals(false) &
                t.clientUuid.isNotIn(unsent),
          ))
        .get();
    for (final row in dprs) {
      await _deleteDpr(row.clientUuid);
    }
  }

  /// A row soft-deleted on the server while it had unsent work is listed in
  /// `deleted` only once, and its push then comes back `duplicate`. Nothing
  /// else would ever remove it, so it is remembered and removed here once its
  /// outbox items have finished. A row that came back in this pull was
  /// restored on the server and is kept.
  Future<bool> _sweepTombstones(
    Map<String, String> tombstones,
    Set<String> unsent, {
    required Set<int> incomingDprIds,
    required Set<int> incomingIssueIds,
  }) async {
    var changed = false;
    for (final entry in tombstones.entries.toList()) {
      final uuid = entry.key;
      if (unsent.contains(uuid)) continue;

      if (entry.value == _tombstoneDpr) {
        final row =
            await (_db.select(_db.dprs)
                  ..where((t) => t.clientUuid.equals(uuid)))
                .getSingleOrNull();
        if (row != null && row.localDraft) continue;
        if (row != null && !incomingDprIds.contains(row.serverId)) {
          await _deleteDpr(uuid);
        }
      } else {
        final row =
            await (_db.select(_db.issues)
                  ..where((t) => t.clientUuid.equals(uuid)))
                .getSingleOrNull();
        if (row != null && !incomingIssueIds.contains(row.serverId)) {
          await _deleteIssue(uuid);
        }
      }
      tombstones.remove(uuid);
      changed = true;
    }
    return changed;
  }

  Future<Map<String, String>> _readTombstones() async {
    final raw = await _db.readMeta(MetaKey.tombstones);
    if (raw == null) return <String, String>{};
    return (jsonDecode(raw) as Map).cast<String, String>();
  }

  Future<void> _deleteIssue(String uuid) {
    return (_db.delete(_db.issues)..where((t) => t.clientUuid.equals(uuid)))
        .go();
  }

  Future<void> _deleteDpr(String uuid) async {
    await (_db.delete(_db.dprs)..where((t) => t.clientUuid.equals(uuid))).go();
    await (_db.delete(_db.dprPhotos)
          ..where((t) => t.dprClientUuid.equals(uuid)))
        .go();
  }

  bool _keepsServerStatus(String status) =>
      status == DocStatus.approved || status == DocStatus.inReview;

  static int _int(Object? value) => (value as num).toInt();

  static String? _str(Object? value) => value?.toString();

  static String? _date(Object? value) {
    if (value == null) return null;
    final text = value.toString();
    return text.isEmpty ? null : text;
  }

  static Qty? _qtyOrNull(Object? value) {
    if (value == null) return null;
    final text = value.toString();
    return text.isEmpty ? null : Qty.fromWire(text);
  }

  static List<Json> _maps(Object? value) => ((value as List?) ?? const [])
      .map((e) => (e as Map).cast<String, dynamic>())
      .toList();
}

/// Builds an inbox row from one `/approvals` item. Shared with the repository.
ApprovalsInboxCompanion approvalCompanion(Json j) {
  final project = (j['project'] as Map?)?.cast<String, dynamic>();
  final submittedBy = (j['submitted_by'] as Map?)?.cast<String, dynamic>();
  return ApprovalsInboxCompanion(
    id: Value((j['id'] as num).toInt()),
    docType: Value(j['doc_type'] as String),
    status: Value(j['status'] as String),
    title: Value(j['title'] as String),
    projectId: Value((project?['id'] as num?)?.toInt()),
    projectCode: Value(project?['code'] as String?),
    projectName: Value(project?['name'] as String?),
    submittedById: Value((submittedBy?['id'] as num?)?.toInt()),
    submittedByName: Value(submittedBy?['name'] as String?),
    submittedAt: Value(parseServerTime(j['submitted_at'])),
    currentLevel: Value((j['current_level'] as num?)?.toInt()),
    levels: Value(
      ((j['levels'] as List?) ?? const []).map((e) => e.toString()).toList(),
    ),
    currentRole: Value(j['current_role'] as String?),
    currentRoleLabel: Value(j['current_role_label'] as String?),
    canAct: Value(j['can_act'] as bool? ?? false),
    document: Value((j['document'] as Map?)?.cast<String, dynamic>() ?? const {}),
    steps: Value((j['steps'] as List?) ?? const []),
  );
}
