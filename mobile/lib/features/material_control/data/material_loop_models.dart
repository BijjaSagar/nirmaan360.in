// What the online material screens read: one challan in full, the bills
// list, and wastage against the BOQ norms. None of it is kept on the phone.
// Bill figures are read only when the server sent them, which it does only
// for `money.view`.
import '../../../core/money/money.dart';

Map<String, dynamic> _map(Object? value) => value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

List<Map<String, dynamic>> _rows(Object? value) =>
    value is List ? value.whereType<Map>().map((row) => row.cast<String, dynamic>()).toList() : const <Map<String, dynamic>>[];

String? _text(Object? value) {
  if (value == null) return null;
  final String text = value.toString().trim();
  return text.isEmpty ? null : text;
}

int? _intOrNull(Object? value) => value is num ? value.toInt() : int.tryParse(value?.toString() ?? '');

int _int(Object? value) => _intOrNull(value) ?? 0;

Qty _qty(Object? value) => Qty.tryParse(_text(value)) ?? Qty.zero;

Qty? _qtyOrNull(Object? value) => Qty.tryParse(_text(value));

/// The server's limit on a written reason (`MaterialCheckService::reasonRules`).
const int kReasonMax = 2000;

/// The server's limit on a note with a short delivery (`ShortDeliveryService::rules`).
const int kShortNoteMax = 500;

/// The bill side of a challan. Only sent to `money.view`.
class ChallanBill {
  const ChallanBill({this.number, this.date, this.amount, this.hasFile = false});

  final String? number;
  final String? date;
  final Money? amount;
  final bool hasFile;
}

/// Less arrived than the challan said.
class ChallanShort {
  const ChallanShort({
    required this.id,
    required this.qty,
    this.foundOn,
    this.note,
    this.atEntry = false,
    this.reversed = false,
    this.createdBy,
  });

  factory ChallanShort.fromJson(Map<String, dynamic> json) => ChallanShort(
    id: _int(json['id']),
    qty: _qty(json['short_qty']),
    foundOn: _text(json['found_on']),
    note: _text(json['note']),
    atEntry: json['at_entry'] == true,
    reversed: _text(json['status']) == 'REVERSED',
    createdBy: _text(json['created_by']),
  );

  final int id;

  /// In the challan's unit.
  final Qty qty;
  final String? foundOn;
  final String? note;

  /// Counted at the gate, so it never went into stock.
  final bool atEntry;
  final bool reversed;
  final String? createdBy;
}

/// One delivery challan as `GET /delivery-challans/{id}` gives it.
class ChallanDetail {
  const ChallanDetail({
    required this.id,
    required this.materialId,
    required this.qty,
    this.projectId,
    this.number,
    this.material,
    this.unit,
    this.supplier,
    this.supplierWarning,
    this.store,
    this.challanQty,
    this.challanNo,
    this.date,
    this.vehicleNo,
    this.remarks,
    this.hasPhoto = false,
    this.billPending = true,
    this.reversed = false,
    this.reversedBy,
    this.reversedAt,
    this.reversalReason,
    this.createdBy,
    this.billAttachedBy,
    this.shorts = const <ChallanShort>[],
    this.bill,
  });

  factory ChallanDetail.fromJson(Map<String, dynamic> json) {
    final bool money = json.containsKey('bill_no') || json.containsKey('bill_amount') || json.containsKey('has_bill_file');
    return ChallanDetail(
      id: _int(json['id']),
      projectId: _intOrNull(json['project_id']),
      number: _text(json['number']),
      materialId: _int(json['material_id']),
      material: _text(json['material']),
      unit: _text(json['unit']),
      supplier: _text(json['supplier']),
      supplierWarning: _text(json['supplier_warning']),
      store: _text(json['store']),
      qty: _qty(json['qty']),
      challanQty: _qtyOrNull(json['challan_qty']),
      challanNo: _text(json['challan_no']),
      date: _text(json['challan_date']),
      vehicleNo: _text(json['vehicle_no']),
      remarks: _text(json['remarks']),
      hasPhoto: json['has_photo'] == true,
      billPending: _text(json['bill_status']) != 'attached',
      reversed: _text(json['status']) == 'REVERSED',
      reversedBy: _text(json['reversed_by']),
      reversedAt: _text(json['reversed_at']),
      reversalReason: _text(json['reversal_reason']),
      createdBy: _text(json['created_by']),
      billAttachedBy: _text(json['bill_attached_by']),
      shorts: _rows(json['shorts']).map(ChallanShort.fromJson).toList(),
      bill: money
          ? ChallanBill(
              number: _text(json['bill_no']),
              date: _text(json['bill_date']),
              amount: _text(json['bill_amount']) == null ? null : Money.fromWire(_text(json['bill_amount'])),
              hasFile: json['has_bill_file'] == true,
            )
          : null,
    );
  }

  final int id;
  final int? projectId;
  final String? number;
  final int materialId;
  final String? material;
  final String? unit;
  final String? supplier;

  /// Short deliveries or a company note about this supplier.
  final String? supplierWarning;
  final String? store;

  /// Counted on site, in [unit].
  final Qty qty;

  /// What the paper said, when it differed.
  final Qty? challanQty;
  final String? challanNo;
  final String? date;
  final String? vehicleNo;
  final String? remarks;
  final bool hasPhoto;
  final bool billPending;
  final bool reversed;
  final String? reversedBy;
  final String? reversedAt;
  final String? reversalReason;
  final String? createdBy;
  final String? billAttachedBy;
  final List<ChallanShort> shorts;

  /// Null for someone without `money.view`: the server did not send it.
  final ChallanBill? bill;

  /// Shorts already taken out of stock, which a later short cannot exceed with the challan.
  Qty get shortAfterEntry => shorts
      .where((ChallanShort short) => !short.atEntry && !short.reversed)
      .fold<Qty>(Qty.zero, (Qty sum, ChallanShort short) => sum + short.qty);
}

/// One line of the bills list.
class BillRow {
  const BillRow({
    required this.id,
    required this.qty,
    this.number,
    this.supplier,
    this.material,
    this.unit,
    this.challanNo,
    this.date,
    this.billPending = true,
    this.daysWaiting,
    this.overdue = false,
    this.amount,
  });

  factory BillRow.fromJson(Map<String, dynamic> json) => BillRow(
    id: _int(json['id']),
    number: _text(json['number']),
    supplier: _text(json['supplier']),
    material: _text(json['material']),
    unit: _text(json['unit']),
    qty: _qty(json['qty']),
    challanNo: _text(json['challan_no']),
    date: _text(json['challan_date']),
    billPending: _text(json['bill_status']) != 'attached',
    daysWaiting: _intOrNull(json['days_waiting']),
    overdue: json['overdue'] == true,
    amount: _text(json['bill_amount']) == null ? null : Money.fromWire(_text(json['bill_amount'])),
  );

  final int id;
  final String? number;
  final String? supplier;
  final String? material;
  final String? unit;
  final Qty qty;
  final String? challanNo;
  final String? date;
  final bool billPending;

  /// Days since the challan date, while the bill is still awaited.
  final int? daysWaiting;

  /// Waiting as long as the site alert allows, or longer.
  final bool overdue;

  /// Only for `money.view`.
  final Money? amount;
}

/// One page of `GET /projects/{project}/material-control/bills`.
class BillsPage {
  const BillsPage({
    required this.rows,
    required this.attached,
    required this.pendingCount,
    required this.alertAfterDays,
    required this.page,
    required this.lastPage,
  });

  factory BillsPage.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> meta = _map(json['meta']);
    return BillsPage(
      rows: _rows(json['data']).map(BillRow.fromJson).toList(),
      attached: _text(meta['show']) == 'attached',
      pendingCount: _int(meta['pending_count']),
      alertAfterDays: _intOrNull(meta['alert_after_days']) ?? 7,
      page: _intOrNull(meta['current_page']) ?? 1,
      lastPage: _intOrNull(meta['last_page']) ?? 1,
    );
  }

  final List<BillRow> rows;
  final bool attached;
  final int pendingCount;
  final int alertAfterDays;
  final int page;
  final int lastPage;

  bool get hasMore => page < lastPage;
}

/// How the work done is counted for the reconciliation, from `ReconciliationService::BASES`.
enum WastageBasis {
  measured('measured'),
  approved('approved'),
  dpr('dpr');

  const WastageBasis(this.wire);

  final String wire;

  static WastageBasis fromWire(String? wire) =>
      WastageBasis.values.where((WastageBasis basis) => basis.wire == wire).firstOrNull ?? WastageBasis.measured;
}

/// The reconciliation's verdict on one BOQ line and material.
enum WastageStatus {
  wastage,
  under,
  noWork,
  noNorm,
  ok;

  static WastageStatus fromWire(String? wire) => switch (wire) {
    'wastage' => WastageStatus.wastage,
    'under' => WastageStatus.under,
    'no_work' => WastageStatus.noWork,
    'no_norm' => WastageStatus.noNorm,
    _ => WastageStatus.ok,
  };
}

/// One BOQ line and material: used against the work done times the norm.
class WastageRow {
  const WastageRow({
    required this.boqItemId,
    required this.materialId,
    required this.executed,
    required this.actual,
    required this.status,
    this.boqCode,
    this.boqDescription,
    this.boqUnit,
    this.materialName,
    this.unit,
    this.norm,
    this.allowedPercent,
    this.theoretical,
    this.allowed,
    this.variance,
    this.variancePercent,
  });

  factory WastageRow.fromJson(Map<String, dynamic> json) => WastageRow(
    boqItemId: _int(json['boq_item_id']),
    boqCode: _text(json['boq_code']),
    boqDescription: _text(json['boq_description']),
    boqUnit: _text(json['boq_unit']),
    executed: _qty(json['executed']),
    materialId: _int(json['material_id']),
    materialName: _text(json['material_name']),
    unit: _text(json['unit']),
    actual: _qty(json['actual']),
    norm: _text(json['norm']),
    allowedPercent: _text(json['wastage_pct']),
    theoretical: _qtyOrNull(json['theoretical']),
    allowed: _qtyOrNull(json['allowed']),
    variance: _qtyOrNull(json['variance']),
    variancePercent: _text(json['variance_pct']),
    status: WastageStatus.fromWire(_text(json['status'])),
  );

  final int boqItemId;
  final String? boqCode;
  final String? boqDescription;
  final String? boqUnit;

  /// Work done on the line, on the chosen basis, in [boqUnit].
  final Qty executed;
  final int materialId;
  final String? materialName;

  /// The material's base unit, which every figure below is in.
  final String? unit;
  final Qty actual;

  /// Material per unit of work. Null when the line has no norm for it.
  final String? norm;

  /// The norm's allowed wastage, as a percentage.
  final String? allowedPercent;
  final Qty? theoretical;
  final Qty? allowed;

  /// Used minus theoretical.
  final Qty? variance;
  final String? variancePercent;
  final WastageStatus status;

  /// How far past the allowance, in the base unit, on a line the server judged as
  /// wastage. The phone never draws that line itself: where the server's verdict is
  /// anything else this is null, whatever the figures say.
  Qty? get beyondAllowed {
    final Qty? limit = allowed;
    if (status != WastageStatus.wastage || limit == null) return null;
    final Qty over = actual - limit;
    return over.isNegative || over.isZero ? null : over;
  }
}

/// Material issued to an activity with no BOQ line, so no norm can judge it.
class UnplannedIssue {
  const UnplannedIssue({required this.materialId, required this.actual, this.materialName, this.unit, this.activity});

  factory UnplannedIssue.fromJson(Map<String, dynamic> json) => UnplannedIssue(
    materialId: _int(json['material_id']),
    materialName: _text(json['material_name']),
    unit: _text(json['unit']),
    activity: _text(json['activity']),
    actual: _qty(json['actual']),
  );

  final int materialId;
  final String? materialName;
  final String? unit;
  final String? activity;
  final Qty actual;
}

/// A store holding less of a material than its reorder level.
class ReorderGap {
  const ReorderGap({
    required this.storeId,
    required this.materialId,
    required this.balance,
    required this.reorderLevel,
    required this.shortfall,
    this.store,
    this.materialName,
    this.unit,
  });

  factory ReorderGap.fromJson(Map<String, dynamic> json) => ReorderGap(
    storeId: _int(json['store_id']),
    store: _text(json['store']),
    materialId: _int(json['material_id']),
    materialName: _text(json['material_name']),
    unit: _text(json['unit']),
    balance: _qty(json['balance']),
    reorderLevel: _qty(json['reorder_level']),
    shortfall: _qty(json['shortfall']),
  );

  final int storeId;
  final String? store;
  final int materialId;
  final String? materialName;
  final String? unit;
  final Qty balance;
  final Qty reorderLevel;
  final Qty shortfall;
}

/// `GET /projects/{project}/material-reconciliation`.
class WastageReport {
  const WastageReport({
    required this.basis,
    required this.rows,
    this.unplanned = const <UnplannedIssue>[],
    this.belowReorder = const <ReorderGap>[],
  });

  factory WastageReport.fromJson(Map<String, dynamic> json) => WastageReport(
    basis: WastageBasis.fromWire(_text(json['basis'])),
    rows: _rows(json['rows']).map(WastageRow.fromJson).toList(),
    unplanned: _rows(json['unallocated']).map(UnplannedIssue.fromJson).toList(),
    belowReorder: _rows(json['below_reorder']).map(ReorderGap.fromJson).toList(),
  );

  final WastageBasis basis;
  final List<WastageRow> rows;
  final List<UnplannedIssue> unplanned;
  final List<ReorderGap> belowReorder;

  /// Lines over their allowance first, then lines with work and no norm,
  /// then the rest, as the site would want to read them.
  List<WastageRow> get ordered {
    int rank(WastageRow row) => switch (row.status) {
      WastageStatus.wastage => 0,
      WastageStatus.noWork => 1,
      WastageStatus.noNorm => 2,
      WastageStatus.under => 3,
      WastageStatus.ok => 4,
    };
    final List<WastageRow> copy = List<WastageRow>.of(rows);
    // A stable sort, so the server's order holds within a status.
    _stableSortBy(copy, rank);
    return copy;
  }

  int count(WastageStatus status) => rows.where((WastageRow row) => row.status == status).length;
}

/// A stable insertion sort by an integer key; the lists here are short.
void _stableSortBy<T>(List<T> list, int Function(T) key) {
  for (int i = 1; i < list.length; i++) {
    final T item = list[i];
    final int k = key(item);
    int j = i - 1;
    while (j >= 0 && key(list[j]) > k) {
      list[j + 1] = list[j];
      j--;
    }
    list[j + 1] = item;
  }
}
