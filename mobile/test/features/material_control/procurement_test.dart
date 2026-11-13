import 'dart:convert';

import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter/material.dart' hide MaterialState;
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
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/material_control/data/material_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart' show pumpShellApp;
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

Future<TestApp> stockApp({bool stock = true, void Function(FakeApi api)? setup}) async {
  final FakeApi server = FakeApi()
    ..grant('material_control.view')
    ..grant('material_control.challan')
    ..grant('material_control.consume')
    ..grant('material_control.count');
  ((server.user['organization'] as Map)['features'] as List).add('site_control');
  if (stock) server.grantStock();
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<MaterialOverview> overview(TestApp app) async => (await app.services.material.readOverview(1))!;

/// Queues a receipt on PO-101: [cement] bags accepted, [rejected] turned away, and
/// optionally [sand] MT.
Future<String> receiveOrder(TestApp app, {String cement = '40', String rejected = '0', String? sand}) async {
  final OpenOrder order = (await overview(app)).order(70)!;
  return app.services.material.receiveAgainstOrder(
    projectId: 1,
    order: order,
    date: app.services.context.today,
    challanNo: 'CH-77',
    lines: <ReceiptEntry>[
      ReceiptEntry(orderLineId: 701, materialId: 5, unitId: 10, qty: Qty.parse(cement), rejected: Qty.parse(rejected)),
      if (sand != null) ReceiptEntry(orderLineId: 702, materialId: 6, unitId: 11, qty: Qty.parse(sand), rejected: Qty.zero),
    ],
    title: order.number,
    subtitle: 'Cement',
  );
}

Future<String> issueCement(TestApp app, {String qty = '10', int? boq = 1, String? activity}) {
  return app.services.material.issueFromStore(
    projectId: 1,
    storeId: 1,
    date: app.services.context.today,
    issuedTo: 'Mason gang',
    lines: <IssueEntry>[
      IssueEntry(materialId: 5, unitId: 10, qty: Qty.parse(qty), boqItemId: boq, activity: activity),
    ],
    title: 'Cement',
  );
}

Map<String, dynamic> orderLine(TestApp app, int id) {
  final List<Map<String, dynamic>> orders = (app.api.procurementSnapshotBody['purchase_orders'] as List).cast<Map<String, dynamic>>();
  return (orders.single['items'] as List).cast<Map<String, dynamic>>().firstWhere((line) => line['id'] == id);
}

String serverStock(TestApp app, int materialId) => (app.api.procurementSnapshotBody['stock'] as List)
    .cast<Map<String, dynamic>>()
    .firstWhere((row) => row['material_id'] == materialId)['balance'] as String;

Future<void> freshApp(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> pumpMaterial(WidgetTester tester, TestApp app, {String start = Routes.material, String locale = 'en'}) async {
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

Future<void> typeInto(WidgetTester tester, String key, String text) async {
  await tester.ensureVisible(find.byKey(k(key)));
  await tester.pump();
  await tester.enterText(find.descendant(of: find.byKey(k(key)), matching: find.byType(TextField)), text);
  await settle(tester);
}

void main() {
  // Real glyph widths, or a 360dp check measures a font that is not the app's.
  setUpAll(loadRealFonts);

  group('reading', () {
    late TestApp app;

    setUp(() async => app = await stockApp());
    tearDown(() => app.dispose());

    test('the open order, the stores and the stock come from one refresh', () async {
      final MaterialOverview seen = await overview(app);
      expect(seen.ordersDue.single.number, 'PO-101');
      expect(seen.ordersDue.single.vendor, 'Gupta Traders');
      expect(seen.order(70)!.line(701)!.remaining, Qty.parse('100.000'));
      expect(seen.order(70)!.line(702)!.unitId, 11);
      expect(seen.stockIn(1, 5), Qty.parse('75.000'));
      expect(seen.receipts, isEmpty);
    });

    test('a rate, an amount or a tax figure is never kept on the phone', () async {
      final String kept = (await app.db.readMeta(MetaKey.materialOverview(1)))!;
      expect(kept, contains('PO-101'));
      for (final String key in <String>['"rate"', '"amount"', '"gst_pct"', '"gst_amount"', '"subtotal"', '"total"']) {
        expect(kept, isNot(contains(key)), reason: key);
      }
      expect(kept, isNot(contains('400.00')));
      expect(kept, isNot(contains('40000')));
    });

    test('a receipt already on the server is listed with what was turned away', () async {
      app.api.grnRows = <Map<String, dynamic>>[
        <String, dynamic>{
          'id': 11,
          'number': 'GRN-0011',
          'client_uuid': null,
          'purchase_order_id': 70,
          'store_id': 1,
          'grn_date': '2026-11-05',
          'challan_no': 'CH-5',
          'status': 'REVERSED',
          'rate': '400.00',
          'items': <Map<String, dynamic>>[
            <String, dynamic>{'id': 1, 'purchase_order_item_id': 701, 'material_id': 5, 'qty': '20.000', 'unit_id': 10, 'base_qty': '20.000', 'rejected_qty': '2.000', 'amount': '8000.00'},
          ],
        },
      ];
      await app.services.material.refresh(1);

      final MaterialOverview seen = await overview(app);
      final GoodsReceipt receipt = seen.receipts.single;
      expect(receipt.number, 'GRN-0011');
      expect(receipt.reversed, isTrue);
      expect(receipt.lines.single.qty, Qty.parse('20.000'));
      expect(receipt.lines.single.rejected, Qty.parse('2.000'));
      expect(await app.db.readMeta(MetaKey.materialOverview(1)), isNot(contains('8000')));
    });

    test('a receipt waiting to send is due less, stock more, and listed as not sent', () async {
      await receiveOrder(app, cement: '40');

      final MaterialOverview seen = await overview(app);
      expect(seen.order(70)!.line(701)!.remaining, Qty.parse('60.000'));
      expect(seen.stockIn(1, 5), Qty.parse('115.000'));
      expect(seen.row(5)!.received, Qty.parse('240.000'));
      expect(seen.receipts.single.notSent, isTrue);
      expect(seen.receipts.single.orderNumber, 'PO-101');
      expect(seen.unsent, 1);
    });

    test('turned-away goods are not received: the order still owes them', () async {
      await receiveOrder(app, cement: '40', rejected: '3');

      final MaterialOverview seen = await overview(app);
      expect(seen.order(70)!.line(701)!.remaining, Qty.parse('60.000'));
      expect(seen.stockIn(1, 5), Qty.parse('115.000'));
      expect(seen.receipts.single.lines.single.rejected, Qty.parse('3.000'));
    });

    test('goods in another unit count against the order in the base unit', () async {
      await receiveOrder(app, cement: '1', sand: '4');

      final MaterialOverview seen = await overview(app);
      expect(seen.order(70)!.line(702)!.remaining, Qty.parse('6.000'));
      expect(seen.stockIn(1, 6), Qty.parse('7.000'));
    });

    test('an order that is fully received waiting to send is no longer due', () async {
      await receiveOrder(app, cement: '100', sand: '10');
      expect((await overview(app)).ordersDue, isEmpty);
    });

    test('an issue waiting to send takes stock from its store and is listed with who got it', () async {
      await issueCement(app, qty: '10');

      final MaterialOverview seen = await overview(app);
      expect(seen.stockIn(1, 5), Qty.parse('65.000'));
      expect(seen.row(5)!.used, Qty.parse('130.000'));
      expect(seen.uses.first.issuedTo, 'Mason gang');
      expect(seen.uses.first.notSent, isTrue);
    });

    test('an issue with several lines shows every line', () async {
      app.api.materialUseRows = <Map<String, dynamic>>[
        <String, dynamic>{
          'id': 9,
          'issue_date': '2026-11-02',
          'issued_to': 'Plaster gang',
          'items': <Map<String, dynamic>>[
            <String, dynamic>{'material_id': 5, 'qty': '4.000', 'unit_id': 10, 'activity': 'Plaster'},
            <String, dynamic>{'material_id': 6, 'qty': '1.000', 'unit_id': 11, 'activity': 'Plaster'},
          ],
        },
      ];
      await app.services.material.refresh(1);
      final MaterialOverview seen = await overview(app);
      expect(seen.uses.map((use) => use.materialId), <int>[5, 6]);
      expect(seen.uses.every((use) => use.issuedTo == 'Plaster gang'), isTrue);
    });

    test('someone who cannot receive or issue keeps no order or stock, and no receipt is read', () async {
      final TestApp plain = await stockApp(stock: false);
      addTearDown(plain.dispose);

      final MaterialOverview seen = await overview(plain);
      expect(seen.orders, isEmpty);
      expect(seen.stock, isEmpty);
      expect(plain.api.receiptReads, 0);
      final String kept = (await plain.db.readMeta(MetaKey.materialOverview(1)))!;
      expect(kept, isNot(contains('PO-101')));
    });

    test('without the purchasing list the rest of the page still loads', () async {
      final TestApp limited = await stockApp(
        setup: (api) => api.receiptListFailure = const ServerFailure('Forbidden.', statusCode: 403),
      );
      addTearDown(limited.dispose);
      final MaterialOverview seen = await overview(limited);
      expect(seen.rows, hasLength(1));
      expect(seen.receipts, isEmpty);
    });

    test('a figure that will not read never stops the page', () async {
      app.api.procurementSnapshotBody['purchase_orders'] = <Map<String, dynamic>>[
        <String, dynamic>{
          'id': 71,
          'number': 'PO-X',
          'items': <Map<String, dynamic>>[
            <String, dynamic>{'id': 1, 'material_id': 5, 'qty': 'many', 'remaining_base_qty': null},
          ],
        },
      ];
      app.api.procurementSnapshotBody['stock'] = <String, dynamic>{};
      await app.services.material.refresh(1);
      final MaterialOverview seen = await overview(app);
      expect(seen.order(71)!.line(1)!.remaining, Qty.zero);
      expect(seen.stock, isEmpty);
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await stockApp());
    tearDown(() => app.dispose());

    test('it uses the single endpoints, a whole receipt under one client_uuid', () async {
      expect(OutboxType.goodsReceipt.isDirect, isTrue);
      expect(OutboxType.materialIssue.isDirect, isTrue);
      final String uuid = await receiveOrder(app, cement: '40', rejected: '3');
      await app.sync();

      final Map<String, dynamic> body = app.api.grnBodies.single;
      expect(body['client_uuid'], uuid);
      expect(body['purchase_order_id'], 70);
      expect(body['challan_no'], 'CH-77');
      // What is for the phone alone is not sent.
      expect(body.containsKey('local'), isFalse);
      expect((body['items'] as List).single, <String, dynamic>{
        'purchase_order_item_id': 701,
        'qty': '40.000',
        'unit_id': 10,
        'rejected_qty': '3.000',
      });
      expect(app.api.grnPosts[uuid]!['status'], 'POSTED');
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('a receipt whose reply is lost is stored once and adds stock once', () async {
      final String uuid = await receiveOrder(app, cement: '40');
      app.api.loseNextProgressReply = true;
      await app.sync();
      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));

      await app.sync();

      expect(app.api.grnPosts.keys, <String>[uuid]);
      expect(app.api.grnPostCalls, 2);
      expect(serverStock(app, 5), '115.000');
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('the reply is still good after the order has filled meanwhile', () async {
      final String uuid = await receiveOrder(app, cement: '100', sand: '10');
      app.api.loseNextProgressReply = true;
      await app.sync();
      expect((app.api.procurementSnapshotBody['purchase_orders'] as List), isEmpty);

      await app.sync();

      expect(app.api.grnPosts.keys, <String>[uuid]);
      expect(serverStock(app, 5), '175.000');
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('an over-receipt another phone caused waits for a person, in the server\'s words', () async {
      await receiveOrder(app, cement: '40');
      // Another phone received 70 bags of the 100 first.
      orderLine(app, 701)
        ..['received_base_qty'] = '70.000'
        ..['remaining_base_qty'] = '30.000';
      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, contains('still due'));
      expect(app.api.grnPosts, isEmpty);
    });

    test('a server fault is tried again, not handed to a person', () async {
      await receiveOrder(app, cement: '40');
      app.api.failNextProgressPost = const ServerFailure('Server error', statusCode: 500);
      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.pending);

      await app.sync();
      expect((await outbox(app)).single.status, OutboxStatus.done);
      expect(app.api.grnPosts, hasLength(1));
    });

    test('what was sent is in the copy before the next read, so it is not received twice', () async {
      await receiveOrder(app, cement: '40', rejected: '3');
      // The next read would fail: the copy must already know.
      app.api.snapshotFailure = const ServerFailure('Server error', statusCode: 500);
      await app.sync();

      final MaterialOverview seen = await overview(app);
      expect(seen.unsent, 0);
      expect(seen.order(70)!.line(701)!.remaining, Qty.parse('60.000'));
      expect(seen.stockIn(1, 5), Qty.parse('115.000'));
      expect(seen.receipts.single.notSent, isFalse);
      expect(seen.receipts.single.lines.single.rejected, Qty.parse('3.000'));
    });

    test('a receipt that fills the order takes it off the list', () async {
      await receiveOrder(app, cement: '100', sand: '10');
      app.api.snapshotFailure = const ServerFailure('Server error', statusCode: 500);
      await app.sync();
      expect((await overview(app)).ordersDue, isEmpty);
    });

    test('a receipt the server already lists is not laid over a second time', () async {
      await receiveOrder(app, cement: '40');
      final OutboxRow row = (await outbox(app)).single;
      final Map<String, dynamic> data = (jsonDecode(row.payload) as Map).cast<String, dynamic>()..remove('local');
      // The server stored it and a read ran before the answer was recorded.
      await app.api.postGoodsReceipt(1, <String, dynamic>{...data, 'client_uuid': row.wireUuid});
      await app.services.material.refresh(1);

      final MaterialOverview seen = await overview(app);
      expect(seen.order(70)!.line(701)!.remaining, Qty.parse('60.000'));
      expect(seen.stockIn(1, 5), Qty.parse('115.000'));
      expect(seen.unsent, 0);
    });

    test('an issue whose reply is lost takes stock once', () async {
      final String uuid = await issueCement(app, qty: '10');
      app.api.loseNextProgressReply = true;
      await app.sync();
      await app.sync();

      expect(app.api.issuePosts.keys, <String>[uuid]);
      expect(app.api.issuePostCalls, 2);
      expect(serverStock(app, 5), '65.000');
      final Map<String, dynamic> item = (app.api.issueBodies.first['items'] as List).single as Map<String, dynamic>;
      expect(item, <String, dynamic>{'material_id': 5, 'qty': '10.000', 'unit_id': 10, 'boq_item_id': 1, 'activity': null});
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('an issue above the stock waits for a person, and nothing leaves the store', () async {
      await issueCement(app, qty: '500');
      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionReason, contains('available'));
      expect(serverStock(app, 5), '75.000');
    });

    test('an issue is in the copy after it is sent: stock down, listed once', () async {
      await issueCement(app, qty: '10');
      app.api.snapshotFailure = const ServerFailure('Server error', statusCode: 500);
      await app.sync();

      final MaterialOverview seen = await overview(app);
      expect(seen.stockIn(1, 5), Qty.parse('65.000'));
      expect(seen.uses.first.notSent, isFalse);
      expect(seen.uses.where((use) => use.issuedTo == 'Mason gang'), hasLength(1));
    });

    test('a permission lost meanwhile is a refusal for a person', () async {
      await receiveOrder(app, cement: '40');
      app.api.failNextProgressPost = const ServerFailure('Forbidden', statusCode: 403);
      await app.sync();
      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.forbidden);
    });
  });

  group('the form checks what the server checks', () {
    testWidgets('a receipt needs the order, and some accepted quantity on a line', (WidgetTester tester) async {
      final TestApp app = await stockApp(
        setup: (api) => (api.procurementSnapshotBody['purchase_orders'] as List).add(<String, dynamic>{
          'id': 71,
          'number': 'PO-102',
          'status': 'ISSUED',
          'vendor': 'Shree Traders',
          'store_id': 1,
          'items': <Map<String, dynamic>>[
            <String, dynamic>{'id': 711, 'material_id': 5, 'qty': '10.000', 'unit_id': 10, 'base_qty': '10.000', 'received_base_qty': '0.000', 'remaining_base_qty': '10.000'},
          ],
        }),
      );
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      // Two orders are open, so one has to be chosen.
      await tap(tester, k('receive-order-save'));
      expect(find.text(en.procurementOrderRequired), findsOneWidget);
      await pick(tester, k('receive-order-pick'), 70);
      expect(find.text('PO-101 · Gupta Traders'), findsOneWidget);

      await tap(tester, k('receive-order-save'));
      expect(find.text(en.procurementNothingReceived), findsOneWidget);
      expect(app.api.grnPostCalls, 0);
      expect(await outbox(app), isEmpty);
    });

    testWidgets('a single open order is already chosen, and no rate or amount is shown', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      expect(find.text('PO-101 · Gupta Traders'), findsOneWidget);
      expect(find.text('Cement'), findsOneWidget);
      expect(find.textContaining('Ordered 100.000 bag'), findsOneWidget);
      expect(find.textContaining('Still due 100.000 bag'), findsOneWidget);
      expect(find.textContaining('Ordered 10.000 MT'), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
      expect(find.textContaining('400'), findsNothing);
      expect(find.textContaining('1500'), findsNothing);
      expect(find.textContaining('18.00'), findsNothing);
      expect(find.textContaining(RegExp('rate|amount|total|gst', caseSensitive: false)), findsNothing);
    });

    testWidgets('more than is due is refused with the figure, in the unit being typed', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      await typeInto(tester, 'receive-accepted-701', '150');
      await tap(tester, k('receive-order-save'));
      expect(find.text(en.procurementOverDue('100.000 bag')), findsOneWidget);
      expect(await outbox(app), isEmpty);

      // 6 MT of cement is 120 bags, and 100 are due: the answer is in MT.
      await tap(tester, k('receive-unit-701-11'));
      expect(find.textContaining('Still due 5.000 MT'), findsOneWidget);
      await typeInto(tester, 'receive-accepted-701', '6');
      await tap(tester, k('receive-order-save'));
      expect(find.text(en.procurementOverDue('5.000 MT')), findsOneWidget);
      expect(await outbox(app), isEmpty);
    });

    testWidgets('turned-away goods with nothing accepted cannot be sent, and say why', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      await typeInto(tester, 'receive-rejected-701', '5');
      await tap(tester, k('receive-order-save'));
      expect(find.text(en.procurementRejectedOnly), findsOneWidget);
      expect(await outbox(app), isEmpty);
    });

    testWidgets('four decimals are refused, not rounded', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      await typeInto(tester, 'receive-accepted-701', '1.2345');
      await tap(tester, k('receive-order-save'));
      expect(find.text(en.procurementTooManyDecimals), findsOneWidget);
      expect(await outbox(app), isEmpty);
    });

    testWidgets('a part delivery with some turned away is queued as the server wants it', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      // Cement came in part; nothing came of the sand, so its line is left empty.
      await typeInto(tester, 'receive-accepted-701', '40');
      await typeInto(tester, 'receive-rejected-701', '3');
      await typeInto(tester, 'receive-challan-no', 'CH-9');
      await tap(tester, k('receive-order-save'));
      await app.sync();

      final Map<String, dynamic> body = app.api.grnBodies.single;
      expect(body['challan_no'], 'CH-9');
      final List<dynamic> items = body['items'] as List<dynamic>;
      expect(items, hasLength(1));
      expect(items.single, <String, dynamic>{
        'purchase_order_item_id': 701,
        'qty': '40.000',
        'unit_id': 10,
        'rejected_qty': '3.000',
      });
      final MaterialOverview seen = await overview(app);
      // The order stays open for the rest, and the turned-away bags are still owed.
      expect(seen.ordersDue.single.line(701)!.remaining, Qty.parse('60.000'));
      expect(seen.ordersDue.single.line(702)!.remaining, Qty.parse('10.000'));
    });

    testWidgets('with no open order there is a calm page and nothing to save', (WidgetTester tester) async {
      final TestApp app = await stockApp(setup: (api) => (api.procurementSnapshotBody['purchase_orders'] as List).clear());
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceiveOrder);

      expect(find.text(en.procurementNoOrdersTitle), findsOneWidget);
      expect(find.byKey(k('receive-order-save')), findsNothing);
    });

    testWidgets('an issue needs a material, a quantity, and a BOQ item or an activity', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialIssue);

      await tap(tester, k('issue-stock-save'));
      expect(find.text(en.materialChoose), findsWidgets);

      await pick(tester, k('issue-material-0'), 5);
      expect(find.textContaining('In this store 75.000 bag'), findsOneWidget);
      await typeInto(tester, 'issue-qty-0', '80');
      expect(find.text(en.materialOverStock), findsOneWidget);
      await typeInto(tester, 'issue-qty-0', '10');
      expect(find.text(en.materialOverStock), findsNothing);

      await tap(tester, k('issue-stock-save'));
      expect(find.text(en.procurementWorkRequired), findsOneWidget);
      expect(await outbox(app), isEmpty);

      await typeInto(tester, 'issue-activity-0', 'Slab 3 plaster');
      await typeInto(tester, 'issue-issued-to', 'Plaster gang');
      await tap(tester, k('issue-stock-save'));
      await app.sync();

      final Map<String, dynamic> body = app.api.issueBodies.single;
      expect(body['store_id'], 1);
      expect(body['issued_to'], 'Plaster gang');
      expect((body['items'] as List).single, <String, dynamic>{
        'material_id': 5,
        'qty': '10.000',
        'unit_id': 10,
        'boq_item_id': null,
        'activity': 'Slab 3 plaster',
      });
      expect(serverStock(app, 5), '65.000');
    });

    testWidgets('two items go in one issue, each in its own unit', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialIssue);

      await pick(tester, k('issue-material-0'), 5);
      await typeInto(tester, 'issue-qty-0', '5');
      await pick(tester, k('issue-boq-0'), 1);

      await tap(tester, k('issue-add-line'));
      await pick(tester, k('issue-material-1'), 6);
      await typeInto(tester, 'issue-qty-1', '1');
      await typeInto(tester, 'issue-activity-1', 'Backfill');
      await tap(tester, k('issue-stock-save'));
      await app.sync();

      final List<dynamic> items = app.api.issueBodies.single['items'] as List<dynamic>;
      expect(items, hasLength(2));
      expect((items[0] as Map)['boq_item_id'], 1);
      expect((items[1] as Map)['material_id'], 6);
      expect((items[1] as Map)['unit_id'], 11);
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    testWidgets('a project with no store cannot issue from the phone', (WidgetTester tester) async {
      final TestApp app = await stockApp(setup: (api) => (api.procurementSnapshotBody['stores'] as List).clear());
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialIssue);

      expect(find.text(en.procurementNoStoreTitle), findsOneWidget);
      expect(find.byKey(k('issue-stock-save')), findsNothing);
    });
  });

  group('the Material page', () {
    testWidgets('offers the two entries and a tab of orders and receipts to someone who may', (WidgetTester tester) async {
      final TestApp app = await stockApp();
      addTearDown(app.dispose);
      await receiveOrder(app, cement: '40', rejected: '3');
      await pumpMaterial(tester, app);

      await tap(tester, k('material-add'));
      expect(find.text(en.procurementReceiveOrder), findsOneWidget);
      expect(find.text(en.procurementIssueStock), findsOneWidget);
      expect(find.text(en.materialReceived), findsOneWidget);
      await tester.tap(find.byKey(k('material-action-${Routes.materialReceiveOrder}')));
      await settle(tester);
      expect(find.byKey(k('receive-order-save')), findsOneWidget);
      await tester.tap(find.text(en.commonBack));
      await settle(tester);

      await tester.ensureVisible(find.text(en.procurementTabReceipts));
      await tester.pump();
      await tester.tap(find.text(en.procurementTabReceipts));
      await settle(tester);
      expect(find.text('PO-101'), findsWidgets);
      expect(find.textContaining(en.procurementOrderDue(2)), findsOneWidget);
      expect(find.textContaining(en.procurementRejectedFigure('3.000 bag')), findsOneWidget);
      expect(find.text(en.progressNotSent), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);

      await tap(tester, k('material-order-70'));
      expect(find.byKey(k('receive-order-save')), findsOneWidget);
      expect(find.text('PO-101 · Gupta Traders'), findsOneWidget);
    });

    testWidgets('someone without procurement.stock sees neither the entries nor the tab', (WidgetTester tester) async {
      final TestApp app = await stockApp(stock: false);
      addTearDown(app.dispose);
      await pumpMaterial(tester, app);

      await tap(tester, k('material-add'));
      expect(find.text(en.materialReceived), findsOneWidget);
      expect(find.text(en.procurementReceiveOrder), findsNothing);
      expect(find.text(en.procurementIssueStock), findsNothing);
      await tester.tapAt(const Offset(10, 10));
      await settle(tester);
      expect(find.text(en.procurementTabReceipts), findsNothing);
    });

    testWidgets('a plan without procurement hides them even with the permission', (WidgetTester tester) async {
      final TestApp app = await stockApp(
        stock: false,
        setup: (api) => api.grant('procurement.stock'),
      );
      addTearDown(app.dispose);
      await pumpMaterial(tester, app);
      expect(find.text(en.procurementTabReceipts), findsNothing);
      await tap(tester, k('material-add'));
      expect(find.text(en.procurementReceiveOrder), findsNothing);
    });

    testWidgets('the two pages are not reachable without them: they go Home', (WidgetTester tester) async {
      final TestApp none = await stockApp(stock: false);
      addTearDown(none.dispose);
      await pumpShellApp(tester, none, location: Routes.materialReceiveOrder);
      expect(find.byType(HomeScreen), findsOneWidget);
      await freshApp(tester);
      await pumpShellApp(tester, none, location: Routes.materialIssue);
      expect(find.byType(HomeScreen), findsOneWidget);

      final TestApp allowed = await stockApp();
      addTearDown(allowed.dispose);
      await freshApp(tester);
      await pumpShellApp(tester, allowed, location: Routes.materialIssue);
      expect(find.byType(HomeScreen), findsNothing);
      expect(find.byKey(k('issue-stock-save')), findsOneWidget);
    });
  });

  group('small phones', () {
    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every screen fits 360dp in $locale, with its problems showing', (WidgetTester tester) async {
        final TestApp app = await stockApp();
        addTearDown(app.dispose);
        await receiveOrder(app, cement: '40', rejected: '3');

        await pumpMaterial(tester, app, locale: locale);
        await tester.ensureVisible(find.text(lookupAppLocalizations(Locale(locale)).procurementTabReceipts));
        await tester.pump();
        await tester.tap(find.text(lookupAppLocalizations(Locale(locale)).procurementTabReceipts));
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'material page');

        await freshApp(tester);
        await pumpMaterial(tester, app, start: Routes.materialReceiveOrder, locale: locale);
        await typeInto(tester, 'receive-accepted-701', '150');
        await typeInto(tester, 'receive-rejected-702', '2');
        await tap(tester, k('receive-order-save'));
        expect(tester.takeException(), isNull, reason: 'receive form');

        await freshApp(tester);
        await pumpMaterial(tester, app, start: '${Routes.materialIssue}?material=5', locale: locale);
        await tap(tester, k('issue-add-line'));
        await tap(tester, k('issue-stock-save'));
        expect(tester.takeException(), isNull, reason: 'issue form');
      });
    }
  });
}
