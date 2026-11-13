// Renders editing and deleting a BOQ line to PNG with real fonts, for a person
// to judge.
//
// flutter test test/features/projects/view/boq_edit_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/view/boq_edit_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

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

  Future<void> settle(WidgetTester tester) async {
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 800));
  }

  testWidgets('boq edit screens', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 780) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final FakeApi api = FakeApi()..grant('boq.manage');
    api.user['is_owner'] = true;
    final TestApp app = await TestApp.create(api: api);
    addTearDown(app.dispose);
    await app.services.measurements.create(
      MeasurementInput(projectId: 1, boqItemId: 1, measuredOn: '2026-11-09', nos: Qty.parse('310')),
    );
    await app.sync();
    api.boqItemUses[1] = <String>[
      'This item is in a tender and cannot be removed.',
      'This item has work in daily reports and cannot be removed.',
    ];

    for (final (String name, Locale locale) in <(String, Locale)>[('en', const Locale('en')), ('mr', const Locale('mr'))]) {
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await tester.pumpWidget(
        fieldRouterApp(
          app.services,
          locale: locale,
          initial: Routes.boq,
          routes: <String, Widget Function(dynamic)>{
            Routes.boq: (_) => const BoqScreen(),
            '/boq/:item/edit': (s) => BoqEditScreen(boqItemId: int.parse(s.pathParameters['item']!)),
          },
        ),
      );
      await settle(tester);
      await capture(tester, '${name}_boq_list_with_edit');

      await tester.tap(find.byKey(const ValueKey<String>('boq-edit-1')));
      await settle(tester);
      await capture(tester, '${name}_boq_edit_top');

      final Finder form = find.descendant(of: find.byType(BoqEditScreen), matching: find.byType(Scrollable)).first;
      final Finder delete = find.byKey(const ValueKey<String>('boq-edit-delete'));
      await tester.scrollUntilVisible(delete, 300, scrollable: form);
      await settle(tester);
      await capture(tester, '${name}_boq_edit_foot');

      await tester.tap(delete);
      await settle(tester);
      await capture(tester, '${name}_boq_delete_sheet');

      await tester.enterText(find.byKey(const ValueKey<String>('boq-delete-reason')), 'Added twice');
      await tester.tap(find.byKey(const ValueKey<String>('boq-delete-confirm')));
      await settle(tester);
      await tester.scrollUntilVisible(find.byKey(const ValueKey<String>('boq-delete-problem')), 300, scrollable: form);
      await settle(tester);
      await capture(tester, '${name}_boq_delete_refused');
    }
  });
}
