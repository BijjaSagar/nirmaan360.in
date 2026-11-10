import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/offline/offline_plan.dart' show LocalRecordKind;
import '../../../core/api/api_failure.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/pull_applier.dart';

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
  }) {
    if (_ctx.offlinePlan.isLocalOnly) {
      return _createLocalProject(
        name: name,
        location: location,
        startDate: startDate,
        endDate: endDate,
        contractValue: contractValue,
      );
    }
    return _ctx.createOnline(
      identity: ['project', name],
      send: (clientUuid) => _ctx.api.createProject(
        clientUuid: clientUuid,
        name: name,
        location: location,
        startDate: startDate,
        endDate: endDate,
        contractValue: contractValue?.toWire(),
      ),
      store: (json) async {
        final row = PullApplier.projectCompanion(json);
        await _db.into(_db.projects).insertOnConflictUpdate(row);
        return (_db.select(_db.projects)
              ..where((t) => t.id.equals(row.id.value)))
            .getSingle();
      },
    );
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
    if (_ctx.offlinePlan.isLocalOnly) {
      return _addLocalBoqItem(
        projectId: projectId,
        description: description,
        unit: unit,
        qty: qty,
        rate: rate,
        code: code,
        section: section,
      );
    }
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

  Future<ProjectRow> _createLocalProject({
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    Money? contractValue,
  }) async {
    _ctx.assertWithin(<(String, String?, int)>[('name', name, 160), ('location', location, 160)]);
    if (startDate != null && endDate != null && endDate.compareTo(startDate) < 0) {
      const message = 'The end date cannot be before the start date.';
      throw const ValidationFailure(message, errors: {'end_date': [message]});
    }
    return _ctx.createLocal(
      kind: LocalRecordKind.project,
      table: 'projects',
      extras: <String, Object?>{'contract_value': contractValue?.toWire()},
      insert: (id) async {
        // The server gives it a real code on upload.
        await _db.into(_db.projects).insert(
          ProjectsCompanion.insert(
            id: Value(id),
            code: 'L${-id}',
            name: name,
            location: Value(location),
            startDate: Value(startDate),
            endDate: Value(endDate),
            updatedAt: Value(_ctx.clock.now()),
          ),
        );
        return (_db.select(_db.projects)..where((t) => t.id.equals(id))).getSingle();
      },
    );
  }

  Future<BoqItemRow> _addLocalBoqItem({
    required int projectId,
    required String description,
    required String unit,
    required Qty qty,
    required Money rate,
    String? code,
    String? section,
  }) async {
    _ctx.assertWithin(<(String, String?, int)>[
      ('description', description, 2000),
      ('unit', unit, 16),
      ('code', code, 32),
      ('section', section, 120),
    ]);
    final lines = await (_db.select(_db.boqItems)..where((t) => t.projectId.equals(projectId))).get();
    var last = lines.length;
    for (final BoqItemRow line in lines) {
      final int? number = int.tryParse(line.code);
      if (number != null && number > last) last = number;
    }
    return _ctx.createLocal(
      kind: LocalRecordKind.boqItem,
      table: 'boq_items',
      insert: (id) async {
        await _db.into(_db.boqItems).insert(
          BoqItemsCompanion.insert(
            id: Value(id),
            projectId: projectId,
            section: Value(section),
            code: code?.trim().isNotEmpty ?? false ? code!.trim() : '${last + 1}',
            description: description,
            unit: unit,
            qty: qty,
            rate: rate,
            sort: Value(last + 1),
            measuredQty: Qty.zero,
            approvedQty: Qty.zero,
            balanceQty: qty,
            updatedAt: Value(_ctx.clock.now()),
          ),
        );
        return (_db.select(_db.boqItems)..where((t) => t.id.equals(id))).getSingle();
      },
    );
  }

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
