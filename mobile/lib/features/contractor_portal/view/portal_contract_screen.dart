import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../contractor_control/view/widgets/contractor_kit.dart' show ContractorWords;
import '../../measurement/view/widgets/book_kit.dart';
import '../../site_progress/view/widgets/progress_widgets.dart' show ProgressBarTile;
import '../bloc/portal_cubit.dart';
import '../data/portal_models.dart';
import 'widgets/portal_kit.dart';

/// One contract: how far the work has come, what each line is worth, the
/// parts it is paid in, and the payments and bills against it.
class PortalContractScreen extends StatelessWidget {
  const PortalContractScreen({super.key, required this.workOrderId});

  final int workOrderId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PortalCubit>(
      create: (context) => PortalCubit(context.read<AppServices>(), contractId: workOrderId),
      child: PortalPage(
        title: (context, snapshot) =>
            snapshot?.contract(workOrderId)?.displayName ?? AppLocalizations.of(context).contractorContract,
        subtitle: (context, snapshot) => snapshot?.contract(workOrderId)?.number,
        available: (snapshot) => snapshot.contract(workOrderId) != null,
        body: (context, snapshot) => _body(context, snapshot, snapshot.contract(workOrderId)!, snapshot.details[workOrderId]),
      ),
    );
  }

  List<Widget> _body(BuildContext context, PortalSnapshot snapshot, PortalContract contract, PortalContractDetail? detail) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? when = deadlineWords(context, contract);
    final String? title = contract.title;
    return <Widget>[
      PortalFigureCard(
        key: const ValueKey<String>('portal-contract-summary'),
        figures: contract.figures,
        heading: title,
        subheading: contract.projectName,
        extra: <Widget>[if (when != null) BookRow.words(label: l.portalDeadlineLabel, value: when, ruled: false)],
      ),
      if (detail != null && detail.lines.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        LedgerList(
          header: portalHeader(l.contractorLines),
          children: <Widget>[for (final PortalLine line in detail.lines) _LineTile(line: line)],
        ),
      ],
      if (detail != null && detail.parts.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        LedgerList(
          header: portalHeader(l.contractorParts),
          children: <Widget>[for (final PortalPart part in detail.parts) _PartTile(part: part)],
        ),
      ],
      if (detail != null && detail.payments.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        LedgerList(
          header: portalHeader(l.portalPaymentsTitle),
          children: <Widget>[
            for (final PortalPayment payment in detail.payments) PortalPaymentTile(payment: payment, showContract: false),
          ],
        ),
      ],
      if (detail != null && detail.bills.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.cardGap),
        LedgerList(
          header: portalHeader(l.portalBillsTitle),
          children: <Widget>[
            for (final PortalBill bill in detail.bills)
              PortalBillTile(bill: bill, showContract: false, onTap: () => context.push(Routes.portalBill(bill.id))),
          ],
        ),
      ],
    ];
  }
}

class _LineTile extends StatelessWidget {
  const _LineTile({required this.line});

  final PortalLine line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = line.unit ?? '';
    final String? progress = line.doneQty == null || line.qty == null
        ? null
        : l.portalLineDone(line.doneQty!.format(), line.qty!.format(), unit).trim();
    final String? rate = line.rate == null ? null : l.portalLineRate(AppFormat.rupees(line.rate!), unit).trim();
    final String? worth = line.doneValue == null || line.value == null
        ? null
        : l.portalLineWorth(AppFormat.rupees(line.doneValue!), AppFormat.rupees(line.value!));
    return ProgressBarTile(
      title: line.displayName.isEmpty ? l.contractorContract : line.displayName,
      percent: line.workPct,
      caption: <String>[?progress, ?rate, ?worth].join('\n').nullIfEmpty,
    );
  }
}

class _PartTile extends StatelessWidget {
  const _PartTile({required this.part});

  final PortalPart part;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? doneOn = part.doneOn;
    final String subtitle = <String>[
      if (part.amount != null) AppFormat.rupees(part.amount!),
      if (part.state != null) l.partStateWord(part.state!),
      if (doneOn != null) dayLabel(context, doneOn, context.read<AppServices>().context.today),
    ].join(' · ');
    return LedgerListTile(
      title: part.title,
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: StatusPill(
        kind: part.done ? StampKind.approved : StampKind.draft,
        label: part.done ? l.contractorPartDone : l.contractorPartNotDone,
      ),
    );
  }
}

extension on String {
  String? get nullIfEmpty => isEmpty ? null : this;
}
