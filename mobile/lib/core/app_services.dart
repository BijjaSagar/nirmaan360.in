import 'dart:io' show Directory;
import 'dart:ui' show Rect;

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import 'api/api_failure.dart';
import 'dart:async';

import '../app/app_info.dart';
import '../features/approvals/data/approval_repository.dart';
import '../features/contractor_control/data/contractor_repository.dart';
import '../features/contractor_portal/data/portal_repository.dart';
import '../features/attendance/data/attendance_repository.dart';
import '../features/attendance/data/selfie_repository.dart';
import '../features/auth/data/auth_repository.dart';
import '../features/dpr/data/dpr_repository.dart';
import '../features/home/data/home_repository.dart';
import '../features/inspections/data/inspection_repository.dart';
import '../features/issues/data/issue_repository.dart';
import '../features/machine_logs/data/machine_repository.dart';
import '../features/measurement/data/measurement_repository.dart';
import '../features/petty_cash/data/petty_cash_repository.dart';
import '../features/price_watch/data/price_watch_repository.dart';
import '../features/material_control/data/material_repository.dart';
import '../features/projects/data/project_image_repository.dart';
import '../features/projects/data/project_repository.dart';
import '../features/site_alerts/data/site_alerts_repository.dart';
import '../features/site_progress/data/progress_repository.dart';
import '../features/offline_plan/data/offline_export.dart';
import '../features/offline_plan/data/offline_upload.dart';
import '../features/sync_center/data/sync_repository.dart';
import 'api/dio_nirmaan_api.dart';
import 'api/nirmaan_api.dart';
import 'db/app_database.dart';
import 'offline/offline_plan.dart';
import 'session/session_controller.dart';
import 'session/token_store.dart';
import 'sync/attachment_store.dart';
import 'sync/clock.dart';
import 'sync/connectivity.dart';
import 'sync/data_context.dart';
import 'sync/photo_capture.dart';
import 'sync/sync_engine.dart';
import 'sync/sync_scheduler.dart';
import 'push/device_registry.dart';
import 'push/firebase_push_messaging.dart';
import 'push/push_messaging.dart';
import 'push/push_service.dart';
import 'session/current_user.dart';

/// Offers a file to the person: the file, a subject, and where the button is.
typedef ShareFile = Future<void> Function(String path, String subject, Rect? origin);

/// Composition root for the data layer.
///
/// Call [start] once at app start-up. Nothing there needs the network.
class AppServices {
  AppServices._({
    required this.progress,
    required this.material,
    required this.siteAlerts,
    required this.contractors,
    required this.inspections,
    required this.machines,
    required this.portal,
    required this.priceWatch,
    required this.projectImages,
    required this.push,
    required this.db,
    required this.api,
    required this.session,
    required this.sync,
    required this.scheduler,
    required this.connectivity,
    required this._rawConnectivity,
    required this.context,
    required this.offlinePlan,
    required this.exportBackup,
    required this.shareFile,
    this._onDispose,
  }) : photos = PhotoCapture(),
       auth = AuthRepository(context, portal),
       projects = ProjectRepository(context),
       home = HomeRepository(context),
       dpr = DprRepository(context),
       measurements = MeasurementRepository(context),
       issues = IssueRepository(context),
       attendance = AttendanceRepository(context),
       selfies = SelfieRepository(context),
       pettyCash = PettyCashRepository(context),
       approvals = ApprovalRepository(context),
       syncCenter = SyncRepository(context) {
    offlineUploader = OfflineUploader(context, dpr);
    auth.beforeSignOut = push.forgetThisPhone;
  }

  /// Production wiring: real database, secure token storage, Dio.
  factory AppServices.production() {
    final db = AppDatabase.open();
    final tokens = SecureTokenStore();
    final session = SessionController(tokens: tokens, db: db);
    final connectivity = ConnectivityPlusMonitor();
    final api = DioNirmaanApi(
      readToken: tokens.read,
      onUnauthorized: session.markExpired,
    );
    return AppServices._build(
      db: db,
      api: api,
      session: session,
      connectivity: connectivity,
      clock: const SystemClock(),
      attachments: DirectoryAttachmentStore.documents(),
      observeLifecycle: true,
      onDispose: connectivity.dispose,
      tempDirectory: () async => (await getTemporaryDirectory()).path,
      shareFile: _systemShare,
      // Off unless the build was given Firebase settings: see FirebaseSettings.
      pushMessaging: FirebasePushMessaging(),
      pushPlatform: defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android',
    );
  }

  /// Test wiring: an in-memory database and a fake API.
  factory AppServices.forTesting({
    required AppDatabase db,
    required NirmaanApi api,
    TokenStore? tokens,
    AppClock? clock,
    ConnectivityMonitor? connectivity,
    AttachmentStore? attachments,
    Future<String> Function()? tempDirectory,
    ShareFile? shareFile,
    PushMessaging? pushMessaging,
  }) {
    final monitor = connectivity ?? _AlwaysOnline();
    final session = SessionController(
      tokens: tokens ?? InMemoryTokenStore(),
      db: db,
    );
    return AppServices._build(
      db: db,
      api: api,
      session: session,
      connectivity: monitor,
      clock: clock ?? const SystemClock(),
      attachments: attachments ?? InMemoryAttachmentStore(),
      observeLifecycle: false,
      tempDirectory: tempDirectory ?? () async => Directory.systemTemp.path,
      shareFile: shareFile ?? (path, subject, origin) async {},
      pushMessaging: pushMessaging ?? const NoPushMessaging(),
      pushPlatform: 'android',
    );
  }

  factory AppServices._build({
    required AppDatabase db,
    required NirmaanApi api,
    required SessionController session,
    required ConnectivityMonitor connectivity,
    required AppClock clock,
    required AttachmentStore attachments,
    required bool observeLifecycle,
    Future<void> Function()? onDispose,
    required Future<String> Function() tempDirectory,
    required ShareFile shareFile,
    required PushMessaging pushMessaging,
    required String pushPlatform,
  }) {
    final offlinePlan = OfflinePlan(
      db: db,
      api: api,
      clock: clock,
      connectivity: connectivity,
      session: session,
      appVersion: AppInfo.label,
    );
    if (api is DioNirmaanApi) api.blocksPath = offlinePlan.blocksPath;
    session.keepSessionOn401 = () => offlinePlan.isLocalOnly;
    // Screens and repositories see this. The plan, the engine and the
    // scheduler keep the real one, because they are the ones who ask.
    final ConnectivityMonitor screens = _PlanConnectivity(connectivity, offlinePlan);
    final engine = SyncEngine(
      db: db,
      api: api,
      clock: clock,
      attachments: attachments,
      connectivity: connectivity,
      session: session,
    );
    final scheduler = SyncScheduler(
      db: db,
      engine: engine,
      connectivity: connectivity,
      observeLifecycle: observeLifecycle,
    )..beforeRun = offlinePlan.check;
    engine.onOfflinePlanRefused = (url) => offlinePlan.adoptRefusal(upgradeUrl: url);
    final context = DataContext(
      db: db,
      api: api,
      clock: clock,
      attachments: attachments,
      connectivity: screens,
      session: session,
      sync: engine,
      offlinePlan: offlinePlan,
    );
    final progress = ProgressRepository(context);
    final material = MaterialRepository(context);
    final siteAlerts = SiteAlertsRepository(context);
    final contractors = ContractorRepository(context);
    final inspections = InspectionRepository(context);
    final machines = MachineRepository(context);
    final portal = PortalRepository(context);
    final priceWatch = PriceWatchRepository(context);
    final projectImages = ProjectImageRepository(context);
    final push = PushService(
      messaging: pushMessaging,
      devices: ApiDeviceRegistry(api),
      db: db,
      platform: pushPlatform,
      connectivity: connectivity,
    );
    engine.afterPull = (sentSomething) async {
      // Separately, so a failure in one does not leave the other stale.
      for (final refresh in <Future<void> Function({bool force})>[
        progress.refreshCurrent,
        material.refreshCurrent,
        siteAlerts.refreshCurrent,
        contractors.refreshCurrent,
        priceWatch.refreshCurrent,
        inspections.refreshCurrent,
        machines.refreshCurrent,
      ]) {
        try {
          await refresh(force: sentSomething);
        } on ApiFailure catch (e) {
          debugPrint('After-pull refresh failed: $e');
        }
      }
      await projectImages.sweepRemoved();
      // A token the server did not take earlier goes again: a sync means there is a network.
      await push.retryPending();
    };
    return AppServices._(
      progress: progress,
      material: material,
      siteAlerts: siteAlerts,
      contractors: contractors,
      inspections: inspections,
      machines: machines,
      portal: portal,
      priceWatch: priceWatch,
      projectImages: projectImages,
      push: push,
      db: db,
      api: api,
      session: session,
      sync: engine,
      scheduler: scheduler,
      connectivity: screens,
      rawConnectivity: connectivity,
      context: context,
      offlinePlan: offlinePlan,
      exportBackup: OfflineExporter(context, tempDirectory: tempDirectory).create,
      shareFile: shareFile,
      onDispose: onDispose,
    );
  }

  final AppDatabase db;
  final NirmaanApi api;
  final SessionController session;
  final SyncEngine sync;
  final SyncScheduler scheduler;

  /// What screens and repositories should go by: see [_PlanConnectivity].
  final ConnectivityMonitor connectivity;
  final ConnectivityMonitor _rawConnectivity;

  /// Whether this company keeps its data on the phone, and its licence.
  final OfflinePlan offlinePlan;

  /// Takes local work to the server, after the owner has said yes.
  late final OfflineUploader offlineUploader;

  /// Writes the data and photos into one file, and returns it.
  final Future<OfflineExport> Function() exportBackup;

  /// Hands a file to the system share sheet.
  final ShareFile shareFile;

  /// Repositories' shared context, for code that needs the raw pieces.
  final DataContext context;
  final Future<void> Function()? _onDispose;

  /// Takes compressed photos for `addPhoto` and `addExpense`.
  final PhotoCapture photos;

  final AuthRepository auth;
  final ProjectRepository projects;
  final HomeRepository home;
  final DprRepository dpr;
  final MeasurementRepository measurements;
  final IssueRepository issues;
  final AttendanceRepository attendance;

  /// Attendance by a photo of the worker taken at the site.
  final SelfieRepository selfies;
  final PettyCashRepository pettyCash;
  final ApprovalRepository approvals;
  final SyncRepository syncCenter;
  final ProgressRepository progress;
  final MaterialRepository material;
  final SiteAlertsRepository siteAlerts;
  final ContractorRepository contractors;

  /// Checklists and the inspections filled in from them.
  final InspectionRepository inspections;

  /// The daily machine log: machines, and what each worked.
  final MachineRepository machines;

  /// The contractor portal: all a contractor login reads.
  final PortalRepository portal;

  /// Recorded material prices, for anyone with `money.view`.
  final PriceWatchRepository priceWatch;
  final ProjectImageRepository projectImages;

  /// Push notifications: asking, the token, and what arrives or is tapped.
  final PushService push;

  StreamSubscription<SessionState>? _pushSession;
  StreamSubscription<OfflinePlanState?>? _pushPlan;

  /// Restores the session and starts the triggers. Offline-safe.
  ///
  /// Rows stuck in `sending` from a killed process go back to `pending` here.
  Future<void> start() async {
    await sync.recoverInterrupted();
    final ConnectivityMonitor raw = _rawConnectivity;
    if (raw is ConnectivityPlusMonitor) await raw.start();
    await offlinePlan.load();
    await auth.restoreSession();
    final OfflinePlanState? local = offlinePlan.state;
    if (local != null && !local.signedOut) session.resumeLocally();
    unawaited(offlinePlan.check());
    offlinePlan.startWatching();
    scheduler.start();
  }

  /// Push is for a signed-in member of staff on a company that syncs. Never a
  /// contractor login, and never the offline plan, which has no server to send it.
  bool get _mayGetPush {
    final CurrentUser? user = session.user;
    return session.current.isSignedIn && user != null && !user.isContractor && !offlinePlan.isLocalOnly;
  }

  void _watchPushEligibility() {
    void update() => unawaited(push.setEligible(_mayGetPush));
    _pushSession ??= session.changes.listen((_) => update());
    _pushPlan ??= offlinePlan.changes.listen((_) => update());
    update();
  }

  /// Starts push, after the app is up. Nothing here needs the network, and a
  /// build with no Firebase settings does nothing. Never throws.
  Future<void> startPush() {
    _watchPushEligibility();
    return push.start();
  }

  /// Stops timers and closes the database.
  Future<void> dispose() async {
    await _pushSession?.cancel();
    await _pushPlan?.cancel();
    await push.dispose();
    await scheduler.stop();
    await sync.dispose();
    await offlinePlan.dispose();
    await session.dispose();
    await _onDispose?.call();
    await db.close();
  }
}

/// On the offline plan there is no server to wait for, so no screen should
/// say "no network" or hold a form back. Anywhere else, the real answer.
Future<void> _systemShare(String path, String subject, Rect? origin) async {
  await SharePlus.instance.share(
    ShareParams(
      files: <XFile>[XFile(path, mimeType: 'application/zip')],
      subject: subject,
      title: subject,
      sharePositionOrigin: origin,
    ),
  );
}

class _PlanConnectivity implements ConnectivityMonitor {
  const _PlanConnectivity(this._inner, this._plan);

  final ConnectivityMonitor _inner;
  final OfflinePlan _plan;

  @override
  bool get isOnline => _plan.isLocalOnly || _inner.isOnline;

  @override
  Stream<bool> get changes => _inner.changes.map((online) => _plan.isLocalOnly || online);
}

class _AlwaysOnline implements ConnectivityMonitor {
  @override
  bool get isOnline => true;

  @override
  Stream<bool> get changes => const Stream.empty();
}
