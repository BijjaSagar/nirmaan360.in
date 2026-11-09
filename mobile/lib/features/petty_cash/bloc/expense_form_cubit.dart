import 'dart:async';

import 'package:decimal/decimal.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart' show dateString;
import '../../../core/sync/failures.dart';
import '../data/petty_cash_repository.dart';
import 'petty_cash_cubit.dart' show pettyCashWalletMetaKey;

enum ExpenseProblem { amountRequired, overBalance, categoryRequired, saveFailed, billFailed }

/// Formats what was typed on the keypad with Indian digit grouping, keeping a
/// trailing point or paise exactly as entered. Never goes through `double`.
String formatTypedAmount(String typed) {
  if (typed.isEmpty) return '0';
  final int point = typed.indexOf('.');
  final String whole = point == -1 ? typed : typed.substring(0, point);
  final String grouped = indianGroup(Decimal.parse(whole.isEmpty ? '0' : whole), 0);
  return point == -1 ? grouped : '$grouped${typed.substring(point)}';
}

class ExpenseFormState {
  const ExpenseFormState({
    required this.today,
    required this.yesterday,
    this.step = 0,
    this.amountText = '',
    this.wallets = const [],
    this.walletId,
    this.category,
    this.billPath,
    this.entryDate,
    this.submitting = false,
    this.problem,
    this.saved = false,
    this.savedWhileOffline = false,
  });

  final String today;
  final String yesterday;

  /// 0 for the amount keypad, 1 for category, bill and the rest.
  final int step;
  final String amountText;
  final List<WalletLine> wallets;
  final int? walletId;
  final String? category;
  final String? billPath;
  final String? entryDate;
  final bool submitting;
  final ExpenseProblem? problem;
  final bool saved;
  final bool savedWhileOffline;

  Money? get amount => Money.tryParse(amountText);

  WalletLine? get wallet {
    for (final line in wallets) {
      if (line.wallet.id == walletId) return line;
    }
    return null;
  }

  /// The balance this expense must fit in: the server balance less anything on
  /// the phone it does not know about yet.
  Money? get available => wallet?.previewBalance;

  bool get isOverBalance {
    final Money? typed = amount;
    final Money? limit = available;
    return typed != null && limit != null && typed > limit;
  }

  Money? get balanceAfter {
    final Money? typed = amount;
    final Money? limit = available;
    if (typed == null || limit == null || typed <= Money.zero || typed > limit) {
      return null;
    }
    return limit - typed;
  }

  bool get amountReady {
    final Money? typed = amount;
    return typed != null && typed > Money.zero && !isOverBalance && wallet != null;
  }

  ExpenseFormState copyWith({
    int? step,
    String? amountText,
    List<WalletLine>? wallets,
    int? walletId,
    String? category,
    String? billPath,
    String? entryDate,
    bool? submitting,
    ExpenseProblem? problem,
    bool clearProblem = false,
    bool? saved,
    bool? savedWhileOffline,
  }) {
    return ExpenseFormState(
      today: today,
      yesterday: yesterday,
      step: step ?? this.step,
      amountText: amountText ?? this.amountText,
      wallets: wallets ?? this.wallets,
      walletId: walletId ?? this.walletId,
      category: category ?? this.category,
      billPath: billPath ?? this.billPath,
      entryDate: entryDate ?? this.entryDate,
      submitting: submitting ?? this.submitting,
      problem: clearProblem ? null : (problem ?? this.problem),
      saved: saved ?? this.saved,
      savedWhileOffline: savedWhileOffline ?? this.savedWhileOffline,
    );
  }
}

/// Add expense in very few taps: the amount, what it was for, save.
///
/// An expense bigger than the wallet balance is stopped here with a plain
/// message; the server would reject it anyway.
class ExpenseFormCubit extends Cubit<ExpenseFormState> {
  ExpenseFormCubit(
    this._services, {
    Future<String?> Function()? captureBill,
  })  : _captureBill = captureBill ?? _services.photos.capture,
        super(
          ExpenseFormState(
            today: _services.context.today,
            yesterday: _yesterday(_services.context.today),
            entryDate: _services.context.today,
          ),
        ) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  static String _yesterday(String today) =>
      dateString(DateTime.parse(today).subtract(const Duration(days: 1)));

  final AppServices _services;
  final Future<String?> Function() _captureBill;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<WalletLine>>? _walletsSub;
  String _note = '';

  void _onProject(ProjectRow? project) {
    _walletsSub?.cancel();
    if (project == null) return;
    _walletsSub = _services.pettyCash
        .watchWallets(project.id)
        .listen(_onWallets);
  }

  Future<void> _onWallets(List<WalletLine> wallets) async {
    if (wallets.isEmpty) {
      emit(state.copyWith(wallets: const []));
      return;
    }
    final int walletId = await _pickWallet(wallets);
    if (isClosed) return;
    emit(state.copyWith(wallets: wallets, walletId: walletId));
  }

  Future<int> _pickWallet(List<WalletLine> wallets) async {
    bool exists(int? id) => id != null && wallets.any((w) => w.wallet.id == id);

    if (exists(state.walletId)) return state.walletId!;
    final remembered = int.tryParse(
      await _services.db.readMeta(pettyCashWalletMetaKey) ?? '',
    );
    if (exists(remembered)) return remembered!;
    final int? me = _services.session.user?.id;
    for (final line in wallets) {
      if (line.wallet.userId == me) return line.wallet.id;
    }
    return wallets.first.wallet.id;
  }

  void setAmount(String text) =>
      emit(state.copyWith(amountText: text, clearProblem: true));

  void setWallet(int walletId) =>
      emit(state.copyWith(walletId: walletId, clearProblem: true));

  /// Moves to the second step, or says why it cannot.
  void continueFromAmount() {
    final Money? amount = state.amount;
    if (amount == null || amount <= Money.zero) {
      emit(state.copyWith(problem: ExpenseProblem.amountRequired));
      return;
    }
    if (state.isOverBalance) {
      emit(state.copyWith(problem: ExpenseProblem.overBalance));
      return;
    }
    emit(state.copyWith(step: 1, clearProblem: true));
  }

  void backToAmount() => emit(state.copyWith(step: 0, clearProblem: true));

  void setCategory(String category) =>
      emit(state.copyWith(category: category, clearProblem: true));

  void setNote(String note) => _note = note;

  void setDate(String date) => emit(state.copyWith(entryDate: date));

  /// Opens the camera. A cancelled photo changes nothing.
  Future<void> takeBillPhoto() async {
    try {
      final String? path = await _captureBill();
      if (isClosed) return;
      emit(path == null ? state.copyWith(clearProblem: true) : state.copyWith(billPath: path, clearProblem: true));
    } on Exception {
      // The camera can be refused or unavailable. The bill is optional.
      if (!isClosed) emit(state.copyWith(problem: ExpenseProblem.billFailed));
    }
  }

  /// Saves on the phone and queues the entry and its bill. Returns true when
  /// the entry is safely stored.
  Future<bool> submit() async {
    if (state.submitting) return false;
    final Money? amount = state.amount;
    final WalletLine? wallet = state.wallet;
    if (amount == null || amount <= Money.zero || wallet == null) {
      emit(state.copyWith(step: 0, problem: ExpenseProblem.amountRequired));
      return false;
    }
    if (state.isOverBalance) {
      emit(state.copyWith(step: 0, problem: ExpenseProblem.overBalance));
      return false;
    }
    final String? category = state.category;
    if (category == null) {
      emit(state.copyWith(problem: ExpenseProblem.categoryRequired));
      return false;
    }

    emit(state.copyWith(submitting: true, clearProblem: true));
    try {
      await _services.pettyCash.addExpense(
        walletId: wallet.wallet.id,
        amount: amount,
        entryDate: state.entryDate ?? state.today,
        category: category,
        description: _note.trim().isEmpty ? null : _note.trim(),
        billSourcePath: state.billPath,
      );
      emit(
        state.copyWith(
          submitting: false,
          saved: true,
          savedWhileOffline: !_services.connectivity.isOnline,
        ),
      );
      return true;
    } on ValidationFailure {
      emit(state.copyWith(submitting: false, problem: ExpenseProblem.saveFailed));
    } on RecordLocked {
      emit(state.copyWith(submitting: false, problem: ExpenseProblem.saveFailed));
    } on ApiFailure {
      emit(state.copyWith(submitting: false, problem: ExpenseProblem.saveFailed));
    }
    return false;
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _walletsSub?.cancel();
    return super.close();
  }
}
