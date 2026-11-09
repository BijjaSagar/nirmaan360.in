import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/failures.dart';
import '../../projects/data/project_repository.dart';
import '../data/measurement_repository.dart';
import 'measurement_book.dart';

enum DimField { nos, length, breadth, depth }

/// Why the entry cannot be saved yet, in words the screen can translate.
enum FormProblem { noLine, nosRequired, notPositive, tooLarge, futureDate, locked, unknown }

/// What happened when the user pressed Save or Send.
enum FormResult { ignored, invalid, saved, sent, failed }

/// A row saved in this sitting, still on the form's tally.
class SavedRow extends Equatable {
  const SavedRow(this.clientUuid, this.qty);

  final String clientUuid;
  final Qty qty;

  @override
  List<Object?> get props => [clientUuid, qty];
}

class MeasurementFormState extends Equatable {
  const MeasurementFormState({
    required this.measuredOn,
    required this.online,
    this.clientUuid,
    this.loading = true,
    this.locked = false,
    this.projectId,
    this.tolerancePct = '5.00',
    this.line,
    this.dims = const {},
    this.active = DimField.nos,
    this.isDeduction = false,
    this.location = '',
    this.description = '',
    this.remarks,
    this.returnRemark,
    this.originalStatus,
    this.countedQty,
    this.saved = const [],
    this.busy = false,
    this.problem,
  });

  /// Null when creating; the entry's uuid when editing.
  final String? clientUuid;
  final bool loading;

  /// The entry being edited can no longer be changed.
  final bool locked;
  final int? projectId;
  final String tolerancePct;
  final BoqLine? line;

  /// The strings exactly as typed. A blank key is a blank dimension.
  final Map<DimField, String> dims;
  final DimField active;
  final bool isDeduction;
  final String measuredOn;
  final String location;
  final String description;
  final String? remarks;
  final String? returnRemark;
  final String? originalStatus;

  /// When editing, the quantity already inside the line's preview balance.
  final Qty? countedQty;
  final List<SavedRow> saved;
  final bool busy;
  final FormProblem? problem;
  final bool online;

  bool get isEditing => clientUuid != null;

  String text(DimField field) => dims[field] ?? '';

  String get activeText => text(active);

  Qty? _parsed(DimField field) {
    final String raw = text(field);
    if (raw.isEmpty) return null;
    return Qty.tryParse(raw.endsWith('.') ? raw.substring(0, raw.length - 1) : raw);
  }

  Qty? get nos => _parsed(DimField.nos);
  Qty? get length => _parsed(DimField.length);
  Qty? get breadth => _parsed(DimField.breadth);
  Qty? get depth => _parsed(DimField.depth);

  bool get hasAnyDims => dims.values.any((value) => value.isNotEmpty);

  /// The live figure. Without a Nos there is nothing to multiply yet, even
  /// though the server's formula would count a missing Nos as one.
  Qty get qty {
    final Qty? count = nos;
    if (count == null) return Qty.zero;
    return computeMeasurementQty(count, length, breadth, depth, isDeduction);
  }

  String? get unit => line?.item.unit;

  /// `2 × 10 × 5 × 0.5 = 50.000 cum`, or null until there is a Nos.
  String? get formula => bookFormula(
    nos: nos,
    length: length,
    breadth: breadth,
    depth: depth,
    deduction: isDeduction,
    result: qty,
    unit: unit,
  );

  /// The same line without its result, where space is short.
  String? get factors => bookFactors(nos: nos, length: length, breadth: breadth, depth: depth, deduction: isDeduction);

  /// The balance once this entry counts. Local preview only.
  Qty? get balanceAfter {
    final BoqLine? boq = line;
    if (boq == null) return null;
    return boq.previewBalance + (countedQty ?? Qty.zero) - qty;
  }

  bool get deviation {
    final BoqLine? boq = line;
    if (boq == null) return false;
    return isDeviation(
      boqQty: boq.item.qty,
      approvedQty: boq.item.approvedQty,
      entryQty: qty,
      tolerancePct: tolerancePct,
    );
  }

  /// Net of the rows saved in this sitting.
  Qty get savedNet => saved.fold(Qty.zero, (sum, row) => sum + row.qty);

  MeasurementFormState copyWith({
    bool? loading,
    bool? locked,
    int? projectId,
    String? tolerancePct,
    BoqLine? line,
    Map<DimField, String>? dims,
    DimField? active,
    bool? isDeduction,
    String? measuredOn,
    String? location,
    String? description,
    String? remarks,
    String? returnRemark,
    String? originalStatus,
    Qty? countedQty,
    List<SavedRow>? saved,
    bool? busy,
    Object? problem = _keep,
    bool? online,
  }) {
    return MeasurementFormState(
      clientUuid: clientUuid,
      measuredOn: measuredOn ?? this.measuredOn,
      online: online ?? this.online,
      loading: loading ?? this.loading,
      locked: locked ?? this.locked,
      projectId: projectId ?? this.projectId,
      tolerancePct: tolerancePct ?? this.tolerancePct,
      line: line ?? this.line,
      dims: dims ?? this.dims,
      active: active ?? this.active,
      isDeduction: isDeduction ?? this.isDeduction,
      location: location ?? this.location,
      description: description ?? this.description,
      remarks: remarks ?? this.remarks,
      returnRemark: returnRemark ?? this.returnRemark,
      originalStatus: originalStatus ?? this.originalStatus,
      countedQty: countedQty ?? this.countedQty,
      saved: saved ?? this.saved,
      busy: busy ?? this.busy,
      problem: identical(problem, _keep) ? this.problem : problem as FormProblem?,
    );
  }

  static const Object _keep = Object();

  @override
  List<Object?> get props => [
    clientUuid,
    loading,
    locked,
    projectId,
    tolerancePct,
    line,
    dims,
    active,
    isDeduction,
    measuredOn,
    location,
    description,
    remarks,
    returnRemark,
    originalStatus,
    countedQty,
    saved,
    busy,
    problem,
    online,
  ];
}

class MeasurementFormCubit extends Cubit<MeasurementFormState> {
  MeasurementFormCubit({required AppServices services, String? clientUuid})
    : _services = services,
      super(
        MeasurementFormState(
          clientUuid: clientUuid,
          measuredOn: services.context.today,
          online: services.connectivity.isOnline,
          loading: clientUuid != null,
        ),
      );

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _project;
  StreamSubscription<SyncedRecord<MeasurementRow>?>? _entry;
  StreamSubscription<BoqLine?>? _line;
  StreamSubscription<bool>? _connectivity;

  /// The server refuses anything above this for Nos, L, B and D.
  static const int _maxDimension = 99999999;

  /// The largest figure the server's quantity column can hold.
  static final Qty _maxQuantity = Qty.parse('99999999999.999');

  MeasurementRepository get _repo => _services.measurements;

  /// Starts watching. Offline-safe: nothing here needs the network.
  void start() {
    _connectivity = _services.connectivity.changes.listen((online) => _set(state.copyWith(online: online)));
    final String? uuid = state.clientUuid;
    if (uuid == null) {
      _project = _services.projects.watchCurrentProject().listen(_onProject);
      return;
    }
    _entry = _repo.watchOne(uuid).listen(_onEntry);
  }

  void _onProject(ProjectRow? project) {
    if (project == null) return;
    _set(state.copyWith(projectId: project.id, tolerancePct: project.measurementTolerancePct));
  }

  /// Takes the entry once. A form that changed under the user's thumb, as a
  /// sync lands, would be worse than one that is a moment out of date.
  Future<void> _onEntry(SyncedRecord<MeasurementRow>? synced) async {
    unawaited(_entry?.cancel());
    _entry = null;
    if (isClosed || !state.loading) return;
    final MeasurementRow? entry = synced?.record;
    if (entry == null || (entry.status != DocStatus.draft && entry.status != DocStatus.returned)) {
      _set(state.copyWith(loading: false, locked: true, originalStatus: entry?.status));
      return;
    }
    final ProjectRow? project = await _services.projects.getProject(entry.projectId);
    if (isClosed) return;

    String typed(Qty? value) => value == null ? '' : bookFigure(value);

    _set(
      state.copyWith(
        loading: false,
        projectId: entry.projectId,
        tolerancePct: project?.measurementTolerancePct,
        measuredOn: entry.measuredOn,
        isDeduction: entry.isDeduction,
        location: entry.location ?? '',
        description: entry.description ?? '',
        remarks: entry.remarks,
        returnRemark: entry.returnRemark,
        originalStatus: entry.status,
        countedQty: entry.qty,
        dims: {
          DimField.nos: typed(entry.nos),
          DimField.length: typed(entry.length),
          DimField.breadth: typed(entry.breadth),
          DimField.depth: typed(entry.depth),
        },
      ),
    );
    _watchLine(entry.boqItemId);
  }

  void _watchLine(int boqItemId) {
    _line?.cancel();
    _line = _services.projects.watchBoqLine(boqItemId).listen((line) {
      if (line != null) _set(state.copyWith(line: line));
    });
  }

  void selectLine(BoqLine line) {
    _set(state.copyWith(line: line, problem: null));
    _watchLine(line.item.id);
  }

  void setActive(DimField field) => _set(state.copyWith(active: field, problem: null));

  /// Takes the keypad's new string for the active tile.
  void typed(String value) {
    _set(state.copyWith(dims: {...state.dims, state.active: value}, problem: null));
  }

  /// Nos → L → B → D. Stops at D: Save is what closes a row.
  void next() {
    if (state.active == DimField.depth) return;
    _set(state.copyWith(active: DimField.values[state.active.index + 1], problem: null));
  }

  void setDeduction(bool deduction) {
    _set(state.copyWith(isDeduction: deduction, problem: null));
  }

  void setDetails({required String location, required String description, required String measuredOn}) {
    _set(state.copyWith(location: location, description: description, measuredOn: measuredOn, problem: null));
  }

  FormProblem? _findProblem(MeasurementFormState s) {
    if (s.line == null || s.projectId == null) return FormProblem.noLine;
    if (s.text(DimField.nos).isEmpty) return FormProblem.nosRequired;
    final FormProblem? bad = _badDimension(s)?.$2;
    if (bad != null) return bad;
    return s.qty.abs() > _maxQuantity ? FormProblem.tooLarge : null;
  }

  /// The first tile holding a value the server would refuse.
  (DimField, FormProblem)? _badDimension(MeasurementFormState s) {
    for (final DimField field in DimField.values) {
      if (s.text(field).isEmpty) continue;
      final Qty? value = switch (field) {
        DimField.nos => s.nos,
        DimField.length => s.length,
        DimField.breadth => s.breadth,
        DimField.depth => s.depth,
      };
      if (value == null || value <= Qty.zero) return (field, FormProblem.notPositive);
      if (value > Qty.fromInt(_maxDimension)) return (field, FormProblem.tooLarge);
    }
    return null;
  }

  MeasurementFormState _focusProblem(FormProblem problem) {
    final DimField? field = switch (problem) {
      FormProblem.nosRequired => DimField.nos,
      _ => _badDimension(state)?.$1,
    };
    return state.copyWith(problem: problem, active: field);
  }

  MeasurementInput _input() {
    final MeasurementFormState s = state;
    String? optional(String value) {
      final String trimmed = value.trim();
      return trimmed.isEmpty ? null : trimmed;
    }

    return MeasurementInput(
      projectId: s.projectId!,
      boqItemId: s.line!.item.id,
      measuredOn: s.measuredOn,
      location: optional(s.location),
      description: optional(s.description),
      nos: s.nos!,
      length: s.length,
      breadth: s.breadth,
      depth: s.depth,
      isDeduction: s.isDeduction,
      remarks: s.remarks,
    );
  }

  /// Saves the row on the phone. A new entry keeps its BOQ line and clears the
  /// dimensions for the next row, because a line is usually several rows.
  Future<FormResult> save() async {
    if (state.busy || state.loading) return FormResult.ignored;
    final FormProblem? problem = _findProblem(state);
    if (problem != null) {
      _set(_focusProblem(problem));
      return FormResult.invalid;
    }

    final Qty qty = state.qty;
    _set(state.copyWith(busy: true, problem: null));
    try {
      final String? uuid = state.clientUuid;
      if (uuid != null) {
        await _repo.edit(uuid, _input());
        _set(state.copyWith(busy: false));
        return FormResult.saved;
      }
      final String created = await _repo.create(_input());
      _set(
        state.copyWith(
          busy: false,
          dims: const {},
          active: DimField.nos,
          saved: [...state.saved, SavedRow(created, qty)],
        ),
      );
      return FormResult.saved;
    } catch (failure) {
      _fail(failure);
      return FormResult.failed;
    }
  }

  /// Sends the entry, and any rows saved in this sitting, for approval.
  Future<FormResult> send() async {
    if (state.busy || state.loading) return FormResult.ignored;

    final bool nothingTyped = !state.hasAnyDims;
    if (nothingTyped && !state.isEditing) {
      if (state.saved.isNotEmpty) return _sendSaved();
      _set(_focusProblem(_findProblem(state) ?? FormProblem.nosRequired));
      return FormResult.invalid;
    }

    final FormResult saved = await save();
    if (saved != FormResult.saved) return saved;
    return _sendSaved();
  }

  Future<FormResult> _sendSaved() async {
    final String? editing = state.clientUuid;
    final List<String> uuids = editing != null ? [editing] : [for (final SavedRow row in state.saved) row.clientUuid];
    _set(state.copyWith(busy: true, problem: null));
    try {
      for (final String uuid in uuids) {
        await _repo.sendForApproval(uuid);
      }
      _set(state.copyWith(busy: false, saved: const []));
      return FormResult.sent;
    } catch (failure) {
      _fail(failure);
      return FormResult.failed;
    }
  }

  void _fail(Object failure) {
    final FormProblem problem = switch (failure) {
      RecordLocked() => FormProblem.locked,
      ValidationFailure(errors: final errors) when errors.containsKey('measured_on') => FormProblem.futureDate,
      ValidationFailure() => FormProblem.notPositive,
      _ => FormProblem.unknown,
    };
    _set(state.copyWith(busy: false, problem: problem));
  }

  void _set(MeasurementFormState next) {
    if (!isClosed) emit(next);
  }

  @override
  Future<void> close() async {
    await _project?.cancel();
    await _entry?.cancel();
    await _line?.cancel();
    await _connectivity?.cancel();
    return super.close();
  }
}
