import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow, UserRow;
import '../../../core/money/money.dart';
import '../../../core/sync/failures.dart';

enum WalletFormProblem { nameRequired, offline, failed }

class WalletFormState {
  const WalletFormState({
    required this.today,
    this.online = true,
    this.project,
    this.users = const [],
    this.holderId,
    this.step = 0,
    this.amountText = '',
    this.saving = false,
    this.problem,
    this.serverError,
    this.done = false,
    this.openingAmount,
    this.openingSaved = false,
    this.savedWhileOffline = false,
  });

  final String today;
  final bool online;
  final ProjectRow? project;
  final List<UserRow> users;
  final int? holderId;

  /// 0 for name and holder, 1 for the opening amount.
  final int step;
  final String amountText;
  final bool saving;
  final WalletFormProblem? problem;

  /// A message from the server, shown as it came.
  final String? serverError;
  final bool done;

  /// The opening amount asked for, once the wallet exists.
  final Money? openingAmount;

  /// Whether that opening amount was stored on the phone.
  final bool openingSaved;
  final bool savedWhileOffline;

  Money? get amount => Money.tryParse(amountText);

  String? get holderName {
    for (final UserRow user in users) {
      if (user.id == holderId) return user.name;
    }
    return null;
  }

  WalletFormState copyWith({
    bool? online,
    ProjectRow? project,
    List<UserRow>? users,
    int? holderId,
    int? step,
    String? amountText,
    bool? saving,
    WalletFormProblem? problem,
    String? serverError,
    bool clearProblems = false,
    bool? done,
    Money? openingAmount,
    bool? openingSaved,
    bool? savedWhileOffline,
  }) {
    return WalletFormState(
      today: today,
      online: online ?? this.online,
      project: project ?? this.project,
      users: users ?? this.users,
      holderId: holderId ?? this.holderId,
      step: step ?? this.step,
      amountText: amountText ?? this.amountText,
      saving: saving ?? this.saving,
      problem: clearProblems ? null : (problem ?? this.problem),
      serverError: clearProblems ? null : (serverError ?? this.serverError),
      done: done ?? this.done,
      openingAmount: openingAmount ?? this.openingAmount,
      openingSaved: openingSaved ?? this.openingSaved,
      savedWhileOffline: savedWhileOffline ?? this.savedWhileOffline,
    );
  }
}

/// Creates a wallet, which needs network, then records any opening amount as a
/// top-up, which does not.
class WalletFormCubit extends Cubit<WalletFormState> {
  WalletFormCubit(this._services)
    : super(
        WalletFormState(
          today: _services.context.today,
          online: _services.connectivity.isOnline,
          holderId: _services.session.user?.id,
        ),
      ) {
    _connectivitySub = _services.connectivity.changes.listen(
      (online) => emit(state.copyWith(online: online, clearProblems: true)),
    );
    _projectSub = _services.projects.watchCurrentProject().listen((project) {
      if (project != null) emit(state.copyWith(project: project));
    });
    _usersSub = _services.projects.watchUsers().listen(
      (users) => emit(state.copyWith(users: users)),
    );
  }

  final AppServices _services;
  StreamSubscription<bool>? _connectivitySub;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<UserRow>>? _usersSub;

  String _name = '';

  void setName(String name) {
    _name = name;
    if (state.problem != null || state.serverError != null) {
      emit(state.copyWith(clearProblems: true));
    }
  }

  void setHolder(int userId) => emit(state.copyWith(holderId: userId));

  void next() {
    if (_name.trim().isEmpty) {
      emit(state.copyWith(problem: WalletFormProblem.nameRequired));
      return;
    }
    emit(state.copyWith(step: 1, clearProblems: true));
  }

  void backToDetails() => emit(state.copyWith(step: 0, clearProblems: true));

  void setAmount(String text) => emit(state.copyWith(amountText: text, clearProblems: true));

  /// Creates the wallet, then adds [openingNote] as a top-up if an amount was
  /// entered. Returns true when the wallet exists.
  Future<bool> create({required String openingNote}) async {
    if (state.saving) return false;
    final project = state.project;
    if (project == null) return false;
    if (_name.trim().isEmpty) {
      emit(state.copyWith(step: 0, problem: WalletFormProblem.nameRequired));
      return false;
    }

    emit(state.copyWith(saving: true, clearProblems: true));
    final int walletId;
    try {
      final wallet = await _services.pettyCash.createWallet(
        projectId: project.id,
        name: _name.trim(),
        userId: state.holderId,
      );
      walletId = wallet.id;
    } on OfflineFailure {
      emit(state.copyWith(saving: false, online: false, problem: WalletFormProblem.offline));
      return false;
    } on ValidationFailure catch (failure) {
      final message = failure.errors.values.isNotEmpty && failure.errors.values.first.isNotEmpty
          ? failure.errors.values.first.first
          : failure.message;
      emit(state.copyWith(saving: false, step: 0, serverError: message));
      return false;
    } on ApiFailure {
      emit(state.copyWith(saving: false, problem: WalletFormProblem.failed));
      return false;
    }

    final Money? opening = state.amount;
    var openingSaved = false;
    if (opening != null && opening > Money.zero) {
      try {
        await _services.pettyCash.addTopUp(
          walletId: walletId,
          amount: opening,
          entryDate: state.today,
          description: openingNote,
        );
        openingSaved = true;
      } on ApiFailure {
        openingSaved = false;
      } on RecordLocked {
        openingSaved = false;
      }
    }

    emit(
      state.copyWith(
        saving: false,
        done: true,
        openingAmount: opening != null && opening > Money.zero ? opening : null,
        openingSaved: openingSaved,
        savedWhileOffline: !_services.connectivity.isOnline,
      ),
    );
    return true;
  }

  @override
  Future<void> close() async {
    await _connectivitySub?.cancel();
    await _projectSub?.cancel();
    await _usersSub?.cancel();
    return super.close();
  }
}
