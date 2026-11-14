import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../projects/data/project_repository.dart';
import '../data/measurement_repository.dart';

class MeasurementListState extends Equatable {
  const MeasurementListState({
    required this.online,
    required this.today,
    this.loading = true,
    this.projectName,
    this.projectId,
    this.rows = const [],
    this.boq = const {},
    this.filter,
  });

  final bool online;
  final String today;
  final bool loading;
  final String? projectName;

  /// The project the book is for, once known: the measurement sheet asks for it.
  final int? projectId;

  /// Every entry of the project, newest first.
  final List<SyncedRecord<MeasurementRow>> rows;
  final Map<int, BoqItemRow> boq;

  /// A server status, or null for all.
  final String? filter;

  List<SyncedRecord<MeasurementRow>> get visible {
    if (filter == null) return rows;
    return rows.where((row) => row.record.status == filter).toList();
  }

  int countOf(String? status) {
    if (status == null) return rows.length;
    return rows.where((row) => row.record.status == status).length;
  }

  MeasurementListState copyWith({
    bool? online,
    bool? loading,
    String? projectName,
    int? projectId,
    List<SyncedRecord<MeasurementRow>>? rows,
    Map<int, BoqItemRow>? boq,
    Object? filter = _keep,
  }) {
    return MeasurementListState(
      online: online ?? this.online,
      today: today,
      loading: loading ?? this.loading,
      projectName: projectName ?? this.projectName,
      projectId: projectId ?? this.projectId,
      rows: rows ?? this.rows,
      boq: boq ?? this.boq,
      filter: identical(filter, _keep) ? this.filter : filter as String?,
    );
  }

  static const Object _keep = Object();

  @override
  List<Object?> get props => [online, today, loading, projectName, projectId, rows, boq, filter];
}

class MeasurementListCubit extends Cubit<MeasurementListState> {
  MeasurementListCubit({required AppServices services})
    : _services = services,
      super(MeasurementListState(online: services.connectivity.isOnline, today: services.context.today));

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _project;
  StreamSubscription<List<SyncedRecord<MeasurementRow>>>? _rows;
  StreamSubscription<List<BoqLine>>? _boq;
  StreamSubscription<bool>? _connectivity;
  int? _projectId;

  void start() {
    _connectivity = _services.connectivity.changes.listen((online) {
      if (!isClosed) emit(state.copyWith(online: online));
    });
    _project = _services.projects.watchCurrentProject().listen(_onProject);
  }

  void _onProject(ProjectRow? project) {
    if (project == null) {
      if (!isClosed) emit(state.copyWith(loading: false));
      return;
    }
    if (project.id == _projectId) return;
    _projectId = project.id;
    if (!isClosed) emit(state.copyWith(projectName: project.name, projectId: project.id));

    _rows?.cancel();
    _rows = _services.measurements.watchByProject(project.id).listen((rows) {
      if (!isClosed) emit(state.copyWith(rows: rows, loading: false));
    });
    _boq?.cancel();
    _boq = _services.projects.watchBoqItems(project.id).listen((lines) {
      if (isClosed) return;
      emit(state.copyWith(boq: {for (final BoqLine line in lines) line.item.id: line.item}));
    });
  }

  void setFilter(String? status) {
    if (status != state.filter) emit(state.copyWith(filter: status));
  }

  @override
  Future<void> close() async {
    await _project?.cancel();
    await _rows?.cancel();
    await _boq?.cancel();
    await _connectivity?.cancel();
    return super.close();
  }
}
