import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/ids.dart';
import '../data/material_loop_repository.dart';

/// Why a send did not go through. The server's own words, when it gave any,
/// travel in the state's `message`.
enum LoopProblem { empty, tooLong, invalid, offline, refused, failed }

LoopProblem _problemOf(ApiFailure failure) => switch (failure) {
  OfflineFailure() => LoopProblem.offline,
  ValidationFailure() => LoopProblem.invalid,
  ServerFailure(statusCode: 403 || 404) => LoopProblem.refused,
  _ => LoopProblem.failed,
};

/// The first message the server gave against a field, else its own message,
/// else nothing so the screen uses its own words.
String? _messageOf(ApiFailure failure) {
  if (failure is ValidationFailure) {
    return failure.errors.values.expand((List<String> list) => list).firstOrNull ??
        (failure.message.trim().isEmpty ? null : failure.message);
  }
  return null;
}

// A reason on a flag -----------------------------------------------------------

class FlagReasonState extends Equatable {
  const FlagReasonState({this.sending = false, this.sent = false, this.problem, this.message});

  final bool sending;
  final bool sent;
  final LoopProblem? problem;
  final String? message;

  @override
  List<Object?> get props => <Object?>[sending, sent, problem, message];
}

/// One reason for one flag. The `client_uuid` is made once, so a retry after a
/// lost reply is answered with the first reason instead of writing a second.
class FlagReasonCubit extends Cubit<FlagReasonState> {
  FlagReasonCubit(this._services, {required this.projectId, required this.materialId, required this.flagType})
    : _uuid = newUuid(),
      super(const FlagReasonState());

  final AppServices _services;
  final int projectId;
  final int materialId;
  final String flagType;
  final String _uuid;

  Future<bool> send(String text) async {
    final String reason = text.trim();
    if (reason.isEmpty) {
      emit(const FlagReasonState(problem: LoopProblem.empty));
      return false;
    }
    if (reason.length > kReasonMax) {
      emit(const FlagReasonState(problem: LoopProblem.tooLong));
      return false;
    }
    emit(const FlagReasonState(sending: true));
    try {
      await _services.material.loop.answerFlag(
        projectId: projectId,
        materialId: materialId,
        flagType: flagType,
        reason: reason,
        clientUuid: _uuid,
      );
      if (!isClosed) emit(const FlagReasonState(sent: true));
      return true;
    } on ApiFailure catch (e) {
      if (!isClosed) emit(FlagReasonState(problem: _problemOf(e), message: _messageOf(e)));
      return false;
    }
  }
}

// One challan ------------------------------------------------------------------

enum ChallanLoad { loading, ready, offline, refused, failed }

enum ChallanSend { none, bill, short }

class ChallanState extends Equatable {
  const ChallanState({this.load = ChallanLoad.loading, this.detail, this.sending = ChallanSend.none, this.problem, this.message});

  final ChallanLoad load;
  final ChallanDetail? detail;

  /// What is on its way to the server, if anything.
  final ChallanSend sending;
  final LoopProblem? problem;
  final String? message;

  ChallanState copyWith({
    ChallanLoad? load,
    ChallanDetail? Function()? detail,
    ChallanSend? sending,
    LoopProblem? Function()? problem,
    String? Function()? message,
  }) {
    return ChallanState(
      load: load ?? this.load,
      detail: detail == null ? this.detail : detail(),
      sending: sending ?? this.sending,
      problem: problem == null ? this.problem : problem(),
      message: message == null ? this.message : message(),
    );
  }

  @override
  List<Object?> get props => <Object?>[load, detail, sending, problem, message];
}

/// A challan read from the server, with its bill and a short found later.
class ChallanCubit extends Cubit<ChallanState> {
  ChallanCubit(this._services, {required this.challanId}) : super(const ChallanState());

  final AppServices _services;
  final int challanId;

  /// Kept until a short is stored, so a retry sends the same one.
  String _shortUuid = newUuid();

  MaterialLoopRepository get repository => _services.material.loop;

  String get today => repository.today;

  Future<void> load() async {
    if (state.detail == null) emit(state.copyWith(load: ChallanLoad.loading));
    try {
      final ChallanDetail detail = await repository.challan(challanId);
      if (!isClosed) emit(state.copyWith(load: ChallanLoad.ready, detail: () => detail));
    } on ApiFailure catch (e) {
      if (isClosed) return;
      // A copy already on screen stays; only a first read shows the failure.
      if (state.detail != null) return;
      emit(
        state.copyWith(
          load: switch (_problemOf(e)) {
            LoopProblem.offline => ChallanLoad.offline,
            LoopProblem.refused => ChallanLoad.refused,
            _ => ChallanLoad.failed,
          },
        ),
      );
    }
  }

  void clearProblem() {
    if (state.problem != null) emit(state.copyWith(problem: () => null, message: () => null));
  }

  Future<int?> _projectId() async => state.detail?.projectId ?? await _services.projects.readSelectedProjectId();

  /// A photo of the bill, or for `money.view` its number alone. Returns true once stored.
  Future<bool> attachBill({String? photoPath, String? number, String? date, String? amountText}) async {
    final String? billNo = number?.trim().isEmpty ?? true ? null : number!.trim();
    Money? amount;
    final String typed = amountText?.trim() ?? '';
    if (typed.isNotEmpty) {
      amount = Money.tryParse(typed);
      if (amount == null || amount.isNegative || amount.isZero || Money.hasExtraDecimals(typed)) {
        emit(state.copyWith(problem: () => LoopProblem.invalid, message: () => null));
        return false;
      }
    }
    if (photoPath == null && billNo == null) {
      emit(state.copyWith(problem: () => LoopProblem.empty, message: () => null));
      return false;
    }
    emit(state.copyWith(sending: ChallanSend.bill, problem: () => null, message: () => null));
    try {
      final ChallanDetail detail = await repository.attachBill(
        projectId: await _projectId(),
        challanId: challanId,
        filePath: photoPath,
        number: billNo,
        date: date,
        amount: amount,
      );
      if (!isClosed) emit(state.copyWith(sending: ChallanSend.none, detail: () => detail));
      return true;
    } on ApiFailure catch (e) {
      if (!isClosed) emit(state.copyWith(sending: ChallanSend.none, problem: () => _problemOf(e), message: () => _messageOf(e)));
      return false;
    }
  }

  /// A shortage found after the delivery was taken into stock. Returns true once stored.
  Future<bool> reportShort({required String qtyText, required String foundOn, String? note}) async {
    final Qty? qty = Qty.tryParse(qtyText.trim());
    if (qty == null || qty.isNegative || qty.isZero || Qty.hasExtraDecimals(qtyText.trim())) {
      emit(state.copyWith(problem: () => LoopProblem.invalid, message: () => null));
      return false;
    }
    final String? cleanNote = note == null || note.trim().isEmpty ? null : note.trim();
    if (cleanNote != null && cleanNote.length > kShortNoteMax) {
      emit(state.copyWith(problem: () => LoopProblem.tooLong, message: () => null));
      return false;
    }
    emit(state.copyWith(sending: ChallanSend.short, problem: () => null, message: () => null));
    try {
      final ChallanDetail detail = await repository.reportShort(
        projectId: await _projectId(),
        challanId: challanId,
        qty: qty,
        foundOn: foundOn,
        note: cleanNote,
        clientUuid: _shortUuid,
      );
      _shortUuid = newUuid();
      if (!isClosed) emit(state.copyWith(sending: ChallanSend.none, detail: () => detail));
      return true;
    } on ApiFailure catch (e) {
      if (!isClosed) emit(state.copyWith(sending: ChallanSend.none, problem: () => _problemOf(e), message: () => _messageOf(e)));
      return false;
    }
  }
}

// Bills waiting ----------------------------------------------------------------

enum ListLoad { loading, noProject, ready, offline, refused, failed }

class BillsState extends Equatable {
  const BillsState({
    this.load = ListLoad.loading,
    this.attached = false,
    this.rows = const <BillRow>[],
    this.pendingCount,
    this.alertAfterDays = 7,
    this.page = 1,
    this.hasMore = false,
    this.loadingMore = false,
    this.moreFailed = false,
  });

  final ListLoad load;

  /// The tab: bills still awaited, or bills already attached.
  final bool attached;
  final List<BillRow> rows;
  final int? pendingCount;
  final int alertAfterDays;
  final int page;
  final bool hasMore;
  final bool loadingMore;
  final bool moreFailed;

  @override
  List<Object?> get props => <Object?>[
    load,
    attached,
    rows,
    pendingCount,
    alertAfterDays,
    page,
    hasMore,
    loadingMore,
    moreFailed,
  ];
}

class BillsCubit extends Cubit<BillsState> {
  BillsCubit(this._services) : super(const BillsState());

  final AppServices _services;

  ListLoad _loadOf(ApiFailure failure) => switch (_problemOf(failure)) {
    LoopProblem.offline => ListLoad.offline,
    LoopProblem.refused => ListLoad.refused,
    _ => ListLoad.failed,
  };

  /// The first page of the tab on show. False when the server could not be read.
  Future<bool> load({bool? attached}) async {
    final bool tab = attached ?? state.attached;
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (isClosed) return false;
    if (projectId == null) {
      emit(BillsState(load: ListLoad.noProject, attached: tab));
      return false;
    }
    final bool switching = tab != state.attached;
    if (switching || state.load != ListLoad.ready) {
      emit(BillsState(attached: tab, pendingCount: state.pendingCount, alertAfterDays: state.alertAfterDays));
    }
    try {
      final BillsPage page = await _services.material.loop.bills(projectId, attached: tab);
      if (isClosed || state.attached != tab) return false;
      emit(
        BillsState(
          load: ListLoad.ready,
          attached: tab,
          rows: page.rows,
          pendingCount: page.pendingCount,
          alertAfterDays: page.alertAfterDays,
          page: page.page,
          hasMore: page.hasMore,
        ),
      );
      return true;
    } on ApiFailure catch (e) {
      if (isClosed || state.attached != tab) return false;
      // A list already on screen stays.
      if (state.load != ListLoad.ready) {
        emit(BillsState(load: _loadOf(e), attached: tab, pendingCount: state.pendingCount));
      }
      return false;
    }
  }

  Future<void> showAttached(bool attached) async {
    if (attached == state.attached && state.load == ListLoad.ready) return;
    await load(attached: attached);
  }

  Future<void> more() async {
    if (!state.hasMore || state.loadingMore) return;
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null || isClosed) return;
    final BillsState before = state;
    emit(
      BillsState(
        load: before.load,
        attached: before.attached,
        rows: before.rows,
        pendingCount: before.pendingCount,
        alertAfterDays: before.alertAfterDays,
        page: before.page,
        hasMore: before.hasMore,
        loadingMore: true,
      ),
    );
    try {
      final BillsPage page = await _services.material.loop.bills(projectId, attached: before.attached, page: before.page + 1);
      if (isClosed || state.attached != before.attached) return;
      final Set<int> seen = before.rows.map((BillRow row) => row.id).toSet();
      emit(
        BillsState(
          load: ListLoad.ready,
          attached: before.attached,
          rows: <BillRow>[...before.rows, ...page.rows.where((BillRow row) => !seen.contains(row.id))],
          pendingCount: page.pendingCount,
          alertAfterDays: page.alertAfterDays,
          page: page.page,
          hasMore: page.hasMore,
        ),
      );
    } on ApiFailure {
      if (isClosed) return;
      emit(
        BillsState(
          load: before.load,
          attached: before.attached,
          rows: before.rows,
          pendingCount: before.pendingCount,
          alertAfterDays: before.alertAfterDays,
          page: before.page,
          hasMore: before.hasMore,
          moreFailed: true,
        ),
      );
    }
  }
}

// Wastage against the BOQ norms ------------------------------------------------

class WastageState extends Equatable {
  const WastageState({this.load = ListLoad.loading, this.basis = WastageBasis.measured, this.report});

  final ListLoad load;
  final WastageBasis basis;
  final WastageReport? report;

  @override
  List<Object?> get props => <Object?>[load, basis, report];
}

class WastageCubit extends Cubit<WastageState> {
  WastageCubit(this._services) : super(const WastageState());

  final AppServices _services;

  /// False when the server could not be read. A report on screen stays.
  Future<bool> load({WastageBasis? basis}) async {
    final WastageBasis chosen = basis ?? state.basis;
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (isClosed) return false;
    if (projectId == null) {
      emit(WastageState(load: ListLoad.noProject, basis: chosen));
      return false;
    }
    if (chosen != state.basis || state.report == null) emit(WastageState(basis: chosen));
    try {
      final WastageReport report = await _services.material.loop.wastage(projectId, basis: chosen);
      if (isClosed || state.basis != chosen) return false;
      emit(WastageState(load: ListLoad.ready, basis: chosen, report: report));
      return true;
    } on ApiFailure catch (e) {
      if (isClosed || state.basis != chosen) return false;
      if (state.report == null) {
        emit(
          WastageState(
            load: switch (_problemOf(e)) {
              LoopProblem.offline => ListLoad.offline,
              LoopProblem.refused => ListLoad.refused,
              _ => ListLoad.failed,
            },
            basis: chosen,
          ),
        );
      }
      return false;
    }
  }
}
