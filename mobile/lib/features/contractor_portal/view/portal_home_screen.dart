import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../site_progress/view/widgets/progress_widgets.dart' show ProgressBarTile;
import '../bloc/portal_cubit.dart';
import '../data/portal_models.dart';
import 'widgets/portal_kit.dart';

/// Where a contractor login lives: every contract with its work done, what has
/// been paid against it, and the way into payments and bills. No project, no
/// site tabs; the company's own screens are not on this phone.
class PortalHomeScreen extends StatelessWidget {
  const PortalHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PortalCubit>(
      create: (context) => PortalCubit(context.read<AppServices>()),
      child: PortalPage(
        root: true,
        title: (context, snapshot) {
          final user = context.read<AppGate>().state.user;
          return snapshot?.contractorName ?? user?.contractorName ?? AppLocalizations.of(context).portalTitle;
        },
        subtitle: (context, snapshot) {
          final String? firm = context.read<AppGate>().state.user?.organizationName;
          return firm == null || firm.isEmpty ? null : firm;
        },
        body: _body,
      ),
    );
  }

  static List<Widget> _body(BuildContext context, PortalSnapshot snapshot) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String saved = savedAgo(context, snapshot);
    return <Widget>[
      PortalFigureCard(
        key: const ValueKey<String>('portal-summary'),
        figures: snapshot.summary.figures,
        heading: l.portalYourContracts,
        subheading: snapshot.summary.contracts == 0 ? null : l.contractorHomeCount(snapshot.summary.contracts),
      ),
      const SizedBox(height: N360Space.cardGap),
      LedgerList(
        children: <Widget>[
          LedgerListTile(
            key: const ValueKey<String>('portal-open-payments'),
            leading: const Icon(Icons.payments_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
            title: l.portalPaymentsTitle,
            subtitle: l.portalPaymentsCount(snapshot.payments.length),
            onTap: () => context.push(Routes.portalPayments),
          ),
          LedgerListTile(
            key: const ValueKey<String>('portal-open-bills'),
            leading: const Icon(Icons.receipt_long_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
            title: l.portalBillsTitle,
            subtitle: l.portalBillsCount(snapshot.bills.length),
            onTap: () => context.push(Routes.portalBills),
          ),
        ],
      ),
      const SizedBox(height: N360Space.cardGap),
      if (snapshot.contracts.isEmpty)
        EmptyLedger(title: l.contractorEmptyTitle, message: l.portalNoContractsMessage)
      else
        LedgerList(
          header: portalHeader(l.contractorTabContracts),
          children: <Widget>[for (final PortalContract contract in snapshot.contracts) _ContractTile(contract: contract)],
        ),
      if (saved.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.md),
        Text(saved, textAlign: TextAlign.center, style: N360Type.fit(N360Type.caption, saved)),
      ],
    ];
  }
}

class _ContractTile extends StatelessWidget {
  const _ContractTile({required this.contract});

  final PortalContract contract;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String reference = <String>[contract.number, ?contract.title].join(' · ');
    final String? versus = paidVersusWork(l, contract.figures);
    final String? when = deadlineWords(context, contract);
    final bool late = contract.time == PortalTime.overdue;
    return ProgressBarTile(
      key: ValueKey<String>('portal-contract-${contract.id}'),
      title: contract.displayName,
      percent: contract.figures.workPct,
      caption: <String>[if (reference.isNotEmpty) reference, ?versus, if (!late) ?when].join('\n'),
      alert: late ? when : null,
      onTap: () => context.push(Routes.portalContract(contract.id)),
    );
  }
}
