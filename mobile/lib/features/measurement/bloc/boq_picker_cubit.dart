import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../projects/data/project_repository.dart';
import '../data/measurement_repository.dart';

class BoqPickerState extends Equatable {
  const BoqPickerState({this.lines = const [], this.recentIds = const [], this.query = '', this.loading = true});

  /// Every line of the project, in BOQ order.
  final List<BoqLine> lines;

  /// Lines measured most recently first.
  final List<int> recentIds;
  final String query;
  final bool loading;

  /// Recent lines, in the order they were last measured.
  List<BoqLine> get recent {
    if (query.trim().isNotEmpty) return const [];
    final Map<int, BoqLine> byId = {for (final BoqLine line in lines) line.item.id: line};
    return [
      for (final int id in recentIds)
        if (byId[id] != null) byId[id]!,
    ];
  }

  /// The rest of the list. A search matches code, description or unit, and
  /// brings lines measured recently to the top of what it found.
  List<BoqLine> get others {
    final String needle = query.trim().toLowerCase();
    if (needle.isEmpty) {
      final Set<int> shown = recentIds.toSet();
      return [
        for (final BoqLine line in lines)
          if (!shown.contains(line.item.id)) line,
      ];
    }
    bool matches(BoqLine line) =>
        line.item.code.toLowerCase().contains(needle) ||
        line.item.description.toLowerCase().contains(needle) ||
        line.item.unit.toLowerCase().contains(needle);
    final List<BoqLine> found = lines.where(matches).toList();
    final Map<int, int> rank = {for (final (index, id) in recentIds.indexed) id: index};
    found.sort((a, b) {
      final int left = rank[a.item.id] ?? rank.length;
      final int right = rank[b.item.id] ?? rank.length;
      return left.compareTo(right);
    });
    return found;
  }

  BoqPickerState copyWith({List<BoqLine>? lines, List<int>? recentIds, String? query, bool? loading}) {
    return BoqPickerState(
      lines: lines ?? this.lines,
      recentIds: recentIds ?? this.recentIds,
      query: query ?? this.query,
      loading: loading ?? this.loading,
    );
  }

  @override
  List<Object?> get props => [lines, recentIds, query, loading];
}

/// Filters in memory so a keystroke never waits on the database.
class BoqPickerCubit extends Cubit<BoqPickerState> {
  BoqPickerCubit({required this._services, required this._projectId}) : super(const BoqPickerState());

  /// How many recently measured lines stay at the top of the list.
  static const int maxRecent = 5;

  final AppServices _services;
  final int _projectId;
  StreamSubscription<List<BoqLine>>? _lines;
  StreamSubscription<List<SyncedRecord<MeasurementRow>>>? _entries;

  void start() {
    _lines = _services.projects.watchBoqItems(_projectId).listen((lines) {
      if (!isClosed) emit(state.copyWith(lines: lines, loading: false));
    });
    _entries = _services.measurements.watchByProject(_projectId).listen((rows) {
      final List<int> recent = <int>[];
      for (final SyncedRecord<MeasurementRow> row in rows) {
        final int id = row.record.boqItemId;
        if (!recent.contains(id)) recent.add(id);
        if (recent.length == maxRecent) break;
      }
      if (!isClosed) emit(state.copyWith(recentIds: recent));
    });
  }

  void search(String query) => emit(state.copyWith(query: query));

  @override
  Future<void> close() async {
    await _lines?.cancel();
    await _entries?.cancel();
    return super.close();
  }
}
