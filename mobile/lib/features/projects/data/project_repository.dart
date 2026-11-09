import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';

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
