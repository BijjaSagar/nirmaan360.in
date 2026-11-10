import 'dart:io';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/offline/offline_plan.dart' show LocalRecordKind;
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/pull_applier.dart';
import '../../../core/sync/record_sync.dart';

export '../../../core/db/app_database.dart' show PettyCashEntryRow, WalletRow;
export '../../../core/sync/record_sync.dart' show SyncedRecord, LocalSyncState;

/// A wallet with the server's balance and the local preview.
class WalletLine {
  const WalletLine({
    required this.wallet,
    required this.unsentExpenses,
    required this.unsentTopUps,
  });

  final WalletRow wallet;

  /// Expenses on this phone the server balance does not include yet.
  final Money unsentExpenses;

  /// Top-ups on this phone the server balance does not include yet.
  final Money unsentTopUps;

  Money get serverBalance => wallet.balance;

  /// Server balance plus unsent top-ups, minus unsent expenses.
  Money get previewBalance => wallet.balance + unsentTopUps - unsentExpenses;
}

const _typeExpense = 'expense';
const _typeTopUp = 'topup';

class PettyCashRepository {
  PettyCashRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  Stream<List<WalletLine>> watchWallets(int projectId) {
    return _db.watchDerived({_db.wallets, _db.pettyCashEntries}, () async {
      final wallets =
          await (_db.select(_db.wallets)
                ..where((t) => t.projectId.equals(projectId))
                ..orderBy([(t) => OrderingTerm.asc(t.name)]))
              .get();
      final walletIds = wallets.map((w) => w.id).toList();
      final entries =
          await (_db.select(_db.pettyCashEntries)..where(
                (t) =>
                    t.walletId.isIn(walletIds) &
                    t.countedInBalance.equals(false),
              ))
              .get();

      final expenses = <int, Money>{};
      final topUps = <int, Money>{};
      for (final entry in entries) {
        final totals = switch (entry.type) {
          _typeExpense => expenses,
          _typeTopUp => topUps,
          _ => null,
        };
        if (totals == null) continue;
        totals[entry.walletId] =
            (totals[entry.walletId] ?? Money.zero) + entry.amount;
      }
      return [
        for (final wallet in wallets)
          WalletLine(
            wallet: wallet,
            unsentExpenses: expenses[wallet.id] ?? Money.zero,
            unsentTopUps: topUps[wallet.id] ?? Money.zero,
          ),
      ];
    });
  }

  /// Records an expense and queues it with its bill photo, if any. Returns
  /// the entry's uuid. The photo is copied before anything is written.
  Future<String> addExpense({
    required int walletId,
    required Money amount,
    required String entryDate,
    String? category,
    String? description,
    String? billSourcePath,
  }) async {
    _assertEntry(amount, entryDate);
    if (category == null || category.trim().isEmpty) {
      throw const ValidationFailure(
        'Choose a category.',
        errors: {
          'category': ['Choose a category.'],
        },
      );
    }
    final wallet = await _requireWallet(walletId);

    final uuid = newUuid();
    final billUuid = newUuid();

    String? billPath;
    if (billSourcePath != null) {
      final extension = p.extension(billSourcePath);
      billPath = await _ctx.attachments.importFile(
        billSourcePath,
        folder: 'petty_bills',
        fileName: '$billUuid${extension.isEmpty ? '.jpg' : extension}',
      );
    }

    await _db.transaction(() async {
      final now = _ctx.clock.now();
      await _writeEntry(
        uuid: uuid,
        wallet: wallet,
        type: _typeExpense,
        amount: amount,
        entryDate: entryDate,
        category: category,
        description: description,
        billPath: billPath,
        subtitle: category,
      );
      if (billPath != null) {
        await _ctx.outbox.enqueue(
          type: OutboxType.pettyBill,
          wireUuid: billUuid,
          recordUuid: uuid,
          groupUuid: uuid,
          projectId: wallet.projectId,
          data: const {},
          filePath: billPath,
          parentUuid: uuid,
          createdAt: now,
          title: amount.inr(),
          subtitle: category,
        );
      }
    });
    return uuid;
  }

  /// Records money added to a wallet and queues it, so it works offline.
  /// Returns the entry's uuid.
  Future<String> addTopUp({
    required int walletId,
    required Money amount,
    required String entryDate,
    String? description,
  }) async {
    _assertEntry(amount, entryDate);
    final wallet = await _requireWallet(walletId);

    final uuid = newUuid();
    await _db.transaction(
      () => _writeEntry(
        uuid: uuid,
        wallet: wallet,
        type: _typeTopUp,
        amount: amount,
        entryDate: entryDate,
        description: description,
        subtitle: description,
      ),
    );
    return uuid;
  }

  /// Creates a wallet on the server and stores it locally. Online only.
  ///
  /// Throws `OfflineFailure` when offline. Safe to retry after a lost reply.
  Future<WalletRow> createWallet({
    required int projectId,
    required String name,
    int? userId,
  }) {
    if (_ctx.offlinePlan.isLocalOnly) {
      _ctx.assertWithin(<(String, String?, int)>[('name', name, 120)]);
      return _ctx.createLocal(
        kind: LocalRecordKind.wallet,
        table: 'wallets',
        insert: (id) async {
          await _db.into(_db.wallets).insert(
            WalletsCompanion.insert(
              id: Value(id),
              projectId: projectId,
              name: name,
              userId: Value(userId),
              balance: Money.zero,
              updatedAt: Value(_ctx.clock.now()),
            ),
          );
          return (_db.select(_db.wallets)..where((t) => t.id.equals(id))).getSingle();
        },
      );
    }
    return _ctx.createOnline(
      identity: ['wallet', projectId, name],
      send: (clientUuid) => _ctx.api.createWallet(
        projectId: projectId,
        clientUuid: clientUuid,
        name: name,
        userId: userId,
      ),
      store: (json) async {
        final row = PullApplier.walletCompanion(json);
        await _db.into(_db.wallets).insertOnConflictUpdate(row);
        return (_db.select(_db.wallets)
              ..where((t) => t.id.equals(row.id.value)))
            .getSingle();
      },
    );
  }

  /// Entries for a wallet, newest first.
  Stream<List<SyncedRecord<PettyCashEntryRow>>> watchEntries(int walletId) {
    return _db.watchDerived({_db.pettyCashEntries, _db.outbox}, () async {
      final rows =
          await (_db.select(_db.pettyCashEntries)
                ..where((t) => t.walletId.equals(walletId))
                ..orderBy([
                  (t) => OrderingTerm.desc(t.entryDate),
                  (t) => OrderingTerm.desc(t.updatedAt),
                ]))
              .get();
      final states = await SyncStates.load(_db);
      return [for (final row in rows) states.wrap(row, row.clientUuid)];
    });
  }

  void _assertEntry(Money amount, String entryDate) {
    _ctx.assertNotFuture(entryDate, 'entry_date');
    if (amount <= Money.zero) {
      throw const ValidationFailure(
        'The amount must be more than zero.',
        errors: {
          'amount': ['The amount must be more than zero.'],
        },
      );
    }
  }

  Future<WalletRow> _requireWallet(int walletId) async {
    final wallet =
        await (_db.select(_db.wallets)..where((t) => t.id.equals(walletId)))
            .getSingleOrNull();
    if (wallet == null) throw RecordLocked('Wallet $walletId was not found.');
    return wallet;
  }

  /// The local row and its outbox row. Call inside a transaction.
  Future<void> _writeEntry({
    required String uuid,
    required WalletRow wallet,
    required String type,
    required Money amount,
    required String entryDate,
    required String? subtitle,
    String? category,
    String? description,
    String? billPath,
  }) async {
    await _db.into(_db.pettyCashEntries).insert(
      PettyCashEntriesCompanion.insert(
        clientUuid: uuid,
        walletId: wallet.id,
        projectId: Value(wallet.projectId),
        type: Value(type),
        amount: amount,
        category: Value(category),
        description: Value(description),
        entryDate: entryDate,
        createdBy: Value(_ctx.currentUserId),
        hasBill: Value(billPath != null),
        billRelativePath: Value(billPath),
      ),
    );
    await _ctx.outbox.enqueue(
      type: OutboxType.pettyCash,
      wireUuid: uuid,
      recordUuid: uuid,
      groupUuid: uuid,
      projectId: wallet.projectId,
      data: {
        'wallet_id': wallet.id,
        'type': type,
        'amount': amount.toWire(),
        'category': category,
        'description': description,
        'entry_date': entryDate,
      },
      clientUpdatedAt: _ctx.nextStamp(const []),
      createdAt: _ctx.clock.now(),
      title: amount.inr(),
      subtitle: subtitle,
    );
  }

  /// Bytes of a bill: the local copy, or the server's.
  Future<Uint8List> billBytes(PettyCashEntryRow entry) async {
    final relative = entry.billRelativePath;
    if (relative != null && await _ctx.attachments.exists(relative)) {
      return File(await _ctx.attachments.absolutePath(relative)).readAsBytes();
    }
    final serverId = entry.serverId;
    if (serverId == null) {
      throw const RecordLocked('This bill is not available yet.');
    }
    return _ctx.api.pettyBillBytes(serverId);
  }
}
