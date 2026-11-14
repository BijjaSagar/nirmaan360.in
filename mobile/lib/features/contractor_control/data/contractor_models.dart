import 'dart:convert';

import 'package:equatable/equatable.dart';

import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? <Map<String, dynamic>>[
        for (final Object? row in value)
          if (row is Map) row.cast<String, dynamic>(),
      ]
    : const <Map<String, dynamic>>[];

Money? _money(Object? raw) => raw == null ? null : Money.fromWire(raw.toString());

/// The figures on a contract or a BOQ line. The whole set is null when the
/// server withheld it, so a screen cannot show half of it or a zero in its place.
class ContractFigures extends Equatable {
  const ContractFigures({
    required this.contractValue,
    required this.paid,
    this.advance,
    this.paidPct,
    this.gapPct,
    this.limitPct,
    this.label,
    this.ratioStatus,
    this.overLimit = false,
    this.balanceWorkValue,
    this.balanceMoney,
    this.shortBy,
  });

  /// Null when the presenter left the figures out.
  static ContractFigures? tryFromJson(Map<String, dynamic> json) {
    final Money? contractValue = _money(json['contract_value']);
    final Money? paid = _money(json['paid']);
    if (contractValue == null || paid == null) return null;
    return ContractFigures(
      contractValue: contractValue,
      paid: paid,
      advance: _money(json['advance']),
      paidPct: json['paid_pct']?.toString(),
      gapPct: json['gap_pct']?.toString(),
      limitPct: json['limit_pct']?.toString(),
      label: json['label'] as String?,
      ratioStatus: json['ratio_status'] as String?,
      overLimit: json['over_limit'] == true,
      balanceWorkValue: _money(json['balance_work_value']),
      balanceMoney: _money(json['balance_money']),
      shortBy: _money(json['short_by']),
    );
  }

  final Money contractValue;
  final Money paid;
  final Money? advance;
  final String? paidPct;
  final String? gapPct;
  final String? limitPct;

  /// The server's own "Paid 90%, Work 70%".
  final String? label;

  /// `red` when paid is ahead of work, `green` when not, `none` with no value.
  final String? ratioStatus;
  final bool overLimit;
  final Money? balanceWorkValue;
  final Money? balanceMoney;
  final Money? shortBy;

  bool get paidAhead => ratioStatus == 'red';

  @override
  List<Object?> get props => <Object?>[
    contractValue,
    paid,
    advance,
    paidPct,
    gapPct,
    limitPct,
    label,
    ratioStatus,
    overLimit,
    balanceWorkValue,
    balanceMoney,
    shortBy,
  ];
}

class WorkContract extends Equatable {
  const WorkContract({
    required this.workOrderId,
    required this.number,
    this.title,
    this.contractor,
    this.status,
    this.workPct,
    this.balanceWorkPct,
    this.figures,
  });

  factory WorkContract.fromJson(Map<String, dynamic> json) => WorkContract(
    workOrderId: (json['work_order_id'] as num).toInt(),
    number: json['number'] as String? ?? '',
    title: json['title'] as String?,
    contractor: json['contractor'] as String?,
    status: json['contract_status'] as String?,
    workPct: json['work_pct']?.toString(),
    balanceWorkPct: json['balance_work_pct']?.toString(),
    figures: ContractFigures.tryFromJson(json),
  );

  final int workOrderId;
  final String number;
  final String? title;
  final String? contractor;
  final String? status;
  final String? workPct;
  final String? balanceWorkPct;

  /// Null for anyone without `money.view`.
  final ContractFigures? figures;

  bool get isClosed => status == 'CLOSED';

  /// The name to call this contract by.
  String get displayName => contractor ?? number;

  WorkContract withoutFigures() => WorkContract(
    workOrderId: workOrderId,
    number: number,
    title: title,
    contractor: contractor,
    status: status,
    workPct: workPct,
    balanceWorkPct: balanceWorkPct,
  );

  @override
  List<Object?> get props => <Object?>[workOrderId, number, title, contractor, status, workPct, balanceWorkPct, figures];
}

class ContractLine extends Equatable {
  const ContractLine({
    this.boqItemId,
    this.code,
    this.description,
    this.unit,
    this.workPct,
    this.balanceWorkPct,
    this.figures,
  });

  factory ContractLine.fromJson(Map<String, dynamic> json) => ContractLine(
    boqItemId: (json['boq_item_id'] as num?)?.toInt(),
    code: json['code'] as String?,
    description: json['description'] as String?,
    unit: json['unit'] as String?,
    workPct: json['work_pct']?.toString(),
    balanceWorkPct: json['balance_work_pct']?.toString(),
    figures: ContractFigures.tryFromJson(json),
  );

  final int? boqItemId;
  final String? code;
  final String? description;
  final String? unit;
  final String? workPct;
  final String? balanceWorkPct;
  final ContractFigures? figures;

  String get displayName => <String>[?code, ?description].join(' · ');

  ContractLine withoutFigures() => ContractLine(
    boqItemId: boqItemId,
    code: code,
    description: description,
    unit: unit,
    workPct: workPct,
    balanceWorkPct: balanceWorkPct,
  );

  @override
  List<Object?> get props => <Object?>[boqItemId, code, description, unit, workPct, balanceWorkPct, figures];
}

/// A milestone of the contract. "Part" is the word the web uses.
class ContractPart extends Equatable {
  const ContractPart({
    required this.id,
    required this.title,
    required this.done,
    this.doneOn,
    this.amount,
    this.paid,
    this.balance,
    this.state,
  });

  factory ContractPart.fromJson(Map<String, dynamic> json) => ContractPart(
    id: (json['id'] as num).toInt(),
    title: json['title'] as String? ?? '',
    done: json['done'] == true,
    doneOn: json['done_on'] as String?,
    amount: _money(json['amount']),
    paid: _money(json['paid']),
    balance: _money(json['balance']),
    state: json['state'] as String?,
  );

  final int id;
  final String title;
  final bool done;
  final String? doneOn;

  /// All null for anyone without `money.view`.
  final Money? amount;
  final Money? paid;
  final Money? balance;
  final String? state;

  ContractPart withoutFigures() => ContractPart(id: id, title: title, done: done, doneOn: doneOn);

  @override
  List<Object?> get props => <Object?>[id, title, done, doneOn, amount, paid, balance, state];
}

class ContractDetail extends Equatable {
  const ContractDetail({required this.contract, required this.lines, required this.parts});

  factory ContractDetail.fromJson(Map<String, dynamic> json) => ContractDetail(
    contract: WorkContract.fromJson(json),
    lines: _rows(json['lines']).map(ContractLine.fromJson).toList(growable: false),
    parts: _rows(json['milestones']).map(ContractPart.fromJson).toList(growable: false),
  );

  final WorkContract contract;
  final List<ContractLine> lines;
  final List<ContractPart> parts;

  ContractDetail withoutFigures() => ContractDetail(
    contract: contract.withoutFigures(),
    lines: lines.map((ContractLine line) => line.withoutFigures()).toList(growable: false),
    parts: parts.map((ContractPart part) => part.withoutFigures()).toList(growable: false),
  );

  @override
  List<Object?> get props => <Object?>[contract, lines, parts];
}

/// What only someone with `money.view` is sent about a payment.
class PaymentFigures extends Equatable {
  const PaymentFigures({
    required this.amount,
    this.mode,
    this.paidTo,
    this.reference,
    this.isAdvance = false,
    this.notes,
    this.hasBill = false,
    this.isReversal = false,
  });

  final Money amount;
  final String? mode;
  final String? paidTo;
  final String? reference;
  final bool isAdvance;
  final String? notes;
  final bool hasBill;
  final bool isReversal;

  @override
  List<Object?> get props => <Object?>[amount, mode, paidTo, reference, isAdvance, notes, hasBill, isReversal];
}

/// One payment request, the server's or one still on this phone.
class ContractorPayment extends Equatable {
  const ContractorPayment({
    this.id,
    this.number,
    this.status,
    this.workOrderId,
    this.workOrderNumber,
    this.contractor,
    this.paidOn,
    this.enteredBy,
    this.clientUuid,
    this.figures,
    this.unsent,
  });

  factory ContractorPayment.fromJson(Map<String, dynamic> json) {
    final Money? amount = _money(json['amount']);
    return ContractorPayment(
      id: (json['id'] as num?)?.toInt(),
      number: json['number'] as String?,
      status: json['status'] as String?,
      workOrderId: (json['work_order_id'] as num?)?.toInt(),
      workOrderNumber: json['work_order_number'] as String?,
      contractor: json['contractor'] as String?,
      paidOn: json['paid_on'] as String?,
      enteredBy: json['entered_by'] as String?,
      clientUuid: json['client_uuid'] as String?,
      figures: amount == null
          ? null
          : PaymentFigures(
              amount: amount,
              mode: json['mode'] as String?,
              paidTo: json['paid_to'] as String?,
              reference: json['reference'] as String?,
              isAdvance: json['is_advance'] == true,
              notes: json['notes'] as String?,
              hasBill: json['has_bill'] == true,
              isReversal: json['is_reversal'] == true,
            ),
    );
  }

  final int? id;
  final String? number;

  /// A `DocStatus` value. Null while still on the phone.
  final String? status;
  final int? workOrderId;
  final String? workOrderNumber;
  final String? contractor;

  /// `YYYY-MM-DD`.
  final String? paidOn;
  final String? enteredBy;
  final String? clientUuid;

  /// Null for anyone without `money.view`.
  final PaymentFigures? figures;

  /// The outbox row while this has not reached the server.
  final OutboxRow? unsent;

  bool get notSent => unsent != null;

  bool get needsAttention => unsent?.status == OutboxStatus.attention;

  ContractorPayment withoutFigures() => ContractorPayment(
    id: id,
    number: number,
    status: status,
    workOrderId: workOrderId,
    workOrderNumber: workOrderNumber,
    contractor: contractor,
    paidOn: paidOn,
    enteredBy: enteredBy,
    clientUuid: clientUuid,
    unsent: unsent,
  );

  @override
  List<Object?> get props => <Object?>[
    id,
    number,
    status,
    workOrderId,
    workOrderNumber,
    contractor,
    paidOn,
    enteredBy,
    clientUuid,
    figures,
    unsent,
  ];
}

/// What approving a payment would do, in the server's own words.
class PaymentImpact extends Equatable {
  const PaymentImpact({this.now, this.after, this.item});

  /// Null when the server sent no impact, as for anyone without `money.view`.
  static PaymentImpact? tryFromJson(Object? json) {
    if (json is! Map) return null;
    String? label(Object? value) => value is Map ? value['label'] as String? : null;
    return PaymentImpact(now: label(json['now']), after: label(json['after']), item: label(json['item']));
  }

  final String? now;

  /// Null once the payment is no longer waiting for approval.
  final String? after;

  /// The BOQ line this payment is tagged to, if any.
  final String? item;

  @override
  List<Object?> get props => <Object?>[now, after, item];
}

/// The last contract board and payments this phone read for a project, with
/// requests still waiting to be sent laid over them.
class ContractorOverview extends Equatable {
  const ContractorOverview({
    required this.contracts,
    required this.details,
    required this.payments,
    this.morePayments = false,
    this.refused = false,
    this.fetchedAt,
  });

  factory ContractorOverview.fromJson(Map<String, dynamic> json) {
    final Object? details = json['contracts'];
    return ContractorOverview(
      contracts: _rows(json['board']).map(WorkContract.fromJson).toList(growable: false),
      details: details is Map
          ? <int, ContractDetail>{
              for (final MapEntry<Object?, Object?> entry in details.entries)
                if (entry.value is Map && int.tryParse('${entry.key}') != null)
                  int.parse('${entry.key}'): ContractDetail.fromJson((entry.value as Map).cast<String, dynamic>()),
            }
          : const <int, ContractDetail>{},
      payments: _rows(json['payments']).map(ContractorPayment.fromJson).toList(growable: false),
      morePayments: json['more_payments'] == true,
      refused: json['refused'] == true,
      fetchedAt: DateTime.tryParse(json['fetched_at'] as String? ?? ''),
    );
  }

  final List<WorkContract> contracts;
  final Map<int, ContractDetail> details;
  final List<ContractorPayment> payments;

  /// The list holds the latest page only and the server has older ones.
  final bool morePayments;

  /// The server said no, so there is nothing to show.
  final bool refused;
  final DateTime? fetchedAt;

  WorkContract? contract(int workOrderId) {
    for (final WorkContract row in contracts) {
      if (row.workOrderId == workOrderId) return row;
    }
    return null;
  }

  int get unsent => payments.where((ContractorPayment payment) => payment.notSent).length;

  int get aheadCount => contracts.where((WorkContract row) => row.figures?.paidAhead ?? false).length;

  int get overLimitCount => contracts.where((WorkContract row) => row.figures?.overLimit ?? false).length;

  /// True when at least one figure came through.
  bool get seesMoney => contracts.any((WorkContract row) => row.figures != null);

  /// A copy kept from before someone lost `money.view` must not show them what
  /// it held. The next read replaces it.
  ContractorOverview forViewer({required bool seesMoney}) {
    if (seesMoney) return this;
    return ContractorOverview(
      contracts: contracts.map((WorkContract row) => row.withoutFigures()).toList(growable: false),
      details: details.map((int id, ContractDetail detail) => MapEntry<int, ContractDetail>(id, detail.withoutFigures())),
      payments: payments.map((ContractorPayment row) => row.withoutFigures()).toList(growable: false),
      morePayments: morePayments,
      refused: refused,
      fetchedAt: fetchedAt,
    );
  }

  /// Requests on this phone go on top, newest first. A row the server has
  /// already stored is not shown twice while its reply is being resent.
  ContractorOverview withUnsent(List<OutboxRow> rows, {required bool seesMoney}) {
    final Set<String?> known = <String?>{for (final ContractorPayment row in payments) row.clientUuid};
    final List<ContractorPayment> waiting = <ContractorPayment>[
      for (final OutboxRow row in rows.reversed)
        if (row.type == OutboxType.contractorPayment && !known.contains(row.wireUuid)) _fromOutbox(row, seesMoney),
    ];
    if (waiting.isEmpty) return this;
    return ContractorOverview(
      contracts: contracts,
      details: details,
      payments: <ContractorPayment>[...waiting, ...payments],
      morePayments: morePayments,
      refused: refused,
      fetchedAt: fetchedAt,
    );
  }

  ContractorPayment _fromOutbox(OutboxRow row, bool seesMoney) {
    final Map<String, dynamic> data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
    final int? workOrderId = (data['work_order_id'] as num?)?.toInt();
    final WorkContract? work = workOrderId == null ? null : contract(workOrderId);
    final Money? amount = _money(data['amount']);
    return ContractorPayment(
      status: null,
      workOrderId: workOrderId,
      workOrderNumber: work?.number,
      contractor: work?.contractor,
      paidOn: data['paid_on'] as String?,
      clientUuid: row.wireUuid,
      figures: amount == null || !seesMoney
          ? null
          : PaymentFigures(
              amount: amount,
              mode: data['mode'] as String?,
              paidTo: data['paid_to'] as String?,
              reference: data['reference'] as String?,
              isAdvance: data['is_advance'] == 1,
              notes: data['notes'] as String?,
              hasBill: row.filePath != null,
            ),
      unsent: row,
    );
  }

  @override
  List<Object?> get props => <Object?>[contracts, details, payments, morePayments, refused, fetchedAt];
}
