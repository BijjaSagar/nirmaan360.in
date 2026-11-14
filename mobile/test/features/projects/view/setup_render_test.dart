// Renders the site setup screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/projects/view/setup_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
//
// Without the define the test is skipped, so a normal run writes nothing.
import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/measurement/view/widgets/boq_picker_sheet.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

const List<(Locale, String)> _locales = <(Locale, String)>[(Locale('en'), 'en'), (Locale('mr'), 'mr')];

Future<TestApp> _site() async {
  final api = FakeApi()..grant('boq.manage');
  final app = await TestApp.create(seed: false, api: api);
  api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  api.users.add({'id': 4, 'name': 'Sachin More', 'role': 'engineer'});
  api.users.add({'id': 7, 'name': 'Prakash More', 'role': 'pm'});
  api.addWallet(id: 1, projectId: 1, balance: '12500.00', userId: 4);
  var id = 1;
  final Map<int, String> measuredById = <int, String>{};
  void line(String section, String code, String description, String unit, String qty, String rate, String measured) {
    measuredById[id] = measured;
    api.boqItems.add({
      'id': id,
      'project_id': 1,
      'section': section,
      'code': code,
      'description': description,
      'unit': unit,
      'qty': qty,
      'rate': rate,
      'sort': id++,
      'updated_at': api.stampNow(),
    });
  }

  line('Substructure', '1.1', 'Excavation in hard murum for footings', 'cum', '420.000', '310.00', '378.000');
  line('Substructure', '1.2', 'PCC 1:4:8 below footings', 'cum', '36.500', '5150.00', '36.500');
  line('Superstructure', '2.1', 'M25 concrete in slab and beams', 'cum', '620.000', '7450.00', '65.000');
  line('Superstructure', '2.2', 'Fe-500 reinforcement', 'MT', '84.000', '68500.00', '12.600');
  line('Superstructure', '2.3', 'Brick masonry, 230 mm', 'sqm', '1250.000', '520.00', '0.000');
  await app.sync();
  // The fake server has no measurement ledger, so give the lines their figures here.
  final rows = await app.db.select(app.db.boqItems).get();
  for (final row in rows) {
    final Qty measured = Qty.parse(measuredById[row.id]!);
    await (app.db.update(app.db.boqItems)..where((t) => t.id.equals(row.id))).write(
      BoqItemsCompanion(measuredQty: Value(measured), balanceQty: Value(row.qty - measured)),
    );
  }
  return app;
}

Future<TestApp> _bareSite() async {
  final api = FakeApi()..grant('boq.manage');
  final app = await TestApp.create(seed: false, api: api);
  api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  api.users.add({'id': 4, 'name': 'Sachin More', 'role': 'engineer'});
  await app.sync();
  return app;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  if (_dir.isEmpty) return;

  Future<void> shot(WidgetTester tester, String name) async {
    await tester.pump(const Duration(milliseconds: 700));
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  void phone(WidgetTester tester) {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
  }

  Future<void> clear(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
  }

  testWidgets('BOQ with lines and its form', (tester) async {
    phone(tester);
    final app = await _site();
    addTearDown(app.dispose);

    for (final (locale, name) in _locales) {
      await tester.pumpWidget(
        fieldRouterApp(
          app.services,
          locale: locale,
          initial: Routes.boq,
          routes: {Routes.boq: (_) => const BoqScreen()},
        ),
      );
      await pumpDrift(tester);
      await shot(tester, '${name}_boq');
      await clear(tester);

      await tester.pumpWidget(fieldApp(app.services, const BoqFormScreen(), locale: locale));
      await pumpDrift(tester);
      await tester.enterText(find.byType(TextField).first, name == 'en' ? 'M25 concrete in columns' : 'कॉलममध्ये M25 काँक्रीट');
      await tester.tap(find.text('cum'));
      await tester.pump();
      await shot(tester, '${name}_boq_form_details');

      await tester.drag(find.byType(ListView).first, const Offset(0, -400));
      await shot(tester, '${name}_boq_form_details_lower');

      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे'));
      await tester.pump();
      for (final key in ['1', '2', '.', '5']) {
        await tester.tap(find.text(key).last);
        await tester.pump();
      }
      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे'));
      await tester.pump();
      for (final key in ['7', '4', '5', '0']) {
        await tester.tap(find.text(key).last);
        await tester.pump();
      }
      await shot(tester, '${name}_boq_form_figures');
      await clear(tester);
    }
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('wallet set-up', (tester) async {
    phone(tester);

    for (final (locale, name) in _locales) {
      final bare = await _bareSite();
      addTearDown(bare.dispose);

      await tester.pumpWidget(fieldApp(bare.services, const PettyCashScreen(), locale: locale));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 300));
      await shot(tester, '${name}_petty_no_wallet_manage');
      await clear(tester);

      await tester.pumpWidget(fieldApp(bare.services, const WalletFormScreen(), locale: locale));
      await pumpDrift(tester);
      await shot(tester, '${name}_wallet_details');
      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे'));
      await tester.pump();
      for (final key in ['5', '0', '0', '0']) {
        await tester.tap(find.text(key).last);
        await tester.pump();
      }
      await shot(tester, '${name}_wallet_amount');
      await clear(tester);

      await tester.pumpWidget(fieldApp(bare.services, const AttendanceScreen(), locale: locale));
      await pumpDrift(tester);
      await shot(tester, '${name}_attendance_first_worker');
      await clear(tester);
    }
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('top-up, wallet actions and the BOQ prompt', (tester) async {
    phone(tester);
    final app = await _site();
    addTearDown(app.dispose);
    final bare = await _bareSite();
    addTearDown(bare.dispose);

    for (final (locale, name) in _locales) {
      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen(), locale: locale));
      await pumpDrift(tester);
      await shot(tester, '${name}_petty_add_money');
      await clear(tester);

      await tester.pumpWidget(fieldApp(app.services, const TopUpScreen(), locale: locale));
      await pumpDrift(tester);
      for (final key in ['2', '5', '0', '0']) {
        await tester.tap(find.text(key).last);
        await tester.pump();
      }
      await shot(tester, '${name}_top_up_amount');
      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे'));
      await pumpDrift(tester);
      await shot(tester, '${name}_top_up_details');
      await clear(tester);

      await tester.pumpWidget(
        fieldRouterApp(
          bare.services,
          locale: locale,
          initial: '/start',
          routes: {
            '/start': (_) => Scaffold(
              body: Center(
                child: Builder(
                  builder: (context) => GloveButton(
                    label: 'Open',
                    onPressed: () => showBoqPicker(context, services: bare.services, projectId: 1),
                  ),
                ),
              ),
            ),
          },
        ),
      );
      await pumpDrift(tester);
      await tester.tap(find.text('Open'));
      await pumpDrift(tester);
      await shot(tester, '${name}_boq_prompt');
      await clear(tester);
    }
    await tester.pump(const Duration(seconds: 1));
  });
}
