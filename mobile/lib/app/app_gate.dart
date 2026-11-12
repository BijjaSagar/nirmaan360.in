import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../core/api/account_models.dart';
import '../core/app_services.dart';
import '../core/db/app_database.dart';
import '../core/offline/offline_plan.dart';
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
    this.offline,
    this.licence = LicenceStatus.none,
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

  /// Null for a company on any normal plan, which is nearly every company.
  final OfflinePlanState? offline;

  /// How the offline licence stands. Always [LicenceStatus.none] for a company
  /// on any normal plan.
  final LicenceStatus licence;

  /// This company keeps its data on the phone. One person: no approvals.
  bool get isLocalOnly => offline != null;

  /// The licence has ended: nothing can be added or changed, though everything
  /// can still be read and exported.
  bool get licenceEnded => licence.entriesBlocked;

  /// Another phone holds the licence and this one has nothing of its own yet.
  /// With data here the app stays open, because data is never hidden.
  bool get licenceBlocked =>
      offline != null && !offline!.hasToken && offline!.issue == LicenceIssue.deviceLimit && (projects?.isEmpty ?? true);

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
      !isLocalOnly && (can('measurement.approve') || can('dpr.approve') || can('ra_bill.approve'));

  bool get canManageProjects => can('project.manage');

  /// Recorded material prices are for `money.view` on a plan that has them.
  /// Nobody else is shown them, or a way to them.
  bool get canSeePrices => can('money.view') && (user?.features.contains('price_watch') ?? false) && !isContractor;

  /// Checklists and inspections: `checklist.fill` on a plan with `qhs`. The
  /// server's `checklist.view` does not reach the app, but every role that fills
  /// a checklist also sees them. A plan that lacks it, such as the offline plan,
  /// is never shown them or a way to them.
  bool get canInspect =>
      can('checklist.fill') && (user?.features.contains('qhs') ?? false) && !isContractor && !isLocalOnly;

  /// The daily machine log: `asset.log` on a plan with `assets`. `asset.view`,
  /// which the server asks for on the reads, does not reach the app, and every
  /// role that logs a machine also sees them.
  bool get canLogMachines =>
      can('asset.log') && (user?.features.contains('assets') ?? false) && !isContractor && !isLocalOnly;

  /// Receiving goods against a purchase order and issuing from a store, inside the
  /// Material page: `procurement.stock` on a plan with `procurement`. The server's
  /// `procurement.view` does not reach the app, and every role that has the first
  /// has the second. Never offered to a contractor login or the offline plan.
  bool get canStockProcure =>
      can('procurement.stock') && (user?.features.contains('procurement') ?? false) && !isContractor && !isLocalOnly;

  /// Marking attendance with the camera, by a worker's QR card or by a photo:
  /// `attendance.manage` on a plan with `labour`. Never offered to a contractor
  /// login or a phone that keeps its data local.
  bool get canCaptureAttendance =>
      can('attendance.manage') && (user?.features.contains('labour') ?? false) && !isContractor && !isLocalOnly;

  /// Site tools has a row on Home when at least one of its tools is open.
  bool get hasSiteTools => canInspect || canLogMachines;

  /// The site dashboard: `progress.view` on a plan with `site_control`.
  bool get canSeeSiteDashboard =>
      can('progress.view') && (user?.features.contains('site_control') ?? false) && !isContractor;

  /// The site alerts: `alerts.view`, which the server now sends, on a plan with
  /// `site_control`. This is the server's own gate for the alerts, not a stand-in.
  bool get canSeeAlerts =>
      can('alerts.view') && (user?.features.contains('site_control') ?? false) && !isContractor;

  /// "Scan now": `alerts.manage` and the same plan feature. Seeing the alerts
  /// is not enough; the server refuses a scan to everyone else.
  bool get canScanAlerts => can('alerts.manage') && canSeeAlerts;

  /// Home has a row for the site: the dashboard, the alerts, or both.
  bool get hasSite => canSeeSiteDashboard || canSeeAlerts;

  /// The site page opens for either permission. The plan feature is not asked
  /// here, as it never was: a company without it meets the server's refusal and
  /// a calm page, and only the entry points (Home's row) hide it.
  bool get canOpenSite => can('progress.view') || can('alerts.view');

  /// A subcontractor's login: the portal and settings, and nothing else.
  bool get isContractor => user?.isContractor ?? false;

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
    OfflinePlanState? offline,
    bool clearOffline = false,
    LicenceStatus? licence,
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
      offline: clearOffline ? null : (offline ?? this.offline),
      licence: clearOffline ? LicenceStatus.none : (licence ?? this.licence),
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
        offline,
        licence,
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
      _services.offlinePlan.changes.listen(_onOffline),
      _services.offlinePlan.licenceChanges.listen(_onLicence),
    ]);
  }

  final AppServices _services;
  final List<StreamSubscription<Object?>> _subscriptions = <StreamSubscription<Object?>>[];
  final ValueNotifier<int> _refresh = ValueNotifier<int>(0);

  /// Tells the router to run its redirect again.
  Listenable get refreshListenable => _refresh;

  void _onOffline(OfflinePlanState? offline) {
    emit(state.copyWith(offline: offline, clearOffline: offline == null, licence: _services.offlinePlan.licence));
  }

  void _onLicence(LicenceStatus licence) {
    // A status that arrives after the plan was left must not bring it back.
    if (state.offline == null) return;
    emit(state.copyWith(licence: licence));
  }

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

  /// The account deletion that just signed this phone out, once. The sign-in
  /// screen reads it as it opens and says what happened.
  AccountDeletionResult? takeAccountDeletedNotice() => _services.auth.takeDeletedNotice();

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
      a.isContractor != b.isContractor ||
      a.canSeePrices != b.canSeePrices ||
      a.canInspect != b.canInspect ||
      a.canLogMachines != b.canLogMachines ||
      a.canStockProcure != b.canStockProcure ||
      a.canCaptureAttendance != b.canCaptureAttendance ||
      a.hasSiteTools != b.hasSiteTools ||
      a.hasSite != b.hasSite ||
      a.canSeeAlerts != b.canSeeAlerts ||
      a.ready != b.ready ||
      a.offline?.mode != b.offline?.mode ||
      a.licenceEnded != b.licenceEnded ||
      a.licenceBlocked != b.licenceBlocked;

  /// Exact paths that need a permission. Anything else is open once signed in.
  static const Map<String, String> _pathPermissions = <String, String>{
    Routes.projectForm: 'project.manage',
    Routes.boq: 'boq.view',
    Routes.boqForm: 'boq.manage',
    Routes.walletForm: 'petty_cash.manage',
    Routes.topUp: 'petty_cash.manage',
    Routes.progress: 'progress.view',
    Routes.progressForm: 'progress.create',
    Routes.contractors: 'contractor.view',
    Routes.contractorRequest: 'contractor.request',
    Routes.material: 'material_control.view',
    Routes.materialReceive: 'material_control.challan',
    Routes.materialUse: 'material_control.consume',
    Routes.materialCount: 'material_control.count',
  };

  /// The location for [uri], or null to stay.
  String? redirect(Uri uri) {
    final s = state;
    final String path = uri.path;
    final bool atAuth = Routes.isAuth(path);
    if (!s.isSignedIn) return atAuth ? null : Routes.signIn;
    if (atAuth) {
      if (s.signingIn) return null;
      if (s.isContractor) return Routes.portal;
      return s.hasProject ? Routes.home : Routes.projects;
    }
    if (s.isContractor) {
      // No project picker, no tabs, no site screens: the portal, and settings
      // so the language can change and the person can sign out.
      return Routes.isPortal(path) || Routes.isSettings(path) ? null : Routes.portal;
    }
    final OfflinePlanState? offline = s.offline;
    if (offline != null) {
      final String? local = _localRedirect(s, offline, path);
      if (local != null) return local == path ? null : local;
    } else if (Routes.isOfflinePlan(path)) {
      return s.hasProject ? Routes.home : Routes.projects;
    }
    if (!s.hasProject) {
      // Settings stays open so someone on the wrong account can sign out
      // instead of being stuck on "ask your office".
      if (path == Routes.projects || Routes.isSettings(path)) return null;
      if (path == Routes.projectForm && s.canManageProjects) return null;
      return Routes.projects;
    }
    if (path == Routes.projects && s.onlyOneProject) {
      final bool onPurpose = uri.queryParameters['switch'] == '1';
      if (!(onPurpose && s.canManageProjects)) return Routes.home;
    }
    if (!s.canApprove && path.startsWith(Routes.approvals)) return Routes.home;
    if (Routes.isPrices(path) && !s.canSeePrices) return Routes.home;
    if (Routes.isInspections(path) && !s.canInspect) return Routes.home;
    if (Routes.isMachines(path) && !s.canLogMachines) return Routes.home;
    if (Routes.isAttendanceCapture(path) && !s.canCaptureAttendance) return Routes.attendance;
    if (Routes.isStockEntry(path) && !(s.canStockProcure && s.can('material_control.view'))) return Routes.home;
    if (path == Routes.siteTools && !s.hasSiteTools) return Routes.home;
    if (path == Routes.siteDashboard && !s.canOpenSite) return Routes.home;
    // The portal belongs to contractor logins. The server would refuse the site team anyway.
    if (Routes.isPortal(path)) return Routes.home;
    final String? needed = _pathPermissions[path];
    if (needed != null && !s.can(needed)) return Routes.home;
    return null;
  }

  /// Where a phone on the offline plan belongs. Returns [path] to stay there
  /// whatever else is true, or null to carry on with the usual checks.
  String? _localRedirect(GateState s, OfflinePlanState offline, String path) {
    // Settings, and deleting the account from it, stay open whatever the plan says.
    final bool atSettings = path == Routes.settings || path == Routes.deleteAccount;
    if (offline.mode == OfflineMode.asking || offline.mode == OfflineMode.uploading) {
      return atSettings ? path : Routes.offlineUpload;
    }
    // The renewal page stays open here: it is where a full plan is explained.
    if (s.licenceBlocked) return atSettings || path == Routes.licenceEnded ? path : Routes.offlinePlan;
    // Upload is offered only once the server stops saying offline.
    if (path == Routes.offlineUpload && offline.mode == OfflineMode.local) return Routes.offlinePlan;
    if (Routes.isOfflinePlan(path)) return path;
    // An ended licence turns every page that adds or changes something into the
    // page that explains why. Reading and the export are not among them.
    if (s.licenceEnded && Routes.isEntryForm(path)) return Routes.licenceEnded;
    if (!Routes.isLocalFeature(path)) return s.hasProject ? Routes.home : Routes.projects;
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
