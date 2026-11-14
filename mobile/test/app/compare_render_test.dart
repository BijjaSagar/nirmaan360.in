// Renders the same four screens twice at 390x844, once with each top-bar tone,
// so the owner can choose between a white bar (A) and a brand bar (B).
//
// flutter test test/app/compare_render_test.dart --dart-define=N360_COMPARE_DIR=/abs/dir
//
// Without the define the layout check still runs and nothing is written.
import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';

import '../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../features/attendance/view/field_test_support.dart';
import '../features/measurement/view/book_test_kit.dart' show pumpBook;
import 'polish_harness.dart';
import 'shell_test_helpers.dart' show seedProgress, seedToday;

const String _dir = String.fromEnvironment('N360_COMPARE_DIR');

Map<String, dynamic> _measurementApproval(int id) => <String, dynamic>{
  'id': id,
  'doc_type': 'measurement',
  'status': 'IN_REVIEW',
  'title': 'Measurement · 2.1 · 15.000 cum',
  'project': <String, dynamic>{'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': <String, dynamic>{'id': 4, 'name': 'Sachin More'},
  'submitted_at': '2026-11-09T08:12:00+05:30',
  'current_level': 1,
  'levels': <String>['qs', 'pm'],
  'current_role': 'qs',
  'current_role_label': 'Estimator / QS',
  'can_act': true,
  'document': <String, dynamic>{
    'id': 3,
    'boq_item': <String, dynamic>{'code': '2.1', 'description': 'RCC M25 slab at level 4', 'unit': 'cum'},
    'measured_on': '2026-11-08',
    'location': 'Level 4, panel S2',
    'description': 'Slab concrete',
    'nos': '1.000',
    'length': '6.000',
    'breadth': '5.000',
    'depth': '0.500',
    'is_deduction': false,
    'qty': '15.000',
    'deviation': false,
    'remarks': null,
    'balance_qty': '555.000',
  },
  'steps': <Map<String, dynamic>>[
    <String, dynamic>{
      'level': 0,
      'role': 'engineer',
      'action': 'submit',
      'remark': null,
      'actor': 'Sachin More',
      'acted_at': '2026-11-09T08:12:00+05:30',
    },
  ],
};

Map<String, dynamic> _billApproval(int id) => <String, dynamic>{
  'id': id,
  'doc_type': 'ra_bill',
  'status': 'IN_REVIEW',
  'title': 'RA bill 7 · Patil Constructions',
  'project': <String, dynamic>{'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': <String, dynamic>{'id': 6, 'name': 'Anita Kulkarni'},
  'submitted_at': '2026-11-08T17:30:00+05:30',
  'current_level': 2,
  'levels': <String>['qs', 'pm', 'accounts'],
  'current_role': 'pm',
  'current_role_label': 'Project Manager',
  'can_act': true,
  'document': <String, dynamic>{
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
  'steps': <Map<String, dynamic>>[
    <String, dynamic>{
      'level': 0,
      'role': 'accounts',
      'action': 'submit',
      'remark': null,
      'actor': 'Anita Kulkarni',
      'acted_at': '2026-11-08T17:30:00+05:30',
    },
  ],
};

Future<TestApp> _owner() => TestApp.create(
      seed: false,
      api: FakeApi()
        ..grant('project.manage')
        ..grant('boq.manage'),
    );

Future<TestApp> _withIssues() async {
  final app = await TestApp.create();
  app.api.users.add(<String, dynamic>{'id': 7, 'name': 'Prakash More', 'role': 'pm'});
  await app.sync();
  final issues = app.services.issues;
  await issues.raise(
    projectId: 1,
    title: 'Shuttering plates short for Level 5',
    priority: 'critical',
    locationNote: 'Store yard',
    assignedTo: 4,
    dueDate: '2026-11-10',
  );
  await issues.raise(
    projectId: 1,
    title: 'Honeycombing at column C7, Level 3',
    priority: 'high',
    locationNote: 'Grid C7',
    assignedTo: 4,
    dueDate: '2026-11-12',
  );
  final seepage = await issues.raise(
    projectId: 1,
    title: 'Seepage at lift pit retaining wall',
    priority: 'medium',
    locationNote: 'Lift pit, basement',
    assignedTo: 7,
    dueDate: '2026-11-16',
  );
  await issues.changeStatus(seepage, 'in_progress');
  await app.sync();
  return app;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  tearDown(() => N360Chrome.topBar = N360BarTone.paper);

  /// Lets the brand mark decode before the frame is captured.
  Future<void> assets(WidgetTester tester) async {
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 300)));
    await tester.pump();
  }

  Future<void> shoot(WidgetTester tester, String name) async {
    await assets(tester);
    expect(tester.takeException(), isNull, reason: name);
    if (_dir.isEmpty) return;
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  /// Clears the tree between two apps, so the next pump builds a fresh shell
  /// rather than reusing the last one's state.
  Future<void> clear(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
  }

  void iphone(WidgetTester tester) {
    const Phone phone = Phone.iphone;
    tester.view.devicePixelRatio = 3;
    tester.view.physicalSize = phone.size * 3;
    tester.view.padding = FakeViewPadding(top: phone.safeTop * 3, bottom: phone.safeBottom * 3);
    tester.view.viewPadding = FakeViewPadding(top: phone.safeTop * 3, bottom: phone.safeBottom * 3);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetPadding);
    addTearDown(tester.view.resetViewPadding);
  }

  Future<void> settle(WidgetTester tester) async {
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 900));
  }

  for (final (String tag, N360BarTone tone) in <(String, N360BarTone)>[
    ('a', N360BarTone.paper),
    ('b', N360BarTone.brand),
  ]) {
    testWidgets('option $tag: home and new project', (tester) async {
      N360Chrome.topBar = tone;

      final home = await TestApp.create();
      addTearDown(home.dispose);
      await seedToday(home);
      await home.sync();
      await seedProgress(home);
      await pumpPolishApp(tester, home, phone: Phone.iphone, pixelRatio: 3);
      await shoot(tester, '${tag}_home');
      await clear(tester);

      final owner = await _owner();
      addTearDown(owner.dispose);
      await pumpPolishApp(tester, owner, location: Routes.projectForm, phone: Phone.iphone, pixelRatio: 3);
      await shoot(tester, '${tag}_project_form');
      await clear(tester);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('option $tag: approvals', (tester) async {
      N360Chrome.topBar = tone;
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.api.approvalInbox.addAll(<Map<String, dynamic>>[_measurementApproval(7), _billApproval(8)]);
      await app.sync();
      iphone(tester);
      await pumpBook(tester, app, start: Routes.approvals, size: Phone.iphone.size, pixelRatio: 3);
      await settle(tester);
      await shoot(tester, '${tag}_approvals');
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('option $tag: issues', (tester) async {
      N360Chrome.topBar = tone;
      final app = await _withIssues();
      addTearDown(app.dispose);
      iphone(tester);
      await tester.pumpWidget(fieldApp(app.services, const Scaffold(body: SizedBox.expand())));
      await tester.pump();
      final NavigatorState navigator = Navigator.of(tester.element(find.byType(Scaffold).first));
      unawaited(navigator.push(MaterialPageRoute<void>(builder: (_) => const IssueListScreen())));
      await settle(tester);
      await shoot(tester, '${tag}_issues');
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(seconds: 1));
    });
  }
}
