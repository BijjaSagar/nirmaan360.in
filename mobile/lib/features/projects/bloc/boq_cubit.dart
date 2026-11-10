import 'dart:async';

import 'package:decimal/decimal.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../../core/sync/sync_status.dart' show SyncOutcome, SyncRunResult;
import '../../tenders/data/tender_repository.dart';
import '../data/project_repository.dart';

export '../data/project_repository.dart' show BoqLine;

/// How much of a line has been measured, counting entries still on the phone
/// so it agrees with the balance shown beside it.
class MeasuredShare {
  const MeasuredShare({required this.fraction, required this.percent});

  /// 0 to 1, for drawing only.
  final double fraction;

  /// Whole percent, rounded down: a line shows 100% only when it is finished.
  final int percent;
}

MeasuredShare measuredShareOf(BoqLine line) {
  final Decimal qty = line.item.qty.value;
  if (qty <= Decimal.zero) return const MeasuredShare(fraction: 0, percent: 0);
  final Decimal measured = qty - line.previewBalance.value;
  if (measured <= Decimal.zero) return const MeasuredShare(fraction: 0, percent: 0);
  return MeasuredShare(
    fraction: (measured / qty).toDouble().clamp(0.0, 1.0),
    percent: (measured * Decimal.fromInt(100) / qty).floor().toInt(),
  );
}

/// The lines of one BOQ section, in BOQ order. A null [name] is lines with no
/// section.
class BoqSectionLines {
  const BoqSectionLines({required this.name, required this.lines});

  final String? name;
  final List<BoqLine> lines;
}

enum BoqLoad { loading, ready, noProject }

class BoqState {
  const BoqState({
    this.load = BoqLoad.loading,
    this.project,
    this.all = const [],
    this.query = '',
    this.sections = const [],
    this.markers = const <int, TenderMarker>{},
  });

  final BoqLoad load;
  final ProjectRow? project;
  final List<BoqLine> all;
  final String query;

  /// [all] narrowed to [query] and grouped by section.
  final List<BoqSectionLines> sections;

  /// Lines in a tender, by BOQ item id, as the office last said. Read live and
  /// kept only while the screen is open, so offline the lines show without them.
  final Map<int, TenderMarker> markers;

  BoqState copyWith({
    BoqLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    List<BoqLine>? all,
    String? query,
    List<BoqSectionLines>? sections,
    Map<int, TenderMarker>? markers,
  }) {
    return BoqState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      all: all ?? this.all,
      query: query ?? this.query,
      sections: sections ?? this.sections,
      markers: markers ?? this.markers,
    );
  }
}

/// The BOQ of the current project. Search filters in memory, so a keystroke
/// never waits on the database.
class BoqCubit extends Cubit<BoqState> {
  BoqCubit(this._services) : tenders = TenderRepository(_services.context), super(const BoqState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;

  /// Tender markers beside each line, for those who may see tenders.
  final TenderRepository tenders;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<BoqLine>>? _linesSub;

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _linesSub?.cancel();
      _linesSub = null;
      emit(state.copyWith(clearProject: true, load: BoqLoad.noProject));
      return;
    }
    if (state.project?.id == project.id && _linesSub != null) return;
    emit(state.copyWith(project: project));
    _linesSub?.cancel();
    _linesSub = _services.projects.watchBoqItems(project.id).listen(_onLines);
    _loadMarkers(project.id);
  }

  /// Best effort: without the server the BOQ still reads, just without markers.
  Future<void> _loadMarkers(int projectId) async {
    if (!tenders.canView || !_services.connectivity.isOnline) return;
    try {
      final Map<int, TenderMarker> markers = await tenders.markers(projectId);
      if (!isClosed && state.project?.id == projectId) emit(state.copyWith(markers: markers));
    } on ApiFailure {
      // The last markers stay.
    }
  }

  void _onLines(List<BoqLine> lines) {
    emit(
      state.copyWith(
        load: BoqLoad.ready,
        all: lines,
        sections: _group(lines, state.query),
      ),
    );
  }

  void search(String query) {
    emit(state.copyWith(query: query, sections: _group(state.all, query)));
  }

  /// Pulls what the office has changed. The lines update themselves from the
  /// database, so this only reports whether the server was reached: false
  /// means the phone is showing the BOQ it already had.
  Future<bool> refresh() async {
    if (!_services.connectivity.isOnline) return false;
    try {
      final SyncRunResult result = await _services.sync.syncNow();
      final int? projectId = state.project?.id;
      if (projectId != null) await _loadMarkers(projectId);
      // Busy means a sync is already running and will bring the same data.
      return result.isSuccess || result.outcome == SyncOutcome.busy;
    } on Object {
      return false;
    }
  }

  static List<BoqSectionLines> _group(List<BoqLine> lines, String query) {
    final String needle = query.trim().toLowerCase();
    bool matches(BoqLine line) {
      if (needle.isEmpty) return true;
      return line.item.code.toLowerCase().contains(needle) ||
          line.item.description.toLowerCase().contains(needle) ||
          line.item.unit.toLowerCase().contains(needle) ||
          (line.item.section ?? '').toLowerCase().contains(needle);
    }

    final byName = <String?, List<BoqLine>>{};
    for (final BoqLine line in lines) {
      if (!matches(line)) continue;
      final String? section = line.item.section?.trim();
      final String? name = section == null || section.isEmpty ? null : section;
      byName.putIfAbsent(name, () => []).add(line);
    }
    return <BoqSectionLines>[
      for (final entry in byName.entries)
        BoqSectionLines(name: entry.key, lines: entry.value),
    ];
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _linesSub?.cancel();
    return super.close();
  }
}
