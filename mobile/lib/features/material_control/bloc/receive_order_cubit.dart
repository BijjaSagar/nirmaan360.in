import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart';
import '../../measurement/bloc/measurement_book.dart';
import '../data/material_repository.dart';

enum ReceiveProblem { orderRequired, nothingReceived, saveFailed }

enum LineProblemKind { invalid, tooManyDecimals, overDue, rejectedOnly }

class LineProblem extends Equatable {
  const LineProblem(this.kind, {this.due});

  final LineProblemKind kind;

  /// For [LineProblemKind.overDue]: what is still due, in the unit being typed.
  final Qty? due;

  @override
  List<Object?> get props => <Object?>[kind, due];
}

/// What was typed for one line of the order.
class ReceiveLineText {
  const ReceiveLineText({this.accepted = '', this.rejected = ''});

  final String accepted;
  final String rejected;
}

/// What the person typed. Kept out of the state so typing does not rebuild.
class ReceiveOrderText {
  const ReceiveOrderText({this.lines = const <int, ReceiveLineText>{}, this.challanNo = '', this.vehicleNo = '', this.remarks = ''});

  final Map<int, ReceiveLineText> lines;
  final String challanNo;
  final String vehicleNo;
  final String remarks;
}

class ReceiveOrderState extends Equatable {
  const ReceiveOrderState({
    required this.today,
    required this.yesterday,
    required this.date,
    this.ready = false,
    this.projectId,
    this.overview,
    this.orderId,
    this.storeId,
    this.units = const <int, int>{},
    this.lineProblems = const <int, LineProblem>{},
    this.submitting = false,
    this.problem,
  });

  final String today;
  final String yesterday;
  final String date;
  final bool ready;
  final int? projectId;
  final MaterialOverview? overview;
  final int? orderId;

  /// Null is where the order says: the server uses the order's store.
  final int? storeId;

  /// The unit chosen for a line of the order, by line id. A line not here is typed in its own unit.
  final Map<int, int> units;
  final Map<int, LineProblem> lineProblems;
  final bool submitting;
  final ReceiveProblem? problem;

  OpenOrder? get order => orderId == null ? null : overview?.order(orderId!);

  /// The unit a line is being typed in.
  int? unitOf(OrderLine line) => units[line.id] ?? line.unitId;

  ReceiveOrderState copyWith({
    String? date,
    bool? ready,
    int? projectId,
    MaterialOverview? overview,
    int? Function()? orderId,
    int? Function()? storeId,
    Map<int, int>? units,
    Map<int, LineProblem>? lineProblems,
    bool? submitting,
    ReceiveProblem? Function()? problem,
  }) {
    return ReceiveOrderState(
      today: today,
      yesterday: yesterday,
      date: date ?? this.date,
      ready: ready ?? this.ready,
      projectId: projectId ?? this.projectId,
      overview: overview ?? this.overview,
      orderId: orderId == null ? this.orderId : orderId(),
      storeId: storeId == null ? this.storeId : storeId(),
      units: units ?? this.units,
      lineProblems: lineProblems ?? this.lineProblems,
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
    orderId,
    storeId,
    units,
    lineProblems,
    submitting,
    problem,
  ];
}

/// Goods arriving against a purchase order, a part at a time.
///
/// The server's rules, which this form follows: the quantity on a line is what was
/// accepted, it must be above zero, and with what already came it cannot pass what
/// was ordered. A turned-away quantity is recorded beside it and counts as neither
/// stock nor received, so it stays due on the order. A line with nothing accepted is
/// not sent.
class ReceiveOrderCubit extends Cubit<ReceiveOrderState> {
  ReceiveOrderCubit(this._services, {int? orderId})
    : _requestedOrder = orderId,
      super(
        ReceiveOrderState(
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
          date: _services.context.today,
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final int? _requestedOrder;

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(ready: true));
      return;
    }
    final MaterialOverview? overview = await _services.material.readOverview(projectId);
    if (isClosed) return;
    final List<OpenOrder> due = overview?.ordersDue ?? const <OpenOrder>[];
    final int? requested = _requestedOrder;
    // One open order leaves nothing to choose. Its number is the first thing on the page.
    final int? start = requested != null && due.any((order) => order.id == requested)
        ? requested
        : (due.length == 1 ? due.single.id : null);
    emit(state.copyWith(ready: true, projectId: projectId, overview: overview, orderId: () => start));
  }

  void setOrder(int id) => emit(
    state.copyWith(
      orderId: () => id,
      storeId: () => null,
      units: const <int, int>{},
      lineProblems: const <int, LineProblem>{},
      problem: () => null,
    ),
  );

  void setUnit(int lineId, int unitId) => emit(
    state.copyWith(
      units: <int, int>{...state.units, lineId: unitId},
      lineProblems: <int, LineProblem>{...state.lineProblems}..remove(lineId),
    ),
  );

  void setStore(int? id) => emit(state.copyWith(storeId: () => id));

  void setDate(String date) => emit(state.copyWith(date: date));

  /// Typing on a line clears what was said about it.
  void clearLineProblem(int lineId) {
    if (!state.lineProblems.containsKey(lineId) && state.problem == null) return;
    emit(
      state.copyWith(
        lineProblems: <int, LineProblem>{...state.lineProblems}..remove(lineId),
        problem: () => null,
      ),
    );
  }

  bool _fail(ReceiveProblem problem) {
    emit(state.copyWith(problem: () => problem));
    return false;
  }

  static String? _clean(String text) {
    final String trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  /// A blank box is nothing (null parses as zero); anything else must be a number.
  static Qty? _typed(String text) => text.trim().isEmpty ? Qty.zero : Qty.tryParse(text);

  /// True when the entry is safe on this phone.
  Future<bool> submit(ReceiveOrderText text) async {
    final OpenOrder? order = state.order;
    final int? projectId = state.projectId;
    final MaterialOverview? overview = state.overview;
    if (order == null || projectId == null || overview == null) return _fail(ReceiveProblem.orderRequired);

    final Map<int, LineProblem> problems = <int, LineProblem>{};
    final List<ReceiptEntry> entries = <ReceiptEntry>[];
    for (final OrderLine line in order.due) {
      final ReceiveLineText typed = text.lines[line.id] ?? const ReceiveLineText();
      final Qty? accepted = _typed(typed.accepted);
      final Qty? rejected = _typed(typed.rejected);
      if (accepted == null || rejected == null || accepted.isNegative || rejected.isNegative) {
        problems[line.id] = const LineProblem(LineProblemKind.invalid);
        continue;
      }
      // Refused rather than rounded: the server takes three places and no more.
      if (Qty.hasExtraDecimals(typed.accepted) || Qty.hasExtraDecimals(typed.rejected)) {
        problems[line.id] = const LineProblem(LineProblemKind.tooManyDecimals);
        continue;
      }
      if (accepted.isZero) {
        // The server refuses a line of 0, so there is no way to send only a turned-away quantity.
        if (!rejected.isZero) problems[line.id] = const LineProblem(LineProblemKind.rejectedOnly);
        continue;
      }
      final int? unitId = state.unitOf(line);
      final MaterialInfo? material = overview.material(line.materialId);
      final Qty base = material?.toBase(accepted, unitId) ?? accepted;
      if (base > line.remaining) {
        problems[line.id] = LineProblem(
          LineProblemKind.overDue,
          due: material?.fromBase(line.remaining, unitId) ?? line.remaining,
        );
        continue;
      }
      entries.add(
        ReceiptEntry(orderLineId: line.id, materialId: line.materialId, unitId: unitId, qty: accepted, rejected: rejected),
      );
    }
    if (problems.isNotEmpty) {
      emit(state.copyWith(lineProblems: problems, problem: () => null));
      return false;
    }
    if (entries.isEmpty) return _fail(ReceiveProblem.nothingReceived);

    emit(state.copyWith(submitting: true, lineProblems: const <int, LineProblem>{}, problem: () => null));
    final String subtitle = _subtitle(overview, entries);
    try {
      await _services.material.receiveAgainstOrder(
        projectId: projectId,
        order: order,
        storeId: state.storeId,
        date: state.date,
        challanNo: _clean(text.challanNo),
        vehicleNo: _clean(text.vehicleNo)?.toUpperCase(),
        remarks: _clean(text.remarks),
        lines: entries,
        title: order.number,
        subtitle: subtitle,
      );
      return true;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => ReceiveProblem.saveFailed));
      return false;
    }
  }

  /// "Cement 40 bag · Sand 3 MT", and how many more when there are many.
  String _subtitle(MaterialOverview overview, List<ReceiptEntry> entries) {
    final List<String> shown = <String>[
      for (final ReceiptEntry entry in entries.take(2))
        '${overview.materialName(entry.materialId)} ${formatQty(entry.qty)} ${overview.unitLabel(entry.materialId, entry.unitId)}'.trim(),
    ];
    return <String>[...shown, if (entries.length > 2) '+${entries.length - 2}'].join(' · ');
  }
}
