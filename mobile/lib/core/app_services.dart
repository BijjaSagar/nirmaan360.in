import 'dart:io' show Directory, Platform;

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

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
import '../features/sync_center/data/sync_repository.dart';
import 'api/dio_nirmaan_api.dart';
import 'config/app_config.dart';
import 'api/nirmaan_api.dart';
import 'db/app_database.dart';
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
import 'readers/readers.dart';
import 'session/current_user.dart';

/// Deletes the temporary folders that hold company data: quotation files and
/// report PDFs handed to another app. Never throws.
Future<void> clearCompanyTempFiles(Future<String> Function() tempDirectory) async {
  try {
    final String root = await tempDirectory();
    for (final String folder in <String>[AppConfig.quotationFolder, AppConfig.reportFolder]) {
      try {
        final Directory dir = Directory('$root${Platform.pathSeparator}$folder');
        // Synchronous on purpose: small folders, and it runs inside the sign-out.
        if (dir.existsSync()) dir.deleteSync(recursive: true);
      } on Object {
        // One folder that will not go must not keep the other.
      }
    }
  } on Object {
    // Best effort: the operating system clears the temporary folder too.
  }
}

/// Opens a web page outside the app. False when the phone could not.
typedef OpenLink = Future<bool> Function(Uri uri);

Future<bool> _openInBrowser(Uri uri) async {
  try {
    return await launchUrl(uri, mode: LaunchMode.externalApplication);
  } on Object {
    return false;
  }
}

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
    required this.readers,
    required this.db,
    required this.api,
    required this.session,
    required this.sync,
    required this.scheduler,
    required this.connectivity,
    required this.context,
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
    auth.beforeSignOut = push.forgetThisPhone;
    auth.afterLocaleChange = push.languageChanged;
    auth.afterAccountDeleted = push.forgetLocally;
    // A reader answer belongs to one person: a sign-out or a new sign-in drops it.
    _readerSession = session.changes.listen((_) => readers.forget());
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
    required PushMessaging pushMessaging,
    required String pushPlatform,
  }) {
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
    );
    final context = DataContext(
      db: db,
      api: api,
      clock: clock,
      attachments: attachments,
      connectivity: connectivity,
      session: session,
      sync: engine,
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
    final readers = Readers(api: api, session: session, connectivity: connectivity, clock: clock);
    final push = PushService(
      messaging: pushMessaging,
      devices: ApiDeviceRegistry(api, portal: () => session.user?.isContractor ?? false),
      db: db,
      platform: pushPlatform,
      appVersion: AppInfo.label,
      locale: () => _pushLocale(db, session),
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
      readers: readers,
      db: db,
      api: api,
      session: session,
      sync: engine,
      scheduler: scheduler,
      connectivity: connectivity,
      context: context,
      onDispose: onDispose,
    )..auth.clearTemporaryFiles = () => clearCompanyTempFiles(tempDirectory);
  }

  final AppDatabase db;
  final NirmaanApi api;
  final SessionController session;
  final SyncEngine sync;
  final SyncScheduler scheduler;

  final ConnectivityMonitor connectivity;

  /// Opens the terms, the privacy policy and other pages of the website.
  /// Tests swap it to see what was opened.
  OpenLink openLink = _openInBrowser;

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

  /// The document readers: whether to offer one, and watching a read.
  final Readers readers;

  StreamSubscription<SessionState>? _pushSession;
  StreamSubscription<SessionState>? _readerSession;

  /// Restores the session and starts the triggers. Offline-safe.
  ///
  /// Rows stuck in `sending` from a killed process go back to `pending` here.
  Future<void> start() async {
    await sync.recoverInterrupted();
    final ConnectivityMonitor raw = connectivity;
    if (raw is ConnectivityPlusMonitor) await raw.start();
    await auth.restoreSession();
    scheduler.start();
  }

  /// Push is for anyone signed in: staff, and a contractor login for its own
  /// payments and bills.
  bool get _mayGetPush {
    final CurrentUser? user = session.user;
    return session.current.isSignedIn && user != null;
  }

  /// The language the app shows, which is the one push text is written in:
  /// the person's choice on this phone, then their account's, then the phone's.
  static Future<String?> _pushLocale(AppDatabase db, SessionController session) async {
    const Set<String> written = <String>{'en', 'mr', 'hi'};
    for (final String? code in <String?>[
      await db.readMeta(MetaKey.locale),
      session.user?.locale,
      PlatformDispatcher.instance.locale.languageCode,
    ]) {
      if (written.contains(code)) return code;
    }
    return 'en';
  }

  void _watchPushEligibility() {
    void update() => unawaited(push.setEligible(_mayGetPush));
    _pushSession ??= session.changes.listen((_) => update());
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
    await _readerSession?.cancel();
    await push.dispose();
    await scheduler.stop();
    await sync.dispose();
    await session.dispose();
    await _onDispose?.call();
    await db.close();
  }
}

class _AlwaysOnline implements ConnectivityMonitor {
  @override
  bool get isOnline => true;

  @override
  Stream<bool> get changes => const Stream.empty();
}
