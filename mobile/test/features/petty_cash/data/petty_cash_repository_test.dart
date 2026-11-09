import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
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

  Future<String> spend(String amount, {String? bill}) => repo.addExpense(
    walletId: 1,
    amount: Money.parse(amount),
    entryDate: day,
    category: 'Fuel',
    billSourcePath: bill,
  );

  group('wallet preview', () {
    test('subtracts unsent expenses from the server balance', () async {
      await spend('450.00');

      final line = (await repo.watchWallets(1).first).single;

      expect(line.serverBalance, Money.parse('12500.00'));
      expect(line.unsentExpenses, Money.parse('450.00'));
      expect(line.previewBalance, Money.parse('12050.00'));
    });

    test('is not counted twice after a pull', () async {
      await spend('450.00');
      await app.sync();

      final line = (await repo.watchWallets(1).first).single;

      expect(line.serverBalance, Money.parse('12050.00'));
      expect(line.unsentExpenses, Money.zero);
      expect(line.previewBalance, Money.parse('12050.00'));
    });

    test('stays right when a push landed but its pull failed', () async {
      await spend('450.00');
      app.api.failNextPull();
      await app.services.sync.syncNow();

      final line = (await repo.watchWallets(1).first).single;

      // The stale balance is offset by the unconfirmed local expense.
      expect(line.previewBalance, Money.parse('12050.00'));
    });

    test('sums rupees and paise exactly', () async {
      await spend('0.10');
      await spend('0.20');

      final line = (await repo.watchWallets(1).first).single;

      expect(line.unsentExpenses.toWire(), '0.30');
    });
  });

  test('an expense is exactly one server row after a lost response', () async {
    await spend('100.00');
    app.api.loseNextPushResponse();
    await app.services.sync.syncNow();
    await app.services.sync.syncNow();

    expect(app.api.pettyEntries, hasLength(1));
  });

  test('refuses a zero amount', () {
    expect(() => spend('0.00'), throwsA(isA<ValidationFailure>()));
  });

  test('entries list newest first with a sync badge', () async {
    await spend('10.00');

    final entries = await repo.watchEntries(1).first;

    expect(entries.single.syncState, LocalSyncState.queued);
  });

  group('bill photo', () {
    late Directory temp;
    late TestApp files;
    late File source;

    setUp(() async {
      temp = await Directory.systemTemp.createTemp('n360');
      final store = DirectoryAttachmentStore(() async => temp.path);
      files = await TestApp.create(attachments: store);
      source = File('${temp.path}/bill.jpg')..writeAsBytesSync([9]);
    });

    tearDown(() async {
      await files.dispose();
      await temp.delete(recursive: true);
    });

    test('goes after its entry, then the file is removed', () async {
      final uuid = await files.services.pettyCash.addExpense(
        walletId: 1,
        amount: Money.parse('100.00'),
        entryDate: day,
        category: 'Fuel',
        billSourcePath: source.path,
      );

      final rows = await files.db.select(files.db.outbox).get();
      expect(rows.map((r) => r.type), [
        OutboxType.pettyCash,
        OutboxType.pettyBill,
      ]);
      final path = rows.last.filePath!;
      expect(path.startsWith('/'), isFalse);

      await files.services.sync.syncNow();

      expect(files.api.uploadCalls.single.parentClientUuid, isNull);
      expect(files.api.uploadCalls.single.parentId, isNotNull);
      final entry = await (files.db.select(files.db.pettyCashEntries)
            ..where((t) => t.clientUuid.equals(uuid)))
          .getSingle();
      expect(entry.hasBill, isTrue);
      expect(entry.billRelativePath, isNull);
    });
  });
}
