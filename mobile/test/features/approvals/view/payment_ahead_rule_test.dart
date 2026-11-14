// The payment-ahead rule on the approvals screen: the server refuses a
// contractor payment that goes past the work done unless the owner approves
// with a written reason (422 on `override_reason`).
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/approvals/view/approval_detail_screen.dart';

import '../../measurement/view/book_test_kit.dart';
import 'contractor_payment_approval_test.dart' show payment;

const Key _approve = ValueKey<String>('approval-approve');
const Key _sendBack = ValueKey<String>('approval-send-back');
const Key _reject = ValueKey<String>('approval-reject');
const Key _note = ValueKey<String>('approval-owner-override');
const Key _cardNote = ValueKey<String>('approval-payment-ahead');
const Key _overrideReason = ValueKey<String>('approval-override-reason');
const Key _confirmOverride = ValueKey<String>('approval-override-confirm');
const Key _rule = ValueKey<String>('approval-override-rule');

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

/// A payment as the server with the rule sends it: the document it always
/// sent, with `owner_override` filled.
Map<String, dynamic> ruled(int id, Map<String, dynamic> ownerOverride) => payment(id, ownerOverride: ownerOverride);

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
  return row;
}

/// Refuses the next decision the way the server does, and can change what the
/// inbox holds at that moment, as when work or payments moved since the phone read it.
class RuleApi extends FakeApi {
  ValidationFailure? refuseNext;
  void Function()? onRefuse;

  /// Any other failure for the next decision, such as a lost network.
  ApiFailure? failNextAct;
  final List<(String, String?)> sent = <(String, String?)>[];

  @override
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
    String? overrideReason,
  }) {
    final ApiFailure? failure = failNextAct;
    if (failure != null) {
      failNextAct = null;
      throw failure;
    }
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

  testWidgets('a reason the server refuses shows beside the box, and what was typed stays', (tester) async {
    final RuleApi api = RuleApi()..refuseNext = refusal(_writeWhy);
    final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: true, message: _blocked)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await settle(tester);
    const String first = 'It is fine to pay this one now.';
    await tester.enterText(_reasonField(), first);
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmOverride));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);

    // Still on the reason: the server's words under the box, the owner's words in it, ready to send again.
    expect(find.byKey(_overrideReason), findsOneWidget);
    expect(find.descendant(of: find.byKey(_overrideReason), matching: find.text(_writeWhy)), findsOneWidget);
    expect(tester.widget<TextField>(_reasonField()).controller!.text, first);
    expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).onPressed, isNotNull);
    expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).loading, isFalse);
    expect(api.approvalActLog, isEmpty);

    // Changing the words clears the refusal, which was about the old ones.
    await tester.enterText(_reasonField(), _reason);
    await settle(tester, ms: 100);
    expect(find.descendant(of: find.byKey(_overrideReason), matching: find.text(_writeWhy)), findsNothing);

    await tester.tap(find.byKey(_confirmOverride));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);

    expect(api.approvalActLog.single['override_reason'], _reason);
    expect(api.sent.map((attempt) => attempt.$2), <String?>[first, _reason]);
    expect(api.sent[0].$1, api.sent[1].$1);
    expect(find.byKey(_overrideReason), findsNothing);
    expect(find.byType(ApprovalDetailScreen), findsNothing);
  });

  testWidgets('with no network the reason stays in the box and nothing is sent', (tester) async {
    final RuleApi api = RuleApi();
    final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: true, message: _blocked)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await settle(tester);
    await tester.enterText(_reasonField(), _reason);
    await settle(tester, ms: 100);
    api.failNextAct = const OfflineFailure();
    await tester.tap(find.byKey(_confirmOverride));
    await settle(tester, ms: 900);

    expect(
      find.descendant(of: find.byKey(_overrideReason), matching: find.textContaining('Nothing was queued')),
      findsOneWidget,
    );
    expect(tester.widget<TextField>(_reasonField()).controller!.text, _reason);
    expect(api.approvalActLog, isEmpty);

    await tester.tap(find.byKey(_confirmOverride));
    await settle(tester, ms: 900);
    await settle(tester, ms: 400);
    expect(api.approvalActLog.single['override_reason'], _reason);
  });

  testWidgets('the owner is told the 20-character and four-word rule before anything is sent', (tester) async {
    final RuleApi api = RuleApi();
    final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: true, message: _blocked)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await settle(tester);

    // On the page before a word is typed, with why the reason is asked and where it is kept.
    expect(find.text('At least 4 words and 20 letters.'), findsOneWidget);
    expect(find.textContaining('kept with the payment and in the audit log'), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).onPressed, isNull);

    Color ruleColour() => tester.widget<Text>(find.byKey(_rule)).style!.color!;
    final Color quiet = ruleColour();

    // One word, four short words, twenty letters in one word: none is a sentence, and the rule says so in colour.
    for (final String short in <String>['Urgent', 'Pay it now ok', 'aaaaaaaaaaaaaaaaaaaaaaaaa']) {
      await tester.enterText(_reasonField(), short);
      await settle(tester, ms: 100);
      expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).onPressed, isNull, reason: short);
      expect(ruleColour(), isNot(quiet), reason: short);
    }

    await tester.enterText(_reasonField(), 'Slab work starts tomorrow morning');
    await settle(tester, ms: 100);
    expect(tester.widget<GloveButton>(find.byKey(_confirmOverride)).onPressed, isNotNull);
    expect(ruleColour(), quiet);
    expect(api.sent, isEmpty);
  });

  testWidgets('only the owner\'s override carries a reason: send back and reject never do', (tester) async {
    final RuleApi api = RuleApi();
    api.approvalInbox.add(ruled(41, _override(needed: true, allowed: true, message: _blocked)));
    final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: true, message: _blocked)));
    addTearDown(app.dispose);

    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);
    await tester.tap(find.byKey(_sendBack));
    await settle(tester);
    await tester.tap(find.text('Bill or receipt is missing'));
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(const ValueKey<String>('approval-send-back-confirm')));
    await settle(tester, ms: 500);
    await tester.pump(const Duration(seconds: 4));

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    await pumpBook(tester, app, start: Routes.approvalDetail(41));
    await settle(tester);
    await tester.tap(find.byKey(_reject));
    await settle(tester);
    await tester.tap(find.text('Already paid, or asked for twice'));
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(const ValueKey<String>('approval-reject-confirm')));
    await settle(tester, ms: 500);
    await tester.pump(const Duration(seconds: 4));

    expect(api.approvalActLog.map((act) => (act['action'], act['override_reason'])), <(Object?, Object?)>[
      ('return', null),
      ('reject', null),
    ]);
    expect(api.sent.map((attempt) => attempt.$2), <String?>[null, null]);
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
    // What the work done is worth, beside what is paid: the contract less the work still to do.
    expect(find.text('Value of work done'), findsOneWidget);
    expect(find.text('₹70,000.00'), findsOneWidget);
    expect(find.text('₹60,000.00'), findsOneWidget);
    expect(find.text('₹80,000.00'), findsOneWidget);
  });

  testWidgets('with no message from the server, the card warns in its own words', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, payment(40));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.byKey(_note), findsNothing);
    expect(find.byKey(_cardNote), findsOneWidget);
    expect(find.text('Value of work done'), findsOneWidget);
    expect(find.text('₹70,000.00'), findsOneWidget);
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

  testWidgets('a message that does not stop approval shows as a warning, and approve is plain', (tester) async {
    final FakeApi api = FakeApi();
    final TestApp app = await appWith(api, ruled(40, _override(needed: false, allowed: false, message: _later)));
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(40));
    await settle(tester);

    expect(find.text('Paid ahead of work'), findsOneWidget);
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

  for (final (String locale, String title, String workValue, String rule) in <(String, String, String, String)>[
    ('mr', 'तुमची लेखी विशेष मंजुरी लागेल', 'झालेल्या कामाची किंमत', 'किमान 4 शब्द आणि 20 अक्षरे.'),
    ('hi', 'आपकी लिखित विशेष मंज़ूरी चाहिए', 'हुए काम की कीमत', 'कम से कम 4 शब्द और 20 अक्षर।'),
  ]) {
    testWidgets('the rule reads in $locale', (tester) async {
      final FakeApi api = FakeApi();
      final TestApp app = await appWith(api, ruled(40, _override(needed: true, allowed: true, message: _blocked)));
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.approvalDetail(40), locale: locale);
      await settle(tester);

      expect(find.text(title), findsOneWidget);
      expect(find.text(workValue), findsOneWidget);

      await tester.tap(find.byKey(_approve));
      await settle(tester);
      expect(find.text(rule), findsOneWidget);
    });
  }
}
