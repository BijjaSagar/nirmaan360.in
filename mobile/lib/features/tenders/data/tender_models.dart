import '../../../core/money/money.dart';

/// What the server sends as a number or a string, read as text for [Money] and [Qty].
String? _wire(Object? value) => value?.toString();

int _int(Object? value) => (value as num?)?.toInt() ?? 0;

int? _intOrNull(Object? value) => (value as num?)?.toInt();

String? _text(Object? value) {
  final String? text = value?.toString().trim();
  return text == null || text.isEmpty ? null : text;
}

Money? _money(Object? value) => value == null ? null : Money.fromWire(_wire(value));

List<Map<String, dynamic>> _rows(Object? value) =>
    value is List ? value.whereType<Map>().map((Map row) => row.cast<String, dynamic>()).toList() : const [];

/// Beside a BOQ line: how many tenders it is in and how many quotes came in.
/// [lowestRate] is null for someone without `money.view`.
class TenderMarker {
  const TenderMarker({required this.tenders, required this.quotes, this.lowestRate});

  final int tenders;
  final int quotes;
  final Money? lowestRate;

  static TenderMarker? tryFromJson(Object? json) {
    if (json is! Map) return null;
    final Map<String, dynamic> row = json.cast<String, dynamic>();
    final int tenders = _int(row['tenders']);
    if (tenders == 0) return null;
    return TenderMarker(tenders: tenders, quotes: _int(row['quotes']), lowestRate: _money(row['lowest_rate']));
  }
}

/// A BOQ line and the tenders it is in, as `GET /boq-items/{item}/tenders` sends it.
class LineTenders {
  const LineTenders({
    required this.boqItemId,
    required this.code,
    required this.description,
    required this.unit,
    required this.qty,
    required this.tenderCount,
    required this.openTenderCount,
    required this.quoteCount,
    required this.tenders,
    required this.canQuote,
    required this.canAward,
    required this.noQuantity,
    this.boqRate,
    this.boqAmount,
    this.lowestRate,
    this.lowestContractor,
  });

  factory LineTenders.fromJson(Map<String, dynamic> json) {
    final Object? can = json['can'];
    final Map<String, dynamic> allowed = can is Map ? can.cast<String, dynamic>() : const <String, dynamic>{};
    final Qty qty = Qty.fromWire(_wire(json['qty']));
    return LineTenders(
      boqItemId: _int(json['boq_item_id']),
      code: _text(json['code']) ?? '',
      description: _text(json['description']) ?? '',
      unit: _text(json['unit']) ?? '',
      qty: qty,
      tenderCount: _int(json['tender_count']),
      openTenderCount: _int(json['open_tender_count']),
      quoteCount: _int(json['quote_count']),
      tenders: _rows(json['tenders']).map(LineTender.fromJson).toList(growable: false),
      canQuote: allowed['quote'] == true,
      canAward: allowed['award'] == true,
      // Older servers do not say. A line with nothing to buy cannot be priced by rate.
      noQuantity: json['no_quantity'] == true || (json['no_quantity'] == null && qty.isZero),
      boqRate: _money(json['boq_rate']),
      boqAmount: _money(json['boq_amount']),
      lowestRate: _money(json['lowest_rate']),
      lowestContractor: _text(json['lowest_contractor']),
    );
  }

  final int boqItemId;
  final String code;
  final String description;
  final String unit;
  final Qty qty;
  final int tenderCount;
  final int openTenderCount;
  final int quoteCount;
  final List<LineTender> tenders;

  /// What the server says this person may do here.
  final bool canQuote;
  final bool canAward;

  /// The line has no quantity, so a rate cannot make an amount.
  final bool noQuantity;

  /// Money, sent only for `money.view`.
  final Money? boqRate;
  final Money? boqAmount;
  final Money? lowestRate;
  final String? lowestContractor;

  LineTender? tender(int id) {
    for (final LineTender tender in tenders) {
      if (tender.id == id) return tender;
    }
    return null;
  }
}

/// One tender the line is in. A tender can carry other BOQ lines too.
class LineTender {
  const LineTender({
    required this.id,
    required this.title,
    required this.status,
    required this.lines,
    required this.quoteCount,
    required this.quotes,
    this.number,
    this.dueOn,
    this.workOrderId,
    this.workOrderNumber,
    this.qty,
    this.boqRate,
    this.boqAmount,
    this.lowestRate,
  });

  factory LineTender.fromJson(Map<String, dynamic> json) {
    return LineTender(
      id: _int(json['id']),
      number: _text(json['number']),
      title: _text(json['title']) ?? '',
      status: (_text(json['status']) ?? 'OPEN').toUpperCase(),
      dueOn: _text(json['due_on']),
      workOrderId: _intOrNull(json['work_order_id']),
      workOrderNumber: _text(json['work_order_number']),
      qty: json['qty'] == null ? null : Qty.fromWire(_wire(json['qty'])),
      lines: _rows(json['lines']).map(TenderLine.fromJson).toList(growable: false),
      quoteCount: _int(json['quote_count']),
      quotes: _rows(json['quotes']).map(TenderQuote.fromJson).toList(growable: false),
      boqRate: _money(json['boq_rate']),
      boqAmount: _money(json['boq_amount']),
      lowestRate: _money(json['lowest_rate']),
    );
  }

  final int id;
  final String? number;
  final String title;

  /// OPEN, AWARDED or CANCELLED.
  final String status;
  final String? dueOn;
  final int? workOrderId;
  final String? workOrderNumber;

  /// This line's quantity in the tender.
  final Qty? qty;
  final List<TenderLine> lines;

  /// Current quotes only: a contractor's replaced quote is not counted.
  final int quoteCount;
  final List<TenderQuote> quotes;
  final Money? boqRate;
  final Money? boqAmount;
  final Money? lowestRate;

  bool get isOpen => status == 'OPEN';
  bool get isAwarded => status == 'AWARDED';

  TenderQuote? quote(int bidId) {
    for (final TenderQuote quote in quotes) {
      if (quote.bidId == bidId) return quote;
    }
    return null;
  }
}

/// A BOQ line inside a tender. The quote form asks a rate for each.
class TenderLine {
  const TenderLine({required this.id, required this.qty, this.boqItemId, this.code, this.description, this.unit});

  factory TenderLine.fromJson(Map<String, dynamic> json) {
    return TenderLine(
      id: _int(json['id']),
      boqItemId: _intOrNull(json['boq_item_id']),
      code: _text(json['code']),
      description: _text(json['description']),
      unit: _text(json['unit']),
      qty: Qty.fromWire(_wire(json['qty'])),
    );
  }

  final int id;
  final int? boqItemId;
  final String? code;
  final String? description;
  final String? unit;
  final Qty qty;

  String get displayName => <String?>[code, description].whereType<String>().join(' · ');
}

/// One contractor's quote. Figures, terms and the file are sent only for `money.view`.
class TenderQuote {
  const TenderQuote({
    required this.bidId,
    required this.contractor,
    required this.current,
    required this.selected,
    required this.lowest,
    required this.hasQuotation,
    this.receivedOn,
    this.validUntil,
    this.includesMaterial,
    this.completionDays,
    this.enteredBy,
    this.rate,
    this.amount,
    this.diff,
    this.diffAmount,
    this.diffPct,
    this.paymentTerms,
    this.remarks,
    this.quotationName,
  });

  factory TenderQuote.fromJson(Map<String, dynamic> json) {
    final Object? material = json['includes_material'];
    return TenderQuote(
      bidId: _int(json['bid_id']),
      contractor: _text(json['contractor']) ?? '',
      receivedOn: _text(json['received_on']),
      current: json['current'] != false,
      selected: json['selected'] == true,
      validUntil: _text(json['valid_until']),
      includesMaterial: material == null ? null : (material == true || material == 1 || material == '1'),
      completionDays: _intOrNull(json['completion_days']),
      enteredBy: _text(json['entered_by']),
      rate: _money(json['rate']),
      amount: _money(json['amount']),
      diff: _money(json['diff']),
      diffAmount: _money(json['diff_amount']),
      diffPct: _text(json['diff_pct']),
      lowest: json['lowest'] == true,
      paymentTerms: _text(json['payment_terms']),
      remarks: _text(json['remarks']),
      hasQuotation: json['has_quotation'] == true,
      quotationName: _text(json['quotation_name']),
    );
  }

  final int bidId;
  final String contractor;
  final String? receivedOn;

  /// False once the same contractor sent a newer quote for this tender.
  final bool current;
  final bool selected;
  final String? validUntil;
  final bool? includesMaterial;
  final int? completionDays;
  final String? enteredBy;

  final Money? rate;
  final Money? amount;

  /// The rate against the BOQ rate, and the amount against the BOQ amount.
  final Money? diff;
  final Money? diffAmount;

  /// Percent against the BOQ, as the server worked it out.
  final String? diffPct;
  final bool lowest;
  final String? paymentTerms;
  final String? remarks;
  final bool hasQuotation;
  final String? quotationName;
}

/// A subcontractor the company knows, for the quote form.
class Subcontractor {
  const Subcontractor({required this.id, required this.name, this.phone, this.contactPerson});

  factory Subcontractor.fromJson(Map<String, dynamic> json) {
    return Subcontractor(
      id: _int(json['id']),
      name: _text(json['name']) ?? '',
      phone: _text(json['phone']),
      contactPerson: _text(json['contact_person']),
    );
  }

  final int id;
  final String name;
  final String? phone;
  final String? contactPerson;

  String? get detail => <String?>[contactPerson, phone].whereType<String>().join(' · ').trim().isEmpty
      ? null
      : <String?>[contactPerson, phone].whereType<String>().join(' · ');
}

/// The award form's choices, as the website offers them.
const List<String> kDeductionBases = <String>['gross', 'running'];
const List<String> kDeductionOrders = <String>[
  'retention,advance,material,tds',
  'advance,retention,material,tds',
  'tds,retention,advance,material',
];
