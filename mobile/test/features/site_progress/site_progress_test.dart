import 'package:drift/drift.dart' show OrderingTerm;
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
import 'package:nirmaan360/features/home/bloc/home_cubit.dart';
import 'package:nirmaan360/features/site_progress/data/progress_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../support/test_app.dart';

Future<TestApp> progressApp({bool canCreate = true, bool seesMoney = true, bool shared = false}) async {
  final FakeApi server = FakeApi()..grant('progress.view');
  if (canCreate) server.grant('progress.create');
  (server.user['can'] as Map)['money.view'] = seesMoney;
  if (shared) {
    server.progressItemRows.first['work_orders'] = <Map<String, dynamic>>[
      {'id': 31, 'label': 'Patil Constructions'},
      {'id': 32, 'label': 'Shree Ganesh Civil and Structural Contractors Private Limited, Pune Division'},
    ];
  }
  ((server.user['organization'] as Map)['features'] as List).add('site_control');
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<String> addWork(TestApp app, {String qty = '5.000', List<String> photos = const <String>[]}) {
  return app.services.progress.addEntry(
    projectId: 1,
    boqItemId: 1,
    floorId: 11,
    workDate: app.services.context.today,
    qty: Qty.parse(qty),
    photoPaths: photos,
    title: '2.1 RCC slab',
  );
}

Future<void> setDone(TestApp app, {String status = 'done', List<String> photos = const <String>[]}) {
  return app.services.progress.setStage(
    projectId: 1,
    floorId: 11,
    trade: 'plumbing',
    stage: 'drainage',
    status: status,
    photoPaths: photos,
    title: 'Drainage line',
  );
}

Future<void> pumpProgress(WidgetTester tester, TestApp app, {String start = Routes.progress, String locale = 'en'}) async {
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

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

void main() {
  group('reading', () {
    late TestApp app;

    setUp(() async => app = await progressApp());
    tearDown(() => app.dispose());

    Future<ProgressOverview> overview() async {
      final ProgressOverview cached = (await app.services.progress.watchCached(1).first)!;
      return cached.withUnsent(await app.services.progress.watchUnsent(1).first);
    }

    test('percentages read the way a person says them', () {
      expect(percentText('42.50'), '42.5%');
      expect(percentText('40.00'), '40%');
      expect(percentText('0.68'), '0.68%');
      expect(percentText(null), '—');
    });

    test('work not yet sent already counts against the balance', () async {
      await app.services.connectivityOffline();
      await addWork(app, qty: '5.000');

      final ProgressOverview seen = await overview();
      expect(seen.item(1)!.done, Qty.parse('45.000'));
      expect(seen.item(1)!.balance, Qty.parse('55.000'));
      expect(seen.unsent, 1);
    });

    test('a stage waiting to send shows, and an earlier pending stage warns', () async {
      await app.services.connectivityOffline();
      await app.services.progress.setStage(
        projectId: 1,
        floorId: 21,
        trade: 'plumbing',
        stage: 'drainage',
        status: 'done',
        title: 'Drainage line',
      );

      final StageMatrix matrix = (await overview()).matrix;
      final StageRow flat = matrix.row(21)!;
      expect(flat.cell('plumbing', 'drainage').status, 'done');
      expect(flat.cell('plumbing', 'drainage').warning, isTrue);
      expect(flat.pending, 1);
    });

    test('a resolved item leaves the pending list before it is sent', () async {
      await app.services.connectivityOffline();
      await app.services.progress.resolvePending(projectId: 1, entryId: 70, title: 'RCC slab');
      expect((await overview()).pending, isEmpty);
    });

    test('the before finishing check needs no network', () async {
      final BeforeFinishing check = (await overview()).matrix.beforeFinishing(11);
      expect(check.ready, isFalse);
      expect(check.pending.single.rowLabel, 'First floor › Flat 101');
      expect(check.pending.single.remark, 'Sleeve missing');
      // Done and not applicable are not listed; the floor's own drainage is.
      expect(check.unfinished.single.stage.key, 'drainage');
      expect(check.unfinished.single.floorId, 11);
    });

    test('the card weighted by rates is only for those who may see money', () async {
      final HomeCubit seen = HomeCubit(services: app.services, projectId: 1);
      addTearDown(seen.close);
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(seen.state.progress, isNotNull);

      final TestApp site = await progressApp(seesMoney: false);
      addTearDown(site.dispose);
      final HomeCubit hidden = HomeCubit(services: site.services, projectId: 1);
      addTearDown(hidden.close);
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(hidden.state.progress, isNull);
    });

    test('a sync keeps the progress of the selected project on the phone', () async {
      await app.db.deleteMeta(MetaKey.progressOverview(1));
      await addWork(app);
      await app.sync();
      expect(await app.services.progress.watchCached(1).first, isNotNull);
    });

    test('a plan without site control fetches nothing', () async {
      await app.db.deleteMeta(MetaKey.progressOverview(1));
      app.services.session.user!.features.remove('site_control');
      await app.services.progress.refreshCurrent(force: true);
      expect(await app.services.progress.watchCached(1).first, isNull);
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await progressApp());
    tearDown(() => app.dispose());

    test('an entry whose reply is lost is recorded once', () async {
      final String uuid = await addWork(app);
      app.api.loseNextProgressReply = true;
      await app.sync();
      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));

      await app.sync();

      expect(app.api.progressPosts.keys, <String>[uuid]);
      expect(app.api.progressPosts[uuid]!['qty_done'], '5.000');
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('photos follow their entry and use its server id', () async {
      await addWork(app, photos: <String>['/tmp/a.jpg']);
      await app.sync();

      final int id = app.api.progressPosts.values.single['id'] as int;
      expect(app.api.fieldPhotos.single.$1, '/progress/entries/$id/photos');
      expect((await outbox(app)).every((r) => r.status == OutboxStatus.done), isTrue);
    });

    test('a stage photo uses the cell the server made', () async {
      await setDone(app, photos: <String>['/tmp/b.jpg']);
      await app.sync();

      final int id = app.api.stagePosts.values.single['id'] as int;
      expect(app.api.fieldPhotos.single.$1, '/stage-cells/$id/photos');
    });

    test('the newest change to a stage replaces one still waiting', () async {
      await app.services.connectivityOffline();
      await setDone(app, status: 'in_progress');
      await setDone(app, status: 'done');
      expect(await outbox(app), hasLength(1));

      app.services.connectivityOnline();
      await app.sync();
      expect(app.api.stagePosts.values.single['status'], 'done');
    });

    test('a refused entry waits for a person', () async {
      await addWork(app);
      app.api.failNextProgressPost = const ValidationFailure(
        'Enter the quantity done.',
        errors: <String, List<String>>{
          'qty_done': <String>['Enter the quantity done.'],
        },
      );
      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);

      await app.services.syncCenter.retry(row.id);
      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('no permission and a removed project each say so', () async {
      await addWork(app);
      app.api.failNextProgressPost = const ServerFailure('Forbidden.', statusCode: 403);
      await app.sync();
      expect((await outbox(app)).single.attentionCode, AttentionCode.forbidden);

      await addWork(app, qty: '1.000');
      await (app.db.delete(app.db.projects)..where((t) => t.id.equals(1))).go();
      app.api.failNextProgressPost = const ServerFailure('Not found.', statusCode: 404);
      await app.sync();
      expect((await outbox(app)).last.attentionCode, AttentionCode.projectGone);
    });

    test('a server fault is retried, not blamed on the entry', () async {
      await addWork(app);
      app.api.failNextProgressPost = const ServerFailure('Boom.', statusCode: 500);
      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.pending);
    });

    test('discarding an entry takes its photos with it', () async {
      await addWork(app, photos: <String>['/tmp/a.jpg']);
      app.api.failNextProgressPost = const ValidationFailure('No.', errors: <String, List<String>>{});
      await app.sync();

      final OutboxRow entry = (await outbox(app)).first;
      expect(entry.status, OutboxStatus.attention);
      await app.services.syncCenter.discard(entry.id);
      expect(await outbox(app), isEmpty);
    });
  });

  group('screens', () {
    testWidgets('the overview leads with the project, then floors, stages and pending', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app);

      expect(find.text('40%'), findsOneWidget);
      expect(find.text('Behind by 15%'), findsOneWidget);
      expect(find.text('First floor'), findsOneWidget);

      await tester.tap(find.text('Stages'));
      await settle(tester);
      expect(find.text('First floor › Flat 101'), findsOneWidget);
      expect(find.text('1 pending'), findsOneWidget);

      await tester.tap(find.text('Pending'));
      await settle(tester);
      expect(find.textContaining('Shuttering left'), findsOneWidget);
    });

    testWidgets('work is saved with a floor, an item and a number', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressForm);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      expect(find.text('Choose an item'), findsWidgets);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-item')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-item-choice-1')));
      await settle(tester);
      expect(find.textContaining('Balance 60.000 cum'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      expect(find.text('Enter the quantity done'), findsOneWidget);

      await tester.enterText(find.byType(TextField).first, '70');
      await settle(tester);
      expect(find.textContaining('more than the BOQ balance'), findsOneWidget);
      await tester.enterText(find.byType(TextField).first, '12.5');
      await settle(tester);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      await app.sync();

      final Map<String, dynamic> posted = app.api.progressPosts.values.single;
      expect(posted['boq_item_id'], 1);
      expect(posted['qty_done'], '12.500');
      expect(posted['is_pending'], false);
    });

    testWidgets('a line two contractors share asks whose work it is', (tester) async {
      final TestApp app = await progressApp(shared: true);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressForm);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-item')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-item-choice-1')));
      await settle(tester);
      expect(find.text('Whose work is this?'), findsOneWidget);

      await tester.enterText(find.byType(TextField).first, '12.5');
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      expect(find.text('Choose the contractor'), findsOneWidget);
      expect(app.api.progressPosts, isEmpty);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-contractor-31')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      await app.sync();

      expect(app.api.progressPosts.values.single['work_order_id'], 31);
    });

    testWidgets('a pending note on a shared line is nobody\'s yet', (tester) async {
      final TestApp app = await progressApp(shared: true);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressForm);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-item')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-item-choice-1')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-form-pending')));
      await settle(tester);
      expect(find.text('Whose work is this?'), findsNothing);

      await tester.enterText(find.byType(TextField).first, 'Tiles not delivered');
      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      await app.sync();

      expect(app.api.progressPosts.values.single['work_order_id'], isNull);
    });

    testWidgets('someone who may not see money sees quantities on the BOQ', (tester) async {
      final TestApp app = await progressApp(seesMoney: false);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.boq);

      expect(find.textContaining('×'), findsNothing);
      expect(find.textContaining('₹'), findsNothing);
    });

    testWidgets('someone who may see money still sees the rate', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.boq);

      expect(find.textContaining('× ₹'), findsWidgets);
    });

    testWidgets('a pending-only entry needs words, not a number', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressForm);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-item')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-item-choice-1')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-form-pending')));
      await settle(tester);
      expect(find.byKey(const ValueKey<String>('progress-form-qty')), findsNothing);

      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      expect(find.text('Say what is pending'), findsOneWidget);

      await tester.enterText(find.byType(TextField).first, 'Tiles not delivered');
      await tester.tap(find.byKey(const ValueKey<String>('progress-form-save')));
      await settle(tester);
      await app.sync();

      final Map<String, dynamic> posted = app.api.progressPosts.values.single;
      expect(posted['is_pending'], true);
      expect(posted['qty_done'], isNull);
      expect(posted['remark'], 'Tiles not delivered');
    });

    testWidgets('a stage is set from a sheet, and only optional stages can be skipped', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressStage(11, 'plumbing'));

      await tester.tap(find.byKey(const ValueKey<String>('progress-stage-core_cutting')));
      await settle(tester);
      expect(find.byKey(const ValueKey<String>('progress-status-na')), findsNothing);
      await tester.tapAt(const Offset(180, 40));
      await settle(tester);

      await tester.tap(find.byKey(const ValueKey<String>('progress-stage-drainage')));
      await settle(tester);
      expect(find.byKey(const ValueKey<String>('progress-status-na')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey<String>('progress-status-in_progress')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('progress-stage-save')));
      await settle(tester);
      await app.sync();

      final Map<String, dynamic> posted = app.api.stagePosts.values.single;
      expect(posted['stage'], 'drainage');
      expect(posted['status'], 'in_progress');
    });

    testWidgets('someone who can only view gets no way to add', (tester) async {
      final TestApp app = await progressApp(canCreate: false);
      addTearDown(app.dispose);
      await pumpProgress(tester, app);

      expect(find.byKey(const ValueKey<String>('progress-add')), findsNothing);
    });

    testWidgets('the before finishing check lists what is open', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressBeforeFinishing(11));

      expect(find.text('Pending work'), findsOneWidget);
      expect(find.text('Not finished yet'), findsOneWidget);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every screen fits a small phone in $locale', (tester) async {
        final TestApp app = await progressApp();
        addTearDown(app.dispose);
        for (final String start in <String>[
          Routes.progress,
          Routes.progressForm,
          Routes.progressFloor(11),
          Routes.progressStage(21, 'plumbing'),
          Routes.progressBeforeFinishing(11),
        ]) {
          await pumpProgress(tester, app, start: start, locale: locale);
          expect(tester.takeException(), isNull, reason: start);
        }
      });
    }

    testWidgets('every control on the form is at least 56dp high', (tester) async {
      final TestApp app = await progressApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressForm);

      for (final Key key in <Key>[
        const ValueKey<String>('progress-form-floor'),
        const ValueKey<String>('progress-form-item'),
        const ValueKey<String>('progress-form-save'),
      ]) {
        expect(tester.getSize(find.byKey(key)).height, greaterThanOrEqualTo(56));
      }
    });
  });
}

extension on AppServices {
  Future<void> connectivityOffline() async => (connectivity as FakeConnectivity).setOnline(false);

  void connectivityOnline() => (connectivity as FakeConnectivity).setOnline(true);
}
