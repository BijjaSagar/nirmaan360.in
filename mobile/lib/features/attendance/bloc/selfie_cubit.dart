import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show SiteRow, WorkerRow;
import '../data/selfie_repository.dart';
import 'selfie_sensors.dart';

enum SelfieStep {
  loading,
  noProject,

  /// No site to check the photo against.
  noSite,

  /// The consent note has never been loaded and there is no network to load it.
  needsNetwork,

  /// The note is shown and waits for the worker's agreement.
  consent,
  form,
}

/// Why the form cannot be sent yet, shown beside the part that is missing.
enum SelfieProblem { workerRequired, photoRequired, locationRequired, saveFailed }

class SelfieState extends Equatable {
  const SelfieState({
    this.step = SelfieStep.loading,
    this.projectCode,
    this.workers = const <WorkerRow>[],
    this.sites = const <SiteRow>[],
    this.siteId,
    this.workerId,
    this.consent,
    this.photoPath,
    this.capturedAt,
    this.fix,
    this.locating = false,
    this.locationProblem,
    this.submitting = false,
    this.problem,
    this.result,
    this.resultNumber = 0,
  });

  final SelfieStep step;
  final String? projectCode;
  final List<WorkerRow> workers;
  final List<SiteRow> sites;
  final int? siteId;
  final int? workerId;
  final SelfieConsent? consent;
  final String? photoPath;
  final DateTime? capturedAt;
  final SelfieFix? fix;
  final bool locating;
  final LocationProblem? locationProblem;
  final bool submitting;
  final SelfieProblem? problem;

  /// How the last photo ended, shown until the next worker is started.
  final SelfieOutcome? result;
  final int resultNumber;

  WorkerRow? get worker {
    for (final WorkerRow row in workers) {
      if (row.id == workerId) return row;
    }
    return null;
  }

  SelfieState copyWith({
    SelfieStep? step,
    String? projectCode,
    List<WorkerRow>? workers,
    List<SiteRow>? sites,
    int? siteId,
    int? Function()? workerId,
    SelfieConsent? consent,
    String? Function()? photoPath,
    DateTime? Function()? capturedAt,
    SelfieFix? Function()? fix,
    bool? locating,
    LocationProblem? Function()? locationProblem,
    bool? submitting,
    SelfieProblem? Function()? problem,
    SelfieOutcome? Function()? result,
    int? resultNumber,
  }) {
    return SelfieState(
      step: step ?? this.step,
      projectCode: projectCode ?? this.projectCode,
      workers: workers ?? this.workers,
      sites: sites ?? this.sites,
      siteId: siteId ?? this.siteId,
      workerId: workerId == null ? this.workerId : workerId(),
      consent: consent ?? this.consent,
      photoPath: photoPath == null ? this.photoPath : photoPath(),
      capturedAt: capturedAt == null ? this.capturedAt : capturedAt(),
      fix: fix == null ? this.fix : fix(),
      locating: locating ?? this.locating,
      locationProblem: locationProblem == null ? this.locationProblem : locationProblem(),
      submitting: submitting ?? this.submitting,
      problem: problem == null ? this.problem : problem(),
      result: result == null ? this.result : result(),
      resultNumber: resultNumber ?? this.resultNumber,
    );
  }

  @override
  List<Object?> get props => <Object?>[
    step,
    projectCode,
    workers,
    sites,
    siteId,
    workerId,
    consent?.version,
    photoPath,
    capturedAt,
    fix?.lat,
    fix?.lng,
    locating,
    locationProblem,
    submitting,
    problem,
    result?.result,
    resultNumber,
  ];
}

/// Marks a worker present from a photo taken at the site.
///
/// The consent note is shown, and agreed to, before the first photo, and again when
/// the server's version of it changes. The location is read once, when the photo is
/// taken, never before and never in the background.
class SelfieCubit extends Cubit<SelfieState> {
  SelfieCubit(this._services) : super(const SelfieState()) {
    unawaited(_load());
  }

  final AppServices _services;
  int? _projectId;

  SelfieRepository get _repo => _services.selfies;

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    _projectId = projectId;
    if (projectId == null) {
      if (!isClosed) emit(state.copyWith(step: SelfieStep.noProject));
      return;
    }
    final project = await _services.projects.getProject(projectId);
    final List<SiteRow> sites = await _repo.readSites(projectId);
    final List<WorkerRow> workers = await _repo.readWorkers();
    if (isClosed) return;
    emit(
      state.copyWith(
        projectCode: project?.code,
        sites: sites,
        workers: workers,
        siteId: sites.length == 1 ? sites.single.id : null,
        step: sites.isEmpty ? SelfieStep.noSite : SelfieStep.loading,
      ),
    );
    if (sites.isEmpty) return;
    await _loadConsent();
  }

  /// The note from the server, or the last copy kept. The first photo needs one or the other.
  Future<void> _loadConsent() async {
    final SelfieConsent? consent = await _repo.loadConsent();
    if (isClosed) return;
    if (consent == null) {
      emit(state.copyWith(step: SelfieStep.needsNetwork));
      return;
    }
    final bool agreed = await _repo.hasAccepted(consent);
    if (isClosed) return;
    emit(state.copyWith(consent: consent, step: agreed ? SelfieStep.form : SelfieStep.consent));
  }

  Future<void> retryConsent() async {
    emit(state.copyWith(step: SelfieStep.loading));
    await _loadConsent();
  }

  /// The worker agrees. Remembered for this person and this version of the note.
  Future<void> agree() async {
    final SelfieConsent? consent = state.consent;
    if (consent == null) return;
    await _repo.accept(consent);
    if (!isClosed) emit(state.copyWith(step: SelfieStep.form));
  }

  void setWorker(int id) => emit(state.copyWith(workerId: () => id, problem: () => null, result: () => null));

  void setSite(int id) => emit(state.copyWith(siteId: id));

  /// Takes the photo, and then, at that moment, reads the location. A photo with no
  /// location cannot be checked, so it stays on the screen with the reason and a way to take it again.
  Future<void> takePhoto() async {
    if (state.locating || state.submitting) return;
    final String? path = await _services.photos.capture();
    if (path == null || isClosed) return;
    emit(
      state.copyWith(
        photoPath: () => path,
        capturedAt: () => _services.context.clock.now(),
        fix: () => null,
        locating: true,
        locationProblem: () => null,
        problem: () => null,
        result: () => null,
      ),
    );
    final LocationResult found = await const SelfieLocator().locate();
    if (isClosed) return;
    emit(state.copyWith(locating: false, fix: () => found.fix, locationProblem: () => found.problem));
  }

  void clearPhoto() => emit(
    state.copyWith(photoPath: () => null, capturedAt: () => null, fix: () => null, locationProblem: () => null, problem: () => null),
  );

  /// True when the worker is marked, or the photo is safe on this phone.
  Future<bool> submit() async {
    final int? projectId = _projectId;
    final int? siteId = state.siteId;
    final WorkerRow? worker = state.worker;
    final SelfieConsent? consent = state.consent;
    if (projectId == null || siteId == null || consent == null || state.submitting) return false;
    if (worker == null) {
      emit(state.copyWith(problem: () => SelfieProblem.workerRequired));
      return false;
    }
    final String? photo = state.photoPath;
    if (photo == null) {
      emit(state.copyWith(problem: () => SelfieProblem.photoRequired));
      return false;
    }
    final SelfieFix? fix = state.fix;
    final DateTime? at = state.capturedAt;
    if (fix == null || at == null) {
      emit(state.copyWith(problem: () => SelfieProblem.locationRequired));
      return false;
    }

    emit(state.copyWith(submitting: true, problem: () => null));
    try {
      final SelfieOutcome outcome = await _repo.submit(
        projectId: projectId,
        siteId: siteId,
        worker: worker,
        photoPath: photo,
        fix: fix,
        capturedAt: at,
        consent: consent,
      );
      if (isClosed) return false;
      // A photo that was marked or kept is done with. A refusal keeps the form, so the
      // person can move and take it again.
      final bool done = outcome.result != SelfieResult.refused;
      emit(
        state.copyWith(
          submitting: false,
          result: () => outcome,
          resultNumber: state.resultNumber + 1,
          workerId: done ? () => null : null,
          photoPath: done ? () => null : null,
          capturedAt: done ? () => null : null,
          fix: done ? () => null : null,
        ),
      );
      return done;
    } on Object {
      if (!isClosed) emit(state.copyWith(submitting: false, problem: () => SelfieProblem.saveFailed));
      return false;
    }
  }
}
