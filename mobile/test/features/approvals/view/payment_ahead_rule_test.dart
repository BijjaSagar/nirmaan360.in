// The payment-ahead rule on the approvals screen: the server refuses a
// contractor payment that goes past the work done unless the owner approves
// with a written reason (422 on `override_reason`).
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/design/design.dart';

import '../../measurement/view/book_test_kit.dart';
import 'contractor_payment_approval_test.dart' show payment;

const Key _approve = ValueKey<String>('approval-approve');
const Key _sendBack = ValueKey<String>('approval-send-back');
const Key _reject = ValueKey<String>('approval-reject');
const Key _note = ValueKey<String>('approval-owner-override');
const Key _cardNote = ValueKey<String>('approval-payment-ahead');
const Key _overrideReason = ValueKey<String>('approval-override-reason');
const Key _confirmOverride = ValueKey<String>('approval-override-confirm');

const String _blocked =
    'Approving this payment would make it 80% paid against 70% work done, 10 points ahead, beyond this '
    "project's limit of 5 points. Only the owner can approve it, with a written reason.";
const String _writeWhy = 'Write why this payment may go ahead of the work done, in at least one full sentence.';
const String _noValue =
    'This contract has no value, as its lines have no quantity, so no payment on it is covered by work done. '
    'Only the owner can approve it, with a written reason.';
const String _later =
    'As it stands, the last approval of this payment will need the owner, with a written reason: it would put '
    "payment ahead of the work done beyond this project's limit.";
const String _reason = 'Slab shuttering must start tomorrow, so the advance is needed now.';

Map<String, dynamic> _override({required bool needed, required bool allowed, String? message}) => <String, dynamic>{
  'needed': needed,
  'allowed': allowed,
  'message': message,
};

/// PaymentAheadGuard::assess() as the server sends it to someone with money.view.
const Map<String, dynamic> _check = <String, dynamic>{
  'contract_value': '100000.00',
  'work_done': '70000.00',
  'work_pct': '70.00',
  'paid': '60000.00',
  'paid_pct': '60.00',
  'paid_after': '80000.00',
  'paid_pct_after': '80.00',
  'gap_after': '10.00',
  'limit_pct': '5.00',
  'blocked': true,
};

/// A payment with the guard's figures, as the server with the rule sends it.
Map<String, dynamic> ruled(int id, Map<String, dynamic> ownerOverride) {
  final Map<String, dynamic> row = payment(id, ownerOverride: ownerOverride);
  (row['document'] as Map<String, dynamic>)['check'] = _check;
  return row;
}

/// A contract whose lines have no quantity: worth nothing, so both percents read 0.
Map<String, dynamic> noValuePayment(int id, Map<String, dynamic> ownerOverride) {
  final Map<String, dynamic> row = payment(id, ownerOverride: ownerOverride);
  final Map<String, dynamic> doc = row['document'] as Map<String, dynamic>;
  final Map<String, dynamic> impact = doc['impact'] as Map<String, dynamic>;
  Map<String, dynamic> zero(Object? figures, String paid) => <String, dynamic>{
    ...(figures as Map<String, dynamic>),
    'contract_value': '0.00',
    'paid': paid,
    'paid_pct': '0.00',
    'gap_pct': '0.00',
    'ratio_status': 'none',
    'over_limit': paid != '0.00',
    'work_pct': '0.00',
    'balance_work_value': '0.00',
  };
  doc['work_pct'] = '0.00';
  doc['paid_pct'] = '0.00';
  impact['now'] = zero(impact['now'], '0.00');
  impact['after'] = zero(impact['after'], '20000.00');
  impact['item'] = null;
  doc['check'] = <String, dynamic>{
    ..._check,
    'contract_value': '0.00',
    'work_done': '0.00',
    'work_pct': '0.00',
    'paid': '0.00',
    'paid_pct': '0.00',
    'paid_after': '20000.00',
    'paid_pct_after': '0.00',
    'gap_after': '0.00',
  };
  return row;
}

/// Refuses the next decision the way the server does, and can change what the
/// inbox holds at that moment, as when work or payments moved since the phone read it.
class RuleApi extends FakeApi {
  ValidationFailure? refuseNext;
  void Function()? onRefuse;
  final List<(String, String?)> sent = <(String, String?)>[];

  @override
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
    String? overrideReason,
  }) {
    sent.add((clientUuid, overrideReason));
    final ValidationFailure? refusal = refuseNext;
    if (refusal != null) {
      refuseNext = null;
      onRefuse?.call();
      throw refusal;
    }
    return super.actOnApproval(
      approvalId,
      action: action,
      clientUuid: clientUuid,
      remark: remark,
      overrideReason: overrideReason,
    );
  }
}

ValidationFailure refusal(String message) => ValidationFailure(
  'The given data was invalid.',
  errors: <String, List<String>>{
    'override_reason': <String>[message],
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

Finder _reasonField() => find.descendant(of: find.byKey(_overrideReason), matching: find.byType(TextField));

void main() {
  testWidgets('a manager whose phone is behind is refused, told why, and no longer offered approve', (tester) async {
    final RuleApi api = RuleApi();
    // Read before the rule applied: nothing says an override is needed.
    final TestApp app = await appWith(api, ruled(40, _override(needed: false, allowed: false)));
    addTearDown(app.dispose);
    api
      ..refuseNext = refusal(_blocked)
      ..onRefuse = () => api.approvalInbox
        ..clear()
        ..add(ruled(40, _override(needed: true, allowed: false, message: _blocked)));
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);
    expect(find.byKey(_approve), findsOneWidget);

    await tester.tap(find.byKey(_approve));
    await settle(tester);
    await settle(tester);

    // The field's own message, not the reply's general one.
    expect(find.text('The server refused this'), findsOneWidget);
    expect(find.text('The given data was invalid.'), findsNothing);
    // Once in the refusal, once in the document read again.
    expect(find.text(_blocked), findsNWidgets(2));
    expect(find.text('Only the owner can approve this'), findsOneWidget);
    expect(find.byKey(_approve), findsNothing);
    expect(tester.widget<GloveButton>(find.byKey(_sendBack)).onPressed, isNotNull);
    expect(tester.widget<GloveButton>(find.byKey(_reject)).onPressed, isNotNull);
    expect(api.approvalActLog, isEmpty);
  });

  testWidgets('an owner whose phone is behind is asked for the reason on the next tap', (tester) async {
    final RuleApi api = RuleApi();
    final TestApp app = await appWith(api, ruled(40, _override(needed: false, allowed: false)));
    addTearDown(app.dispose);
    api
      ..refuseNext = refusal(_writeWhy)
      ..onRefuse = () => api.approvalInbox
        ..clear()
        ..add(ruled(40, _override(needed: true, allowed: true, message: _blocked)));
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await settle(tester);
    await settle(tester);

    expect(find.text(_writeWhy), findsOneWidget);
    expect(find.text('Your written override is needed'), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_approve)).label, 'Approve with override');

    await tester.tap(find.byKey(_approve));
    await settle(tester);
    await tester.enterText(_reasonField(), _reason);
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmOverride));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);

    expect(api.approvalActLog.single['action'], 'approve');
    expect(api.approvalActLog.single['override_reason'], _reason);
    // The refused try and the one that landed carry one request id.
    expect(api.sent, hasLength(2));
    expect(api.sent[0].$1, api.sent[1].$1);
    expect(api.sent[0].$2, isNull);
  });

  testWidgets('a reason the server will not take leaves the owner free to write a better one', (tester) async {
    final RuleApi api = RuleApi()..refuseNext = refusal(_writeWhy);
    final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: true, message: _blocked)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    Future<void> approveWith(String reason) async {
      await tester.tap(find.byKey(_approve));
      await settle(tester);
      await tester.enterText(_reasonField(), reason);
      await settle(tester, ms: 100);
      await tester.tap(find.byKey(_confirmOverride));
      await settle(tester, ms: 900);
      await settle(tester, ms: 400);
    }

    await approveWith('It is fine to pay this one now.');
    expect(find.text(_writeWhy), findsOneWidget);
    expect(api.approvalActLog, isEmpty);
    expect(tester.widget<GloveButton>(find.byKey(_approve)).onPressed, isNotNull);

    await approveWith(_reason);
    expect(api.approvalActLog.single['override_reason'], _reason);
    expect(api.sent.map((attempt) => attempt.$2), <String?>['It is fine to pay this one now.', _reason]);
  });

  testWidgets('the server gives the reason, so the card does not say it again', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: false, message: _blocked)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.byKey(_note), findsOneWidget);
    expect(find.byKey(_cardNote), findsNothing);
    expect(find.text('Approving keeps payment within the work done.'), findsNothing);
    // The guard's figure: what the work done is worth, beside what is paid.
    expect(find.text('Value of work done'), findsOneWidget);
    expect(find.text('₹70,000.00'), findsOneWidget);
    expect(find.text('Value of work left'), findsNothing);
  });

  testWidgets('a server without the guard figures still shows the value of work left', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('Value of work done'), findsNothing);
    expect(find.text('Value of work left'), findsOneWidget);
    expect(find.byKey(_cardNote), findsOneWidget);
  });

  testWidgets('a contract with no value is never called within the work done', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(
      api,
      noValuePayment(40, _override(needed: true, allowed: false, message: _noValue)),
    );
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text(_noValue), findsOneWidget);
    expect(find.text('Only the owner can approve this'), findsOneWidget);
    expect(find.text('Approving keeps payment within the work done.'), findsNothing);
    expect(find.byKey(_approve), findsNothing);
    expect(find.byKey(_sendBack), findsOneWidget);
  });

  testWidgets('with no message from the server, the card itself says a no-value contract is over', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, noValuePayment(40, _override(needed: false, allowed: false)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.byKey(_cardNote), findsOneWidget);
    expect(find.text('Over the limit'), findsOneWidget);
    expect(find.text('This contract has no value, so no payment on it is covered by work done.'), findsOneWidget);
    expect(find.text('Approving keeps payment within the work done.'), findsNothing);
  });

  testWidgets('before the last approval level the approver is warned and still approves', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, ruled(40, _override(needed: false, allowed: false, message: _later)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('The last approval will need the owner'), findsOneWidget);
    expect(find.text(_later), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_approve)).label, 'Approve');

    await tester.tap(find.byKey(_approve));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);

    expect(find.byKey(_overrideReason), findsNothing);
    expect(api.approvalActLog.single, <String, Object?>{
      'id': 40,
      'action': 'approve',
      'remark': null,
      'override_reason': null,
    });
  });

  for (final (String locale, String later, String workValue) in <(String, String, String)>[
    ('mr', 'शेवटच्या मंजुरीसाठी मालक लागेल', 'झालेल्या कामाची किंमत'),
    ('hi', 'आख़िरी मंज़ूरी के लिए मालिक चाहिए', 'हुए काम की कीमत'),
  ]) {
    testWidgets('the rule reads in $locale', (tester) async {
      final FakeApi api = FakeApi();
      final TestApp app = await appWith(api, ruled(40, _override(needed: false, allowed: false, message: _later)));
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.approvalDetail(40), locale: locale);
      await settle(tester);

      expect(find.text(later), findsOneWidget);
      expect(find.text(workValue), findsOneWidget);
    });
  }
}
