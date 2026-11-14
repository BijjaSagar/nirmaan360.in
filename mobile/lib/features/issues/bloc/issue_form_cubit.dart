import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow, UserRow;
import '../../../core/sync/clock.dart' show dateString;
import '../../../core/sync/failures.dart';
import '../../../core/sync/record_photos.dart' show RecordPhotos;

enum DueChoice { none, today, threeDays, week }

/// A problem the form shows inline. Null when there is none.
enum IssueFormProblem { titleRequired, saveFailed, noProject }

class IssueFormState {
  const IssueFormState({
    required this.today,
    this.priority = 'medium',
    this.assignedTo,
    this.due = DueChoice.none,
    this.users = const [],
    this.photos = const [],
    this.submitting = false,
    this.problem,
    this.savedUuid,
  });

  final String today;
  final String priority;
  final int? assignedTo;
  final DueChoice due;
  final List<UserRow> users;

  /// Paths of the photos taken for this issue. They are copied in on Save.
  final List<String> photos;
  final bool submitting;
  final IssueFormProblem? problem;

  /// Set once the issue is on the phone.
  final String? savedUuid;

  IssueFormState copyWith({
    String? priority,
    int? assignedTo,
    bool clearAssignee = false,
    DueChoice? due,
    List<UserRow>? users,
    List<String>? photos,
    bool? submitting,
    IssueFormProblem? problem,
    bool clearProblem = false,
    String? savedUuid,
  }) {
    return IssueFormState(
      today: today,
      priority: priority ?? this.priority,
      assignedTo: clearAssignee ? null : (assignedTo ?? this.assignedTo),
      due: due ?? this.due,
      users: users ?? this.users,
      photos: photos ?? this.photos,
      submitting: submitting ?? this.submitting,
      problem: clearProblem ? null : (problem ?? this.problem),
      savedUuid: savedUuid ?? this.savedUuid,
    );
  }
}

/// Raises an issue in as few taps as possible. Only the title is required.
class IssueFormCubit extends Cubit<IssueFormState> {
  IssueFormCubit(this._services)
    : super(IssueFormState(today: _services.context.today)) {
    _usersSub = _services.projects.watchUsers().listen(
      (users) => emit(state.copyWith(users: users)),
    );
    _projectSub = _services.projects.watchCurrentProject().listen((project) {
      _project = project;
      if (!_projectKnown.isCompleted) _projectKnown.complete();
    });
  }

  final AppServices _services;
  StreamSubscription<List<UserRow>>? _usersSub;
  StreamSubscription<ProjectRow?>? _projectSub;
  ProjectRow? _project;

  /// Completes on the first project event, so a very quick Save waits for the
  /// real answer instead of reporting that no project is chosen.
  final Completer<void> _projectKnown = Completer<void>();

  void setPriority(String priority) =>
      emit(state.copyWith(priority: priority, clearProblem: true));

  void setAssignee(int? userId) => emit(
    userId == null
        ? state.copyWith(clearAssignee: true)
        : state.copyWith(assignedTo: userId),
  );

  void setDue(DueChoice due) => emit(state.copyWith(due: due));

  void addPhoto(String path) {
    if (state.photos.length >= RecordPhotos.maxPerKind || state.photos.contains(path)) return;
    emit(state.copyWith(photos: [...state.photos, path]));
  }

  void removePhoto(String path) => emit(state.copyWith(photos: [for (final p in state.photos) if (p != path) p]));

  void clearTitleProblem() {
    if (state.problem == IssueFormProblem.titleRequired) {
      emit(state.copyWith(clearProblem: true));
    }
  }

  String? _dueDate() {
    final int days = switch (state.due) {
      DueChoice.none => -1,
      DueChoice.today => 0,
      DueChoice.threeDays => 3,
      DueChoice.week => 7,
    };
    if (days < 0) return null;
    return dateString(DateTime.parse(state.today).add(Duration(days: days)));
  }

  /// Validates and saves locally. Returns true when the issue is on the phone.
  Future<bool> submit({required String title, required String location}) async {
    if (state.submitting) return false;
    final String cleanTitle = title.trim();
    if (cleanTitle.isEmpty) {
      emit(state.copyWith(problem: IssueFormProblem.titleRequired));
      return false;
    }

    await _projectKnown.future;
    if (isClosed) return false;
    final project = _project;
    if (project == null) {
      emit(state.copyWith(problem: IssueFormProblem.noProject));
      return false;
    }

    final String cleanLocation = location.trim();
    emit(state.copyWith(submitting: true, clearProblem: true));
    try {
      final String uuid = await _services.issues.raise(
        projectId: project.id,
        title: cleanTitle,
        priority: state.priority,
        locationNote: cleanLocation.isEmpty ? null : cleanLocation,
        assignedTo: state.assignedTo,
        dueDate: _dueDate(),
        photoPaths: state.photos,
      );
      emit(state.copyWith(submitting: false, savedUuid: uuid));
      return true;
    } on ValidationFailure {
      emit(state.copyWith(submitting: false, problem: IssueFormProblem.saveFailed));
    } on RecordLocked {
      emit(state.copyWith(submitting: false, problem: IssueFormProblem.saveFailed));
    } on ApiFailure {
      emit(state.copyWith(submitting: false, problem: IssueFormProblem.saveFailed));
    }
    return false;
  }

  @override
  Future<void> close() async {
    await _usersSub?.cancel();
    await _projectSub?.cancel();
    return super.close();
  }
}
