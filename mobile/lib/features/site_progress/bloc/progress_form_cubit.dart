import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart';
import '../data/progress_repository.dart';

enum ProgressKind { work, pendingOnly }

enum ProgressFormProblem { itemRequired, contractorRequired, qtyRequired, remarkRequired, saveFailed }

class ProgressFormState extends Equatable {
  const ProgressFormState({
    required this.today,
    required this.yesterday,
    required this.workDate,
    this.ready = false,
    this.projectId,
    this.overview,
    this.floorId,
    this.itemId,
    this.workOrderId,
    this.kind = ProgressKind.work,
    this.photos = const <String>[],
    this.submitting = false,
    this.problem,
  });

  final String today;
  final String yesterday;
  final String workDate;
  final bool ready;
  final int? projectId;
  final ProgressOverview? overview;
  final int? floorId;
  final int? itemId;

  /// Whose work this is, on a line several contractors share.
  final int? workOrderId;
  final ProgressKind kind;
  final List<String> photos;
  final bool submitting;
  final ProgressFormProblem? problem;

  ProgressItem? get item => itemId == null ? null : overview?.item(itemId!);

  FloorChoice? get floor => floorId == null ? null : overview?.floorChoice(floorId!);

  /// A note about pending work records no quantity, so it is nobody's yet.
  bool get asksContractor => kind == ProgressKind.work && (item?.workOrders.isNotEmpty ?? false);

  ProgressFormState copyWith({
    String? workDate,
    bool? ready,
    int? projectId,
    ProgressOverview? overview,
    int? Function()? floorId,
    int? itemId,
    int? Function()? workOrderId,
    ProgressKind? kind,
    List<String>? photos,
    bool? submitting,
    ProgressFormProblem? Function()? problem,
  }) {
    return ProgressFormState(
      today: today,
      yesterday: yesterday,
      workDate: workDate ?? this.workDate,
      ready: ready ?? this.ready,
      projectId: projectId ?? this.projectId,
      overview: overview ?? this.overview,
      floorId: floorId == null ? this.floorId : floorId(),
      itemId: itemId ?? this.itemId,
      workOrderId: workOrderId == null ? this.workOrderId : workOrderId(),
      kind: kind ?? this.kind,
      photos: photos ?? this.photos,
      submitting: submitting ?? this.submitting,
      problem: problem == null ? this.problem : problem(),
    );
  }

  @override
  List<Object?> get props => <Object?>[
    workDate,
    ready,
    projectId,
    overview,
    floorId,
    itemId,
    workOrderId,
    kind,
    photos,
    submitting,
    problem,
  ];
}

class ProgressFormCubit extends Cubit<ProgressFormState> {
  ProgressFormCubit(this._services, {int? floorId})
    : _requestedFloor = floorId,
      super(
        ProgressFormState(
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
          workDate: _services.context.today,
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final int? _requestedFloor;

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(ready: true));
      return;
    }
    final repository = _services.progress;
    final ProgressOverview? overview = await repository.readOverview(projectId);
    final last = await repository.lastPick(projectId);
    if (isClosed) return;
    final int? floorId = _requestedFloor ?? last.floorId;
    final int? itemId = last.itemId;
    emit(
      state.copyWith(
        ready: true,
        projectId: projectId,
        overview: overview,
        floorId: () => floorId != null && overview?.floor(floorId) != null ? floorId : null,
        itemId: itemId != null && overview?.item(itemId) != null ? itemId : null,
      ),
    );
  }

  void setFloor(int? floorId) => emit(state.copyWith(floorId: () => floorId));

  /// Contractors differ from line to line, so a new line starts unchosen.
  void setItem(int itemId) =>
      emit(state.copyWith(itemId: itemId, workOrderId: () => null, problem: () => null));

  void setWorkOrder(int id) => emit(state.copyWith(workOrderId: () => id, problem: () => null));

  void setKind(ProgressKind kind) => emit(state.copyWith(kind: kind, problem: () => null));

  void setDate(String date) => emit(state.copyWith(workDate: date));

  void clearProblem() => emit(state.copyWith(problem: () => null));

  void addPhoto(String path) => emit(state.copyWith(photos: <String>[...state.photos, path]));

  void removePhoto(String path) =>
      emit(state.copyWith(photos: state.photos.where((photo) => photo != path).toList()));

  /// True when the entry is safe on this phone.
  Future<bool> submit({required String qtyText, required String remark}) async {
    final ProgressItem? item = state.item;
    final int? projectId = state.projectId;
    final String note = remark.trim();
    final bool pendingOnly = state.kind == ProgressKind.pendingOnly;
    final Qty? qty = pendingOnly ? null : Qty.tryParse(qtyText);

    if (item == null || projectId == null) {
      emit(state.copyWith(problem: () => ProgressFormProblem.itemRequired));
      return false;
    }
    final bool asksContractor = state.asksContractor;
    if (asksContractor && !item.workOrders.any((order) => order.id == state.workOrderId)) {
      emit(state.copyWith(problem: () => ProgressFormProblem.contractorRequired));
      return false;
    }
    if (!pendingOnly && (qty == null || qty.isZero || qty.isNegative)) {
      emit(state.copyWith(problem: () => ProgressFormProblem.qtyRequired));
      return false;
    }
    if (pendingOnly && note.isEmpty) {
      emit(state.copyWith(problem: () => ProgressFormProblem.remarkRequired));
      return false;
    }

    emit(state.copyWith(submitting: true, problem: () => null));
    try {
      await _services.progress.addEntry(
        projectId: projectId,
        boqItemId: item.id,
        floorId: state.floorId,
        workOrderId: asksContractor ? state.workOrderId : null,
        workDate: state.workDate,
        qty: qty,
        remark: note.isEmpty ? null : note,
        isPending: pendingOnly,
        photoPaths: state.photos,
        title: '${item.code} ${item.description}'.trim(),
        subtitle: state.floor?.label,
      );
      return true;
    } on Object {
      if (!isClosed) {
        emit(state.copyWith(submitting: false, problem: () => ProgressFormProblem.saveFailed));
      }
      return false;
    }
  }
}
