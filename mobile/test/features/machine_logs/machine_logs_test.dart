import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/ids.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/machine_logs/bloc/machine_log_form_cubit.dart';
import 'package:nirmaan360/features/machine_logs/data/machine_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ValidationFailure _tooMany = ValidationFailure(
  'That would be more than 24 hours for this machine on one day.',
  errors: <String, List<String>>{
    'hours': <String>['That would be more than 24 hours for this machine on one day.'],
  },
);

Future<TestApp> machineApp({
  bool log = true,
  bool plan = true,
  bool inspect = false,
  void Function(FakeApi api)? setup,
}) async {
  final FakeApi server = FakeApi();
  if (log) server.grant('asset.log');
  if (plan) ((server.user['organization'] as Map)['features'] as List).add('assets');
  if (inspect) server.grantInspections();
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

/// Queues a log for the first machine (a JCB with an hours meter) unless told otherwise.
Future<String> addLog(
  TestApp app, {
  int machineId = 1,
  String? date,
  Hours? hours,
  Qty? km,
  Hours? breakdown,
  Qty? opening,
  Qty? closing,
  String? operatorName,
}) async {
  final MachineOverview overview = (await app.services.machines.readOverview(1))!;
  final String uuid = newUuid();
  await app.services.machines.log(
    uuid: uuid,
    projectId: 1,
    machine: overview.machine(machineId)!,
    date: date ?? app.services.context.today,
    hours: hours ?? (km == null && breakdown == null && opening == null ? Hours.parse('7.5') : null),
    km: km,
    breakdownHours: breakdown,
    openingReading: opening,
    closingReading: closing,
    operatorName: operatorName,
  );
  return uuid;
}

/// A second app on the same tester must not inherit the first one's state.
Future<void> freshApp(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> pumpScreen(
  WidgetTester tester,
  TestApp app, {
  String start = Routes.machines,
  String locale = 'en',
}) async {
  tester.view.devicePixelRatio = 3;
  tester.view.physicalSize = const Size(360, 800) * 3;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final AppGate gate = AppGate(app.services);
  addTearDown(gate.close);
  await pumpDrift(tester);
  final GoRouter router = buildRouter(gate, initialLocation: start);
  addTearDown(router.dispose);

  await tester.pumpWidget(
    RepositoryProvider<AppServices>.value(
      value: app.services,
      child: BlocProvider<AppGate>.value(
        value: gate,
        child: MaterialApp.router(
          routerConfig: router,
          theme: buildN360Theme(),
          locale: Locale(locale),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          builder: (context, navigator) => MediaQuery(
            data: MediaQuery.of(context).copyWith(disableAnimations: true),
            child: LedgerPaper.root(child: navigator),
          ),
        ),
      ),
    ),
  );
  await settle(tester);
}

Key k(String value) => ValueKey<String>(value);

Future<void> tap(WidgetTester tester, Key key) async {
  await tester.ensureVisible(find.byKey(key));
  await tester.pump();
  await tester.tap(find.byKey(key));
  await settle(tester);
}

Future<void> typeIn(WidgetTester tester, String key, String text) async {
  final Finder field = find.descendant(of: find.byKey(k(key)), matching: find.byType(TextField));
  await tester.ensureVisible(field);
  await tester.pump();
  await tester.enterText(field, text);
  await settle(tester);
}

Future<void> pickMachine(WidgetTester tester, int id) async {
  await tap(tester, k('machine-form-machine'));
  await tester.tap(find.byKey(k('field-pick-$id')));
  await settle(tester);
}

Iterable<String> keysOf(Object? value) sync* {
  if (value is Map) {
    for (final MapEntry<Object?, Object?> entry in value.entries) {
      yield '${entry.key}';
      yield* keysOf(entry.value);
    }
  } else if (value is List) {
    for (final Object? item in value) {
      yield* keysOf(item);
    }
  }
}

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('reading', () {
    test('keeps the machines and the latest logs of the project, with hours as Hours', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);

      final MachineOverview seen = (await app.services.machines.readOverview(1))!;
      expect(seen.machines.map((m) => m.code), <String>['AST-0001', 'AST-0002', 'AST-0003']);
      expect(seen.machine(2)!.meterIsKm, isTrue);
      expect(seen.machine(3)!.underRepair, isTrue);
      expect(seen.logs.map((l) => l.id), <int>[22, 21, 23]);

      final MachineLog jcb = seen.logs.firstWhere((l) => l.id == 21);
      expect(jcb.hours, Hours.parse('8.5'));
      expect(jcb.hours, isA<Hours>());
      expect(jcb.operatorName, 'Ramesh');
      expect(jcb.hasKm, isFalse);
      expect(seen.logs.firstWhere((l) => l.id == 22).km, Qty.parse('42.5'));
      expect(seen.logs.firstWhere((l) => l.id == 23).breakdownHours, Hours.parse('4'));
    });

    test('every page of machines is read', () async {
      final TestApp app = await machineApp(setup: (FakeApi api) => api.machineAssetsPerPage = 2);
      addTearDown(app.dispose);

      expect((await app.services.machines.readOverview(1))!.machines, hasLength(3));
    });

    test('a figure that is not a number does not stop the read, or the sync', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      app.api.machineLogRows[0]['hours'] = 'n/a';
      app.api.machineLogRows[1]['km'] = null;
      app.api.machineAssetRows[0]['id'] = null;

      await app.services.machines.refresh(1);
      final MachineOverview seen = (await app.services.machines.readOverview(1))!;

      expect(seen.logs.firstWhere((l) => l.id == 21).hours, isNull);
      expect(seen.machines.map((m) => m.id), <int>[2, 3], reason: 'a row with no id cannot be logged against');
      expect((await app.services.sync.syncNow()).isSuccess, isTrue);
    });

    test('nothing about a rate or a cost is asked for or kept', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);

      final Object? stored = jsonDecode((await app.db.readMeta(MetaKey.machines(1)))!);
      final List<String> keys = keysOf(stored).toList();
      expect(keys, isNotEmpty);
      expect(keys.where((key) => RegExp('rate|cost|amount|price|hire|rent').hasMatch(key)), isEmpty);
    });

    test('the last answer stays when the server cannot be reached', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);

      app.api.offline = true;
      await expectLater(app.services.machines.refresh(1), throwsA(isA<ApiFailure>()));

      expect((await app.services.machines.readOverview(1))!.logs, hasLength(3));
    });

    test('a refusal replaces what was kept, and is not an error', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);

      app.api.machineReadFailure = const ServerFailure('Forbidden', statusCode: 403);
      await app.services.machines.refresh(1);

      final MachineOverview seen = (await app.services.machines.readOverview(1))!;
      expect(seen.refused, isTrue);
      expect(seen.logs, isEmpty);
    });

    test('without the permission or the plan the phone does not ask', () async {
      final TestApp noPermission = await machineApp(log: false);
      addTearDown(noPermission.dispose);
      final TestApp noPlan = await machineApp(plan: false);
      addTearDown(noPlan.dispose);

      expect(await noPermission.services.machines.readOverview(1), isNull);
      expect(await noPlan.services.machines.readOverview(1), isNull);
      expect(noPermission.api.machineAssetReads, 0);
      expect(noPlan.api.machineAssetReads, 0);
    });

    test('a copy kept from before the permission went is dropped on the next sync', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.machines(1)), isNotNull);

      (app.api.user['can'] as Map)['asset.log'] = false;
      await app.sync();

      expect(await app.db.readMeta(MetaKey.machines(1)), isNull);
    });

    test('a removed project takes its copy with it', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.machines(1)), isNotNull);

      app.api.pullBodyOverride = <String, dynamic>{
        'server_time': '2026-11-09T14:27:54+05:30',
        'deleted': <String, dynamic>{
          'projects': <int>[1],
        },
      };
      await app.sync();

      expect(await app.db.readMeta(MetaKey.machines(1)), isNull);
    });
  });

  group('the server\'s rules, checked on the phone first', () {
    Future<void> refused(TestApp app, Future<void> Function() act, String field) async {
      await expectLater(
        act(),
        throwsA(isA<ValidationFailure>().having((e) => e.errors.keys, 'fields', contains(field))),
      );
      expect(await outbox(app), isEmpty);
    }

    test('something worked, or lost to breakdown, is needed', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      final Machine jcb = (await app.services.machines.readOverview(1))!.machine(1)!;

      await refused(
        app,
        () => app.services.machines.log(uuid: newUuid(), projectId: 1, machine: jcb, date: '2026-11-09'),
        'hours',
      );
    });

    test('both readings give the hours only on a machine whose meter counts them', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      final MachineOverview overview = (await app.services.machines.readOverview(1))!;

      // The hired crane has no meter: the server would find nothing worked.
      await refused(
        app,
        () => app.services.machines.log(
          uuid: newUuid(),
          projectId: 1,
          machine: overview.machine(3)!,
          date: '2026-11-09',
          openingReading: Qty.parse('10'),
          closingReading: Qty.parse('20'),
        ),
        'hours',
      );
      // The JCB counts hours, so its readings are enough.
      await addLog(app, opening: Qty.parse('100'), closing: Qty.parse('108.5'));
      expect(await outbox(app), hasLength(1));
    });

    test('the closing reading cannot be below the opening one', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);

      await refused(app, () => addLog(app, hours: Hours.parse('1'), opening: Qty.parse('50'), closing: Qty.parse('40')), 'closing_reading');
    });

    test('hours are from 0 to 24, and a day cannot be in the future', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);

      await refused(app, () => addLog(app, hours: Hours.parse('24.5')), 'hours');
      await refused(app, () => addLog(app, breakdown: Hours.parse('25')), 'breakdown_hours');
      await refused(app, () => addLog(app, date: '2099-01-01'), 'log_date');
    });

    test('hours keep one decimal, as Hours does, and a typed figure is never rounded', () {
      expect(Hours.hasExtraDecimals('7.25'), isTrue);
      expect(Hours.hasExtraDecimals('7.5'), isFalse);
      expect(Hours.hasExtraDecimals('7'), isFalse);
      expect(Hours.parse('7.5').toWire(), '7.5');
    });
  });

  group('adding a log', () {
    test('offline it waits, then reaches the server once', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      final String uuid = await addLog(app, hours: Hours.parse('7.5'), operatorName: ' Suresh ');

      final MachineOverview waiting = (await app.services.machines.readOverview(1))!;
      expect(waiting.unsent, 1);
      final MachineLog first = waiting.logs.first;
      expect(first.notSent, isTrue);
      expect(first.hours, Hours.parse('7.5'));
      expect(first.operatorName, 'Suresh');
      expect((await outbox(app)).single.title, 'AST-0001 · JCB 3DX');

      app.api.offline = false;
      await app.sync();

      expect(app.api.machineLogPosts.keys, <String>[uuid]);
      expect(app.api.machineLogPostCalls, 1);
      final Map<String, dynamic> stored = app.api.machineLogPosts[uuid]!;
      expect(stored['hours'], '7.500');
      expect(stored['project_id'], 1);
      expect(stored['asset_id'], 1);
      expect((await outbox(app)).single.status, OutboxStatus.done);
      final MachineOverview after = (await app.services.machines.readOverview(1))!;
      expect(after.unsent, 0);
      expect(after.logs.where((l) => l.clientUuid == uuid), hasLength(1));
    });

    test('the call carries the figures as the server reads them, and nothing else', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await addLog(app, machineId: 2, km: Qty.parse('42.5'), breakdown: Hours.parse('1.5'), opening: Qty.parse('1000'), closing: Qty.parse('1042.5'));
      final List<Map<String, dynamic>> bodies = <Map<String, dynamic>>[];
      app.api.onMachineLogPost = bodies.add;

      await app.sync();

      expect(bodies.single.keys.toSet(), <String>{
        'asset_id',
        'project_id',
        'log_date',
        'km',
        'breakdown_hours',
        'opening_reading',
        'closing_reading',
        'client_uuid',
      });
      expect(bodies.single['km'], '42.500');
      expect(bodies.single['breakdown_hours'], '1.5');
      expect(bodies.single['opening_reading'], '1000.000');
    });

    test('a meter reading alone is worked out by the server', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      final String uuid = await addLog(app, opening: Qty.parse('100'), closing: Qty.parse('108.5'));

      await app.sync();

      expect(app.api.machineLogPosts[uuid]!['hours'], '8.500');
      expect((await app.services.machines.readOverview(1))!.logs.firstWhere((l) => l.clientUuid == uuid).hours, Hours.parse('8.5'));
    });

    test('a reply that is lost does not make a second log', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      final String uuid = await addLog(app);
      app.api.loseNextProgressReply = true;

      await app.sync();
      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));
      expect(app.api.machineLogPosts, hasLength(1));

      await app.sync();

      expect(app.api.machineLogPosts.keys, <String>[uuid]);
      expect((await outbox(app)).single.status, OutboxStatus.done);
      final MachineOverview seen = (await app.services.machines.readOverview(1))!;
      expect(seen.logs.where((l) => l.clientUuid == uuid), hasLength(1));
    });

    test('a sent log is listed at once, even if the next read fails', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      final String uuid = await addLog(app);
      app.api.machineLogListFailure = const OfflineFailure();

      await app.sync();

      final MachineLog row = (await app.services.machines.readOverview(1))!.logs.first;
      expect(row.clientUuid, uuid);
      expect(row.notSent, isFalse);
      expect(row.hours, Hours.parse('7.5'));
    });

    test('more than 24 hours in a day waits for a person, in the server\'s own words', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await addLog(app, hours: Hours.parse('20'), date: '2026-11-08');

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, 'That would be more than 24 hours for this machine on one day.');
      expect((await app.services.machines.readOverview(1))!.logs.first.needsAttention, isTrue);
    });

    test('a day a hire bill covers is refused with the server\'s words', () async {
      final TestApp app = await machineApp(setup: (FakeApi api) => api.machineHireBilled.add('1:2026-11-05'));
      addTearDown(app.dispose);
      await addLog(app, date: '2026-11-05');

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionReason, contains('hire bill'));
    });

    test('a machine taken away meanwhile is refused', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await addLog(app);
      app.api.machineAssetRows.removeWhere((row) => row['id'] == 1);

      await app.sync();

      expect((await outbox(app)).single.status, OutboxStatus.attention);
    });

    test('403 goes to attention too', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await addLog(app);
      app.api.failNextProgressPost = const ServerFailure('Forbidden', statusCode: 403);

      await app.sync();

      expect((await outbox(app)).single.attentionCode, AttentionCode.forbidden);
    });

    test('a refused log can be discarded and leaves nothing behind', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await addLog(app);
      app.api.failNextProgressPost = _tooMany;
      await app.sync();

      await app.services.syncCenter.discard((await outbox(app)).single.id);

      expect(await outbox(app), isEmpty);
      expect((await app.services.machines.readOverview(1))!.unsent, 0);
    });

    test('a log waiting to send counts toward the day\'s hours the phone knows of', () async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await addLog(app, hours: Hours.parse('3'), date: '2026-11-08');

      final MachineOverview seen = (await app.services.machines.readOverview(1))!;

      expect(seen.hoursOn(1, '2026-11-08'), Hours.parse('11.5'));
      expect(seen.hoursOn(1, '2026-11-01'), Hours.zero);
    });
  });

  group('the form\'s state', () {
    test('machines of this project come first, then the rest, each by code', () {
      final MachineOverview overview = MachineOverview(
        machines: <Machine>[
          const Machine(id: 1, code: 'AST-0001', name: 'A', projectId: 2),
          const Machine(id: 2, code: 'AST-0002', name: 'B'),
          const Machine(id: 3, code: 'AST-0003', name: 'C', projectId: 1),
        ],
      );
      final MachineFormState state = MachineFormState(
        today: '2026-11-09',
        yesterday: '2026-11-08',
        date: '2026-11-09',
        projectId: 1,
        overview: overview,
      );

      expect(state.machines.map((m) => m.id), <int>[3, 1, 2]);
    });
  });

  group('permissions and the plan', () {
    testWidgets('Home has the site tools row, naming only what is open', (WidgetTester tester) async {
      final TestApp both = await machineApp(inspect: true);
      addTearDown(both.dispose);
      await pumpShellApp(tester, both);
      expect(
        find.descendant(of: find.byKey(k('home-site-tools')), matching: find.text('${en.inspectionsTitle} · ${en.machinesTitle}')),
        findsOneWidget,
      );

      final TestApp machinesOnly = await machineApp();
      addTearDown(machinesOnly.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, machinesOnly);
      expect(find.descendant(of: find.byKey(k('home-site-tools')), matching: find.text(en.machinesTitle)), findsOneWidget);
      expect(find.descendant(of: find.byKey(k('home-site-tools')), matching: find.textContaining(en.inspectionsTitle)), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the list holds only the tools that are open', (WidgetTester tester) async {
      final TestApp machinesOnly = await machineApp();
      addTearDown(machinesOnly.dispose);
      await pumpShellApp(tester, machinesOnly, location: Routes.siteTools);
      expect(find.byKey(k('site-tool-machines')), findsOneWidget);
      expect(find.byKey(k('site-tool-inspections')), findsNothing);

      final TestApp both = await machineApp(inspect: true);
      addTearDown(both.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, both, location: Routes.siteTools);
      expect(find.byKey(k('site-tool-machines')), findsOneWidget);
      expect(find.byKey(k('site-tool-inspections')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home leaves the row out without the permission, or without the plan', (WidgetTester tester) async {
      final TestApp noPermission = await machineApp(log: false);
      addTearDown(noPermission.dispose);
      await pumpShellApp(tester, noPermission);
      expect(find.byKey(k('home-site-tools')), findsNothing);

      final TestApp noPlan = await machineApp(plan: false);
      addTearDown(noPlan.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, noPlan);
      expect(find.byKey(k('home-site-tools')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the pages are not reachable without them: they go Home', (WidgetTester tester) async {
      final TestApp noPlan = await machineApp(plan: false);
      addTearDown(noPlan.dispose);
      await pumpShellApp(tester, noPlan, location: Routes.machines);
      expect(find.byType(HomeScreen), findsOneWidget);
      await freshApp(tester);
      await pumpShellApp(tester, noPlan, location: Routes.machineLogForm);
      expect(find.byType(HomeScreen), findsOneWidget);

      final TestApp noPermission = await machineApp(log: false);
      addTearDown(noPermission.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, noPermission, location: Routes.machines);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('on the offline plan there is no row and no page, whatever the plan list says', (WidgetTester tester) async {
      final FakeApi api = FakeApi()..offlineCompany = true;
      api.grantMachineLogs();
      final TestApp app = await TestApp.create(seed: false, signedIn: false, api: api);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
      await app.db.into(app.db.projects).insert(ProjectsCompanion.insert(id: const Value(-1), code: 'L1', name: 'Local site'));
      await app.services.projects.selectProject(-1);
      expect(app.services.session.user!.features, contains('assets'));

      await pumpShellApp(tester, app);
      expect(find.byKey(k('home-site-tools')), findsNothing);
      await freshApp(tester);
      await pumpShellApp(tester, app, location: Routes.machines);
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(app.api.refusedCalls, 0);
      expect(tester.takeException(), isNull);
    });
  });

  group('screens', () {
    testWidgets('the list shows hours, km and breakdown in words, and no rupee anywhere', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.text('AST-0001 · JCB 3DX'), findsOneWidget);
      expect(find.textContaining(en.machinesHoursValue('8.5')), findsOneWidget);
      expect(find.textContaining(en.machinesKmValue('42.500')), findsOneWidget);
      expect(find.textContaining(en.machinesBreakdownValue('4.0')), findsOneWidget);
      expect(find.textContaining(en.machinesOperatorValue('Ramesh')), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
      expect(find.textContaining(RegExp('rate|cost|bill', caseSensitive: false)), findsNothing);
      expect(find.byKey(k('machines-add')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a log waiting to send is marked, and a refused one says why', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await addLog(app);
      await pumpScreen(tester, app);
      expect(find.text(en.progressNotSent), findsOneWidget);

      app.api.offline = false;
      app.api.failNextProgressPost = _tooMany;
      await app.sync();
      await pumpScreen(tester, app);
      expect(find.text('That would be more than 24 hours for this machine on one day.'), findsOneWidget);
      expect(find.text(en.shellPillAttention), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a 403 is a calm page with nothing to add', (WidgetTester tester) async {
      final TestApp app = await machineApp(
        setup: (FakeApi api) => api.machineReadFailure = const ServerFailure('Forbidden', statusCode: 403),
      );
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.text(en.machinesRefusedTitle), findsOneWidget);
      expect(find.byKey(k('machines-add')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('with no machines it says so, and offers no log', (WidgetTester tester) async {
      final TestApp app = await machineApp(setup: (FakeApi api) => api.machineAssetRows = <Map<String, dynamic>>[]);
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.text(en.machinesNoMachinesTitle), findsOneWidget);
      expect(find.byKey(k('machines-add')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the form saves one log with what was entered', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.machineLogForm);

      await pickMachine(tester, 1);
      await tap(tester, k('machine-form-date-yesterday'));
      await typeIn(tester, 'machine-form-hours', '7.5');
      await typeIn(tester, 'machine-form-operator', 'Ramesh');
      await tap(tester, k('machine-form-save'));

      final OutboxRow row = (await outbox(app)).single;
      expect(row.type, OutboxType.machineLog);
      final Map<String, dynamic> data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
      expect(data['hours'], '7.5');
      expect(data['asset_id'], 1);
      expect(data['project_id'], 1);
      expect(data['log_date'], '2026-11-08');
      expect(data['operator'], 'Ramesh');
      expect(tester.takeException(), isNull);
    });

    testWidgets('a machine can be named in the address', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=2');

      expect(find.textContaining('AST-0002 · Tipper'), findsWidgets);
      expect(find.byKey(k('machine-form-km')), findsOneWidget, reason: 'a machine with a km meter has a km box');
      expect(find.text(en.machineFormReadingsHintKm), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a machine with an hours meter has no km box, and says what the readings do', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=1');

      expect(find.byKey(k('machine-form-km')), findsNothing);
      expect(find.text(en.machineFormReadingsHintHours), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('hours are checked as the server checks them, and never rounded', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=1');

      for (final String bad in <String>['7.25', '25', '7.2.1']) {
        await typeIn(tester, 'machine-form-hours', bad);
        await tap(tester, k('machine-form-save'));
        expect(find.text(en.machineFormHoursError), findsOneWidget, reason: bad);
      }
      expect(await outbox(app), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the machine is needed, and so is something worked or lost', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.machineLogForm);

      await tap(tester, k('machine-form-save'));
      expect(find.text(en.machineFormMachineRequired), findsOneWidget);

      await pickMachine(tester, 1);
      await tap(tester, k('machine-form-save'));
      expect(find.text(en.machineFormNothing), findsOneWidget);

      // Readings on a machine with no meter give nothing worked.
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=3');
      await typeIn(tester, 'machine-form-opening', '10');
      await typeIn(tester, 'machine-form-closing', '20');
      await tap(tester, k('machine-form-save'));
      expect(find.text(en.machineFormNothing), findsOneWidget);
      expect(await outbox(app), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a closing reading below the opening one is pointed out', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=1');

      await typeIn(tester, 'machine-form-opening', '50');
      await typeIn(tester, 'machine-form-closing', '40');
      await tap(tester, k('machine-form-save'));

      expect(find.text(en.machineFormClosingBelow), findsOneWidget);
      expect(await outbox(app), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('both readings are enough on a machine that counts hours', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=1');

      await typeIn(tester, 'machine-form-opening', '100');
      await typeIn(tester, 'machine-form-closing', '108.5');
      await tap(tester, k('machine-form-save'));

      final Map<String, dynamic> data = (jsonDecode((await outbox(app)).single.payload) as Map).cast<String, dynamic>();
      expect(data.containsKey('hours'), isFalse);
      expect(data['opening_reading'], '100.000');
      expect(data['closing_reading'], '108.500');
      expect(tester.takeException(), isNull);
    });

    testWidgets('hours already logged that day are said, so 24 is not a surprise', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=1');
      expect(find.byKey(k('machine-form-logged')), findsNothing);

      await tap(tester, k('machine-form-date-yesterday'));

      expect(find.text(en.machineFormLogged('8.5')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('works with no network from the last copy', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await pumpScreen(tester, app, start: Routes.machineLogForm);

      await pickMachine(tester, 2);
      await typeIn(tester, 'machine-form-km', '40');
      await tap(tester, k('machine-form-save'));

      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));
      expect(tester.takeException(), isNull);
    });

    testWidgets('the form asks for no rate or cost', (WidgetTester tester) async {
      final TestApp app = await machineApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: '${Routes.machineLogForm}?asset=2');

      expect(find.textContaining('₹'), findsNothing);
      expect(find.textContaining(RegExp('rate|cost|price|amount', caseSensitive: false)), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });

  group('at 360dp', () {
    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every screen fits in $locale', (WidgetTester tester) async {
        final TestApp app = await machineApp(inspect: true);
        addTearDown(app.dispose);
        app.api.offline = true;
        await addLog(app, operatorName: 'Ramesh Patil');
        app.api.offline = false;

        await pumpScreen(tester, app, locale: locale);
        expect(tester.takeException(), isNull);

        await pumpScreen(tester, app, locale: locale, start: '${Routes.machineLogForm}?asset=2');
        expect(tester.takeException(), isNull);
        await tap(tester, k('machine-form-save'));
        expect(tester.takeException(), isNull);
        await typeIn(tester, 'machine-form-hours', '7.25');
        await tap(tester, k('machine-form-save'));
        expect(tester.takeException(), isNull);
        await typeIn(tester, 'machine-form-hours', '');
        await typeIn(tester, 'machine-form-opening', '50');
        await typeIn(tester, 'machine-form-closing', '40');
        await tap(tester, k('machine-form-save'));
        expect(tester.takeException(), isNull);

        await pumpScreen(tester, app, locale: locale, start: '${Routes.machineLogForm}?asset=1');
        await tap(tester, k('machine-form-date-yesterday'));
        expect(tester.takeException(), isNull);

        await pumpScreen(tester, app, locale: locale, start: Routes.machineLogForm);
        await tap(tester, k('machine-form-machine'));
        expect(tester.takeException(), isNull);

        await pumpScreen(tester, app, locale: locale, start: Routes.siteTools);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
