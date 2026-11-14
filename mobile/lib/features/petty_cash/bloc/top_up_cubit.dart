import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart' show dateString;
import '../../../core/sync/failures.dart';
import '../data/petty_cash_repository.dart';
import 'petty_cash_cubit.dart' show pickWalletId;

enum TopUpProblem { amountRequired, failed }

class TopUpState {
  const TopUpState({
    required this.today,
    required this.yesterday,
    required this.entryDate,
    this.step = 0,
    this.amountText = '',
    this.wallets = const [],
    this.walletId,
    this.saving = false,
    this.problem,
    this.saved = false,
    this.savedWhileOffline = false,
  });

  final String today;
  final String yesterday;
  final String entryDate;

  /// 0 for the amount keypad, 1 for note and date.
  final int step;
  final String amountText;
  final List<WalletLine> wallets;
  final int? walletId;
  final bool saving;
  final TopUpProblem? problem;
  final bool saved;
  final bool savedWhileOffline;

  Money? get amount => Money.tryParse(amountText);

  bool get amountReady {
    final Money? typed = amount;
    return typed != null && typed > Money.zero && wallet != null;
  }

  WalletLine? get wallet {
    for (final WalletLine line in wallets) {
      if (line.wallet.id == walletId) return line;
    }
    return null;
  }

  TopUpState copyWith({
    String? entryDate,
    int? step,
    String? amountText,
    List<WalletLine>? wallets,
    int? walletId,
    bool? saving,
    TopUpProblem? problem,
    bool clearProblem = false,
    bool? saved,
    bool? savedWhileOffline,
  }) {
    return TopUpState(
      today: today,
      yesterday: yesterday,
      entryDate: entryDate ?? this.entryDate,
      step: step ?? this.step,
      amountText: amountText ?? this.amountText,
      wallets: wallets ?? this.wallets,
      walletId: walletId ?? this.walletId,
      saving: saving ?? this.saving,
      problem: clearProblem ? null : (problem ?? this.problem),
      saved: saved ?? this.saved,
      savedWhileOffline: savedWhileOffline ?? this.savedWhileOffline,
    );
  }
}

/// Adds money to a wallet. Works offline: the entry waits on the phone.
class TopUpCubit extends Cubit<TopUpState> {
  TopUpCubit(this._services)
    : super(
        TopUpState(
          today: _services.context.today,
          yesterday: dateString(
            DateTime.parse(_services.context.today).subtract(const Duration(days: 1)),
          ),
          entryDate: _services.context.today,
        ),
      ) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<WalletLine>>? _walletsSub;
  String _note = '';

  void _onProject(ProjectRow? project) {
    _walletsSub?.cancel();
    if (project == null) return;
    _walletsSub = _services.pettyCash.watchWallets(project.id).listen(_onWallets);
  }

  Future<void> _onWallets(List<WalletLine> wallets) async {
    if (wallets.isEmpty) {
      emit(state.copyWith(wallets: const []));
      return;
    }
    final int walletId = await pickWalletId(_services, wallets, current: state.walletId);
    if (isClosed) return;
    emit(state.copyWith(wallets: wallets, walletId: walletId));
  }

  void setAmount(String text) => emit(state.copyWith(amountText: text, clearProblem: true));

  void setWallet(int walletId) => emit(state.copyWith(walletId: walletId));

  void setNote(String note) => _note = note;

  void setDate(String date) => emit(state.copyWith(entryDate: date));

  void next() {
    if (!state.amountReady) {
      emit(state.copyWith(problem: TopUpProblem.amountRequired));
      return;
    }
    emit(state.copyWith(step: 1, clearProblem: true));
  }

  void backToAmount() => emit(state.copyWith(step: 0, clearProblem: true));

  /// Saves on the phone and queues the entry.
  Future<bool> submit() async {
    if (state.saving) return false;
    final Money? amount = state.amount;
    final WalletLine? wallet = state.wallet;
    if (amount == null || amount <= Money.zero || wallet == null) {
      emit(state.copyWith(step: 0, problem: TopUpProblem.amountRequired));
      return false;
    }

    emit(state.copyWith(saving: true, clearProblem: true));
    try {
      final String note = _note.trim();
      await _services.pettyCash.addTopUp(
        walletId: wallet.wallet.id,
        amount: amount,
        entryDate: state.entryDate,
        description: note.isEmpty ? null : note,
      );
    } on ApiFailure {
      emit(state.copyWith(saving: false, problem: TopUpProblem.failed));
      return false;
    } on RecordLocked {
      emit(state.copyWith(saving: false, problem: TopUpProblem.failed));
      return false;
    }
    emit(
      state.copyWith(
        saving: false,
        saved: true,
        savedWhileOffline: !_services.connectivity.isOnline,
      ),
    );
    return true;
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _walletsSub?.cancel();
    return super.close();
  }
}
