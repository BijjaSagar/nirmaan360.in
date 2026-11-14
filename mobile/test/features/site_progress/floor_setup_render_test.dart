// Renders the floor set-up, plan, share and week screens to PNG with real
// fonts, for a person to judge.
//
// flutter test test/features/site_progress/floor_setup_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';

import '../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../support/test_app.dart';
import 'floor_setup_test.dart' show key, setupApp, tapKey;
import 'site_progress_test.dart' show pumpProgress, settle;

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

  Future<TestApp> world() async {
    final TestApp app = await setupApp(owner: true);
    app.api.progressItemRows.add(<String, dynamic>{
      'id': 2, 'section': 'Masonry', 'code': '3.1', 'description': '230 mm brick work in cement mortar 1:6', 'unit': 'cum',
      'qty': '80.000', 'done_qty': '0.000', 'balance_qty': '80.000', 'percent': '0.00',
    });
    app.api.floorTargets[21] = <int, String>{1: '25.000'};
    app.api.floorProgressBody = app.api.floorDetailBody(21);
    app.api.progressSummaryBody = <String, dynamic>{
      ...app.api.progressSummaryBody,
      'start_date': '2026-10-01',
      'planned_days': 120,
      'deadline_date': '2027-01-29',
    };
    return app;
  }

  for (final String locale in <String>['en', 'mr', 'hi']) {
    testWidgets('floor set-up screens in $locale', (tester) async {
      for (final (String name, String path) in <(String, String)>[
        ('progress', Routes.progress),
        ('setup', Routes.floorSetup),
        ('plan', Routes.floorPlan(21)),
        ('share', Routes.floorSplit),
        ('week', Routes.progressWeek),
      ]) {
        final TestApp app = await world();
        addTearDown(app.dispose);
        await pumpProgress(tester, app, start: path, locale: locale);
        await capture(tester, '${locale}_floor_$name');

        if (name == 'setup') {
          await tapKey(tester, 'setup-floor-11');
          await capture(tester, '${locale}_floor_setup_actions');
          await tapKey(tester, 'floor-action-delete');
          await capture(tester, '${locale}_floor_setup_delete');
          await tapKey(tester, 'floor-delete-confirm');
          await capture(tester, '${locale}_floor_setup_delete_refused');
          await tapKey(tester, 'floor-delete-keep');
          await tapKey(tester, 'setup-floor-11');
          await tapKey(tester, 'floor-action-segments');
          await tester.enterText(key('floor-segments'), 'Flat 102\nFlat 103');
          await tester.pump();
          await capture(tester, '${locale}_floor_setup_segments');
          // Tapping above the sheet puts it away.
          await tester.tapAt(const Offset(180, 30));
          await settle(tester);
          await tapKey(tester, 'setup-time-frame');
          await capture(tester, '${locale}_floor_setup_time_frame');
        }
        if (name == 'plan') {
          await tapKey(tester, 'plan-line-2');
          await capture(tester, '${locale}_floor_plan_qty');
        }
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }
    });
  }
}
