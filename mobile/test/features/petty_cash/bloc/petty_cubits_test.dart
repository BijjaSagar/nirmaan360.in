import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/features/petty_cash/bloc/expense_form_cubit.dart';
import 'package:nirmaan360/features/petty_cash/bloc/petty_cash_cubit.dart';

import '../../attendance/view/field_test_support.dart';

Future<ExpenseFormCubit> _readyForm(TestApp app, {Future<String?> Function()? capture}) async {
  final cubit = ExpenseFormCubit(app.services, captureBill: capture);
  await cubit.stream.firstWhere((s) => s.wallet != null);
  return cubit;
}

void main() {
  test('an expense over the balance is stopped and nothing is queued', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);

    cubit.setAmount('13000');
    expect(cubit.state.isOverBalance, isTrue);
    expect(cubit.state.amountReady, isFalse);

    cubit.continueFromAmount();
    expect(cubit.state.problem, ExpenseProblem.overBalance);
    expect(cubit.state.step, 0);

    expect(await cubit.submit(), isFalse);
    expect(cubit.state.problem, ExpenseProblem.overBalance);
    expect(await app.db.select(app.db.pettyCashEntries).get(), isEmpty);
    expect(await app.db.select(app.db.outbox).get(), isEmpty);
  });

  test('unsent expenses on the phone count against the balance', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await app.services.pettyCash.addExpense(
      walletId: 1,
      amount: Money.parse('12000'),
      entryDate: '2026-11-09',
      category: 'Fuel',
    );

    final cubit = await _readyForm(app);
    addTearDown(cubit.close);
    cubit.setAmount('600');

    expect(cubit.state.isOverBalance, isTrue);
  });

  test('an expense with a bill queues the entry then its upload', () async {
    final temp = await Directory.systemTemp.createTemp('n360-bill');
    addTearDown(() => temp.delete(recursive: true));
    final source = File('${temp.path}/bill.jpg')..writeAsBytesSync(List<int>.filled(64, 7));
    final store = DirectoryAttachmentStore(() async => temp.path);

    final app = await TestApp.create(attachments: store);
    addTearDown(app.dispose);
    final cubit = await _readyForm(app, capture: () async => source.path);
    addTearDown(cubit.close);

    cubit.setAmount('450.50');
    cubit.continueFromAmount();
    expect(cubit.state.step, 1);
    cubit.setCategory('Fuel');
    cubit.setNote('Diesel');
    await cubit.takeBillPhoto();
    expect(await cubit.submit(), isTrue);

    final outbox = await (app.db.select(app.db.outbox)).get();
    expect(outbox.map((r) => r.type).toList(), [OutboxType.pettyCash, OutboxType.pettyBill]);

    final entry = (await app.db.select(app.db.pettyCashEntries).get()).single;
    expect(entry.amount, Money.parse('450.50'));
    expect(entry.hasBill, isTrue);
    expect(entry.category, 'Fuel');
    expect(entry.description, 'Diesel');
    expect(entry.billRelativePath, isNotNull);
    expect(await store.exists(entry.billRelativePath!), isTrue);
  });

  test('wallet screen picks the user wallet and switches', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.api.addWallet(id: 2, projectId: 1, balance: '100.00', userId: 9);
    await app.sync();

    final cubit = PettyCashCubit(app.services);
    addTearDown(cubit.close);
    final ready = await cubit.stream.firstWhere(
      (s) => s.load == PettyCashLoad.ready && s.wallets.length == 2,
    );
    expect(ready.selectedWalletId, 1);

    await cubit.selectWallet(2);
    expect(cubit.state.selectedWalletId, 2);
  });
}
