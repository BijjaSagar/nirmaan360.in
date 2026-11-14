import 'dart:async';

import 'package:drift/drift.dart';

import 'connection.dart';
import 'converters.dart';
import '../money/money.dart';
import 'status.dart';
import 'tables/field_tables.dart';
import 'tables/masters.dart';
import 'tables/outbox.dart';

export 'converters.dart'
    show ManpowerLine, MachineryLine, WorkLine;
export 'status.dart';
export 'tables/outbox.dart' show OutboxStatus, OutboxType, AttentionCode;

part 'app_database.g.dart';

@DriftDatabase(
  tables: [
    Projects,
    Sites,
    BoqItems,
    Workers,
    Wallets,
    Users,
    KeyValues,
    Dprs,
    DprPhotos,
    Measurements,
    Issues,
    AttendanceMarks,
    PettyCashEntries,
    ApprovalsInbox,
    Outbox,
  ],
)
class AppDatabase extends _$AppDatabase {
  AppDatabase(super.executor);

  /// Use with `NativeDatabase.memory()` or a `DatabaseConnection` in tests.
  AppDatabase.forTesting(super.executor);

  /// Production: background isolate, shared by name across engines.
  factory AppDatabase.open() => AppDatabase(openAppConnection());

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (m) => m.createAll(),
    onUpgrade: (m, from, to) async {
      if (from < 2) {
        await m.addColumn(projects, projects.imageUrl);
        // Rows already here have not changed, so a delta pull would never
        // bring their new field.
        await customStatement(
          'INSERT OR REPLACE INTO key_values (key, value) VALUES (?, ?)',
          <Object>[MetaKey.fullRefresh, '1'],
        );
      }
    },
  );

  /// Reads one key-value entry.
  Future<String?> readMeta(String key) async {
    final row = await (select(keyValues)..where((t) => t.key.equals(key)))
        .getSingleOrNull();
    return row?.value;
  }

  /// Watches one key-value entry.
  Stream<String?> watchMeta(String key) {
    return (select(keyValues)..where((t) => t.key.equals(key)))
        .watchSingleOrNull()
        .map((row) => row?.value);
  }

  Future<void> writeMeta(String key, String value) {
    return into(keyValues).insertOnConflictUpdate(
      KeyValuesCompanion.insert(key: key, value: value),
    );
  }

  Future<void> deleteMeta(String key) {
    return (delete(keyValues)..where((t) => t.key.equals(key))).go();
  }

  /// A stream that recomputes [load] whenever any of [tables] changes.
  ///
  /// Used where SQL alone cannot produce the result, chiefly decimal sums
  /// that must not pass through sqlite floats. Subscribes before the first
  /// load, and coalesces updates that arrive while a load is running.
  Stream<T> watchDerived<T>(
    Set<ResultSetImplementation<dynamic, dynamic>> tables,
    Future<T> Function() load,
  ) {
    return Stream.multi((controller) {
      var loading = false;
      var dirty = false;
      var cancelled = false;

      Future<void> run() async {
        if (loading) {
          dirty = true;
          return;
        }
        loading = true;
        do {
          dirty = false;
          try {
            final value = await load();
            if (!cancelled) controller.add(value);
          } catch (error, stack) {
            if (!cancelled) controller.addError(error, stack);
          }
        } while (dirty && !cancelled);
        loading = false;
      }

      final subscription = tableUpdates(
        TableUpdateQuery.onAllTables(tables),
      ).listen((_) => run());

      controller.onCancel = () {
        cancelled = true;
        return subscription.cancel();
      };

      run();
    });
  }
}
