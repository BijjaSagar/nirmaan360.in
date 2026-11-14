import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../data/tender_repository.dart';

enum QuoteFormLoad { loading, ready, offline, failed, closed }

enum QuoteProblem { contractorRequired, rateInvalid, amountInvalid, daysInvalid, validBeforeReceived, saveFailed }

/// What was typed, read once on save.
class QuoteText {
  const QuoteText({
    this.rates = const <int, String>{},
    this.amount = '',
    this.days = '',
    this.paymentTerms = '',
    this.remarks = '',
  });

  /// Typed rate by tender line id.
  final Map<int, String> rates;
  final String amount;
  final String days;
  final String paymentTerms;
  final String remarks;
}

class QuoteFormState {
  const QuoteFormState({
    required this.today,
    required this.receivedOn,
    this.load = QuoteFormLoad.loading,
    this.line,
    this.tender,
    this.contractors = const <Subcontractor>[],
    this.contractorsFailed = false,
    this.contractor,
    this.validUntil,
    this.includesMaterial = false,
    this.quotation,
    this.submitting = false,
    this.problem,
    this.badRates = const <int>{},
    this.message,
  });

  final String today;
  final QuoteFormLoad load;
  final LineTenders? line;
  final LineTender? tender;
  final List<Subcontractor> contractors;
  final bool contractorsFailed;
  final Subcontractor? contractor;
  final String receivedOn;
  final String? validUntil;
  final bool includesMaterial;
  final String? quotation;
  final bool submitting;
  final QuoteProblem? problem;

  /// Tender lines whose rate was not a rate.
  final Set<int> badRates;

  /// The server's words for a refusal.
  final String? message;

  /// A line with no quantity cannot be priced by rate, so the total is asked instead.
  bool get byAmount {
    final LineTender? tender = this.tender;
    if (tender == null) return false;
    if (tender.lines.isEmpty) return true;
    return tender.lines.every((TenderLine row) => row.qty.isZero);
  }

  QuoteFormState copyWith({
    QuoteFormLoad? load,
    LineTenders? line,
    LineTender? tender,
    List<Subcontractor>? contractors,
    bool? contractorsFailed,
    Subcontractor? Function()? contractor,
    String? receivedOn,
    String? Function()? validUntil,
    bool? includesMaterial,
    String? Function()? quotation,
    bool? submitting,
    QuoteProblem? Function()? problem,
    Set<int>? badRates,
    String? Function()? message,
  }) {
    return QuoteFormState(
      today: today,
      load: load ?? this.load,
      line: line ?? this.line,
      tender: tender ?? this.tender,
      contractors: contractors ?? this.contractors,
      contractorsFailed: contractorsFailed ?? this.contractorsFailed,
      contractor: contractor != null ? contractor() : this.contractor,
      receivedOn: receivedOn ?? this.receivedOn,
      validUntil: validUntil != null ? validUntil() : this.validUntil,
      includesMaterial: includesMaterial ?? this.includesMaterial,
      quotation: quotation != null ? quotation() : this.quotation,
      submitting: submitting ?? this.submitting,
      problem: problem != null ? problem() : this.problem,
      badRates: badRates ?? this.badRates,
      message: message != null ? message() : this.message,
    );
  }
}

/// Enters one contractor's quote for a tender.
class QuoteFormCubit extends Cubit<QuoteFormState> {
  QuoteFormCubit(AppServices services, {required this.boqItemId, required this.tenderId})
    : repository = TenderRepository(services.context),
      super(QuoteFormState(today: services.context.today, receivedOn: services.context.today)) {
    load();
  }

  final int boqItemId;
  final int tenderId;
  final TenderRepository repository;

  Future<void> load() async {
    emit(state.copyWith(load: QuoteFormLoad.loading));
    try {
      final LineTenders line = await repository.line(boqItemId);
      final LineTender? tender = line.tender(tenderId);
      if (isClosed) return;
      if (tender == null || !tender.isOpen || !line.canQuote) {
        emit(state.copyWith(load: QuoteFormLoad.closed, line: line));
        return;
      }
      emit(state.copyWith(load: QuoteFormLoad.ready, line: line, tender: tender));
    } on OfflineFailure {
      if (!isClosed) emit(state.copyWith(load: QuoteFormLoad.offline));
      return;
    } on ApiFailure {
      if (!isClosed) emit(state.copyWith(load: QuoteFormLoad.failed));
      return;
    }
    await loadContractors();
  }

  Future<void> loadContractors() async {
    try {
      final List<Subcontractor> rows = await repository.subcontractors();
      if (!isClosed) emit(state.copyWith(contractors: rows, contractorsFailed: false));
    } on ApiFailure {
      if (!isClosed) emit(state.copyWith(contractorsFailed: true));
    }
  }

  void choose(int partyId) {
    for (final Subcontractor row in state.contractors) {
      if (row.id == partyId) {
        emit(state.copyWith(contractor: () => row, problem: () => null));
        return;
      }
    }
  }

  /// A contractor added from the form joins the list and is chosen.
  void added(Subcontractor contractor) {
    emit(
      state.copyWith(
        contractors: <Subcontractor>[contractor, ...state.contractors.where((Subcontractor row) => row.id != contractor.id)],
        contractor: () => contractor,
        problem: () => null,
      ),
    );
  }

  void setReceived(String date) {
    final String? valid = state.validUntil;
    emit(
      state.copyWith(
        receivedOn: date,
        // A validity before the new received day would be refused, so it goes.
        validUntil: valid != null && valid.compareTo(date) < 0 ? () => null : null,
        problem: () => null,
      ),
    );
  }

  void setValidUntil(String? date) => emit(state.copyWith(validUntil: () => date, problem: () => null));

  void setMaterial(bool included) => emit(state.copyWith(includesMaterial: included));

  void setQuotation(String? path) => emit(state.copyWith(quotation: () => path));

  void clearProblem() {
    if (state.problem != null) emit(state.copyWith(problem: () => null, badRates: const <int>{}, message: () => null));
  }

  /// The rate typed for a line, when it is one: above zero and two decimals at most.
  static Money? rateOf(String text) {
    if (Money.hasExtraDecimals(text)) return null;
    final Money? rate = Money.tryParse(text);
    return rate == null || rate.isNegative || rate.isZero ? null : rate;
  }

  /// Returns true once the server has the quote.
  Future<bool> submit(QuoteText text) async {
    final LineTender? tender = state.tender;
    if (tender == null || state.submitting) return false;
    final Subcontractor? contractor = state.contractor;
    if (contractor == null) {
      emit(state.copyWith(problem: () => QuoteProblem.contractorRequired));
      return false;
    }

    final Map<int, Money> rates = <int, Money>{};
    Money? amount;
    if (state.byAmount) {
      amount = rateOf(text.amount);
      if (amount == null) {
        emit(state.copyWith(problem: () => QuoteProblem.amountInvalid));
        return false;
      }
    } else {
      final Set<int> bad = <int>{};
      for (final TenderLine row in tender.lines) {
        final Money? rate = rateOf(text.rates[row.id] ?? '');
        if (rate == null) {
          bad.add(row.id);
        } else {
          rates[row.id] = rate;
        }
      }
      if (bad.isNotEmpty) {
        emit(state.copyWith(problem: () => QuoteProblem.rateInvalid, badRates: bad));
        return false;
      }
    }

    int? days;
    if (text.days.trim().isNotEmpty) {
      days = int.tryParse(text.days.trim());
      if (days == null || days < 1 || days > 3650) {
        emit(state.copyWith(problem: () => QuoteProblem.daysInvalid));
        return false;
      }
    }
    final String? valid = state.validUntil;
    if (valid != null && valid.compareTo(state.receivedOn) < 0) {
      emit(state.copyWith(problem: () => QuoteProblem.validBeforeReceived));
      return false;
    }

    String? optional(String value) {
      final String trimmed = value.trim();
      if (trimmed.isEmpty) return null;
      return trimmed.length > 500 ? trimmed.substring(0, 500) : trimmed;
    }

    emit(state.copyWith(submitting: true, problem: () => null, message: () => null, badRates: const <int>{}));
    try {
      await repository.addQuote(
        tenderId,
        QuoteInput(
          partyId: contractor.id,
          rates: rates,
          quotedAmount: amount,
          receivedOn: state.receivedOn,
          validUntil: valid,
          includesMaterial: state.includesMaterial,
          completionDays: days,
          paymentTerms: optional(text.paymentTerms),
          remarks: optional(text.remarks),
          quotationPath: state.quotation,
        ),
      );
      if (!isClosed) emit(state.copyWith(submitting: false));
      return true;
    } on ValidationFailure catch (e) {
      if (!isClosed) {
        emit(
          state.copyWith(
            submitting: false,
            problem: () => QuoteProblem.saveFailed,
            message: () => e.errors.values.expand((List<String> list) => list).firstOrNull ?? e.message,
          ),
        );
      }
    } on ApiFailure catch (e) {
      if (!isClosed) {
        emit(
          state.copyWith(
            submitting: false,
            problem: () => QuoteProblem.saveFailed,
            message: () => e is ServerFailure && e.message.trim().isNotEmpty ? e.message : null,
          ),
        );
      }
    }
    return false;
  }
}
