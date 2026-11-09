import 'dart:io';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/record_sync.dart';

export '../../../core/db/app_database.dart' show PettyCashEntryRow, WalletRow;
export '../../../core/sync/record_sync.dart' show SyncedRecord, LocalSyncState;

/// A wallet with the server's balance and the local preview.
class WalletLine {
  const WalletLine({required this.wallet, required this.unsentExpenses});

  final WalletRow wallet;

  /// Expenses on this phone the server balance does not include yet.
  final Money unsentExpenses;

  Money get serverBalance => wallet.balance;

  /// Server balance minus unsent local expenses.
  Money get previewBalance => wallet.balance - unsentExpenses;
}

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

      final unsent = <int, Money>{};
      for (final entry in entries) {
        if (entry.type != 'expense') continue;
        unsent[entry.walletId] =
            (unsent[entry.walletId] ?? Money.zero) + entry.amount;
      }
      return [
        for (final wallet in wallets)
          WalletLine(
            wallet: wallet,
            unsentExpenses: unsent[wallet.id] ?? Money.zero,
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
    _ctx.assertNotFuture(entryDate, 'entry_date');
    if (amount <= Money.zero) {
      throw const ValidationFailure(
        'The amount must be more than zero.',
        errors: {
          'amount': ['The amount must be more than zero.'],
        },
      );
    }
    if (category == null || category.trim().isEmpty) {
      throw const ValidationFailure(
        'Choose a category.',
        errors: {
          'category': ['Choose a category.'],
        },
      );
    }
    final wallet =
        await (_db.select(_db.wallets)..where((t) => t.id.equals(walletId)))
            .getSingleOrNull();
    if (wallet == null) throw RecordLocked('Wallet $walletId was not found.');

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
      await _db.into(_db.pettyCashEntries).insert(
        PettyCashEntriesCompanion.insert(
          clientUuid: uuid,
          walletId: walletId,
          projectId: Value(wallet.projectId),
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
          'wallet_id': walletId,
          'type': 'expense',
          'amount': amount.toWire(),
          'category': category,
          'description': description,
          'entry_date': entryDate,
        },
        clientUpdatedAt: _ctx.nextStamp(const []),
        createdAt: now,
        title: amount.inr(),
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
