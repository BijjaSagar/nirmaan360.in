// Renders the issue screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/issues/view/issues_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/features/attendance/view/widgets/field_kit.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_form_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

late String _crackUuid;
late String _resolvedUuid;
late String _marathiUuid;

/// Some issues have reached the office, and some are still on the phone, so
/// the list shows both honestly.
Future<TestApp> _seeded() async {
  final app = await TestApp.create();
  app.api.users.add({'id': 7, 'name': 'Prakash More', 'role': 'pm'});
  app.api.users.add({'id': 8, 'name': 'Ganesh Bhosale', 'role': 'engineer'});
  await app.sync();
  final issues = app.services.issues;
  _crackUuid = await issues.raise(
    projectId: 1,
    title: 'Crack in column C3',
    description:
        'Diagonal crack about 2 mm wide on the east face, from the beam '
        'junction down. Props are in place. Engineer to inspect before the '
        'next pour.',
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
  _resolvedUuid = await issues.raise(
    projectId: 1,
    title: 'Scaffold tie loose on east side',
    priority: 'low',
    locationNote: 'East elevation',
    assignedTo: 8,
    dueDate: '2026-11-05',
  );
  await issues.changeStatus(_resolvedUuid, 'resolved');
  await app.sync();
  // These two stay on the phone.
  await issues.raise(
    projectId: 1,
    title:
        'Shuttering panels short at slab S2, need forty more before Thursday',
    priority: 'medium',
    locationNote: 'Slab S2',
    dueDate: '2026-11-14',
  );
  _marathiUuid = await issues.raise(
    projectId: 1,
    title: 'तिसऱ्या मजल्यावर स्लॅबला पाणी गळती',
    description: 'पावसानंतर स्लॅबच्या खालून पाणी टपकते. वॉटरप्रूफिंग तपासा.',
    priority: 'high',
    locationNote: 'तिसरा मजला, ग्रिड बी',
    assignedTo: 7,
    dueDate: '2026-11-12',
  );
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

  Future<void> settle(WidgetTester tester) async {
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 800));
  }

  testWidgets('issue screens', (tester) async {
    phone(tester);
    final app = await _seeded();
    addTearDown(app.dispose);

    for (final (name, locale) in <(String, Locale)>[
      ('en', const Locale('en')),
      ('mr', const Locale('mr')),
    ]) {
      await tester.pumpWidget(
        fieldRouterApp(
          app.services,
          locale: locale,
          initial: Routes.issues,
          routes: {
            Routes.issues: (_) => const IssueListScreen(),
            Routes.issueForm: (_) => const IssueFormScreen(),
            '/issues/:uuid': (s) =>
                IssueDetailScreen(clientUuid: s.pathParameters['uuid']!),
          },
        ),
      );
      await settle(tester);
      await capture(tester, '${name}_issues_list');

      // Scrolled to the foot, where the Marathi issue and the resolved group are.
      await tester.drag(find.byType(CustomScrollView), const Offset(0, -900));
      await settle(tester);
      await capture(tester, '${name}_issues_list_foot');

      await tester.pumpWidget(
        fieldApp(app.services, const IssueFormScreen(), locale: locale),
      );
      await settle(tester);
      await capture(tester, '${name}_issue_form');

      // A form that has been filled in: the day chosen says how to clear it.
      await tester.enterText(
        find.byType(TextField).first,
        'Crack in column C3',
      );
      await tester.tap(find.byType(FieldChoiceTile).last);
      await settle(tester);
      await capture(tester, '${name}_issue_form_filled');

      await tester.tap(find.byIcon(Icons.expand_more));
      await settle(tester);
      await capture(tester, '${name}_issue_assignee_sheet');

      // Choosing someone closes the sheet and shows the name in the field.
      await tester.tap(
        find
            .descendant(
              of: find.byType(BottomSheet),
              matching: find.byType(FieldChoiceTile),
            )
            .at(2),
      );
      await settle(tester);
      await capture(tester, '${name}_issue_form_assigned');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await tester.pumpWidget(
        fieldApp(
          app.services,
          IssueDetailScreen(clientUuid: _crackUuid),
          locale: locale,
        ),
      );
      await settle(tester);
      await capture(tester, '${name}_issue_detail');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await tester.pumpWidget(
        fieldApp(
          app.services,
          IssueDetailScreen(clientUuid: _resolvedUuid),
          locale: locale,
        ),
      );
      await settle(tester);
      await capture(tester, '${name}_issue_detail_resolved');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await tester.pumpWidget(
        fieldApp(
          app.services,
          IssueDetailScreen(clientUuid: _marathiUuid),
          locale: locale,
        ),
      );
      await settle(tester);
      await capture(tester, '${name}_issue_detail_unsent');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
    await tester.pump(const Duration(seconds: 1));
  });
}
