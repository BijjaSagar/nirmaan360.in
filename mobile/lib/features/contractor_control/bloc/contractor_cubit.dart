import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/contractor_repository.dart';

enum ContractorTab { contracts, payments }

enum ContractorLoad { loading, noProject, ready }

class ContractorState extends Equatable {
  const ContractorState({
    this.load = ContractorLoad.loading,
    this.project,
    this.overview,
    this.tab = ContractorTab.contracts,
    this.refreshing = false,
  });

  final ContractorLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project once.
  final ContractorOverview? overview;
  final ContractorTab tab;
  final bool refreshing;

  ContractorState copyWith({
    ContractorLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    ContractorOverview? Function()? overview,
    ContractorTab? tab,
    bool? refreshing,
  }) {
    return ContractorState(
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

/// The last copy on this phone with waiting requests laid over it, refreshed
/// from the server whenever it can be reached. With [contractId] only that
/// contract is read again.
class ContractorCubit extends Cubit<ContractorState> {
  ContractorCubit(this._services, {this.contractId}) : super(const ContractorState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  final int? contractId;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<ContractorOverview?>? _cacheSub;
  StreamSubscription<List<OutboxRow>>? _unsentSub;

  ContractorOverview? _cached;
  List<OutboxRow> _unsent = const <OutboxRow>[];

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _unsentSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: ContractorLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _unsentSub?.cancel();
    _cacheSub = _services.contractors.watchCached(project.id).listen((cached) {
      _cached = cached;
      _publish();
    });
    _unsentSub = _services.contractors.watchUnsent(project.id).listen((rows) {
      _unsent = rows;
      _publish();
    });
    unawaited(refresh());
  }

  void _publish() {
    if (isClosed) return;
    final bool seesMoney = _services.auth.can('money.view');
    emit(
      state.copyWith(
        load: ContractorLoad.ready,
        overview: () => _cached?.withUnsent(_unsent, seesMoney: seesMoney),
      ),
    );
  }

  void setTab(ContractorTab tab) => emit(state.copyWith(tab: tab));

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      final int? id = contractId;
      if (id == null) {
        await _services.contractors.refresh(projectId);
      } else {
        await _services.contractors.refreshContract(projectId, id);
      }
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
