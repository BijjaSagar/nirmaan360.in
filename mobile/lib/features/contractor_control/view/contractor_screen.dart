import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../../site_progress/view/widgets/progress_widgets.dart' show ProgressBarTile;
import '../../sync_center/data/sync_repository.dart' show OutboxItem;
import '../../sync_center/view/sync_labels.dart';
import '../bloc/contractor_cubit.dart';
import '../data/contractor_repository.dart';
import 'widgets/contractor_kit.dart';

/// Each contract's work against what has been paid, and the payment requests
/// raised on it. Without `money.view` the server sends work figures only, and
/// that is all this shows.
class ContractorScreen extends StatelessWidget {
  const ContractorScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ContractorCubit>(
      create: (context) => ContractorCubit(context.read<AppServices>()),
      child: const _ContractorView(),
    );
  }
}

class _ContractorView extends StatelessWidget {
  const _ContractorView();

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
              final bool offerRequest =
                  canRequest &&
                  overview != null &&
                  !overview.refused &&
                  overview.contracts.any((WorkContract row) => !row.isClosed);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.contractorTitle, subtitle: state.project?.code),
                  const Expanded(child: _ContractorScroll()),
                  if (offerRequest)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('contractor-request'),
                        label: l.contractorRequest,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => context.push(Routes.contractorRequest),
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
}

class _ContractorScroll extends StatelessWidget {
  const _ContractorScroll();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<ContractorCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.contractorLoading,
      child: BlocBuilder<ContractorCubit, ContractorState>(
        builder: (context, state) => CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: _slivers(context, l, state),
        ),
      ),
    );
  }

  List<Widget> _slivers(BuildContext context, AppLocalizations l, ContractorState state) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
    Widget skeleton() => SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.contractorLoading),
      ),
    );

    if (state.load == ContractorLoad.loading) return <Widget>[skeleton()];
    if (state.load == ContractorLoad.noProject) {
      return <Widget>[empty(l.progressNoProjectTitle, l.progressNoProjectMessage)];
    }
    final ContractorOverview? overview = state.overview;
    if (overview == null) {
      return <Widget>[if (state.refreshing) skeleton() else empty(l.progressNoDataTitle, l.contractorNoDataMessage)];
    }
    if (overview.refused) return <Widget>[empty(l.contractorRefusedTitle, l.contractorRefusedMessage)];

    return <Widget>[
      SliverToBoxAdapter(child: _Tabs(state: state, overview: overview)),
      ...switch (state.tab) {
        ContractorTab.contracts => <Widget>[
          if (overview.contracts.isEmpty)
            empty(l.contractorEmptyTitle, l.contractorEmptyMessage)
          else
            _List(
              count: overview.contracts.length,
              builder: (context, index) => _ContractTile(row: overview.contracts[index]),
            ),
        ],
        ContractorTab.payments => <Widget>[
          if (overview.payments.isEmpty)
            empty(l.contractorNoPaymentsTitle, l.contractorNoPaymentsMessage)
          else
            _List(
              count: overview.payments.length,
              builder: (context, index) => _PaymentTile(row: overview.payments[index]),
            ),
          if (overview.morePayments)
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
                child: Text(l.contractorLatestOnly, style: N360Type.fit(N360Type.caption, l.contractorLatestOnly)),
              ),
            ),
        ],
      },
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }
}

class _Tabs extends StatelessWidget {
  const _Tabs({required this.state, required this.overview});

  final ContractorState state;
  final ContractorOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ContractorCubit cubit = context.read<ContractorCubit>();
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.sm),
      child: Wrap(
        spacing: N360Space.sm,
        runSpacing: N360Space.sm,
        children: <Widget>[
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('contractor-tab-contracts'),
              label: l.contractorTabContracts,
              selected: state.tab == ContractorTab.contracts,
              onTap: () => cubit.setTab(ContractorTab.contracts),
            ),
          ),
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('contractor-tab-payments'),
              label: l.contractorTabPayments,
              count: overview.unsent > 0 ? '${overview.unsent}' : null,
              selected: state.tab == ContractorTab.payments,
              onTap: () => cubit.setTab(ContractorTab.payments),
            ),
          ),
        ],
      ),
    );
  }
}

class _List extends StatelessWidget {
  const _List({required this.count, required this.builder});

  final int count;
  final IndexedWidgetBuilder builder;

  @override
  Widget build(BuildContext context) {
    return FieldGroupSliver(
      padding: EdgeInsets.zero,
      sliver: SliverList.builder(
        itemCount: count,
        itemBuilder: (context, index) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (index > 0) const RuledDivider(indent: N360Space.card),
            builder(context, index),
          ],
        ),
      ),
    );
  }
}

class _ContractTile extends StatelessWidget {
  const _ContractTile({required this.row});

  final WorkContract row;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ContractFigures? figures = row.figures;
    final String reference = <String>[row.number, ?row.title, if (row.isClosed) l.contractorClosed].join(' · ');
    final String? label = figures?.label;
    final String? warning = switch (figures) {
      ContractFigures(overLimit: true) => l.contractorOverLimit,
      ContractFigures(paidAhead: true) => l.contractorPaidAhead,
      _ => null,
    };
    return ProgressBarTile(
      key: ValueKey<String>('contract-${row.workOrderId}'),
      title: row.displayName,
      percent: row.workPct,
      caption: <String>[reference, if (warning == null && label != null) label].join('\n'),
      alert: warning == null ? null : <String>[?label, warning].join(' · '),
      onTap: () => context.push(Routes.contractorDetail(row.workOrderId)),
    );
  }
}

class _PaymentTile extends StatelessWidget {
  const _PaymentTile({required this.row});

  final ContractorPayment row;

  /// The server's own words when it refused the request.
  String? _refusal(AppLocalizations l) {
    final OutboxRow? outbox = row.unsent;
    if (outbox == null || !row.needsAttention) return null;
    if (outbox.attentionCode == AttentionCode.validation && (outbox.attentionReason?.isNotEmpty ?? false)) {
      return outbox.attentionReason;
    }
    return l.syncReasonFor(OutboxItem(row: outbox, isBlocked: false));
  }

  void _open(BuildContext context) {
    if (row.needsAttention) {
      context.go(Routes.outbox);
    } else if (!row.notSent) {
      showLedgerSheet<void>(context, isScrollControlled: true, builder: (_) => _PaymentSheet(row: row, gateContext: context));
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String title = row.contractor ?? row.workOrderNumber ?? row.number ?? l.contractorRequest;
    final String? date = row.paidOn;
    final String detail = <String>[
      ?row.number,
      ?row.workOrderNumber,
      if (date != null) dayLabel(context, date, context.read<AppServices>().context.today),
    ].join(' · ');
    final Widget status = row.notSent
        ? StatusPill(
            kind: row.needsAttention ? StampKind.returned : StampKind.queued,
            label: row.needsAttention ? l.shellPillAttention : l.progressNotSent,
          )
        : StatusPill(kind: paymentStampKind(row.status), label: l.paymentStatusWord(row.status));
    final String? refusal = _refusal(l);
    final PaymentFigures? figures = row.figures;
    final bool tappable = row.needsAttention || !row.notSent;

    final Widget body = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: Text(title, style: N360Type.fit(N360Type.bodyStrong, title))),
              if (figures != null) ...<Widget>[
                const SizedBox(width: N360Space.sm),
                Text(AppFormat.rupees(figures.amount), style: N360Type.figureSmall),
              ],
              if (tappable) ...<Widget>[
                const SizedBox(width: N360Space.xs),
                const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink2),
              ],
            ],
          ),
          if (detail.isNotEmpty) Text(detail, style: N360Type.fit(N360Type.caption, detail)),
          const SizedBox(height: N360Space.xs),
          Align(alignment: Alignment.centerLeft, child: status),
          if (refusal != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(refusal, style: N360Type.fit(N360Type.body, refusal).copyWith(color: N360Colors.laterite)),
          ],
        ],
      ),
    );
    if (!tappable) {
      return ConstrainedBox(
        key: ValueKey<String>('payment-${row.clientUuid ?? row.id}'),
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: body,
      );
    }
    return PressTarget(
      key: ValueKey<String>('payment-${row.clientUuid ?? row.id}'),
      onTap: () => _open(context),
      haptic: N360Haptics.select,
      semanticsLabel: <String>[title, ?refusal].join('. '),
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: ConstrainedBox(constraints: const BoxConstraints(minHeight: N360Size.tap), child: body),
    );
  }
}

/// One payment in full, and for someone deciding it, what approving would do.
class _PaymentSheet extends StatefulWidget {
  const _PaymentSheet({required this.row, required this.gateContext});

  final ContractorPayment row;
  final BuildContext gateContext;

  @override
  State<_PaymentSheet> createState() => _PaymentSheetState();
}

class _PaymentSheetState extends State<_PaymentSheet> {
  late final Future<PaymentImpact?> _impact = _readImpact();

  Future<PaymentImpact?> _readImpact() {
    final int? id = widget.row.id;
    if (id == null || widget.row.figures == null) return Future<PaymentImpact?>.value();
    return widget.gateContext.read<AppServices>().contractors.readImpact(id);
  }

  void _viewBill() {
    final int? id = widget.row.id;
    if (id == null) return;
    final AppServices services = widget.gateContext.read<AppServices>();
    showLedgerSheet<void>(
      context,
      isScrollControlled: true,
      builder: (_) => _BillSheet(load: services.contractors.billBytes(id)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ContractorPayment row = widget.row;
    final PaymentFigures? figures = row.figures;
    final String today = widget.gateContext.read<AppServices>().context.today;
    final String heading = row.number ?? l.contractorRequest;
    final String? date = row.paidOn;
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(heading, style: N360Type.fit(N360Type.heading, heading)),
            const SizedBox(height: N360Space.xs),
            Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(kind: paymentStampKind(row.status), label: l.paymentStatusWord(row.status)),
            ),
            const SizedBox(height: N360Space.sm),
            if (row.contractor != null) BookRow.words(label: l.contractorTitle, value: row.contractor!),
            if (row.workOrderNumber != null) BookRow.words(label: l.contractorContract, value: row.workOrderNumber!),
            if (date != null) BookRow.words(label: l.contractorPaidOn, value: dayLabel(context, date, today)),
            if (row.enteredBy != null) BookRow.words(label: l.contractorEnteredBy, value: row.enteredBy!),
            if (figures != null) ...<Widget>[
              BookRow(label: l.contractorAmountLabel, total: true, value: AppFormat.rupees(figures.amount)),
              if (figures.mode != null) BookRow.words(label: l.contractorMode, value: l.paymentModeWord(figures.mode!)),
              if (figures.paidTo != null) BookRow.words(label: l.contractorPaidTo, value: figures.paidTo!),
              if (figures.reference != null) BookRow.words(label: l.contractorReference, value: figures.reference!),
              if (figures.isAdvance) BookRow.words(label: l.contractorKind, value: l.contractorKindAdvance),
              if (figures.isReversal) BookRow.words(label: l.contractorKind, value: l.contractorReversal),
              if (figures.notes != null) BookRow.words(label: l.contractorNotes, value: figures.notes!),
              FutureBuilder<PaymentImpact?>(
                future: _impact,
                builder: (context, snapshot) {
                  final PaymentImpact? impact = snapshot.data;
                  if (impact == null) return const SizedBox.shrink();
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (impact.now != null) BookRow.words(label: l.contractorImpactNow, value: impact.now!),
                      if (impact.after != null) BookRow.words(label: l.contractorImpactAfter, value: impact.after!),
                      if (impact.item != null) BookRow.words(label: l.contractorImpactLine, value: impact.item!),
                    ],
                  );
                },
              ),
              if (figures.hasBill && row.id != null) ...<Widget>[
                const SizedBox(height: N360Space.md),
                GloveButton(
                  key: const ValueKey<String>('payment-view-bill'),
                  label: l.contractorViewBill,
                  icon: Icons.receipt_long_outlined,
                  kind: GloveButtonKind.secondary,
                  fullWidth: true,
                  onPressed: _viewBill,
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

/// A picture of the bill. A PDF cannot be drawn here, so it says where to find it.
class _BillSheet extends StatelessWidget {
  const _BillSheet({required this.load});

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
            child: Image.memory(bytes, fit: BoxFit.contain, errorBuilder: (_, _, _) => message(l.contractorBillNotPicture)),
          );
        },
      ),
    );
  }
}
