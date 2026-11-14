// What the phone knows about material on site. Quantities only: bill figures
// are not read here, even for people the server would send them to.
import 'dart:convert';

import 'package:decimal/decimal.dart';

import '../../../core/db/app_database.dart' show OutboxRow, OutboxType;
import '../../../core/money/money.dart';
import 'procurement_models.dart';

export 'procurement_models.dart';

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

class MaterialInfo {
  const MaterialInfo({required this.id, this.code, required this.name, this.baseUnitId, this.factors = const <int, Decimal>{}});

  factory MaterialInfo.fromJson(Map<String, dynamic> json) => MaterialInfo(
    id: _int(json['id']),
    code: _text(json['code']),
    name: _text(json['name']) ?? '',
    baseUnitId: _intOrNull(json['base_unit_id']),
    factors: <int, Decimal>{
      for (final Map<String, dynamic> row in _rows(json['conversions']))
        if (Decimal.tryParse(_text(row['factor']) ?? '') != null)
          _int(row['unit_id']): Decimal.parse(_text(row['factor'])!),
    },
  );

  final int id;
  final String? code;
  final String name;
  final int? baseUnitId;

  /// Other units this material can be counted in, as how many base units each
  /// one holds.
  final Map<int, Decimal> factors;

  Qty toBase(Qty qty, int? unitId) {
    final Decimal? factor = unitId == null || unitId == baseUnitId ? null : factors[unitId];
    return factor == null ? qty : Qty.of(qty.value * factor);
  }

  /// [base] counted in [unitId] instead, to the three places a quantity holds.
  /// For telling someone how much is left in the unit they are typing in.
  Qty fromBase(Qty base, int? unitId) {
    final Decimal? factor = unitId == null || unitId == baseUnitId ? null : factors[unitId];
    if (factor == null || factor == Decimal.zero) return base;
    return Qty.of((base.value / factor).toDecimal(scaleOnInfinitePrecision: 3));
  }

  /// The units a quantity of this material can be typed in: its own, then the others.
  List<int> unitIds() => <int>[?baseUnitId, ...factors.keys.where((id) => id != baseUnitId)];
}

class MaterialStoreInfo {
  const MaterialStoreInfo(this.id, this.name);

  final int id;
  final String name;
}

class SupplierOption {
  const SupplierOption({required this.id, required this.name, this.warning});

  factory SupplierOption.fromJson(Map<String, dynamic> json) => SupplierOption(
    id: _int(json['party_id']),
    name: _text(json['name']) ?? '',
    warning: json['warn'] == true ? _text(json['warning']) : null,
  );

  final int id;
  final String name;

  /// Short deliveries or a company note, to read before taking more from them.
  final String? warning;
}

class MaterialFlag {
  const MaterialFlag({required this.type, required this.message, this.open = true, this.reason, this.reasonBy, this.reasonAt});

  factory MaterialFlag.fromJson(Map<String, dynamic> json) => MaterialFlag(
    type: _text(json['type']) ?? '',
    message: _text(json['message']) ?? '',
    open: json['open'] != false,
    reason: _text(json['reason']),
    reasonBy: _text(json['reason_by']),
    reasonAt: _text(json['reason_at']),
  );

  final String type;
  final String message;
  final bool open;

  /// The written answer, while it still covers the gap. A gap that grew opens again with none.
  final String? reason;
  final String? reasonBy;
  final String? reasonAt;
}

/// One material on the check: what the BOQ expects against what really moved.
class MaterialRow {
  const MaterialRow({
    required this.materialId,
    required this.name,
    required this.unit,
    required this.estimate,
    required this.received,
    required this.used,
    required this.shouldBeInStock,
    required this.stock,
    required this.unaccounted,
    required this.shortDelivered,
    this.lastCounted,
    this.wastagePercent,
    this.flags = const <MaterialFlag>[],
  });

  factory MaterialRow.fromJson(Map<String, dynamic> json) => MaterialRow(
    materialId: _int(json['material_id']),
    name: _text(json['material']) ?? '',
    unit: _text(json['unit']) ?? '',
    estimate: _qty(json['estimate']),
    received: _qty(json['received']),
    used: _qty(json['used']),
    shouldBeInStock: _qty(json['should_be_in_stock']),
    stock: _qty(json['stock_on_site']),
    unaccounted: _qty(json['unaccounted']),
    shortDelivered: _qty(json['short_delivered']),
    lastCounted: _text(json['last_counted']),
    wastagePercent: _text(json['wastage_pct']),
    flags: _rows(json['flags']).map(MaterialFlag.fromJson).toList(),
  );

  final int materialId;
  final String name;
  final String unit;
  final Qty estimate;
  final Qty received;
  final Qty used;
  final Qty shouldBeInStock;
  final Qty stock;
  final Qty unaccounted;
  final Qty shortDelivered;
  final String? lastCounted;
  final String? wastagePercent;
  final List<MaterialFlag> flags;

  int get openFlags => flags.where((flag) => flag.open).length;

  MaterialRow moved({Qty? received, Qty? used, Qty? shouldBeInStock, Qty? stock}) => MaterialRow(
    materialId: materialId,
    name: name,
    unit: unit,
    estimate: estimate,
    received: received ?? this.received,
    used: used ?? this.used,
    shouldBeInStock: shouldBeInStock ?? this.shouldBeInStock,
    stock: stock ?? this.stock,
    unaccounted: unaccounted,
    shortDelivered: shortDelivered,
    lastCounted: lastCounted,
    wastagePercent: wastagePercent,
    flags: flags,
  );
}

class MaterialDelivery {
  const MaterialDelivery({
    required this.materialId,
    required this.qty,
    this.id,
    this.unitId,
    this.supplier,
    this.challanNo,
    this.date,
    this.billPending = false,
    this.reversed = false,
    this.notSent = false,
  });

  factory MaterialDelivery.fromJson(Map<String, dynamic> json) => MaterialDelivery(
    id: _intOrNull(json['id']),
    materialId: _int(json['material_id']),
    qty: _qty(json['qty']),
    unitId: _intOrNull(json['unit_id']),
    supplier: _text(json['supplier']),
    challanNo: _text(json['challan_no']),
    date: _text(json['challan_date']),
    billPending: _text(json['bill_status']) == 'pending' && _text(json['status']) != 'REVERSED',
    reversed: _text(json['status']) == 'REVERSED',
  );

  /// The server's id, so the challan can be opened in full. Null while it waits to send.
  final int? id;

  /// Taken into stock, and the supplier's bill not attached yet.
  final bool billPending;

  /// Undone on the website.
  final bool reversed;
  final int materialId;
  final Qty qty;
  final int? unitId;
  final String? supplier;
  final String? challanNo;
  final String? date;
  final bool notSent;
}

class MaterialUse {
  const MaterialUse({
    required this.materialId,
    required this.qty,
    this.unitId,
    this.activity,
    this.date,
    this.issuedTo,
    this.clientUuid,
    this.reversed = false,
    this.notSent = false,
  });

  /// The server stores a use as an issue, and an issue can have several lines.
  /// Each line is one row here, so nothing a store issued is left out.
  static List<MaterialUse> linesOf(Map<String, dynamic> json) => <MaterialUse>[
    for (final Map<String, dynamic> line in _rows(json['items']))
      MaterialUse(
        materialId: _int(line['material_id']),
        qty: _qty(line['qty']),
        unitId: _intOrNull(line['unit_id']),
        activity: _text(line['activity']),
        date: _text(json['issue_date']),
        issuedTo: _text(json['issued_to']),
        clientUuid: _text(json['client_uuid']),
        reversed: _text(json['status']) == 'REVERSED',
      ),
  ];

  final int materialId;
  final Qty qty;
  final int? unitId;
  final String? activity;
  final String? date;

  /// Who a store issue went to, when it said.
  final String? issuedTo;

  /// What the phone sent it under, so an issue the server already holds is not laid over twice.
  final String? clientUuid;

  /// Undone on the website. Its stock was put back.
  final bool reversed;
  final bool notSent;
}

class StockCount {
  const StockCount({required this.materialId, required this.counted, this.unitId, this.variance, this.date, this.notSent = false});

  factory StockCount.fromJson(Map<String, dynamic> json) => StockCount(
    materialId: _int(json['material_id']),
    counted: _qty(json['physical_qty']),
    unitId: _intOrNull(json['unit_id']),
    variance: _text(json['variance_qty']) == null ? null : _qty(json['variance_qty']),
    date: _text(json['check_date']),
  );

  final int materialId;
  final Qty counted;
  final int? unitId;

  /// Null until the server has compared it with the book.
  final Qty? variance;
  final String? date;
  final bool notSent;
}

/// Everything the material screens read, as last fetched from the server, with
/// the work still waiting to send laid over it.
class MaterialOverview {
  const MaterialOverview({
    required this.rows,
    required this.materials,
    required this.units,
    required this.stores,
    required this.suppliers,
    required this.deliveries,
    required this.uses,
    required this.counts,
    this.orders = const <OpenOrder>[],
    this.stock = const <StoreStock>[],
    this.receipts = const <GoodsReceipt>[],
    this.fetchedAt,
    this.unsent = 0,
  });

  factory MaterialOverview.fromJson(Map<String, dynamic> json) => MaterialOverview(
    rows: _rows(_map(json['check'])['rows']).map(MaterialRow.fromJson).toList(),
    materials: _rows(json['materials']).map(MaterialInfo.fromJson).toList(),
    units: <int, String>{
      for (final Map<String, dynamic> row in _rows(json['units'])) _int(row['id']): _text(row['code']) ?? '',
    },
    stores: <MaterialStoreInfo>[
      for (final Map<String, dynamic> row in _rows(json['stores'])) MaterialStoreInfo(_int(row['id']), _text(row['name']) ?? ''),
    ],
    suppliers: _rows(json['suppliers']).map(SupplierOption.fromJson).toList(),
    deliveries: _rows(json['challans']).map(MaterialDelivery.fromJson).toList(),
    uses: <MaterialUse>[for (final Map<String, dynamic> issue in _rows(json['uses'])) ...MaterialUse.linesOf(issue)],
    counts: _rows(json['counts']).map(StockCount.fromJson).toList(),
    orders: _rows(json['purchase_orders']).map(OpenOrder.fromJson).toList(),
    stock: _rows(json['stock']).map(StoreStock.fromJson).toList(),
    receipts: _rows(json['receipts']).map(GoodsReceipt.fromJson).toList(),
    fetchedAt: DateTime.tryParse(_text(json['fetched_at']) ?? ''),
  );

  final List<MaterialRow> rows;
  final List<MaterialInfo> materials;
  final Map<int, String> units;
  final List<MaterialStoreInfo> stores;
  final List<SupplierOption> suppliers;
  final List<MaterialDelivery> deliveries;
  final List<MaterialUse> uses;
  final List<StockCount> counts;

  /// Purchase orders open for receipts, with what is still due on each line.
  final List<OpenOrder> orders;

  /// What each store holds of each material, base unit.
  final List<StoreStock> stock;

  /// The latest goods receipts against purchase orders.
  final List<GoodsReceipt> receipts;
  final DateTime? fetchedAt;

  /// Deliveries, uses and counts not yet on the server.
  final int unsent;

  int get openFlags => rows.fold<int>(0, (sum, row) => sum + row.openFlags);

  MaterialInfo? material(int id) {
    for (final MaterialInfo info in materials) {
      if (info.id == id) return info;
    }
    return null;
  }

  MaterialRow? row(int materialId) {
    for (final MaterialRow row in rows) {
      if (row.materialId == materialId) return row;
    }
    return null;
  }

  OpenOrder? order(int id) {
    for (final OpenOrder order in orders) {
      if (order.id == id) return order;
    }
    return null;
  }

  /// Orders with something still to receive, in the order the server listed them.
  List<OpenOrder> get ordersDue => orders.where((order) => order.due.isNotEmpty).toList();

  String storeName(int? id) {
    for (final MaterialStoreInfo store in stores) {
      if (store.id == id) return store.name;
    }
    return '';
  }

  /// What the phone thinks [storeId] holds of [materialId], in the base unit.
  Qty stockIn(int storeId, int materialId) {
    for (final StoreStock row in stock) {
      if (row.storeId == storeId && row.materialId == materialId) return row.balance;
    }
    return Qty.zero;
  }

  SupplierOption? supplier(int id) {
    for (final SupplierOption option in suppliers) {
      if (option.id == id) return option;
    }
    return null;
  }

  String materialName(int id) => material(id)?.name ?? row(id)?.name ?? '';

  String baseUnit(int materialId) {
    final int? unitId = material(materialId)?.baseUnitId;
    return (unitId == null ? null : units[unitId]) ?? row(materialId)?.unit ?? '';
  }

  /// [unitId] when known, otherwise the material's base unit.
  String unitLabel(int materialId, int? unitId) => (unitId == null ? null : units[unitId]) ?? baseUnit(materialId);

  /// [unsentRows] are this project's outbox rows not yet finished, oldest first.
  MaterialOverview withUnsent(List<OutboxRow> unsentRows) {
    if (unsentRows.isEmpty) return this;

    final Map<int, MaterialRow> moved = <int, MaterialRow>{for (final MaterialRow row in rows) row.materialId: row};
    final List<MaterialDelivery> newDeliveries = <MaterialDelivery>[];
    final List<MaterialUse> newUses = <MaterialUse>[];
    final List<StockCount> newCounts = <StockCount>[];
    final List<GoodsReceipt> newReceipts = <GoodsReceipt>[];

    /// Base quantity of each order line already sent from this phone and not yet
    /// on the server's copy, and what each store gains or loses the same way.
    final Map<int, Qty> taken = <int, Qty>{};
    final Map<(int, int), Qty> stockMoved = <(int, int), Qty>{};
    var unsentCount = 0;

    MaterialRow rowFor(int materialId) =>
        moved[materialId] ??
        MaterialRow(
          materialId: materialId,
          name: materialName(materialId),
          unit: baseUnit(materialId),
          estimate: Qty.zero,
          received: Qty.zero,
          used: Qty.zero,
          shouldBeInStock: Qty.zero,
          stock: Qty.zero,
          unaccounted: Qty.zero,
          shortDelivered: Qty.zero,
        );

    Qty baseOf(int materialId, Qty qty, int? unitId) => material(materialId)?.toBase(qty, unitId) ?? qty;

    void moveStock(int? storeId, int materialId, Qty by) {
      if (storeId == null) return;
      final (int, int) key = (storeId, materialId);
      stockMoved[key] = (stockMoved[key] ?? Qty.zero) + by;
    }

    // A receipt or an issue the server already lists, because a read ran while it was
    // being sent, has its figures in the copy. Laying it over again would count it twice.
    final Set<String> listed = <String>{
      for (final GoodsReceipt receipt in receipts) ?receipt.clientUuid,
      for (final MaterialUse use in uses) ?use.clientUuid,
    };

    for (final OutboxRow outbox in unsentRows) {
      if ((outbox.type == OutboxType.goodsReceipt || outbox.type == OutboxType.materialIssue) &&
          listed.contains(outbox.wireUuid)) {
        continue;
      }
      final Map<String, dynamic> data = _map(jsonDecode(outbox.payload));
      final int materialId = _int(data['material_id']);
      final int? unitId = _intOrNull(data['unit_id']);
      switch (outbox.type) {
        case OutboxType.materialChallan:
          unsentCount++;
          final Qty qty = _qty(data['qty']);
          final Qty base = baseOf(materialId, qty, unitId);
          final MaterialRow row = rowFor(materialId);
          moved[materialId] = row.moved(
            received: row.received + base,
            shouldBeInStock: row.shouldBeInStock + base,
            stock: row.stock + base,
          );
          newDeliveries.insert(
            0,
            MaterialDelivery(
              materialId: materialId,
              qty: qty,
              unitId: unitId,
              supplier: _text(data['supplier_name']) ?? supplier(_int(data['party_id']))?.name,
              challanNo: _text(data['challan_no']),
              date: _text(data['challan_date']),
              notSent: true,
            ),
          );
        case OutboxType.materialUse:
          unsentCount++;
          final Qty qty = _qty(data['qty']);
          final Qty base = baseOf(materialId, qty, unitId);
          final MaterialRow row = rowFor(materialId);
          moved[materialId] = row.moved(
            used: row.used + base,
            shouldBeInStock: row.shouldBeInStock - base,
            stock: row.stock - base,
          );
          newUses.insert(
            0,
            MaterialUse(
              materialId: materialId,
              qty: qty,
              unitId: unitId,
              activity: _text(data['stage']) ?? _text(data['extra_work']),
              date: _text(data['used_on']),
              notSent: true,
            ),
          );
        case OutboxType.stockCount:
          unsentCount++;
          final Qty counted = _qty(data['physical_qty']);
          moved[materialId] = rowFor(materialId).moved(stock: baseOf(materialId, counted, unitId));
          newCounts.insert(
            0,
            StockCount(
              materialId: materialId,
              counted: counted,
              unitId: unitId,
              date: _text(data['check_date']),
              notSent: true,
            ),
          );
        case OutboxType.goodsReceipt:
          unsentCount++;
          final OpenOrder? known = order(_int(data['purchase_order_id']));
          final int? storeId = _intOrNull(data['store_id']) ?? known?.storeId;
          final Map<String, dynamic> local = _map(data['local']);
          // The material of each line was written down when the receipt was made, so
          // an order that has since closed on the server still reads right.
          final Map<int, int> materialOf = <int, int>{
            for (final Map<String, dynamic> line in _rows(local['lines']))
              _int(line['purchase_order_item_id']): _int(line['material_id']),
          };
          final List<ReceiptLine> lines = <ReceiptLine>[];
          for (final Map<String, dynamic> item in _rows(data['items'])) {
            final int lineId = _int(item['purchase_order_item_id']);
            final OrderLine? orderLine = known?.line(lineId);
            final int lineMaterial = materialOf[lineId] ?? orderLine?.materialId ?? 0;
            final int? lineUnit = _intOrNull(item['unit_id']) ?? orderLine?.unitId;
            final Qty accepted = _qty(item['qty']);
            final Qty base = baseOf(lineMaterial, accepted, lineUnit);
            taken[lineId] = (taken[lineId] ?? Qty.zero) + base;
            moveStock(storeId, lineMaterial, base);
            final MaterialRow row = rowFor(lineMaterial);
            moved[lineMaterial] = row.moved(
              received: row.received + base,
              shouldBeInStock: row.shouldBeInStock + base,
              stock: row.stock + base,
            );
            lines.add(ReceiptLine(materialId: lineMaterial, qty: accepted, unitId: lineUnit, rejected: _qty(item['rejected_qty'])));
          }
          newReceipts.insert(
            0,
            GoodsReceipt(
              orderId: _int(data['purchase_order_id']),
              orderNumber: _text(local['po_number']) ?? known?.number,
              storeId: storeId,
              date: _text(data['grn_date']),
              challanNo: _text(data['challan_no']),
              lines: lines,
              notSent: true,
            ),
          );
        case OutboxType.materialIssue:
          unsentCount++;
          final int? storeId = _intOrNull(data['store_id']);
          final List<MaterialUse> lines = <MaterialUse>[];
          for (final Map<String, dynamic> item in _rows(data['items'])) {
            final int lineMaterial = _int(item['material_id']);
            final int? lineUnit = _intOrNull(item['unit_id']);
            final Qty qty = _qty(item['qty']);
            final Qty base = baseOf(lineMaterial, qty, lineUnit);
            moveStock(storeId, lineMaterial, -base);
            final MaterialRow row = rowFor(lineMaterial);
            moved[lineMaterial] = row.moved(
              used: row.used + base,
              shouldBeInStock: row.shouldBeInStock - base,
              stock: row.stock - base,
            );
            lines.add(
              MaterialUse(
                materialId: lineMaterial,
                qty: qty,
                unitId: lineUnit,
                activity: _text(item['activity']),
                date: _text(data['issue_date']),
                issuedTo: _text(data['issued_to']),
                notSent: true,
              ),
            );
          }
          newUses.insertAll(0, lines);
        default:
          break;
      }
    }

    Qty notBelowZero(Qty qty) => qty < Qty.zero ? Qty.zero : qty;
    final List<OpenOrder> openOrders = taken.isEmpty
        ? orders
        : <OpenOrder>[
            for (final OpenOrder open in orders)
              OpenOrder(
                id: open.id,
                number: open.number,
                vendor: open.vendor,
                storeId: open.storeId,
                lines: <OrderLine>[
                  for (final OrderLine line in open.lines)
                    OrderLine(
                      id: line.id,
                      materialId: line.materialId,
                      ordered: line.ordered,
                      unitId: line.unitId,
                      remaining: notBelowZero(line.remaining - (taken[line.id] ?? Qty.zero)),
                    ),
                ],
              ),
          ];
    final Map<(int, int), Qty> held = <(int, int), Qty>{
      for (final StoreStock row in stock) (row.storeId, row.materialId): row.balance,
    };
    stockMoved.forEach((key, by) => held[key] = (held[key] ?? Qty.zero) + by);

    return MaterialOverview(
      rows: moved.values.toList(),
      materials: materials,
      units: units,
      stores: stores,
      suppliers: suppliers,
      deliveries: <MaterialDelivery>[...newDeliveries, ...deliveries],
      uses: <MaterialUse>[...newUses, ...uses],
      counts: <StockCount>[...newCounts, ...counts],
      orders: openOrders,
      stock: <StoreStock>[
        for (final MapEntry<(int, int), Qty> entry in held.entries)
          StoreStock(storeId: entry.key.$1, materialId: entry.key.$2, balance: entry.value),
      ],
      receipts: <GoodsReceipt>[...newReceipts, ...receipts],
      fetchedAt: fetchedAt,
      unsent: unsentCount,
    );
  }
}
