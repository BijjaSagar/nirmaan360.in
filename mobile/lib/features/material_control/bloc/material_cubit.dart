import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/material_repository.dart';

enum MaterialTab { stock, deliveries, used, counts, receipts }

enum MaterialLoad { loading, noProject, ready }

class MaterialState extends Equatable {
  const MaterialState({
    this.load = MaterialLoad.loading,
    this.project,
    this.overview,
    this.tab = MaterialTab.stock,
    this.refreshing = false,
  });

  final MaterialLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project's material once.
  final MaterialOverview? overview;
  final MaterialTab tab;
  final bool refreshing;

  MaterialState copyWith({
    MaterialLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    MaterialOverview? Function()? overview,
    MaterialTab? tab,
    bool? refreshing,
  }) {
    return MaterialState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      overview: overview == null ? this.overview : overview(),
      tab: tab ?? this.tab,
      refreshing: refreshing ?? this.refreshing,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, project?.id, overview, tab, refreshing];
}

/// The last copy on this phone with unsent work laid over it, refreshed from
/// the server whenever it can be reached.
class MaterialCubit extends Cubit<MaterialState> {
  MaterialCubit(this._services) : super(const MaterialState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<MaterialOverview?>? _cacheSub;
  StreamSubscription<List<OutboxRow>>? _unsentSub;

  MaterialOverview? _cached;
  List<OutboxRow> _unsent = const <OutboxRow>[];

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _unsentSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: MaterialLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _unsentSub?.cancel();
    _cacheSub = _services.material.watchCached(project.id).listen((cached) {
      _cached = cached;
      _publish();
    });
    _unsentSub = _services.material.watchUnsent(project.id).listen((rows) {
      _unsent = rows;
      _publish();
    });
    unawaited(refresh());
  }

  void _publish() {
    if (isClosed) return;
    final MaterialOverview? cached = _cached;
    emit(state.copyWith(load: MaterialLoad.ready, overview: () => cached?.withUnsent(_unsent)));
  }

  void setTab(MaterialTab tab) => emit(state.copyWith(tab: tab));

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await _services.material.refresh(projectId);
      return true;
    } on ApiFailure {
      return false;
    } finally {
      if (!isClosed) emit(state.copyWith(refreshing: false));
    }
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _cacheSub?.cancel();
    await _unsentSub?.cancel();
    return super.close();
  }
}
