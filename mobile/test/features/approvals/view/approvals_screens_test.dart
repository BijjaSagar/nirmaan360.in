import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/approvals/view/approval_detail_screen.dart';

import '../../measurement/view/book_test_kit.dart';

const Key _approve = ValueKey<String>('approval-approve');
const Key _sendBack = ValueKey<String>('approval-send-back');
const Key _confirmBack = ValueKey<String>('approval-send-back-confirm');
const Key _refresh = ValueKey<String>('approvals-refresh');

/// Counts every act call and can refuse the next one.
class ActingApi extends FakeApi {
  final List<(int, String, String, String?)> acts = <(int, String, String, String?)>[];
  ApiFailure? failNext;

  @override
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
  }) {
    acts.add((approvalId, action, clientUuid, remark));
    final ApiFailure? failure = failNext;
    if (failure != null) {
      failNext = null;
      throw failure;
    }
    return super.actOnApproval(approvalId, action: action, clientUuid: clientUuid, remark: remark);
  }
}

Map<String, dynamic> measurement(int id, {bool deviation = false, bool canAct = true}) => {
  'id': id,
  'doc_type': 'measurement',
  'status': 'IN_REVIEW',
  'title': 'Measurement · 2.1 · 15.000 cum',
  'project': {'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': {'id': 4, 'name': 'Sachin More'},
  'submitted_at': '2026-11-09T07:00:00+05:30',
  'current_level': 1,
  'levels': ['qs', 'pm'],
  'current_role': 'qs',
  'current_role_label': 'Estimator / QS',
  'can_act': canAct,
  'document': {
    'id': 3,
    'boq_item': {'code': '2.1', 'description': 'RCC M25 slab', 'unit': 'cum'},
    'measured_on': '2026-11-09',
    'location': 'Footing F12',
    'description': null,
    'nos': '3.000',
    'length': '5.000',
    'breadth': '1.000',
    'depth': null,
    'is_deduction': false,
    'qty': '15.000',
    'deviation': deviation,
    'remarks': null,
    'balance_qty': '85.000',
  },
  'steps': [
    {
      'level': 0,
      'role': 'engineer',
      'action': 'submit',
      'remark': null,
      'actor': 'Sachin More',
      'acted_at': '2026-11-09T07:00:00+05:30',
    },
  ],
};

Map<String, dynamic> bill(int id) => {
  'id': id,
  'doc_type': 'ra_bill',
  'status': 'IN_REVIEW',
  'title': 'RA bill 7',
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
  'steps': [],
};

Future<TestApp> approvalsApp(ActingApi api, {FakeConnectivity? connectivity}) async {
  api.approvalInbox.addAll(<Map<String, dynamic>>[measurement(7), bill(8)]);
  final TestApp app = await bookApp(api: api, connectivity: connectivity);
  return app;
}

Future<void> settle(WidgetTester tester, {int ms = 500}) async {
  await pumpDrift(tester, 10);
  await tester.pump(Duration(milliseconds: ms));
  await pumpDrift(tester, 10);
}

void main() {
  testWidgets('the inbox shows each document with its deciding figure large', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);

    expect(find.text('Measurement · 2.1 · 15.000 cum'), findsOneWidget);
    expect(find.textContaining('15.000'), findsWidgets);
    expect(find.textContaining('₹13,95,060.00'), findsOneWidget);
    expect(find.textContaining('KT-B · Kalyani Tower B'), findsNWidgets(2));
    expect(find.textContaining('Sachin More'), findsOneWidget);
    expect(find.textContaining('3 hours ago'), findsWidgets);
    expect(find.text('2 waiting for you'), findsOneWidget);
  });

  testWidgets('a deviation is flagged on the card', (tester) async {
    final ActingApi api = ActingApi();
    api.approvalInbox.add(measurement(7, deviation: true));
    final TestApp app = await bookApp(api: api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);

    expect(find.text('Deviation'), findsOneWidget);
  });

  testWidgets('a nothing-waiting inbox says so', (tester) async {
    final TestApp app = await bookApp(api: ActingApi());
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);
    expect(find.text('Nothing waiting'), findsOneWidget);
  });

  testWidgets('approve stamps, then the item leaves the inbox', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);

    await tester.tap(find.text('Measurement · 2.1 · 15.000 cum'));
    await settle(tester);

    expect(find.text('3 × 5 × 1 = 15.000 cum'), findsOneWidget);
    expect(find.byKey(_approve), findsOneWidget);

    await tester.tap(find.byKey(_approve));
    await pumpDrift(tester, 10);

    expect(api.acts.single.$2, 'approve');
    expect(find.byWidgetPredicate((w) => w is RichText && w.text.toPlainText().contains('APPROVED')), findsWidgets);

    await settle(tester, ms: 900);
    await settle(tester, ms: 400);
    expect(find.byType(ApprovalDetailScreen), findsNothing);
    expect(find.text('Measurement · 2.1 · 15.000 cum'), findsNothing);
    expect(find.text('RA bill 7'), findsOneWidget);
  });

  testWidgets('with motion on the approved card is stamped and leaves', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals, disableAnimations: false);
    await settle(tester, ms: 800);

    await tester.tap(find.text('Measurement · 2.1 · 15.000 cum'));
    await settle(tester, ms: 500);
    await tester.tap(find.byKey(_approve));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(seconds: 1));
    await settle(tester, ms: 500);

    // Back on the inbox, the card is still there, wearing its stamp.
    expect(find.byWidgetPredicate((w) => w is RubberStamp && w.label == 'Approved'), findsOneWidget);

    await tester.pump(const Duration(seconds: 2));
    await pumpDrift(tester, 10);
    expect(find.byWidgetPredicate((w) => w is RubberStamp && w.label == 'Approved'), findsNothing);
    expect(find.text('Measurement · 2.1 · 15.000 cum'), findsNothing);
    expect(api.acts, hasLength(1));
  });

  testWidgets('a double tap on approve acts once', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await tester.tap(find.byKey(_approve));
    await pumpDrift(tester, 10);
    await tester.tap(find.byKey(_approve), warnIfMissed: false);
    await settle(tester, ms: 900);

    expect(api.acts, hasLength(1));
    expect(api.appliedApprovalActs, 1);
  });

  testWidgets('send back needs a remark, and sends the one chosen', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);
    await tester.tap(find.text('Measurement · 2.1 · 15.000 cum'));
    await settle(tester);

    await tester.tap(find.byKey(_sendBack));
    await settle(tester);

    final GloveButton confirm = tester.widget(find.byKey(_confirmBack));
    expect(confirm.onPressed, isNull);

    await tester.tap(find.text('Check the dimensions'));
    await settle(tester, ms: 100);
    expect(tester.widget<GloveButton>(find.byKey(_confirmBack)).onPressed, isNotNull);

    await tester.tap(find.byKey(_confirmBack));
    await settle(tester, ms: 500);

    expect(api.acts.single.$2, 'return');
    expect(api.acts.single.$4, 'Check the dimensions');
    await settle(tester, ms: 400);
    expect(find.byType(ApprovalDetailScreen), findsNothing);
    expect(find.text('Measurement · 2.1 · 15.000 cum'), findsNothing);
    await tester.pump(const Duration(seconds: 4));
  });

  testWidgets('a typed remark also works', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    await tester.tap(find.byKey(_sendBack));
    await settle(tester);
    await tester.enterText(find.byType(TextField), 'Depth is missing');
    await settle(tester, ms: 100);
    await tester.tap(find.byKey(_confirmBack));
    await settle(tester, ms: 500);

    expect(api.acts.single.$4, 'Depth is missing');
    await tester.pump(const Duration(seconds: 4));
  });

  testWidgets('offline, both buttons are disabled and say why', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    expect(tester.widget<GloveButton>(find.byKey(_approve)).onPressed, isNull);
    expect(tester.widget<GloveButton>(find.byKey(_sendBack)).onPressed, isNull);
    expect(find.textContaining('need network'), findsWidgets);

    await tester.tap(find.byKey(_approve), warnIfMissed: false);
    await pumpDrift(tester, 10);
    expect(api.acts, isEmpty);

    // The network returns: the same screen wakes up.
    app.connectivity.setOnline(true);
    await settle(tester);
    expect(tester.widget<GloveButton>(find.byKey(_approve)).onPressed, isNotNull);
  });

  testWidgets('a call that fails offline explains itself and retries with the same id', (tester) async {
    final ActingApi api = ActingApi()..failNext = const OfflineFailure();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await pumpDrift(tester, 10);
    await settle(tester, ms: 100);

    expect(find.text('No network'), findsOneWidget);
    expect(find.textContaining('Nothing was queued'), findsOneWidget);

    await tester.tap(find.byKey(_approve));
    await settle(tester, ms: 900);

    expect(api.acts, hasLength(2));
    expect(api.acts[0].$3, api.acts[1].$3);
    expect(api.appliedApprovalActs, 1);
  });

  testWidgets('a 403 from losing the race says so and offers a refresh', (tester) async {
    final ActingApi api = ActingApi()
      ..failNext = const ServerFailure('You cannot act on this approval.', statusCode: 403);
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    await tester.tap(find.byKey(_approve));
    await pumpDrift(tester, 10);
    await settle(tester, ms: 100);

    expect(find.text('Already decided'), findsOneWidget);
    expect(find.text('Refresh'), findsOneWidget);

    // Someone else approved it: the next refresh no longer lists it.
    api.approvalInbox.removeWhere((row) => row['id'] == 7);
    await tester.tap(find.text('Refresh'));
    await settle(tester);

    expect(find.text('No longer waiting'), findsOneWidget);
    expect(find.text('Back to approvals'), findsOneWidget);
  });

  testWidgets('a document someone else must act on shows no buttons', (tester) async {
    final ActingApi api = ActingApi();
    api.approvalInbox.add(measurement(7, canAct: false));
    final TestApp app = await bookApp(api: api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    expect(find.byKey(_approve), findsNothing);
    expect(find.byKey(_sendBack), findsNothing);
  });

  testWidgets('a bill shows net payable, grouped the Indian way, and its chain', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(8));
    await settle(tester);

    expect(find.textContaining('₹13,95,060.00'), findsWidgets);
    expect(find.text('₹12,45,000.00'), findsOneWidget);
  });

  testWidgets('offline, the inbox still reads from cache', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);
    api.offline = true;
    await pumpBook(tester, app, start: Routes.approvals);
    await settle(tester);

    expect(find.text('Measurement · 2.1 · 15.000 cum'), findsOneWidget);
    expect(tester.widget<GloveButton>(find.byKey(_refresh)).onPressed, isNull);
  });

  testWidgets('every control is at least 56dp high', (tester) async {
    final ActingApi api = ActingApi();
    final TestApp app = await approvalsApp(api);
    addTearDown(app.dispose);
    await pumpBook(tester, app, start: Routes.approvalDetail(7));
    await settle(tester);

    expect(tester.getSize(find.byKey(_approve)).height, greaterThanOrEqualTo(56));
    expect(tester.getSize(find.byKey(_sendBack)).height, greaterThanOrEqualTo(56));
    expect(tester.getCenter(find.byKey(_approve)).dy, greaterThan(400));
  });
}
