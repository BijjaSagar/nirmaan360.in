import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/pull_applier.dart';
import 'project_detail.dart';

export 'project_detail.dart' show ProjectDetail, ValuePhase, phaseShares;

/// A BOQ line with the server's balance and the local preview.
class BoqLine {
  const BoqLine({required this.item, required this.unsentQty});

  final BoqItemRow item;

  /// Net quantity on this phone the server has not counted yet.
  final Qty unsentQty;

  Qty get serverBalance => item.balanceQty;

  /// Server balance minus unsent local entries.
  Qty get previewBalance => item.balanceQty - unsentQty;
}

/// This phone holds work for a BOQ line that has not reached the server, so
/// the line is not removed: the server could not refuse what it has not seen.
class UnsentWorkOnLine implements Exception {
  const UnsentWorkOnLine();
}

/// How far a project's setup has got.
class ProjectSetup {
  const ProjectSetup({
    required this.boqCount,
    required this.workerCount,
    required this.walletCount,
  });

  final int boqCount;

  /// Active workers. Workers belong to the organisation, not to one project.
  final int workerCount;
  final int walletCount;
}

/// Lookup lists from the last pull, so forms work offline.
class Lookups {
  const Lookups({
    this.trades = const [],
    this.units = const [],
    this.expenseCategories = const [],
    this.issuePriorities = const [],
    this.issueStatuses = const [],
    this.attendanceStatuses = const [],
    this.weather = const [],
    this.measurementTolerancePct = '5',
  });

  factory Lookups.fromJson(Map<String, dynamic> json) {
    List<String> list(String key) =>
        ((json[key] as List?) ?? const []).map((e) => e.toString()).toList();
    return Lookups(
      trades: list('trades'),
      units: list('units'),
      expenseCategories: list('expense_categories'),
      issuePriorities: list('issue_priorities'),
      issueStatuses: list('issue_statuses'),
      attendanceStatuses: list('attendance_statuses'),
      weather: list('weather'),
      measurementTolerancePct:
          json['measurement_tolerance_pct']?.toString() ?? '5',
    );
  }

  final List<String> trades;
  final List<String> units;
  final List<String> expenseCategories;
  final List<String> issuePriorities;
  final List<String> issueStatuses;
  final List<String> attendanceStatuses;
  final List<String> weather;
  final String measurementTolerancePct;
}

class ProjectRepository {
  ProjectRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  Stream<List<ProjectRow>> watchProjects() {
    return (_db.select(_db.projects)
          ..orderBy([(t) => OrderingTerm.asc(t.name)]))
        .watch();
  }

  /// Creates a project on the server and stores it locally. Online only.
  /// The caller selects it afterwards with [selectProject].
  ///
  /// Throws `OfflineFailure` when offline and `ValidationFailure` with the
  /// server's `errors`, including `plan` at the plan's project limit.
  Future<ProjectRow> createProject({
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    Money? contractValue,
    List<ValuePhase>? phases,
  }) {
    return _ctx.createOnline(
      identity: ['project', name],
      send: (clientUuid) => _ctx.api.createProject(
        clientUuid: clientUuid,
        name: name,
        location: location,
        startDate: startDate,
        endDate: endDate,
        contractValue: contractValue?.toWire(),
        phases: phases == null || phases.isEmpty ? null : phases.map((p) => p.toWire()).toList(),
      ),
      store: _storeProject,
    );
  }

  /// The project as the edit screen needs it. Online only, as project setup
  /// is: throws `OfflineFailure` without network.
  Future<ProjectDetail> loadProject(int projectId) async {
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();
    return ProjectDetail.fromJson(await _ctx.api.projectDetail(projectId));
  }

  /// Changes a project on the server and the copy on this phone. Online only.
  ///
  /// [phases] replace the saved ones and set the contract value to their
  /// total; an empty list makes it one figure again, [contractValue]. Money is
  /// sent only when [withMoney], for someone the server lets see it.
  ///
  /// Throws `OfflineFailure` when offline and `ValidationFailure` with the
  /// server's `errors`.
  Future<ProjectDetail> updateProject(
    int projectId, {
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    bool withMoney = false,
    Money? contractValue,
    List<ValuePhase> phases = const <ValuePhase>[],
  }) async {
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();
    final Map<String, dynamic> json = await _ctx.api.updateProject(projectId, <String, dynamic>{
      'name': name,
      'location': location,
      'start_date': startDate,
      'end_date': endDate,
      if (withMoney) ...<String, dynamic>{
        'phases': phases.map((p) => p.toWire()).toList(),
        if (phases.isEmpty) 'contract_value': (contractValue ?? Money.zero).toWire(),
      },
    });
    await _db.transaction(() => _storeProject(json));
    return ProjectDetail.fromJson(json);
  }

  Future<ProjectRow> _storeProject(Map<String, dynamic> json) async {
    final row = PullApplier.projectCompanion(json);
    await _db.into(_db.projects).insertOnConflictUpdate(row);
    return (_db.select(_db.projects)..where((t) => t.id.equals(row.id.value))).getSingle();
  }

  /// Adds a BOQ line on the server and stores it locally. Online only.
  /// A blank [code] becomes the next number in the project.
  Future<BoqItemRow> addBoqItem({
    required int projectId,
    required String description,
    required String unit,
    required Qty qty,
    required Money rate,
    String? code,
    String? section,
  }) {
    return _ctx.createOnline(
      identity: ['boq', projectId, description, unit, code, section],
      send: (clientUuid) => _ctx.api.addBoqItem(
        projectId: projectId,
        clientUuid: clientUuid,
        description: description,
        unit: unit,
        qty: qty.toWire(),
        rate: rate.toWire(),
        code: code,
        section: section,
      ),
      store: (json) async {
        var row = PullApplier.boqItemCompanion(json);
        // Nothing is measured on a new line, so its balance is its quantity.
        if (json['balance_qty'] == null) {
          row = row.copyWith(balanceQty: row.qty);
        }
        await _db.into(_db.boqItems).insertOnConflictUpdate(row);
        return (_db.select(_db.boqItems)
              ..where((t) => t.id.equals(row.id.value)))
            .getSingle();
      },
    );
  }

  /// One BOQ line as this phone last had it.
  Future<BoqItemRow?> getBoqItem(int boqItemId) =>
      (_db.select(_db.boqItems)..where((t) => t.id.equals(boqItemId))).getSingleOrNull();

  /// Edits a BOQ line on the server and stores the reply. Online only, with no
  /// outbox: the office's BOQ is changed now or not at all.
  ///
  /// [rate] is null for someone who may not see money; the server then keeps
  /// the rate. [openedAt] is the line's `updated_at` when the form opened. When
  /// someone has changed the line since, the server's copy is stored here and
  /// the 409 [ServerFailure] (`code: changed`) is rethrown, so the form can
  /// show the line as it is now. A line the server no longer has (404) is
  /// taken off this phone and the failure rethrown.
  Future<BoqItemRow> updateBoqItem({
    required int boqItemId,
    required String code,
    required String description,
    required String unit,
    required Qty qty,
    Money? rate,
    required String reason,
    DateTime? openedAt,
  }) async {
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();
    final Map<String, dynamic> json;
    try {
      json = await _ctx.api.updateBoqItem(
        boqItemId: boqItemId,
        code: code,
        description: description,
        unit: unit,
        qty: qty.toWire(),
        rate: rate?.toWire(),
        reason: reason,
        updatedAt: openedAt?.toUtc().toIso8601String(),
      );
    } on ServerFailure catch (failure) {
      final Object? current = failure.data?['data'];
      if (failure.statusCode == 409 && current is Map) {
        await _storeBoqRow(current.cast<String, dynamic>());
      } else if (failure.statusCode == 404) {
        await _forgetBoqItem(boqItemId);
      }
      rethrow;
    }
    return _storeBoqRow(json);
  }

  /// Removes a BOQ line on the server, then from this phone. Online only. The
  /// server lets only the company owner do it and refuses a line that anything
  /// is recorded against, with a 422 on `boq` naming each record.
  ///
  /// Throws [UnsentWorkOnLine] first when this phone holds work for the line
  /// the server has not had yet, which the server could not know about.
  /// A line the server no longer has counts as removed.
  Future<void> deleteBoqItem({required int boqItemId, required String reason}) async {
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();
    if (await hasUnsentWorkOn(boqItemId)) throw const UnsentWorkOnLine();
    try {
      await _ctx.api.deleteBoqItem(boqItemId: boqItemId, reason: reason);
    } on ServerFailure catch (failure) {
      if (failure.statusCode != 404) rethrow;
    }
    await _forgetBoqItem(boqItemId);
  }

  /// Whether this phone holds work for [boqItemId] that has not reached the
  /// server: a measurement never sent, or anything still in the outbox that
  /// names the line.
  Future<bool> hasUnsentWorkOn(int boqItemId) async {
    final MeasurementRow? unsent =
        await (_db.select(_db.measurements)
              ..where((t) => t.boqItemId.equals(boqItemId) & t.serverId.isNull())
              ..limit(1))
            .getSingleOrNull();
    if (unsent != null) return true;
    for (final OutboxRow row in await _ctx.outbox.unfinished()) {
      Object? payload;
      try {
        payload = jsonDecode(row.payload);
      } on FormatException {
        continue;
      }
      if (_namesLine(payload, boqItemId)) return true;
    }
    return false;
  }

  static bool _namesLine(Object? node, int boqItemId) {
    if (node is Map) {
      for (final MapEntry<dynamic, dynamic> entry in node.entries) {
        final Object? value = entry.value;
        if (entry.key == 'boq_item_id' && value is num && value.toInt() == boqItemId) return true;
        if (_namesLine(value, boqItemId)) return true;
      }
      return false;
    }
    if (node is List) return node.any((Object? element) => _namesLine(element, boqItemId));
    return false;
  }

  Future<BoqItemRow> _storeBoqRow(Map<String, dynamic> json) async {
    final BoqItemsCompanion row = PullApplier.boqItemCompanion(json);
    await _db.into(_db.boqItems).insertOnConflictUpdate(row);
    return (_db.select(_db.boqItems)..where((t) => t.id.equals(row.id.value))).getSingle();
  }

  Future<void> _forgetBoqItem(int boqItemId) =>
      (_db.delete(_db.boqItems)..where((t) => t.id.equals(boqItemId))).go();

  /// Live counts of what has been set up for a project.
  Stream<ProjectSetup> watchSetup(int projectId) {
    return _db
        .customSelect(
          'SELECT '
          '(SELECT COUNT(*) FROM boq_items WHERE project_id = ?1) AS boq, '
          '(SELECT COUNT(*) FROM workers WHERE is_active = 1) AS workers, '
          '(SELECT COUNT(*) FROM wallets WHERE project_id = ?1) AS wallets',
          variables: [Variable.withInt(projectId)],
          readsFrom: {_db.boqItems, _db.workers, _db.wallets},
        )
        .watchSingle()
        .map(
          (row) => ProjectSetup(
            boqCount: row.read<int>('boq'),
            workerCount: row.read<int>('workers'),
            walletCount: row.read<int>('wallets'),
          ),
        );
  }

  Future<void> selectProject(int projectId) =>
      _db.writeMeta(MetaKey.selectedProject, projectId.toString());

  Future<int?> readSelectedProjectId() async {
    final raw = await _db.readMeta(MetaKey.selectedProject);
    return raw == null ? null : int.tryParse(raw);
  }

  /// The selected project, or the first one when none is selected yet.
  Stream<ProjectRow?> watchCurrentProject() {
    return _db.watchDerived({_db.projects, _db.keyValues}, () async {
      final selectedId = await readSelectedProjectId();
      if (selectedId != null) {
        final project =
            await (_db.select(_db.projects)
                  ..where((t) => t.id.equals(selectedId)))
                .getSingleOrNull();
        if (project != null) return project;
      }
      final projects =
          await (_db.select(_db.projects)
                ..orderBy([(t) => OrderingTerm.asc(t.name)])
                ..limit(1))
              .get();
      return projects.isEmpty ? null : projects.first;
    });
  }

  Stream<List<SiteRow>> watchSites(int projectId) {
    return (_db.select(_db.sites)
          ..where((t) => t.projectId.equals(projectId))
          ..orderBy([(t) => OrderingTerm.asc(t.name)]))
        .watch();
  }

  Future<ProjectRow?> getProject(int projectId) {
    return (_db.select(_db.projects)..where((t) => t.id.equals(projectId)))
        .getSingleOrNull();
  }

  /// BOQ lines with the live balance preview. [search] matches code or
  /// description.
  Stream<List<BoqLine>> watchBoqItems(int projectId, {String? search}) {
    final query = search?.trim();
    return _db.watchDerived({_db.boqItems, _db.measurements}, () async {
      final items =
          await (_db.select(_db.boqItems)
                ..where((t) {
                  var condition = t.projectId.equals(projectId);
                  if (query != null && query.isNotEmpty) {
                    condition =
                        condition &
                        (t.code.contains(query) | t.description.contains(query));
                  }
                  return condition;
                })
                ..orderBy([
                  (t) => OrderingTerm.asc(t.sort),
                  (t) => OrderingTerm.asc(t.code),
                ]))
              .get();

      final unsent = await _unsentByBoqItem(projectId);
      return [
        for (final item in items)
          BoqLine(item: item, unsentQty: unsent[item.id] ?? Qty.zero),
      ];
    });
  }

  /// Live balance preview for one BOQ line.
  Stream<BoqLine?> watchBoqLine(int boqItemId) {
    return _db.watchDerived({_db.boqItems, _db.measurements}, () async {
      final item =
          await (_db.select(_db.boqItems)
                ..where((t) => t.id.equals(boqItemId)))
              .getSingleOrNull();
      if (item == null) return null;
      final unsent = await _unsentByBoqItem(item.projectId);
      return BoqLine(item: item, unsentQty: unsent[item.id] ?? Qty.zero);
    });
  }

  /// Sums in Dart, because SUM over TEXT in sqlite would use floats.
  Future<Map<int, Qty>> _unsentByBoqItem(int projectId) async {
    final entries =
        await (_db.select(_db.measurements)..where(
              (t) =>
                  t.projectId.equals(projectId) &
                  (t.serverQty.isNull() | t.qty.equalsExp(t.serverQty).not()),
            ))
            .get();

    final totals = <int, Qty>{};
    for (final entry in entries) {
      final delta = entry.qty - (entry.serverQty ?? Qty.zero);
      totals[entry.boqItemId] = (totals[entry.boqItemId] ?? Qty.zero) + delta;
    }
    return totals;
  }

  Stream<Lookups> watchLookups() {
    return _db.watchMeta(MetaKey.lookups).map((raw) {
      if (raw == null) return const Lookups();
      return Lookups.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
    });
  }

  Stream<List<UserRow>> watchUsers() {
    return (_db.select(_db.users)
          ..orderBy([(t) => OrderingTerm.asc(t.name)]))
        .watch();
  }
}
