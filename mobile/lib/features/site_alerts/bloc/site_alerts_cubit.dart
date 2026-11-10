import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/session/current_user.dart';
import '../../../core/session/session_controller.dart';
import '../data/site_alerts_repository.dart';

enum SiteTab { dashboard, alerts }

enum SiteLoad { loading, noProject, ready }

/// Where a scan stands. A scan is the one thing this page asks of the server
/// besides reading, so its refusals are shown, not hidden.
enum ScanStatus { idle, running, done, offline, rateLimited, refused, failed }

class ScanNotice extends Equatable {
  const ScanNotice({this.status = ScanStatus.idle, this.result, this.serverMessage, this.wait});

  final ScanStatus status;

  /// What the server counted, after a scan that worked.
  final AlertScan? result;

  /// The server's own words, for a refusal. Never replaced by ours.
  final String? serverMessage;

  /// How long the server asked to wait, from `Retry-After`, in whole seconds.
  final Duration? wait;

  bool get running => status == ScanStatus.running;

  @override
  List<Object?> get props => <Object?>[status, result, serverMessage, wait];
}

class SiteAlertsState extends Equatable {
  const SiteAlertsState({
    this.load = SiteLoad.loading,
    this.project,
    this.overview,
    this.tab = SiteTab.dashboard,
    this.refreshing = false,
    this.canDashboard = true,
    this.canAlerts = true,
    this.canScan = false,
    this.scan = const ScanNotice(),
  });

  final SiteLoad load;
  final ProjectRow? project;

  /// Null until this phone has read the project once.
  final SiteOverview? overview;
  final SiteTab tab;
  final bool refreshing;

  /// `progress.view` and the plan feature: the dashboard cards.
  final bool canDashboard;

  /// `alerts.view` and the plan feature: the alert list.
  final bool canAlerts;

  /// `alerts.manage` and the plan feature: the "Scan now" button.
  final bool canScan;
  final ScanNotice scan;

  /// The tab on show. Someone who may read only one of the two sees that one,
  /// whatever was asked for.
  SiteTab get shownTab {
    if (!canDashboard && canAlerts) return SiteTab.alerts;
    if (!canAlerts && canDashboard) return SiteTab.dashboard;
    return tab;
  }

  /// Both parts are open to this person, so there is a choice to make.
  bool get hasTabs => canDashboard && canAlerts;

  SiteAlertsState copyWith({
    SiteLoad? load,
    ProjectRow? project,
    bool clearProject = false,
    SiteOverview? Function()? overview,
    SiteTab? tab,
    bool? refreshing,
    bool? canDashboard,
    bool? canAlerts,
    bool? canScan,
    ScanNotice? scan,
  }) {
    return SiteAlertsState(
      load: load ?? this.load,
      project: clearProject ? null : (project ?? this.project),
      overview: overview == null ? this.overview : overview(),
      tab: tab ?? this.tab,
      refreshing: refreshing ?? this.refreshing,
      canDashboard: canDashboard ?? this.canDashboard,
      canAlerts: canAlerts ?? this.canAlerts,
      canScan: canScan ?? this.canScan,
      scan: scan ?? this.scan,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, project?.id, overview, tab, refreshing, canDashboard, canAlerts, canScan, scan];
}

/// The last copy on this phone, refreshed from the server whenever it can be
/// reached.
class SiteAlertsCubit extends Cubit<SiteAlertsState> {
  SiteAlertsCubit(this._services, {SiteTab tab = SiteTab.dashboard}) : super(_initial(_services, tab)) {
    _projectSub = _services.projects.watchCurrentProject().listen(_onProject);
    _sessionSub = _services.auth.watchSession().listen(_onSession);
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<SiteOverview?>? _cacheSub;
  StreamSubscription<SessionState>? _sessionSub;

  static SiteAlertsState _initial(AppServices services, SiteTab tab) {
    final (:dashboard, :alerts, :scan) = _permissions(services);
    return SiteAlertsState(tab: tab, canDashboard: dashboard, canAlerts: alerts, canScan: scan);
  }

  /// What this person may do here: the server's own permissions, with the plan.
  static ({bool dashboard, bool alerts, bool scan}) _permissions(AppServices services) {
    final CurrentUser? user = services.session.user;
    final bool plan = user?.features.contains('site_control') ?? false;
    final bool alerts = plan && (user?.can('alerts.view') ?? false);
    return (dashboard: plan && (user?.can('progress.view') ?? false), alerts: alerts, scan: alerts && (user?.can('alerts.manage') ?? false));
  }

  void _onSession(SessionState session) {
    if (isClosed) return;
    final (:dashboard, :alerts, :scan) = _permissions(_services);
    if (dashboard == state.canDashboard && alerts == state.canAlerts && scan == state.canScan) return;
    emit(state.copyWith(canDashboard: dashboard, canAlerts: alerts, canScan: scan));
  }

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _cacheSub?.cancel();
      _cacheSub = null;
      emit(state.copyWith(clearProject: true, load: SiteLoad.noProject, overview: () => null));
      return;
    }
    if (state.project?.id == project.id && _cacheSub != null) return;
    emit(state.copyWith(project: project));
    _cacheSub?.cancel();
    _cacheSub = _services.siteAlerts.watchCached(project.id).listen((overview) {
      if (!isClosed) emit(state.copyWith(load: SiteLoad.ready, overview: () => overview));
    });
    unawaited(refresh());
  }

  void setTab(SiteTab tab) => emit(state.copyWith(tab: tab));

  /// Asks the server to scan this project now. Only for someone with
  /// `alerts.manage`: for anyone else nothing is sent. The server's refusal,
  /// above all its rate limit, is kept in its own words for the page to show.
  Future<void> scan() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !state.canScan || state.scan.running) return;
    emit(state.copyWith(scan: const ScanNotice(status: ScanStatus.running)));
    ScanNotice outcome;
    try {
      final AlertScan result = await _services.siteAlerts.scan(projectId);
      outcome = ScanNotice(status: ScanStatus.done, result: result);
    } on OfflineFailure {
      outcome = const ScanNotice(status: ScanStatus.offline);
    } on RateLimited catch (e) {
      outcome = ScanNotice(status: ScanStatus.rateLimited, serverMessage: e.serverMessage, wait: e.retryAfter);
    } on ValidationFailure catch (e) {
      outcome = ScanNotice(status: ScanStatus.refused, serverMessage: e.message);
    } on ServerFailure catch (e) {
      final bool refused = e.statusCode != null && e.statusCode! < 500;
      outcome = ScanNotice(status: refused ? ScanStatus.refused : ScanStatus.failed, serverMessage: refused ? e.message : null);
    } on ApiFailure {
      outcome = const ScanNotice(status: ScanStatus.failed);
    }
    if (!isClosed) emit(state.copyWith(scan: outcome));
  }

  /// False means the phone is showing what it already had.
  Future<bool> refresh() async {
    final int? projectId = state.project?.id;
    if (projectId == null || !_services.connectivity.isOnline) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await _services.siteAlerts.refresh(projectId);
      return true;
    } on ApiFailure {
      return false;
    } finally {
      if (!isClosed) emit(state.copyWith(refreshing: false));
    }
  }

  @override
  Future<void> close() async {
    await _projectSub?.cancel();
    await _cacheSub?.cancel();
    await _sessionSub?.cancel();
    return super.close();
  }
}
