// Renders the online material screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/material_control/material_loop_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/sync/photo_capture.dart';

import '../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../support/test_app.dart';
import 'material_loop_test.dart' show k, loopApp, pumpAt, settle, tap, type;

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

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

  Future<void> fresh(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
  }

  const List<String> everything = <String>[
    'material_control.reason',
    'material_control.bills',
    'material_control.challan',
    'material_control.count',
    'procurement.view',
  ];

  for (final String locale in <String>['en', 'mr', 'hi']) {
    testWidgets('material loop screens ($locale)', (tester) async {
      // A site engineer: answers flags, attaches bills, reports shorts, sees no money.
      final TestApp site = await loopApp(
        grants: everything,
        setup: (api) => api.challanDetails[1]!.addAll(<String, dynamic>{
          'supplier_warning': 'Delivered short 3 times.',
          'challan_qty': '204.000',
          'shorts': <Map<String, dynamic>>[
            <String, dynamic>{'id': 1, 'short_qty': '4.000', 'found_on': '2026-11-01', 'at_entry': true, 'status': 'POSTED', 'created_by': 'Sachin More'},
          ],
        }),
      );
      addTearDown(site.dispose);

      await pumpAt(tester, site, locale: locale);
      await capture(tester, 'loop_${locale}_1_material');

      await tap(tester, k('material-row-5'));
      await capture(tester, 'loop_${locale}_2_flag');

      await tap(tester, k('material-flag-unaccounted-reason'));
      await type(tester, k('flag-reason-text'), 'Five bags went to the gate cabin for the watchman\'s plinth.');
      await capture(tester, 'loop_${locale}_3_reason');

      await fresh(tester);
      await pumpAt(tester, site, start: Routes.materialChallanAt(1), locale: locale);
      await capture(tester, 'loop_${locale}_4_challan');

      PhotoCapture.stand = () async => '/tmp/none.jpg';
      addTearDown(() => PhotoCapture.stand = null);
      await tap(tester, k('challan-attach-bill'));
      await tap(tester, k('bill-form-camera'));
      await capture(tester, 'loop_${locale}_5_bill_form_site');

      await fresh(tester);
      await pumpAt(tester, site, start: Routes.materialChallanAt(1), locale: locale);
      await tap(tester, k('challan-report-short'));
      await type(tester, k('short-form-qty'), '3');
      await capture(tester, 'loop_${locale}_6_short_form');

      await fresh(tester);
      await pumpAt(tester, site, start: Routes.materialBills, locale: locale);
      await capture(tester, 'loop_${locale}_7_bills');

      await fresh(tester);
      await pumpAt(tester, site, start: Routes.materialWastage, locale: locale);
      await capture(tester, 'loop_${locale}_8_wastage');
      await tap(tester, k('wastage-row-11-5'));
      await capture(tester, 'loop_${locale}_9_wastage_line');

      // The accountant: bills and their figures.
      await fresh(tester);
      final TestApp office = await loopApp(
        grants: <String>['material_control.bills', 'money.view', 'procurement.view'],
        setup: (api) => api.challanDetails[1]!.addAll(<String, dynamic>{
          'bill_status': 'attached',
          'bill_attached_by': 'Asha Kulkarni',
          'bill_no': 'GT/4471',
          'bill_date': '2026-11-02',
          'bill_amount': '15200.50',
          'has_bill_file': true,
        }),
      );
      addTearDown(office.dispose);
      await pumpAt(tester, office, start: Routes.materialChallanAt(1), locale: locale);
      await capture(tester, 'loop_${locale}_10_challan_office');
      await tap(tester, k('challan-attach-bill'));
      await capture(tester, 'loop_${locale}_11_bill_form_office');

      await fresh(tester);
      await pumpAt(tester, office, start: Routes.materialBills, locale: locale);
      await tap(tester, k('bills-tab-attached'));
      await settle(tester);
      await capture(tester, 'loop_${locale}_12_bills_attached_office');
      expect(tester.takeException(), isNull);
    });
  }
}
