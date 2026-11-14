import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/machine_repository.dart';

enum MachineLogsLoad { loading, noProject, ready }

class MachineLogsState extends Equatable {
  const MachineLogsState({
    this.load = MachineLogsLoad.loading,
    this.project,
    this.overview,
    this.refreshing = false,
  });

  final MachineLogsLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project once.
  final MachineOverview? overview;
  final bool refreshing;

  /// A log can be started: a machine is on the phone and the server has not
  /// refused this role.
  bool get canLog {
    final MachineOverview? seen = overview;
    return seen != null && !seen.refused && seen.machines.isNotEmpty;
  }

  MachineLogsState copyWith({
    MachineLogsLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    MachineOverview? Function()? overview,
    bool? refreshing,
  }) {
    return MachineLogsState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      overview: overview == null ? this.overview : overview(),
      refreshing: refreshing ?? this.refreshing,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, project?.id, overview, refreshing];
}

/// The last copy on this phone with logs still waiting laid over it, refreshed
/// from the server whenever it can be reached.
class MachineLogsCubit extends Cubit<MachineLogsState> {
  MachineLogsCubit(this._services) : super(const MachineLogsState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<MachineOverview?>? _cacheSub;
  StreamSubscription<List<OutboxRow>>? _unsentSub;

  MachineOverview? _cached;
  List<OutboxRow> _unsent = const <OutboxRow>[];

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _unsentSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: MachineLogsLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _unsentSub?.cancel();
    _cacheSub = _services.machines.watchCached(project.id).listen((cached) {
      _cached = cached;
      _publish();
    });
    _unsentSub = _services.machines.watchUnsent(project.id).listen((rows) {
      _unsent = rows;
      _publish();
    });
    unawaited(refresh());
  }

  void _publish() {
    if (isClosed) return;
    emit(state.copyWith(load: MachineLogsLoad.ready, overview: () => _cached?.withUnsent(_unsent)));
  }

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await _services.machines.refresh(projectId);
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
