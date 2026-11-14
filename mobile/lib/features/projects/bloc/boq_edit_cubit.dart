import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show BoqItemRow;
import '../../../core/money/money.dart';
import '../data/project_repository.dart';
import 'boq_form_cubit.dart' show boqFallbackUnits;

enum BoqEditLoad { loading, ready, gone }

/// Why a save did not go through, worded by the screen in the user's language.
enum BoqEditProblem {
  codeRequired,
  descriptionRequired,
  unitRequired,
  qtyInvalid,
  rateInvalid,
  reasonRequired,
  offline,
  changed,
  gone,
  notAllowed,
  failed,
}

/// Why a delete did not go through.
enum BoqDeleteProblem { offline, unsent, ownerOnly, refused, failed }

class BoqEditState {
  const BoqEditState({
    this.load = BoqEditLoad.loading,
    this.line,
    this.online = true,
    this.units = boqFallbackUnits,
    this.unit,
    this.saving = false,
    this.problem,
    this.serverErrors = const {},
    this.serverMessage,
    this.formVersion = 0,
    this.saved = false,
    this.deleting = false,
    this.deleteProblem,
    this.refusals = const [],
    this.deleted = false,
  });

  final BoqEditLoad load;

  /// The line as the office last had it. Replaced when a save meets a newer copy.
  final BoqItemRow? line;
  final bool online;

  /// The server's units, with the line's own unit added when it is not one of them.
  final List<String> units;
  final String? unit;
  final bool saving;
  final BoqEditProblem? problem;

  /// Field errors from the server, by field name.
  final Map<String, String> serverErrors;

  /// A server message that belongs to no field.
  final String? serverMessage;

  /// Goes up when the form has to show [line] afresh, so the view refills its fields.
  final int formVersion;
  final bool saved;
  final bool deleting;
  final BoqDeleteProblem? deleteProblem;

  /// The server's reasons for refusing a delete, one per record that holds the line.
  final List<String> refusals;
  final bool deleted;

  BoqEditState copyWith({
    BoqEditLoad? load,
    BoqItemRow? line,
    bool? online,
    List<String>? units,
    String? unit,
    bool? saving,
    BoqEditProblem? problem,
    bool clearProblems = false,
    Map<String, String>? serverErrors,
    String? serverMessage,
    int? formVersion,
    bool? saved,
    bool? deleting,
    BoqDeleteProblem? deleteProblem,
    List<String>? refusals,
    bool clearDeleteProblem = false,
    bool? deleted,
  }) {
    return BoqEditState(
      load: load ?? this.load,
      line: line ?? this.line,
      online: online ?? this.online,
      units: units ?? this.units,
      unit: unit ?? this.unit,
      saving: saving ?? this.saving,
      problem: clearProblems ? null : (problem ?? this.problem),
      serverErrors: clearProblems ? const {} : (serverErrors ?? this.serverErrors),
      serverMessage: clearProblems ? null : (serverMessage ?? this.serverMessage),
      formVersion: formVersion ?? this.formVersion,
      saved: saved ?? this.saved,
      deleting: deleting ?? this.deleting,
      deleteProblem: clearDeleteProblem ? null : (deleteProblem ?? this.deleteProblem),
      refusals: clearDeleteProblem ? const [] : (refusals ?? this.refusals),
      deleted: deleted ?? this.deleted,
    );
  }
}

/// Edits one BOQ line, and removes it for the company owner. Online only: the
/// office's BOQ changes now or not at all, and every change carries a reason
/// that the BOQ history keeps.
class BoqEditCubit extends Cubit<BoqEditState> {
  BoqEditCubit(this._services, this.boqItemId)
    : canSeeMoney = _services.auth.can('money.view'),
      isOwner = _services.auth.isOwner,
      super(BoqEditState(online: _services.connectivity.isOnline)) {
    _connectivitySub = _services.connectivity.changes.listen(
      (online) => emit(state.copyWith(online: online, clearProblems: true, clearDeleteProblem: true)),
    );
    _lookupsSub = _services.projects.watchLookups().listen((lookups) {
      if (lookups.units.isNotEmpty) _setUnits(lookups.units);
    });
    unawaited(_open());
  }

  final AppServices _services;
  final int boqItemId;

  /// Rates are shown and sent only to someone who may see money.
  final bool canSeeMoney;

  /// Delete is offered to the company owner only. The server checks again.
  final bool isOwner;

  StreamSubscription<bool>? _connectivitySub;
  StreamSubscription<Lookups>? _lookupsSub;
  List<String> _serverUnits = boqFallbackUnits;

  String _code = '';
  String _description = '';
  String _qtyText = '';
  String _rateText = '';
  String _reason = '';

  ProjectRepository get _projects => _services.projects;

  Future<void> _open() async {
    final BoqItemRow? line = await _projects.getBoqItem(boqItemId);
    if (isClosed) return;
    if (line == null) {
      emit(state.copyWith(load: BoqEditLoad.gone));
      return;
    }
    _show(line, load: BoqEditLoad.ready);
  }

  /// Fills the form from [line]. The reason someone typed is kept.
  void _show(BoqItemRow line, {BoqEditLoad? load, BoqEditProblem? problem}) {
    _code = line.code;
    _description = line.description;
    _qtyText = line.qty.toWire();
    _rateText = line.rate.toWire();
    final BoqEditState shown = state.copyWith(
      load: load,
      line: line,
      unit: line.unit,
      units: _unitsWith(line.unit),
      formVersion: state.formVersion + 1,
      saving: false,
      clearProblems: true,
    );
    emit(problem == null ? shown : shown.copyWith(problem: problem));
  }

  void _setUnits(List<String> units) {
    _serverUnits = units;
    emit(state.copyWith(units: _unitsWith(state.unit)));
  }

  List<String> _unitsWith(String? unit) =>
      unit == null || _serverUnits.contains(unit) ? _serverUnits : <String>[unit, ..._serverUnits];

  String get code => _code;
  String get description => _description;
  String get qtyText => _qtyText;
  String get rateText => _rateText;
  String get reason => _reason;

  void setCode(String text) => _typed(() => _code = text);
  void setDescription(String text) => _typed(() => _description = text);
  void setQty(String text) => _typed(() => _qtyText = text);
  void setRate(String text) => _typed(() => _rateText = text);
  void setReason(String text) => _typed(() => _reason = text);
  void setUnit(String unit) => emit(state.copyWith(unit: unit, clearProblems: true));

  void _typed(void Function() change) {
    change();
    if (state.problem != null || state.serverErrors.isNotEmpty || state.serverMessage != null) {
      emit(state.copyWith(clearProblems: true));
    }
  }

  /// Checks the form, then saves it on the server. True when it was saved.
  Future<bool> save() async {
    final BoqItemRow? line = state.line;
    if (line == null || state.saving || state.deleting) return false;

    final BoqEditProblem? missing = _check();
    if (missing != null) {
      emit(state.copyWith(problem: missing, serverErrors: const {}));
      return false;
    }
    if (!_services.connectivity.isOnline) {
      emit(state.copyWith(online: false, problem: BoqEditProblem.offline));
      return false;
    }

    emit(state.copyWith(saving: true, clearProblems: true));
    try {
      await _projects.updateBoqItem(
        boqItemId: line.id,
        code: _code.trim(),
        description: _description.trim(),
        unit: state.unit!,
        qty: Qty.tryParse(_qtyText)!,
        rate: canSeeMoney ? Money.tryParse(_rateText) : null,
        reason: _reason.trim(),
        openedAt: line.updatedAt,
      );
    } on OfflineFailure {
      emit(state.copyWith(saving: false, online: false, problem: BoqEditProblem.offline));
      return false;
    } on ValidationFailure catch (failure) {
      _showServerErrors(failure);
      return false;
    } on ServerFailure catch (failure) {
      await _onServerFailure(failure);
      return false;
    } on ApiFailure catch (failure) {
      emit(state.copyWith(saving: false, problem: BoqEditProblem.failed, serverMessage: failure.message));
      return false;
    }
    emit(state.copyWith(saving: false, saved: true));
    return true;
  }

  BoqEditProblem? _check() {
    if (_code.trim().isEmpty) return BoqEditProblem.codeRequired;
    if (_description.trim().isEmpty) return BoqEditProblem.descriptionRequired;
    if (state.unit == null || state.unit!.trim().isEmpty) return BoqEditProblem.unitRequired;
    final Qty? qty = Qty.tryParse(_qtyText);
    if (qty == null || qty.isNegative || Qty.hasExtraDecimals(_qtyText)) return BoqEditProblem.qtyInvalid;
    if (canSeeMoney) {
      final Money? rate = Money.tryParse(_rateText);
      if (rate == null || rate.isNegative || Money.hasExtraDecimals(_rateText)) return BoqEditProblem.rateInvalid;
    }
    if (_reason.trim().isEmpty) return BoqEditProblem.reasonRequired;
    return null;
  }

  Future<void> _onServerFailure(ServerFailure failure) async {
    switch (failure.statusCode) {
      case 409:
        // The repository stored the office's copy: show it, keep the reason.
        final BoqItemRow? fresh = await _projects.getBoqItem(boqItemId);
        if (isClosed) return;
        if (fresh != null) {
          _show(fresh, problem: BoqEditProblem.changed);
          return;
        }
        emit(state.copyWith(saving: false, problem: BoqEditProblem.changed));
      case 404:
        emit(state.copyWith(saving: false, load: BoqEditLoad.gone, problem: BoqEditProblem.gone));
      case 403:
        emit(state.copyWith(saving: false, problem: BoqEditProblem.notAllowed));
      default:
        emit(state.copyWith(saving: false, problem: BoqEditProblem.failed, serverMessage: failure.message));
    }
  }

  /// Each message beside its own field; anything else on its own.
  void _showServerErrors(ValidationFailure failure) {
    final Map<String, String> errors = <String, String>{
      for (final entry in failure.errors.entries)
        if (entry.value.isNotEmpty) entry.key: entry.value.first,
    };
    const Set<String> fields = <String>{'code', 'description', 'unit', 'qty', 'rate', 'reason'};
    final bool onAField = errors.keys.any(fields.contains);
    emit(
      state.copyWith(
        saving: false,
        serverErrors: errors,
        serverMessage: onAField ? null : failure.message,
      ),
    );
  }

  /// Removes the line, for the company owner. [reason] is kept in the BOQ
  /// history. True when it was removed.
  Future<bool> delete(String reason) async {
    final BoqItemRow? line = state.line;
    if (line == null || reason.trim().isEmpty || state.deleting || state.saving) return false;
    if (!_services.connectivity.isOnline) {
      emit(state.copyWith(online: false, deleteProblem: BoqDeleteProblem.offline, refusals: const []));
      return false;
    }

    emit(state.copyWith(deleting: true, clearDeleteProblem: true));
    try {
      await _projects.deleteBoqItem(boqItemId: line.id, reason: reason.trim());
    } on UnsentWorkOnLine {
      emit(state.copyWith(deleting: false, deleteProblem: BoqDeleteProblem.unsent));
      return false;
    } on OfflineFailure {
      emit(state.copyWith(deleting: false, online: false, deleteProblem: BoqDeleteProblem.offline));
      return false;
    } on ValidationFailure catch (failure) {
      final List<String> reasons = failure.errors['boq'] ?? failure.errors.values.expand((m) => m).toList();
      emit(
        state.copyWith(
          deleting: false,
          deleteProblem: BoqDeleteProblem.refused,
          refusals: reasons.isEmpty ? <String>[failure.message] : reasons,
        ),
      );
      return false;
    } on ServerFailure catch (failure) {
      emit(
        state.copyWith(
          deleting: false,
          deleteProblem: failure.statusCode == 403 ? BoqDeleteProblem.ownerOnly : BoqDeleteProblem.failed,
        ),
      );
      return false;
    } on ApiFailure {
      emit(state.copyWith(deleting: false, deleteProblem: BoqDeleteProblem.failed));
      return false;
    }
    emit(state.copyWith(deleting: false, deleted: true));
    return true;
  }

  @override
  Future<void> close() async {
    await _connectivitySub?.cancel();
    await _lookupsSub?.cancel();
    return super.close();
  }
}
