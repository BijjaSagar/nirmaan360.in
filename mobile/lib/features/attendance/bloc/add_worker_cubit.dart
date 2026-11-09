import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/failures.dart';

enum AddWorkerProblem { nameRequired, tradeRequired, offline, failed }

class AddWorkerState {
  const AddWorkerState({
    this.step = 0,
    this.online = true,
    this.name = '',
    this.trade,
    this.wageText = '',
    this.submitting = false,
    this.problem,
    this.done = false,
    this.addedName = '',
  });

  /// 0 for name and trade, 1 for the wage keypad.
  final int step;
  final bool online;
  final String name;
  final String? trade;

  /// What the wage keypad holds. Parsed through `Money`, never `double`.
  final String wageText;
  final bool submitting;
  final AddWorkerProblem? problem;
  final bool done;
  final String addedName;

  /// A wage was typed but the data layer has no way to send it yet.

  AddWorkerState copyWith({
    int? step,
    bool? online,
    String? name,
    String? trade,
    String? wageText,
    bool? submitting,
    AddWorkerProblem? problem,
    bool clearProblem = false,
    bool? done,
    String? addedName,
  }) {
    return AddWorkerState(
      step: step ?? this.step,
      online: online ?? this.online,
      name: name ?? this.name,
      trade: trade ?? this.trade,
      wageText: wageText ?? this.wageText,
      submitting: submitting ?? this.submitting,
      problem: clearProblem ? null : (problem ?? this.problem),
      done: done ?? this.done,
      addedName: addedName ?? this.addedName,
    );
  }
}

/// Add worker is online only, by design.
class AddWorkerCubit extends Cubit<AddWorkerState> {
  AddWorkerCubit(this._services)
    : super(AddWorkerState(online: _services.connectivity.isOnline)) {
    _connectivitySub = _services.connectivity.changes.listen(
      (online) => emit(state.copyWith(online: online, clearProblem: true)),
    );
  }

  final AppServices _services;
  StreamSubscription<bool>? _connectivitySub;

  bool get seesWage => _services.auth.can('money.view');

  void setName(String name) =>
      emit(state.copyWith(name: name, clearProblem: true));

  void setTrade(String trade) =>
      emit(state.copyWith(trade: trade, clearProblem: true));

  void setWage(String wageText) =>
      emit(state.copyWith(wageText: wageText, clearProblem: true));

  void backToDetails() => emit(state.copyWith(step: 0, clearProblem: true));

  /// Validates name and trade, then moves to the wage keypad.
  void next() {
    if (state.name.trim().isEmpty) {
      emit(state.copyWith(problem: AddWorkerProblem.nameRequired));
      return;
    }
    if (state.trade == null) {
      emit(state.copyWith(problem: AddWorkerProblem.tradeRequired));
      return;
    }
    // The server drops a wage from someone who may not see money, so asking
    // for one would only waste their time.
    if (!seesWage) {
      unawaited(save());
      return;
    }
    emit(state.copyWith(step: 1, clearProblem: true));
  }

  Future<void> save() async {
    if (state.submitting) return;
    final String name = state.name.trim();
    final String? trade = state.trade;
    if (name.isEmpty || trade == null) return;
    if (!_services.connectivity.isOnline) {
      emit(state.copyWith(online: false, problem: AddWorkerProblem.offline));
      return;
    }

    emit(state.copyWith(submitting: true, clearProblem: true));
    try {
      await _services.attendance.addWorker(
        name: name,
        trade: trade,
        dailyWage: seesWage ? Money.tryParse(state.wageText) : null,
      );
      emit(state.copyWith(submitting: false, done: true, addedName: name));
    } on OfflineFailure {
      emit(
        state.copyWith(
          submitting: false,
          online: false,
          problem: AddWorkerProblem.offline,
        ),
      );
    } on ValidationFailure {
      emit(state.copyWith(submitting: false, problem: AddWorkerProblem.failed));
    } on RecordLocked {
      emit(state.copyWith(submitting: false, problem: AddWorkerProblem.failed));
    } on ApiFailure {
      emit(state.copyWith(submitting: false, problem: AddWorkerProblem.failed));
    }
  }

  @override
  Future<void> close() async {
    await _connectivitySub?.cancel();
    return super.close();
  }
}
