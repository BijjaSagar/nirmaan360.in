import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/photo_capture.dart';
import 'package:nirmaan360/features/attendance/bloc/selfie_sensors.dart';
import 'package:nirmaan360/features/attendance/data/selfie_repository.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../core/design/test_helpers.dart' show loadRealFonts;
import 'view/field_test_support.dart';

final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

Key k(String value) => ValueKey<String>(value);

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

/// Inside the 200 m fence of the fake site, about 15 m from its centre.
const SelfieFix inside = SelfieFix(lat: '18.5205000', lng: '73.8568000', accuracy: '12.0');

/// About 3 km away.
const SelfieFix far = SelfieFix(lat: '18.5500000', lng: '73.9000000', accuracy: '8.0');

Future<TestApp> selfieApp({void Function(FakeApi api)? setup, AttachmentStore? attachments}) async {
  final FakeApi server = FakeApi();
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server, attachments: attachments);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<SelfieOutcome> take(
  TestApp app, {
  SelfieFix fix = inside,
  int workerIndex = 0,
  DateTime? at,
  String photo = '/tmp/selfie.jpg',
}) async {
  final int id = app.api.workers[workerIndex]['id'] as int;
  final WorkerRow row = await (app.db.select(app.db.workers)..where((t) => t.id.equals(id))).getSingle();
  return app.services.selfies.submit(
    projectId: 1,
    siteId: 1,
    worker: row,
    photoPath: photo,
    fix: fix,
    capturedAt: at ?? app.clock.now(),
    consent: SelfieConsent(version: app.api.selfieConsentBody['version'] as String, text: 'note'),
  );
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> freshApp(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

Future<void> tap(WidgetTester tester, String key) async {
  await tester.ensureVisible(find.byKey(k(key)));
  await tester.pump();
  await tester.tap(find.byKey(k(key)));
  await settle(tester);
}

/// Stands in for the camera and the phone's location, and counts each ask.
class Sensors {
  Sensors({this.fix = inside, this.problem});

  SelfieFix fix;
  LocationProblem? problem;
  int photos = 0;
  int locations = 0;

  void install() {
    PhotoCapture.stand = () async {
      photos++;
      return '/tmp/selfie-$photos.jpg';
    };
    SelfieLocator.stand = () async {
      locations++;
      return problem == null ? LocationResult.found(fix) : LocationResult.lacking(problem!);
    };
    addTearDown(() {
      PhotoCapture.stand = null;
      SelfieLocator.stand = null;
    });
  }
}

void main() {
  setUpAll(loadRealFonts);

  group('consent', () {
    late TestApp app;

    setUp(() async => app = await selfieApp());
    tearDown(() => app.dispose());

    test('the note is loaded from the server and kept for when there is no network', () async {
      expect(await app.services.selfies.readConsent(), isNull);
      final SelfieConsent? loaded = await app.services.selfies.loadConsent();
      expect(loaded!.version, 'selfie-v1');
      expect(loaded.text, contains('agrees to it being taken'));

      app.connectivity.setOnline(false);
      final SelfieConsent? kept = await app.services.selfies.loadConsent();
      expect(kept!.version, 'selfie-v1');
      expect(app.api.selfieConsentReads, 1);
    });

    test('agreeing is remembered for this person and this version, and a new version asks again', () async {
      final SelfieConsent v1 = (await app.services.selfies.loadConsent())!;
      expect(await app.services.selfies.hasAccepted(v1), isFalse);

      await app.services.selfies.accept(v1);
      expect(await app.services.selfies.hasAccepted(v1), isTrue);
      expect(await app.services.selfies.hasAccepted(SelfieConsent(version: 'selfie-v2', text: v1.text)), isFalse);
    });

    test('a note that cannot be read is no note', () {
      expect(SelfieConsent.tryFromJson(<String, dynamic>{'version': 'selfie-v1', 'text': '  '}), isNull);
      expect(SelfieConsent.tryFromJson(<String, dynamic>{'text': 'x'}), isNull);
      expect(SelfieConsent.tryFromJson('nope'), isNull);
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await selfieApp());
    tearDown(() => app.dispose());

    test('inside the fence the server marks the worker, and says how far and how much is allowed', () async {
      final SelfieOutcome outcome = await take(app);

      expect(outcome.result, SelfieResult.marked);
      expect(outcome.workerName, app.api.workers.first['name']);
      expect(outcome.radius, 200);
      expect(outcome.distance, lessThan(30));
      final Map<String, String> form = app.api.selfieForms.single;
      expect(form['consent'], '1');
      expect(form['consent_version'], 'selfie-v1');
      expect(form['site_id'], '1');
      expect(form['lat'], inside.lat);
      expect(form['accuracy_m'], '12.0');
      expect(DateTime.parse(form['captured_at']!), isNotNull);
      expect(await outbox(app), isEmpty);

      await app.sync();
      final line = (await app.services.attendance.watchDay(1, app.services.context.today).first)
          .firstWhere((l) => l.worker.id == app.api.workers.first['id']);
      expect(line.mark!.status, AttendanceStatus.present);
    });

    test('outside the fence the server refuses at once, in its words, and nothing is kept', () async {
      final SelfieOutcome outcome = await take(app, fix: far);

      expect(outcome.result, SelfieResult.refused);
      expect(outcome.message, matches(RegExp(r'This location is \d+ m from .+\. Attendance needs to be within 200 m\.')));
      expect(await outbox(app), isEmpty);
      expect(app.api.attendance, isEmpty);
    });

    test('a reading vaguer than the fence is refused too', () async {
      final SelfieOutcome outcome = await take(app, fix: const SelfieFix(lat: '18.5205000', lng: '73.8568000', accuracy: '450.0'));
      expect(outcome.result, SelfieResult.refused);
      expect(outcome.message, contains('too rough'));
    });

    test('a second photo of the same worker on the same day is refused', () async {
      await take(app);
      await app.sync();
      final SelfieOutcome again = await take(app);
      expect(again.result, SelfieResult.refused);
      expect(again.message, contains('already has a selfie'));
      expect(app.api.selfiePosts, hasLength(1));
    });

    test('offline the photo is kept on the phone and sent once when the network is back', () async {
      final InMemoryAttachmentStore files = InMemoryAttachmentStore();
      final TestApp withFiles = await selfieApp(attachments: files);
      addTearDown(withFiles.dispose);
      withFiles.connectivity.setOnline(false);

      final SelfieOutcome outcome = await take(withFiles);
      expect(outcome.result, SelfieResult.queued);
      final OutboxRow row = (await outbox(withFiles)).single;
      expect(row.type, OutboxType.attendanceSelfie);
      expect(row.filePath, startsWith('attendance_selfies/'));
      expect(files.files, hasLength(1));
      expect(withFiles.api.selfieForms, isEmpty);

      withFiles.connectivity.setOnline(true);
      await withFiles.sync();

      expect(withFiles.api.selfieForms, hasLength(1));
      expect(withFiles.api.selfiePosts.keys, <String>[row.wireUuid]);
      expect((await outbox(withFiles)).single.status, OutboxStatus.done);
      // Only after the server has it is the file let go.
      expect(files.files, isEmpty);
    });

    test('the mark takes the day the photo was taken, even when it is sent days later', () async {
      app.connectivity.setOnline(false);
      final DateTime taken = app.clock.now();
      await take(app, at: taken);
      app.connectivity.setOnline(true);
      app.clock.advance(const Duration(days: 3));
      app.api.advanceServerTime(const Duration(days: 3));

      await app.sync();

      final Map<String, dynamic> stored = app.api.selfiePosts.values.single;
      expect(stored['attendance_date'], '2026-11-09');
      expect((await outbox(app)).single.status, OutboxStatus.done);
      expect(app.api.attendance.single['attendance_date'], '2026-11-09');
    });

    test('a photo older than the server allows waits for a person, in its words', () async {
      app.connectivity.setOnline(false);
      await take(app);
      app.connectivity.setOnline(true);
      app.clock.advance(const Duration(days: 8));
      app.api.advanceServerTime(const Duration(days: 8));

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionReason, contains('-7 days'));
      expect(app.api.selfiePosts, isEmpty);
    });

    test('a reply lost after the server stored the photo makes no second photo or mark', () async {
      app.api.loseNextProgressReply = true;
      final SelfieOutcome outcome = await take(app);
      expect(outcome.result, SelfieResult.queued);
      expect(app.api.selfiePosts, hasLength(1));

      await app.sync();

      expect(app.api.selfieForms, hasLength(2));
      expect(app.api.selfiePosts, hasLength(1));
      expect(app.api.attendance, hasLength(1));
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('a photo taken under a note that has changed is held, not recorded as agreed to the new one', () async {
      app.connectivity.setOnline(false);
      await take(app);
      app.connectivity.setOnline(true);
      app.api.selfieConsentBody = <String, dynamic>{'version': 'selfie-v2', 'text': 'A new note.'};

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionReason, contains('consent note was changed'));
      expect(app.api.selfieForms, isEmpty);
    });

    test('a place the server turns away when it arrives waits for a person', () async {
      app.connectivity.setOnline(false);
      await take(app, fix: far);
      app.connectivity.setOnline(true);

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionReason, contains('Attendance needs to be within 200 m'));
    });

    test('discarding a kept photo takes the picture of the worker off the phone', () async {
      final InMemoryAttachmentStore files = InMemoryAttachmentStore();
      final TestApp withFiles = await selfieApp(attachments: files);
      addTearDown(withFiles.dispose);
      withFiles.connectivity.setOnline(false);
      await take(withFiles, fix: far);
      withFiles.connectivity.setOnline(true);
      await withFiles.sync();
      final OutboxRow row = (await outbox(withFiles)).single;
      expect(row.status, OutboxStatus.attention);
      expect(files.files, hasLength(1));

      await withFiles.services.syncCenter.discard(row.id);

      expect(await outbox(withFiles), isEmpty);
      expect(files.files, isEmpty);
    });

    test('a server fault is tried again, not handed to a person', () async {
      app.connectivity.setOnline(false);
      await take(app);
      app.connectivity.setOnline(true);
      app.api.failNextProgressPost = const ServerFailure('Server error', statusCode: 500);

      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.pending);
      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('a photo waiting to send reads as present on the muster for the day it was taken', () async {
      app.connectivity.setOnline(false);
      await take(app);
      final Set<int> waiting = await app.services.attendance.readUnsentScans(1, '2026-11-09');
      expect(waiting, <int>{app.api.workers.first['id'] as int});
      expect(await app.services.attendance.readUnsentScans(1, '2026-11-08'), isEmpty);
    });

    test('nothing the phone keeps for it holds the photo\'s place in the title or the sync log', () async {
      app.connectivity.setOnline(false);
      await take(app);
      final OutboxRow row = (await outbox(app)).single;
      expect('${row.title} ${row.subtitle}', isNot(contains('18.52')));
      expect(jsonDecode(row.payload)['local']['worker_name'], row.title);
    });

    test('only people who may manage attendance on a plan with labour are offered it', () async {
      expect(app.services.selfies.isOpenToUser, isTrue);

      final TestApp none = await selfieApp(setup: (api) => (api.user['can'] as Map)['attendance.manage'] = false);
      addTearDown(none.dispose);
      expect(none.services.selfies.isOpenToUser, isFalse);

      final TestApp noPlan = await selfieApp(setup: (api) => ((api.user['organization'] as Map)['features'] as List).remove('labour'));
      addTearDown(noPlan.dispose);
      expect(noPlan.services.selfies.isOpenToUser, isFalse);
    });
  });

  group('screens', () {
    testWidgets('the muster offers Selfie beside Scan card', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await settle(tester);
      expect(find.byKey(k('attendance-selfie')), findsOneWidget);
      expect(find.byKey(k('attendance-scan')), findsOneWidget);
    });

    testWidgets('the note comes first: no camera and no location until the worker agrees', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      final Sensors sensors = Sensors()..install();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);

      expect(find.byKey(k('selfie-consent-text')), findsOneWidget);
      expect(find.textContaining('agrees to it being taken'), findsOneWidget);
      expect(find.text(en.selfieConsentVersion('selfie-v1')), findsOneWidget);
      expect(find.byKey(k('selfie-take')), findsNothing);
      expect(sensors.photos, 0);
      expect(sensors.locations, 0);

      await tap(tester, 'selfie-agree');
      expect(find.byKey(k('selfie-take')), findsOneWidget);
      // Nothing has asked for the location yet: it waits for the photo.
      expect(sensors.locations, 0);
    });

    testWidgets('once agreed it is not asked again, until the server\'s note changes', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      Sensors().install();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);
      await tap(tester, 'selfie-agree');

      await freshApp(tester);
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);
      expect(find.byKey(k('selfie-agree')), findsNothing);
      expect(find.byKey(k('selfie-take')), findsOneWidget);

      app.api.selfieConsentBody = <String, dynamic>{'version': 'selfie-v2', 'text': 'A new note for the worker.'};
      await freshApp(tester);
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);
      expect(find.byKey(k('selfie-agree')), findsOneWidget);
      expect(find.text('A new note for the worker.'), findsOneWidget);
    });

    testWidgets('with no copy of the note and no network it says so, and offers nothing else', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);
      Sensors().install();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);

      expect(find.text(en.selfieConsentNeedsNetwork), findsOneWidget);
      expect(find.byKey(k('selfie-take')), findsNothing);
      expect(find.byKey(k('selfie-agree')), findsNothing);
    });

    testWidgets('a project with no site cannot be photographed', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      await app.db.delete(app.db.sites).go();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);
      expect(find.text(en.selfieNoSiteTitle), findsOneWidget);
    });

    testWidgets('the photo, then the place, then the mark: the location is read once, at the photo', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      await app.services.selfies.accept((await app.services.selfies.loadConsent())!);
      final Sensors sensors = Sensors()..install();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);

      await tap(tester, 'selfie-send');
      expect(find.text(en.selfieWorkerRequired), findsOneWidget);

      await tester.tap(find.byKey(k('selfie-worker')));
      await settle(tester);
      await tester.tap(find.byKey(k('field-pick-${app.api.workers.first['id']}')));
      await settle(tester);

      await tap(tester, 'selfie-send');
      expect(find.text(en.selfiePhotoRequired), findsOneWidget);
      expect(app.api.selfieForms, isEmpty);

      await tap(tester, 'selfie-take');
      expect(sensors.photos, 1);
      expect(sensors.locations, 1);
      expect(find.text(en.selfieLocationNote('12')), findsOneWidget);

      await tap(tester, 'selfie-send');
      expect(sensors.locations, 1);
      expect(app.api.selfieForms, hasLength(1));
      expect(find.textContaining('marked present'), findsOneWidget);
      expect(find.textContaining('200 m allowed'), findsOneWidget);
      // The form is ready for the next worker.
      expect(find.text(en.selfieWorkerChoose), findsOneWidget);
    });

    testWidgets('without the location the photo cannot be sent, and the reason is plain', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      await app.services.selfies.accept((await app.services.selfies.loadConsent())!);
      Sensors(problem: LocationProblem.deniedForever).install();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);

      await tester.tap(find.byKey(k('selfie-worker')));
      await settle(tester);
      await tester.tap(find.byKey(k('field-pick-${app.api.workers.first['id']}')));
      await settle(tester);
      await tap(tester, 'selfie-take');
      expect(find.text(en.selfieLocationDenied), findsOneWidget);

      await tap(tester, 'selfie-send');
      expect(app.api.selfieForms, isEmpty);
      expect(await outbox(app), isEmpty);
    });

    testWidgets('a refusal from the server stays on the form, so it can be taken again nearer', (WidgetTester tester) async {
      final TestApp app = await selfieApp();
      addTearDown(app.dispose);
      await app.services.selfies.accept((await app.services.selfies.loadConsent())!);
      final Sensors sensors = Sensors(fix: far)..install();
      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);

      await tester.tap(find.byKey(k('selfie-worker')));
      await settle(tester);
      await tester.tap(find.byKey(k('field-pick-${app.api.workers.first['id']}')));
      await settle(tester);
      await tap(tester, 'selfie-take');
      await tap(tester, 'selfie-send');
      expect(find.textContaining('Attendance needs to be within 200 m'), findsOneWidget);
      expect(await outbox(app), isEmpty);

      sensors.fix = inside;
      await tap(tester, 'selfie-take');
      await tap(tester, 'selfie-send');
      expect(find.textContaining('marked present'), findsOneWidget);
    });

    testWidgets('the page is not reachable without the permission or the plan', (WidgetTester tester) async {
      final TestApp none = await selfieApp(setup: (api) => (api.user['can'] as Map)['attendance.manage'] = false);
      addTearDown(none.dispose);
      await pumpShellApp(tester, none, location: Routes.attendanceSelfie);
      expect(find.byType(AttendanceScreen), findsOneWidget);
      expect(find.text(en.selfieTitle), findsNothing);
    });

    testWidgets('on the offline plan there is no way in, and the location is never asked', (WidgetTester tester) async {
      final FakeApi api = FakeApi()..offlineCompany = true;
      final TestApp app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
      await app.db.into(app.db.projects).insert(ProjectsCompanion.insert(id: const Value(-1), code: 'L1', name: 'Local site'));
      await app.services.projects.selectProject(-1);
      final Sensors sensors = Sensors()..install();
      expect(app.services.selfies.isOpenToUser, isFalse);

      await pumpShellApp(tester, app, location: Routes.attendanceSelfie);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(sensors.locations, 0);
      expect(api.refusedCalls, 0);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every step fits 360dp in $locale', (WidgetTester tester) async {
        final TestApp app = await selfieApp();
        addTearDown(app.dispose);
        final Sensors sensors = Sensors(fix: far)..install();
        await pumpShellApp(tester, app, location: Routes.attendanceSelfie, locale: locale);
        expect(tester.takeException(), isNull, reason: 'note');

        await tap(tester, 'selfie-agree');
        expect(tester.takeException(), isNull, reason: 'form');

        await tester.tap(find.byKey(k('selfie-worker')));
        await settle(tester);
        await tester.tap(find.byKey(k('field-pick-${app.api.workers.first['id']}')));
        await settle(tester);
        await tap(tester, 'selfie-take');
        expect(tester.takeException(), isNull, reason: 'photo');
        await tap(tester, 'selfie-send');
        expect(tester.takeException(), isNull, reason: 'refused');

        sensors.problem = LocationProblem.denied;
        await tap(tester, 'selfie-take');
        expect(tester.takeException(), isNull, reason: 'location denied');

        sensors
          ..problem = null
          ..fix = inside;
        await tap(tester, 'selfie-take');
        app.connectivity.setOnline(false);
        await tap(tester, 'selfie-send');
        expect(tester.takeException(), isNull, reason: 'saved');
      });
    }
  });
}
