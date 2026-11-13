import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../core/sync/clock.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../measurement/bloc/measurement_book.dart';
import '../../../contractor_control/view/widgets/contractor_kit.dart';
import '../../../measurement/view/widgets/book_kit.dart';
import '../../../site_progress/data/progress_models.dart' show percentText;
import '../../../site_progress/view/widgets/progress_widgets.dart' show percentFraction;
import '../../data/approval_repository.dart';
import 'approval_read.dart';

/// The full document behind an approval, read straight from the server's copy.
class ApprovalDocument extends StatelessWidget {
  const ApprovalDocument({super.key, required this.row, required this.today});

  final ApprovalRow row;
  final String today;

  @override
  Widget build(BuildContext context) {
    if (!row.reviewableHere) return const _WebOnlyDoc();
    return switch (row.docType) {
      'measurement' => _MeasurementDoc(doc: row.document, today: today),
      'dpr' => _DprDoc(doc: row.document, today: today),
      'ra_bill' => _BillDoc(doc: row.document, today: today),
      'bill_note' => _BillNoteDoc(doc: row.document),
      'contractor' => _ContractorPaymentDoc(doc: row.document, today: today, ownerOverride: row.ownerOverride),
      'vendor_bill' => _VendorBillDoc(doc: row.document, today: today),
      'indent' => _IndentDoc(doc: row.document, today: today),
      'rfi' => _RfiDoc(doc: row.document, today: today),
      _ => const _WebOnlyDoc(),
    };
  }
}

Widget _sheet(List<Widget> rows) {
  return LedgerCard(
    padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.sm, N360Space.lg, N360Space.md),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: rows),
  );
}

class _MeasurementDoc extends StatelessWidget {
  const _MeasurementDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Map<String, dynamic> boq = readMap(doc['boq_item']);
    final String unit = readText(boq['unit']) ?? '';
    final Qty? qty = readQty(doc['qty']);
    final Qty? nos = readQty(doc['nos']);
    final Qty? length = readQty(doc['length']);
    final Qty? breadth = readQty(doc['breadth']);
    final Qty? depth = readQty(doc['depth']);
    final Qty? balance = readQty(doc['balance_qty']);
    final String? measuredOn = readText(doc['measured_on']);
    final String? location = readText(doc['location']);
    final String? description = readText(doc['description']);
    final String? remarks = readText(doc['remarks']);
    final Color qtyInk = qty?.isNegative == true ? N360Colors.laterite : N360Colors.ink;
    String dim(Qty? value) => value == null ? '—' : bookFigure(value);

    return _sheet(<Widget>[
      if (readText(boq['code']) != null)
        BookRow(label: l.measurementItem, value: readText(boq['code'])!, hint: readText(boq['description'])),
      if (measuredOn != null) BookRow.words(label: l.measurementDateLabel, value: dayLabel(context, measuredOn, today)),
      if (location != null) BookRow.words(label: l.measurementLocationLabel, value: location),
      if (description != null) BookRow.words(label: l.measurementDescriptionLabel, value: description),
      BookRow(label: l.measurementNos, value: dim(nos)),
      BookRow(label: l.measurementLength, value: dim(length)),
      BookRow(label: l.measurementBreadth, value: dim(breadth)),
      BookRow(label: l.measurementDepth, value: dim(depth)),
      if (qty != null)
        BookRow(
          label: l.measurementQuantity,
          total: true,
          value: '${formatQty(qty)}${unit.isEmpty ? '' : ' $unit'}',
          valueStyle: N360Type.figure.copyWith(color: qtyInk),
        ),
      if (balance != null)
        BookRow(label: l.measurementBalance, value: '${formatQty(balance)} $unit'.trim(), ruled: false),
      if (remarks != null) BookRow.words(label: l.approvalsRemarks, value: remarks, ruled: false),
    ]);
  }
}

class _DprDoc extends StatelessWidget {
  const _DprDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? date = readText(doc['report_date']);
    final String? weather = readText(doc['weather']);
    final String? summary = readText(doc['summary']);
    final String? hindrances = readText(doc['hindrances']);
    final String? total = readText(doc['manpower_total']);
    final List<Map<String, dynamic>> manpower = readList(doc['manpower']);
    final List<Map<String, dynamic>> work = readList(doc['work_items']);
    final List<Map<String, dynamic>> photos = readList(doc['photos']);

    return _sheet(<Widget>[
      if (date != null) BookRow.words(label: l.approvalsReportDate, value: dayLabel(context, date, today)),
      if (weather != null) BookRow.words(label: l.approvalsWeather, value: weather),
      if (summary != null) ...<Widget>[
        _Heading(l.approvalsSummary),
        Text(summary, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (hindrances != null) ...<Widget>[
        _Heading(l.approvalsHindrances),
        Text(hindrances, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (manpower.isNotEmpty || total != null) _Heading(l.approvalsManpower),
      for (final Map<String, dynamic> row in manpower)
        BookRow(label: readText(row['trade']) ?? '', value: readText(row['count']) ?? '—'),
      if (total != null) BookRow(label: l.approvalsTotal, value: total, total: true),
      if (work.isNotEmpty) _Heading(l.approvalsWorkDone),
      for (final Map<String, dynamic> row in work)
        BookRow(
          label: '${readText(row['code']) ?? ''} ${readText(row['description']) ?? ''}'.trim(),
          value: '${_qty(row['qty'])} ${readText(row['unit']) ?? ''}'.trim(),
        ),
      if (photos.isNotEmpty) BookRow(label: l.approvalsPhotos, value: '${photos.length}', ruled: false),
    ]);
  }

  String _qty(Object? raw) {
    final Qty? qty = readQty(raw);
    return qty == null ? '—' : formatQty(qty);
  }
}

class _BillDoc extends StatelessWidget {
  const _BillDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? number = readText(doc['number']);
    final String? party = readText(doc['party_name']);
    final String? type = readText(doc['type']);
    final String? from = readText(doc['period_from']);
    final String? to = readText(doc['period_to']);
    Money? money(String key) => readMoney(doc[key]);
    final Money? gross = money('gross_amount');
    final Money? deductions = money('deduction_total');
    final Money? gst = money('gst_amount');
    final Money? net = money('net_payable');

    String period() =>
        '${from == null ? '—' : dayLabel(context, from, today)} – ${to == null ? '—' : dayLabel(context, to, today)}';

    return _sheet(<Widget>[
      if (number != null) BookRow(label: l.approvalsBillNumber, value: number),
      if (party != null) BookRow.words(label: l.approvalsParty, value: party),
      if (type != null) BookRow.words(label: l.approvalsBillType, value: type),
      if (from != null || to != null) BookRow.words(label: l.approvalsPeriod, value: period()),
      if (gross != null) BookRow(label: l.approvalsGross, value: gross.inr()),
      if (deductions != null) BookRow(label: l.approvalsDeductions, value: deductions.inr()),
      if (gst != null) BookRow(label: l.approvalsGst, value: gst.inr()),
      if (net != null)
        BookRow(label: l.approvalsNetPayable, total: true, value: net.inr(), valueStyle: N360Type.figure),
    ]);
  }
}

/// A credit or debit note against a running bill.
class _BillNoteDoc extends StatelessWidget {
  const _BillNoteDoc({required this.doc});

  final Map<String, dynamic> doc;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? number = readText(doc['number']);
    final String? bill = readText(doc['ra_bill_number']);
    final String? party = readText(doc['party_name']);
    final String? reason = readText(doc['reason']);
    final String? kind = switch (readText(doc['kind'])) {
      'credit' => l.approvalsNoteCredit,
      'debit' => l.approvalsNoteDebit,
      final String? other => other,
    };
    Money? money(String key) => readMoney(doc[key]);
    final Money? taxable = money('taxable_amount');
    final Money? gst = money('gst_amount');
    final Money? total = money('total_amount');
    final Money? effect = money('net_effect');

    return _sheet(<Widget>[
      if (number != null) BookRow(label: l.approvalsNoteNumber, value: number),
      if (kind != null) BookRow.words(label: l.approvalsBillType, value: kind),
      if (bill != null) BookRow(label: l.approvalsNoteAgainstBill, value: bill),
      if (party != null) BookRow.words(label: l.approvalsParty, value: party),
      if (reason != null) ...<Widget>[
        _Heading(l.approvalsNoteReason),
        Text(reason, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (taxable != null) BookRow(label: l.approvalsNoteTaxable, value: taxable.inr()),
      if (gst != null) BookRow(label: l.approvalsGst, value: gst.inr()),
      if (total != null)
        BookRow(label: l.approvalsNoteTotal, total: true, value: total.inr(), valueStyle: N360Type.figure),
      if (effect != null) BookRow(label: l.approvalsNoteEffect, value: _signed(effect), ruled: false),
    ]);
  }

  /// A credit note takes money off the bill, so the sign has to be readable.
  String _signed(Money value) {
    final String text = value.inr();
    return text.startsWith('₹-') ? '−₹${text.substring(2)}' : '+$text';
  }
}

/// A contractor payment request: what it is for, and what approving it does to
/// the contract, as work done against paid. The figures are the server's own,
/// the same ones the contractor board shows.
class _ContractorPaymentDoc extends StatelessWidget {
  const _ContractorPaymentDoc({required this.doc, required this.today, required this.ownerOverride});

  final Map<String, dynamic> doc;
  final String today;
  final OwnerOverride ownerOverride;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Map<String, dynamic> order = readMap(doc['work_order']);
    final String contract = <String>[?readText(order['number']), ?readText(order['title'])].join(' · ');
    final String? contractor = readText(doc['contractor']);
    final String? paidOn = readText(doc['paid_on']);
    final String? enteredBy = readText(doc['entered_by']);
    final String? mode = readText(doc['mode']);
    final String? paidTo = readText(doc['paid_to']);
    final String? notes = readText(doc['notes']);
    final Money? amount = readMoney(doc['amount']);
    final int? paymentId = doc['id'] is num ? (doc['id'] as num).toInt() : null;
    final bool hasBill = doc['has_bill'] == true;
    final String? message = ownerOverride.message;

    final List<Widget> details = <Widget>[
      if (contract.isNotEmpty) BookRow.words(label: l.contractorContract, value: contract),
      if (contractor != null) BookRow.words(label: l.approvalsPayContractor, value: contractor),
      _againstRow(l, readMap(doc['against'])),
      if (paidOn != null) BookRow.words(label: l.contractorPaidOn, value: dayLabel(context, paidOn, today)),
      if (enteredBy != null) BookRow.words(label: l.contractorEnteredBy, value: enteredBy),
      if (doc['is_advance'] is bool)
        BookRow.words(
          label: l.contractorKind,
          value: doc['is_advance'] == true ? l.contractorKindAdvance : l.contractorKindWork,
        ),
      if (mode != null) BookRow.words(label: l.contractorMode, value: l.paymentModeWord(mode)),
      if (paidTo != null) BookRow.words(label: l.contractorPaidTo, value: paidTo),
      if (notes != null) ...<Widget>[
        _Heading(l.contractorNotes),
        Text(notes, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (amount != null)
        BookRow(
          label: l.contractorAmountLabel,
          total: true,
          value: amount.inr(),
          valueStyle: N360Type.figure,
          ruled: false,
        ),
      if (hasBill && paymentId != null) ...<Widget>[
        const SizedBox(height: N360Space.sm),
        GloveButton(
          key: const ValueKey<String>('approval-view-bill'),
          label: l.contractorViewBill,
          icon: Icons.receipt_long_outlined,
          kind: GloveButtonKind.secondary,
          fullWidth: true,
          onPressed: () => _viewBill(context, paymentId),
        ),
      ],
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        // The server's own words for the payment-ahead rule: why approving is stopped, or, before the last
        // approval level, that the last one will need the owner.
        if (message != null) ...<Widget>[
          TintedNote(
            key: const ValueKey<String>('approval-owner-override'),
            icon: Icons.gpp_maybe_outlined,
            ink: N360Colors.stampRed,
            edge: N360Colors.stampRed,
            title: switch (ownerOverride) {
              OwnerOverride(needed: true, allowed: true) => l.approvalsPayOverrideOwnerTitle,
              OwnerOverride(needed: true) => l.approvalsPayOverrideTitle,
              _ => l.approvalsPayOverrideLaterTitle,
            },
            body: message,
          ),
          const SizedBox(height: N360Space.md),
        ],
        _PaymentRatio(doc: doc, ruleExplained: message != null),
        const SizedBox(height: N360Space.md),
        _sheet(details),
      ],
    );
  }

  Widget _againstRow(AppLocalizations l, Map<String, dynamic> against) {
    switch (readText(against['type'])) {
      case 'boq_item':
        final String? code = readText(against['code']);
        return BookRow(
          label: l.contractorAgainst,
          value: code ?? l.contractorAgainstLine,
          hint: readText(against['description']),
        );
      case 'part':
        final String title = readText(against['title']) ?? l.contractorAgainstPart;
        return BookRow.words(
          label: l.contractorAgainst,
          value: against['done'] == true ? '$title · ${l.contractorPartDone}' : title,
        );
    }
    return BookRow.words(label: l.contractorAgainst, value: l.contractorAgainstWhole);
  }

  void _viewBill(BuildContext context, int paymentId) {
    final AppServices services = context.read<AppServices>();
    showLedgerSheet<void>(
      context,
      isScrollControlled: true,
      builder: (_) => _PaymentBillSheet(load: services.contractors.billBytes(paymentId)),
    );
  }
}

/// Work done against paid, now and if approved, with what the gap would be.
class _PaymentRatio extends StatelessWidget {
  const _PaymentRatio({required this.doc, this.ruleExplained = false});

  final Map<String, dynamic> doc;

  /// The server's message about the payment-ahead rule is on the page, so
  /// this card does not say the same thing again in its own words.
  final bool ruleExplained;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Map<String, dynamic> impact = readMap(doc['impact']);
    final Map<String, dynamic> now = readMap(impact['now']);
    final Map<String, dynamic> after = readMap(impact['after']);
    final Map<String, dynamic> line = readMap(impact['item']);
    final Map<String, dynamic> against = readMap(doc['against']);
    final Money? workValue = readMoney(readMap(doc['check'])['work_done']);
    final String? work = readText(doc['work_pct']) ?? readText(now['work_pct']);
    final String? paidNow = readText(doc['paid_pct']) ?? readText(now['paid_pct']);
    final String? paidAfter = readText(after['paid_pct']);
    final bool aheadNow = readText(now['ratio_status']) == 'red';
    final bool aheadAfter = readText(after['ratio_status']) == 'red';
    final bool overAfter = after['over_limit'] == true;
    final String? gapAfter = readText(after['gap_pct']);
    final String? limit = readText(after['limit_pct']) ?? readText(now['limit_pct']);
    final String? lineCode = readText(against['code']);

    final List<(String, Money?, bool)> money = <(String, Money?, bool)>[
      (l.contractorValue, readMoney(now['contract_value']), false),
      // The rule sets what is paid against the value of the work done, so that figure sits beside the payments.
      // An older server sends only the value of the work still to do.
      if (workValue != null) (l.approvalsPayWorkValue, workValue, false),
      (l.approvalsPayPaidNow, readMoney(now['paid']), false),
      if (after.isNotEmpty) (l.approvalsPayPaidAfter, readMoney(after['paid']), false),
      if (workValue == null) (l.contractorWorkLeftValue, readMoney(now['balance_work_value']), false),
      (l.contractorBalance, readMoney((after.isNotEmpty ? after : now)['balance_money']), true),
    ].where((row) => row.$2 != null).toList();

    // Past the limit with neither percent ahead: a contract with no value, where both read 0.
    final bool noValue = overAfter && !aheadAfter;

    String? aheadText() {
      if (ruleExplained) return null;
      if (noValue) return l.approvalsPayNoValue;
      if (!aheadAfter || gapAfter == null) return null;
      final String ahead = l.approvalsPayAhead(_points(gapAfter));
      return overAfter && limit != null ? '$ahead ${l.approvalsPayOverLimit(_points(limit))}' : ahead;
    }

    final String? warning = aheadText();

    return LedgerCard(
      key: const ValueKey<String>('approval-payment-ratio'),
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.md, N360Space.lg, N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.approvalsPayWorkVsPaid, style: N360Type.heading),
          const SizedBox(height: N360Space.sm),
          _Meter(label: l.contractorWorkDone, percent: work),
          if (paidNow != null) _Meter(label: l.approvalsPayPaidNow, percent: paidNow, ahead: aheadNow),
          if (paidAfter != null)
            _Meter(
              key: const ValueKey<String>('approval-paid-after'),
              label: l.approvalsPayPaidAfter,
              percent: paidAfter,
              ahead: aheadAfter || overAfter,
              strong: true,
            ),
          if (warning != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            TintedNote(
              key: const ValueKey<String>('approval-payment-ahead'),
              icon: Icons.warning_amber,
              ink: overAfter ? N360Colors.stampRed : N360Colors.laterite,
              edge: overAfter ? N360Colors.stampRed : N360Colors.laterite,
              title: overAfter ? l.contractorOverLimit : l.contractorPaidAhead,
              body: warning,
            ),
          ] else if (paidAfter != null && !aheadAfter && !overAfter)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xxs),
              child: Text(l.approvalsPayInStep, style: N360Type.bodyStrong.copyWith(color: N360Colors.moss)),
            ),
          if (money.isNotEmpty) const SizedBox(height: N360Space.sm),
          for (final (int index, (String label, Money? value, bool total)) in money.indexed)
            BookRow(label: label, value: value!.inr(), total: total, ruled: index < money.length - 1),
          if (line.isNotEmpty || readText(against['work_pct']) != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            BookRow.words(
              label: lineCode == null ? l.contractorImpactLine : '${l.contractorImpactLine} · $lineCode',
              value: readText(line['paid_pct']) == null
                  ? '${l.contractorWorkDone} ${percentText(readText(against['work_pct']))}'
                  : l.approvalsPayRatio(
                      percentText(readText(line['paid_pct'])),
                      percentText(readText(line['work_pct']) ?? readText(against['work_pct'])),
                    ),
              ruled: false,
            ),
          ],
        ],
      ),
    );
  }

  /// "10.00" as "10", "2.50" as "2.5".
  String _points(String raw) => percentText(raw).replaceAll('%', '');
}

/// One percent with its bar. Paid ahead of work fills in the urgent colour.
class _Meter extends StatelessWidget {
  const _Meter({super.key, required this.label, required this.percent, this.ahead = false, this.strong = false});

  final String label;
  final String? percent;
  final bool ahead;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final String value = percentText(percent);
    final Color ink = ahead ? N360Colors.laterite : N360Colors.ink;
    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text(label, style: N360Type.fit(strong ? N360Type.bodyStrong : N360Type.label, label))),
              Text(value, style: N360Type.figureSmall.copyWith(color: ink)),
            ],
          ),
          const SizedBox(height: N360Space.xxs),
          HatchedProgressBar(
            fraction: percentFraction(percent),
            height: 6,
            delayed: ahead,
            semanticLabel: '$label, $value',
          ),
        ],
      ),
    );
  }
}

/// A picture of the payment's bill. A PDF cannot be drawn here.
class _PaymentBillSheet extends StatelessWidget {
  const _PaymentBillSheet({required this.load});

  final Future<Uint8List> load;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    Widget message(String text) => Padding(
      padding: const EdgeInsets.all(N360Space.gutter),
      child: Text(text, style: N360Type.fit(N360Type.body, text)),
    );
    return SafeArea(
      top: false,
      child: FutureBuilder<Uint8List>(
        future: load,
        builder: (context, snapshot) {
          if (snapshot.hasError) return message(l.contractorBillFailed);
          final Uint8List? bytes = snapshot.data;
          if (bytes == null) {
            return Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(semanticsLabel: l.contractorLoading),
            );
          }
          return Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.lg),
            child: Image.memory(
              bytes,
              fit: BoxFit.contain,
              errorBuilder: (_, _, _) => message(l.contractorBillNotPicture),
            ),
          );
        },
      ),
    );
  }
}

/// A supplier's bill: who it is from, what it is for, and how the net payable
/// is reached from the taxable value.
class _VendorBillDoc extends StatelessWidget {
  const _VendorBillDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? number = readText(doc['number']);
    final String? party = readText(doc['party_name']);
    final String? billDate = readText(doc['bill_date']);
    final String? dueDate = readText(doc['due_date']);
    final String? costHead = readText(doc['cost_head']);
    final String? description = readText(doc['description']);
    final String? remarks = readText(doc['remarks']);
    final String? gstPct = readText(doc['gst_pct']);
    final String? tdsPct = readText(doc['tds_pct']);
    final String? tdsSection = readText(doc['tds_section']);
    Money? money(String key) => readMoney(doc[key]);
    final Money? taxable = money('taxable_amount');
    final Money? gst = money('gst_amount');
    final Money? total = money('total_amount');
    final Money? tds = money('tds_amount');
    final Money? net = money('net_payable');
    final String tdsLabel = <String>[
      l.approvalsVbTds,
      if (tdsPct != null) percentText(tdsPct),
      ?tdsSection,
    ].join(' · ');

    return _sheet(<Widget>[
      if (number != null) BookRow(label: l.approvalsBillNumber, value: number),
      if (party != null) BookRow.words(label: l.approvalsParty, value: party),
      if (billDate != null) BookRow.words(label: l.approvalsVbBillDate, value: dayLabel(context, billDate, today)),
      if (dueDate != null) BookRow.words(label: l.approvalsVbDueDate, value: dayLabel(context, dueDate, today)),
      if (costHead != null) BookRow.words(label: l.approvalsVbCostHead, value: costHead),
      if (description != null) ...<Widget>[
        _Heading(l.approvalsVbDescription),
        Text(description, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (remarks != null) ...<Widget>[
        _Heading(l.approvalsRemarks),
        Text(remarks, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (taxable != null) BookRow(label: l.approvalsNoteTaxable, value: taxable.inr()),
      if (gst != null)
        BookRow(label: gstPct == null ? l.approvalsGst : '${l.approvalsGst} ${percentText(gstPct)}', value: gst.inr()),
      if (total != null) BookRow(label: l.approvalsVbBillTotal, value: total.inr()),
      if (tds != null && !tds.isZero) BookRow(label: tdsLabel, value: '−${tds.inr()}'),
      if (net != null)
        BookRow(label: l.approvalsNetPayable, total: true, value: net.inr(), valueStyle: N360Type.figure, ruled: false),
    ]);
  }
}

/// A request for material from site: which store, by when, and every line asked for.
class _IndentDoc extends StatelessWidget {
  const _IndentDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? number = readText(doc['number']);
    final String? store = readText(doc['store']);
    final String? requiredBy = readText(doc['required_by']);
    final String? raisedBy = readText(doc['created_by_name']);
    final String? remarks = readText(doc['remarks']);
    final List<Map<String, dynamic>> items = readList(doc['items']);

    return _sheet(<Widget>[
      if (number != null) BookRow(label: l.approvalsDocIndent, value: number),
      if (store != null) BookRow.words(label: l.approvalsIndentStore, value: store),
      if (requiredBy != null)
        BookRow.words(label: l.approvalsIndentRequiredBy, value: dayLabel(context, requiredBy, today)),
      if (raisedBy != null) BookRow.words(label: l.approvalsRaisedBy, value: raisedBy),
      if (remarks != null) ...<Widget>[
        _Heading(l.approvalsRemarks),
        Text(remarks, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (items.isNotEmpty) _Heading(l.approvalsIndentItems),
      for (final (int index, Map<String, dynamic> item) in items.indexed)
        BookRow(
          label: readText(item['material']) ?? readText(item['material_code']) ?? '—',
          value: '${_qty(item['qty'])} ${readText(item['unit']) ?? ''}'.trim(),
          hint: _lineHint(l, item),
          ruled: index < items.length - 1,
        ),
    ]);
  }

  String _qty(Object? raw) {
    final Qty? qty = readQty(raw);
    return qty == null ? '—' : formatQty(qty);
  }

  /// Which BOQ line the material is for, and the sender's note on the line.
  String? _lineHint(AppLocalizations l, Map<String, dynamic> item) {
    final String? code = readText(readMap(item['boq_item'])['code']);
    final String hint = <String>[
      if (code != null) l.approvalsIndentForLine(code),
      ?readText(item['remarks']),
    ].join(' · ');
    return hint.isEmpty ? null : hint;
  }
}

/// A request for information, read before it goes out to the consultant.
class _RfiDoc extends StatelessWidget {
  const _RfiDoc({required this.doc, required this.today});

  final Map<String, dynamic> doc;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? number = readText(doc['number']);
    final String? subject = readText(doc['subject']);
    final String? question = readText(doc['question']);
    final String? drawing = readText(doc['drawing_ref']);
    final String? location = readText(doc['location']);
    final String? addressedTo = readText(doc['addressed_to']);
    final String? requiredBy = readText(doc['required_by']);
    final String? raisedBy = readText(doc['raised_by_name']);
    final String? priority = switch (readText(doc['priority'])) {
      'low' => l.designPriorityLow,
      'medium' => l.designPriorityMedium,
      'high' => l.designPriorityHigh,
      'critical' => l.designPriorityCritical,
      final String? other => other,
    };

    return _sheet(<Widget>[
      if (number != null) BookRow(label: l.approvalsDocRfi, value: number),
      if (subject != null) ...<Widget>[const SizedBox(height: N360Space.xs), Text(subject, style: N360Type.bodyStrong)],
      if (question != null) ...<Widget>[
        _Heading(l.approvalsRfiQuestion),
        Text(question, style: N360Type.body),
        const SizedBox(height: N360Space.sm),
      ],
      if (drawing != null) BookRow.words(label: l.approvalsRfiDrawing, value: drawing),
      if (location != null) BookRow.words(label: l.measurementLocationLabel, value: location),
      if (addressedTo != null) BookRow.words(label: l.approvalsRfiAddressedTo, value: addressedTo),
      if (priority != null) BookRow.words(label: l.issuesDetailPriority, value: priority),
      if (requiredBy != null) BookRow.words(label: l.approvalsRfiAnswerBy, value: dayLabel(context, requiredBy, today)),
      if (raisedBy != null) BookRow.words(label: l.approvalsRaisedBy, value: raisedBy, ruled: false),
    ]);
  }
}

/// A document this version of the app cannot lay out. It says where to go
/// rather than showing a title with buttons under it.
class _WebOnlyDoc extends StatelessWidget {
  const _WebOnlyDoc();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Icon(Icons.language, size: N360IconSize.md, color: N360Colors.ink2),
          const SizedBox(width: N360Space.sm),
          Expanded(child: Text(l.approvalsOpenOnWeb, style: N360Type.body)),
        ],
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: N360Space.sm, bottom: N360Space.xxs),
      child: SectionLabel(text),
    );
  }
}

/// The trail of a document: who sent it, who has acted, who is next.
class ApprovalChain extends StatelessWidget {
  const ApprovalChain({super.key, required this.row, required this.now});

  final ApprovalRow row;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<Map<String, dynamic>> steps = readList(row.steps);
    final int current = (row.currentLevel ?? 1).clamp(1, row.levels.length + 1);
    final List<String> pending = current <= row.levels.length ? row.levels.sublist(current - 1) : const <String>[];

    final List<Widget> rows = <Widget>[
      for (final Map<String, dynamic> step in steps)
        _ChainRow(
          state: _StationState.done,
          title: _stepTitle(l, step),
          line: _stepLine(l, step),
          remark: readText(step['remark']),
          last: pending.isEmpty && identical(step, steps.last),
        ),
      for (final (index, role) in pending.indexed)
        _ChainRow(
          state: index == 0 ? _StationState.current : _StationState.upcoming,
          title: roleWord(l, role, serverLabel: index == 0 ? row.currentRoleLabel : null),
          line: index == 0 ? l.approvalsChainWaiting : l.approvalsChainNext,
          last: index == pending.length - 1,
        ),
    ];

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.md, N360Space.lg, N360Space.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.approvalsChainTitle, style: N360Type.heading),
          const SizedBox(height: N360Space.sm),
          ...rows,
        ],
      ),
    );
  }

  String _stepTitle(AppLocalizations l, Map<String, dynamic> step) {
    final String action = switch (readText(step['action'])) {
      'submit' => l.approvalsActionSubmitted,
      'approve' => l.approvalsActionApproved,
      'return' => l.approvalsActionReturned,
      'reject' => l.approvalsActionRejected,
      final other => other ?? '',
    };
    final String actor = readText(step['actor']) ?? roleWord(l, readText(step['role']));
    return '$actor · $action';
  }

  String _stepLine(AppLocalizations l, Map<String, dynamic> step) {
    final String role = roleWord(l, readText(step['role']));
    final DateTime? at = parseServerTime(step['acted_at']);
    return <String>[if (role.isNotEmpty) role, if (at != null) agoText(l, at, now)].join(' · ');
  }
}

enum _StationState { done, current, upcoming }

class _ChainRow extends StatelessWidget {
  const _ChainRow({required this.state, required this.title, required this.line, required this.last, this.remark});

  final _StationState state;
  final String title;
  final String line;
  final String? remark;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final bool current = state == _StationState.current;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SizedBox(
            width: 28,
            child: CustomPaint(
              painter: _RailPainter(state: state, last: last),
            ),
          ),
          const SizedBox(width: N360Space.sm),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: N360Space.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    title,
                    style: N360Type.bodyStrong.copyWith(color: current ? N360Colors.laterite : N360Colors.ink),
                  ),
                  if (line.isNotEmpty) Text(line, style: N360Type.caption),
                  if (remark != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text('“$remark”', style: N360Type.body),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A survey line down the page, with a station for each person in the chain.
class _RailPainter extends CustomPainter {
  const _RailPainter({required this.state, required this.last});

  final _StationState state;
  final bool last;

  @override
  void paint(Canvas canvas, Size size) {
    const y = 12.0;
    final double x = size.width / 2;
    final Paint rail = Paint()
      ..color = N360Colors.ruleStrong
      ..strokeWidth = 1.5;
    if (!last) canvas.drawLine(Offset(x, y), Offset(x, size.height), rail);

    switch (state) {
      case _StationState.done:
        canvas.drawCircle(Offset(x, y), 9, Paint()..color = N360Colors.moss);
        final Path check = Path()
          ..moveTo(x - 4.2, y + 0.2)
          ..lineTo(x - 1, y + 3.4)
          ..lineTo(x + 4.6, y - 3);
        canvas.drawPath(
          check,
          Paint()
            ..color = N360Colors.sheet
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2.2
            ..strokeCap = StrokeCap.round
            ..strokeJoin = StrokeJoin.round,
        );
      case _StationState.current:
        canvas.drawCircle(Offset(x, y), 9, Paint()..color = N360Colors.primaryTint);
        canvas.drawCircle(
          Offset(x, y),
          9,
          Paint()
            ..color = N360Colors.primary
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2.5,
        );
        canvas.drawCircle(Offset(x, y), 3.5, Paint()..color = N360Colors.primary);
      case _StationState.upcoming:
        canvas.drawCircle(Offset(x, y), 8, Paint()..color = N360Colors.sheet);
        canvas.drawCircle(
          Offset(x, y),
          8,
          Paint()
            ..color = N360Colors.ruleStrong
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1.5,
        );
    }
  }

  @override
  bool shouldRepaint(_RailPainter oldDelegate) => oldDelegate.state != state || oldDelegate.last != last;
}
