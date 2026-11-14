import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../bloc/portal_cubit.dart';
import '../data/portal_models.dart';
import 'widgets/portal_kit.dart';

/// One approved bill: what was billed for the period, the deductions, and
/// what is payable.
class PortalBillScreen extends StatelessWidget {
  const PortalBillScreen({super.key, required this.billId});

  final int billId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PortalCubit>(
      create: (context) => PortalCubit(context.read<AppServices>(), billId: billId),
      child: PortalPage(
        title: (context, snapshot) {
          final String? number = snapshot?.bill(billId)?.number;
          return number == null || number.isEmpty ? AppLocalizations.of(context).portalBillsTitle : number;
        },
        subtitle: (context, snapshot) => snapshot?.bill(billId)?.project,
        available: (snapshot) => snapshot.bill(billId) != null,
        body: (context, snapshot) => _body(context, snapshot.bill(billId)!),
      ),
    );
  }

  List<Widget> _body(BuildContext context, PortalBill bill) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final String? period = billPeriod(context, bill);
    final List<(String, Money, bool)> money = <(String, Money, bool)>[
      if (bill.gross != null) (l.portalBillGross, bill.gross!, false),
      if (bill.deductionTotal != null && !bill.deductionTotal!.isZero) (l.portalBillDeductions, bill.deductionTotal!, false),
      if (bill.gst != null && !bill.gst!.isZero) (l.portalBillGst, bill.gst!, false),
      if (bill.retentionReleased != null && !bill.retentionReleased!.isZero)
        (l.portalBillRetentionReleased, bill.retentionReleased!, false),
      if (bill.net != null) (l.portalBillNet, bill.net!, true),
      if (bill.isPaid && bill.paid != null) (l.contractorPaid, bill.paid!, false),
    ];
    final List<PortalBillLine>? lines = bill.lines;
    final List<PortalDeduction> deductions = bill.deductions ?? const <PortalDeduction>[];
    return <Widget>[
      LedgerCard(
        key: const ValueKey<String>('portal-bill-summary'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Wrap(
              spacing: N360Space.sm,
              runSpacing: N360Space.xs,
              children: <Widget>[
                billPill(l, bill),
                if (bill.isFinal) StatusPill(kind: StampKind.draft, label: l.portalBillFinal),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            if (bill.workOrderNumber != null)
              BookRow.words(label: l.contractorContract, value: bill.workOrderNumber!, ruled: period != null || money.isNotEmpty),
            if (period != null) BookRow.words(label: l.portalBillPeriod, value: period, ruled: money.isNotEmpty),
            for (final (int index, (String label, Money value, bool total)) in money.indexed)
              BookRow(label: label, value: AppFormat.rupees(value), total: total, ruled: index < money.length - 1),
            if (bill.isPaid && bill.paidAt != null) ...<Widget>[
              const SizedBox(height: N360Space.xs),
              BookRow.words(label: l.contractorPaidOn, value: dayLabel(context, bill.paidAt!, today), ruled: false),
            ],
          ],
        ),
      ),
      if (lines == null) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        Text(l.portalBillLinesOnline, style: N360Type.fit(N360Type.caption, l.portalBillLinesOnline)),
      ] else if (lines.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        LedgerList(
          header: portalHeader(l.portalBillLines),
          children: <Widget>[for (final PortalBillLine line in lines) _LineTile(line: line)],
        ),
      ],
      if (deductions.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        LedgerList(
          header: portalHeader(l.portalBillDeductions),
          children: <Widget>[for (final PortalDeduction deduction in deductions) _DeductionTile(deduction: deduction)],
        ),
      ],
    ];
  }
}

class _LineTile extends StatelessWidget {
  const _LineTile({required this.line});

  final PortalBillLine line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = line.unit ?? '';
    final String? thisBill = line.currentQty == null || line.rate == null
        ? null
        : l.portalBillLineQty(line.currentQty!.format(), unit, AppFormat.rupees(line.rate!)).trim();
    final String? soFar = line.cumulativeQty == null ? null : l.portalBillLineSoFar(line.cumulativeQty!.format(), unit).trim();
    final String subtitle = <String>[?thisBill, ?soFar].join('\n');
    final Money? amount = line.currentAmount;
    return LedgerListTile(
      title: line.displayName.isEmpty ? l.portalBillLines : line.displayName,
      subtitle: subtitle.isEmpty ? null : subtitle,
      trailing: amount == null
          ? null
          : ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 110),
              child: FittedBox(fit: BoxFit.scaleDown, child: Text(AppFormat.rupees(amount), style: N360Type.figureSmall)),
            ),
    );
  }
}

class _DeductionTile extends StatelessWidget {
  const _DeductionTile({required this.deduction});

  final PortalDeduction deduction;

  @override
  Widget build(BuildContext context) {
    final Money? amount = deduction.amount;
    final String? pct = deduction.pct;
    return LedgerListTile(
      title: deduction.label,
      subtitle: pct == null ? null : '$pct%',
      trailing: amount == null
          ? null
          : ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 110),
              child: FittedBox(fit: BoxFit.scaleDown, child: Text(AppFormat.rupees(amount), style: N360Type.figureSmall)),
            ),
    );
  }
}
