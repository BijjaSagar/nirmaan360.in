import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/site_alerts_repository.dart';

enum SiteTab { dashboard, alerts }

enum SiteLoad { loading, noProject, ready }

class SiteAlertsState extends Equatable {
  const SiteAlertsState({
    this.load = SiteLoad.loading,
    this.project,
    this.overview,
    this.tab = SiteTab.dashboard,
    this.refreshing = false,
  });

  final SiteLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project once.
  final SiteOverview? overview;
  final SiteTab tab;
  final bool refreshing;

  SiteAlertsState copyWith({
    SiteLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    SiteOverview? Function()? overview,
    SiteTab? tab,
    bool? refreshing,
  }) {
    return SiteAlertsState(
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

/// The last copy on this phone, refreshed from the server whenever it can be
/// reached.
class SiteAlertsCubit extends Cubit<SiteAlertsState> {
  SiteAlertsCubit(this._services, {SiteTab tab = SiteTab.dashboard}) : super(SiteAlertsState(tab: tab)) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<SiteOverview?>? _cacheSub;

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: SiteLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _cacheSub = _services.siteAlerts.watchCached(project.id).listen((overview) {
      if (!isClosed) emit(state.copyWith(load: SiteLoad.ready, overview: () => overview));
    });
    unawaited(refresh());
  }

  void setTab(SiteTab tab) => emit(state.copyWith(tab: tab));

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await _services.siteAlerts.refresh(projectId);
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
    return super.close();
  }
}
