import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart';
import '../../../core/sync/ids.dart';
import '../data/contractor_repository.dart';

enum PaymentFormProblem { contractRequired, contractClosed, amountInvalid, modeRequired, lineRequired, partRequired, saveFailed }

enum PaymentAgainst { whole, line, part }

/// The modes the server accepts, from `contractor_control.payment_modes`.
const List<String> kPaymentModes = <String>['cash', 'bank', 'upi'];

/// What the person typed. Kept out of the state so typing does not rebuild.
class PaymentFormText {
  const PaymentFormText({required this.amount, this.paidTo = '', this.reference = '', this.notes = ''});

  final String amount;
  final String paidTo;
  final String reference;
  final String notes;
}

class PaymentFormState extends Equatable {
  const PaymentFormState({
    required this.today,
    required this.yesterday,
    required this.date,
    this.ready = false,
    this.projectId,
    this.overview,
    this.workOrderId,
    this.mode,
    this.isAdvance = false,
    this.against = PaymentAgainst.whole,
    this.boqItemId,
    this.partId,
    this.bill,
    this.submitting = false,
    this.problem,
  });

  final String today;
  final String yesterday;
  final String date;
  final bool ready;
  final int? projectId;
  final ContractorOverview? overview;
  final int? workOrderId;
  final String? mode;
  final bool isAdvance;
  final PaymentAgainst against;
  final int? boqItemId;
  final int? partId;
  final String? bill;
  final bool submitting;
  final PaymentFormProblem? problem;

  WorkContract? get contract => workOrderId == null ? null : overview?.contract(workOrderId!);

  /// Null until this contract has been read once.
  ContractDetail? get detail => workOrderId == null ? null : overview?.details[workOrderId!];

  /// A closed contract takes no more, so it is not offered.
  List<WorkContract> get openContracts =>
      (overview?.contracts ?? const <WorkContract>[]).where((WorkContract row) => !row.isClosed).toList(growable: false);

  ContractLine? get line {
    for (final ContractLine row in detail?.lines ?? const <ContractLine>[]) {
      if (row.boqItemId == boqItemId) return row;
    }
    return null;
  }

  ContractPart? get part {
    for (final ContractPart row in detail?.parts ?? const <ContractPart>[]) {
      if (row.id == partId) return row;
    }
    return null;
  }

  PaymentFormState copyWith({
    String? date,
    bool? ready,
    int? projectId,
    ContractorOverview? overview,
    int? Function()? workOrderId,
    String? mode,
    bool? isAdvance,
    PaymentAgainst? against,
    int? Function()? boqItemId,
    int? Function()? partId,
    String? Function()? bill,
    bool? submitting,
    PaymentFormProblem? Function()? problem,
  }) {
    return PaymentFormState(
      today: today,
      yesterday: yesterday,
      date: date ?? this.date,
      ready: ready ?? this.ready,
      projectId: projectId ?? this.projectId,
      overview: overview ?? this.overview,
      workOrderId: workOrderId == null ? this.workOrderId : workOrderId(),
      mode: mode ?? this.mode,
      isAdvance: isAdvance ?? this.isAdvance,
      against: against ?? this.against,
      boqItemId: boqItemId == null ? this.boqItemId : boqItemId(),
      partId: partId == null ? this.partId : partId(),
      bill: bill == null ? this.bill : bill(),
      submitting: submitting ?? this.submitting,
      problem: problem == null ? this.problem : problem(),
    );
  }

  @override
  List<Object?> get props => <Object?>[
    date,
    ready,
    projectId,
    overview,
    workOrderId,
    mode,
    isAdvance,
    against,
    boqItemId,
    partId,
    bill,
    submitting,
    problem,
  ];
}

class PaymentFormCubit extends Cubit<PaymentFormState> {
  PaymentFormCubit(this._services, {int? workOrderId})
    : _requested = workOrderId,
      super(
        PaymentFormState(
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
          date: _services.context.today,
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final int? _requested;

  /// One request, one id, however many times this form tries to save it.
  final String _uuid = newUuid();

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(ready: true));
      return;
    }
    final ContractorOverview? overview = await _services.contractors.readOverview(projectId);
    if (isClosed) return;
    final int? requested = _requested;
    emit(
      state.copyWith(
        ready: true,
        projectId: projectId,
        overview: overview,
        workOrderId: () => requested != null && overview?.contract(requested) != null ? requested : null,
      ),
    );
  }

  void setContract(int id) => emit(
    state.copyWith(
      workOrderId: () => id,
      against: PaymentAgainst.whole,
      boqItemId: () => null,
      partId: () => null,
      problem: () => null,
    ),
  );

  void setMode(String mode) => emit(state.copyWith(mode: mode, problem: () => null));

  void setDate(String date) => emit(state.copyWith(date: date));

  void setAdvance(bool advance) => emit(state.copyWith(isAdvance: advance));

  /// A line and a part cannot both be named: the server refuses that.
  void setAgainst(PaymentAgainst against) =>
      emit(state.copyWith(against: against, boqItemId: () => null, partId: () => null, problem: () => null));

  void setLine(int id) => emit(state.copyWith(boqItemId: () => id, problem: () => null));

  void setPart(int id) => emit(state.copyWith(partId: () => id, problem: () => null));

  void setBill(String? path) => emit(state.copyWith(bill: () => path));

  void clearProblem() => emit(state.copyWith(problem: () => null));

  bool _fail(PaymentFormProblem problem) {
    emit(state.copyWith(problem: () => problem));
    return false;
  }

  static final Money _limit = Money.parse('9999999999');

  static String? _clean(String text) {
    final String trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  /// True when the request is safe on this phone.
  Future<bool> submit(PaymentFormText text) async {
    if (state.submitting) return false;
    final WorkContract? contract = state.contract;
    final int? projectId = state.projectId;
    if (contract == null || projectId == null) return _fail(PaymentFormProblem.contractRequired);
    if (contract.isClosed) return _fail(PaymentFormProblem.contractClosed);

    final Money? amount = Money.tryParse(text.amount);
    if (amount == null || amount <= Money.zero || amount > _limit || Money.hasExtraDecimals(text.amount)) {
      return _fail(PaymentFormProblem.amountInvalid);
    }
    final String? mode = state.mode;
    if (mode == null) return _fail(PaymentFormProblem.modeRequired);
    if (state.against == PaymentAgainst.line && state.boqItemId == null) return _fail(PaymentFormProblem.lineRequired);
    if (state.against == PaymentAgainst.part && state.partId == null) return _fail(PaymentFormProblem.partRequired);

    emit(state.copyWith(submitting: true, problem: () => null));
    try {
      await _services.contractors.requestPayment(
        uuid: _uuid,
        projectId: projectId,
        workOrderId: contract.workOrderId,
        amount: amount,
        paidOn: state.date,
        mode: mode,
        paidTo: _clean(text.paidTo),
        reference: _clean(text.reference),
        isAdvance: state.isAdvance,
        notes: _clean(text.notes),
        boqItemId: state.boqItemId,
        milestoneId: state.partId,
        billPath: state.bill,
        title: <String>[contract.number, ?contract.contractor].join(' · '),
        // Someone without money.view typed this figure, but it is not shown back.
        subtitle: _services.auth.can('money.view') ? amount.inr() : null,
      );
      return true;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => PaymentFormProblem.saveFailed));
      return false;
    }
  }
}
