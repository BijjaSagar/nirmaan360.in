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
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';

import '../../../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../../attendance/view/field_test_support.dart';

const String _dir = String.fromEnvironment('N360_FIELD_DIR');

const List<(String, Locale)> _locales = <(String, Locale)>[
  ('en', Locale('en')),
  ('mr', Locale('mr')),
];

Future<TestApp> _seeded({bool twoWallets = false, bool unsent = true}) async {
  final app = await TestApp.create();
  if (twoWallets) {
    app.api.addWallet(id: 2, projectId: 1, balance: '4200.00', userId: 4);
    await app.sync();
  }
  final cash = app.services.pettyCash;
  await cash.addExpense(walletId: 1, amount: Money.parse('2450.00'), entryDate: '2026-11-09', category: 'Fuel', description: 'Diesel for the mixer');
  await cash.addExpense(walletId: 1, amount: Money.parse('850.50'), entryDate: '2026-11-09', category: 'Food', description: 'Tea and lunch for crew');
  await cash.addExpense(walletId: 1, amount: Money.parse('1200.00'), entryDate: '2026-11-08', category: 'Tools');
  await cash.addTopUp(walletId: 1, amount: Money.parse('5000.00'), entryDate: '2026-11-07', description: 'Cash from office');
  await cash.addExpense(walletId: 1, amount: Money.parse('300.00'), entryDate: '2026-11-07', category: 'Travel', description: 'Auto to the depot');
  await app.sync();
  if (unsent) {
    await cash.addExpense(walletId: 1, amount: Money.parse('450.00'), entryDate: '2026-11-09', category: 'Materials', description: 'Binding wire');
  }
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

  Future<void> settle(WidgetTester tester, [int ms = 600]) async {
    await pumpDrift(tester);
    await tester.pump(Duration(milliseconds: ms));
  }

  Future<void> typeAmount(WidgetTester tester, List<String> keys) async {
    for (final key in keys) {
      await tester.tap(find.text(key).first);
      await tester.pump();
    }
    await tester.pump(const Duration(milliseconds: 400));
  }

  testWidgets('wallet, expense and top-up screens', (tester) async {
    phone(tester);
    final app = await _seeded(twoWallets: true);
    addTearDown(app.dispose);

    for (final (name, locale) in _locales) {
      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen(), locale: locale));
      if (name == 'en') await capture(tester, 'en_petty_wallet_loading');
      await settle(tester, 800);
      await capture(tester, '${name}_petty_wallet');
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();

      await tester.pumpWidget(fieldApp(app.services, ExpenseFormScreen(captureBill: () async => '/no/such/bill.jpg'), locale: locale));
      await settle(tester, 500);
      await typeAmount(tester, ['1', '5', '5', '0']);
      await capture(tester, '${name}_expense_amount');

      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे').first);
      await settle(tester, 500);
      await capture(tester, '${name}_expense_details');

      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Photo of bill' : 'बिलाचा फोटो').first);
      await settle(tester, 500);
      await capture(tester, '${name}_expense_bill_added');
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();

      await tester.pumpWidget(fieldApp(app.services, const TopUpScreen(), locale: locale));
      await settle(tester, 500);
      await typeAmount(tester, ['5', '0', '0', '0']);
      await capture(tester, '${name}_topup_amount');
      await tester.tap(find.bySemanticsLabel(name == 'en' ? 'Next' : 'पुढे').first);
      await settle(tester, 500);
      await capture(tester, '${name}_topup_details');
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }

    // Over-balance message.
    await tester.pumpWidget(fieldApp(app.services, const ExpenseFormScreen()));
    await settle(tester, 500);
    await typeAmount(tester, ['9', '9', '9', '9', '9', '9']);
    await capture(tester, 'en_expense_over_balance');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('a clean wallet, and one with no entries', (tester) async {
    phone(tester);
    final clean = await _seeded(unsent: false);
    addTearDown(clean.dispose);
    await tester.pumpWidget(fieldApp(clean.services, const PettyCashScreen()));
    await settle(tester, 800);
    await capture(tester, 'en_petty_wallet_clean');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();

    final empty = await TestApp.create();
    addTearDown(empty.dispose);
    await tester.pumpWidget(fieldApp(empty.services, const PettyCashScreen(), locale: const Locale('mr')));
    await settle(tester, 800);
    await capture(tester, 'mr_petty_entries_empty');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('no wallet state and the wallet form', (tester) async {
    phone(tester);
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
    app.api.users.add({'id': 4, 'name': 'Sachin More', 'role': 'engineer'});
    app.api.users.add({'id': 5, 'name': 'Anil Gaikwad', 'role': 'engineer'});
    await app.sync();

    await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
    await settle(tester, 500);
    await capture(tester, 'en_petty_no_wallet');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();

    await tester.pumpWidget(fieldApp(app.services, const WalletFormScreen()));
    await settle(tester, 500);
    await capture(tester, 'en_wallet_form_details');
    await tester.tap(find.text('Sachin More'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await capture(tester, 'en_wallet_form_holder_sheet');
    await tester.tapAt(const Offset(180, 60));
    await tester.pump(const Duration(milliseconds: 500));
    await tester.tap(find.bySemanticsLabel('Next').first);
    await settle(tester, 500);
    await capture(tester, 'en_wallet_form_amount');
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(seconds: 1));
  });
}
