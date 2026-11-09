import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../../site_progress/data/progress_models.dart' show percentText;
import '../../site_progress/view/widgets/progress_widgets.dart' show ProgressBarTile, percentFraction;
import '../bloc/contractor_cubit.dart';
import '../data/contractor_repository.dart';
import 'widgets/contractor_kit.dart';

/// One contract: how far the work has come, and with `money.view` what has been
/// paid against it. A figure the server left out simply has no row here.
class ContractDetailScreen extends StatelessWidget {
  const ContractDetailScreen({super.key, required this.workOrderId});

  final int workOrderId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ContractorCubit>(
      create: (context) => ContractorCubit(context.read<AppServices>(), contractId: workOrderId),
      child: _DetailView(workOrderId: workOrderId),
    );
  }
}

class _DetailView extends StatelessWidget {
  const _DetailView({required this.workOrderId});

  final int workOrderId;

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<ContractorCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canRequest = context.select((AppGate gate) => gate.state.can('contractor.request'));
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<ContractorCubit, ContractorState>(
            builder: (context, state) {
              final ContractorOverview? overview = state.overview;
              final ContractDetail? detail = overview?.details[workOrderId];
              final WorkContract? contract = detail?.contract ?? overview?.contract(workOrderId);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: contract?.displayName ?? l.contractorContract, subtitle: contract?.number),
                  Expanded(
                    child: LedgerRefresh(
                      onRefresh: () => _refresh(context),
                      semanticsLabel: l.contractorLoading,
                      child: _body(context, l, state, contract, detail),
                    ),
                  ),
                  if (canRequest && contract != null && !contract.isClosed)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('contract-request'),
                        label: l.contractorRequest,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => context.push('${Routes.contractorRequest}?work_order=$workOrderId'),
                      ),
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _body(
    BuildContext context,
    AppLocalizations l,
    ContractorState state,
    WorkContract? contract,
    ContractDetail? detail,
  ) {
    if (contract == null) {
      final bool waiting = state.load == ContractorLoad.loading || state.refreshing;
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: <Widget>[
          if (waiting)
            Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(lines: 3, semanticsLabel: l.contractorLoading),
            )
          else if (state.overview?.refused ?? false)
            EmptyLedger(title: l.contractorRefusedTitle, message: l.contractorRefusedMessage)
          else
            EmptyLedger(title: l.progressNoDataTitle, message: l.contractorNoDataMessage),
        ],
      );
    }
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        _Summary(contract: contract),
        if (detail != null && detail.lines.isNotEmpty) ...<Widget>[
          const SizedBox(height: N360Space.cardGap),
          LedgerList(
            header: Text(l.contractorLines, style: N360Type.fit(N360Type.label, l.contractorLines)),
            children: <Widget>[
              for (final ContractLine line in detail.lines)
                ProgressBarTile(
                  title: line.displayName.isEmpty ? l.contractorContract : line.displayName,
                  percent: line.workPct,
                  caption: line.figures?.label,
                  alert: line.figures == null || !line.figures!.overLimit ? null : l.contractorOverLimit,
                ),
            ],
          ),
        ],
        if (detail != null && detail.parts.isNotEmpty) ...<Widget>[
          const SizedBox(height: N360Space.cardGap),
          LedgerList(
            header: Text(l.contractorParts, style: N360Type.fit(N360Type.label, l.contractorParts)),
            children: <Widget>[for (final ContractPart part in detail.parts) _PartTile(part: part)],
          ),
        ],
      ],
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.contract});

  final WorkContract contract;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ContractFigures? figures = contract.figures;
    final String? title = contract.title;
    final StatusPill? pill = figures == null ? null : ratioPill(l, figures);
    final List<(String, Money, bool)> rows = <(String, Money, bool)>[
      if (figures != null) ...<(String, Money, bool)>[
        (l.contractorValue, figures.contractValue, false),
        (l.contractorPaid, figures.paid, false),
        if (figures.advance != null && !figures.advance!.isZero) (l.contractorAdvance, figures.advance!, false),
        if (figures.balanceMoney != null) (l.contractorBalance, figures.balanceMoney!, true),
        if (figures.balanceWorkValue != null) (l.contractorWorkLeftValue, figures.balanceWorkValue!, false),
        if (figures.shortBy != null && !figures.shortBy!.isZero) (l.contractorShortBy, figures.shortBy!, false),
      ],
    ];
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (title != null) ...<Widget>[
            Text(title, style: N360Type.fit(N360Type.heading, title)),
            const SizedBox(height: N360Space.sm),
          ],
          Row(
            children: <Widget>[
              Expanded(child: Text(l.contractorWorkDone, style: N360Type.fit(N360Type.label, l.contractorWorkDone))),
              Text(percentText(contract.workPct), style: N360Type.figureSmall),
            ],
          ),
          const SizedBox(height: N360Space.xxs),
          HatchedProgressBar(
            fraction: percentFraction(contract.workPct),
            height: 6,
            semanticLabel: '${l.contractorWorkDone}, ${percentText(contract.workPct)}',
          ),
          if (contract.balanceWorkPct != null)
            BookRow(label: l.contractorWorkLeft, value: percentText(contract.balanceWorkPct), ruled: false),
          if (figures != null) ...<Widget>[
            if (figures.label != null) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Text(figures.label!, style: N360Type.fit(N360Type.body, figures.label!)),
            ],
            if (pill != null) ...<Widget>[
              const SizedBox(height: N360Space.xxs),
              Align(alignment: Alignment.centerLeft, child: pill),
            ],
            const SizedBox(height: N360Space.xs),
            for (final (int index, (String label, Money value, bool total)) in rows.indexed)
              BookRow(label: label, value: AppFormat.rupees(value), total: total, ruled: index < rows.length - 1),
          ],
        ],
      ),
    );
  }
}

class _PartTile extends StatelessWidget {
  const _PartTile({required this.part});

  final ContractPart part;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Money? amount = part.amount;
    final String? state = part.state;
    final String? doneOn = part.doneOn;
    final String subtitle = <String>[
      if (amount != null) AppFormat.rupees(amount),
      if (state != null) l.partStateWord(state),
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
