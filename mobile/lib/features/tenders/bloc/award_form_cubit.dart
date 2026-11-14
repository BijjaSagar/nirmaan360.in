import 'package:decimal/decimal.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../data/tender_repository.dart';

enum AwardFormLoad { loading, ready, offline, failed, gone }

enum AwardField { retention, tds, gst, advance, recovery }

/// What was typed, read once on save.
class AwardText {
  const AwardText({
    required this.retention,
    required this.tds,
    required this.gst,
    required this.advance,
    required this.recovery,
    this.note = '',
  });

  final String retention;
  final String tds;
  final String gst;
  final String advance;
  final String recovery;
  final String note;
}

class AwardFormState {
  const AwardFormState({
    required this.today,
    this.load = AwardFormLoad.loading,
    this.line,
    this.tender,
    this.quote,
    this.startDate,
    this.endDate,
    this.basis = 'gross',
    this.order = 'retention,advance,material,tds',
    this.submitting = false,
    this.bad = const <AwardField>{},
    this.endBeforeStart = false,
    this.failed = false,
    this.message,
  });

  final String today;
  final AwardFormLoad load;
  final LineTenders? line;
  final LineTender? tender;
  final TenderQuote? quote;
  final String? startDate;
  final String? endDate;
  final String basis;
  final String order;
  final bool submitting;
  final Set<AwardField> bad;
  final bool endBeforeStart;
  final bool failed;
  final String? message;

  AwardFormState copyWith({
    AwardFormLoad? load,
    LineTenders? line,
    LineTender? tender,
    TenderQuote? quote,
    String? Function()? startDate,
    String? Function()? endDate,
    String? basis,
    String? order,
    bool? submitting,
    Set<AwardField>? bad,
    bool? endBeforeStart,
    bool? failed,
    String? Function()? message,
  }) {
    return AwardFormState(
      today: today,
      load: load ?? this.load,
      line: line ?? this.line,
      tender: tender ?? this.tender,
      quote: quote ?? this.quote,
      startDate: startDate != null ? startDate() : this.startDate,
      endDate: endDate != null ? endDate() : this.endDate,
      basis: basis ?? this.basis,
      order: order ?? this.order,
      submitting: submitting ?? this.submitting,
      bad: bad ?? this.bad,
      endBeforeStart: endBeforeStart ?? this.endBeforeStart,
      failed: failed ?? this.failed,
      message: message != null ? message() : this.message,
    );
  }
}

/// Chooses one quote and makes the work order from it.
class AwardFormCubit extends Cubit<AwardFormState> {
  AwardFormCubit(AppServices services, {required this.boqItemId, required this.tenderId, required this.bidId})
    : repository = TenderRepository(services.context),
      super(AwardFormState(today: services.context.today)) {
    load();
  }

  final int boqItemId;
  final int tenderId;
  final int bidId;
  final TenderRepository repository;

  /// The work order the award made, once it has.
  int? workOrderId;

  Future<void> load() async {
    emit(state.copyWith(load: AwardFormLoad.loading));
    try {
      final LineTenders line = await repository.line(boqItemId);
      final LineTender? tender = line.tender(tenderId);
      final TenderQuote? quote = tender?.quote(bidId);
      if (isClosed) return;
      if (tender == null || quote == null || !tender.isOpen || !quote.current || !line.canAward) {
        emit(state.copyWith(load: AwardFormLoad.gone, line: line));
        return;
      }
      emit(state.copyWith(load: AwardFormLoad.ready, line: line, tender: tender, quote: quote));
    } on OfflineFailure {
      if (!isClosed) emit(state.copyWith(load: AwardFormLoad.offline));
    } on ApiFailure {
      if (!isClosed) emit(state.copyWith(load: AwardFormLoad.failed));
    }
  }

  void setStart(String? date) => emit(state.copyWith(startDate: () => date, endBeforeStart: false));

  void setEnd(String? date) => emit(state.copyWith(endDate: () => date, endBeforeStart: false));

  void setBasis(String basis) => emit(state.copyWith(basis: basis));

  void setOrder(String order) => emit(state.copyWith(order: order));

  void clearProblem(AwardField field) {
    if (state.bad.contains(field) || state.failed) {
      emit(state.copyWith(bad: <AwardField>{...state.bad}..remove(field), failed: false, message: () => null));
    }
  }

  /// A percentage as typed: 0 to 100, two decimals at most. Returns the text to send.
  static String? pctOf(String text) {
    final String trimmed = text.trim();
    final Decimal? value = Decimal.tryParse(trimmed);
    if (value == null || value.scale > 2 || value < Decimal.zero || value > Decimal.fromInt(100)) return null;
    return trimmed;
  }

  static Money? advanceOf(String text) {
    final String trimmed = text.trim().isEmpty ? '0' : text.trim();
    if (Money.hasExtraDecimals(trimmed)) return null;
    final Money? money = Money.tryParse(trimmed);
    return money == null || money.isNegative ? null : money;
  }

  /// Returns true once the server has made the work order.
  Future<bool> submit(AwardText text) async {
    if (state.load != AwardFormLoad.ready || state.submitting) return false;
    final String? retention = pctOf(text.retention);
    final String? tds = pctOf(text.tds);
    final String? gst = pctOf(text.gst);
    final String? recovery = pctOf(text.recovery);
    final Money? advance = advanceOf(text.advance);
    final Set<AwardField> bad = <AwardField>{
      if (retention == null) AwardField.retention,
      if (tds == null) AwardField.tds,
      if (gst == null) AwardField.gst,
      if (advance == null) AwardField.advance,
      if (recovery == null) AwardField.recovery,
    };
    final String? start = state.startDate;
    final String? end = state.endDate;
    final bool endBefore = start != null && end != null && end.compareTo(start) < 0;
    if (bad.isNotEmpty || endBefore) {
      emit(state.copyWith(bad: bad, endBeforeStart: endBefore));
      return false;
    }

    final String note = text.note.trim();
    emit(state.copyWith(submitting: true, bad: const <AwardField>{}, failed: false, message: () => null));
    try {
      workOrderId = await repository.award(
        tenderId,
        AwardInput(
          bidId: bidId,
          retentionPct: retention!,
          tdsPct: tds!,
          gstPct: gst!,
          advanceAmount: advance!,
          advanceRecoveryPct: recovery!,
          deductionBasis: state.basis,
          deductionOrder: state.order,
          startDate: start,
          endDate: end,
          note: note.isEmpty ? null : (note.length > 500 ? note.substring(0, 500) : note),
        ),
      );
      if (!isClosed) emit(state.copyWith(submitting: false));
      return true;
    } on ValidationFailure catch (e) {
      if (!isClosed) {
        emit(
          state.copyWith(
            submitting: false,
            failed: true,
            message: () => e.errors.values.expand((List<String> list) => list).firstOrNull ?? e.message,
          ),
        );
      }
    } on ApiFailure catch (e) {
      if (!isClosed) {
        emit(
          state.copyWith(
            submitting: false,
            failed: true,
            message: () => e is ServerFailure && e.message.trim().isNotEmpty ? e.message : null,
          ),
        );
      }
    }
    return false;
  }
}
