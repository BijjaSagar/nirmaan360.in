import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../data/inspection_repository.dart';

enum InspectionsLoad { loading, noProject, ready }

class InspectionsState extends Equatable {
  const InspectionsState({
    this.load = InspectionsLoad.loading,
    this.project,
    this.overview,
    this.drafts = const <InspectionDraft>[],
    this.refreshing = false,
  });

  final InspectionsLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project once.
  final InspectionOverview? overview;

  /// Inspections being filled in on this phone, newest first.
  final List<InspectionDraft> drafts;
  final bool refreshing;

  /// Something can be started from: a checklist is on the phone, and the
  /// server has not refused this role.
  bool get canStart {
    final InspectionOverview? seen = overview;
    return seen != null &&
        !seen.refused &&
        seen.templates.any((ChecklistTemplate row) => row.items.isNotEmpty);
  }

  InspectionsState copyWith({
    InspectionsLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    InspectionOverview? Function()? overview,
    List<InspectionDraft>? drafts,
    bool? refreshing,
  }) {
    return InspectionsState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      overview: overview == null ? this.overview : overview(),
      drafts: drafts ?? this.drafts,
      refreshing: refreshing ?? this.refreshing,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, project?.id, overview, drafts, refreshing];
}

/// The last copy on this phone, with finished inspections still waiting laid over
/// it and the drafts beside, refreshed from the server whenever it can be reached.
class InspectionsCubit extends Cubit<InspectionsState> {
  InspectionsCubit(this._services) : super(const InspectionsState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<InspectionOverview?>? _cacheSub;
  StreamSubscription<List<OutboxRow>>? _unsentSub;
  StreamSubscription<List<InspectionDraft>>? _draftSub;

  InspectionOverview? _cached;
  List<OutboxRow> _unsent = const <OutboxRow>[];
  List<InspectionDraft> _drafts = const <InspectionDraft>[];

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _unsentSub?.cancel();
      _draftSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: InspectionsLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _unsentSub?.cancel();
    _draftSub?.cancel();
    _cacheSub = _services.inspections.watchCached(project.id).listen((cached) {
      _cached = cached;
      _publish();
    });
    _unsentSub = _services.inspections.watchUnsent(project.id).listen((rows) {
      _unsent = rows;
      _publish();
    });
    _draftSub = _services.inspections.watchDrafts(project.id).listen((drafts) {
      _drafts = drafts;
      _publish();
    });
    unawaited(refresh());
  }

  void _publish() {
    if (isClosed) return;
    emit(
      state.copyWith(
        load: InspectionsLoad.ready,
        overview: () => _cached?.withUnsent(_unsent),
        drafts: _drafts,
      ),
    );
  }

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await _services.inspections.refresh(projectId);
      return true;
    } on ApiFailure {
      return false;
    } finally {
      if (!isClosed) emit(state.copyWith(refreshing: false));
    }
  }

  /// Starts an inspection from a checklist and returns the uuid of its draft.
  /// Null when the checklist is not on the phone any more.
  Future<String?> start(int templateId) async {
    final int? projectId = state.project?.id;
    final ChecklistTemplate? template = state.overview?.template(templateId);
    if (projectId == null || template == null || template.items.isEmpty) return null;
    final InspectionDraft draft = await _services.inspections.startDraft(projectId: projectId, template: template);
    return draft.uuid;
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _cacheSub?.cancel();
    await _unsentSub?.cancel();
    await _draftSub?.cancel();
    return super.close();
  }
}

class InspectionDetailState extends Equatable {
  const InspectionDetailState({this.loaded = false, this.inspection, this.refused = false});

  final bool loaded;
  final Inspection? inspection;

  /// The server said this role or plan does not have inspections.
  final bool refused;

  @override
  List<Object?> get props => <Object?>[loaded, inspection, refused];
}

/// One inspection, by the server's id or by the uuid of one waiting to be sent.
/// An inspection from the server shows its items from the copy on the phone,
/// and is read again when opened if the phone has them for it.
class InspectionDetailCubit extends Cubit<InspectionDetailState> {
  InspectionDetailCubit(this._services, this._key) : super(const InspectionDetailState()) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
  }

  final AppServices _services;
  final String _key;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<InspectionOverview?>? _cacheSub;
  StreamSubscription<List<OutboxRow>>? _unsentSub;
  InspectionOverview? _cached;
  List<OutboxRow> _unsent = const <OutboxRow>[];
  bool _asked = false;
  int? _projectId;

  void _onProject(ProjectRow? project) {
    if (project == null) {
      emit(const InspectionDetailState(loaded: true));
      return;
    }
    if (_projectId == project.id) return;
    _projectId = project.id;
    _cacheSub?.cancel();
    _unsentSub?.cancel();
    _cacheSub = _services.inspections.watchCached(project.id).listen((cached) {
      _cached = cached;
      _publish();
    });
    _unsentSub = _services.inspections.watchUnsent(project.id).listen((rows) {
      _unsent = rows;
      _publish();
    });
  }

  void _publish() {
    if (isClosed) return;
    final InspectionOverview? overview = _cached?.withUnsent(_unsent);
    final Inspection? found = overview?.find(_key);
    emit(InspectionDetailState(loaded: true, inspection: found, refused: overview?.refused ?? false));
    // The list carries no items. Read them once, if the phone does not have them.
    final int? id = found?.id;
    if (!_asked && id != null && found!.items.isEmpty && _services.connectivity.isOnline) {
      _asked = true;
      unawaited(_read(id));
    }
  }

  Future<void> _read(int id) async {
    final int? projectId = _projectId;
    if (projectId == null) return;
    try {
      await _services.inspections.refreshInspection(projectId, id);
    } on ApiFailure {
      // What the phone has is shown.
    }
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _cacheSub?.cancel();
    await _unsentSub?.cancel();
    return super.close();
  }
}
