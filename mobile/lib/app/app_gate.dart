import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../core/app_services.dart';
import '../core/db/app_database.dart';
import '../core/session/current_user.dart';
import '../core/session/session_controller.dart';
import 'routes.dart';

const List<String> kAppLocales = <String>['en', 'mr', 'hi'];

/// What the app knows before it can decide which screen comes first.
@immutable
class GateState extends Equatable {
  const GateState({
    this.status = SessionStatus.restoring,
    this.user,
    this.projects,
    this.selectedProjectId,
    this.selectionKnown = false,
    this.storedLocale,
    this.localeKnown = false,
    this.signingIn = false,
    this.bootFailed = false,
  });

  final SessionStatus status;
  final CurrentUser? user;

  /// Null until the first read from the local database.
  final List<ProjectRow>? projects;
  final int? selectedProjectId;
  final bool selectionKnown;
  final String? storedLocale;
  final bool localeKnown;

  /// True while a sign-in is in flight, so the sign-in screen stays up until
  /// the first pull has finished.
  final bool signingIn;

  /// `AppServices.start` threw: the sign-in screen is the safe place to land.
  final bool bootFailed;

  /// Everything the first frame of the real app needs is known.
  bool get ready =>
      bootFailed ||
      (projects != null && selectionKnown && localeKnown && status != SessionStatus.restoring);

  bool get isSignedIn => status == SessionStatus.signedIn;

  /// The user was signed out by the server. Local work is intact.
  bool get isExpired => status == SessionStatus.expired;

  ProjectRow? get currentProject {
    final all = projects;
    if (all == null || all.isEmpty) return null;
    final id = selectedProjectId;
    if (id != null) {
      for (final project in all) {
        if (project.id == id) return project;
      }
    }
    return all.length == 1 ? all.first : null;
  }

  bool get hasProject => currentProject != null;

  bool get onlyOneProject => projects?.length == 1;

  bool can(String permission) => user?.can(permission) ?? false;

  bool get canApprove =>
      can('measurement.approve') || can('dpr.approve') || can('ra_bill.approve');

  bool get canManageProjects => can('project.manage');

  /// The stored choice, then the server's, then the phone's language.
  String? get localeCode {
    final code = storedLocale ?? user?.locale;
    return kAppLocales.contains(code) ? code : null;
  }

  Locale? get locale => localeCode == null ? null : Locale(localeCode!);

  GateState copyWith({
    SessionStatus? status,
    CurrentUser? user,
    bool clearUser = false,
    List<ProjectRow>? projects,
    int? selectedProjectId,
    bool clearSelected = false,
    bool? selectionKnown,
    String? storedLocale,
    bool clearLocale = false,
    bool? localeKnown,
    bool? signingIn,
    bool? bootFailed,
  }) {
    return GateState(
      status: status ?? this.status,
      user: clearUser ? null : (user ?? this.user),
      projects: projects ?? this.projects,
      selectedProjectId: clearSelected ? null : (selectedProjectId ?? this.selectedProjectId),
      selectionKnown: selectionKnown ?? this.selectionKnown,
      storedLocale: clearLocale ? null : (storedLocale ?? this.storedLocale),
      localeKnown: localeKnown ?? this.localeKnown,
      signingIn: signingIn ?? this.signingIn,
      bootFailed: bootFailed ?? this.bootFailed,
    );
  }

  @override
  List<Object?> get props => <Object?>[
        status,
        user,
        projects,
        selectedProjectId,
        selectionKnown,
        storedLocale,
        localeKnown,
        signingIn,
        bootFailed,
      ];
}

/// Decides where the user should be, from local data only.
///
/// It listens to the session, the local project list, the selected project and
/// the stored language, so a sign-out, an expired token, or the first pull
/// after signing in moves the user without any screen having to ask.
class AppGate extends Cubit<GateState> {
  AppGate(this._services) : super(const GateState()) {
    final db = _services.db;
    _subscriptions.addAll(<StreamSubscription<Object?>>[
      _services.auth.watchSession().listen(_onSession),
      _services.projects.watchProjects().listen(_onProjects),
      db.watchMeta(MetaKey.selectedProject).listen(_onSelected),
      _services.auth.watchLocale().listen(_onLocale),
    ]);
  }

  final AppServices _services;
  final List<StreamSubscription<Object?>> _subscriptions = <StreamSubscription<Object?>>[];
  final ValueNotifier<int> _refresh = ValueNotifier<int>(0);

  /// Tells the router to run its redirect again.
  Listenable get refreshListenable => _refresh;

  void _onSession(SessionState session) {
    emit(
      state.copyWith(
        status: session.status,
        user: session.user,
        clearUser: session.user == null,
      ),
    );
    _maybeAutoSelect();
  }

  void _onProjects(List<ProjectRow> projects) {
    emit(state.copyWith(projects: projects));
    _maybeAutoSelect();
  }

  void _onSelected(String? raw) {
    final id = raw == null ? null : int.tryParse(raw);
    emit(
      state.copyWith(
        selectedProjectId: id,
        clearSelected: id == null,
        selectionKnown: true,
      ),
    );
    _maybeAutoSelect();
  }

  void _onLocale(String? code) {
    emit(state.copyWith(storedLocale: code, clearLocale: code == null, localeKnown: true));
  }

  /// With one project there is nothing to choose, so the picker never shows.
  void _maybeAutoSelect() {
    if (!state.selectionKnown || !state.isSignedIn) return;
    final projects = state.projects;
    if (projects == null || projects.length != 1) return;
    if (state.selectedProjectId == projects.first.id) return;
    unawaited(_services.projects.selectProject(projects.first.id));
  }

  /// Selects a project and updates the gate before the caller navigates.
  Future<void> selectProject(int projectId) async {
    await _services.projects.selectProject(projectId);
    if (!isClosed) {
      emit(state.copyWith(selectedProjectId: projectId, selectionKnown: true));
    }
  }

  /// Selects a project that was only just created, before the local project
  /// list has caught up, so navigating Home straight after cannot bounce.
  Future<void> adoptProject(ProjectRow project) async {
    await _services.projects.selectProject(project.id);
    if (isClosed) return;
    final List<ProjectRow> known = state.projects ?? const <ProjectRow>[];
    emit(
      state.copyWith(
        projects: known.any((p) => p.id == project.id) ? known : <ProjectRow>[...known, project],
        selectedProjectId: project.id,
        selectionKnown: true,
      ),
    );
  }

  void beginSignIn() => emit(state.copyWith(signingIn: true));

  void endSignIn() {
    if (!isClosed) emit(state.copyWith(signingIn: false));
  }

  void markBootFailed() {
    if (!isClosed) emit(state.copyWith(bootFailed: true));
  }

  // Not `onChange`: that runs before `state` is updated, so a redirect it
  // triggered would read the old state and decide nothing has changed.
  @override
  void emit(GateState state) {
    final GateState previous = this.state;
    super.emit(state);
    if (_redirectChanged(previous, state)) _refresh.value++;
  }

  bool _redirectChanged(GateState a, GateState b) =>
      a.status != b.status ||
      a.hasProject != b.hasProject ||
      a.onlyOneProject != b.onlyOneProject ||
      a.signingIn != b.signingIn ||
      a.canApprove != b.canApprove ||
      a.canManageProjects != b.canManageProjects ||
      a.ready != b.ready;

  /// Exact paths that need a permission. Anything else is open once signed in.
  static const Map<String, String> _pathPermissions = <String, String>{
    Routes.projectForm: 'project.manage',
    Routes.boq: 'boq.view',
    Routes.boqForm: 'boq.manage',
    Routes.walletForm: 'petty_cash.manage',
    Routes.topUp: 'petty_cash.manage',
    Routes.progress: 'progress.view',
    Routes.progressForm: 'progress.create',
  };

  /// The location for [uri], or null to stay.
  String? redirect(Uri uri) {
    final s = state;
    final String path = uri.path;
    final atSignIn = path == Routes.signIn;
    if (!s.isSignedIn) return atSignIn ? null : Routes.signIn;
    if (atSignIn) {
      if (s.signingIn) return null;
      return s.hasProject ? Routes.home : Routes.projects;
    }
    if (!s.hasProject) {
      // Settings stays open so someone on the wrong account can sign out
      // instead of being stuck on "ask your office".
      if (path == Routes.projects || path == Routes.settings) return null;
      if (path == Routes.projectForm && s.canManageProjects) return null;
      return Routes.projects;
    }
    if (path == Routes.projects && s.onlyOneProject) {
      final bool onPurpose = uri.queryParameters['switch'] == '1';
      if (!(onPurpose && s.canManageProjects)) return Routes.home;
    }
    if (!s.canApprove && path.startsWith(Routes.approvals)) return Routes.home;
    final String? needed = _pathPermissions[path];
    if (needed != null && !s.can(needed)) return Routes.home;
    return null;
  }

  @override
  Future<void> close() async {
    for (final subscription in _subscriptions) {
      await subscription.cancel();
    }
    _refresh.dispose();
    return super.close();
  }
}
