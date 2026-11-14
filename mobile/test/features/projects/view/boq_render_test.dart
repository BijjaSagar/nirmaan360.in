// Renders the BOQ screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/projects/view/boq_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_missing_prompt.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

/// A BOQ with real-looking lines in two sections. One line has more measured
/// on the phone than the BOQ allows, one is half done, one is untouched.
Future<TestApp> _seeded() async {
  final api = FakeApi()..grant('boq.manage');
  final app = await TestApp.create(seed: false, api: api);
  api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  await api.addBoqItem(
    id: 1,
    projectId: 1,
    code: '2.1',
    qty: '620.000',
    rate: '9850.00',
    unit: 'cum',
  );
  await api.addBoqItem(
    id: 2,
    projectId: 1,
    code: '2.2',
    qty: '48.000',
    rate: '72000.00',
    unit: 'MT',
  );
  await api.addBoqItem(
    id: 3,
    projectId: 1,
    code: '2.3',
    qty: '1840.500',
    rate: '425.00',
    unit: 'sqm',
  );
  await api.addBoqItem(
    id: 4,
    projectId: 1,
    code: 'FIN-4.1',
    qty: '3200.000',
    rate: '118.50',
    unit: 'sqm',
  );
  await api.addBoqItem(
    id: 5,
    projectId: 1,
    code: '4.2',
    qty: '12.000',
    rate: '15500.00',
    unit: 'nos',
  );
  const rows = <(String, String)>[
    (
      'M25 concrete in slab, columns and beams including shuttering',
      'Superstructure',
    ),
    ('Reinforcement steel Fe500D, cut, bent and tied', 'Superstructure'),
    (
      'Brick masonry in cement mortar 1:6, 230 mm thick walls',
      'Superstructure',
    ),
    ('Vitrified floor tiles 600 x 600 mm laid on cement bed', 'Finishing'),
    ('Aluminium sliding windows with 5 mm clear glass', 'Finishing'),
  ];
  for (var i = 0; i < rows.length; i++) {
    api.boqItems[i]['description'] = rows[i].$1;
    api.boqItems[i]['section'] = rows[i].$2;
  }
  await app.sync();

  Future<void> measure(int item, String nos, String length) =>
      app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: item,
          measuredOn: '2026-11-09',
          nos: Qty.parse(nos),
          length: Qty.parse(length),
        ),
      );
  await measure(1, '1.000', '310.000');
  await measure(5, '1.000', '14.000');
  return app;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  if (_dir.isEmpty) return;

  Future<void> capture(WidgetTester tester, String name) async {
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  void phone(WidgetTester tester) {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
  }

  Future<void> settle(WidgetTester tester) async {
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 800));
  }

  Future<void> tapKeys(WidgetTester tester, String keys) async {
    for (final String key in keys.split('')) {
      await tester.tap(find.text(key).last);
      await tester.pump();
    }
  }

  Future<void> fresh(WidgetTester tester, Widget app) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    await tester.pumpWidget(app);
    await settle(tester);
  }

  testWidgets('boq screens', (tester) async {
    phone(tester);
    final app = await _seeded();
    addTearDown(app.dispose);
    final empty = await TestApp.create(
      seed: false,
      api: FakeApi()..grant('boq.manage'),
    );
    empty.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
    await empty.sync();
    addTearDown(empty.dispose);

    for (final (name, locale) in <(String, Locale)>[
      ('en', const Locale('en')),
      ('mr', const Locale('mr')),
    ]) {
      await fresh(
        tester,
        fieldRouterApp(
          app.services,
          locale: locale,
          initial: Routes.boq,
          routes: {
            Routes.boq: (_) => const BoqScreen(),
            Routes.boqForm: (_) => const BoqFormScreen(),
          },
        ),
      );
      await capture(tester, '${name}_boq_list');

      await tester.drag(find.byType(CustomScrollView), const Offset(0, -700));
      await settle(tester);
      await capture(tester, '${name}_boq_list_foot');

      await fresh(
        tester,
        fieldRouterApp(
          empty.services,
          locale: locale,
          initial: Routes.boq,
          routes: {
            Routes.boq: (_) => const BoqScreen(),
            Routes.boqForm: (_) => const BoqFormScreen(),
          },
        ),
      );
      await capture(tester, '${name}_boq_empty');

      await fresh(
        tester,
        fieldApp(app.services, const BoqFormScreen(), locale: locale),
      );
      await capture(tester, '${name}_boq_form_details');

      await tester.enterText(
        find.byType(TextField).first,
        'M25 concrete in slab, columns and beams including shuttering',
      );
      await tester.tap(find.text('cum'));
      await tester.pump();
      await capture(tester, '${name}_boq_form_details_filled');

      // The next step's button label differs by language, so find it by type.
      await tester.tap(find.widgetWithIcon(GloveButton, Icons.arrow_forward));
      await tester.pump();
      await tapKeys(tester, '10');
      await tester.tap(find.byType(DimensionTile).last);
      await tester.pump();
      await tapKeys(tester, '9850');
      await settle(tester);
      await capture(tester, '${name}_boq_form_figures');

      // The prompt a picker shows inside a sheet when the project has no lines.
      await fresh(
        tester,
        fieldApp(
          empty.services,
          Scaffold(
            body: Builder(
              builder: (context) => Center(
                child: SizedBox(
                  height: N360Size.tap,
                  child: GloveButton(
                    label: 'Open',
                    onPressed: () => showLedgerSheet<void>(
                      context,
                      builder: (_) => SizedBox(
                        height: 360,
                        child: BoqMissingPrompt(services: empty.services),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
          locale: locale,
        ),
      );
      await tester.tap(find.byType(GloveButton));
      await settle(tester);
      await capture(tester, '${name}_boq_missing_sheet');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
    await tester.pump(const Duration(seconds: 1));
  });
}
