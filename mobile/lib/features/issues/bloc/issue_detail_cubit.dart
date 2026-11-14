import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show UserRow;
import '../../../core/sync/failures.dart';
import '../data/issue_repository.dart';

enum IssueDetailLoad { loading, ready, missing }

/// A problem to show once, in a toast.
enum IssueDetailProblem { failed, photoFailed }

class IssueDetailState {
  const IssueDetailState({
    required this.today,
    this.load = IssueDetailLoad.loading,
    this.issue,
    this.userNames = const {},
    this.photos = const [],
    this.canAddPhotos = false,
    this.savingStatus,
    this.problem,
    this.problemId = 0,
    this.savedStatus,
    this.savedId = 0,
    this.savedWhileOffline = false,
    this.photoSavedId = 0,
    this.photoSavedWhileOffline = false,
  });

  final String today;
  final IssueDetailLoad load;
  final SyncedRecord<IssueRow>? issue;
  final Map<int, String> userNames;

  /// The server's photos, then those waiting to be sent.
  final List<RecordPhoto> photos;
  final bool canAddPhotos;

  /// A status change being written.
  final String? savingStatus;
  final IssueDetailProblem? problem;
  final int problemId;

  /// The last status saved, raised with a fresh [savedId].
  final String? savedStatus;
  final int savedId;
  final bool savedWhileOffline;

  /// Raised with a fresh id each time a photo is kept on the phone.
  final int photoSavedId;
  final bool photoSavedWhileOffline;

  IssueDetailState copyWith({
    IssueDetailLoad? load,
    SyncedRecord<IssueRow>? issue,
    Map<int, String>? userNames,
    List<RecordPhoto>? photos,
    String? savingStatus,
    bool clearSaving = false,
    IssueDetailProblem? problem,
    int? problemId,
    String? savedStatus,
    int? savedId,
    bool? savedWhileOffline,
    int? photoSavedId,
    bool? photoSavedWhileOffline,
  }) {
    return IssueDetailState(
      today: today,
      load: load ?? this.load,
      issue: issue ?? this.issue,
      userNames: userNames ?? this.userNames,
      photos: photos ?? this.photos,
      canAddPhotos: canAddPhotos,
      savingStatus: clearSaving ? null : (savingStatus ?? this.savingStatus),
      problem: problem,
      problemId: problemId ?? this.problemId,
      savedStatus: savedStatus,
      savedId: savedId ?? this.savedId,
      savedWhileOffline: savedWhileOffline ?? this.savedWhileOffline,
      photoSavedId: photoSavedId ?? this.photoSavedId,
      photoSavedWhileOffline: photoSavedWhileOffline ?? this.photoSavedWhileOffline,
    );
  }
}

class IssueDetailCubit extends Cubit<IssueDetailState> {
  IssueDetailCubit(this._services, this.clientUuid)
    : super(
        IssueDetailState(
          today: _services.context.today,
          canAddPhotos: _services.auth.can('issue.manage'),
        ),
      ) {
    _issueSub = _services.issues.watchOne(clientUuid).listen(_onIssue);
    _photosSub = _services.issues.watchPhotos(clientUuid).listen(_onPhotos);
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
  StreamSubscription<List<RecordPhoto>>? _photosSub;

  /// The server's id for this issue the last time its photos were read.
  int? _photosReadFor;
  int _waitingPhotos = 0;

  void _onIssue(SyncedRecord<IssueRow>? issue) {
    if (issue == null) {
      emit(state.copyWith(load: IssueDetailLoad.missing));
      return;
    }
    emit(state.copyWith(load: IssueDetailLoad.ready, issue: issue));
    // The server's photos can be read once it knows the issue.
    final int? serverId = issue.record.serverId;
    if (serverId != null && serverId != _photosReadFor) {
      _photosReadFor = serverId;
      unawaited(refreshPhotos());
    }
  }

  void _onPhotos(List<RecordPhoto> photos) {
    if (isClosed) return;
    final int waiting = photos.where((photo) => photo.isWaiting).length;
    final bool someWereSent = waiting < _waitingPhotos;
    _waitingPhotos = waiting;
    emit(state.copyWith(photos: photos));
    if (someWereSent) unawaited(refreshPhotos());
  }

  /// Reads the server's photos again. Quiet when there is no network: what was
  /// read last stays on screen.
  Future<void> refreshPhotos() async {
    try {
      await _services.issues.refreshPhotos(clientUuid);
    } on ApiFailure {
      // Offline, or not allowed to see them. The last list stands.
    }
  }

  /// Keeps a photo on the phone and queues it. Works offline.
  Future<void> addPhoto(String kind, String path) async {
    try {
      await _services.issues.addPhotos(clientUuid, [path], kind: kind);
      if (isClosed) return;
      emit(
        state.copyWith(
          photoSavedId: state.photoSavedId + 1,
          photoSavedWhileOffline: !_services.connectivity.isOnline,
        ),
      );
    } on Object {
      if (isClosed) return;
      emit(
        state.copyWith(
          problem: IssueDetailProblem.photoFailed,
          problemId: state.problemId + 1,
        ),
      );
    }
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
    await _photosSub?.cancel();
    return super.close();
  }
}
