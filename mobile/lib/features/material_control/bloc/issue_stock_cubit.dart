import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart';
import '../data/material_repository.dart';

enum IssueProblem { noStore, saveFailed }

enum IssueLineProblem { materialRequired, qtyRequired, tooManyDecimals, workRequired }

/// One line being filled in. [key] only tells lines apart on screen.
class IssueLine extends Equatable {
  const IssueLine({required this.key, this.materialId, this.unitId, this.boqItemId});

  final int key;
  final int? materialId;

  /// Null is the material's own unit.
  final int? unitId;
  final int? boqItemId;

  IssueLine copyWith({int? materialId, int? Function()? unitId, int? boqItemId}) => IssueLine(
    key: key,
    materialId: materialId ?? this.materialId,
    unitId: unitId == null ? this.unitId : unitId(),
    boqItemId: boqItemId ?? this.boqItemId,
  );

  @override
  List<Object?> get props => <Object?>[key, materialId, unitId, boqItemId];
}

class IssueLineText {
  const IssueLineText({this.qty = '', this.activity = ''});

  final String qty;
  final String activity;
}

/// What the person typed. Kept out of the state so typing does not rebuild.
class IssueText {
  const IssueText({this.lines = const <int, IssueLineText>{}, this.issuedTo = ''});

  final Map<int, IssueLineText> lines;
  final String issuedTo;
}

class IssueStockState extends Equatable {
  const IssueStockState({
    required this.today,
    required this.yesterday,
    required this.date,
    this.ready = false,
    this.projectId,
    this.overview,
    this.boqLines = const <BoqItemRow>[],
    this.storeId,
    this.lines = const <IssueLine>[IssueLine(key: 0)],
    this.lineProblems = const <int, IssueLineProblem>{},
    this.submitting = false,
    this.problem,
  });

  final String today;
  final String yesterday;
  final String date;
  final bool ready;
  final int? projectId;
  final MaterialOverview? overview;
  final List<BoqItemRow> boqLines;
  final int? storeId;
  final List<IssueLine> lines;
  final Map<int, IssueLineProblem> lineProblems;
  final bool submitting;
  final IssueProblem? problem;

  BoqItemRow? boqLine(int? id) {
    for (final BoqItemRow line in boqLines) {
      if (line.id == id) return line;
    }
    return null;
  }

  IssueStockState copyWith({
    String? date,
    bool? ready,
    int? projectId,
    MaterialOverview? overview,
    List<BoqItemRow>? boqLines,
    int? storeId,
    List<IssueLine>? lines,
    Map<int, IssueLineProblem>? lineProblems,
    bool? submitting,
    IssueProblem? Function()? problem,
  }) {
    return IssueStockState(
      today: today,
      yesterday: yesterday,
      date: date ?? this.date,
      ready: ready ?? this.ready,
      projectId: projectId ?? this.projectId,
      overview: overview ?? this.overview,
      boqLines: boqLines ?? this.boqLines,
      storeId: storeId ?? this.storeId,
      lines: lines ?? this.lines,
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
    boqLines,
    storeId,
    lines,
    lineProblems,
    submitting,
    problem,
  ];
}

/// Material leaving a store: one or more lines, each for a BOQ item, an activity,
/// or both, as the server's rule says. A store is always needed, so a project with
/// none cannot issue from the phone.
class IssueStockCubit extends Cubit<IssueStockState> {
  IssueStockCubit(this._services, {int? materialId})
    : _requestedMaterial = materialId,
      super(
        IssueStockState(
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
          date: _services.context.today,
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final int? _requestedMaterial;
  var _nextKey = 1;

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(ready: true));
      return;
    }
    final MaterialOverview? overview = await _services.material.readOverview(projectId);
    final List<BoqItemRow> boq = await _services.material.readBoqLines(projectId);
    if (isClosed) return;
    final int? requested = _requestedMaterial;
    emit(
      state.copyWith(
        ready: true,
        projectId: projectId,
        overview: overview,
        boqLines: boq,
        storeId: overview == null || overview.stores.isEmpty ? null : overview.stores.first.id,
        lines: <IssueLine>[
          IssueLine(key: 0, materialId: requested != null && overview?.material(requested) != null ? requested : null),
        ],
      ),
    );
  }

  void setStore(int id) => emit(state.copyWith(storeId: id));

  void setDate(String date) => emit(state.copyWith(date: date));

  void addLine() {
    if (state.lines.length >= MaterialRepository.issueLinesMax) return;
    emit(state.copyWith(lines: <IssueLine>[...state.lines, IssueLine(key: _nextKey++)]));
  }

  void removeLine(int key) {
    if (state.lines.length < 2) return;
    emit(
      state.copyWith(
        lines: <IssueLine>[for (final IssueLine line in state.lines) if (line.key != key) line],
        lineProblems: <int, IssueLineProblem>{...state.lineProblems}..remove(key),
      ),
    );
  }

  void _change(int key, IssueLine Function(IssueLine line) change) {
    emit(
      state.copyWith(
        lines: <IssueLine>[for (final IssueLine line in state.lines) line.key == key ? change(line) : line],
        lineProblems: <int, IssueLineProblem>{...state.lineProblems}..remove(key),
      ),
    );
  }

  /// Units differ from material to material, so a new one starts on its own.
  void setMaterial(int key, int id) => _change(key, (line) => line.copyWith(materialId: id, unitId: () => null));

  void setUnit(int key, int? id) => _change(key, (line) => line.copyWith(unitId: () => id));

  void setBoqItem(int key, int id) => _change(key, (line) => line.copyWith(boqItemId: id));

  void clearLineProblem(int key) {
    if (!state.lineProblems.containsKey(key)) return;
    emit(state.copyWith(lineProblems: <int, IssueLineProblem>{...state.lineProblems}..remove(key)));
  }

  static String? _clean(String text) {
    final String trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  /// True when the entry is safe on this phone.
  Future<bool> submit(IssueText text) async {
    final int? projectId = state.projectId;
    final int? storeId = state.storeId;
    final MaterialOverview? overview = state.overview;
    if (projectId == null || overview == null) return false;
    if (storeId == null) {
      emit(state.copyWith(problem: () => IssueProblem.noStore));
      return false;
    }

    final Map<int, IssueLineProblem> problems = <int, IssueLineProblem>{};
    final List<IssueEntry> entries = <IssueEntry>[];
    for (final IssueLine line in state.lines) {
      final IssueLineText typed = text.lines[line.key] ?? const IssueLineText();
      final Qty? qty = Qty.tryParse(typed.qty);
      final String? activity = _clean(typed.activity);
      final int? materialId = line.materialId;
      if (materialId == null) {
        problems[line.key] = IssueLineProblem.materialRequired;
      } else if (qty == null || qty.isNegative || qty.isZero) {
        problems[line.key] = IssueLineProblem.qtyRequired;
      } else if (Qty.hasExtraDecimals(typed.qty)) {
        problems[line.key] = IssueLineProblem.tooManyDecimals;
      } else if (line.boqItemId == null && activity == null) {
        problems[line.key] = IssueLineProblem.workRequired;
      } else {
        entries.add(
          IssueEntry(
            materialId: materialId,
            unitId: line.unitId ?? overview.material(materialId)?.baseUnitId,
            qty: qty,
            boqItemId: line.boqItemId,
            activity: activity,
          ),
        );
      }
    }
    if (problems.isNotEmpty) {
      emit(state.copyWith(lineProblems: problems, problem: () => null));
      return false;
    }

    emit(state.copyWith(submitting: true, lineProblems: const <int, IssueLineProblem>{}, problem: () => null));
    final String names = <String>[
      for (final IssueEntry entry in entries.take(2)) overview.materialName(entry.materialId),
      if (entries.length > 2) '+${entries.length - 2}',
    ].join(' · ');
    final String store = overview.storeName(storeId);
    final String? issuedTo = _clean(text.issuedTo);
    try {
      await _services.material.issueFromStore(
        projectId: projectId,
        storeId: storeId,
        date: state.date,
        issuedTo: issuedTo,
        lines: entries,
        title: names,
        subtitle: <String>[store, ?issuedTo].where((part) => part.isNotEmpty).join(' · '),
      );
      return true;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => IssueProblem.saveFailed));
      return false;
    }
  }
}
