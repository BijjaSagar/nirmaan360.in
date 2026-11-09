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
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/features/material_control/data/material_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../support/test_app.dart';

Future<TestApp> materialApp({
  bool canEnter = true,
  void Function(FakeApi api)? setup,
  AttachmentStore? attachments,
}) async {
  final FakeApi server = FakeApi()..grant('material_control.view');
  if (canEnter) {
    server
      ..grant('material_control.challan')
      ..grant('material_control.consume')
      ..grant('material_control.count');
  }
  ((server.user['organization'] as Map)['features'] as List).add('site_control');
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server, attachments: attachments);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<String> receive(TestApp app, {String qty = '50.000', int? unitId, int materialId = 5, String? photo}) {
  return app.services.material.receive(
    projectId: 1,
    materialId: materialId,
    supplierId: 8,
    unitId: unitId,
    qty: Qty.parse(qty),
    date: app.services.context.today,
    photoPath: photo,
    title: 'Cement',
  );
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> pumpMaterial(
  WidgetTester tester,
  TestApp app, {
  String start = Routes.material,
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

Future<void> typeQty(WidgetTester tester, String text) async {
  await tester.enterText(
    find.descendant(of: find.byKey(k('material-form-qty')), matching: find.byType(TextField)),
    text,
  );
  await settle(tester);
}

void main() {
  group('reading', () {
    late TestApp app;

    setUp(() async => app = await materialApp());
    tearDown(() => app.dispose());

    Future<MaterialOverview> overview() async => (await app.services.material.readOverview(1))!;

    test('the check, lists and pickers all come from one refresh', () async {
      final MaterialOverview seen = await overview();
      expect(seen.row(5)!.stock, Qty.parse('75.000'));
      expect(seen.openFlags, 1);
      expect(seen.materialName(5), 'Cement');
      expect(seen.baseUnit(5), 'bag');
      expect(seen.supplier(8)!.warning, 'Delivered short 3 times.');
      expect(seen.supplier(9)!.warning, isNull);
      expect(seen.deliveries.single.supplier, 'Gupta Traders');
      expect(seen.uses.single.activity, 'Slab 1');
      expect(seen.counts.single.variance, Qty.parse('-5.000'));
    });

    test('a delivery waiting to send already counts as stock', () async {
      await receive(app, qty: '50.000');

      final MaterialOverview seen = await overview();
      expect(seen.row(5)!.received, Qty.parse('250.000'));
      expect(seen.row(5)!.stock, Qty.parse('125.000'));
      expect(seen.deliveries.first.notSent, isTrue);
      expect(seen.unsent, 1);
    });

    test('other units are converted to the base unit', () async {
      await receive(app, qty: '2.000', unitId: 11);
      expect((await overview()).row(5)!.stock, Qty.parse('115.000'));
    });

    test('a use takes stock away and a later count replaces it', () async {
      await app.services.material.use(
        projectId: 1,
        materialId: 5,
        boqItemId: 1,
        qty: Qty.parse('10.000'),
        date: app.services.context.today,
        title: 'Cement',
      );
      expect((await overview()).row(5)!.stock, Qty.parse('65.000'));

      await app.services.material.count(
        projectId: 1,
        materialId: 5,
        counted: Qty.parse('60.000'),
        date: app.services.context.today,
        title: 'Cement',
      );
      final MaterialOverview seen = await overview();
      expect(seen.row(5)!.stock, Qty.parse('60.000'));
      expect(seen.unsent, 2);
    });

    test('the first delivery of a material makes a new row', () async {
      await receive(app, materialId: 6, qty: '3.000');
      final MaterialRow row = (await overview()).row(6)!;
      expect(row.name, 'Sand');
      expect(row.unit, 'MT');
      expect(row.stock, Qty.parse('3.000'));
    });

    test('without the purchasing list the rest of the page still loads', () async {
      final TestApp limited = await materialApp(
        setup: (api) => api.snapshotFailure = const ServerFailure('Forbidden.', statusCode: 403),
      );
      addTearDown(limited.dispose);
      final MaterialOverview seen = (await limited.services.material.readOverview(1))!;
      expect(seen.rows, hasLength(1));
      expect(seen.materials, isEmpty);
    });

    test('a plan without site control fetches nothing', () async {
      await app.db.deleteMeta(MetaKey.materialOverview(1));
      app.services.session.user!.features.remove('site_control');
      await app.services.material.refreshCurrent(force: true);
      expect(await app.services.material.readOverview(1), isNull);
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await materialApp());
    tearDown(() => app.dispose());

    test('a delivery whose reply is lost moves stock once', () async {
      final String uuid = await receive(app);
      app.api.loseNextProgressReply = true;
      await app.sync();
      expect((await outbox(app)).single.status, isNot(OutboxStatus.done));

      await app.sync();

      expect(app.api.challanPosts.keys, <String>[uuid]);
      expect(app.api.challanPosts[uuid]!['party_id'], 8);
      expect((await outbox(app)).single.status, OutboxStatus.done);
    });

    test('the challan photo goes with the delivery and is then cleared', () async {
      final InMemoryAttachmentStore files = InMemoryAttachmentStore();
      final TestApp withFiles = await materialApp(attachments: files);
      addTearDown(withFiles.dispose);
      await receive(withFiles, photo: '/tmp/challan.jpg');
      expect(files.files, hasLength(1));

      await withFiles.sync();

      expect(withFiles.api.challanFiles.single, contains('material_challans'));
      expect(files.files, isEmpty);
    });

    test('a use the server refuses waits for a person', () async {
      await app.services.material.use(
        projectId: 1,
        materialId: 5,
        boqItemId: 1,
        qty: Qty.parse('999.000'),
        date: app.services.context.today,
        title: 'Cement',
      );
      app.api.failNextProgressPost = const ValidationFailure(
        'Not enough stock.',
        errors: <String, List<String>>{
          'stock': <String>['Not enough stock.'],
        },
      );
      await app.sync();

      final OutboxRow row = (await outbox(app)).single;
      expect(row.status, OutboxStatus.attention);
      expect(row.attentionCode, AttentionCode.validation);
      expect(row.attentionReason, contains('Not enough stock'));
    });

    test('counting nothing is a real answer', () async {
      await app.services.material.count(
        projectId: 1,
        materialId: 5,
        counted: Qty.zero,
        date: app.services.context.today,
        title: 'Cement',
      );
      await app.sync();
      expect(app.api.stockCountPosts.values.single['physical_qty'], '0.000');
    });

    test('a removed project takes its material off the phone', () async {
      final Map<String, dynamic> project = app.api.projects.firstWhere((p) => p['id'] == 1);
      app.api.softDeleteOnWeb('projects', project);
      app.api.projects.remove(project);
      await app.sync();
      expect(await app.services.material.readOverview(1), isNull);
    });
  });

  group('screens', () {
    testWidgets('the page leads with questions and shows each list', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app);

      expect(find.text('1 to answer'), findsOneWidget);
      expect(find.textContaining('In stock 75.000 bag'), findsOneWidget);

      await tester.tap(find.text('Challans'));
      await settle(tester);
      expect(find.textContaining('Gupta Traders'), findsOneWidget);

      await tester.tap(find.text('Used'));
      await settle(tester);
      expect(find.textContaining('Slab 1'), findsOneWidget);

      await tester.ensureVisible(find.text('Counts'));
      await tester.pump();
      await tester.tap(find.text('Counts'));
      await settle(tester);
      expect(find.textContaining('Difference −5.000 bag'), findsOneWidget);
    });

    testWidgets('a material opens with its figures and its question', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app);

      await tap(tester, k('material-row-5'));
      expect(find.text('Should be in stock'), findsOneWidget);
      expect(find.textContaining('Where did it go?'), findsOneWidget);
      expect(find.text('Write the reason on the website.'), findsOneWidget);
      expect(find.text('Count stock'), findsOneWidget);
    });

    testWidgets('the add button offers the three entries', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app);

      await tap(tester, k('material-add'));
      expect(find.text('Material received'), findsOneWidget);
      expect(find.text('Material used'), findsOneWidget);
      expect(find.text('Count stock'), findsOneWidget);
    });

    testWidgets('someone who can only view gets no way to add', (tester) async {
      final TestApp app = await materialApp(canEnter: false);
      addTearDown(app.dispose);
      await pumpMaterial(tester, app);

      expect(find.byKey(k('material-add')), findsNothing);
      expect(find.text('Count stock'), findsNothing);
    });

    testWidgets('a delivery needs a material, a supplier and a quantity', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceive);

      await tap(tester, k('material-form-save'));
      expect(find.text('Choose material'), findsWidgets);

      await pick(tester, k('material-form-material'), 5);
      await typeQty(tester, '40');
      await tap(tester, k('material-form-save'));
      expect(find.text('Say who supplied it'), findsOneWidget);

      await pick(tester, k('material-form-supplier'), 8);
      expect(find.text('Delivered short 3 times.'), findsOneWidget);
      await tap(tester, k('material-form-save'));
      await app.sync();

      final Map<String, dynamic> posted = app.api.challanPosts.values.single;
      expect(posted['material_id'], 5);
      expect(posted['party_id'], 8);
      expect(posted['supplier_name'], isNull);
      expect(posted['qty'], '40.000');
    });

    testWidgets('a new supplier can be typed in', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialReceive);

      await pick(tester, k('material-form-material'), 5);
      await typeQty(tester, '5');
      await tap(tester, k('material-form-supplier-switch'));
      await tester.enterText(
        find.descendant(of: find.byKey(k('material-form-supplier-name')), matching: find.byType(TextField)),
        'Sai Traders',
      );
      await tap(tester, k('material-form-save'));
      await app.sync();

      final Map<String, dynamic> posted = app.api.challanPosts.values.single;
      expect(posted['party_id'], isNull);
      expect(posted['supplier_name'], 'Sai Traders');
    });

    testWidgets('a use needs a BOQ line and warns above the stock', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialUse);

      await pick(tester, k('material-form-material'), 5);
      expect(find.textContaining('In stock 75.000 bag'), findsOneWidget);
      await typeQty(tester, '80');
      expect(find.textContaining('more than the stock'), findsOneWidget);
      await typeQty(tester, '10');
      expect(find.textContaining('more than the stock'), findsNothing);

      await tap(tester, k('material-form-save'));
      expect(find.text('Choose an item'), findsWidgets);
      expect(app.api.materialUsePosts, isEmpty);

      await pick(tester, k('material-form-boq'), 1);
      await tap(tester, k('material-form-save'));
      await app.sync();

      final Map<String, dynamic> posted = app.api.materialUsePosts.values.single;
      expect(posted['boq_item_id'], 1);
      expect(posted['extra_work'], isNull);
    });

    testWidgets('extra work is written instead of a BOQ line', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: Routes.materialUse);

      await pick(tester, k('material-form-material'), 5);
      await typeQty(tester, '2');
      await tap(tester, k('material-form-extra'));
      await tap(tester, k('material-form-save'));
      expect(find.text('Say what the extra work was'), findsOneWidget);

      await tester.enterText(
        find.descendant(of: find.byKey(k('material-form-extra-text')), matching: find.byType(TextField)),
        'Compound wall repair',
      );
      await tap(tester, k('material-form-save'));
      await app.sync();

      final Map<String, dynamic> posted = app.api.materialUsePosts.values.single;
      expect(posted['boq_item_id'], isNull);
      expect(posted['extra_work'], 'Compound wall repair');
    });

    testWidgets('a count accepts zero and a chosen unit', (tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpMaterial(tester, app, start: '${Routes.materialCount}?material=5');

      await typeQty(tester, '0');
      await tester.tap(find.text('MT'));
      await settle(tester);
      await tap(tester, k('material-form-save'));
      await app.sync();

      final Map<String, dynamic> posted = app.api.stockCountPosts.values.single;
      expect(posted['physical_qty'], '0.000');
      expect(posted['unit_id'], 11);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every screen fits a small phone in $locale', (tester) async {
        final TestApp app = await materialApp();
        addTearDown(app.dispose);
        for (final String start in <String>[
          Routes.material,
          '${Routes.materialReceive}?material=5',
          '${Routes.materialUse}?material=5',
          '${Routes.materialCount}?material=5',
        ]) {
          await pumpMaterial(tester, app, start: start, locale: locale);
          expect(tester.takeException(), isNull, reason: start);
        }
      });
    }
  });
}
