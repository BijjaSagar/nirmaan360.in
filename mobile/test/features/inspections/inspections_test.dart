import 'dart:convert';

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
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/inspections/data/inspection_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ValidationFailure _refused = ValidationFailure(
  'The selected checklist template id is invalid.',
  errors: <String, List<String>>{
    'checklist_template_id': <String>['The selected checklist template id is invalid.'],
  },
);

Future<TestApp> inspectionApp({
  bool fill = true,
  bool plan = true,
  void Function(FakeApi api)? setup,
}) async {
  final FakeApi server = FakeApi();
  if (fill) server.grant('checklist.fill');
  if (plan) ((server.user['organization'] as Map)['features'] as List).add('qhs');
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

/// A draft of the first checklist (three items), or [templateId].
Future<InspectionDraft> start(TestApp app, {int templateId = 1}) async {
  final InspectionOverview overview = (await app.services.inspections.readOverview(1))!;
  return app.services.inspections.startDraft(projectId: 1, template: overview.template(templateId)!);
}

/// Marks every item of [draft] as [result] (a failure gets a reason).
InspectionDraft markAll(InspectionDraft draft, ItemResult result, {Map<int, ItemResult> except = const <int, ItemResult>{}}) {
  var next = draft;
  for (var position = 1; position <= draft.items.length; position++) {
    final ItemResult value = except[position] ?? result;
    next = next.withMark(position, result: value, remark: value == ItemResult.fail ? 'Cover only 20 mm' : null);
  }
  return next;
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
  String start = Routes.inspections,
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

Future<void> typeIn(WidgetTester tester, Key field, String text) async {
  await tester.enterText(find.descendant(of: find.byKey(field), matching: find.byType(TextField)), text);
  await settle(tester);
}

String fillPath(InspectionDraft draft) => '${Routes.inspectionFill}?uuid=${draft.uuid}';

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('reading', () {
    test('keeps the checklists and recent inspections of the project, with their items', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);

      final InspectionOverview seen = (await app.services.inspections.readOverview(1))!;
      expect(seen.templates.map((t) => t.name), <String>['Pour card', 'PPE checklist']);
      expect(seen.template(1)!.items, <String>['Level checked', 'Cover blocks fixed', 'Cubes cast']);
      expect(seen.inspections.map((i) => i.id), <int>[13, 12, 11]);

      final Inspection failed = seen.find('12')!;
      expect(failed.status, InspectionStatus.failed);
      expect(failed.location, 'Gate 2');
      expect(failed.items.map((i) => i.result), <ItemResult>[ItemResult.pass, ItemResult.pass, ItemResult.fail, ItemResult.pass]);
      expect(failed.items[2].remark, 'No harness on the 3rd floor edge');

      final Inspection passed = seen.find('11')!;
      expect(passed.items.last.result, ItemResult.na);
      expect(seen.find('13')!.isOpen, isTrue);
      expect(seen.find('13')!.count(ItemResult.pending), 2);
    });

    test('an item state the app does not know is no result, never a pass', () {
      expect(ItemResult.fromWire('waived'), ItemResult.pending);
      expect(ItemResult.fromWire(null), ItemResult.pending);
      expect(ItemResult.fromWire('na'), ItemResult.na);
    });

    test('a locked inspection is read again only when its stamp changes', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      expect(app.api.inspectionDetailReads, 3);

      await app.services.inspections.refresh(1);
      expect(app.api.inspectionDetailReads, 3);

      app.api.inspectionStore[12]!['updated_at'] = '2026-11-09T12:00:00+05:30';
      await app.services.inspections.refresh(1);
      expect(app.api.inspectionDetailReads, 4);
    });

    test('the last answer stays when the server cannot be reached', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);

      app.api.offline = true;
      await expectLater(app.services.inspections.refresh(1), throwsA(isA<ApiFailure>()));

      expect((await app.services.inspections.readOverview(1))!.inspections, hasLength(3));
    });

    test('a refusal replaces what was kept, and is not an error', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);

      app.api.inspectionReadFailure = const ServerFailure('Forbidden', statusCode: 403);
      await app.services.inspections.refresh(1);

      final InspectionOverview seen = (await app.services.inspections.readOverview(1))!;
      expect(seen.refused, isTrue);
      expect(seen.inspections, isEmpty);
    });

    test('without the permission or the plan the phone does not ask', () async {
      final TestApp noPermission = await inspectionApp(fill: false);
      addTearDown(noPermission.dispose);
      final TestApp noPlan = await inspectionApp(plan: false);
      addTearDown(noPlan.dispose);

      expect(await noPermission.services.inspections.readOverview(1), isNull);
      expect(await noPlan.services.inspections.readOverview(1), isNull);
      expect(noPermission.api.checklistTemplateReads, 0);
      expect(noPlan.api.checklistTemplateReads, 0);
    });

    test('a copy kept from before the permission went is dropped on the next sync', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.inspections(1)), isNotNull);

      (app.api.user['can'] as Map)['checklist.fill'] = false;
      await app.sync();

      expect(await app.db.readMeta(MetaKey.inspections(1)), isNull);
    });

    test('a removed project takes its copy and its drafts with it', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await start(app);
      expect(await app.db.readMeta(MetaKey.inspections(1)), isNotNull);
      expect(await app.db.readMeta(MetaKey.inspectionDrafts(1)), isNotNull);

      app.api.pullBodyOverride = <String, dynamic>{
        'server_time': '2026-11-09T14:27:54+05:30',
        'deleted': <String, dynamic>{
          'projects': <int>[1],
        },
      };
      await app.sync();

      expect(await app.db.readMeta(MetaKey.inspections(1)), isNull);
      expect(await app.db.readMeta(MetaKey.inspectionDrafts(1)), isNull);
    });
  });

  group('a draft', () {
    test('is kept on the phone as it is marked, and nothing goes to the server', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = await start(app);
      await app.services.inspections.saveDraft(draft.withMark(1, result: ItemResult.pass).withMark(2, result: ItemResult.na));

      final InspectionDraft back = (await app.services.inspections.readDraft(1, draft.uuid))!;
      expect(back.resultAt(1), ItemResult.pass);
      expect(back.resultAt(2), ItemResult.na);
      expect(back.resultAt(3), ItemResult.pending);
      expect(back.markedCount, 2);
      expect(await outbox(app), isEmpty);
      expect(app.api.inspectionPostCalls, 0);
    });

    test('cannot be finished with an item that has no result', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = (await start(app)).withMark(1, result: ItemResult.pass);

      await expectLater(app.services.inspections.finish(draft), throwsA(isA<ValidationFailure>()));
      expect(await outbox(app), isEmpty);
    });

    test('cannot be finished with a failed item that says nothing', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass).withMark(2, result: ItemResult.fail, remark: '   ');

      expect(draft.needRemark, <int>[2]);
      await expectLater(app.services.inspections.finish(draft), throwsA(isA<ValidationFailure>()));
      expect(await outbox(app), isEmpty);
    });

    test('a remark kept after switching away is not sent for a pass', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass)
          .withMark(1, result: ItemResult.fail, remark: 'Out of plumb')
          .withMark(1, result: ItemResult.pass);

      expect(draft.remarkAt(1), 'Out of plumb');
      final List<dynamic> results = draft.toPayload()['results'] as List<dynamic>;
      expect((results.first as Map).containsKey('remark'), isFalse);
    });

    test('a date in the future is refused on the phone', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass).copyWith(inspectedOn: '2099-01-01');

      await expectLater(app.services.inspections.finish(draft), throwsA(isA<ValidationFailure>()));
      expect(await outbox(app), isEmpty);
    });

    test('can be discarded', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = await start(app);

      await app.services.inspections.discardDraft(1, draft.uuid);

      expect(await app.services.inspections.readDrafts(1), isEmpty);
    });

    test('an unfinished one stops a sign-out from losing it quietly', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      expect(await app.services.auth.unsentWorkCount(), 0);

      final InspectionDraft draft = await start(app);
      expect(await app.services.auth.unsentWorkCount(), 1);

      await app.services.inspections.finish(markAll(draft, ItemResult.pass));
      expect(await app.services.auth.unsentWorkCount(), 1, reason: 'now it is the outbox row');
    });
  });

  group('finishing', () {
    test('offline it waits whole, then reaches the server once', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass, except: <int, ItemResult>{2: ItemResult.fail})
          .copyWith(location: ' Slab L3 ');
      await app.services.inspections.finish(draft);

      final InspectionOverview waiting = (await app.services.inspections.readOverview(1))!;
      expect(waiting.unsent, 1);
      final Inspection first = waiting.inspections.first;
      expect(first.notSent, isTrue);
      expect(first.status, InspectionStatus.failed, reason: 'a preview of what the server will say');
      expect(first.title, 'Pour card');
      expect(first.items.map((i) => i.text), <String>['Level checked', 'Cover blocks fixed', 'Cubes cast']);
      expect(first.items[1].remark, 'Cover only 20 mm');
      expect(await app.services.inspections.readDrafts(1), isEmpty);

      app.api.offline = false;
      await app.sync();

      expect(app.api.inspectionPosts.keys, <String>[draft.uuid]);
      expect(app.api.inspectionPostCalls, 1);
      final Map<String, dynamic> stored = app.api.inspectionPosts[draft.uuid]!;
      expect(stored['status'], 'failed');
      expect(stored['location'], 'Slab L3');
      expect((stored['items'] as List)[1]['remark'], 'Cover only 20 mm');
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('the call that is sent starts, marks and completes in one, with the phone-only parts left out', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass);
      await app.services.inspections.finish(draft);

      final Map<String, dynamic> queued = (jsonDecode((await outbox(app)).single.payload) as Map).cast<String, dynamic>();
      expect(queued['complete'], true);
      expect(queued['checklist_template_id'], 1);
      expect((queued['results'] as List), hasLength(3));
      expect((queued['results'] as List).first, <String, dynamic>{'position': 1, 'result': 'pass'});
      expect(queued.containsKey('local'), isTrue);

      final List<Map<String, dynamic>> bodies = <Map<String, dynamic>>[];
      app.api.onInspectionPost = bodies.add;
      await app.sync();

      expect(bodies.single.containsKey('local'), isFalse);
      expect(bodies.single['client_uuid'], draft.uuid);
      expect(bodies.single['complete'], true);
    });

    test('finishing twice queues it once', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass);

      await app.services.inspections.finish(draft);
      await app.services.inspections.finish(draft);

      expect(await outbox(app), hasLength(1));
    });

    test('a reply that is lost does not make a second inspection', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass);
      await app.services.inspections.finish(draft);
      app.api.loseNextProgressReply = true;

      await app.sync();
      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));
      expect(app.api.inspectionPosts, hasLength(1));

      await app.sync();

      expect(app.api.inspectionPosts.keys, <String>[draft.uuid]);
      expect((await outbox(app)).single.status, OutboxStatus.done);
      final InspectionOverview seen = (await app.services.inspections.readOverview(1))!;
      expect(seen.inspections.where((i) => i.clientUuid == draft.uuid), hasLength(1));
      expect(seen.unsent, 0);
    });

    test('a sent inspection is listed at once, even if the next read fails', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass);
      await app.services.inspections.finish(draft);
      app.api.inspectionListFailure = const OfflineFailure();

      await app.sync();

      final InspectionOverview seen = (await app.services.inspections.readOverview(1))!;
      final Inspection row = seen.inspections.first;
      expect(row.clientUuid, draft.uuid);
      expect(row.status, InspectionStatus.passed);
      expect(row.notSent, isFalse);
      expect(seen.find(draft.uuid)!.items, hasLength(3), reason: 'its items are kept too');
    });

    test('a refusal waits for a person, in the server\'s own words', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      app.api.failNextProgressPost = _refused;

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, 'The selected checklist template id is invalid.');
      expect((await app.services.inspections.readOverview(1))!.inspections.first.needsAttention, isTrue);
    });

    test('403 goes to attention too, and the plan being lost reads the same', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      app.api.failNextProgressPost = const ServerFailure('Forbidden', statusCode: 403);

      await app.sync();

      expect((await outbox(app)).single.attentionCode, AttentionCode.forbidden);
    });

    test('a refused inspection can be discarded and leaves nothing behind', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      app.api.failNextProgressPost = _refused;
      await app.sync();

      await app.services.syncCenter.discard((await outbox(app)).single.id);

      expect(await outbox(app), isEmpty);
      expect((await app.services.inspections.readOverview(1))!.unsent, 0);
    });

    test('a checklist changed after it was copied is not sent, because positions would mislead', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      // Someone reorders the checklist on the website while the phone is offline.
      app.api.checklistTemplateRows[0]['items'] = <String>['Cover blocks fixed', 'Level checked', 'Cubes cast'];

      await app.sync();

      expect(app.api.inspectionPostCalls, 0);
      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, contains('changed'));
    });

    test('a checklist taken away is not sent either', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      app.api.checklistTemplateRows.removeAt(0);

      await app.sync();

      expect(app.api.inspectionPostCalls, 0);
      expect((await outbox(app)).single.status, OutboxStatus.attention);
    });

    test('a checklist that is the same is sent', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      // Only its stamp moved: the items are what the results go by.
      app.api.checklistTemplateRows[0]['updated_at'] = '2026-11-08T09:00:00+05:30';

      await app.sync();

      expect(app.api.inspectionPostCalls, 1);
    });

    test('with no network at the check the row simply waits', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await app.services.inspections.finish(markAll(await start(app), ItemResult.pass));
      app.api.inspectionReadFailure = const OfflineFailure();

      await app.sync();

      expect((await outbox(app)).single.status, OutboxStatus.pending);
      expect(app.api.inspectionPostCalls, 0);
    });
  });

  group('a completed inspection', () {
    test('refuses any change on the server, so the phone offers none and never queues one', () async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      // The phone has no PATCH and no separate complete: those are 409 once locked,
      // and a complete whose reply was lost would be answered 409 on the retry.
      expect(OutboxType.values.map((t) => t.wire), isNot(contains('inspection_complete')));
      expect(OutboxType.values.map((t) => t.wire), isNot(contains('inspection_save')));
      expect(OutboxType.inspection.isDirect, isTrue);
    });
  });

  group('permissions and the plan', () {
    testWidgets('Home has the site tools row, and it opens the list with inspections', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.byKey(k('home-site-tools')), findsOneWidget);
      expect(find.descendant(of: find.byKey(k('home-site-tools')), matching: find.text(en.inspectionsTitle)), findsOneWidget);

      await tap(tester, k('home-site-tools'));
      expect(find.byKey(k('site-tool-inspections')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home leaves the row out without the permission, or without the plan', (WidgetTester tester) async {
      final TestApp noPermission = await inspectionApp(fill: false);
      addTearDown(noPermission.dispose);
      await pumpShellApp(tester, noPermission);
      expect(find.byKey(k('home-site-tools')), findsNothing);

      final TestApp noPlan = await inspectionApp(plan: false);
      addTearDown(noPlan.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, noPlan);
      expect(find.byKey(k('home-site-tools')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the pages are not reachable without them: they go Home', (WidgetTester tester) async {
      final TestApp noPlan = await inspectionApp(plan: false);
      addTearDown(noPlan.dispose);
      await pumpShellApp(tester, noPlan, location: Routes.inspections);
      expect(find.byType(HomeScreen), findsOneWidget);

      final TestApp noPermission = await inspectionApp(fill: false);
      addTearDown(noPermission.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, noPermission, location: Routes.siteTools);
      expect(find.byType(HomeScreen), findsOneWidget);
      await freshApp(tester);
      await pumpShellApp(tester, noPermission, location: Routes.inspectionDetail('12'));
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('screens', () {
    testWidgets('the list shows each inspection with its state in words', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await start(app);
      await pumpScreen(tester, app);

      expect(find.text(en.inspectionStatusPassed), findsOneWidget);
      expect(find.text(en.inspectionStatusFailed), findsOneWidget);
      expect(find.text(en.inspectionStatusOpen), findsOneWidget);
      expect(find.text(en.inspectionsDraftWord), findsOneWidget);
      expect(find.textContaining(en.inspectionsFailedCount(1)), findsOneWidget);
      expect(find.byKey(k('inspections-start')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a finished inspection waiting to send is marked, and a refused one says why', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass);
      await app.services.inspections.finish(draft);
      await pumpScreen(tester, app);
      expect(find.text(en.progressNotSent), findsOneWidget);

      app.api.offline = false;
      app.api.failNextProgressPost = _refused;
      await app.sync();
      await pumpScreen(tester, app);
      expect(find.text('The selected checklist template id is invalid.'), findsOneWidget);
      expect(find.text(en.shellPillAttention), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('starting from a checklist opens it ready to mark', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      await tap(tester, k('inspections-start'));
      await tester.tap(find.byKey(k('field-pick-1')));
      await settle(tester);

      expect(find.byKey(k('inspection-finish')), findsOneWidget);
      expect(find.byKey(k('inspection-item-1-pass')), findsOneWidget);
      expect(find.byKey(k('inspection-item-3-na')), findsOneWidget);
      expect((await app.services.inspections.readDrafts(1)).single.templateName, 'Pour card');
      expect(tester.takeException(), isNull);
    });

    testWidgets('marking is kept at once, and finishing queues one inspection', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = await start(app);
      await pumpScreen(tester, app, start: fillPath(draft));

      await tap(tester, k('inspection-item-1-pass'));
      expect((await app.services.inspections.readDraft(1, draft.uuid))!.resultAt(1), ItemResult.pass);
      await tap(tester, k('inspection-item-2-fail'));
      await tap(tester, k('inspection-item-3-na'));

      // The failure needs words before it can be finished.
      await tap(tester, k('inspection-finish'));
      expect(find.text(en.inspectionFillWrongRequired), findsOneWidget);
      expect(find.text(en.inspectionFillNeedRemark(1)), findsOneWidget);
      expect(await outbox(app), isEmpty);

      await typeIn(tester, k('inspection-item-2-remark'), 'Cover only 20 mm');
      expect(find.text(en.inspectionFillWrongRequired), findsNothing);
      await tap(tester, k('inspection-finish'));

      final OutboxRow row = (await outbox(app)).single;
      expect(row.type, OutboxType.inspection);
      final Map<String, dynamic> data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
      expect((data['results'] as List)[1], <String, dynamic>{'position': 2, 'result': 'fail', 'remark': 'Cover only 20 mm'});
      expect(await app.services.inspections.readDrafts(1), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('an item left without a result is pointed out', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = await start(app);
      await pumpScreen(tester, app, start: fillPath(draft));
      await tap(tester, k('inspection-item-1-pass'));

      await tap(tester, k('inspection-finish'));

      expect(find.text(en.inspectionFillUnmarked(2)), findsOneWidget);
      expect(find.text(en.inspectionFillChoose), findsNWidgets(2));
      expect(await outbox(app), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a draft reopens as it was left', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = (await start(app)).withMark(2, result: ItemResult.fail, remark: 'Honeycomb at the corner');
      await app.services.inspections.saveDraft(draft);
      await pumpScreen(tester, app, start: fillPath(draft));

      expect(find.text('Honeycomb at the corner'), findsOneWidget);
      expect(find.text(en.inspectionFillMarked(1, 3)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a draft can be discarded after asking', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      final InspectionDraft draft = await start(app);
      await pumpScreen(tester, app, start: fillPath(draft));

      await tap(tester, k('inspection-discard'));
      expect(find.text(en.inspectionDiscardTitle), findsOneWidget);
      await tap(tester, k('inspection-discard-confirm'));

      expect(await app.services.inspections.readDrafts(1), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a finished one is read only, with every state in words', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.inspectionDetail('12'));

      expect(find.text(en.inspectionStatusFailed), findsOneWidget);
      expect(find.text(en.inspectionResultPass), findsNWidgets(3));
      expect(find.text(en.inspectionResultFail), findsWidgets);
      expect(find.text('No harness on the 3rd floor edge'), findsOneWidget);
      expect(find.byKey(k('inspection-finish')), findsNothing);
      expect(find.byType(N360Chip), findsNothing);
      expect(find.byType(TextField), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('one started on the website says so and offers no way to change it here', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.inspectionDetail('13'));

      expect(find.text(en.inspectionStatusOpen), findsOneWidget);
      expect(find.text(en.inspectionOpenNote), findsOneWidget);
      expect(find.text(en.inspectionResultPending), findsNWidgets(2));
      expect(find.byKey(k('inspection-finish')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('one waiting to send can be opened from its uuid', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      final InspectionDraft draft = markAll(await start(app), ItemResult.pass, except: <int, ItemResult>{3: ItemResult.na});
      await app.services.inspections.finish(draft);
      await pumpScreen(tester, app, start: Routes.inspectionDetail(draft.uuid));

      expect(find.text(en.inspectionWaitingNote), findsOneWidget);
      expect(find.text(en.inspectionResultNa), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a 403 is a calm page with nothing to start', (WidgetTester tester) async {
      final TestApp app = await inspectionApp(
        setup: (FakeApi api) => api.inspectionReadFailure = const ServerFailure('Forbidden', statusCode: 403),
      );
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.text(en.inspectionsRefusedTitle), findsOneWidget);
      expect(find.byKey(k('inspections-start')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('works with no network from the last copy', (WidgetTester tester) async {
      final TestApp app = await inspectionApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await pumpScreen(tester, app);
      expect(find.text(en.inspectionStatusFailed), findsOneWidget);

      await tap(tester, k('inspections-start'));
      await tester.tap(find.byKey(k('field-pick-2')));
      await settle(tester);
      expect(find.byKey(k('inspection-item-4-pass')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('at 360dp', () {
    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every screen fits in $locale', (WidgetTester tester) async {
        final TestApp app = await inspectionApp();
        addTearDown(app.dispose);
        final InspectionDraft draft = (await start(app, templateId: 2)).withMark(3, result: ItemResult.fail, remark: 'Harness missing');
        await app.services.inspections.saveDraft(draft);
        app.api.offline = true;
        await app.services.inspections.finish(markAll(await start(app), ItemResult.pass, except: <int, ItemResult>{2: ItemResult.fail}));

        await pumpScreen(tester, app, locale: locale);
        expect(tester.takeException(), isNull);

        await pumpScreen(tester, app, locale: locale, start: fillPath(draft));
        expect(tester.takeException(), isNull);
        await tap(tester, k('inspection-finish'));
        expect(tester.takeException(), isNull);
        await tap(tester, k('inspection-item-1-fail'));
        expect(tester.takeException(), isNull);

        for (final String key in <String>['12', '13']) {
          await pumpScreen(tester, app, locale: locale, start: Routes.inspectionDetail(key));
          expect(tester.takeException(), isNull);
        }
        final Inspection waiting = (await app.services.inspections.readOverview(1))!.inspections.first;
        await pumpScreen(tester, app, locale: locale, start: Routes.inspectionDetail(waiting.key));
        expect(tester.takeException(), isNull);

        await pumpScreen(tester, app, locale: locale, start: Routes.siteTools);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
