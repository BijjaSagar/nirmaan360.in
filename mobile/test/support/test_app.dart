import 'dart:async';

import 'package:drift/drift.dart'
    show DatabaseConnection, driftRuntimeOptions;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/token_store.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/clock.dart';
import 'package:nirmaan360/core/sync/connectivity.dart';

import 'fake_api.dart';

export 'fake_api.dart';

/// A clock tests can move by hand. Starts at a fixed instant.
class FakeClock implements AppClock {
  FakeClock([DateTime? start])
    : _now = start ?? DateTime.utc(2026, 11, 9, 4, 30);

  DateTime _now;

  @override
  DateTime now() => _now;

  void advance(Duration duration) => _now = _now.add(duration);

  void set(DateTime instant) => _now = instant;
}

/// Connectivity under test control.
class FakeConnectivity implements ConnectivityMonitor {
  FakeConnectivity({bool online = true}) : _isOnline = online;

  final _changes = StreamController<bool>.broadcast();
  bool _isOnline;

  @override
  bool get isOnline => _isOnline;

  @override
  Stream<bool> get changes => _changes.stream;

  void setOnline(bool online) {
    if (online == _isOnline) return;
    _isOnline = online;
    _changes.add(online);
  }
}

/// An in-memory database whose stream queries close synchronously, so Drift
/// leaves no timers behind for a widget test to trip over.
AppDatabase newTestDatabase() {
  // Each test opens its own in-memory database on its own executor.
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
  return AppDatabase.forTesting(
    DatabaseConnection(
      NativeDatabase.memory(),
      closeStreamsSynchronously: true,
    ),
  );
}

/// Real repositories on an in-memory database, with a fake server.
///
/// ```dart
/// testWidgets('shows projects', (tester) async {
///   final app = await TestApp.create(seed: true);
///   addTearDown(app.dispose);
///
///   await tester.pumpWidget(MyScreen(repo: app.services.projects));
///   await pumpDrift(tester);
///
///   expect(find.text('Kalyani Tower B'), findsOneWidget);
///
///   await app.services.issues.raise(projectId: 1, title: 'Crack');
///   await pumpDrift(tester);
/// });
/// ```
///
/// Await data calls directly in the test body. Do not wrap them in
/// `tester.runAsync`: a query started by a widget holds drift's lock inside
/// the test's fake-async zone, and a call from the real zone then waits on it
/// forever. The default [InMemoryAttachmentStore] exists for the same reason,
/// because real file I/O never completes in that zone. Tests that need real
/// files belong in plain `test()`, like the upload tests in test/core/sync.
class TestApp {
  TestApp._({
    required this.services,
    required this.api,
    required this.clock,
    required this.connectivity,
    required this.tokens,
    required this.attachments,
  });

  final AppServices services;
  final FakeApi api;
  final FakeClock clock;
  final FakeConnectivity connectivity;
  final InMemoryTokenStore tokens;
  final AttachmentStore attachments;

  AppDatabase get db => services.db;

  /// Builds the app. With [seed] (the default) the fake server gets demo
  /// data and the app signs in and pulls it, so screens have data at once.
  /// Pass [attachments] (e.g. a temp directory) to exercise real file uploads.
  static Future<TestApp> create({
    bool seed = true,
    bool signedIn = true,
    FakeApi? api,
    FakeClock? clock,
    FakeConnectivity? connectivity,
    AttachmentStore? attachments,
    AppDatabase? db,
  }) async {
    final fakeApi = api ?? FakeApi();
    final fakeClock = clock ?? FakeClock();
    final fakeConnectivity = connectivity ?? FakeConnectivity();
    final store = attachments ?? InMemoryAttachmentStore();
    final tokens = InMemoryTokenStore();

    final services = AppServices.forTesting(
      db: db ?? newTestDatabase(),
      api: fakeApi,
      tokens: tokens,
      clock: fakeClock,
      connectivity: fakeConnectivity,
      attachments: store,
    );

    final app = TestApp._(
      services: services,
      api: fakeApi,
      clock: fakeClock,
      connectivity: fakeConnectivity,
      tokens: tokens,
      attachments: store,
    );

    if (signedIn) {
      await services.session.startSession(
        'fake-token',
        CurrentUser.fromJson(fakeApi.user),
      );
    }
    if (seed) {
      fakeApi.seedDemo();
      await services.sync.syncNow();
    }
    return app;
  }

  /// Runs a full sync against the fake server.
  Future<void> sync() => services.sync.syncNow();

  /// Closes everything. Register with `addTearDown(app.dispose)`.
  Future<void> dispose() => services.dispose();
}

/// Lets Drift stream results reach widgets, without `pumpAndSettle` hanging
/// on an infinite animation. Call after `pumpWidget` and after each write.
Future<void> pumpDrift(WidgetTester tester, [int frames = 5]) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(Duration.zero);
  }
}
