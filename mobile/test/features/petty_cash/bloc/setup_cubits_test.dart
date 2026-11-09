import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/petty_cash/bloc/top_up_cubit.dart';
import 'package:nirmaan360/features/petty_cash/bloc/wallet_form_cubit.dart';

import '../../attendance/view/field_test_support.dart';

Future<List<OutboxRow>> _outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<WalletFormCubit> _readyWallet(TestApp app) async {
  final cubit = WalletFormCubit(app.services);
  await cubit.stream.firstWhere((s) => s.project != null && s.users.isNotEmpty);
  return cubit;
}

Future<TopUpCubit> _readyTopUp(TestApp app) async {
  final cubit = TopUpCubit(app.services);
  await cubit.stream.firstWhere((s) => s.wallet != null);
  return cubit;
}

void main() {
  test('holder defaults to the signed-in user', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyWallet(app);
    addTearDown(cubit.close);

    expect(cubit.state.holderId, 4);
    expect(cubit.state.holderName, 'Sachin More');
  });

  test('a wallet with an opening amount is created, then topped up', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyWallet(app);
    addTearDown(cubit.close);

    cubit
      ..setName('Block A cash')
      ..next()
      ..setAmount('5000');
    expect(await cubit.create(openingNote: 'Opening balance'), isTrue);

    expect(cubit.state.done, isTrue);
    expect(cubit.state.openingSaved, isTrue);
    expect(cubit.state.openingAmount, Money.parse('5000'));
    expect(app.api.wallets.where((w) => w['name'] == 'Block A cash'), hasLength(1));

    final outbox = await _outbox(app);
    expect(outbox.single.type, OutboxType.pettyCash);
    expect(outbox.single.payload, contains('"type":"topup"'));
    expect(outbox.single.payload, contains('"amount":"5000.00"'));
  });

  test('no opening amount means no top-up', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyWallet(app);
    addTearDown(cubit.close);

    cubit
      ..setName('Block A cash')
      ..next();
    expect(await cubit.create(openingNote: 'Opening balance'), isTrue);

    expect(cubit.state.openingAmount, isNull);
    expect(await _outbox(app), isEmpty);
  });

  test('a wallet cannot be created offline', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyWallet(app);
    addTearDown(cubit.close);
    final int before = app.api.wallets.length;

    app.connectivity.setOnline(false);
    cubit
      ..setName('Block A cash')
      ..next()
      ..setAmount('5000');
    expect(await cubit.create(openingNote: 'Opening balance'), isFalse);

    expect(cubit.state.problem, WalletFormProblem.offline);
    expect(app.api.wallets, hasLength(before));
    expect(await _outbox(app), isEmpty, reason: 'no money without a wallet');
  });

  test('a wallet name is required', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyWallet(app);
    addTearDown(cubit.close);

    cubit.next();

    expect(cubit.state.problem, WalletFormProblem.nameRequired);
    expect(cubit.state.step, 0);
  });

  test('a top-up works offline, queues and raises the preview balance', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyTopUp(app);
    addTearDown(cubit.close);
    app.connectivity.setOnline(false);

    cubit
      ..setAmount('2000.50')
      ..next()
      ..setNote('Cash from office');
    expect(await cubit.submit(), isTrue);

    expect(cubit.state.saved, isTrue);
    expect(cubit.state.savedWhileOffline, isTrue);

    final outbox = await _outbox(app);
    expect(outbox.single.type, OutboxType.pettyCash);
    expect(outbox.single.payload, contains('"type":"topup"'));

    final line = (await app.services.pettyCash.watchWallets(1).first).single;
    expect(line.previewBalance, Money.parse('14500.50'));
  });

  test('a top-up needs an amount above zero', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = await _readyTopUp(app);
    addTearDown(cubit.close);

    cubit.setAmount('0');
    expect(cubit.state.amountReady, isFalse);
    cubit.next();

    expect(cubit.state.problem, TopUpProblem.amountRequired);
    expect(await _outbox(app), isEmpty);
  });
}
