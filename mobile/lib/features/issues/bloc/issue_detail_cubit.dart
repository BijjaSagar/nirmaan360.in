import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show UserRow;
import '../../../core/sync/failures.dart';
import '../data/issue_repository.dart';

enum IssueDetailLoad { loading, ready, missing }

/// A problem to show once, in a toast.
enum IssueDetailProblem { failed }

class IssueDetailState {
  const IssueDetailState({
    required this.today,
    this.load = IssueDetailLoad.loading,
    this.issue,
    this.userNames = const {},
    this.savingStatus,
    this.problem,
    this.problemId = 0,
    this.savedStatus,
    this.savedId = 0,
    this.savedWhileOffline = false,
  });

  final String today;
  final IssueDetailLoad load;
  final SyncedRecord<IssueRow>? issue;
  final Map<int, String> userNames;

  /// A status change being written.
  final String? savingStatus;
  final IssueDetailProblem? problem;
  final int problemId;

  /// The last status saved, raised with a fresh [savedId].
  final String? savedStatus;
  final int savedId;
  final bool savedWhileOffline;

  IssueDetailState copyWith({
    IssueDetailLoad? load,
    SyncedRecord<IssueRow>? issue,
    Map<int, String>? userNames,
    String? savingStatus,
    bool clearSaving = false,
    IssueDetailProblem? problem,
    int? problemId,
    String? savedStatus,
    int? savedId,
    bool? savedWhileOffline,
  }) {
    return IssueDetailState(
      today: today,
      load: load ?? this.load,
      issue: issue ?? this.issue,
      userNames: userNames ?? this.userNames,
      savingStatus: clearSaving ? null : (savingStatus ?? this.savingStatus),
      problem: problem,
      problemId: problemId ?? this.problemId,
      savedStatus: savedStatus,
      savedId: savedId ?? this.savedId,
      savedWhileOffline: savedWhileOffline ?? this.savedWhileOffline,
    );
  }
}

class IssueDetailCubit extends Cubit<IssueDetailState> {
  IssueDetailCubit(this._services, this.clientUuid)
    : super(IssueDetailState(today: _services.context.today)) {
    _issueSub = _services.issues.watchOne(clientUuid).listen(_onIssue);
    _usersSub = _services.projects.watchUsers().listen(
      (users) => emit(
        state.copyWith(userNames: {for (final u in users) u.id: u.name}),
      ),
    );
  }

  final AppServices _services;
  final String clientUuid;
  StreamSubscription<SyncedRecord<IssueRow>?>? _issueSub;
  StreamSubscription<List<UserRow>>? _usersSub;

  void _onIssue(SyncedRecord<IssueRow>? issue) {
    if (issue == null) {
      emit(state.copyWith(load: IssueDetailLoad.missing));
      return;
    }
    emit(state.copyWith(load: IssueDetailLoad.ready, issue: issue));
  }

  /// Writes the status locally and queues it. Works offline.
  Future<void> changeStatus(String status) async {
    if (state.savingStatus != null) return;
    emit(state.copyWith(savingStatus: status));
    try {
      await _services.issues.changeStatus(clientUuid, status);
      emit(
        state.copyWith(
          clearSaving: true,
          savedStatus: status,
          savedId: state.savedId + 1,
          savedWhileOffline: !_services.connectivity.isOnline,
        ),
      );
    } on RecordLocked {
      _fail();
    } on ValidationFailure {
      _fail();
    } on ApiFailure {
      _fail();
    }
  }

  void _fail() {
    emit(
      state.copyWith(
        clearSaving: true,
        problem: IssueDetailProblem.failed,
        problemId: state.problemId + 1,
      ),
    );
  }

  @override
  Future<void> close() async {
    await _issueSub?.cancel();
    await _usersSub?.cancel();
    return super.close();
  }
}
