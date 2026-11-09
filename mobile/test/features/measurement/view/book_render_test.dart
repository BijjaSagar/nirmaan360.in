// Renders the measurement and approval screens to PNG with the real fonts, for
// a person to judge.
//
// flutter test test/features/measurement/view/book_render_test.dart \
//   --dart-define=N360_BOOK_DIR=/absolute/output/dir
//
// Without the define nothing is written and the test only checks that every
// screen lays out cleanly in both languages and at a large text size.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/measurement/view/widgets/book_kit.dart';
import 'package:nirmaan360/features/measurement/view/widgets/form_parts.dart';

import '../../../core/design/test_helpers.dart';
import 'book_test_kit.dart';

const String _dir = String.fromEnvironment('N360_BOOK_DIR');

Map<String, dynamic> _measurementApproval(int id, {bool deviation = false, String qty = '50.000'}) => {
  'id': id,
  'doc_type': 'measurement',
  'status': 'IN_REVIEW',
  'title': 'Measurement · 2.1 · $qty cum',
  'project': {'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': {'id': 4, 'name': 'Sachin More'},
  'submitted_at': '2026-11-09T08:12:00+05:30',
  'current_level': 1,
  'levels': ['qs', 'pm'],
  'current_role': 'qs',
  'current_role_label': 'Estimator / QS',
  'can_act': true,
  'document': {
    'id': 3,
    'boq_item': {'code': '2.1', 'description': 'RCC M25 slab at level 6', 'unit': 'cum'},
    'measured_on': '2026-11-09',
    'location': 'Footing F12, grid C',
    'description': 'Footing concrete',
    'nos': '2.000',
    'length': '10.000',
    'breadth': '5.000',
    'depth': '0.500',
    'is_deduction': false,
    'qty': qty,
    'deviation': deviation,
    'remarks': null,
    'balance_qty': '50.000',
  },
  'steps': [
    {
      'level': 0,
      'role': 'engineer',
      'action': 'submit',
      'remark': null,
      'actor': 'Sachin More',
      'acted_at': '2026-11-09T08:12:00+05:30',
    },
  ],
};

Map<String, dynamic> _billApproval(int id) => {
  'id': id,
  'doc_type': 'ra_bill',
  'status': 'IN_REVIEW',
  'title': 'RA bill 7 · Patil Constructions',
  'project': {'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': {'id': 6, 'name': 'Anita Kulkarni'},
  'submitted_at': '2026-11-08T17:30:00+05:30',
  'current_level': 2,
  'levels': ['qs', 'pm', 'accounts'],
  'current_role': 'pm',
  'current_role_label': 'Project Manager',
  'can_act': true,
  'document': {
    'id': 9,
    'number': 'RA-7',
    'type': 'running',
    'period_from': '2026-10-01',
    'period_to': '2026-10-31',
    'party_name': 'Patil Constructions',
    'gross_amount': '1245000.00',
    'deduction_total': '62250.00',
    'gst_amount': '212310.00',
    'net_payable': '1395060.00',
  },
  'steps': [
    {
      'level': 0,
      'role': 'accounts',
      'action': 'submit',
      'remark': null,
      'actor': 'Anita Kulkarni',
      'acted_at': '2026-11-08T17:30:00+05:30',
    },
    {
      'level': 1,
      'role': 'qs',
      'action': 'approve',
      'remark': 'Quantities checked against MB.',
      'actor': 'Rahul Deshpande',
      'acted_at': '2026-11-09T09:02:00+05:30',
    },
  ],
};

Map<String, dynamic> _dprApproval(int id) => {
  'id': id,
  'doc_type': 'dpr',
  'status': 'IN_REVIEW',
  'title': 'Daily report · 9 Nov',
  'project': {'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': {'id': 4, 'name': 'Sachin More'},
  'submitted_at': '2026-11-09T09:50:00+05:30',
  'current_level': 1,
  'levels': ['pm'],
  'current_role': 'pm',
  'current_role_label': 'Project Manager',
  'can_act': true,
  'document': {
    'id': 4,
    'report_date': '2026-11-09',
    'weather': 'Clear',
    'summary': 'Slab casting at level 6.',
    'hindrances': null,
    'manpower_total': 24,
    'manpower': [
      {'trade': 'Mason', 'count': 12},
      {'trade': 'Helper', 'count': 12},
    ],
    'work_items': [],
    'photos': [],
    'created_by_name': 'Sachin More',
  },
  'steps': [],
};

Future<TestApp> _seeded(FakeConnectivity connectivity) async {
  final TestApp app = await bookApp(connectivity: connectivity);
  app.api
    ..addBoqItem(id: 2, projectId: 1, code: '3.4', qty: '420.000', unit: 'sqm')
    ..addBoqItem(id: 3, projectId: 1, code: '4.2', qty: '980.500', unit: 'rmt');
  app.api.boqItems[1]['description'] = 'Brick masonry in CM 1:6, 230 mm thick';
  app.api.boqItems[2]['description'] = 'Plaster 12 mm, one coat';
  await app.sync();
  return app;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  Future<void> shot(WidgetTester tester, String name) async {
    if (_dir.isEmpty) return;
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  for (final String locale in <String>['en', 'mr', 'hi']) {
    final _Words words = _Words.of(locale);
    testWidgets('entry page $locale', (tester) async {
      final FakeConnectivity connectivity = FakeConnectivity();
      final TestApp app = await _seeded(connectivity);
      addTearDown(app.dispose);

      await pumpBook(tester, app, start: Routes.measurementForm, locale: locale, pixelRatio: 2.5);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_0_blank');

      // Open the picker from the header.
      await tester.tap(find.text(words.choose).first);
      await tester.pump();
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_1_picker');

      await tester.tap(find.textContaining('2.1 · ').last);
      await tester.pump();
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 500));

      await typeKeys(tester, '2');
      await pressNext(tester, label: words.next);
      await typeKeys(tester, '10');
      await pressNext(tester, label: words.next);
      await typeKeys(tester, '5');
      await pressNext(tester, label: words.next);
      await typeKeys(tester, '0.5');
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_2_typed');

      // Backing out with figures typed.
      await tester.tap(find.byType(LedgerBackKey));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_leave_sheet');
      await tester.tap(find.byKey(const ValueKey<String>('measurement-leave-keep')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      // The details sheet.
      await tester.tap(find.byType(DetailsKey));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_details_sheet');
      await tester.tap(find.text(words.done));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      // A deduction, in laterite.
      await tester.tap(find.text(words.deduct));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      await shot(tester, '${locale}_form_3_deduct');
      await tester.tap(find.text(words.add));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      // Past BOQ plus tolerance.
      await tester.tap(find.byKey(const ValueKey<String>('tile-nos')));
      await tester.pump();
      await typeKeys(tester, '5');
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_4_deviation');

      // Saved: the dimensions clear and the tally counts the row.
      await tester.tap(find.byKey(const ValueKey<String>('measurement-save')));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_5_saved');

      connectivity.setOnline(false);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_6_offline');

      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('empty states $locale', (tester) async {
      final TestApp app = await bookApp();
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.measurements, locale: locale, pixelRatio: 2.5);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_book_empty');

      await pumpBook(tester, app, start: Routes.approvals, locale: locale, pixelRatio: 2.5);
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_inbox_empty');
    });

    testWidgets('entry page small phone and big text $locale', (tester) async {
      final TestApp app = await _seeded(FakeConnectivity());
      addTearDown(app.dispose);
      await pumpBook(
        tester,
        app,
        start: Routes.measurementForm,
        locale: locale,
        pixelRatio: 2.5,
        size: const Size(360, 620),
      );
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_small');

      await pumpBook(tester, app, start: Routes.measurementForm, locale: locale, pixelRatio: 2.5, textScale: 1.3);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_scaled');
    });

    testWidgets('book and detail $locale', (tester) async {
      final TestApp app = await _seeded(FakeConnectivity(online: false));
      addTearDown(app.dispose);

      await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 1,
          measuredOn: '2026-11-09',
          location: 'Footing F12, grid C',
          nos: Qty.parse('2'),
          length: Qty.parse('10'),
          breadth: Qty.parse('5'),
          depth: Qty.parse('0.5'),
        ),
      );
      await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 1,
          measuredOn: '2026-11-09',
          location: 'Opening at F12',
          nos: Qty.parse('1'),
          length: Qty.parse('1.2'),
          breadth: Qty.parse('0.9'),
          depth: Qty.parse('0.5'),
          isDeduction: true,
        ),
      );
      final String approved = await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 2,
          measuredOn: '2026-11-08',
          location: 'Tower B, level 5',
          nos: Qty.parse('4'),
          length: Qty.parse('6.2'),
          breadth: Qty.parse('3.1'),
        ),
      );
      final String returned = await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 3,
          measuredOn: '2026-11-07',
          location: 'Stair core',
          nos: Qty.parse('3'),
          length: Qty.parse('12.5'),
        ),
      );
      app.connectivity.setOnline(true);
      await app.sync();
      app.api.setServerStatus(app.api.measurementByUuid(approved), 'APPROVED');
      app.api.setServerStatus(
        app.api.measurementByUuid(returned),
        'RETURNED',
        returnRemark: 'Length of stair flight looks short. Please re-measure.',
      );
      await app.sync();
      app.connectivity.setOnline(false);

      await pumpBook(tester, app, start: Routes.measurements, locale: locale, pixelRatio: 2.5);
      await tester.pump(const Duration(milliseconds: 800));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_book_list');

      app.connectivity.setOnline(true);
      await tester.pump();
      await pumpDrift(tester);

      await pumpBook(tester, app, start: Routes.measurementDetail(approved), locale: locale, pixelRatio: 2.5);
      await tester.pump(const Duration(milliseconds: 800));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_detail_approved');

      await pumpBook(tester, app, start: Routes.measurementDetail(returned), locale: locale, pixelRatio: 2.5);
      await tester.pump(const Duration(milliseconds: 800));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_detail_returned');

      await pumpBook(tester, app, start: '${Routes.measurementForm}?uuid=$returned', locale: locale, pixelRatio: 2.5);
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 800));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_form_returned');
    });

    testWidgets('approvals $locale', (tester) async {
      final FakeConnectivity connectivity = FakeConnectivity();
      final TestApp app = await bookApp(connectivity: connectivity);
      addTearDown(app.dispose);
      app.api.approvalInbox.addAll(<Map<String, dynamic>>[
        _measurementApproval(7, deviation: true, qty: '112.500'),
        _billApproval(8),
        _dprApproval(9),
      ]);
      await app.sync();

      await pumpBook(tester, app, start: Routes.approvals, locale: locale, pixelRatio: 2.5);
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 800));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_inbox');

      for (final int id in <int>[7, 8, 9]) {
        await pumpBook(tester, app, start: Routes.approvalDetail(id), locale: locale, pixelRatio: 2.5);
        await pumpDrift(tester, 10);
        await tester.pump(const Duration(milliseconds: 500));
        expect(tester.takeException(), isNull);
        await shot(tester, '${locale}_approval_$id');

        await pumpBook(
          tester,
          app,
          start: Routes.approvalDetail(id),
          locale: locale,
          pixelRatio: 2,
          size: const Size(360, 1500),
        );
        await pumpDrift(tester, 10);
        await tester.pump(const Duration(milliseconds: 500));
        expect(tester.takeException(), isNull);
        await shot(tester, '${locale}_approval_${id}_full');
      }

      // Send back sheet.
      await pumpBook(tester, app, start: Routes.approvalDetail(7), locale: locale, pixelRatio: 2.5);
      await pumpDrift(tester, 10);
      await tester.tap(find.byKey(const ValueKey<String>('approval-send-back')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      await pumpDrift(tester);
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_send_back_sheet');
      await tester.pumpWidget(const SizedBox.shrink());

      // Offline detail.
      connectivity.setOnline(false);
      await tester.pump();
      await pumpBook(tester, app, start: Routes.approvalDetail(8), locale: locale, pixelRatio: 2.5);
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
      await shot(tester, '${locale}_approval_offline');
      connectivity.setOnline(true);
      await tester.pump();

      // Mid-thud.
      await pumpBook(
        tester,
        app,
        start: Routes.approvalDetail(7),
        locale: locale,
        pixelRatio: 2.5,
        disableAnimations: false,
      );
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 500));
      await tester.tap(find.byKey(const ValueKey<String>('approval-approve')));
      await tester.pump();
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 165));
      await shot(tester, '${locale}_thud_mid_impact');
      await tester.pump(const Duration(milliseconds: 235));
      await shot(tester, '${locale}_thud_settled');
      await tester.pump(const Duration(seconds: 2));
      await pumpDrift(tester, 10);
      expect(tester.takeException(), isNull);
    });
  }
}

class _Words {
  const _Words(this.choose, this.next, this.add, this.deduct, this.done);

  factory _Words.of(String locale) => switch (locale) {
    'mr' => const _Words('BOQ ओळ निवडा', 'पुढे', 'जोडा', 'वजा करा', 'झाले'),
    'hi' => const _Words('BOQ लाइन चुनें', 'आगे', 'जोड़ें', 'घटाएँ', 'हो गया'),
    _ => const _Words('Choose BOQ line', 'Next', 'Add', 'Deduct', 'Done'),
  };

  final String choose;
  final String next;
  final String add;
  final String deduct;
  final String done;
}
