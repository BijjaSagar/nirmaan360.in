import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import 'material_loop_repository.dart';
import 'material_models.dart';

export 'material_models.dart';

/// Material on site works offline. What the server last said is kept as JSON,
/// and deliveries, uses and counts wait in the outbox until they can be sent.
class MaterialRepository {
  MaterialRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  static const List<OutboxType> _types = <OutboxType>[
    OutboxType.materialChallan,
    OutboxType.materialUse,
    OutboxType.stockCount,
    OutboxType.goodsReceipt,
    OutboxType.materialIssue,
  ];

  /// The server's own limits, from `GrnService::rules` and `MaterialIssueService::rules`.
  static const int challanNoMax = 60;
  static const int vehicleNoMax = 30;
  static const int remarksMax = 2000;
  static const int issuedToMax = 160;
  static const int activityMax = 160;
  static const int issueLinesMax = 200;

  AppDatabase get _db => _ctx.db;

  /// The online half: reasons, a challan in full, bills, shorts found later and wastage.
  late final MaterialLoopRepository loop = MaterialLoopRepository(_ctx, this);

  /// Receiving against a purchase order and issuing from a store. Only
  /// `procurement.stock` reaches the app, and every role that has it also reads
  /// the purchasing lists. A contractor login is never offered either.
  bool get isStockOpenToUser {
    final user = _ctx.session.user;
    return user != null && !user.isContractor && user.can('procurement.stock') && user.features.contains('procurement');
  }

  Stream<MaterialOverview?> watchCached(int projectId) =>
      _db.watchMeta(MetaKey.materialOverview(projectId)).map(_decode);

  SimpleSelectStatement<$OutboxTable, OutboxRow> _unsentQuery(int projectId) {
    final names = _types.map((type) => type.name).toList();
    return _db.select(_db.outbox)
      ..where(
        (t) =>
            t.projectId.equals(projectId) &
            t.type.isIn(names) &
            t.status.equals(OutboxStatus.done.name).not(),
      )
      ..orderBy([(t) => OrderingTerm.asc(t.id)]);
  }

  /// Oldest first, so a count made after a delivery replaces it.
  Stream<List<OutboxRow>> watchUnsent(int projectId) => _unsentQuery(projectId).watch();

  /// The last copy on this phone with unsent work laid over it, read once.
  Future<MaterialOverview?> readOverview(int projectId) async {
    final MaterialOverview? cached = _decode(await _db.readMeta(MetaKey.materialOverview(projectId)));
    return cached?.withUnsent(await _unsentQuery(projectId).get());
  }

  /// BOQ lines for "where was it used", from this phone.
  Future<List<BoqItemRow>> readBoqLines(int projectId) {
    return (_db.select(_db.boqItems)
          ..where((t) => t.projectId.equals(projectId))
          ..orderBy([(t) => OrderingTerm.asc(t.sort)]))
        .get();
  }

  MaterialOverview? _decode(String? raw) {
    if (raw == null) return null;
    return MaterialOverview.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
  }

  /// The material list comes from purchasing, which a custom role may not have.
  /// The rest of the page still works without it.
  Future<Map<String, dynamic>> _snapshotOrEmpty(int projectId) async {
    try {
      return await _ctx.api.procurementSnapshot(projectId);
    } on ServerFailure catch (e) {
      if (e.statusCode == 403 || e.statusCode == 404) return const <String, dynamic>{};
      rethrow;
    }
  }

  /// Throws an [ApiFailure] when the server cannot be reached, leaving the
  /// last copy in place.
  Future<void> refresh(int projectId) async {
    final api = _ctx.api;
    final bool stock = isStockOpenToUser;
    final results = await Future.wait<Object>(<Future<Object>>[
      api.materialCheck(projectId),
      api.materialChallans(projectId),
      api.materialConsumption(projectId),
      api.materialStockChecks(projectId),
      api.materialSuppliers(),
      _snapshotOrEmpty(projectId),
      if (stock) _receiptsOrEmpty(projectId),
    ]);
    final snapshot = results[5] as Map<String, dynamic>;
    await _db.writeMeta(
      MetaKey.materialOverview(projectId),
      jsonEncode(<String, dynamic>{
        'check': results[0],
        'challans': results[1],
        'uses': ProcurementCopy.issues(results[2]),
        'counts': results[3],
        'suppliers': results[4],
        'materials': snapshot['materials'] ?? const <Object>[],
        'units': snapshot['units'] ?? const <Object>[],
        'stores': snapshot['stores'] ?? const <Object>[],
        // Quantities only, and only for someone who may receive and issue. A rate on a
        // purchase order line is dropped here, so it is never kept on the phone.
        if (stock) ...<String, dynamic>{
          'purchase_orders': ProcurementCopy.orders(snapshot['purchase_orders']),
          'stock': ProcurementCopy.stock(snapshot['stock']),
          'receipts': ProcurementCopy.receipts(results[6]),
        },
        'fetched_at': _ctx.clock.now().toUtc().toIso8601String(),
      }),
    );
  }

  /// A role without the purchasing list still has the rest of the page.
  Future<List<Map<String, dynamic>>> _receiptsOrEmpty(int projectId) async {
    try {
      return await _ctx.api.procurementReceipts(projectId);
    } on ServerFailure catch (e) {
      if (e.statusCode == 403 || e.statusCode == 404) return const <Map<String, dynamic>>[];
      rethrow;
    }
  }

  /// For the sync engine: keeps the selected project ready to use offline.
  Future<void> refreshCurrent({bool force = false}) async {
    final user = _ctx.session.user;
    if (user == null || !user.can('material_control.view') || !user.features.contains('site_control')) return;
    final int? projectId = int.tryParse(await _db.readMeta(MetaKey.selectedProject) ?? '');
    if (projectId == null) return;
    // A project taken away is not read again. It would only be refused.
    final known = await (_db.select(_db.projects)..where((t) => t.id.equals(projectId))).getSingleOrNull();
    if (known == null) return;
    if (!force) {
      final DateTime? last = (await readOverview(projectId))?.fetchedAt;
      if (last != null && _ctx.clock.now().difference(last) < _freshFor) return;
    }
    await refresh(projectId);
  }

  /// A delivery that came to site. The photo is the proof, so it goes with it.
  Future<String> receive({
    required int projectId,
    required int materialId,
    int? supplierId,
    String? supplierName,
    int? unitId,
    int? storeId,
    required Qty qty,
    Qty? challanQty,
    required String date,
    String? challanNo,
    String? vehicleNo,
    String? photoPath,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertNotFuture(date, 'challan_date');
    final String uuid = newUuid();
    String? stored;
    if (photoPath != null) {
      final extension = p.extension(photoPath);
      stored = await _ctx.attachments.importFile(
        photoPath,
        folder: 'material_challans',
        fileName: '$uuid${extension.isEmpty ? '.jpg' : extension}',
      );
    }
    await _queue(
      type: OutboxType.materialChallan,
      uuid: uuid,
      projectId: projectId,
      filePath: stored,
      title: title,
      subtitle: subtitle,
      data: <String, dynamic>{
        'material_id': materialId,
        'party_id': supplierId,
        'supplier_name': supplierId == null ? supplierName : null,
        'unit_id': unitId,
        'store_id': storeId,
        'qty': qty.toWire(),
        'challan_qty': challanQty?.toWire(),
        'challan_date': date,
        'challan_no': challanNo,
        'vehicle_no': vehicleNo,
      },
    );
    return uuid;
  }

  /// Material used on a BOQ line, or on extra work that is not in the BOQ.
  Future<String> use({
    required int projectId,
    required int materialId,
    int? unitId,
    int? storeId,
    int? boqItemId,
    required Qty qty,
    String? stage,
    String? extraWork,
    required String date,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertNotFuture(date, 'used_on');
    final String uuid = newUuid();
    await _queue(
      type: OutboxType.materialUse,
      uuid: uuid,
      projectId: projectId,
      title: title,
      subtitle: subtitle,
      data: <String, dynamic>{
        'material_id': materialId,
        'unit_id': unitId,
        'store_id': storeId,
        'boq_item_id': boqItemId,
        'qty': qty.toWire(),
        'stage': stage,
        'extra_work': extraWork,
        'used_on': date,
      },
    );
    return uuid;
  }

  /// A physical count. Zero is a real answer.
  Future<String> count({
    required int projectId,
    required int materialId,
    int? unitId,
    int? storeId,
    required Qty counted,
    required String date,
    String? remarks,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertNotFuture(date, 'check_date');
    final String uuid = newUuid();
    await _queue(
      type: OutboxType.stockCount,
      uuid: uuid,
      projectId: projectId,
      title: title,
      subtitle: subtitle,
      data: <String, dynamic>{
        'material_id': materialId,
        'unit_id': unitId,
        'store_id': storeId,
        'physical_qty': counted.toWire(),
        'check_date': date,
        'remarks': remarks,
      },
    );
    return uuid;
  }

  /// Goods that came against a purchase order. [lines] are only those with an accepted
  /// quantity: the server refuses a line of 0, so a line with nothing accepted is left
  /// out by the caller, and a receipt of nothing is never queued. One `client_uuid`
  /// stands for the whole receipt, so a lost reply stores it once.
  Future<String> receiveAgainstOrder({
    required int projectId,
    required OpenOrder order,
    int? storeId,
    required String date,
    String? challanNo,
    String? vehicleNo,
    String? remarks,
    required List<ReceiptEntry> lines,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertNotFuture(date, 'grn_date');
    final String uuid = newUuid();
    await _queue(
      type: OutboxType.goodsReceipt,
      uuid: uuid,
      projectId: projectId,
      title: title,
      subtitle: subtitle,
      data: <String, dynamic>{
        'purchase_order_id': order.id,
        'store_id': storeId,
        'grn_date': date,
        'challan_no': challanNo,
        'vehicle_no': vehicleNo,
        'remarks': remarks,
        'items': <Map<String, dynamic>>[
          for (final ReceiptEntry line in lines)
            <String, dynamic>{
              'purchase_order_item_id': line.orderLineId,
              'qty': line.qty.toWire(),
              'unit_id': line.unitId,
              'rejected_qty': line.rejected.isZero ? null : line.rejected.toWire(),
            },
        ],
        // For the phone only: the order's number and each line's material, kept so a waiting
        // receipt reads right even after the order has closed. Removed before it is sent.
        'local': <String, dynamic>{
          'po_number': order.number,
          'lines': <Map<String, dynamic>>[
            for (final ReceiptEntry line in lines)
              <String, dynamic>{'purchase_order_item_id': line.orderLineId, 'material_id': line.materialId},
          ],
        },
      },
    );
    return uuid;
  }

  /// Material leaving a store, for BOQ work or an activity.
  Future<String> issueFromStore({
    required int projectId,
    required int storeId,
    required String date,
    String? issuedTo,
    required List<IssueEntry> lines,
    required String title,
    String? subtitle,
  }) async {
    _ctx.assertNotFuture(date, 'issue_date');
    final String uuid = newUuid();
    await _queue(
      type: OutboxType.materialIssue,
      uuid: uuid,
      projectId: projectId,
      title: title,
      subtitle: subtitle,
      data: <String, dynamic>{
        'store_id': storeId,
        'issue_date': date,
        'issued_to': issuedTo,
        'items': <Map<String, dynamic>>[
          for (final IssueEntry line in lines)
            <String, dynamic>{
              'material_id': line.materialId,
              'qty': line.qty.toWire(),
              'unit_id': line.unitId,
              'boq_item_id': line.boqItemId,
              'activity': line.activity,
            },
        ],
      },
    );
    return uuid;
  }

  Future<void> _queue({
    required OutboxType type,
    required String uuid,
    required int projectId,
    required Map<String, dynamic> data,
    required String title,
    String? subtitle,
    String? filePath,
  }) {
    return _db.transaction(() async {
      await _ctx.outbox.enqueue(
        type: type,
        wireUuid: uuid,
        recordUuid: uuid,
        groupUuid: uuid,
        projectId: projectId,
        data: data,
        filePath: filePath,
        createdAt: _ctx.clock.now(),
        title: title,
        subtitle: subtitle,
      );
    });
  }
}

/// One line of a receipt as typed: what was accepted and what was turned away.
class ReceiptEntry {
  const ReceiptEntry({
    required this.orderLineId,
    required this.materialId,
    this.unitId,
    required this.qty,
    required this.rejected,
  });

  final int orderLineId;
  final int materialId;
  final int? unitId;

  /// Accepted. Above zero: the server refuses a line of nothing.
  final Qty qty;

  /// Turned away, in the same unit. Not stock, and still due on the order.
  final Qty rejected;
}

/// One line of an issue as typed.
class IssueEntry {
  const IssueEntry({required this.materialId, this.unitId, required this.qty, this.boqItemId, this.activity});

  final int materialId;
  final int? unitId;
  final Qty qty;

  /// Either this or [activity] must be given.
  final int? boqItemId;
  final String? activity;
}
