// Site engineers often enlarge the system font. Every screen must lay out at
// 1.3x in Marathi, the widest language, without overflowing.
//
// With --dart-define=N360_FIELD_DIR=/dir it also saves PNGs of each screen.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import 'field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
  });

  testWidgets('every screen fits at large text in Marathi', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 640) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final app = await TestApp.create();
    addTearDown(app.dispose);
    final today = app.services.context.today;
    await app.services.attendance.mark(
      projectId: 1,
      workerId: 1,
      date: today,
      status: 'overtime',
      otHours: Hours.fromInt(2),
    );
    final uuid = await app.services.issues.raise(
      projectId: 1,
      title: 'Crack in column C3',
      priority: 'critical',
      locationNote: 'Grid C, third floor',
      dueDate: '2026-11-01',
    );
    await app.services.pettyCash.addExpense(
      walletId: 1,
      amount: Money.parse('2450.00'),
      entryDate: today,
      category: 'Fuel',
      description: 'Diesel for the mixer',
    );

    final Map<String, Widget> screens = <String, Widget>{
      'muster': const AttendanceScreen(),
      'issue_form': const IssueFormScreen(),
      'issue_detail': IssueDetailScreen(clientUuid: uuid),
      'wallet': const PettyCashScreen(),
      'expense': const ExpenseFormScreen(),
    };

    for (final entry in screens.entries) {
      await tester.pumpWidget(
        fieldApp(app.services, entry.value, locale: const Locale('mr'), textScale: 1.3),
      );
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 600));
      expect(tester.takeException(), isNull, reason: entry.key);
      if (_dir.isNotEmpty) {
        await tester.runAsync(() => capturePng(tester, _dir, 'mr_scaled_${entry.key}'));
      }
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
    await tester.pump(const Duration(seconds: 1));
  });
}
