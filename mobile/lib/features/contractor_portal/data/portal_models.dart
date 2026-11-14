import 'package:equatable/equatable.dart';

import '../../../core/money/money.dart';

// What `GET /contractor-portal` and its siblings return, read tolerantly. PHP
// sends an empty array where a map is expected and leaves a key out when it
// has nothing to say, and one odd row must never keep the portal from opening.

Map<String, dynamic> _map(Object? value) => value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? <Map<String, dynamic>>[
        for (final Object? row in value)
          if (row is Map) row.cast<String, dynamic>(),
      ]
    : const <Map<String, dynamic>>[];

Money? _money(Object? raw) {
  if (raw == null) return null;
  try {
    return Money.fromWire(raw.toString());
  } on FormatException {
    return null;
  }
}

Qty? _qty(Object? raw) {
  if (raw == null) return null;
  try {
    return Qty.fromWire(raw.toString());
  } on FormatException {
    return null;
  }
}

String? _text(Object? raw) {
  final String? text = raw?.toString().trim();
  return text == null || text.isEmpty ? null : text;
}

int? _int(Object? raw) => raw is num ? raw.toInt() : int.tryParse('${raw ?? ''}');

/// Paid against work, for one contract or for all of them. Every money figure
/// is a [Money]; a percent stays the server's own text, which has two places.
class PortalFigures extends Equatable {
  const PortalFigures({
    this.contractValue,
    this.earned,
    this.paid,
    this.advance,
    this.balanceMoney,
    this.balanceWorkValue,
    this.workPct,
    this.paidPct,
    this.balanceWorkPct,
    this.ratio,
  });

  factory PortalFigures.fromJson(Map<String, dynamic> json) => PortalFigures(
    contractValue: _money(json['contract_value']),
    earned: _money(json['earned']),
    paid: _money(json['paid']),
    advance: _money(json['advance']),
    balanceMoney: _money(json['balance_money']),
    balanceWorkValue: _money(json['balance_work_value']),
    workPct: _text(json['work_pct']),
    paidPct: _text(json['paid_pct']),
    balanceWorkPct: _text(json['balance_work_pct']),
    ratio: _text(json['status']),
  );

  final Money? contractValue;
  final Money? earned;
  final Money? paid;
  final Money? advance;
  final Money? balanceMoney;
  final Money? balanceWorkValue;
  final String? workPct;
  final String? paidPct;
  final String? balanceWorkPct;

  /// `red` when more has been paid than the work is worth, `green` when not,
  /// `none` when there is no value to compare.
  final String? ratio;

  bool get paidAhead => ratio == 'red';

  @override
  List<Object?> get props => <Object?>[
    contractValue,
    earned,
    paid,
    advance,
    balanceMoney,
    balanceWorkValue,
    workPct,
    paidPct,
    balanceWorkPct,
    ratio,
  ];
}

/// Where a contract stands against its date.
enum PortalTime { closed, done, overdue, dueToday, onTrack, noDate }

PortalTime _time(String? raw) => switch (raw) {
  'closed' => PortalTime.closed,
  'done' => PortalTime.done,
  'overdue' => PortalTime.overdue,
  'due_today' => PortalTime.dueToday,
  'on_track' => PortalTime.onTrack,
  _ => PortalTime.noDate,
};

class PortalContract extends Equatable {
  const PortalContract({
    required this.id,
    required this.number,
    this.title,
    this.status,
    this.projectName,
    this.projectCode,
    this.startDate,
    this.figures = const PortalFigures(),
    this.deadline,
    this.deadlineLabel,
    this.serverDaysLeft,
    this.time = PortalTime.noDate,
  });

  /// Null when the row has no id: it could not be opened, so it is left out.
  static PortalContract? tryFromJson(Map<String, dynamic> json) {
    final int? id = _int(json['id']);
    if (id == null) return null;
    return PortalContract(
      id: id,
      number: _text(json['number']) ?? '',
      title: _text(json['title']),
      status: _text(json['contract_status']),
      projectName: _text(json['project_name']),
      projectCode: _text(json['project_code']),
      startDate: _text(json['start_date']),
      figures: PortalFigures.fromJson(json),
      deadline: _text(json['deadline']),
      deadlineLabel: _text(json['deadline_label']),
      serverDaysLeft: _int(json['days_left']),
      time: _time(_text(json['time_state'])),
    );
  }

  final int id;
  final String number;
  final String? title;
  final String? status;
  final String? projectName;
  final String? projectCode;
  final String? startDate;
  final PortalFigures figures;

  /// `YYYY-MM-DD`: the contract's own date, or its project's.
  final String? deadline;

  /// The contractor's own words for the date, e.g. "Before Lakshmi Puja".
  final String? deadlineLabel;

  /// Days left as the server counted them when it answered.
  final int? serverDaysLeft;
  final PortalTime time;

  bool get isClosed => status == 'CLOSED';

  /// The project first, then the order number: what a contractor calls a job.
  String get displayName => projectName ?? title ?? number;

  /// Whole days from [today] to the deadline, counted on the phone so that a
  /// copy kept for a week is still right. Falls back to the server's count.
  int? daysLeft(String today) {
    final DateTime? due = deadline == null ? null : DateTime.tryParse(deadline!);
    final DateTime? now = DateTime.tryParse(today);
    if (due == null || now == null) return serverDaysLeft;
    return DateTime.utc(due.year, due.month, due.day).difference(DateTime.utc(now.year, now.month, now.day)).inDays;
  }

  @override
  List<Object?> get props => <Object?>[
    id,
    number,
    title,
    status,
    projectName,
    projectCode,
    startDate,
    figures,
    deadline,
    deadlineLabel,
    serverDaysLeft,
    time,
  ];
}

class PortalSummary extends Equatable {
  const PortalSummary({this.figures = const PortalFigures(), this.contracts = 0, this.contractsAhead = 0});

  factory PortalSummary.fromJson(Map<String, dynamic> json) => PortalSummary(
    figures: PortalFigures.fromJson(json),
    contracts: _int(json['contracts']) ?? 0,
    contractsAhead: _int(json['contracts_ahead']) ?? 0,
  );

  final PortalFigures figures;
  final int contracts;
  final int contractsAhead;

  @override
  List<Object?> get props => <Object?>[figures, contracts, contractsAhead];
}

class PortalLine extends Equatable {
  const PortalLine({
    this.code,
    this.description,
    this.unit,
    this.qty,
    this.rate,
    this.value,
    this.doneQty,
    this.doneValue,
    this.balanceQty,
    this.workPct,
  });

  factory PortalLine.fromJson(Map<String, dynamic> json) => PortalLine(
    code: _text(json['code']),
    description: _text(json['description']),
    unit: _text(json['unit']),
    qty: _qty(json['qty']),
    rate: _money(json['rate']),
    value: _money(json['value']),
    doneQty: _qty(json['done_qty']),
    doneValue: _money(json['done_value']),
    balanceQty: _qty(json['balance_qty']),
    workPct: _text(json['work_pct']),
  );

  final String? code;
  final String? description;
  final String? unit;
  final Qty? qty;
  final Money? rate;
  final Money? value;
  final Qty? doneQty;
  final Money? doneValue;
  final Qty? balanceQty;
  final String? workPct;

  String get displayName => <String>[?code, ?description].join(' · ');

  @override
  List<Object?> get props => <Object?>[code, description, unit, qty, rate, value, doneQty, doneValue, balanceQty, workPct];
}

/// A part of a contract, paid when it is done. "Part" is the web's word.
class PortalPart extends Equatable {
  const PortalPart({
    required this.title,
    this.amount,
    this.paid,
    this.balance,
    this.state,
    this.done = false,
    this.doneOn,
  });

  factory PortalPart.fromJson(Map<String, dynamic> json) => PortalPart(
    title: _text(json['title']) ?? '',
    amount: _money(json['amount']),
    paid: _money(json['paid']),
    balance: _money(json['balance']),
    state: _text(json['state']),
    done: json['done'] == true,
    doneOn: _text(json['done_on']),
  );

  final String title;
  final Money? amount;
  final Money? paid;
  final Money? balance;

  /// `not_paid`, `part_paid`, `paid` or `over`.
  final String? state;
  final bool done;
  final String? doneOn;

  @override
  List<Object?> get props => <Object?>[title, amount, paid, balance, state, done, doneOn];
}

class PortalPayment extends Equatable {
  const PortalPayment({
    required this.id,
    required this.number,
    this.paidOn,
    this.amount,
    this.mode,
    this.modeLabel,
    this.paidTo,
    this.reference,
    this.isAdvance = false,
    this.isReversal = false,
    this.workOrderId,
    this.workOrderNumber,
    this.workOrderTitle,
    this.project,
    this.against,
  });

  static PortalPayment? tryFromJson(Map<String, dynamic> json) {
    final int? id = _int(json['id']);
    if (id == null) return null;
    return PortalPayment(
      id: id,
      number: _text(json['number']) ?? '',
      paidOn: _text(json['paid_on']),
      amount: _money(json['amount']),
      mode: _text(json['mode']),
      modeLabel: _text(json['mode_label']),
      paidTo: _text(json['paid_to']),
      reference: _text(json['reference']),
      isAdvance: json['is_advance'] == true,
      isReversal: json['is_reversal'] == true,
      workOrderId: _int(json['work_order_id']),
      workOrderNumber: _text(json['work_order_number']),
      workOrderTitle: _text(json['work_order_title']),
      project: _text(json['project']),
      against: _text(json['against']),
    );
  }

  final int id;
  final String number;
  final String? paidOn;
  final Money? amount;
  final String? mode;

  /// The server's word for the mode, in the language it answered in.
  final String? modeLabel;
  final String? paidTo;
  final String? reference;
  final bool isAdvance;
  final bool isReversal;
  final int? workOrderId;
  final String? workOrderNumber;
  final String? workOrderTitle;
  final String? project;

  /// The BOQ line or part this payment was made against, when it names one.
  final String? against;

  @override
  List<Object?> get props => <Object?>[
    id,
    number,
    paidOn,
    amount,
    mode,
    modeLabel,
    paidTo,
    reference,
    isAdvance,
    isReversal,
    workOrderId,
    workOrderNumber,
    workOrderTitle,
    project,
    against,
  ];
}

class PortalBillLine extends Equatable {
  const PortalBillLine({
    this.code,
    this.description,
    this.unit,
    this.rate,
    this.prevQty,
    this.currentQty,
    this.cumulativeQty,
    this.currentAmount,
    this.cumulativeAmount,
  });

  factory PortalBillLine.fromJson(Map<String, dynamic> json) => PortalBillLine(
    code: _text(json['code']),
    description: _text(json['description']),
    unit: _text(json['unit']),
    rate: _money(json['rate']),
    prevQty: _qty(json['prev_qty']),
    currentQty: _qty(json['current_qty']),
    cumulativeQty: _qty(json['cumulative_qty']),
    currentAmount: _money(json['current_amount']),
    cumulativeAmount: _money(json['cumulative_amount']),
  );

  final String? code;
  final String? description;
  final String? unit;
  final Money? rate;
  final Qty? prevQty;
  final Qty? currentQty;
  final Qty? cumulativeQty;
  final Money? currentAmount;
  final Money? cumulativeAmount;

  String get displayName => <String>[?code, ?description].join(' · ');

  @override
  List<Object?> get props => <Object?>[
    code,
    description,
    unit,
    rate,
    prevQty,
    currentQty,
    cumulativeQty,
    currentAmount,
    cumulativeAmount,
  ];
}

class PortalDeduction extends Equatable {
  const PortalDeduction({required this.label, this.pct, this.amount});

  factory PortalDeduction.fromJson(Map<String, dynamic> json) =>
      PortalDeduction(label: _text(json['label']) ?? '', pct: _text(json['pct']), amount: _money(json['amount']));

  final String label;
  final String? pct;
  final Money? amount;

  @override
  List<Object?> get props => <Object?>[label, pct, amount];
}

/// A bill the company has approved. The list rows carry the totals; the lines
/// and deductions arrive with `GET /contractor-portal/bills/{bill}`.
class PortalBill extends Equatable {
  const PortalBill({
    required this.id,
    required this.number,
    this.isFinal = false,
    this.status,
    this.workOrderId,
    this.workOrderNumber,
    this.project,
    this.periodFrom,
    this.periodTo,
    this.gross,
    this.deductionTotal,
    this.gst,
    this.retentionReleased,
    this.net,
    this.paid,
    this.paidAt,
    this.lines,
    this.deductions,
  });

  static PortalBill? tryFromJson(Map<String, dynamic> json) {
    final int? id = _int(json['id']);
    if (id == null) return null;
    return PortalBill(
      id: id,
      number: _text(json['number']) ?? '',
      isFinal: json['is_final'] == true,
      status: _text(json['status']),
      workOrderId: _int(json['work_order_id']),
      workOrderNumber: _text(json['work_order_number']),
      project: _text(json['project']),
      periodFrom: _text(json['period_from']),
      periodTo: _text(json['period_to']),
      gross: _money(json['gross_amount']),
      deductionTotal: _money(json['deduction_total']),
      gst: _money(json['gst_amount']),
      retentionReleased: _money(json['retention_released']),
      net: _money(json['net_payable']),
      paid: _money(json['paid_amount']),
      paidAt: _text(json['paid_at']),
      lines: json['items'] is List ? _rows(json['items']).map(PortalBillLine.fromJson).toList(growable: false) : null,
      deductions: json['deductions'] is List
          ? _rows(json['deductions']).map(PortalDeduction.fromJson).toList(growable: false)
          : null,
    );
  }

  final int id;
  final String number;
  final bool isFinal;

  /// `APPROVED` or `PAID`. A bill still in the company's hands never arrives.
  final String? status;
  final int? workOrderId;
  final String? workOrderNumber;
  final String? project;
  final String? periodFrom;
  final String? periodTo;
  final Money? gross;
  final Money? deductionTotal;
  final Money? gst;
  final Money? retentionReleased;
  final Money? net;
  final Money? paid;
  final String? paidAt;

  /// Null on a list row, which has not been opened yet.
  final List<PortalBillLine>? lines;
  final List<PortalDeduction>? deductions;

  bool get isPaid => status == 'PAID';

  bool get hasLines => lines != null;

  @override
  List<Object?> get props => <Object?>[
    id,
    number,
    isFinal,
    status,
    workOrderId,
    workOrderNumber,
    project,
    periodFrom,
    periodTo,
    gross,
    deductionTotal,
    gst,
    retentionReleased,
    net,
    paid,
    paidAt,
    lines,
    deductions,
  ];
}

/// One contract in full, as `GET /contractor-portal/work-orders/{id}` gives it.
class PortalContractDetail extends Equatable {
  const PortalContractDetail({
    required this.contract,
    this.lines = const <PortalLine>[],
    this.parts = const <PortalPart>[],
    this.payments = const <PortalPayment>[],
    this.bills = const <PortalBill>[],
  });

  static PortalContractDetail? tryFromJson(Map<String, dynamic> json) {
    final PortalContract? contract = PortalContract.tryFromJson(json);
    if (contract == null) return null;
    return PortalContractDetail(
      contract: contract,
      lines: _rows(json['lines']).map(PortalLine.fromJson).toList(growable: false),
      parts: _rows(json['parts']).map(PortalPart.fromJson).toList(growable: false),
      payments: <PortalPayment>[
        for (final Map<String, dynamic> row in _rows(json['payments'])) ?PortalPayment.tryFromJson(row),
      ],
      bills: <PortalBill>[for (final Map<String, dynamic> row in _rows(json['bills'])) ?PortalBill.tryFromJson(row)],
    );
  }

  final PortalContract contract;
  final List<PortalLine> lines;
  final List<PortalPart> parts;
  final List<PortalPayment> payments;
  final List<PortalBill> bills;

  @override
  List<Object?> get props => <Object?>[contract, lines, parts, payments, bills];
}

/// Everything the portal last read, kept on the phone as one JSON value.
class PortalSnapshot extends Equatable {
  const PortalSnapshot({
    this.refused = false,
    this.partyId,
    this.contractorName,
    this.summary = const PortalSummary(),
    this.contracts = const <PortalContract>[],
    this.payments = const <PortalPayment>[],
    this.bills = const <PortalBill>[],
    this.details = const <int, PortalContractDetail>{},
    this.billDetails = const <int, PortalBill>{},
    this.fetchedAt,
  });

  factory PortalSnapshot.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> overview = _map(json['overview']);
    final Map<String, dynamic> contractor = _map(overview['contractor']);
    final Map<String, dynamic> details = _map(json['contracts']);
    final Map<String, dynamic> bills = _map(json['bill_details']);
    return PortalSnapshot(
      refused: json['refused'] == true,
      partyId: _int(json['party_id']),
      contractorName: _text(contractor['name']),
      summary: PortalSummary.fromJson(_map(overview['summary'])),
      contracts: <PortalContract>[
        for (final Map<String, dynamic> row in _rows(overview['contracts'])) ?PortalContract.tryFromJson(row),
      ],
      payments: <PortalPayment>[
        for (final Map<String, dynamic> row in _rows(json['payments'])) ?PortalPayment.tryFromJson(row),
      ],
      bills: <PortalBill>[for (final Map<String, dynamic> row in _rows(json['bills'])) ?PortalBill.tryFromJson(row)],
      details: <int, PortalContractDetail>{
        for (final MapEntry<String, dynamic> entry in details.entries)
          if (entry.value is Map) ..._detail(entry.value as Map),
      },
      billDetails: <int, PortalBill>{
        for (final MapEntry<String, dynamic> entry in bills.entries)
          if (entry.value is Map) ..._bill(entry.value as Map),
      },
      fetchedAt: DateTime.tryParse('${json['fetched_at'] ?? ''}'),
    );
  }

  static Map<int, PortalContractDetail> _detail(Map<dynamic, dynamic> raw) {
    final PortalContractDetail? detail = PortalContractDetail.tryFromJson(raw.cast<String, dynamic>());
    return detail == null ? const <int, PortalContractDetail>{} : <int, PortalContractDetail>{detail.contract.id: detail};
  }

  static Map<int, PortalBill> _bill(Map<dynamic, dynamic> raw) {
    final PortalBill? bill = PortalBill.tryFromJson(raw.cast<String, dynamic>());
    return bill == null ? const <int, PortalBill>{} : <int, PortalBill>{bill.id: bill};
  }

  /// The server said this login may not use the portal.
  final bool refused;
  final int? partyId;
  final String? contractorName;
  final PortalSummary summary;
  final List<PortalContract> contracts;
  final List<PortalPayment> payments;
  final List<PortalBill> bills;
  final Map<int, PortalContractDetail> details;
  final Map<int, PortalBill> billDetails;
  final DateTime? fetchedAt;

  PortalContract? contract(int id) {
    final PortalContractDetail? detail = details[id];
    if (detail != null) return detail.contract;
    for (final PortalContract row in contracts) {
      if (row.id == id) return row;
    }
    return null;
  }

  /// The bill with its lines when they have been read, else its list row.
  PortalBill? bill(int id) {
    final PortalBill? opened = billDetails[id];
    if (opened != null) return opened;
    for (final PortalBill row in bills) {
      if (row.id == id) return row;
    }
    return null;
  }

  @override
  List<Object?> get props => <Object?>[
    refused,
    partyId,
    contractorName,
    summary,
    contracts,
    payments,
    bills,
    details,
    billDetails,
    fetchedAt,
  ];
}
