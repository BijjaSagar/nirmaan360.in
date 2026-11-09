import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart';
import '../../measurement/bloc/measurement_book.dart';
import '../data/material_repository.dart';

enum MaterialFormKind { receive, use, count }

enum MaterialFormProblem { materialRequired, supplierRequired, qtyRequired, workRequired, extraRequired, saveFailed }

/// What the person typed. Kept out of the state so typing does not rebuild.
class MaterialFormText {
  const MaterialFormText({
    required this.qty,
    this.challanQty = '',
    this.supplier = '',
    this.challanNo = '',
    this.vehicleNo = '',
    this.stage = '',
    this.extraWork = '',
    this.remarks = '',
  });

  final String qty;
  final String challanQty;
  final String supplier;
  final String challanNo;
  final String vehicleNo;
  final String stage;
  final String extraWork;
  final String remarks;
}

class MaterialFormState extends Equatable {
  const MaterialFormState({
    required this.kind,
    required this.today,
    required this.yesterday,
    required this.date,
    this.ready = false,
    this.projectId,
    this.overview,
    this.boqLines = const <BoqItemRow>[],
    this.materialId,
    this.unitId,
    this.storeId,
    this.supplierId,
    this.typingSupplier = false,
    this.boqItemId,
    this.extraWork = false,
    this.photo,
    this.submitting = false,
    this.problem,
  });

  final MaterialFormKind kind;
  final String today;
  final String yesterday;
  final String date;
  final bool ready;
  final int? projectId;
  final MaterialOverview? overview;
  final List<BoqItemRow> boqLines;
  final int? materialId;

  /// Null is the material's base unit.
  final int? unitId;

  /// Null is the project's first store, which the server picks.
  final int? storeId;
  final int? supplierId;
  final bool typingSupplier;
  final int? boqItemId;
  final bool extraWork;
  final String? photo;
  final bool submitting;
  final MaterialFormProblem? problem;

  MaterialInfo? get material => materialId == null ? null : overview?.material(materialId!);

  SupplierOption? get supplier => supplierId == null ? null : overview?.supplier(supplierId!);

  BoqItemRow? get boqLine {
    for (final BoqItemRow line in boqLines) {
      if (line.id == boqItemId) return line;
    }
    return null;
  }

  String get unitLabel => materialId == null ? '' : overview!.unitLabel(materialId!, unitId);

  MaterialFormState copyWith({
    String? date,
    bool? ready,
    int? projectId,
    MaterialOverview? overview,
    List<BoqItemRow>? boqLines,
    int? materialId,
    int? Function()? unitId,
    int? Function()? storeId,
    int? Function()? supplierId,
    bool? typingSupplier,
    int? boqItemId,
    bool? extraWork,
    String? Function()? photo,
    bool? submitting,
    MaterialFormProblem? Function()? problem,
  }) {
    return MaterialFormState(
      kind: kind,
      today: today,
      yesterday: yesterday,
      date: date ?? this.date,
      ready: ready ?? this.ready,
      projectId: projectId ?? this.projectId,
      overview: overview ?? this.overview,
      boqLines: boqLines ?? this.boqLines,
      materialId: materialId ?? this.materialId,
      unitId: unitId == null ? this.unitId : unitId(),
      storeId: storeId == null ? this.storeId : storeId(),
      supplierId: supplierId == null ? this.supplierId : supplierId(),
      typingSupplier: typingSupplier ?? this.typingSupplier,
      boqItemId: boqItemId ?? this.boqItemId,
      extraWork: extraWork ?? this.extraWork,
      photo: photo == null ? this.photo : photo(),
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
    materialId,
    unitId,
    storeId,
    supplierId,
    typingSupplier,
    boqItemId,
    extraWork,
    photo,
    submitting,
    problem,
  ];
}

class MaterialFormCubit extends Cubit<MaterialFormState> {
  MaterialFormCubit(this._services, MaterialFormKind kind, {int? materialId})
    : _requestedMaterial = materialId,
      super(
        MaterialFormState(
          kind: kind,
          today: _services.context.today,
          yesterday: istDate(_services.context.clock.now().subtract(const Duration(days: 1))),
          date: _services.context.today,
        ),
      ) {
    unawaited(_load());
  }

  final AppServices _services;
  final int? _requestedMaterial;

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(ready: true));
      return;
    }
    final MaterialOverview? overview = await _services.material.readOverview(projectId);
    final List<BoqItemRow> lines = await _services.material.readBoqLines(projectId);
    if (isClosed) return;
    final int? requested = _requestedMaterial;
    emit(
      state.copyWith(
        ready: true,
        projectId: projectId,
        overview: overview,
        boqLines: lines,
        materialId: requested != null && overview?.material(requested) != null ? requested : null,
      ),
    );
  }

  /// Units differ from material to material, so a new one starts on its own.
  void setMaterial(int id) => emit(state.copyWith(materialId: id, unitId: () => null, problem: () => null));

  void setUnit(int? id) => emit(state.copyWith(unitId: () => id));

  void setStore(int? id) => emit(state.copyWith(storeId: () => id));

  void setSupplier(int id) =>
      emit(state.copyWith(supplierId: () => id, typingSupplier: false, problem: () => null));

  void typeSupplier() => emit(state.copyWith(supplierId: () => null, typingSupplier: true, problem: () => null));

  void chooseSupplierFromList() => emit(state.copyWith(typingSupplier: false, problem: () => null));

  void setBoqItem(int id) => emit(state.copyWith(boqItemId: id, problem: () => null));

  void setExtraWork(bool extra) => emit(state.copyWith(extraWork: extra, problem: () => null));

  void setDate(String date) => emit(state.copyWith(date: date));

  void setPhoto(String? path) => emit(state.copyWith(photo: () => path));

  void clearProblem() => emit(state.copyWith(problem: () => null));

  bool _fail(MaterialFormProblem problem) {
    emit(state.copyWith(problem: () => problem));
    return false;
  }

  static String? _clean(String text) {
    final String trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  /// True when the entry is safe on this phone.
  Future<bool> submit(MaterialFormText text) async {
    final int? materialId = state.materialId;
    final int? projectId = state.projectId;
    if (materialId == null || projectId == null) return _fail(MaterialFormProblem.materialRequired);

    final Qty? qty = Qty.tryParse(text.qty);
    final bool counting = state.kind == MaterialFormKind.count;
    if (qty == null || qty.isNegative || (qty.isZero && !counting)) return _fail(MaterialFormProblem.qtyRequired);

    final String? typedSupplier = _clean(text.supplier);
    final String? extra = _clean(text.extraWork);
    switch (state.kind) {
      case MaterialFormKind.receive:
        if (state.supplierId == null && (!state.typingSupplier || typedSupplier == null)) {
          return _fail(MaterialFormProblem.supplierRequired);
        }
      case MaterialFormKind.use:
        if (state.extraWork && extra == null) return _fail(MaterialFormProblem.extraRequired);
        if (!state.extraWork && state.boqItemId == null) return _fail(MaterialFormProblem.workRequired);
      case MaterialFormKind.count:
        break;
    }

    emit(state.copyWith(submitting: true, problem: () => null));
    final String title = state.overview!.materialName(materialId);
    final String subtitle = '${formatQty(qty)} ${state.unitLabel}'.trim();
    final repository = _services.material;
    try {
      switch (state.kind) {
        case MaterialFormKind.receive:
          await repository.receive(
            projectId: projectId,
            materialId: materialId,
            supplierId: state.supplierId,
            supplierName: typedSupplier,
            unitId: state.unitId,
            storeId: state.storeId,
            qty: qty,
            challanQty: Qty.tryParse(text.challanQty),
            date: state.date,
            challanNo: _clean(text.challanNo),
            vehicleNo: _clean(text.vehicleNo)?.toUpperCase(),
            photoPath: state.photo,
            title: title,
            subtitle: subtitle,
          );
        case MaterialFormKind.use:
          await repository.use(
            projectId: projectId,
            materialId: materialId,
            unitId: state.unitId,
            storeId: state.storeId,
            boqItemId: state.extraWork ? null : state.boqItemId,
            qty: qty,
            stage: state.extraWork ? null : _clean(text.stage),
            extraWork: state.extraWork ? extra : null,
            date: state.date,
            title: title,
            subtitle: subtitle,
          );
        case MaterialFormKind.count:
          await repository.count(
            projectId: projectId,
            materialId: materialId,
            unitId: state.unitId,
            storeId: state.storeId,
            counted: qty,
            date: state.date,
            remarks: _clean(text.remarks),
            title: title,
            subtitle: subtitle,
          );
      }
      return true;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => MaterialFormProblem.saveFailed));
      return false;
    }
  }
}
