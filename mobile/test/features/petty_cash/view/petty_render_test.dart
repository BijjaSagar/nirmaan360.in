// Renders the petty cash screens to PNG with real fonts, for a person to judge.
//
// flutter test test/features/petty_cash/view/petty_render_test.dart \
//   --dart-define=N360_FIELD_DIR=/absolute/output/dir
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

Future<TestApp> _seeded({bool twoWallets = false, bool noWallet = false}) async {
  final app = await TestApp.create();
  if (noWallet) {
    return app;
  }
  if (twoWallets) {
    app.api.addWallet(id: 2, projectId: 1, balance: '4200.00', userId: 4);
    await app.sync();
  }
  final cash = app.services.pettyCash;
  await cash.addExpense(walletId: 1, amount: Money.parse('2450.00'), entryDate: '2026-11-09', category: 'Fuel', description: 'Diesel for the mixer');
  await cash.addExpense(walletId: 1, amount: Money.parse('850.50'), entryDate: '2026-11-09', category: 'Food', description: 'Tea and lunch for crew');
  await cash.addExpense(walletId: 1, amount: Money.parse('1200.00'), entryDate: '2026-11-08', category: 'Tools');
  await cash.addExpense(walletId: 1, amount: Money.parse('300.00'), entryDate: '2026-11-07', category: 'Travel', description: 'Auto to the depot');
  await app.sync();
  await cash.addExpense(walletId: 1, amount: Money.parse('450.00'), entryDate: '2026-11-09', category: 'Materials', description: 'Binding wire');
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

  testWidgets('petty cash screens', (tester) async {
    phone(tester);
    final app = await _seeded(twoWallets: true);
    addTearDown(app.dispose);

    for (final (name, locale) in <(String, Locale)>[('en', const Locale('en')), ('mr', const Locale('mr'))]) {
      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen(), locale: locale));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 800));
      await capture(tester, '${name}_petty_wallet');

      await tester.pumpWidget(fieldApp(app.services, const ExpenseFormScreen(), locale: locale));
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 500));
      await tester.tap(find.text('1'));
      await tester.pump();
      await tester.tap(find.text('5'));
      await tester.pump();
      await tester.tap(find.text('5'));
      await tester.pump();
      await tester.tap(find.text('0').first);
      await tester.pump(const Duration(milliseconds: 500));
      await capture(tester, '${name}_expense_amount');

      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे').first);
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 500));
      await capture(tester, '${name}_expense_details');

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }

    // Over-balance message.
    await tester.pumpWidget(fieldApp(app.services, const ExpenseFormScreen()));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 500));
    for (final key in ['9', '9', '9', '9', '9', '9']) {
      await tester.tap(find.text(key));
      await tester.pump();
    }
    await tester.pump(const Duration(milliseconds: 500));
    await capture(tester, 'en_expense_over_balance');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('no wallet state', (tester) async {
    phone(tester);
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
    await app.sync();

    await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 500));
    await capture(tester, 'en_petty_no_wallet');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });
}
