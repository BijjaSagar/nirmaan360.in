// Renders the DPR share card and the measurement sheet chooser to PNG with the
// real fonts so a person can judge them.
//
// flutter test test/features/dpr/view/dpr_share_render_test.dart \
//   --dart-define=N360_DPR_DIR=/absolute/output/dir
//
// Without the define nothing is written and the file has no tests.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_list_screen.dart';

import '../../../core/design/test_helpers.dart';
import '../../../support/test_app.dart';
import 'dpr_test_support.dart';

const String _dir = String.fromEnvironment('N360_DPR_DIR');

void main() {
  if (_dir.isEmpty) return;

  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    await Directory(_dir).create(recursive: true);
  });

  Future<TestApp> start() async {
    final FakeApi api = demoApi()..grantReports();
    final TestApp app = await TestApp.create(seed: false, api: api);
    await app.sync();
    return app;
  }

  Future<void> settle(WidgetTester tester) async {
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 900));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 250)));
    await tester.pump(const Duration(milliseconds: 100));
  }

  for (final Locale locale in const <Locale>[Locale('en'), Locale('mr'), Locale('hi')]) {
    testWidgets('dpr-share-${locale.languageCode}', (tester) async {
      usePhone(tester, height: 1500);
      final TestApp app = await start();
      addTearDown(app.dispose);
      final String uuid = await app.services.dpr.saveDraft(
        const DprInput(
          projectId: 1,
          reportDate: today,
          weather: 'Clear',
          summary: 'Slab shuttering done.',
          manpower: <ManpowerLine>[ManpowerLine(trade: 'Mason', count: 12), ManpowerLine(trade: 'Helper', count: 21)],
        ),
      );
      await app.services.dpr.send(uuid);
      await app.sync();

      await tester.pumpWidget(hostApp(app.services, DprDetailScreen(clientUuid: uuid), locale: locale));
      await settle(tester);
      await tester.runAsync(() => capturePng(tester, _dir, 'dpr-share-${locale.languageCode}'));
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('measurement-sheet-${locale.languageCode}', (tester) async {
      usePhone(tester);
      final TestApp app = await start();
      addTearDown(app.dispose);

      await tester.pumpWidget(hostApp(app.services, const MeasurementListScreen(), locale: locale));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey<String>('measurement-sheet-pdf')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));
      await tester.runAsync(() => capturePng(tester, _dir, 'measurement-sheet-${locale.languageCode}'));
      await tester.pump(const Duration(seconds: 1));
    });
  }
}
