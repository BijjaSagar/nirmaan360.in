import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/approvals/data/approval_repository.dart';
import 'package:nirmaan360/features/approvals/view/approval_detail_screen.dart';

import '../../measurement/view/book_test_kit.dart';

const Key _approve = ValueKey<String>('approval-approve');
const Key _sendBack = ValueKey<String>('approval-send-back');
const Key _confirmBack = ValueKey<String>('approval-send-back-confirm');
const Key _reject = ValueKey<String>('approval-reject');
const Key _confirmReject = ValueKey<String>('approval-reject-confirm');
const Key _overrideReason = ValueKey<String>('approval-override-reason');
const Key _confirmOverride = ValueKey<String>('approval-override-confirm');

/// A payment request as GET /approvals sends it to a project manager, shaped
/// by ApprovalPresenter::contractorPayment on the server.
Map<String, dynamic> payment(
  int id, {
  bool money = true,
  Map<String, dynamic> ownerOverride = const <String, dynamic>{'needed': false, 'allowed': false, 'message': null},
  bool hasBill = true,
}) => <String, dynamic>{
  'id': id,
  'doc_type': 'contractor',
  'status': 'IN_REVIEW',
  'title': 'Contractor payment CP-12',
  'project': <String, dynamic>{'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': <String, dynamic>{'id': 4, 'name': 'Sachin More'},
  'submitted_at': '2026-11-09T07:00:00+05:30',
  'current_level': 1,
  'levels': <String>['pm'],
  'current_role': 'pm',
  'current_role_label': 'Project Manager',
  'can_act': true,
  'document': <String, dynamic>{
    'id': 31,
    'number': 'CP-12',
    'status': 'IN_REVIEW',
    'work_order': <String, dynamic>{'id': 5, 'number': 'WO-4', 'title': 'Flooring'},
    'contractor': 'Ganesh Builders',
    'against': <String, dynamic>{
      'type': 'boq_item',
      'code': '2.1',
      'description': 'RCC M25 slab at level 6',
      'unit': 'cum',
      'work_pct': '70.00',
    },
    'paid_on': '2026-11-08',
    'entered_by': 'Sachin More',
    'work_pct': '70.00',
    'balance_work_pct': '30.00',
    'amount': money ? '20000.00' : null,
    'is_advance': money ? false : null,
    'mode': money ? 'upi' : null,
    'paid_to': money ? 'Ganesh' : null,
    'notes': money ? 'Second running bill' : null,
    'has_bill': money ? hasBill : null,
    'paid_pct': money ? '60.00' : null,
    'impact': money
        ? <String, dynamic>{
            'now': <String, dynamic>{
              'contract_value': '100000.00',
              'paid': '60000.00',
              'advance': '0.00',
              'paid_pct': '60.00',
              'gap_pct': '-10.00',
              'ratio_status': 'green',
              'over_limit': false,
              'limit_pct': '5.00',
              'label': 'Paid 60%, Work 70%',
              'balance_work_value': '30000.00',
              'balance_money': '40000.00',
              'short_by': '0.00',
              'work_pct': '70.00',
            },
            'after': <String, dynamic>{
              'contract_value': '100000.00',
              'paid': '80000.00',
              'advance': null,
              'paid_pct': '80.00',
              'gap_pct': '10.00',
              'ratio_status': 'red',
              'over_limit': true,
              'limit_pct': '5.00',
              'label': 'Paid 80%, Work 70%',
              'balance_work_value': '30000.00',
              'balance_money': '20000.00',
              'short_by': '10000.00',
              'work_pct': '70.00',
            },
            'item': <String, dynamic>{
              'contract_value': '100000.00',
              'paid': '60000.00',
              'advance': null,
              'paid_pct': '60.00',
              'gap_pct': '-10.00',
              'ratio_status': 'green',
              'over_limit': false,
              'limit_pct': '5.00',
              'label': 'Paid 60%, Work 70%',
              'balance_work_value': '30000.00',
              'balance_money': '40000.00',
              'short_by': '0.00',
              'work_pct': '70.00',
            },
          }
        : null,
    'owner_override': ownerOverride,
  },
  'steps': <Map<String, dynamic>>[
    <String, dynamic>{
      'level': 0,
      'role': 'engineer',
      'action': 'submit',
      'remark': null,
      'actor': 'Sachin More',
      'acted_at': '2026-11-09T07:00:00+05:30',
    },
  ],
};

const String _blockedMessage =
    'Approving this payment would make it 80% paid against 70% work done, 10 points ahead, beyond this '
    "project's limit of 5 points. Only the owner can approve it, with a written reason.";

Future<TestApp> paymentApp(FakeApi api, Map<String, dynamic> row) async {
  api.approvalInbox.add(row);
  return bookApp(api: api);
}

Future<void> settle(WidgetTester tester, {int ms = 500}) async {
  await pumpDrift(tester, 10);
  await tester.pump(Duration(milliseconds: ms));
  await pumpDrift(tester, 10);
}

Future<void> scrollTo(WidgetTester tester, Finder finder) async {
  await tester.scrollUntilVisible(finder, 200, scrollable: find.byType(Scrollable).first);
  await settle(tester, ms: 100);
}

void main() {
  testWidgets('the inbox names the payment, its contractor and its amount', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);

    expect(find.textContaining('CP-12 · Ganesh Builders'), findsOneWidget);
    expect(find.textContaining('₹20,000.00'), findsWidgets);
    expect(find.textContaining('review and approve it on the website'), findsNothing);
  });

  testWidgets('the payment shows work against paid, and what approving would do', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('Contractor payment'), findsOneWidget);
    expect(find.text('Work against paid'), findsOneWidget);
    expect(find.text('70%'), findsOneWidget);
    expect(find.text('60%'), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('approval-paid-after')), findsOneWidget);
    expect(find.text('80%'), findsOneWidget);
    expect(find.text('Over the limit'), findsOneWidget);
    expect(
      find.text(
        "Approving puts payment 10 points ahead of the work done. That is beyond this project's limit of 5 points.",
      ),
      findsOneWidget,
    );
    expect(find.text('₹1,00,000.00'), findsOneWidget);
    expect(find.text('₹80,000.00'), findsOneWidget);
    expect(find.text('Paid 60%, work 70%'), findsOneWidget);

    await scrollTo(tester, find.text('Second running bill'));
    expect(find.text('WO-4 · Flooring'), findsOneWidget);
    expect(find.text('Ganesh Builders'), findsOneWidget);
    expect(find.text('2.1'), findsWidgets);
    expect(find.text('UPI'), findsOneWidget);
    expect(find.text('For work done'), findsOneWidget);
    await scrollTo(tester, find.byKey(const ValueKey<String>('approval-view-bill')));
    expect(find.byKey(const ValueKey<String>('approval-view-bill')), findsOneWidget);

    // Sending back and rejecting stay one tap away; approving is the main button.
    expect(find.byKey(_approve), findsOneWidget);
    expect(find.byKey(_sendBack), findsOneWidget);
    expect(find.byKey(_reject), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_approve)).label, 'Approve');
  });

  testWidgets('a payment that keeps within the work done says so', (tester) async {
    final FakeApi api = FakeApi();
    final Map<String, dynamic> row = payment(40);
    final Map<String, dynamic> doc = row['document'] as Map<String, dynamic>;
    final Map<String, dynamic> impact = doc['impact'] as Map<String, dynamic>;
    impact['after'] = <String, dynamic>{
      ...(impact['after'] as Map<String, dynamic>),
      'paid_pct': '65.00',
      'gap_pct': '-5.00',
      'ratio_status': 'green',
      'over_limit': false,
    };
    final TestApp app = await paymentApp(api, row);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('Approving keeps payment within the work done.'), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('approval-payment-ahead')), findsNothing);
  });

  testWidgets('approve sends a plain approve', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);

    expect(api.approvalActLog, <Map<String, Object?>>[
      <String, Object?>{'id': 40, 'action': 'approve', 'remark': null, 'override_reason': null},
    ]);
    expect(find.byType(ApprovalDetailScreen), findsNothing);
  });

  testWidgets('reject needs a remark, warns that it is final, and sends the one chosen', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);
    await tester.tap(find.textContaining('CP-12 · Ganesh Builders'));
    await settle(tester);

    await tester.tap(find.byKey(_reject));
    await settle(tester);

    expect(find.text('Why reject it?'), findsOneWidget);
    expect(find.textContaining('closed for good'), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_confirmReject)).onPressed, isNull);

    await tester.tap(find.text('Already paid, or asked for twice'));
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmReject));
    await settle(tester, ms: 500);

    expect(api.approvalActLog.single['action'], 'reject');
    expect(api.approvalActLog.single['remark'], 'Already paid, or asked for twice');
    expect(find.text('Rejected with your remark.'), findsOneWidget);
    await settle(tester, ms: 400);
    expect(find.byType(ApprovalDetailScreen), findsNothing);
    expect(find.textContaining('CP-12 · Ganesh Builders'), findsNothing);
    await tester.pump(const Duration(seconds: 4));
  });

  testWidgets('send back offers reasons that fit a payment', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    await tester.tap(find.byKey(_sendBack));
    await settle(tester);

    expect(find.text('Bill or receipt is missing'), findsOneWidget);
    expect(find.text('Check the dimensions'), findsNothing);
    await tester.tap(find.text('Bill or receipt is missing'));
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmBack));
    await settle(tester, ms: 500);

    expect(api.approvalActLog.single['action'], 'return');
    expect(api.approvalActLog.single['remark'], 'Bill or receipt is missing');
    await tester.pump(const Duration(seconds: 4));
  });

  testWidgets('a payment sent without its figures is left to the website', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(api, payment(40, money: false));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.textContaining('review and approve it on the website'), findsOneWidget);
    expect(find.byKey(_approve), findsNothing);
    expect(find.byKey(_reject), findsNothing);
  });

  testWidgets('past the owner-override rule, a manager is not offered approve', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(
      api,
      payment(40, ownerOverride: <String, dynamic>{'needed': true, 'allowed': false, 'message': _blockedMessage}),
    );
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('Only the owner can approve this'), findsOneWidget);
    expect(find.text(_blockedMessage), findsOneWidget);
    expect(find.byKey(_approve), findsNothing);
    expect(find.byKey(_sendBack), findsOneWidget);
    expect(find.byKey(_reject), findsOneWidget);
  });

  testWidgets('the owner approves past the rule only with a sentence of reason', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await paymentApp(
      api,
      payment(40, ownerOverride: <String, dynamic>{'needed': true, 'allowed': true, 'message': _blockedMessage}),
    );
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('Your written override is needed'), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_approve)).label, 'Approve with override');

    await tester.tap(find.byKey(_approve));
    await settle(tester);
    expect(find.text('Why may this payment go ahead of the work done?'), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).onPressed, isNull);

    await tester.enterText(
      find.descendant(of: find.byKey(_overrideReason), matching: find.byType(TextField)),
      'Urgent',
    );
    await settle(tester, ms: 100);
    expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).onPressed, isNull);

    const String reason = 'Slab shuttering must start tomorrow, so the advance is needed now.';
    await tester.enterText(find.descendant(of: find.byKey(_overrideReason), matching: find.byType(TextField)), reason);
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmOverride));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);

    expect(api.approvalActLog.single['action'], 'approve');
    expect(api.approvalActLog.single['override_reason'], reason);
  });

  testWidgets('older documents keep send back only', (tester) async {
    final FakeApi api = FakeApi();
    api.approvalInbox.add(<String, dynamic>{
      ...payment(41),
      'doc_type': 'bill_note',
      'document': <String, dynamic>{'number': 'CN-2', 'total_amount': '11800.00'},
    });
    final TestApp app = await bookApp(api: api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(41));
    await settle(tester);

    expect(find.byKey(_approve), findsOneWidget);
    expect(find.byKey(_reject), findsNothing);
  });

  for (final (String locale, String heading, String reject) in <(String, String, String)>[
    ('mr', 'काम विरुद्ध दिलेले पैसे', 'नाकारा'),
    ('hi', 'काम बनाम भुगतान', 'अस्वीकार करें'),
  ]) {
    testWidgets('the payment reads in $locale', (tester) async {
      final FakeApi api = FakeApi();
      final TestApp app = await paymentApp(api, payment(40));
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.approvalDetail(40), locale: locale);
      await settle(tester);

      expect(find.text(heading), findsOneWidget);
      expect(find.text(reject), findsOneWidget);
    });
  }

  test('a reason must be a sentence: 20 characters and 4 words, in any script', () {
    expect(OwnerOverride.isSentence('Urgent'), isFalse);
    expect(OwnerOverride.isSentence('Pay now please ok'), isFalse);
    expect(OwnerOverride.isSentence('Slab work starts tomorrow morning'), isTrue);
    expect(OwnerOverride.isSentence('उद्या स्लॅबचे काम सुरू होणार आहे'), isTrue);
  });
}
