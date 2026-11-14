import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';

import '../../attendance/view/field_test_support.dart';

void main() {
  testWidgets('wallet shows the preview balance, provisional, and entries', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await app.services.pettyCash.addExpense(
      walletId: 1,
      amount: Money.parse('450.00'),
      entryDate: '2026-11-09',
      category: 'Fuel',
    );

    await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
    await pumpDrift(tester);

    final RollingNumber balance = tester.widget(
      find.descendant(of: find.byType(RollingMoney), matching: find.byType(RollingNumber)),
    );
    expect(balance.value, '12,050.00');
    // Stamps paint their words, so they are found by what they announce.
    expect(find.bySemanticsLabel('Provisional'), findsOneWidget);
    expect(find.text('Fuel'), findsOneWidget);
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('no wallet explains who to ask', (tester) async {
    setPhone(tester);
    final api = FakeApi();
    (api.user['can'] as Map)['petty_cash.manage'] = false;
    final app = await TestApp.create(seed: false, api: api);
    addTearDown(app.dispose);
    app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
    await app.sync();

    await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
    await pumpDrift(tester);

    expect(find.text('You have no wallet on this project'), findsOneWidget);
    expect(find.textContaining('project manager'), findsOneWidget);
  });

  testWidgets('an amount over the balance is stopped with a plain message', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await tester.pumpWidget(fieldApp(app.services, const ExpenseFormScreen()));
    await pumpDrift(tester);

    for (final digit in ['9', '9', '9', '9', '9', '9']) {
      await tester.tap(find.text(digit));
      await tester.pump();
    }

    expect(find.textContaining('top-up'), findsOneWidget);
    await tester.tap(find.text('Next'));
    await tester.pump();
    expect(find.text('What was it for?'), findsNothing);
    await tester.pump(const Duration(seconds: 1));
  });

  testWidgets('saves offline and says so', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, const ExpenseFormScreen()));
    await pumpDrift(tester);

    await tester.tap(find.text('4'));
    await tester.pump();
    await tester.tap(find.text('5'));
    await tester.pump();
    await tester.tap(find.text('0').last);
    await tester.pump();

    await tester.tap(find.text('Next'));
    await pumpDrift(tester);
    await tester.tap(find.text('Fuel'));
    await tester.pump();
    await tester.tap(find.text('Save expense'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Saved on phone. It will send when network returns.'), findsOneWidget);
    expect((await app.db.select(app.db.pettyCashEntries).get()).single.amount, Money.parse('450'));
    await settleToasts(tester);
  });
}
