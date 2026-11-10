import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart' show HajiriValue;
import 'package:nirmaan360/features/attendance/bloc/attendance_cubit.dart';
import 'package:nirmaan360/features/attendance/data/attendance_repository.dart';
import 'package:nirmaan360/features/attendance/data/worker_card.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/attendance/view/qr_camera.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../core/design/test_helpers.dart' show loadRealFonts;
import 'view/field_test_support.dart';

final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

Key k(String value) => ValueKey<String>(value);

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

/// A signed-in engineer on a plan with `labour`, as the fake server's default user is.
Future<TestApp> scanApp({void Function(FakeApi api)? setup}) async {
  final FakeApi server = FakeApi();
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

int workerId(TestApp app, int index) => app.api.workers[index]['id'] as int;

String workerName(TestApp app, int index) => app.api.workers[index]['name'] as String;

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> freshApp(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

/// A stand-in for the camera: one button for each code the test wants to hold up.
void fakeCamera(List<String> codes) {
  QrCameraView.cameraOverride = (context, onCode) => Column(
    children: <Widget>[
      for (final (i, code) in codes.indexed)
        TextButton(key: k('fake-card-$i'), onPressed: () => onCode(code), child: Text('card $i')),
    ],
  );
  addTearDown(() => QrCameraView.cameraOverride = null);
}

Future<void> hold(WidgetTester tester, int index) async {
  await tester.tap(find.byKey(k('fake-card-$index')));
  await settle(tester);
}

void main() {
  setUpAll(loadRealFonts);

  group('the card', () {
    test('only the shape is read on the phone', () {
      expect(WorkerCard.workerIdOf('N360W-12-1-${'a' * 20}'), 12);
      expect(WorkerCard.workerIdOf('  N360W-7-3-${'0123456789' * 2}\n'), 7);
      for (final String bad in <String>['', 'hello', 'N360W-12-1-short', 'N360W-x-1-${'a' * 20}', 'n360w-12-1-${'a' * 20}', 'N360W-12-1-${'A' * 20}']) {
        expect(WorkerCard.workerIdOf(bad), isNull, reason: bad);
      }
    });
  });

  group('scanning', () {
    late TestApp app;

    setUp(() async => app = await scanApp());
    tearDown(() => app.dispose());

    test('with a network the server marks the worker and the mark reaches the muster', () async {
      final int id = workerId(app, 0);
      final ScanOutcome outcome = await app.services.attendance.scan(1, app.api.cardFor(id));

      expect(outcome.result, ScanResult.marked);
      expect(outcome.workerName, workerName(app, 0));
      // Only the code and the uuid go to the server: the day is its own.
      expect(app.api.scanBodies.single.keys.toSet(), <String>{'code', 'client_uuid'});
      expect(await outbox(app), isEmpty);

      await app.sync();
      final line = (await app.services.attendance.watchDay(1, app.services.context.today).first).firstWhere((l) => l.worker.id == id);
      expect(line.mark!.status, AttendanceStatus.present);
    });

    test('a card scanned twice marks once, and the second says so without asking again', () async {
      final String card = app.api.cardFor(workerId(app, 0));
      await app.services.attendance.scan(1, card);
      await app.sync();

      final ScanOutcome again = await app.services.attendance.scan(1, card);
      expect(again.result, ScanResult.alreadyMarked);
      expect(again.status, AttendanceStatus.present);
      expect(app.api.scanBodies, hasLength(1));
      expect(app.api.attendance.where((a) => a['worker_id'] == workerId(app, 0)), hasLength(1));
    });

    test('a mark made by hand is never changed by a scan', () async {
      final int id = workerId(app, 0);
      await app.services.attendance.mark(projectId: 1, workerId: id, date: app.services.context.today, status: AttendanceStatus.half);

      final ScanOutcome outcome = await app.services.attendance.scan(1, app.api.cardFor(id));
      expect(outcome.result, ScanResult.alreadyMarked);
      expect(outcome.status, AttendanceStatus.half);
      expect(app.api.scanBodies, isEmpty);
    });

    test('something that is not a card is turned away on the phone', () async {
      expect((await app.services.attendance.scan(1, 'https://example.test/x')).result, ScanResult.notACard);
      expect(app.api.scanBodies, isEmpty);
      expect(await outbox(app), isEmpty);
    });

    test('a refusal is shown at once in the server\'s words, and never queued', () async {
      final String card = app.api.cardFor(workerId(app, 0));
      app.api.replacedCards.add(card);

      final ScanOutcome outcome = await app.services.attendance.scan(1, card);
      expect(outcome.result, ScanResult.refused);
      expect(outcome.message, contains('replaced by a newer one'));
      expect(await outbox(app), isEmpty);

      app.api.presentElsewhere[workerId(app, 1)] = 'Harbour Bridge';
      final ScanOutcome busy = await app.services.attendance.scan(1, app.api.cardFor(workerId(app, 1)));
      expect(busy.message, contains('Harbour Bridge'));
      expect(await outbox(app), isEmpty);
    });

    test('offline it is kept, shown present, and sent once when the network is back', () async {
      final int id = workerId(app, 0);
      app.connectivity.setOnline(false);
      final ScanOutcome outcome = await app.services.attendance.scan(1, app.api.cardFor(id));

      expect(outcome.result, ScanResult.queued);
      expect(outcome.workerName, workerName(app, 0));
      final OutboxRow row = (await outbox(app)).single;
      expect(row.type, OutboxType.attendanceScan);
      expect(row.title, workerName(app, 0));
      expect(row.title, isNot(contains('N360W')));
      expect(jsonDecode(row.payload)['scan_date'], app.services.context.today);
      expect(await app.services.attendance.watchUnsentScans(1, app.services.context.today).first, <int>{id});
      expect(app.api.scanBodies, isEmpty);

      app.connectivity.setOnline(true);
      await app.sync();
      expect(app.api.scanBodies, hasLength(1));
      expect(app.api.scanBodies.single['client_uuid'], row.wireUuid);
      expect((await outbox(app)).single.status, OutboxStatus.done);
      expect(await app.services.attendance.watchUnsentScans(1, app.services.context.today).first, isEmpty);
      expect(app.api.attendance.where((a) => a['worker_id'] == id), hasLength(1));
    });

    test('a reply lost after the server marked the worker makes no second mark', () async {
      final int id = workerId(app, 0);
      app.api.loseNextProgressReply = true;
      final ScanOutcome outcome = await app.services.attendance.scan(1, app.api.cardFor(id));

      // The phone does not know, so it keeps the scan under the same uuid.
      expect(outcome.result, ScanResult.queued);
      expect(app.api.attendance.where((a) => a['worker_id'] == id), hasLength(1));
      final OutboxRow row = (await outbox(app)).single;
      expect(app.api.scanBodies.single['client_uuid'], row.wireUuid);

      await app.sync();
      expect(app.api.scanBodies, hasLength(2));
      expect(app.api.attendance.where((a) => a['worker_id'] == id), hasLength(1));
      final OutboxRow done = (await outbox(app)).single;
      expect(done.status, OutboxStatus.done);
      expect(done.resultStatus, 'duplicate');
    });

    test('a scan kept past midnight is not sent: the server would mark the wrong day', () async {
      app.connectivity.setOnline(false);
      await app.services.attendance.scan(1, app.api.cardFor(workerId(app, 0)));
      app.connectivity.setOnline(true);
      app.clock.advance(const Duration(days: 1));
      app.api.advanceServerTime(const Duration(days: 1));

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, contains('by hand'));
      expect(app.api.scanBodies, isEmpty);
      expect(app.api.attendance.where((a) => a['worker_id'] == workerId(app, 0)), isEmpty);
    });

    test('a card replaced while the scan waited waits for a person, in the server\'s words', () async {
      final String card = app.api.cardFor(workerId(app, 0));
      app.connectivity.setOnline(false);
      await app.services.attendance.scan(1, card);
      app.api.replacedCards.add(card);
      app.connectivity.setOnline(true);

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionReason, contains('replaced by a newer one'));
    });

    test('a server fault is tried again, not handed to a person', () async {
      app.connectivity.setOnline(false);
      await app.services.attendance.scan(1, app.api.cardFor(workerId(app, 0)));
      app.connectivity.setOnline(true);
      app.api.failNextProgressPost = const ServerFailure('Server error', statusCode: 500);

      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.pending);
      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('a scan waiting to send reads as present on the muster, unless someone marked otherwise', () async {
      final int scanned = workerId(app, 0);
      final int other = workerId(app, 1);
      app.connectivity.setOnline(false);
      await app.services.attendance.scan(1, app.api.cardFor(scanned));
      await app.services.attendance.mark(projectId: 1, workerId: other, date: app.services.context.today, status: AttendanceStatus.absent);

      final cubit = AttendanceCubit(app.services);
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.load == AttendanceLoad.ready);
      await pumpEventQueue();
      expect(cubit.state.marks[scanned]?.value, HajiriValue.present);
      expect(cubit.state.marks[other]?.value, HajiriValue.absent);

      // A hand mark made after the scan wins over it.
      await cubit.mark(scanned, HajiriValue.half);
      expect(cubit.state.marks[scanned]?.value, HajiriValue.half);
    });

    test('only people who may manage attendance on a plan with labour can scan', () async {
      expect(app.services.attendance.canCapture, isTrue);

      final TestApp noPermission = await scanApp(setup: (api) => (api.user['can'] as Map)['attendance.manage'] = false);
      addTearDown(noPermission.dispose);
      expect(noPermission.services.attendance.canCapture, isFalse);

      final TestApp noPlan = await scanApp(setup: (api) => ((api.user['organization'] as Map)['features'] as List).remove('labour'));
      addTearDown(noPlan.dispose);
      expect(noPlan.services.attendance.canCapture, isFalse);
    });
  });

  group('screens', () {
    testWidgets('the muster offers Scan card to someone who may, and not to someone who may not', (WidgetTester tester) async {
      final TestApp app = await scanApp();
      addTearDown(app.dispose);
      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await settle(tester);
      expect(find.byKey(k('attendance-scan')), findsOneWidget);
      expect(find.text(en.attendanceScanCard), findsOneWidget);

      final TestApp none = await scanApp(setup: (api) => (api.user['can'] as Map)['attendance.manage'] = false);
      addTearDown(none.dispose);
      await freshApp(tester);
      await tester.pumpWidget(fieldApp(none.services, const AttendanceScreen()));
      await settle(tester);
      expect(find.byKey(k('attendance-scan')), findsNothing);
    });

    testWidgets('the camera page says what each card did, in words', (WidgetTester tester) async {
      final TestApp app = await scanApp();
      addTearDown(app.dispose);
      final String first = app.api.cardFor(workerId(app, 0));
      final String second = app.api.cardFor(workerId(app, 1));
      app.api.replacedCards.add(second);
      fakeCamera(<String>[first, second, 'hello']);
      await pumpShellApp(tester, app, location: Routes.attendanceScan);

      expect(find.text(en.scanHint), findsOneWidget);

      await hold(tester, 0);
      expect(find.text(en.scanMarked(workerName(app, 0))), findsOneWidget);
      expect(find.text(en.scanCount(1)), findsOneWidget);

      // The same card still in front of the camera is the same holding.
      await hold(tester, 0);
      expect(app.api.scanBodies, hasLength(1));

      await hold(tester, 1);
      expect(find.textContaining('replaced by a newer one'), findsOneWidget);

      await hold(tester, 2);
      expect(find.text(en.scanNotACard), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('offline the page says the scan is saved, and that it is sent today only', (WidgetTester tester) async {
      final TestApp app = await scanApp();
      addTearDown(app.dispose);
      fakeCamera(<String>[app.api.cardFor(workerId(app, 0))]);
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app, location: Routes.attendanceScan);

      await hold(tester, 0);
      expect(find.text(en.scanQueued(workerName(app, 0))), findsOneWidget);
      expect(find.text(en.scanQueuedNote), findsOneWidget);
      expect((await outbox(app)).single.status, OutboxStatus.pending);
    });

    testWidgets('the page is not reachable without the permission or the plan', (WidgetTester tester) async {
      final TestApp none = await scanApp(setup: (api) => (api.user['can'] as Map)['attendance.manage'] = false);
      addTearDown(none.dispose);
      fakeCamera(<String>[]);
      await pumpShellApp(tester, none, location: Routes.attendanceScan);
      expect(find.byType(AttendanceScreen), findsOneWidget);
      expect(find.text(en.scanTitle), findsNothing);
    });

    testWidgets('on the offline plan there is no way in, whatever the plan list says', (WidgetTester tester) async {
      final FakeApi api = FakeApi()..offlineCompany = true;
      final TestApp app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
      await app.db.into(app.db.projects).insert(ProjectsCompanion.insert(id: const Value(-1), code: 'L1', name: 'Local site'));
      await app.services.projects.selectProject(-1);
      expect(app.services.session.user!.features, contains('labour'));
      expect(app.services.attendance.canCapture, isFalse);
      fakeCamera(<String>[]);

      await pumpShellApp(tester, app, location: Routes.attendanceScan);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(api.refusedCalls, 0);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('the camera page fits 360dp in $locale with every kind of answer', (WidgetTester tester) async {
        final TestApp app = await scanApp();
        addTearDown(app.dispose);
        final String good = app.api.cardFor(workerId(app, 0));
        final String replaced = app.api.cardFor(workerId(app, 1));
        app.api.replacedCards.add(replaced);
        fakeCamera(<String>[good, replaced, 'hello']);
        await pumpShellApp(tester, app, location: Routes.attendanceScan, locale: locale);
        expect(tester.takeException(), isNull);

        for (final int i in <int>[0, 1, 2]) {
          await hold(tester, i);
          expect(tester.takeException(), isNull, reason: 'answer $i');
        }
        app.connectivity.setOnline(false);
        app.api.replacedCards.clear();
        app.clock.advance(const Duration(seconds: 10));
        await hold(tester, 1);
        expect(find.text(lookupAppLocalizations(Locale(locale)).scanQueuedNote), findsOneWidget);
        expect(tester.takeException(), isNull, reason: 'saved');
      });
    }
  });
}
