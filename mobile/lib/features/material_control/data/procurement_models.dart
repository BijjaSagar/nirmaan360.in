// Receiving against a purchase order, and issuing from a store. Quantities
// only. A purchase order line on the server carries a rate, an amount and tax:
// none of that is read here, and `ProcurementCopy` below drops it before
// anything is kept on the phone, so site staff cannot see it even when a
// server sends it.
import '../../../core/money/money.dart';

Map<String, dynamic> _map(Object? value) => value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? value.whereType<Map>().map((row) => row.cast<String, dynamic>()).toList()
    : const <Map<String, dynamic>>[];

String? _text(Object? value) {
  if (value == null) return null;
  final String text = value.toString().trim();
  return text.isEmpty ? null : text;
}

int? _intOrNull(Object? value) => value is num ? value.toInt() : int.tryParse(value?.toString() ?? '');

int _int(Object? value) => _intOrNull(value) ?? 0;

Qty _qty(Object? value) => Qty.tryParse(_text(value)) ?? Qty.zero;

/// One line of an open purchase order, in the units the server counts it in.
class OrderLine {
  const OrderLine({
    required this.id,
    required this.materialId,
    required this.ordered,
    this.unitId,
    required this.remaining,
  });

  factory OrderLine.fromJson(Map<String, dynamic> json) => OrderLine(
    id: _int(json['id']),
    materialId: _int(json['material_id']),
    ordered: _qty(json['qty']),
    unitId: _intOrNull(json['unit_id']),
    remaining: _qty(json['remaining_base_qty']),
  );

  final int id;
  final int materialId;

  /// As ordered, in [unitId].
  final Qty ordered;

  /// The unit the order was written in. Null counts as the material's base unit.
  final int? unitId;

  /// Still to arrive, in the material's base unit. A rejected quantity does not
  /// count as arrived, so it stays in here.
  final Qty remaining;
}

/// A purchase order open for receipts: issued, or part received.
class OpenOrder {
  const OpenOrder({required this.id, required this.number, this.vendor, this.storeId, required this.lines});

  factory OpenOrder.fromJson(Map<String, dynamic> json) => OpenOrder(
    id: _int(json['id']),
    number: _text(json['number']) ?? '',
    vendor: _text(json['vendor']),
    storeId: _intOrNull(json['store_id']),
    lines: _rows(json['items']).map(OrderLine.fromJson).toList(),
  );

  final int id;
  final String number;
  final String? vendor;

  /// Where the order says its goods go. The server uses it when a receipt names none.
  final int? storeId;
  final List<OrderLine> lines;

  /// Lines with something still to come.
  List<OrderLine> get due => lines.where((line) => line.remaining > Qty.zero).toList();

  OrderLine? line(int id) {
    for (final OrderLine line in lines) {
      if (line.id == id) return line;
    }
    return null;
  }
}

/// What a store holds of one material, in the material's base unit.
class StoreStock {
  const StoreStock({required this.storeId, required this.materialId, required this.balance});

  factory StoreStock.fromJson(Map<String, dynamic> json) => StoreStock(
    storeId: _int(json['store_id']),
    materialId: _int(json['material_id']),
    balance: _qty(json['balance']),
  );

  final int storeId;
  final int materialId;
  final Qty balance;
}

class ReceiptLine {
  const ReceiptLine({required this.materialId, required this.qty, this.unitId, required this.rejected});

  factory ReceiptLine.fromJson(Map<String, dynamic> json) => ReceiptLine(
    materialId: _int(json['material_id']),
    qty: _qty(json['qty']),
    unitId: _intOrNull(json['unit_id']),
    rejected: _qty(json['rejected_qty']),
  );

  final int materialId;

  /// What was accepted. It is what counts as received and goes into stock.
  final Qty qty;
  final int? unitId;

  /// Turned away at the gate. Recorded, but not stock and not received.
  final Qty rejected;
}

/// A goods receipt: one delivery, or part of one, against a purchase order.
class GoodsReceipt {
  const GoodsReceipt({
    this.number,
    this.clientUuid,
    required this.orderId,
    this.orderNumber,
    this.storeId,
    this.date,
    this.challanNo,
    this.reversed = false,
    required this.lines,
    this.notSent = false,
  });

  factory GoodsReceipt.fromJson(Map<String, dynamic> json) => GoodsReceipt(
    number: _text(json['number']),
    clientUuid: _text(json['client_uuid']),
    orderId: _int(json['purchase_order_id']),
    storeId: _intOrNull(json['store_id']),
    date: _text(json['grn_date']),
    challanNo: _text(json['challan_no']),
    reversed: _text(json['status']) == 'REVERSED',
    lines: _rows(json['items']).map(ReceiptLine.fromJson).toList(),
  );

  final String? number;

  /// What the phone sent it under, so a receipt the server already holds is not laid over twice.
  final String? clientUuid;
  final int orderId;

  /// Known only for a receipt still waiting to send, from the copy it was made against.
  final String? orderNumber;
  final int? storeId;
  final String? date;
  final String? challanNo;

  /// Undone on the website. Its stock was taken back.
  final bool reversed;
  final List<ReceiptLine> lines;
  final bool notSent;
}

/// The copy of purchasing that is kept on the phone. Only these keys survive,
/// so a rate, an amount or a tax figure never reaches storage.
abstract final class ProcurementCopy {
  static List<Map<String, dynamic>> orders(Object? value) => <Map<String, dynamic>>[
    for (final Map<String, dynamic> row in _rows(value))
      <String, dynamic>{
        'id': row['id'],
        'number': row['number'],
        'status': row['status'],
        'vendor': row['vendor'],
        'store_id': row['store_id'],
        'items': <Map<String, dynamic>>[
          for (final Map<String, dynamic> line in _rows(row['items']))
            <String, dynamic>{
              'id': line['id'],
              'material_id': line['material_id'],
              'qty': line['qty'],
              'unit_id': line['unit_id'],
              'base_qty': line['base_qty'],
              'received_base_qty': line['received_base_qty'],
              'remaining_base_qty': line['remaining_base_qty'],
            },
        ],
      },
  ];

  static List<Map<String, dynamic>> stock(Object? value) => <Map<String, dynamic>>[
    for (final Map<String, dynamic> row in _rows(value))
      <String, dynamic>{'store_id': row['store_id'], 'material_id': row['material_id'], 'balance': row['balance']},
  ];

  static Map<String, dynamic> receipt(Map<String, dynamic> row) => <String, dynamic>{
    'id': row['id'],
    'number': row['number'],
    'client_uuid': row['client_uuid'],
    'purchase_order_id': row['purchase_order_id'],
    'store_id': row['store_id'],
    'grn_date': row['grn_date'],
    'challan_no': row['challan_no'],
    'status': row['status'],
    'items': <Map<String, dynamic>>[
      for (final Map<String, dynamic> line in _rows(row['items']))
        <String, dynamic>{
          'id': line['id'],
          'purchase_order_item_id': line['purchase_order_item_id'],
          'material_id': line['material_id'],
          'qty': line['qty'],
          'unit_id': line['unit_id'],
          'base_qty': line['base_qty'],
          'rejected_qty': line['rejected_qty'],
        },
    ],
  };

  static List<Map<String, dynamic>> receipts(Object? value) => <Map<String, dynamic>>[
    for (final Map<String, dynamic> row in _rows(value)) receipt(row),
  ];

  /// An issue as the server presents it: no price in it, but the same rule applies.
  static Map<String, dynamic> issue(Map<String, dynamic> row) => <String, dynamic>{
    'id': row['id'],
    'number': row['number'],
    'client_uuid': row['client_uuid'],
    'store_id': row['store_id'],
    'issue_date': row['issue_date'],
    'issued_to': row['issued_to'],
    'status': row['status'],
    'items': <Map<String, dynamic>>[
      for (final Map<String, dynamic> line in _rows(row['items']))
        <String, dynamic>{
          'id': line['id'],
          'material_id': line['material_id'],
          'qty': line['qty'],
          'unit_id': line['unit_id'],
          'base_qty': line['base_qty'],
          'boq_item_id': line['boq_item_id'],
          'activity': line['activity'],
        },
    ],
  };

  static List<Map<String, dynamic>> issues(Object? value) => <Map<String, dynamic>>[
    for (final Map<String, dynamic> row in _rows(value)) issue(row),
  ];

  static Map<String, dynamic> asMap(Object? value) => _map(value);
}
