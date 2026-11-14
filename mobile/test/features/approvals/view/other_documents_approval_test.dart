// Vendor bills, indents and RFIs in the approvals screen, shaped as
// ApprovalPresenter sends them.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';

import '../../measurement/view/book_test_kit.dart';

const Key _approve = ValueKey<String>('approval-approve');
const Key _sendBack = ValueKey<String>('approval-send-back');
const Key _confirmBack = ValueKey<String>('approval-send-back-confirm');
const Key _reject = ValueKey<String>('approval-reject');
const Key _confirmReject = ValueKey<String>('approval-reject-confirm');

Map<String, dynamic> _row(int id, String docType, String title, List<String> levels, Map<String, dynamic> document) =>
    <String, dynamic>{
      'id': id,
      'doc_type': docType,
      'status': 'IN_REVIEW',
      'title': title,
      'project': <String, dynamic>{'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
      'submitted_by': <String, dynamic>{'id': 6, 'name': 'Anita Kulkarni'},
      'submitted_at': '2026-11-09T07:00:00+05:30',
      'current_level': 1,
      'levels': levels,
      'current_role': 'pm',
      'current_role_label': 'Project Manager',
      'can_act': true,
      'document': document,
      'steps': <Map<String, dynamic>>[],
    };

Map<String, dynamic> vendorBill(int id, {bool money = true}) => _row(
  id,
  'vendor_bill',
  'Vendor bill SSC-101',
  <String>['pm', 'accounts'],
  <String, dynamic>{
    'id': 12,
    'number': 'SSC-101',
    'party_name': 'Sai Steel & Cement',
    'bill_date': '2026-11-05',
    'due_date': '2026-12-05',
    'cost_head': 'Materials',
    'supply_type': 'intra',
    'gst_pct': '18.00',
    'tds_section': '194C',
    'tds_pct': '1.00',
    'description': money ? 'Cement 200 bags' : null,
    'remarks': null,
    'taxable_amount': money ? '100000.00' : null,
    'gst_amount': money ? '18000.00' : null,
    'total_amount': money ? '118000.00' : null,
    'tds_amount': money ? '1000.00' : null,
    'net_payable': money ? '117000.00' : null,
  },
);

Map<String, dynamic> indent(int id) => _row(
  id,
  'indent',
  'Indent IND-0007',
  <String>['pm'],
  <String, dynamic>{
    'id': 7,
    'number': 'IND-0007',
    'store': 'Main store',
    'required_by': '2026-11-20',
    'remarks': 'For the level 6 slab',
    'created_by_name': 'Sachin More',
    'items': <Map<String, dynamic>>[
      <String, dynamic>{
        'material': 'OPC 53 cement',
        'material_code': 'CEM-53',
        'qty': '100.000',
        'unit': 'bag',
        'boq_item': <String, dynamic>{'code': '2.1', 'description': 'RCC M25 slab at level 6'},
        'remarks': 'OPC only',
      },
      <String, dynamic>{
        'material': 'TMT bar 12 mm',
        'material_code': 'TMT-12',
        'qty': '2.500',
        'unit': 'MT',
        'boq_item': null,
        'remarks': null,
      },
    ],
  },
);

Map<String, dynamic> rfi(int id) => _row(
  id,
  'rfi',
  'RFI-KT-B-003 · Beam depth at gridline B',
  <String>['pm'],
  <String, dynamic>{
    'id': 3,
    'number': 'RFI-KT-B-003',
    'subject': 'Beam depth at gridline B',
    'question': 'Drawing S-12 shows 450 mm, S-14 shows 500 mm. Which governs?',
    'drawing_ref': 'S-12 / S-14',
    'location': 'Level 6, grid B',
    'addressed_to': 'Structural consultant',
    'priority': 'high',
    'required_by': '2026-11-20',
    'raised_by_name': 'Sachin More',
  },
);

Future<TestApp> appWith(FakeApi api, Map<String, dynamic> row) async {
  api.approvalInbox.add(row);
  return bookApp(api: api);
}

Future<void> settle(WidgetTester tester, {int ms = 500}) async {
  await pumpDrift(tester, 10);
  await tester.pump(Duration(milliseconds: ms));
  await pumpDrift(tester, 10);
}

void main() {
  testWidgets('a vendor bill shows how its net payable is reached', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, vendorBill(50));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(50));
    await settle(tester);

    expect(find.text('Vendor bill'), findsOneWidget);
    expect(find.textContaining('SSC-101 · Sai Steel & Cement'), findsOneWidget);
    expect(find.textContaining('₹1,17,000.00'), findsWidgets);
    expect(find.text('Materials'), findsOneWidget);
    expect(find.text('Cement 200 bags'), findsOneWidget);
    expect(find.text('₹1,00,000.00'), findsOneWidget);
    expect(find.text('GST 18%'), findsOneWidget);
    expect(find.text('₹18,000.00'), findsOneWidget);
    expect(find.text('TDS · 1% · 194C'), findsOneWidget);
    expect(find.text('−₹1,000.00'), findsOneWidget);
    expect(find.byKey(_approve), findsOneWidget);
    expect(find.byKey(_reject), findsOneWidget);
  });

  testWidgets('a vendor bill sent without its figures is left to the website', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, vendorBill(50, money: false));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(50));
    await settle(tester);

    expect(find.textContaining('review and approve it on the website'), findsOneWidget);
    expect(find.byKey(_approve), findsNothing);
    expect(find.byKey(_reject), findsNothing);
  });

  testWidgets('an indent lists every material asked for, and is approved here', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, indent(51));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);

    expect(find.textContaining('IND-0007 · Main store'), findsOneWidget);
    await tester.tap(find.textContaining('IND-0007 · Main store'));
    await settle(tester);

    expect(find.text('Indent'), findsWidgets);
    expect(find.text('Main store'), findsOneWidget);
    expect(find.text('For the level 6 slab'), findsOneWidget);
    expect(find.text('OPC 53 cement'), findsOneWidget);
    expect(find.text('100.000 bag'), findsOneWidget);
    expect(find.text('For BOQ line 2.1 · OPC only'), findsOneWidget);
    expect(find.text('TMT bar 12 mm'), findsOneWidget);
    expect(find.text('2.500 MT'), findsOneWidget);

    await tester.tap(find.byKey(_approve));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);
    expect(api.approvalActLog.single['action'], 'approve');
    expect(api.approvalActLog.single['id'], 51);
  });

  testWidgets('an indent goes back with a reason that fits material', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, indent(51));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(51));
    await settle(tester);

    await tester.tap(find.byKey(_sendBack));
    await settle(tester);
    await tester.tap(find.text('Wrong material or unit'));
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmBack));
    await settle(tester, ms: 500);

    expect(api.approvalActLog.single['action'], 'return');
    expect(api.approvalActLog.single['remark'], 'Wrong material or unit');
    await tester.pump(const Duration(seconds: 4));
  });

  testWidgets('an RFI shows its question, and can be rejected with a remark', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, rfi(52));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(52));
    await settle(tester);

    expect(find.text('RFI'), findsWidgets);
    expect(find.textContaining('RFI-KT-B-003 · Beam depth at gridline B'), findsOneWidget);
    expect(find.text('Drawing S-12 shows 450 mm, S-14 shows 500 mm. Which governs?'), findsOneWidget);
    expect(find.text('S-12 / S-14'), findsOneWidget);
    expect(find.text('Structural consultant'), findsOneWidget);
    expect(find.text('High'), findsOneWidget);

    await tester.tap(find.byKey(_reject));
    await settle(tester);
    expect(tester.widget<GloveButton>(find.byKey(_confirmReject)).onPressed, isNull);
    await tester.tap(find.text('Already answered, or not needed'));
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmReject));
    await settle(tester, ms: 500);

    expect(api.approvalActLog.single['action'], 'reject');
    expect(api.approvalActLog.single['remark'], 'Already answered, or not needed');
    await tester.pump(const Duration(seconds: 4));
  });

  for (final (String locale, String store, String question) in <(String, String, String)>[
    ('mr', 'भांडार', 'प्रश्न'),
    ('hi', 'स्टोर', 'प्रश्न'),
  ]) {
    testWidgets('an indent and an RFI read in $locale', (tester) async {
      final FakeApi api = FakeApi();
      api.approvalInbox.add(rfi(52));
      final TestApp app = await appWith(api, indent(51));
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.approvalDetail(51), locale: locale);
      await settle(tester);
      expect(find.text(store), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await pumpBook(tester, app, start: Routes.approvalDetail(52), locale: locale);
      await settle(tester);
      expect(find.text(question), findsOneWidget);
    });
  }
}
