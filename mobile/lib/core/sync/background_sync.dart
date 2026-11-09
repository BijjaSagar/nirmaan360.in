import 'dart:ui';

import 'package:flutter/widgets.dart';
import 'package:workmanager/workmanager.dart';

import '../api/dio_nirmaan_api.dart';
import '../api/nirmaan_api.dart';
import '../config/app_config.dart';
import '../db/app_database.dart';
import '../session/token_store.dart';
import 'attachment_store.dart';
import 'clock.dart';
import 'connectivity.dart';
import 'sync_engine.dart';
import 'sync_status.dart';

/// Registers the periodic background sync. Call from the app shell after
/// start-up; safe to call again, the existing registration is updated.
Future<void> registerBackgroundSync() async {
  await Workmanager().initialize(backgroundSyncDispatcher);
  await Workmanager().registerPeriodicTask(
    AppConfig.backgroundSyncTask,
    AppConfig.backgroundSyncTask,
    frequency: const Duration(minutes: 15),
    constraints: Constraints(networkType: NetworkType.connected),
    existingWorkPolicy: ExistingPeriodicWorkPolicy.update,
  );
}

/// Entry point the OS calls in a background engine.
@pragma('vm:entry-point')
void backgroundSyncDispatcher() {
  WidgetsFlutterBinding.ensureInitialized();
  DartPluginRegistrant.ensureInitialized();

  Workmanager().executeTask((task, inputData) async {
    final tokens = SecureTokenStore();
    if ((await tokens.read()) == null) return true;

    final db = AppDatabase.open();
    final monitor = ConnectivityPlusMonitor();
    await monitor.start();
    final attachments = DirectoryAttachmentStore.documents();
    final api = DioNirmaanApi(readToken: tokens.read);
    try {
      return await runBackgroundSyncOnce(
        db: db,
        api: api,
        attachments: attachments,
        connectivity: monitor,
      );
    } finally {
      await monitor.dispose();
      await db.close();
    }
  });
}

/// One push and pull with its own engine. Lets the platform retry only for
/// a server fault; offline and sign-in problems wait for the next period.
Future<bool> runBackgroundSyncOnce({
  required AppDatabase db,
  required NirmaanApi api,
  required AttachmentStore attachments,
  required ConnectivityMonitor connectivity,
  AppClock clock = const SystemClock(),
}) async {
  final engine = SyncEngine(
    db: db,
    api: api,
    clock: clock,
    attachments: attachments,
    connectivity: connectivity,
  );
  try {
    final result = await engine.syncNow();
    return result.outcome != SyncOutcome.serverError;
  } finally {
    await engine.dispose();
  }
}
