import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';

/// Why a project could not be created, in terms a person can be told.
enum ProjectFormProblem { offline, planLimit, server }

class ProjectFormState extends Equatable {
  const ProjectFormState({
    this.submitting = false,
    this.problem,
    this.planMessage,
    this.fieldErrors = const <String, String>{},
    this.created,
  });

  final bool submitting;
  final ProjectFormProblem? problem;

  /// The server's own words for [ProjectFormProblem.planLimit].
  final String? planMessage;

  /// First message per API field, for the server's validation errors.
  final Map<String, String> fieldErrors;
  final ProjectRow? created;

  @override
  List<Object?> get props => <Object?>[submitting, problem, planMessage, fieldErrors, created];
}

class ProjectFormCubit extends Cubit<ProjectFormState> {
  ProjectFormCubit({required this._services}) : super(const ProjectFormState());

  final AppServices _services;

  Future<void> submit({
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    Money? contractValue,
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
