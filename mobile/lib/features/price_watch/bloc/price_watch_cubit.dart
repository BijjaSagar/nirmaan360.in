import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/price_watch_repository.dart';

enum PriceTab { prices, budget }

enum PriceLoad { loading, ready }

class PriceWatchState extends Equatable {
  const PriceWatchState({
    this.load = PriceLoad.loading,
    this.board,
    this.project,
    this.impact,
    this.tab = PriceTab.prices,
    this.refreshing = false,
  });

  final PriceLoad load;

  /// Null until this phone has read the board once.
  final PriceBoardCopy? board;

  /// The project the budget effect is worked out for.
  final ProjectRow? project;

  /// Null without `money.overview`, or before the project has been read.
  final ProjectImpactCopy? impact;
  final PriceTab tab;
  final bool refreshing;

  PriceWatchState copyWith({
    PriceLoad? load,
    PriceBoardCopy? Function()? board,
    ProjectRow? Function()? project,
    ProjectImpactCopy? Function()? impact,
    PriceTab? tab,
    bool? refreshing,
  }) {
    return PriceWatchState(
      load: load ?? this.load,
      board: board == null ? this.board : board(),
      project: project == null ? this.project : project(),
      impact: impact == null ? this.impact : impact(),
      tab: tab ?? this.tab,
      refreshing: refreshing ?? this.refreshing,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, board, project?.id, impact, tab, refreshing];
}

/// The last copy of the board on the phone, read again whenever the server can
/// be reached. The budget effect is read only for someone with `money.overview`.
class PriceWatchCubit extends Cubit<PriceWatchState> {
  PriceWatchCubit(this._services, {PriceTab tab = PriceTab.prices}) : super(PriceWatchState(tab: tab)) {
    _boardSub = _services.priceWatch.watchBoard().listen((board) {
      if (!isClosed) emit(state.copyWith(load: PriceLoad.ready, board: () => board));
    });
    // The project decides what is read, so with a budget to show the first
    // read waits for the project to be known.
    if (_services.priceWatch.seesBudget) {
      _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
    } else {
      unawaited(_open());
    }
  }

  final AppServices _services;
  StreamSubscription<PriceBoardCopy?>? _boardSub;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<ProjectImpactCopy?>? _impactSub;

  PriceWatchRepository get _repo => _services.priceWatch;

  bool _started = false;

  void _onProject(ProjectRow? project) {
    if (_started && state.project?.id == project?.id) return;
    _started = true;
    emit(state.copyWith(project: () => project, impact: () => null));
    _impactSub?.cancel();
    _impactSub = null;
    if (project != null) {
      _impactSub = _repo.watchProject(project.id).listen((impact) {
        if (!isClosed) emit(state.copyWith(impact: () => impact));
      });
    }
    unawaited(_open());
  }

  void setTab(PriceTab tab) => emit(state.copyWith(tab: tab));

  Future<void> _open() async {
    if (!_services.connectivity.isOnline) return;
    await _guarded(() => _repo.refreshAll(projectId: state.project?.id, force: false));
  }

  Future<bool> _guarded(Future<void> Function() read) async {
    if (isClosed) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await read();
      return true;
    } on ApiFailure {
      return false;
    } finally {
      if (!isClosed) emit(state.copyWith(refreshing: false));
    }
  }

  /// Pull to refresh. False means the phone is showing what it already had.
  Future<bool> refresh() async {
    if (!_services.connectivity.isOnline) return false;
    return _guarded(() => _repo.refreshAll(projectId: state.project?.id));
  }

  @override
  Future<void> close() async {
    await _boardSub?.cancel();
    await _projectSub?.cancel();
    await _impactSub?.cancel();
    return super.close();
  }
}
