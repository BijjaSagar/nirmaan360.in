import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../../core/money/money.dart';
import '../data/project_repository.dart';

/// Which figure the keypad is typing into.
enum BoqFigure { qty, rate }

/// A problem the form words itself, in the user's language.
enum BoqFormProblem { descriptionRequired, unitRequired, qtyRequired, rateRequired, offline, failed }

/// Used when the phone has never pulled the server's list of units.
const List<String> boqFallbackUnits = <String>['cum', 'sqm', 'rmt', 'nos', 'kg', 'MT', 'ltr', 'bag', 'LS'];

class BoqFormState {
  const BoqFormState({
    this.online = true,
    this.project,
    this.units = boqFallbackUnits,
    this.sections = const [],
    this.step = 0,
    this.unit,
    this.qtyText = '',
    this.rateText = '',
    this.active = BoqFigure.qty,
    this.saving = false,
    this.problem,
    this.serverErrors = const {},
    this.serverMessage,
    this.addedCount = 0,
    this.formVersion = 0,
    this.done = false,
  });

  final bool online;
  final ProjectRow? project;
  final List<String> units;

  /// Section names already on this BOQ, for the suggestions.
  final List<String> sections;

  /// 0 for what the line is, 1 for its quantity and rate.
  final int step;
  final String? unit;

  /// What the keypad holds. Parsed through `Qty` and `Money`, never `double`.
  final String qtyText;
  final String rateText;
  final BoqFigure active;
  final bool saving;
  final BoqFormProblem? problem;

  /// Field errors from the server, by field name.
  final Map<String, String> serverErrors;

  /// A server message that belongs to no field.
  final String? serverMessage;

  /// Lines saved since this form opened.
  final int addedCount;

  /// Goes up each time a line is saved, so the view can clear its text fields.
  final int formVersion;
  final bool done;

  Qty? get qty => Qty.tryParse(qtyText);
  Money? get rate => Money.tryParse(rateText);

  /// Quantity times rate. Exact decimal arithmetic, never `double`.
  Money get amount {
    final Qty? q = qty;
    final Money? r = rate;
    return q == null || r == null ? Money.zero : Money.fromQtyRate(q, r);
  }

  String get activeText => active == BoqFigure.qty ? qtyText : rateText;

  BoqFormState copyWith({
    bool? online,
    ProjectRow? project,
    List<String>? units,
    List<String>? sections,
    int? step,
    String? unit,
    bool clearUnit = false,
    String? qtyText,
    String? rateText,
    BoqFigure? active,
    bool? saving,
    BoqFormProblem? problem,
    bool clearProblems = false,
    Map<String, String>? serverErrors,
    String? serverMessage,
    int? addedCount,
    int? formVersion,
    bool? done,
  }) {
    return BoqFormState(
      online: online ?? this.online,
      project: project ?? this.project,
      units: units ?? this.units,
      sections: sections ?? this.sections,
      step: step ?? this.step,
      unit: clearUnit ? null : (unit ?? this.unit),
      qtyText: qtyText ?? this.qtyText,
      rateText: rateText ?? this.rateText,
      active: active ?? this.active,
      saving: saving ?? this.saving,
      problem: clearProblems ? null : (problem ?? this.problem),
      serverErrors: clearProblems ? const {} : (serverErrors ?? this.serverErrors),
      serverMessage: clearProblems ? null : (serverMessage ?? this.serverMessage),
      addedCount: addedCount ?? this.addedCount,
      formVersion: formVersion ?? this.formVersion,
      done: done ?? this.done,
    );
  }
}

/// Adds BOQ lines, online only. "Save and add another" keeps the section and
/// clears the rest, because a BOQ is many lines.
class BoqFormCubit extends Cubit<BoqFormState> {
  BoqFormCubit(this._services)
    : super(BoqFormState(online: _services.connectivity.isOnline)) {
    _connectivitySub = _services.connectivity.changes.listen(
      (online) => emit(state.copyWith(online: online, clearProblems: true)),
    );
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
    _lookupsSub = _services.projects.watchLookups().listen((lookups) {
      if (lookups.units.isNotEmpty) emit(state.copyWith(units: lookups.units));
    });
  }

  final AppServices _services;
  StreamSubscription<bool>? _connectivitySub;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<Lookups>? _lookupsSub;
  StreamSubscription<List<BoqLine>>? _linesSub;

  String _description = '';
  String _section = '';
  String _code = '';

  void _onProject(ProjectRow? project) {
    if (project == null || project.id == state.project?.id) return;
    emit(state.copyWith(project: project));
    _linesSub?.cancel();
    _linesSub = _services.projects.watchBoqItems(project.id).listen((lines) {
      final names = <String>[];
      for (final BoqLine line in lines) {
        final String name = line.item.section?.trim() ?? '';
        if (name.isNotEmpty && !names.contains(name)) names.add(name);
      }
      emit(state.copyWith(sections: names));
    });
  }

  void setDescription(String text) {
    _description = text;
    _clearProblems();
  }

  void setSection(String text) => _section = text;

  void setCode(String text) => _code = text;

  void setUnit(String unit) => emit(state.copyWith(unit: unit, clearProblems: true));

  void _clearProblems() {
    if (state.problem != null || state.serverErrors.isNotEmpty || state.serverMessage != null) {
      emit(state.copyWith(clearProblems: true));
    }
  }

  /// Moves to quantity and rate, or says what is missing.
  void next() {
    if (_description.trim().isEmpty) {
      emit(state.copyWith(problem: BoqFormProblem.descriptionRequired));
      return;
    }
    if (state.unit == null) {
      emit(state.copyWith(problem: BoqFormProblem.unitRequired));
      return;
    }
    emit(state.copyWith(step: 1, active: BoqFigure.qty, clearProblems: true));
  }

  void backToDetails() => emit(state.copyWith(step: 0, clearProblems: true));

  void selectFigure(BoqFigure figure) => emit(state.copyWith(active: figure));

  /// The keypad's Next key: quantity, then rate, then back round.
  void nextFigure() {
    emit(state.copyWith(active: state.active == BoqFigure.qty ? BoqFigure.rate : BoqFigure.qty));
  }

  void setActiveText(String text) {
    emit(
      state.active == BoqFigure.qty
          ? state.copyWith(qtyText: text, clearProblems: true)
          : state.copyWith(rateText: text, clearProblems: true),
    );
  }

  /// Saves the line on the server. With [addAnother] the form starts again and
  /// keeps the section; otherwise the screen is finished.
  Future<bool> save({required bool addAnother}) async {
    if (state.saving) return false;
    final project = state.project;
    final String description = _description.trim();
    final String? unit = state.unit;
    if (project == null) return false;
    if (description.isEmpty) {
      emit(state.copyWith(step: 0, problem: BoqFormProblem.descriptionRequired));
      return false;
    }
    if (unit == null) {
      emit(state.copyWith(step: 0, problem: BoqFormProblem.unitRequired));
      return false;
    }
    final Qty? qty = state.qty;
    final Money? rate = state.rate;
    if (qty == null) {
      emit(state.copyWith(step: 1, active: BoqFigure.qty, problem: BoqFormProblem.qtyRequired));
      return false;
    }
    if (rate == null) {
      emit(state.copyWith(step: 1, active: BoqFigure.rate, problem: BoqFormProblem.rateRequired));
      return false;
    }

    emit(state.copyWith(saving: true, clearProblems: true));
    try {
      await _services.projects.addBoqItem(
        projectId: project.id,
        description: description,
        unit: unit,
        qty: qty,
        rate: rate,
        code: _blankToNull(_code),
        section: _blankToNull(_section),
      );
    } on OfflineFailure {
      emit(state.copyWith(saving: false, online: false, problem: BoqFormProblem.offline));
      return false;
    } on ValidationFailure catch (failure) {
      _showServerErrors(failure);
      return false;
    } on ApiFailure catch (failure) {
      emit(state.copyWith(saving: false, problem: BoqFormProblem.failed, serverMessage: failure.message));
      return false;
    }

    final int added = state.addedCount + 1;
    if (!addAnother) {
      emit(state.copyWith(saving: false, addedCount: added, done: true));
      return true;
    }
    _description = '';
    _code = '';
    emit(
      state.copyWith(
        saving: false,
        step: 0,
        clearUnit: true,
        qtyText: '',
        rateText: '',
        active: BoqFigure.qty,
        addedCount: added,
        formVersion: state.formVersion + 1,
      ),
    );
    return true;
  }

  /// Shows each message beside its own field, and returns to the step that
  /// field is on. Anything the form has no place for is shown on its own.
  void _showServerErrors(ValidationFailure failure) {
    final errors = <String, String>{
      for (final entry in failure.errors.entries)
        if (entry.value.isNotEmpty) entry.key: entry.value.first,
    };
    const onDetails = <String>{'description', 'unit', 'section', 'code'};
    const onFigures = <String>{'qty', 'rate'};
    final bool hasField = errors.keys.any((k) => onDetails.contains(k) || onFigures.contains(k));
    emit(
      state.copyWith(
        saving: false,
        step: errors.keys.any(onDetails.contains) ? 0 : state.step,
        active: errors.containsKey('rate') && !errors.containsKey('qty') ? BoqFigure.rate : null,
        serverErrors: errors,
        serverMessage: hasField ? null : failure.message,
      ),
    );
  }

  static String? _blankToNull(String text) {
    final String trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  @override
  Future<void> close() async {
    await _connectivitySub?.cancel();
    await _projectSub?.cancel();
    await _lookupsSub?.cancel();
    await _linesSub?.cancel();
    return super.close();
  }
}
