import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';
// sqlite3 is resolved through drift; this one type names the setup callback.
// ignore: depend_on_referenced_packages
import 'package:sqlite3/common.dart';

import '../config/app_config.dart';

/// Opens the database on a drift-managed background isolate, shared by name
/// so the workmanager callback and the UI use one connection in this process.
DatabaseConnection openAppConnection() {
  return driftDatabase(
    name: AppConfig.databaseName,
    native: const DriftNativeOptions(
      shareAcrossIsolates: true,
      setup: configureSqlite,
    ),
  );
}

/// WAL with synchronous FULL so a committed entry survives a crash or power
/// loss. Must stay a top-level function: drift sends it across isolates.
void configureSqlite(CommonDatabase database) {
  database.execute('PRAGMA journal_mode = WAL;');
  database.execute('PRAGMA synchronous = FULL;');
  database.execute('PRAGMA busy_timeout = 5000;');
}
