import 'dart:async';
import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter/foundation.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/offline/offline_plan.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/pull_applier.dart';
import '../../../core/sync/sync_engine.dart';
import '../../dpr/data/dpr_repository.dart';

/// Why an upload stopped. Nothing is lost in any of these.
enum UploadStop { noNetwork, signIn, busy, stillOffline, planLimit, refused }

/// What an upload attempt came to.
@immutable
class UploadReport {
  const UploadReport.ready() : stop = null, detail = null;
  const UploadReport.stopped(UploadStop this.stop, [this.detail]);

  final UploadStop? stop;

  /// The server's own words for [UploadStop.planLimit] and [UploadStop.refused].
  final String? detail;

  bool get isReady => stop == null;
}

/// Takes what was built on this phone to the server, after the owner said yes.
///
/// Masters first, one at a time. Each is created with the `client_uuid` chosen
/// when it was made here, so a lost reply gets the original row back; then, in
/// one transaction, every reference to its local id moves to the server's id.
/// A crash at any point leaves each record either untouched or fully moved,
/// and running again carries on from there. The queued entries then go through
/// the normal sync, which already cannot send anything twice.
class OfflineUploader {
  OfflineUploader(this._ctx, this._dpr);

  final DataContext _ctx;
  final DprRepository _dpr;

  AppDatabase get _db => _ctx.db;
  bool _running = false;

  /// Reports `done` of `total` as records are moved.
  ValueChanged<(int, int)>? onProgress;

  /// Moves every local master and rewrites everything that points at them.
  /// Call [finish] once this reports ready. Safe to call again at any time.
  Future<UploadReport> run() async {
    if (_running) return const UploadReport.stopped(UploadStop.busy);
    _running = true;
    try {
      return await _run();
    } on OfflineFailure {
      return const UploadReport.stopped(UploadStop.noNetwork);
    } on AuthExpired {
      return const UploadReport.stopped(UploadStop.signIn);
    } on RateLimited {
      return const UploadReport.stopped(UploadStop.busy);
    } on OfflinePlanRefused {
      await _ctx.offlinePlan.check(force: true);
      return const UploadReport.stopped(UploadStop.stillOffline);
    } on ValidationFailure catch (e) {
      final String detail = e.errors.values.expand((m) => m).firstOrNull ?? e.message;
      return UploadReport.stopped(e.errors.containsKey('plan') ? UploadStop.planLimit : UploadStop.refused, detail);
    } on ServerFailure catch (e) {
      return UploadReport.stopped(UploadStop.refused, e.message);
    } finally {
      _running = false;
    }
  }

  Future<UploadReport> _run() async {
    final Map<String, dynamic> status = await _ctx.api.offlineStatus();
    if (status['offline'] == true) {
      await _ctx.offlinePlan.check(force: true);
      return const UploadReport.stopped(UploadStop.stillOffline);
    }
    await _ctx.offlinePlan.beginUpload();

    // A saved report goes up with everything else, still as a draft.
    final drafts = await (_db.select(_db.dprs)..where((t) => t.localDraft.equals(true))).get();
    for (final DprRow draft in drafts) {
      try {
        await _dpr.send(draft.clientUuid);
      } on Exception catch (e) {
        // It stays a draft on this phone rather than stopping the rest.
        debugPrint('A draft report could not be queued: $e');
      }
    }

    final OfflineWork work = await _ctx.offlinePlan.work();
    final int total = work.projects + work.boqLines + work.workers + work.wallets;
    var done = 0;
    void moved() => onProgress?.call((++done, total));
    onProgress?.call((done, total));

    for (final ProjectRow row in await (_db.select(_db.projects)..where((t) => t.id.isSmallerThanValue(0))).get()) {
      await _uploadProject(row);
      moved();
    }
    for (final BoqItemRow row in await (_db.select(_db.boqItems)..where((t) => t.id.isSmallerThanValue(0))).get()) {
      await _uploadBoqItem(row);
      moved();
    }
    for (final WorkerRow row in await (_db.select(_db.workers)..where((t) => t.id.isSmallerThanValue(0))).get()) {
      await _uploadWorker(row);
      moved();
    }
    for (final WalletRow row in await (_db.select(_db.wallets)..where((t) => t.id.isSmallerThanValue(0))).get()) {
      await _uploadWallet(row);
      moved();
    }
    return const UploadReport.ready();
  }

  /// Hands over to normal syncing. The outbox goes up from here, with the
  /// `client_uuid` each entry has had since it was made.
  Future<void> finish(SyncEngine sync) async {
    await _db.writeMeta(MetaKey.fullRefresh, '1');
    await _ctx.offlinePlan.leave();
    await sync.syncNow();
  }

  Future<void> _uploadProject(ProjectRow row) async {
    final Map<String, dynamic> details = await _details(LocalRecordKind.project, row.id);
    final Map<String, dynamic> json = await _ctx.api.createProject(
      clientUuid: details['uuid'] as String,
      name: row.name,
      location: row.location,
      startDate: row.startDate,
      endDate: row.endDate,
      contractValue: details['contract_value'] as String?,
    );
    final ProjectsCompanion server = PullApplier.projectCompanion(json);
    final int newId = server.id.value;

    await _db.transaction(() async {
      for (final String table in const <String>[
        'sites',
        'boq_items',
        'wallets',
        'dprs',
        'measurements',
        'issues',
        'attendance_marks',
        'petty_cash_entries',
        'outbox',
      ]) {
        await _update('UPDATE $table SET project_id = ? WHERE project_id = ?', <Variable<Object>>[Variable<int>(newId), Variable<int>(row.id)]);
      }
      await _rewritePayloads('project_id', row.id, newId);
      await _db.into(_db.projects).insertOnConflictUpdate(server);
      await (_db.delete(_db.projects)..where((t) => t.id.equals(row.id))).go();
      if (await _db.readMeta(MetaKey.selectedProject) == '${row.id}') {
        await _db.writeMeta(MetaKey.selectedProject, '$newId');
      }
      await _db.deleteMeta(MetaKey.offlineRecord(LocalRecordKind.project, row.id));
    });
  }

  Future<void> _uploadBoqItem(BoqItemRow row) async {
    final Map<String, dynamic> details = await _details(LocalRecordKind.boqItem, row.id);
    final Map<String, dynamic> json = await _ctx.api.addBoqItem(
      projectId: row.projectId,
      clientUuid: details['uuid'] as String,
      description: row.description,
      unit: row.unit,
      qty: row.qty.toWire(),
      rate: row.rate.toWire(),
      code: row.code,
      section: row.section,
    );
    var server = PullApplier.boqItemCompanion(json);
    if (json['balance_qty'] == null) server = server.copyWith(balanceQty: server.qty);
    final int newId = server.id.value;

    await _db.transaction(() async {
      await _update('UPDATE measurements SET boq_item_id = ? WHERE boq_item_id = ?', <Variable<Object>>[Variable<int>(newId), Variable<int>(row.id)]);
      await _rewriteDprWorkItems(row.id, newId);
      await _rewritePayloads('boq_item_id', row.id, newId);
      await _db.into(_db.boqItems).insertOnConflictUpdate(server);
      await (_db.delete(_db.boqItems)..where((t) => t.id.equals(row.id))).go();
      await _db.deleteMeta(MetaKey.offlineRecord(LocalRecordKind.boqItem, row.id));
    });
  }

  Future<void> _uploadWorker(WorkerRow row) async {
    final Map<String, dynamic> details = await _details(LocalRecordKind.worker, row.id);
    final Map<String, dynamic> json = await _ctx.api.addWorker(
      clientUuid: details['uuid'] as String,
      name: row.name,
      trade: row.trade,
      phone: row.phone,
      type: row.type,
      dailyWage: details['daily_wage'] as String?,
    );
    final int newId = (json['id'] as num).toInt();

    await _db.transaction(() async {
      await _update('UPDATE attendance_marks SET worker_id = ? WHERE worker_id = ?', <Variable<Object>>[Variable<int>(newId), Variable<int>(row.id)]);
      await _rewritePayloads('worker_id', row.id, newId);
      await _db.into(_db.workers).insertOnConflictUpdate(
        WorkersCompanion(
          id: Value(newId),
          name: Value(json['name'] as String? ?? row.name),
          phone: Value(json['phone'] as String? ?? row.phone),
          trade: Value(json['trade'] as String? ?? row.trade),
          type: Value(json['type'] as String? ?? row.type),
          isActive: Value(json['is_active'] as bool? ?? true),
        ),
      );
      await (_db.delete(_db.workers)..where((t) => t.id.equals(row.id))).go();
      await _db.deleteMeta(MetaKey.offlineRecord(LocalRecordKind.worker, row.id));
    });
  }

  Future<void> _uploadWallet(WalletRow row) async {
    final Map<String, dynamic> details = await _details(LocalRecordKind.wallet, row.id);
    final Map<String, dynamic> json = await _ctx.api.createWallet(
      projectId: row.projectId,
      clientUuid: details['uuid'] as String,
      name: row.name,
      userId: row.userId,
    );
    final WalletsCompanion server = PullApplier.walletCompanion(json);
    final int newId = server.id.value;

    await _db.transaction(() async {
      await _update('UPDATE petty_cash_entries SET wallet_id = ? WHERE wallet_id = ?', <Variable<Object>>[Variable<int>(newId), Variable<int>(row.id)]);
      await _rewritePayloads('wallet_id', row.id, newId);
      await _db.into(_db.wallets).insertOnConflictUpdate(server);
      await (_db.delete(_db.wallets)..where((t) => t.id.equals(row.id))).go();
      await _db.deleteMeta(MetaKey.offlineRecord(LocalRecordKind.wallet, row.id));
    });
  }

  /// Tells every open list that rows may have moved.
  Future<int> _update(String sql, List<Variable<Object>> variables) {
    return _db.customUpdate(sql, variables: variables, updates: _db.allTables.toSet());
  }

  /// The upload id made with the record. If it is ever missing a new one is
  /// stored before it is used, so even then a retry sends the same one.
  Future<Map<String, dynamic>> _details(String kind, int id) async {
    final String key = MetaKey.offlineRecord(kind, id);
    final String? raw = await _db.readMeta(key);
    if (raw != null) {
      final Object? parsed = jsonDecode(raw);
      if (parsed is Map && parsed['uuid'] is String) return parsed.cast<String, dynamic>();
    }
    final created = <String, dynamic>{'uuid': newUuid()};
    await _db.writeMeta(key, jsonEncode(created));
    return created;
  }

  /// Moves a reference inside queued work. Only rows that mention the id are
  /// parsed, and the parsed value decides, so -3 can never match -31.
  Future<void> _rewritePayloads(String key, int oldId, int newId) async {
    final rows = await _db
        .customSelect(
          "SELECT id, payload FROM outbox WHERE status <> 'done' AND payload LIKE ?",
          variables: <Variable<Object>>[Variable<String>('%"$key":$oldId%')],
        )
        .get();
    for (final row in rows) {
      final Object? decoded = jsonDecode(row.read<String>('payload'));
      if (_replaceId(decoded, key, oldId, newId)) {
        await _update('UPDATE outbox SET payload = ? WHERE id = ?', <Variable<Object>>[Variable<String>(jsonEncode(decoded)), Variable<int>(row.read<int>('id'))]);
      }
    }
  }

  Future<void> _rewriteDprWorkItems(int oldId, int newId) async {
    final rows = await _db
        .customSelect(
          'SELECT client_uuid, work_items FROM dprs WHERE work_items LIKE ?',
          variables: <Variable<Object>>[Variable<String>('%"boq_item_id":$oldId%')],
        )
        .get();
    for (final row in rows) {
      final Object? decoded = jsonDecode(row.read<String>('work_items'));
      if (_replaceId(decoded, 'boq_item_id', oldId, newId)) {
        await _update('UPDATE dprs SET work_items = ? WHERE client_uuid = ?', <Variable<Object>>[Variable<String>(jsonEncode(decoded)), Variable<String>(row.read<String>('client_uuid'))]);
      }
    }
  }

  static bool _replaceId(Object? value, String key, int oldId, int newId) {
    var changed = false;
    if (value is Map) {
      for (final Object? name in value.keys.toList()) {
        if (name == key && value[name] == oldId) {
          value[name] = newId;
          changed = true;
        } else if (_replaceId(value[name], key, oldId, newId)) {
          changed = true;
        }
      }
    } else if (value is List) {
      for (final Object? item in value) {
        if (_replaceId(item, key, oldId, newId)) changed = true;
      }
    }
    return changed;
  }
}
