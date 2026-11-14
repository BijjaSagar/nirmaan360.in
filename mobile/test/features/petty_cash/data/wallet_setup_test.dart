import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/failures.dart';
import 'package:nirmaan360/features/petty_cash/data/petty_cash_repository.dart';

import '../../../support/test_app.dart';

const day = '2026-11-09';

void main() {
  late TestApp app;
  late PettyCashRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.pettyCash;
  });

  tearDown(() => app.dispose());

  Future<WalletLine> wallet() async =>
      (await repo.watchWallets(1).first).single;

  Future<String> topUp(String amount, {String? description}) => repo.addTopUp(
    walletId: 1,
    amount: Money.parse(amount),
    entryDate: day,
    description: description,
  );

  Future<String> spend(String amount) => repo.addExpense(
    walletId: 1,
    amount: Money.parse(amount),
    entryDate: day,
    category: 'Fuel',
  );

  group('createWallet', () {
    test('creates on the server and returns the local row', () async {
      final created = await repo.createWallet(
        projectId: 1,
        name: 'Store cash',
        userId: 4,
      );

      expect(created.name, 'Store cash');
      expect(created.userId, 4);
      expect(created.balance, Money.zero);
      expect(app.api.wallets.last['id'], created.id);
    });

    test('shows at once, with no pull', () async {
      final pulls = app.api.pullCalls.length;

      final created = await repo.createWallet(projectId: 1, name: 'Store');

      final lines = await repo.watchWallets(1).first;
      expect(lines.map((l) => l.wallet.id), contains(created.id));
      expect(app.api.pullCalls.length, pulls);
    });

    test('offline raises OfflineFailure', () async {
      app.connectivity.setOnline(false);

      await expectLater(
        repo.createWallet(projectId: 1, name: 'Store'),
        throwsA(isA<OfflineFailure>()),
      );
      expect(app.api.wallets, hasLength(1));
    });

    test('a lost reply retried makes one server row', () async {
      app.api.loseNextSetupResponse();
      await expectLater(
        repo.createWallet(projectId: 1, name: 'Store'),
        throwsA(isA<OfflineFailure>()),
      );
      expect(app.api.wallets, hasLength(2));

      await repo.createWallet(projectId: 1, name: 'Store');

      expect(app.api.wallets, hasLength(2));
      expect(app.api.setupStatuses, [201, 200]);
      expect(await app.db.select(app.db.wallets).get(), hasLength(2));
    });

    test('server validation surfaces its errors', () async {
      await expectLater(
        repo.createWallet(projectId: 1, name: ' '),
        throwsA(
          isA<ValidationFailure>().having(
            (e) => e.errors.keys,
            'fields',
            contains('name'),
          ),
        ),
      );
    });

    test('a new wallet can take entries straight away', () async {
      final created = await repo.createWallet(projectId: 1, name: 'Store');

      await repo.addTopUp(
        walletId: created.id,
        amount: Money.parse('500.00'),
        entryDate: day,
      );
      await app.sync();

      expect(app.api.wallets.last['balance'], '500.00');
    });
  });

  group('addTopUp', () {
    test('writes the row and an outbox item with wire type topup', () async {
      final uuid = await topUp('1000.00', description: 'From office');

      final row = await (app.db.select(app.db.pettyCashEntries)
            ..where((t) => t.clientUuid.equals(uuid)))
          .getSingle();
      expect(row.type, 'topup');
      expect(row.amount, Money.parse('1000.00'));

      final outbox = await app.db.select(app.db.outbox).getSingle();
      expect(outbox.type, OutboxType.pettyCash);
      expect(jsonDecode(outbox.payload)['type'], 'topup');
      expect(jsonDecode(outbox.payload)['wallet_id'], 1);
    });

    test('offline, then synced exactly once, with a right balance', () async {
      app.api.offline = true;
      app.connectivity.setOnline(false);

      await topUp('1000.00');
      await app.sync();

      expect(app.api.pettyEntries, isEmpty);
      expect((await wallet()).previewBalance, Money.parse('13500.00'));

      app.api.offline = false;
      app.connectivity.setOnline(true);
      app.api.loseNextPushResponse();
      await app.services.sync.syncNow();
      await app.services.sync.syncNow();

      expect(app.api.pettyEntries, hasLength(1));
      expect(app.api.pettyEntries.single['type'], 'topup');
      expect(app.api.wallets.single['balance'], '13500.00');

      final line = await wallet();
      expect(line.serverBalance, Money.parse('13500.00'));
      expect(line.unsentTopUps, Money.zero);
      expect(line.previewBalance, Money.parse('13500.00'));

      final row = await app.db.select(app.db.outbox).getSingle();
      expect(row.status, OutboxStatus.done);
      expect(row.resultStatus, 'duplicate');
    });

    test('is listed with the wallet entries', () async {
      await topUp('10.00');

      final entries = await repo.watchEntries(1).first;

      expect(entries.single.record.type, 'topup');
      expect(entries.single.syncState, LocalSyncState.queued);
    });

    test('needs no category', () async {
      await topUp('10.00');
      await app.sync();

      expect(app.api.pettyEntries.single['category'], isNull);
      expect(
        (await app.db.select(app.db.outbox).getSingle()).status,
        OutboxStatus.done,
      );
    });

    test('refuses a zero amount', () {
      expect(() => topUp('0.00'), throwsA(isA<ValidationFailure>()));
    });

    test('refuses a future date', () {
      expect(
        () => repo.addTopUp(
          walletId: 1,
          amount: Money.parse('1.00'),
          entryDate: '2026-11-10',
        ),
        throwsA(isA<ValidationFailure>()),
      );
    });

    test('an unknown wallet is refused', () {
      expect(
        () => repo.addTopUp(
          walletId: 99,
          amount: Money.parse('1.00'),
          entryDate: day,
        ),
        throwsA(isA<RecordLocked>()),
      );
    });

    test('is not counted as an expense on the home screen', () async {
      await topUp('1000.00');
      await spend('10.00');

      final summary = await app.services.home.watchHomeSummary(1).first;

      expect(summary.expensesToday, Money.parse('10.00'));
    });
  });

  group('previewBalance', () {
    test('adds an unsent top-up and subtracts an unsent expense', () async {
      await topUp('1000.00');
      await spend('450.00');

      final line = await wallet();

      expect(line.serverBalance, Money.parse('12500.00'));
      expect(line.unsentTopUps, Money.parse('1000.00'));
      expect(line.unsentExpenses, Money.parse('450.00'));
      expect(line.previewBalance, Money.parse('13050.00'));
    });

    test('is the same before and after the sync', () async {
      await topUp('1000.00');
      await spend('450.00');
      final before = (await wallet()).previewBalance;

      await app.sync();

      final after = await wallet();
      expect(after.previewBalance, before);
      expect(after.serverBalance, before);
      expect(after.unsentTopUps, Money.zero);
      expect(after.unsentExpenses, Money.zero);
    });

    test('stays right when a push landed but its pull failed', () async {
      await topUp('1000.00');
      app.api.failNextPull();
      await app.services.sync.syncNow();

      expect((await wallet()).previewBalance, Money.parse('13500.00'));
    });

    test('sums paise exactly', () async {
      await topUp('0.10');
      await topUp('0.20');

      expect((await wallet()).unsentTopUps.toWire(), '0.30');
    });

    test('updates live', () async {
      final seen = <Money>[];
      final subscription = repo
          .watchWallets(1)
          .listen((lines) => seen.add(lines.single.previewBalance));
      addTearDown(subscription.cancel);
      await pumpEventQueue();

      await topUp('100.00');
      await pumpEventQueue();

      expect(seen.last, Money.parse('12600.00'));
    });

    test('discarding a failed top-up removes it from the preview', () async {
      await topUp('1000.00');
      app.api.scriptPushStatuses(['forbidden']);
      await app.sync();
      final row = await app.db.select(app.db.outbox).getSingle();
      expect(row.status, OutboxStatus.attention);
      expect((await wallet()).previewBalance, Money.parse('13500.00'));

      await app.services.syncCenter.discard(row.id);

      expect((await wallet()).previewBalance, Money.parse('12500.00'));
    });
  });
}
