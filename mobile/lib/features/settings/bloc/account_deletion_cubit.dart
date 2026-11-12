import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/api/nirmaan_api.dart';
import '../../../core/app_services.dart';
import '../../../core/sync/failures.dart';

/// How far reading what a deletion would do got.
enum DeletionLoad { loading, loaded, offline, server }

/// Why the deletion did not go through, in terms a person can be told.
enum DeletionProblem { passwordMissing, wrongPassword, nameMissing, wrongName, tooManyTries, offline, server }

/// Something to say once, as a toast.
enum DeletionNotice { cancelled, changed }

class AccountDeletionState extends Equatable {
  const AccountDeletionState({
    this.load = DeletionLoad.loading,
    this.preview,
    this.unsent = 0,
    this.localOnly = false,
    this.submitting = false,
    this.cancelling = false,
    this.problem,
    this.notice,
    this.noticeTick = 0,
  });

  final DeletionLoad load;
  final AccountDeletionPreview? preview;

  /// Work on this phone that deleting would lose: unsent entries, or on the
  /// offline plan every record, because it exists nowhere else.
  final int unsent;
  final bool localOnly;
  final bool submitting;
  final bool cancelling;
  final DeletionProblem? problem;
  final DeletionNotice? notice;

  /// Bumped with every [notice], so the same one can be said twice.
  final int noticeTick;

  AccountDeletionState copyWith({
    DeletionLoad? load,
    AccountDeletionPreview? preview,
    int? unsent,
    bool? localOnly,
    bool? submitting,
    bool? cancelling,
    DeletionProblem? problem,
    bool clearProblem = false,
    DeletionNotice? notice,
  }) {
    return AccountDeletionState(
      load: load ?? this.load,
      preview: preview ?? this.preview,
      unsent: unsent ?? this.unsent,
      localOnly: localOnly ?? this.localOnly,
      submitting: submitting ?? this.submitting,
      cancelling: cancelling ?? this.cancelling,
      problem: clearProblem ? null : (problem ?? this.problem),
      notice: notice ?? this.notice,
      noticeTick: notice == null ? noticeTick : noticeTick + 1,
    );
  }

  @override
  List<Object?> get props =>
      <Object?>[load, preview, unsent, localOnly, submitting, cancelling, problem, notice, noticeTick];
}

class AccountDeletionCubit extends Cubit<AccountDeletionState> {
  AccountDeletionCubit({required this._services}) : super(const AccountDeletionState());

  final AppServices _services;

  Future<void> load() async {
    emit(state.copyWith(load: DeletionLoad.loading, clearProblem: true));
    final DeletionLoad outcome;
    AccountDeletionPreview? preview;
    try {
      preview = await _services.auth.deletionPreview();
      outcome = DeletionLoad.loaded;
    } on OfflineFailure {
      if (!isClosed) emit(state.copyWith(load: DeletionLoad.offline));
      return;
    } on Exception {
      if (!isClosed) emit(state.copyWith(load: DeletionLoad.server));
      return;
    }
    final bool localOnly = _services.offlinePlan.isLocalOnly;
    final int unsent = localOnly ? await _services.auth.localRecordCount() : await _services.auth.unsentWorkCount();
    if (!isClosed) emit(state.copyWith(load: outcome, preview: preview, unsent: unsent, localOnly: localOnly));
  }

  /// Deletes the account. On success the session ends and the app leaves this
  /// screen for sign-in by itself.
  Future<void> submit({required String password, required String confirmName}) async {
    final AccountDeletionPreview? preview = state.preview;
    if (preview == null || state.submitting) return;
    final bool company = preview.mode == AccountDeletionMode.company;
    final String name = confirmName.trim();
    if (password.isEmpty) {
      emit(state.copyWith(problem: DeletionProblem.passwordMissing));
      return;
    }
    if (company && name.isEmpty) {
      emit(state.copyWith(problem: DeletionProblem.nameMissing));
      return;
    }

    emit(state.copyWith(submitting: true, clearProblem: true));
    try {
      await _services.auth.deleteAccount(
        mode: preview.mode,
        password: password,
        confirmName: company ? name : null,
        // Only once the screen has shown how much would be lost.
        discardUnsent: state.unsent > 0,
      );
      if (!isClosed) emit(state.copyWith(submitting: false));
    } on UnsentWorkFailure catch (failure) {
      // Something was queued after the screen counted: show the new count first.
      if (!isClosed) emit(state.copyWith(submitting: false, unsent: failure.count));
    } on ValidationFailure catch (failure) {
      final Map<String, List<String>> errors = failure.errors;
      if (errors.containsKey('password')) {
        _fail(DeletionProblem.wrongPassword);
      } else if (errors.containsKey('confirm_name')) {
        _fail(DeletionProblem.wrongName);
      } else if (errors.containsKey('mode')) {
        await _changed();
      } else {
        _fail(DeletionProblem.server);
      }
    } on ServerFailure catch (failure) {
      if (failure.code == 'deletion_mode_changed' || failure.statusCode == 409) {
        await _changed();
      } else {
        _fail(DeletionProblem.server);
      }
    } on RateLimited {
      _fail(DeletionProblem.tooManyTries);
    } on OfflineFailure {
      _fail(DeletionProblem.offline);
    } on Exception {
      _fail(DeletionProblem.server);
    }
  }

  /// Calls off the company's pending deletion, then reads again what deleting would do.
  Future<void> cancelPending() async {
    if (state.cancelling) return;
    emit(state.copyWith(cancelling: true, clearProblem: true));
    try {
      await _services.auth.cancelCompanyDeletion();
    } on OfflineFailure {
      if (!isClosed) emit(state.copyWith(cancelling: false, problem: DeletionProblem.offline));
      return;
    } on RateLimited {
      if (!isClosed) emit(state.copyWith(cancelling: false, problem: DeletionProblem.tooManyTries));
      return;
    } on Exception {
      if (!isClosed) emit(state.copyWith(cancelling: false, problem: DeletionProblem.server));
      return;
    }
    if (isClosed) return;
    emit(state.copyWith(cancelling: false, notice: DeletionNotice.cancelled));
    await load();
  }

  void _fail(DeletionProblem problem) {
    if (!isClosed) emit(state.copyWith(submitting: false, problem: problem));
  }

  /// The company changed under this screen, such as another owner leaving.
  /// Say so, and show what deleting would do now.
  Future<void> _changed() async {
    if (isClosed) return;
    emit(state.copyWith(submitting: false, notice: DeletionNotice.changed));
    await load();
  }
}
