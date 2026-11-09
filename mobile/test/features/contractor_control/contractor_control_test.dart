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
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/core/sync/ids.dart';
import 'package:nirmaan360/features/contractor_control/data/contractor_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ValidationFailure _closed = ValidationFailure(
  'This contract is closed.',
  errors: <String, List<String>>{
    'work_order': <String>['This contract is closed.'],
  },
);

Future<TestApp> contractorApp({
  bool money = true,
  bool view = true,
  bool request = true,
  bool feature = true,
  void Function(FakeApi api)? setup,
  AttachmentStore? attachments,
}) async {
  final FakeApi server = FakeApi();
  if (view) server.grant('contractor.view');
  if (request) server.grant('contractor.request');
  (server.user['can'] as Map)['money.view'] = money;
  if (feature) ((server.user['organization'] as Map)['features'] as List).add('site_control');
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server, attachments: attachments);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<String> request(
  TestApp app, {
  String amount = '30000.00',
  bool isAdvance = false,
  int? boqItemId,
  int? milestoneId,
  String? bill,
  String? subtitle,
}) {
  return app.services.contractors.requestPayment(
    uuid: newUuid(),
    projectId: 1,
    workOrderId: 1,
    amount: Money.parse(amount),
    paidOn: app.services.context.today,
    mode: 'upi',
    paidTo: 'Shree Formwork',
    isAdvance: isAdvance,
    boqItemId: boqItemId,
    milestoneId: milestoneId,
    billPath: bill,
    title: 'WO-1 · Shree Formwork',
    subtitle: subtitle,
  );
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> pumpScreen(
  WidgetTester tester,
  TestApp app, {
  String start = Routes.contractors,
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

Future<void> pick(WidgetTester tester, Key field, int id) async {
  await tap(tester, field);
  await tester.tap(find.byKey(k('field-pick-$id')));
  await settle(tester);
}

Future<void> typeAmount(WidgetTester tester, String text) async {
  await tester.enterText(
    find.descendant(of: find.byKey(k('payment-form-amount')), matching: find.byType(TextField)),
    text,
  );
  await settle(tester);
}

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('reading', () {
    test('with money.view the phone keeps the full figures', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      final ContractorOverview seen = (await app.services.contractors.readOverview(1))!;
      final ContractFigures figures = seen.contract(1)!.figures!;
      expect(figures.contractValue, Money.parse('100000.00'));
      expect(figures.paid, Money.parse('90000.00'));
      expect(figures.balanceMoney, Money.parse('10000.00'));
      expect(figures.shortBy, Money.parse('20000.00'));
      expect(figures.label, 'Paid 90%, Work 70%');
      expect(figures.paidAhead, isTrue);
      expect(figures.overLimit, isTrue);
      expect(seen.seesMoney, isTrue);
      expect(seen.aheadCount, 1);

      final ContractDetail detail = seen.details[1]!;
      expect(detail.lines.single.figures!.paid, Money.parse('90000.00'));
      expect(detail.parts.first.amount, Money.parse('25000.00'));
      expect(detail.parts.first.state, 'paid');

      final ContractorPayment payment = seen.payments.single;
      expect(payment.figures!.amount, Money.parse('30000.00'));
      expect(payment.figures!.mode, 'bank');
      expect(payment.figures!.hasBill, isTrue);
    });

    test('without money.view every figure is withheld, not zero', () async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);

      final ContractorOverview seen = (await app.services.contractors.readOverview(1))!;
      final WorkContract contract = seen.contract(1)!;
      expect(contract.workPct, '70.00');
      expect(contract.balanceWorkPct, '30.00');
      expect(contract.figures, isNull);
      expect(seen.seesMoney, isFalse);
      expect(seen.aheadCount, 0);

      final ContractDetail detail = seen.details[1]!;
      expect(detail.lines.single.figures, isNull);
      expect(detail.lines.single.workPct, '70.00');
      expect(detail.parts.first.title, 'Plinth');
      expect(detail.parts.first.amount, isNull);
      expect(detail.parts.first.state, isNull);

      final ContractorPayment payment = seen.payments.single;
      expect(payment.figures, isNull);
      expect(payment.number, 'CP-21');

      // Nothing about money was even stored.
      final String stored = (await app.db.readMeta(MetaKey.contractorOverview(1)))!;
      expect(stored, isNot(contains('90000')));
      expect(stored, isNot(contains('"amount"')));
    });

    test('the last answer stays when the server cannot be reached', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.offline = true;
      await expectLater(app.services.contractors.refresh(1), throwsA(isA<ApiFailure>()));

      expect((await app.services.contractors.readOverview(1))!.contract(1), isNotNull);
    });

    test('a contract opened with a closed one left out of the read-ahead', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      final ContractorOverview seen = (await app.services.contractors.readOverview(1))!;
      expect(seen.details.keys, unorderedEquals(<int>[1, 2]));

      await app.services.contractors.refreshContract(1, 3);
      expect((await app.services.contractors.readOverview(1))!.details.keys, unorderedEquals(<int>[1, 2, 3]));
    });

    test('a refusal replaces what was kept, and is not an error', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.contractorBoardFailure = const ServerFailure('Forbidden', statusCode: 403);
      await app.services.contractors.refresh(1);

      final ContractorOverview seen = (await app.services.contractors.readOverview(1))!;
      expect(seen.refused, isTrue);
      expect(seen.contracts, isEmpty);
    });

    test('a copy kept from before money.view went is not shown', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      expect((await app.services.contractors.readOverview(1))!.contract(1)!.figures, isNotNull);

      (app.api.user['can'] as Map)['money.view'] = false;
      await app.sync();

      final ContractorOverview seen = (await app.services.contractors.readOverview(1))!;
      expect(seen.contract(1)!.figures, isNull);
      expect(seen.details[1]!.lines.single.figures, isNull);
      expect(seen.details[1]!.parts.first.amount, isNull);
      expect(seen.payments.single.figures, isNull);
    });

    test('without the permission or the plan the phone does not ask', () async {
      final TestApp noPermission = await contractorApp(view: false);
      addTearDown(noPermission.dispose);
      final TestApp noPlan = await contractorApp(feature: false);
      addTearDown(noPlan.dispose);

      expect(await noPermission.services.contractors.readOverview(1), isNull);
      expect(await noPlan.services.contractors.readOverview(1), isNull);
    });

    test('a removed project takes its copy with it', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.contractorOverview(1)), isNotNull);

      app.api.pullBodyOverride = <String, dynamic>{
        'server_time': '2026-11-09T14:27:54+05:30',
        'deleted': <String, dynamic>{
          'projects': <int>[1],
        },
      };
      await app.sync();

      expect(await app.db.readMeta(MetaKey.contractorOverview(1)), isNull);
    });
  });

  group('the approvals inbox', () {
    test('a kind of document the server does not describe does not stop the pull', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.api.approvalInbox.add(<String, dynamic>{
        'id': 41,
        'doc_type': 'contractor',
        'status': 'IN_REVIEW',
        'title': 'Contractor payment CP-41',
        'project': <String, dynamic>{'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
        'submitted_at': '2026-11-09T09:12:00+05:30',
        'current_level': 1,
        'levels': <String>['pm'],
        'current_role': 'pm',
        'current_role_label': 'Project Manager',
        'can_act': true,
        // PHP's empty array is a list on the wire.
        'document': <Object>[],
        'steps': <Object>[],
      });

      await app.sync();

      final List<ApprovalRow> rows = await app.db.select(app.db.approvalsInbox).get();
      expect(rows.single.docType, 'contractor');
      expect(rows.single.document, isEmpty);
    });
  });

  group('sending a request', () {
    test('offline it waits, then reaches the server once', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      final String uuid = await request(app);

      final ContractorOverview waiting = (await app.services.contractors.readOverview(1))!;
      expect(waiting.payments.first.notSent, isTrue);
      expect(waiting.payments.first.workOrderNumber, 'WO-1');
      expect(waiting.payments.first.figures!.amount, Money.parse('30000.00'));
      expect(waiting.unsent, 1);

      app.api.offline = false;
      await app.sync();

      expect(app.api.contractorPaymentPosts.keys, <String>[uuid]);
      expect(app.api.contractorPaymentWorkOrder, 1);
      final Map<String, dynamic> body = app.api.contractorPaymentPosts[uuid]!;
      expect(body['amount'], '30000.00');
      expect(body['mode'], 'upi');
      expect(body['client_uuid'], uuid);
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('the advance flag goes as 1 or 0, which multipart also accepts', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await request(app, isAdvance: true);
      expect(jsonDecode((await outbox(app)).single.payload)['is_advance'], 1);

      await app.sync();

      expect(app.api.contractorPaymentPosts.values.single['is_advance'], 1);
    });

    test('a reply that is lost does not make a second request', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final String uuid = await request(app);
      app.api.loseNextProgressReply = true;

      await app.sync();
      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));
      expect(app.api.contractorPaymentPosts, hasLength(1));

      await app.sync();

      expect(app.api.contractorPaymentPosts.keys, <String>[uuid]);
      expect((await outbox(app)).single.status, OutboxStatus.done);
      expect((await app.services.contractors.readOverview(1))!.payments.where((p) => p.clientUuid == uuid), hasLength(1));
    });

    test('a sent request is listed at once, even if the next read fails', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final String uuid = await request(app);
      app.api.contractorBoardFailure = const OfflineFailure();

      await app.sync();

      final ContractorPayment row = (await app.services.contractors.readOverview(1))!.payments.first;
      expect(row.clientUuid, uuid);
      expect(row.status, 'IN_REVIEW');
      expect(row.notSent, isFalse);
    });

    test('a refusal waits for a person, in the server\'s own words', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await request(app);
      app.api.failNextProgressPost = _closed;

      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, 'This contract is closed.');
      expect((await app.services.contractors.readOverview(1))!.payments.first.needsAttention, isTrue);
    });

    test('403 goes to attention too', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await request(app);
      app.api.failNextProgressPost = const ServerFailure('Forbidden', statusCode: 403);

      await app.sync();

      expect((await outbox(app)).single.attentionCode, AttentionCode.forbidden);
    });

    test('a request can be discarded', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await request(app);
      app.api.failNextProgressPost = _closed;
      await app.sync();

      await app.services.syncCenter.discard((await outbox(app)).single.id);

      expect((await outbox(app)), isEmpty);
      expect((await app.services.contractors.readOverview(1))!.unsent, 0);
    });

    test('someone without money.view is never shown the figure they typed back', () async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);
      app.api.offline = true;
      await request(app);

      final ContractorPayment waiting = (await app.services.contractors.readOverview(1))!.payments.first;
      expect(waiting.notSent, isTrue);
      expect(waiting.figures, isNull);

      app.api.offline = false;
      await app.sync();
      final ContractorPayment sent = (await app.services.contractors.readOverview(1))!.payments.first;
      expect(sent.figures, isNull);
      final String stored = (await app.db.readMeta(MetaKey.contractorOverview(1)))!;
      expect(stored, isNot(contains('30000')));
    });

    test('the bill goes with the request and is then cleared', () async {
      final InMemoryAttachmentStore files = InMemoryAttachmentStore();
      final TestApp app = await contractorApp(attachments: files);
      addTearDown(app.dispose);
      await request(app, bill: '/tmp/bill.jpg');
      expect(files.files, hasLength(1));

      await app.sync();

      expect(app.api.contractorPaymentFiles.single, contains('contractor_bills'));
      expect(files.files, isEmpty);
    });

    test('a future date is refused on the phone', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      await expectLater(
        app.services.contractors.requestPayment(
          uuid: newUuid(),
          projectId: 1,
          workOrderId: 1,
          amount: Money.parse('100'),
          paidOn: '2099-01-01',
          mode: 'cash',
          title: 'WO-1',
        ),
        throwsA(isA<ValidationFailure>()),
      );
      expect(await outbox(app), isEmpty);
    });
  });

  group('screens with money.view', () {
    testWidgets('the board shows the paid figures and says when they run ahead', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.textContaining('Paid 90%, Work 70%'), findsOneWidget);
      expect(find.textContaining(en.contractorOverLimit), findsOneWidget);
      expect(find.byKey(k('contractor-request')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a contract shows every figure the server sent', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.contractorDetail(1));

      expect(find.text(en.contractorValue), findsOneWidget);
      expect(find.text('₹1,00,000'), findsOneWidget);
      expect(find.text('₹90,000'), findsOneWidget);
      expect(find.text(en.contractorShortBy), findsOneWidget);
      expect(find.text(en.contractorOverLimit), findsWidgets);
      await tester.scrollUntilVisible(find.text('Plinth'), 200, scrollable: find.byType(Scrollable).first);
      expect(find.textContaining('₹25,000'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a payment opens with its amount and what approving would do', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app);
      await tap(tester, k('contractor-tab-payments'));

      expect(find.text('₹30,000'), findsOneWidget);
      await tap(tester, k('payment-21'));

      expect(find.text(en.contractorImpactAfter), findsOneWidget);
      expect(find.text('Paid 90%, Work 70%'), findsOneWidget);
      expect(find.byKey(k('payment-view-bill')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('screens without money.view', () {
    testWidgets('the board shows work only, with no rupee anywhere', (WidgetTester tester) async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.text('70%'), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
      expect(find.textContaining('Paid'), findsNothing);
      expect(find.textContaining(en.contractorOverLimit), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a contract has no money rows at all, not blank ones', (WidgetTester tester) async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.contractorDetail(1));

      expect(find.textContaining('₹'), findsNothing);
      expect(find.text(en.contractorValue), findsNothing);
      expect(find.text(en.contractorPaid), findsNothing);
      expect(find.text(en.contractorBalance), findsNothing);
      expect(find.text(en.contractorWorkDone), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a payment shows no amount, and offers no bill', (WidgetTester tester) async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app);
      await tap(tester, k('contractor-tab-payments'));

      expect(find.textContaining('₹'), findsNothing);
      await tap(tester, k('payment-21'));
      expect(find.textContaining('₹'), findsNothing);
      expect(find.text(en.contractorAmountLabel), findsNothing);
      expect(find.byKey(k('payment-view-bill')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a request waiting to send shows no figure', (WidgetTester tester) async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);
      app.api.offline = true;
      await request(app);
      await pumpScreen(tester, app);
      await tap(tester, k('contractor-tab-payments'));

      expect(find.text(en.progressNotSent), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });

  group('permissions and refusals', () {
    testWidgets('a 403 is a calm page', (WidgetTester tester) async {
      final TestApp app = await contractorApp(
        setup: (FakeApi api) => api.contractorBoardFailure = const ServerFailure('Forbidden', statusCode: 403),
      );
      addTearDown(app.dispose);
      await pumpScreen(tester, app);

      expect(find.text(en.contractorRefusedTitle), findsOneWidget);
      expect(find.byKey(k('contractor-request')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('with no contractor.request there is nothing to request with', (WidgetTester tester) async {
      final TestApp app = await contractorApp(request: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app);
      expect(find.byKey(k('contractor-request')), findsNothing);

      await pumpScreen(tester, app, start: Routes.contractorDetail(1));
      expect(find.byKey(k('contract-request')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the server\'s refusal shows on the list in its own words', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await request(app);
      app.api.failNextProgressPost = _closed;
      await app.sync();
      await pumpScreen(tester, app);
      await tap(tester, k('contractor-tab-payments'));

      expect(find.text('This contract is closed.'), findsOneWidget);
      expect(find.text(en.shellPillAttention), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home leaves the row out without the permission or the plan', (WidgetTester tester) async {
      final TestApp noPlan = await contractorApp(feature: false);
      addTearDown(noPlan.dispose);
      await pumpShellApp(tester, noPlan);

      expect(find.byKey(k('home-contractors')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home says how contracts stand, in words a site role may see', (WidgetTester tester) async {
      final TestApp site = await contractorApp(money: false);
      addTearDown(site.dispose);
      await pumpShellApp(tester, site);

      expect(find.descendant(of: find.byKey(k('home-contractors')), matching: find.text(en.contractorHomeCount(3))), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home counts contracts paid ahead for someone who may see that', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(
        find.descendant(
          of: find.byKey(k('home-contractors')),
          matching: find.text('${en.contractorHomeAhead(1)} · ${en.contractorHomeOver(1)}'),
        ),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    });
  });

  group('the form', () {
    Future<void> openForm(WidgetTester tester, TestApp app, {String? path}) async {
      await pumpScreen(tester, app, start: path ?? '${Routes.contractorRequest}?work_order=1');
    }

    testWidgets('sends one request with what was entered', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      expect(find.textContaining('Shree Formwork'), findsWidgets);
      await typeAmount(tester, '12500.50');
      await tap(tester, k('payment-form-mode-bank'));
      await tap(tester, k('payment-form-advance'));
      await tap(tester, k('payment-form-send'));

      final OutboxRow row = (await outbox(app)).single;
      expect(row.type, OutboxType.contractorPayment);
      final Map<String, dynamic> data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
      expect(data['amount'], '12500.50');
      expect(data['mode'], 'bank');
      expect(data['is_advance'], 1);
      expect(data['work_order_id'], 1);
      expect(tester.takeException(), isNull);
    });

    testWidgets('an amount needs to be real, and two decimals at most', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      await tap(tester, k('payment-form-send'));
      expect(find.text(en.contractorAmountError), findsOneWidget);

      await typeAmount(tester, '10.123');
      await tap(tester, k('payment-form-send'));
      expect(find.text(en.contractorAmountError), findsOneWidget);

      await typeAmount(tester, '0');
      await tap(tester, k('payment-form-send'));
      expect(find.text(en.contractorAmountError), findsOneWidget);
      expect(await outbox(app), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('asks how it was paid', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      await typeAmount(tester, '100');
      await tap(tester, k('payment-form-send'));

      expect(find.text(en.contractorModeRequired), findsOneWidget);
      expect(await outbox(app), isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a line and a part cannot both be named', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      await typeAmount(tester, '100');
      await tap(tester, k('payment-form-mode-cash'));
      await tap(tester, k('payment-form-against-line'));
      await pick(tester, k('payment-form-line'), 3);
      await tap(tester, k('payment-form-against-part'));
      await pick(tester, k('payment-form-part'), 12);
      await tap(tester, k('payment-form-send'));

      final Map<String, dynamic> data = (jsonDecode((await outbox(app)).single.payload) as Map).cast<String, dynamic>();
      expect(data['contractor_milestone_id'], 12);
      expect(data['boq_item_id'], isNull);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a closed contract is not offered', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await openForm(tester, app, path: Routes.contractorRequest);

      await tap(tester, k('payment-form-contract'));

      expect(find.byKey(k('field-pick-1')), findsOneWidget);
      expect(find.byKey(k('field-pick-3')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('works with no network from the last copy', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await openForm(tester, app);

      await typeAmount(tester, '100');
      await tap(tester, k('payment-form-mode-cash'));
      await tap(tester, k('payment-form-send'));

      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));
      expect(tester.takeException(), isNull);
    });

    testWidgets('someone without money.view still sees no contract figure', (WidgetTester tester) async {
      final TestApp app = await contractorApp(money: false);
      addTearDown(app.dispose);
      await openForm(tester, app);

      // The field's own label says rupees. No figure of the contract does.
      expect(find.textContaining(RegExp(r'₹\s*\d')), findsNothing);
      expect(find.textContaining('Paid 90'), findsNothing);
      expect(find.text('${en.contractorWorkDone} 70%'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('at 360dp', () {
    for (final String locale in <String>['mr', 'hi']) {
      for (final bool money in <bool>[true, false]) {
        testWidgets('every screen fits in $locale, money $money', (WidgetTester tester) async {
          final TestApp app = await contractorApp(money: money);
          addTearDown(app.dispose);

          await pumpScreen(tester, app, locale: locale);
          expect(tester.takeException(), isNull);
          await tap(tester, k('contractor-tab-payments'));
          expect(tester.takeException(), isNull);
          await tap(tester, k('payment-21'));
          expect(tester.takeException(), isNull);

          await pumpScreen(tester, app, locale: locale, start: Routes.contractorDetail(1));
          expect(tester.takeException(), isNull);

          await pumpScreen(tester, app, locale: locale, start: '${Routes.contractorRequest}?work_order=1');
          expect(tester.takeException(), isNull);
          await tap(tester, k('payment-form-against-line'));
          expect(tester.takeException(), isNull);
        });
      }
    }
  });
}
