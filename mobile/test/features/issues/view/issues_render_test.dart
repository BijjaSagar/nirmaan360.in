// Renders the issue screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/issues/view/issues_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_form_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

late String _crackUuid;

Future<TestApp> _seeded() async {
  final app = await TestApp.create();
  app.api.users.add({'id': 7, 'name': 'Prakash More', 'role': 'pm'});
  app.api.users.add({'id': 8, 'name': 'Ganesh Bhosale', 'role': 'engineer'});
  await app.sync();
  final issues = app.services.issues;
  _crackUuid = await issues.raise(
    projectId: 1,
    title: 'Crack in column C3',
    priority: 'critical',
    locationNote: 'Grid C, third floor',
    assignedTo: 4,
    dueDate: '2026-11-06',
  );
  final started = await issues.raise(
    projectId: 1,
    title: 'Water leak near lift shaft',
    priority: 'high',
    locationNote: 'Lift lobby, ground floor',
    assignedTo: 7,
    dueDate: '2026-11-09',
  );
  await issues.changeStatus(started, 'in_progress');
  await issues.raise(
    projectId: 1,
    title: 'Shuttering panels short at slab S2',
    priority: 'medium',
    locationNote: 'Slab S2',
    dueDate: '2026-11-14',
  );
  final done = await issues.raise(
    projectId: 1,
    title: 'Scaffold tie loose on east side',
    priority: 'low',
    locationNote: 'East elevation',
    assignedTo: 8,
    dueDate: '2026-11-05',
  );
  await issues.changeStatus(done, 'resolved');
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

  testWidgets('issue screens', (tester) async {
    phone(tester);
    final app = await _seeded();
    addTearDown(app.dispose);

    final String uuid = _crackUuid;

    for (final (name, locale) in <(String, Locale)>[('en', const Locale('en')), ('mr', const Locale('mr'))]) {
      await tester.pumpWidget(
        fieldRouterApp(
          app.services,
          locale: locale,
          initial: Routes.issues,
          routes: {
            Routes.issues: (_) => const IssueListScreen(),
            Routes.issueForm: (_) => const IssueFormScreen(),
            '/issues/:uuid': (s) => IssueDetailScreen(clientUuid: s.pathParameters['uuid']!),
          },
        ),
      );
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 800));
      await capture(tester, '${name}_issues_list');

      await tester.pumpWidget(
        fieldApp(app.services, const IssueFormScreen(), locale: locale),
      );
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 800));
      await capture(tester, '${name}_issue_form');

      await tester.pumpWidget(
        fieldApp(app.services, IssueDetailScreen(clientUuid: uuid), locale: locale),
      );
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 800));
      await capture(tester, '${name}_issue_detail');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
    await tester.pump(const Duration(seconds: 1));
  });
}
