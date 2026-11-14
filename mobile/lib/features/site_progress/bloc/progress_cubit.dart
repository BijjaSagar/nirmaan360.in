import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/progress_repository.dart';

enum ProgressTab { floors, stages, pending }

enum ProgressLoad { loading, noProject, ready }

class ProgressState extends Equatable {
  const ProgressState({
    this.load = ProgressLoad.loading,
    this.project,
    this.overview,
    this.tab = ProgressTab.floors,
    this.trade,
    this.refreshing = false,
  });

  final ProgressLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project's progress once.
  final ProgressOverview? overview;
  final ProgressTab tab;

  /// The trade shown on the stages tab. Null means the first one.
  final String? trade;
  final bool refreshing;

  StageTrade? get currentTrade {
    final List<StageTrade> trades = overview?.matrix.trades ?? const <StageTrade>[];
    if (trades.isEmpty) return null;
    for (final StageTrade candidate in trades) {
      if (candidate.key == trade) return candidate;
    }
    return trades.first;
  }

  ProgressState copyWith({
    ProgressLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    ProgressOverview? Function()? overview,
    ProgressTab? tab,
    String? trade,
    bool? refreshing,
  }) {
    return ProgressState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      overview: overview == null ? this.overview : overview(),
      tab: tab ?? this.tab,
      trade: trade ?? this.trade,
      refreshing: refreshing ?? this.refreshing,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, project?.id, overview, tab, trade, refreshing];
}

/// The project's progress: the last copy on this phone with unsent work laid
/// over it, refreshed from the server whenever it can be reached.
class ProgressCubit extends Cubit<ProgressState> {
  ProgressCubit(this._services) : super(const ProgressState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<ProgressOverview?>? _cacheSub;
  StreamSubscription<List<OutboxRow>>? _unsentSub;

  ProgressOverview? _cached;
  List<OutboxRow> _unsent = const <OutboxRow>[];

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _unsentSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: ProgressLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _unsentSub?.cancel();
    _cacheSub = _services.progress.watchCached(project.id).listen((cached) {
      _cached = cached;
      _publish();
    });
    _unsentSub = _services.progress.watchUnsent(project.id).listen((rows) {
      _unsent = rows;
      _publish();
    });
    unawaited(refresh());
  }

  void _publish() {
    if (isClosed) return;
    final ProgressOverview? cached = _cached;
    emit(state.copyWith(load: ProgressLoad.ready, overview: () => cached?.withUnsent(_unsent)));
  }

  void setTab(ProgressTab tab) => emit(state.copyWith(tab: tab));

  void setTrade(String trade) => emit(state.copyWith(trade: trade));

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await _services.progress.refresh(projectId);
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
