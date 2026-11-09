import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow, UserRow;
import '../data/issue_repository.dart';
import 'issue_helpers.dart';

enum IssueFilter { all, open, inProgress, resolved, closed }

extension IssueFilterStatus on IssueFilter {
  /// The server status this filter keeps, or null for all.
  String? get status => switch (this) {
    IssueFilter.all => null,
    IssueFilter.open => IssueStatus.open,
    IssueFilter.inProgress => IssueStatus.inProgress,
    IssueFilter.resolved => IssueStatus.resolved,
    IssueFilter.closed => IssueStatus.closed,
  };
}

enum IssueListLoad { loading, ready, noProject }

class IssueListState {
  const IssueListState({
    required this.today,
    this.load = IssueListLoad.loading,
    this.project,
    this.filter = IssueFilter.all,
    this.all = const [],
    this.visible = const [],
    this.userNames = const {},
  });

  final String today;
  final IssueListLoad load;
  final ProjectRow? project;
  final IssueFilter filter;

  /// Every issue on the project, sorted.
  final List<SyncedRecord<IssueRow>> all;

  /// [all] narrowed to [filter].
  final List<SyncedRecord<IssueRow>> visible;
  final Map<int, String> userNames;

  int countFor(IssueFilter filter) {
    final status = filter.status;
    if (status == null) return all.length;
    return all.where((r) => r.record.status == status).length;
  }

  IssueListState copyWith({
    IssueListLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    IssueFilter? filter,
    List<SyncedRecord<IssueRow>>? all,
    List<SyncedRecord<IssueRow>>? visible,
    Map<int, String>? userNames,
  }) {
    return IssueListState(
      today: today,
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      filter: filter ?? this.filter,
      all: all ?? this.all,
      visible: visible ?? this.visible,
      userNames: userNames ?? this.userNames,
    );
  }
}

/// The issue list: open work first, a client-side status filter, and names for
/// assignees. Works entirely from local data.
class IssueListCubit extends Cubit<IssueListState> {
  IssueListCubit(this._services)
    : super(IssueListState(today: _services.context.today)) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
    _usersSub = _services.projects.watchUsers().listen(_onUsers);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<SyncedRecord<IssueRow>>>? _issuesSub;
  StreamSubscription<List<UserRow>>? _usersSub;

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _issuesSub?.cancel();
      _issuesSub = null;
      emit(state.copyWith(clearProject: true, load: IssueListLoad.noProject));
      return;
    }
    if (state.project?.id == project.id && _issuesSub != null) return;
    emit(state.copyWith(project: project));
    _issuesSub?.cancel();
    _issuesSub = _services.issues
        .watchByProject(project.id)
        .listen(_onIssues);
  }

  void _onIssues(List<SyncedRecord<IssueRow>> rows) {
    final sorted = rows.toList()
      ..sort((a, b) => compareIssues(a.record, b.record, state.today));
    emit(
      state.copyWith(
        load: IssueListLoad.ready,
        all: sorted,
        visible: _narrow(sorted, state.filter),
      ),
    );
  }

  void _onUsers(List<UserRow> users) {
    emit(state.copyWith(userNames: {for (final u in users) u.id: u.name}));
  }

  List<SyncedRecord<IssueRow>> _narrow(
    List<SyncedRecord<IssueRow>> all,
    IssueFilter filter,
  ) {
    final status = filter.status;
    if (status == null) return all;
    return all.where((r) => r.record.status == status).toList();
  }

  void setFilter(IssueFilter filter) {
    emit(state.copyWith(filter: filter, visible: _narrow(state.all, filter)));
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _issuesSub?.cancel();
    await _usersSub?.cancel();
    return super.close();
  }
}
