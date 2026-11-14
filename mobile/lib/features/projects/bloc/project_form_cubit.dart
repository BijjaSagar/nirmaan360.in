import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../data/project_detail.dart';

/// Why a project could not be created, read or changed, in terms a person can be told.
enum ProjectFormProblem { offline, planLimit, server }

class ProjectFormState extends Equatable {
  const ProjectFormState({
    this.submitting = false,
    this.problem,
    this.planMessage,
    this.fieldErrors = const <String, String>{},
    this.created,
    this.loading = false,
    this.loadProblem,
    this.detail,
    this.saved,
  });

  final bool submitting;
  final ProjectFormProblem? problem;

  /// The server's own words for [ProjectFormProblem.planLimit].
  final String? planMessage;

  /// First message per API field, for the server's validation errors.
  final Map<String, String> fieldErrors;
  final ProjectRow? created;

  /// Editing: the project is being read from the server.
  final bool loading;

  /// Editing: why the project could not be read. The form stays closed.
  final ProjectFormProblem? loadProblem;

  /// Editing: the project as the server has it. Null until read.
  final ProjectDetail? detail;

  /// Editing: the project as saved, once the change went through.
  final ProjectDetail? saved;

  ProjectFormState _copy({
    bool? submitting,
    ProjectFormProblem? problem,
    Map<String, String>? fieldErrors,
    bool? loading,
    ProjectFormProblem? loadProblem,
    ProjectDetail? detail,
    ProjectDetail? saved,
  }) =>
      ProjectFormState(
        submitting: submitting ?? false,
        problem: problem,
        fieldErrors: fieldErrors ?? const <String, String>{},
        loading: loading ?? false,
        loadProblem: loadProblem,
        detail: detail ?? this.detail,
        saved: saved,
      );

  @override
  List<Object?> get props =>
      <Object?>[submitting, problem, planMessage, fieldErrors, created, loading, loadProblem, detail, saved];
}

/// The new-project form, and with a [projectId] the same form changing that
/// project. Both need network: the server names the project, enforces the
/// plan's limit and keeps a phased value equal to its phases.
class ProjectFormCubit extends Cubit<ProjectFormState> {
  ProjectFormCubit({required this._services, this.projectId})
      : super(ProjectFormState(loading: projectId != null));

  final AppServices _services;

  /// The project being changed. Null for a new one.
  final int? projectId;

  bool get isEdit => projectId != null;

  /// Editing: reads the project, again after a failure.
  Future<void> load() async {
    final int? id = projectId;
    if (id == null) return;
    emit(state._copy(loading: true));
    ProjectFormState outcome;
    try {
      outcome = state._copy(detail: await _services.projects.loadProject(id));
    } on OfflineFailure {
      outcome = state._copy(loadProblem: ProjectFormProblem.offline);
    } on Exception {
      outcome = state._copy(loadProblem: ProjectFormProblem.server);
    }
    if (!isClosed) emit(outcome);
  }

  Future<void> submit({
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    Money? contractValue,
    List<ValuePhase>? phases,
  }) async {
    if (state.submitting) return;
    emit(const ProjectFormState(submitting: true));
    ProjectFormState outcome;
    try {
      final ProjectRow row = await _services.projects.createProject(
        name: name,
        location: location,
        startDate: startDate,
        endDate: endDate,
        contractValue: contractValue,
        phases: phases,
      );
      outcome = ProjectFormState(created: row);
    } on OfflineFailure {
      outcome = const ProjectFormState(problem: ProjectFormProblem.offline);
    } on ValidationFailure catch (failure) {
      outcome = _fromValidation(failure);
    } on Exception {
      outcome = const ProjectFormState(problem: ProjectFormProblem.server);
    }
    if (!isClosed) emit(outcome);
  }

  /// Editing: sends the change. Money goes only when the server showed it,
  /// which is when this person may change it.
  Future<void> save({
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    Money? contractValue,
    List<ValuePhase> phases = const <ValuePhase>[],
  }) async {
    final int? id = projectId;
    final ProjectDetail? detail = state.detail;
    if (id == null || detail == null || state.submitting) return;
    emit(state._copy(submitting: true));
    ProjectFormState outcome;
    try {
      final ProjectDetail saved = await _services.projects.updateProject(
        id,
        name: name,
        location: location,
        startDate: startDate,
        endDate: endDate,
        withMoney: detail.seesMoney,
        contractValue: contractValue,
        phases: phases,
      );
      outcome = state._copy(detail: saved, saved: saved);
    } on OfflineFailure {
      outcome = state._copy(problem: ProjectFormProblem.offline);
    } on ValidationFailure catch (failure) {
      final ProjectFormState fields = _fromValidation(failure);
      outcome = state._copy(problem: fields.problem, fieldErrors: fields.fieldErrors);
    } on Exception {
      outcome = state._copy(problem: ProjectFormProblem.server);
    }
    if (!isClosed) emit(outcome);
  }

  ProjectFormState _fromValidation(ValidationFailure failure) {
    final List<String>? plan = failure.errors['plan'];
    if (plan != null && plan.isNotEmpty) {
      return ProjectFormState(problem: ProjectFormProblem.planLimit, planMessage: plan.first);
    }
    final Map<String, String> fields = <String, String>{
      for (final entry in failure.errors.entries)
        if (entry.value.isNotEmpty) entry.key: entry.value.first,
    };
    if (fields.isEmpty) return const ProjectFormState(problem: ProjectFormProblem.server);
    return ProjectFormState(fieldErrors: fields);
  }
}
