import 'dart:convert';

import '../../features/material_control/data/procurement_models.dart';
import '../db/app_database.dart';
import '../money/money.dart';

/// Adds a goods receipt or a store issue the server has just stored to the copy
/// of material kept for its project, and moves the figures it changes: what is
/// still due on the purchase order and what the store holds.
///
/// Call it in the transaction that finishes the outbox row. Until then the
/// waiting row was laid over the copy; from here the copy itself says it, so the
/// figures never jump back between the send and the next read, when someone
/// might receive the same delivery twice. With no copy kept yet, the next read
/// brings it with the rest.
class MaterialCache {
  MaterialCache(this._db);

  final AppDatabase _db;

  Future<void> rememberReceipt(int projectId, Map<String, dynamic> reply) async {
    await _update(projectId, (kept) {
      final Map<String, dynamic> receipt = ProcurementCopy.receipt(reply);
      final Object? id = receipt['id'];
      if (id == null) return false;
      final List<Object?> rows = _list(kept['receipts']);
      // A refresh may already have brought this one, with its figures in the order and the stock.
      final bool seen = rows.any((Object? row) => row is Map && row['id'] == id);
      kept['receipts'] = <Object?>[receipt, ...rows.where((Object? row) => !(row is Map && row['id'] == id))];
      if (seen || receipt['status'] == 'REVERSED') return true;

      final int orderId = _int(receipt['purchase_order_id']);
      final int? storeId = _intOrNull(receipt['store_id']);
      final List<Map<String, dynamic>> orders = ProcurementCopy.orders(kept['purchase_orders']);
      for (final Map<String, dynamic> item in _maps(receipt['items'])) {
        final Qty base = _q(item['base_qty']);
        _addStock(kept, storeId, _int(item['material_id']), base);
        for (final Map<String, dynamic> order in orders) {
          if (_int(order['id']) != orderId) continue;
          for (final Map<String, dynamic> line in _maps(order['items'])) {
            if (_int(line['id']) != _int(item['purchase_order_item_id'])) continue;
            final Qty left = _q(line['remaining_base_qty']) - base;
            line['received_base_qty'] = (_q(line['received_base_qty']) + base).toWire();
            line['remaining_base_qty'] = (left < Qty.zero ? Qty.zero : left).toWire();
          }
        }
      }
      // A fully received order is no longer open, as the server's list would say.
      kept['purchase_orders'] = <Map<String, dynamic>>[
        for (final Map<String, dynamic> order in orders)
          if (_maps(order['items']).any((line) => _q(line['remaining_base_qty']) > Qty.zero)) order,
      ];
      return true;
    });
  }

  Future<void> rememberIssue(int projectId, Map<String, dynamic> reply) async {
    await _update(projectId, (kept) {
      final Map<String, dynamic> issue = ProcurementCopy.issue(reply);
      final Object? id = issue['id'];
      if (id == null) return false;
      final List<Object?> rows = _list(kept['uses']);
      final bool seen = rows.any((Object? row) => row is Map && row['id'] == id);
      kept['uses'] = <Object?>[issue, ...rows.where((Object? row) => !(row is Map && row['id'] == id))];
      if (seen || issue['status'] == 'REVERSED') return true;
      for (final Map<String, dynamic> item in _maps(issue['items'])) {
        _addStock(kept, _intOrNull(issue['store_id']), _int(item['material_id']), -_q(item['base_qty']));
      }
      return true;
    });
  }

  Future<void> _update(int projectId, bool Function(Map<String, dynamic> kept) change) async {
    final String key = MetaKey.materialOverview(projectId);
    final String? raw = await _db.readMeta(key);
    if (raw == null) return;
    final Object? decoded;
    try {
      decoded = jsonDecode(raw);
    } on FormatException {
      return;
    }
    if (decoded is! Map) return;
    final Map<String, dynamic> kept = decoded.cast<String, dynamic>();
    // A figure that will not read leaves the copy as it was: the next read replaces it,
    // and this runs inside the transaction that finishes the outbox row.
    try {
      if (change(kept)) await _db.writeMeta(key, jsonEncode(kept));
    } on Object {
      return;
    }
  }

  void _addStock(Map<String, dynamic> kept, int? storeId, int materialId, Qty by) {
    if (storeId == null || materialId == 0) return;
    final List<Map<String, dynamic>> rows = ProcurementCopy.stock(kept['stock']);
    var found = false;
    for (final Map<String, dynamic> row in rows) {
      if (_int(row['store_id']) == storeId && _int(row['material_id']) == materialId) {
        row['balance'] = (_q(row['balance']) + by).toWire();
        found = true;
      }
    }
    if (!found) rows.add(<String, dynamic>{'store_id': storeId, 'material_id': materialId, 'balance': by.toWire()});
    kept['stock'] = rows;
  }

  static Qty _q(Object? value) => Qty.tryParse(value?.toString()) ?? Qty.zero;

  static List<Object?> _list(Object? value) => value is List ? value.cast<Object?>() : const <Object?>[];

  static List<Map<String, dynamic>> _maps(Object? value) =>
      value is List ? <Map<String, dynamic>>[for (final Object? row in value) if (row is Map) row.cast<String, dynamic>()] : const <Map<String, dynamic>>[];

  static int? _intOrNull(Object? value) => value is num ? value.toInt() : int.tryParse(value?.toString() ?? '');

  static int _int(Object? value) => _intOrNull(value) ?? 0;
}
