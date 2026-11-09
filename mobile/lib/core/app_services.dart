import 'package:flutter/foundation.dart';

import 'api/api_failure.dart';
import 'dart:async';

import '../features/approvals/data/approval_repository.dart';
import '../features/contractor_control/data/contractor_repository.dart';
import '../features/contractor_portal/data/portal_repository.dart';
import '../features/attendance/data/attendance_repository.dart';
import '../features/auth/data/auth_repository.dart';
import '../features/dpr/data/dpr_repository.dart';
import '../features/home/data/home_repository.dart';
import '../features/issues/data/issue_repository.dart';
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

/// Composition root for the data layer.
///
/// Call [start] once at app start-up. Nothing there needs the network.
class AppServices {
  AppServices._({
    required this.progress,
    required this.material,
    required this.siteAlerts,
    required this.contractors,
    required this.portal,
    required this.priceWatch,
    required this.projectImages,
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
       pettyCash = PettyCashRepository(context),
       approvals = ApprovalRepository(context),
       syncCenter = SyncRepository(context);

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
    final portal = PortalRepository(context);
    final priceWatch = PriceWatchRepository(context);
    final projectImages = ProjectImageRepository(context);
    engine.afterPull = (sentSomething) async {
      // Separately, so a failure in one does not leave the other stale.
      for (final refresh in <Future<void> Function({bool force})>[
        progress.refreshCurrent,
        material.refreshCurrent,
        siteAlerts.refreshCurrent,
        contractors.refreshCurrent,
        priceWatch.refreshCurrent,
      ]) {
        try {
          await refresh(force: sentSomething);
        } on ApiFailure catch (e) {
          debugPrint('After-pull refresh failed: $e');
        }
      }
      await projectImages.sweepRemoved();
    };
    return AppServices._(
      progress: progress,
      material: material,
      siteAlerts: siteAlerts,
      contractors: contractors,
      portal: portal,
      priceWatch: priceWatch,
      projectImages: projectImages,
      db: db,
      api: api,
      session: session,
      sync: engine,
      scheduler: scheduler,
      connectivity: connectivity,
      context: context,
      onDispose: onDispose,
    );
  }

  final AppDatabase db;
  final NirmaanApi api;
  final SessionController session;
  final SyncEngine sync;
  final SyncScheduler scheduler;
  final ConnectivityMonitor connectivity;

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
  final PettyCashRepository pettyCash;
  final ApprovalRepository approvals;
  final SyncRepository syncCenter;
  final ProgressRepository progress;
  final MaterialRepository material;
  final SiteAlertsRepository siteAlerts;
  final ContractorRepository contractors;

  /// The contractor portal: all a contractor login reads.
  final PortalRepository portal;

  /// Recorded material prices, for anyone with `money.view`.
  final PriceWatchRepository priceWatch;
  final ProjectImageRepository projectImages;

  /// Restores the session and starts the triggers. Offline-safe.
  ///
  /// Rows stuck in `sending` from a killed process go back to `pending` here.
  Future<void> start() async {
    await sync.recoverInterrupted();
    if (connectivity is ConnectivityPlusMonitor) {
      await (connectivity as ConnectivityPlusMonitor).start();
    }
    await auth.restoreSession();
    scheduler.start();
  }

  /// Stops timers and closes the database.
  Future<void> dispose() async {
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
