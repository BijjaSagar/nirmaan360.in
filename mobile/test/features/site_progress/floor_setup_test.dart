import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/site_progress/bloc/floor_setup_cubit.dart';
import 'package:nirmaan360/features/site_progress/data/floor_setup_repository.dart';
import 'package:nirmaan360/features/site_progress/view/floor_plan_screen.dart';
import 'package:nirmaan360/features/site_progress/view/floor_setup_screen.dart';
import 'package:nirmaan360/features/site_progress/view/floor_split_screen.dart';
import 'package:nirmaan360/features/site_progress/view/progress_screen.dart';
import 'package:nirmaan360/features/site_progress/view/progress_week_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../support/test_app.dart';
import 'site_progress_test.dart' show pumpProgress, settle;

/// Someone who plans the work (`progress.manage`), the company owner when [owner].
Future<TestApp> setupApp({bool manage = true, bool owner = false}) async {
  final FakeApi server = FakeApi()
    ..grant('progress.view')
    ..grant('progress.create');
  if (manage) server.grant('progress.manage');
  server.user['is_owner'] = owner;
  ((server.user['organization'] as Map)['features'] as List).add('site_control');
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

FloorSetupRepository repositoryOf(TestApp app) => FloorSetupRepository(app.services.api, app.services.progress);

Finder key(String value) => find.byKey(ValueKey<String>(value));

Future<void> tapKey(WidgetTester tester, String value) async {
  await tester.ensureVisible(key(value));
  await tester.pump();
  await tester.tap(key(value));
  await settle(tester);
}

void main() {
  group('repository', () {
    late TestApp app;
    late FloorSetupRepository repository;

    setUp(() async {
      app = await setupApp(owner: true);
      repository = repositoryOf(app);
    });
    tearDown(() => app.dispose());

    test('the set-up reads the floors with their segments, the BOQ lines and the time frame', () async {
      app.api.progressSummaryBody = <String, dynamic>{
        ...app.api.progressSummaryBody,
        'start_date': '2026-10-01',
        'planned_days': 120,
        'deadline_date': '2027-01-29',
      };

      final FloorSetup setup = await repository.load(1);

      expect(setup.floors.single.name, 'First floor');
      expect(setup.choices.map((c) => c.label), <String>['First floor', 'First floor › Flat 101']);
      expect(setup.items.single.code, '2.1');
      expect(setup.timeFrame.startDate, '2026-10-01');
      expect(setup.timeFrame.plannedDays, 120);
      expect(setup.timeFrame.deadlineDate, '2027-01-29');
      expect(const TimeFrame().isEmpty, isTrue);
    });

    test('a floor whose answer was lost is asked for again with the same client_uuid, and made once', () async {
      app.api.loseNextFloorResponse = true;

      await expectLater(repository.addFloor(1, 'Second floor'), throwsA(isA<OfflineFailure>()));
      final int id = await repository.addFloor(1, ' Second floor ');

      expect(app.api.floorCreates, hasLength(2));
      expect(app.api.floorCreates.first['client_uuid'], app.api.floorCreates.last['client_uuid']);
      expect(app.api.progressFloorRows.where((f) => f['name'] == 'Second floor'), hasLength(1));
      expect(app.api.progressFloorRows.last['id'], id);

      // Once answered, the same name is a new request, which the server refuses as a double.
      await expectLater(
        repository.addFloor(1, 'Second floor'),
        throwsA(isA<ValidationFailure>().having((f) => f.errors.keys.single, 'field', 'name')),
      );
      expect(app.api.floorCreates.last['client_uuid'], isNot(app.api.floorCreates.first['client_uuid']));
    });

    test('a segment goes under its floor', () async {
      await repository.addFloor(1, 'Flat 102', parentId: 11);

      expect(app.api.floorCreates.single['parent_id'], 11);
      final FloorSetup setup = await repository.load(1);
      expect(setup.choices.map((c) => c.label), contains('First floor › Flat 102'));
      expect(setup.choices.last.isFlat, isTrue);
    });

    test('a planned quantity is sent as a wire decimal, read back, and zero removes it', () async {
      final FloorPlan planned = await repository.setTarget(21, 1, Qty.parse('12.5'));

      expect(app.api.floorTargetPuts.single, (floorId: 21, itemId: 1, qty: '12.500'));
      expect(planned.floor.id, 21);
      expect(planned.planned[1], Qty.parse('12.5'));

      final FloorPlan cleared = await repository.setTarget(21, 1, Qty.zero);
      expect(cleared.planned, isEmpty);
    });

    test('a line with work but no target is not planned', () {
      final FloorPlan plan = FloorPlan.fromJson(<String, dynamic>{
        'id': 11,
        'name': 'First floor',
        'lines': <Map<String, dynamic>>[
          {'boq_item_id': 1, 'planned_qty': null, 'done_qty': '4.000'},
          {'boq_item_id': 2, 'planned_qty': '10.000', 'done_qty': '2.500'},
        ],
      });

      expect(plan.planned.keys, <int>[2]);
      expect(plan.done[1], Qty.parse('4'));
      expect(plan.done[2], Qty.parse('2.5'));
    });

    test('the week reads what was done where, what is pending and what is under way', () async {
      final ProgressWeek week = await repository.week(1, date: '2026-11-04');

      expect(app.api.weekReads, <String?>['2026-11-04']);
      expect(week.start, '2026-11-02');
      expect(week.end, '2026-11-08');
      expect(week.isCurrent, isFalse);
      expect(week.entryCount, 3);
      expect(week.done.single.weekQty, Qty.parse('15'));
      expect(week.done.single.floors.single, (name: 'First floor › Flat 101', qty: Qty.parse('12')));
      expect(week.pending.single.remark, 'Shuttering left');
      expect(week.next.single.balance, Qty.parse('60'));
      expect(week.notStarted, 4);
    });

    test('the time frame is sent whole and comes back from the summary', () async {
      final TimeFrame saved = await repository.setTimeFrame(1, const TimeFrame(startDate: '2026-12-01', plannedDays: 90));

      expect(app.api.timeFrames.single, <String, dynamic>{'start_date': '2026-12-01', 'planned_days': 90, 'deadline_date': null});
      expect(saved.startDate, '2026-12-01');
      expect(saved.plannedDays, 90);
      expect(saved.deadlineDate, isNull);
    });
  });

  group('cubits', () {
    test('a refusal keeps the server words and field; other failures are named by kind', () {
      final SetupProblem refused = SetupProblem.of(
        const ValidationFailure('The given data was invalid.', errors: {'name': ['There is already a floor with this name here.']}),
      );
      expect(refused.kind, SetupProblemKind.refused);
      expect(refused.message, 'There is already a floor with this name here.');
      expect(refused.field, 'name');
      expect(SetupProblem.of(const OfflineFailure()).kind, SetupProblemKind.offline);
      expect(SetupProblem.of(const ServerFailure('No.', statusCode: 403)).kind, SetupProblemKind.forbidden);
      expect(SetupProblem.of(const ServerFailure('Boom.', statusCode: 500)).kind, SetupProblemKind.failed);
    });

    test('one refused segment does not stop the others', () async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      final FloorSetupCubit cubit = FloorSetupCubit(repositoryOf(app), 1);
      addTearDown(cubit.close);
      await cubit.load();

      final SegmentsOutcome outcome = await cubit.addSegments(11, <String>['Flat 102', 'Flat 101', 'Flat 103']);

      expect(outcome.added, <String>['Flat 102', 'Flat 103']);
      expect(outcome.refused.keys.single, 'Flat 101');
      expect(outcome.refused.values.single.message, 'There is already a floor with this name here.');
      expect(cubit.state.setup!.floors.single.children.map((f) => f.name), <String>['Flat 101', 'Flat 102', 'Flat 103']);
      expect(cubit.state.busy, isFalse);
    });

    test('a lost connection stops the run, and the rest is not sent', () async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      final FloorSetupCubit cubit = FloorSetupCubit(repositoryOf(app), 1);
      addTearDown(cubit.close);
      await cubit.load();
      app.api.loseNextFloorResponse = true;

      final SegmentsOutcome outcome = await cubit.addSegments(11, <String>['Flat 102', 'Flat 103']);

      expect(outcome.added, isEmpty);
      expect(outcome.refused.keys.single, 'Flat 102');
      expect(outcome.refused.values.single.kind, SetupProblemKind.offline);
      expect(app.api.floorCreates, hasLength(1));
    });

    test('sharing needs a floor and a line, and sends the floors in the order shown', () async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await repositoryOf(app).addFloor(1, 'Second floor');
      final FloorSplitCubit cubit = FloorSplitCubit(repositoryOf(app), 1);
      addTearDown(cubit.close);
      await cubit.load();

      expect(cubit.missing, SplitMissing.floor);
      expect(await cubit.submit(), (null, null));
      final int second = app.api.progressFloorRows.last['id'] as int;
      cubit
        ..toggleFloor(second)
        ..toggleFloor(21)
        ..toggleFloor(11)
        ..toggleFloor(11);
      expect(cubit.missing, SplitMissing.line);
      cubit.toggleItem(1);
      expect(cubit.missing, isNull);

      final (int? count, SetupProblem? problem) = await cubit.submit();

      expect(count, 1);
      expect(problem, isNull);
      expect(app.api.floorSplits.single['floor_ids'], <int>[21, second]);
      expect(app.api.floorSplits.single['boq_item_ids'], <int>[1]);
      expect(app.api.floorTargets[21]![1], '50.000');
      expect(app.api.floorTargets[second]![1], '50.000');
    });

    test('the week steps back and forward by seven days', () async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      final ProgressWeekCubit cubit = ProgressWeekCubit(repositoryOf(app), 1);
      addTearDown(cubit.close);

      await cubit.load();
      expect(cubit.state.week!.start, '2026-11-09');
      await cubit.previous();
      expect(cubit.state.week!.start, '2026-11-02');
      await cubit.next();

      expect(app.api.weekReads, <String?>[null, '2026-11-02', '2026-11-09']);
      expect(cubit.state.load, SetupLoad.ready);

      app.api.offline = true;
      await cubit.previous();
      expect(cubit.state.load, SetupLoad.offline);
      app.api.offline = false;
      await cubit.retry();
      expect(cubit.state.week!.start, '2026-11-02');
    });
  });

  group('set-up screen', () {
    testWidgets('lists floors and segments, and adds a floor', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      expect(find.byType(FloorSetupScreen), findsOneWidget);
      expect(find.text('First floor'), findsOneWidget);
      expect(find.text('Flat 101'), findsOneWidget);
      expect(find.text('1 segment · 2 BOQ lines planned'), findsOneWidget);
      expect(find.text('1 BOQ line planned'), findsOneWidget);

      await tapKey(tester, 'floor-add');
      await tester.enterText(key('floor-name'), 'Second floor');
      await tapKey(tester, 'floor-name-save');

      expect(app.api.floorCreates.single['name'], 'Second floor');
      expect(app.api.floorCreates.single['parent_id'], isNull);
      expect(find.text('Second floor'), findsOneWidget);
      expect(find.text('Second floor added.'), findsOneWidget);
      // The progress screens' copy on this phone has the new floor too.
      expect((await app.services.progress.readOverview(1))!.floors.map((f) => f.name), contains('Second floor'));
      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('a name the server refuses stays on the sheet with the reason', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'floor-add');
      await tapKey(tester, 'floor-name-save');
      expect(find.text('Enter a name.'), findsOneWidget);
      expect(app.api.floorCreates, isEmpty);

      await tester.enterText(key('floor-name'), 'first floor');
      await tapKey(tester, 'floor-name-save');

      expect(find.text('There is already a floor with this name here.'), findsOneWidget);
      expect(key('floor-name-save'), findsOneWidget);
      expect(app.api.progressFloorRows, hasLength(1));
    });

    testWidgets('a floor is renamed from its sheet', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'setup-floor-21');
      // A segment cannot be split again.
      expect(key('floor-action-segments'), findsNothing);
      await tapKey(tester, 'floor-action-rename');
      expect(find.text('Rename Flat 101'), findsOneWidget);
      await tester.enterText(key('floor-name'), 'Flat A');
      await tapKey(tester, 'floor-name-save');

      expect(app.api.floorRenames.single, (id: 21, name: 'Flat A'));
      expect(find.text('Flat A'), findsOneWidget);
      expect(find.text('Flat 101'), findsNothing);
      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('a floor is split into segments, one per line', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'setup-floor-11');
      await tapKey(tester, 'floor-action-segments');
      expect(find.text('Segments of First floor'), findsOneWidget);
      await tester.enterText(key('floor-segments'), 'Flat 102\n\n  Flat 103 \nFlat 102');
      await tapKey(tester, 'floor-segments-save');

      expect(app.api.floorCreates.map((c) => (c['name'], c['parent_id'])), <(Object?, Object?)>[('Flat 102', 11), ('Flat 103', 11)]);
      expect(find.text('Flat 102'), findsOneWidget);
      expect(find.text('Flat 103'), findsOneWidget);
      expect(find.text('2 segments added.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('segments the server refuses stay in the box to be put right', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'setup-floor-11');
      await tapKey(tester, 'floor-action-segments');
      await tester.enterText(key('floor-segments'), 'Flat 101\nFlat 102');
      await tapKey(tester, 'floor-segments-save');

      expect(find.textContaining('Not added: Flat 101'), findsOneWidget);
      expect(tester.widget<GloveTextField>(key('floor-segments')).controller!.text, 'Flat 101');
      expect(app.api.progressFloorRows.single['children'], hasLength(2));
    });

    testWidgets('only the company owner is offered Remove', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'setup-floor-21');

      expect(key('floor-action-plan'), findsOneWidget);
      expect(key('floor-action-rename'), findsOneWidget);
      expect(key('floor-action-delete'), findsNothing);
      expect(find.text('Only the company owner can remove a floor.'), findsNothing);
    });

    testWidgets('the owner removes a floor after confirming', (tester) async {
      final TestApp app = await setupApp(owner: true);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'setup-floor-21');
      expect(find.text('Only the company owner can remove a floor.'), findsOneWidget);
      await tapKey(tester, 'floor-action-delete');

      // Nothing is sent until the confirmation, which names the floor.
      expect(find.text('Remove First floor › Flat 101?'), findsOneWidget);
      expect(app.api.floorDeletes, isEmpty);
      await tapKey(tester, 'floor-delete-keep');
      expect(app.api.floorDeletes, isEmpty);
      expect(find.text('Flat 101'), findsOneWidget);

      await tapKey(tester, 'setup-floor-21');
      await tapKey(tester, 'floor-action-delete');
      await tapKey(tester, 'floor-delete-confirm');

      expect(app.api.floorDeletes, <int>[21]);
      expect(find.text('Flat 101'), findsNothing);
      expect(find.text('First floor › Flat 101 removed.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('a floor with work on it stays, with the reason the server gives', (tester) async {
      final TestApp app = await setupApp(owner: true);
      addTearDown(app.dispose);
      app.api.floorsWithWork.add(21);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      await tapKey(tester, 'setup-floor-21');
      await tapKey(tester, 'floor-action-delete');
      await tapKey(tester, 'floor-delete-confirm');

      expect(find.text('Work has been recorded on this floor, so it cannot be removed.'), findsOneWidget);
      expect(key('floor-delete-confirm'), findsOneWidget);
      await tapKey(tester, 'floor-delete-keep');
      expect(find.text('Flat 101'), findsOneWidget);

      // A floor with segments under it is refused the same way.
      await tapKey(tester, 'setup-floor-11');
      await tapKey(tester, 'floor-action-delete');
      await tapKey(tester, 'floor-delete-confirm');
      expect(find.text('Remove the flats under this floor first.'), findsOneWidget);
    });

    testWidgets('someone the server no longer counts as owner is told so', (tester) async {
      final TestApp app = await setupApp(owner: true);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);
      // The phone still thinks so; the server has the last word.
      app.api.user['is_owner'] = false;

      await tapKey(tester, 'setup-floor-21');
      await tapKey(tester, 'floor-action-delete');
      await tapKey(tester, 'floor-delete-confirm');

      expect(key('floor-delete-problem'), findsOneWidget);
      expect(app.api.floorDeletes, isEmpty);
      expect(app.api.progressFloorRows.single['children'], hasLength(1));
    });

    testWidgets('the time frame is set from its sheet', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      expect(find.text('Not set'), findsOneWidget);
      await tapKey(tester, 'setup-time-frame');
      await tester.enterText(key('time-days'), '0');
      await tapKey(tester, 'time-save');
      expect(find.text('Enter whole days, from 1 to 5000.'), findsOneWidget);
      expect(app.api.timeFrames, isEmpty);

      await tester.enterText(key('time-days'), '120');
      await tapKey(tester, 'time-save');

      expect(app.api.timeFrames.single, <String, dynamic>{'start_date': null, 'planned_days': 120, 'deadline_date': null});
      expect(find.textContaining('120 days'), findsOneWidget);
      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('with no internet it says so and loads on retry', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await pumpProgress(tester, app, start: Routes.floorSetup);

      expect(find.text('No internet. Floors are set up online only.'), findsOneWidget);
      expect(key('floor-add'), findsNothing);

      app.api.offline = false;
      await tapKey(tester, 'setup-retry');
      expect(find.text('First floor'), findsOneWidget);
      expect(key('floor-add'), findsOneWidget);
    });

    testWidgets('someone who does not plan the work is sent back to progress', (tester) async {
      final TestApp app = await setupApp(manage: false);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSetup);

      expect(find.byType(FloorSetupScreen), findsNothing);
      expect(find.byType(ProgressScreen), findsOneWidget);
      // They still get the week, and no way into the set-up.
      expect(key('progress-week'), findsOneWidget);
      expect(key('progress-floor-setup'), findsNothing);

      for (final String path in <String>[Routes.floorSplit, Routes.floorPlan(11)]) {
        final TestApp other = await setupApp(manage: false);
        addTearDown(other.dispose);
        await pumpProgress(tester, other, start: path);
        expect(find.byType(ProgressScreen), findsOneWidget, reason: path);
      }
    });

    testWidgets('the progress screen opens the set-up and the week', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app);

      await tapKey(tester, 'progress-floor-setup');
      expect(find.byType(FloorSetupScreen), findsOneWidget);
    });

    testWidgets('a project with no floors invites whoever plans the work to add them', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      app.api.progressFloorRows = <Map<String, dynamic>>[];
      await app.services.progress.refresh(1);
      await pumpProgress(tester, app);
      expect(find.text('No floors yet'), findsOneWidget);

      final TestApp engineer = await setupApp(manage: false);
      addTearDown(engineer.dispose);
      engineer.api.progressFloorRows = <Map<String, dynamic>>[];
      await engineer.services.progress.refresh(1);
      await pumpProgress(tester, engineer);
      expect(find.text('Ask your project manager to add floors and flats.'), findsOneWidget);
    });
  });

  group('plan screen', () {
    testWidgets('a planned quantity is typed, saved and shown on the line', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      app.api.floorProgressBody = app.api.floorDetailBody(21);
      await pumpProgress(tester, app, start: Routes.floorPlan(21));

      expect(find.byType(FloorPlanScreen), findsOneWidget);
      expect(find.text('Flat 101'), findsOneWidget);
      expect(find.textContaining('Not planned here'), findsOneWidget);

      await tapKey(tester, 'plan-line-1');
      await tester.enterText(key('plan-qty'), '12.5');
      await tapKey(tester, 'plan-qty-save');

      expect(app.api.floorTargetPuts.single, (floorId: 21, itemId: 1, qty: '12.500'));
      expect(find.textContaining('Planned 12.500 cum · done 0.000'), findsOneWidget);
      expect(find.text('Planned quantity saved.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('a quantity that is not one is stopped before it is sent', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      app.api.floorProgressBody = app.api.floorDetailBody(21);
      await pumpProgress(tester, app, start: Routes.floorPlan(21));

      await tapKey(tester, 'plan-line-1');
      for (final String bad in <String>['', '-1', '1.2345', 'abc']) {
        await tester.enterText(key('plan-qty'), bad);
        await tapKey(tester, 'plan-qty-save');
        expect(find.text('Enter a quantity from zero up, with at most three decimals.'), findsOneWidget, reason: bad);
      }
      expect(app.api.floorTargetPuts, isEmpty);
    });

    testWidgets('the list narrows as the search is typed', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      app.api.progressItemRows.add(<String, dynamic>{
        'id': 2, 'section': 'Masonry', 'code': '3.1', 'description': 'Brick work', 'unit': 'cum',
        'qty': '80.000', 'done_qty': '0.000', 'balance_qty': '80.000', 'percent': '0.00',
      });
      app.api.floorProgressBody = app.api.floorDetailBody(21);
      await pumpProgress(tester, app, start: Routes.floorPlan(21));

      expect(key('plan-line-1'), findsOneWidget);
      expect(key('plan-line-2'), findsOneWidget);
      await tester.enterText(key('plan-search'), 'brick');
      await settle(tester);
      expect(key('plan-line-1'), findsNothing);
      expect(key('plan-line-2'), findsOneWidget);
      await tester.enterText(key('plan-search'), 'zzz');
      await settle(tester);
      expect(find.text('No line matches'), findsOneWidget);
    });
  });

  group('share screen', () {
    testWidgets('it says what is missing, then shares all lines across the chosen floors', (tester) async {
      final TestApp app = await setupApp();
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.floorSplit);

      expect(find.byType(FloorSplitScreen), findsOneWidget);
      await tapKey(tester, 'split-submit');
      expect(find.text('Choose at least one floor.'), findsOneWidget);
      await tapKey(tester, 'split-floor-11');
      await tapKey(tester, 'split-floor-21');
      await tapKey(tester, 'split-submit');
      expect(find.text('Choose at least one BOQ line.'), findsOneWidget);
      expect(app.api.floorSplits, isEmpty);

      await tapKey(tester, 'split-all-lines');
      // With every line chosen the single lines are not listed.
      expect(key('split-line-1'), findsNothing);
      await tapKey(tester, 'split-submit');

      expect(app.api.floorSplits.single, <String, dynamic>{
        'project_id': 1,
        'floor_ids': <int>[11, 21],
        'boq_item_ids': <int>[],
        'all_items': true,
      });
      expect(find.byType(FloorSplitScreen), findsNothing);
      await tester.pump(const Duration(seconds: 3));
    });
  });

  group('week screen', () {
    testWidgets('shows what was done and where, what is pending and what is under way', (tester) async {
      final TestApp app = await setupApp(manage: false);
      addTearDown(app.dispose);
      await pumpProgress(tester, app, start: Routes.progressWeek);

      expect(find.byType(ProgressWeekScreen), findsOneWidget);
      expect(find.text('9 Nov to 15 Nov 2026'), findsOneWidget);
      expect(find.text('3 entries'), findsOneWidget);
      expect(find.textContaining('15.000 cum this week'), findsOneWidget);
      expect(find.textContaining('First floor › Flat 101: 12.000'), findsOneWidget);
      expect(find.textContaining('Shuttering left'), findsOneWidget);
      expect(find.textContaining('60.000 cum left'), findsOneWidget);
      expect(find.text('4 BOQ lines not started'), findsOneWidget);
      // This week is the last one there is anything to show for.
      expect(tester.widget<GloveButton>(key('week-next')).onPressed, isNull);

      await tapKey(tester, 'week-previous');
      expect(app.api.weekReads, <String?>[null, '2026-11-02']);
      expect(find.text('2 Nov to 8 Nov 2026'), findsOneWidget);
      expect(tester.widget<GloveButton>(key('week-next')).onPressed, isNotNull);
    });

    testWidgets('an empty week says so, and no internet offers a retry', (tester) async {
      final TestApp app = await setupApp(manage: false);
      addTearDown(app.dispose);
      app.api.progressWeekBody = <String, dynamic>{
        ...app.api.progressWeekBody,
        'entry_count': 0,
        'done': <Object>[],
        'pending': <Object>[],
        'next': <Object>[],
        'not_started': 0,
      };
      await pumpProgress(tester, app, start: Routes.progressWeek);
      expect(find.text('No work entered this week.'), findsOneWidget);

      final TestApp offline = await setupApp(manage: false);
      addTearDown(offline.dispose);
      offline.api.offline = true;
      await pumpProgress(tester, offline, start: Routes.progressWeek);
      expect(find.text('No internet. The weekly view needs a connection.'), findsOneWidget);
      offline.api.offline = false;
      await tapKey(tester, 'setup-retry');
      expect(find.text('3 entries'), findsOneWidget);
    });
  });

  group('Marathi and Hindi', () {
    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every screen draws in $locale on a small phone without overflowing', (tester) async {
        for (final String path in <String>[Routes.floorSetup, Routes.floorSplit, Routes.floorPlan(21), Routes.progressWeek, Routes.progress]) {
          final TestApp app = await setupApp(owner: true);
          addTearDown(app.dispose);
          app.api.floorProgressBody = app.api.floorDetailBody(21);
          await pumpProgress(tester, app, start: path, locale: locale);
          expect(tester.takeException(), isNull, reason: '$locale $path');
        }

        // The sheets too: what to do with a floor, and the confirmation.
        final TestApp app = await setupApp(owner: true);
        addTearDown(app.dispose);
        await pumpProgress(tester, app, start: Routes.floorSetup, locale: locale);
        final AppLocalizations l = await AppLocalizations.delegate.load(Locale(locale));
        expect(find.text(l.floorSetupTitle), findsOneWidget);
        await tapKey(tester, 'setup-floor-11');
        expect(find.text(l.floorSetupAddSegments), findsOneWidget);
        await tapKey(tester, 'floor-action-delete');
        expect(find.text(l.floorSetupDeleteBody), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
