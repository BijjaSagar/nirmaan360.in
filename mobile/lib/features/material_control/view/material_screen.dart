import 'package:flutter/material.dart' hide MaterialState;
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/bloc/measurement_book.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../bloc/material_cubit.dart';
import '../data/material_repository.dart';
import 'material_loop_screens.dart';

/// What came to site, what was used, and whether the two add up.
class MaterialScreen extends StatelessWidget {
  const MaterialScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MaterialCubit>(
      create: (context) => MaterialCubit(context.read<AppServices>()),
      child: const _MaterialView(),
    );
  }
}

class _Action {
  const _Action(this.label, this.icon, this.route);

  final String label;
  final IconData icon;
  final String route;
}

class _MaterialView extends StatelessWidget {
  const _MaterialView();

  List<_Action> _actions(AppLocalizations l, GateState gate) => <_Action>[
    if (gate.can('material_control.challan')) _Action(l.materialReceived, Icons.local_shipping_outlined, Routes.materialReceive),
    if (gate.can('material_control.consume')) _Action(l.materialUsed, Icons.construction, Routes.materialUse),
    if (gate.can('material_control.count')) _Action(l.materialCount, Icons.fact_check_outlined, Routes.materialCount),
    // Against an issued purchase order, and out of a store. Both need `procurement.stock`.
    if (gate.canStockProcure) ...<_Action>[
      _Action(l.procurementReceiveOrder, Icons.assignment_turned_in_outlined, Routes.materialReceiveOrder),
      _Action(l.procurementIssueStock, Icons.outbox_outlined, Routes.materialIssue),
    ],
  ];

  Future<void> _add(BuildContext context, List<_Action> actions) async {
    final String? route = actions.length == 1
        ? actions.single.route
        : await showLedgerSheet<String>(
            context,
            builder: (context) => SafeArea(
              top: false,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  for (final _Action action in actions)
                    LedgerListTile(
                      key: ValueKey<String>('material-action-${action.route}'),
                      leading: Icon(action.icon, size: N360IconSize.lg, color: N360Colors.ink2),
                      title: action.label,
                      onTap: () => Navigator.of(context).pop(action.route),
                    ),
                  const SizedBox(height: N360Space.md),
                ],
              ),
            ),
          );
    if (route != null && context.mounted) context.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final GateState gate = context.select((AppGate gate) => gate.state);
    final List<_Action> actions = _actions(l, gate);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              BlocBuilder<MaterialCubit, MaterialState>(
                buildWhen: (a, b) => a.project?.id != b.project?.id,
                builder: (context, state) => FieldTopBar(
                  title: l.materialTitle,
                  subtitle: state.project?.code,
                  // Recorded prices are for money.view on a plan that has them. Nobody else sees a way in.
                  trailing: gate.canSeePrices
                      ? N360TopTile(
                          key: const ValueKey<String>('material-prices'),
                          icon: Icons.sell_outlined,
                          label: l.priceWatchTile,
                          onTap: () => context.push(Routes.prices),
                        )
                      : null,
                ),
              ),
              const Expanded(child: _MaterialScroll()),
              if (actions.isNotEmpty)
                FieldActionBar(
                  child: GloveButton(
                    key: const ValueKey<String>('material-add'),
                    label: actions.length == 1 ? actions.single.label : l.materialAdd,
                    icon: Icons.add,
                    large: true,
                    fullWidth: true,
                    onPressed: () => _add(context, actions),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MaterialScroll extends StatelessWidget {
  const _MaterialScroll();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<MaterialCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.materialLoading,
      child: BlocBuilder<MaterialCubit, MaterialState>(
        builder: (context, state) => CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: _slivers(context, l, state),
        ),
      ),
    );
  }

  List<Widget> _slivers(BuildContext context, AppLocalizations l, MaterialState state) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
    Widget skeleton() => SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.materialLoading),
      ),
    );

    if (state.load == MaterialLoad.loading) return <Widget>[skeleton()];
    if (state.load == MaterialLoad.noProject) {
      return <Widget>[empty(l.progressNoProjectTitle, l.progressNoProjectMessage)];
    }
    final MaterialOverview? overview = state.overview;
    if (overview == null) {
      return <Widget>[if (state.refreshing) skeleton() else empty(l.progressNoDataTitle, l.materialNoDataMessage)];
    }

    return <Widget>[
      SliverToBoxAdapter(child: _Summary(overview: overview)),
      const SliverToBoxAdapter(child: _Links()),
      SliverToBoxAdapter(child: _Tabs(state: state)),
      switch (state.tab) {
        MaterialTab.stock =>
          overview.rows.isEmpty
              ? empty(l.materialEmptyTitle, l.materialEmptyMessage)
              : _List(
                  count: overview.rows.length,
                  builder: (context, index) => _StockTile(row: overview.rows[index], overview: overview),
                ),
        MaterialTab.deliveries =>
          overview.deliveries.isEmpty
              ? empty(l.materialNoDeliveriesTitle, l.materialEmptyMessage)
              : _List(
                  count: overview.deliveries.length,
                  builder: (context, index) => _DeliveryTile(row: overview.deliveries[index], overview: overview),
                ),
        MaterialTab.used =>
          overview.uses.isEmpty
              ? empty(l.materialNoUseTitle, l.materialEmptyMessage)
              : _List(
                  count: overview.uses.length,
                  builder: (context, index) => _UseTile(row: overview.uses[index], overview: overview),
                ),
        MaterialTab.counts =>
          overview.counts.isEmpty
              ? empty(l.materialNoCountsTitle, l.materialEmptyMessage)
              : _List(
                  count: overview.counts.length,
                  builder: (context, index) => _CountTile(row: overview.counts[index], overview: overview),
                ),
        MaterialTab.receipts => _ReceiptsSliver(overview: overview),
      },
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.overview});

  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final int open = overview.openFlags;
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.sm),
      child: LedgerCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(l.materialFlagsTitle, style: N360Type.fit(N360Type.label, l.materialFlagsTitle)),
            Text('$open', style: N360Type.figureLarge.copyWith(color: open > 0 ? N360Colors.laterite : N360Colors.ink)),
            if (overview.unsent > 0)
              Padding(
                padding: const EdgeInsets.only(top: N360Space.xs),
                child: Row(
                  children: <Widget>[
                    const Icon(Icons.cloud_upload_outlined, size: N360IconSize.md, color: N360Colors.ink2),
                    const SizedBox(width: N360Space.xs),
                    Expanded(
                      child: Text(
                        l.syncPendingCount(overview.unsent),
                        style: N360Type.fit(N360Type.caption, l.materialTitle),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Ways on to the online pages: bills for whoever chases them, and wastage
/// against the BOQ norms. Each shows only to someone the server would answer.
class _Links extends StatelessWidget {
  const _Links();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final GateState gate = context.select((AppGate gate) => gate.state);
    final bool bills = gate.can('material_control.bills');
    final bool wastage = gate.canSeeWastage;
    if (!bills && !wastage) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: LedgerCard(
        padding: EdgeInsets.zero,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (wastage)
              LedgerListTile(
                key: const ValueKey<String>('material-open-wastage'),
                leading: const Icon(Icons.balance_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
                title: l.materialWastageTile,
                subtitle: l.materialWastageTileHint,
                onTap: () => context.push(Routes.materialWastage),
              ),
            if (bills && wastage) const RuledDivider(indent: N360Space.card),
            if (bills)
              LedgerListTile(
                key: const ValueKey<String>('material-open-bills'),
                leading: const Icon(Icons.receipt_long_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
                title: l.materialBillsTitle,
                onTap: () => context.push(Routes.materialBills),
              ),
          ],
        ),
      ),
    );
  }
}

class _Tabs extends StatelessWidget {
  const _Tabs({required this.state});

  final MaterialState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MaterialCubit cubit = context.read<MaterialCubit>();
    final List<(MaterialTab, String)> tabs = <(MaterialTab, String)>[
      (MaterialTab.stock, l.materialTabStock),
      (MaterialTab.deliveries, l.materialTabDeliveries),
      (MaterialTab.used, l.materialTabUsed),
      (MaterialTab.counts, l.materialTabCounts),
      if (context.select((AppGate gate) => gate.state.canStockProcure)) (MaterialTab.receipts, l.procurementTabReceipts),
    ];
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: Row(
        children: <Widget>[
          for (final (index, (tab, label)) in tabs.indexed) ...<Widget>[
            if (index > 0) const SizedBox(width: N360Space.sm),
            N360Chip(label: label, selected: state.tab == tab, onTap: () => cubit.setTab(tab)),
          ],
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

Widget _figure(String text) => Text(text, style: N360Type.figureSmall);

String? _day(BuildContext context, String? date) =>
    date == null ? null : dayLabel(context, date, context.read<AppServices>().context.today);

Widget? _notSent(AppLocalizations l, bool notSent) =>
    notSent ? StatusPill(kind: StampKind.queued, label: l.progressNotSent) : null;

class _StockTile extends StatelessWidget {
  const _StockTile({required this.row, required this.overview});

  final MaterialRow row;
  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerListTile(
      key: ValueKey<String>('material-row-${row.materialId}'),
      title: row.name,
      subtitle: '${l.materialInStock} ${formatQty(row.stock)} ${row.unit}'.trim(),
      status: row.openFlags > 0 ? StatusPill(kind: StampKind.returned, label: l.materialToAnswer(row.openFlags)) : null,
      onTap: () => showLedgerSheet<void>(
        context,
        isScrollControlled: true,
        builder: (sheet) => _RowSheet(
          row: row,
          gateContext: context,
          projectId: context.read<MaterialCubit>().state.project?.id,
        ),
      ),
    );
  }
}

/// The full picture of one material, and the questions it raises.
class _RowSheet extends StatelessWidget {
  const _RowSheet({required this.row, required this.gateContext, required this.projectId});

  final MaterialRow row;
  final BuildContext gateContext;
  final int? projectId;

  /// The sheet closes and the reason box opens over the page, so the page is
  /// what shows, already read again, once the reason is stored.
  void _answer(BuildContext context, MaterialFlag flag) {
    final int? project = projectId;
    if (project == null) return;
    Navigator.of(context).pop();
    showFlagReasonSheet(gateContext, projectId: project, materialId: row.materialId, materialName: row.name, flag: flag);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final GateState gate = gateContext.read<AppGate>().state;
    final bool canCount = gate.can('material_control.count');
    final bool canReason = gate.can('material_control.reason') && projectId != null;
    final List<MaterialFlag> open = row.flags.where((flag) => flag.open).toList();
    final List<MaterialFlag> answered = row.flags.where((flag) => !flag.open && flag.reason != null).toList();
    String figure(Qty value) => '${formatQty(value)} ${row.unit}'.trim();
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(row.name, style: N360Type.fit(N360Type.heading, row.name)),
            const SizedBox(height: N360Space.sm),
            BookRow(label: l.materialBoqForWork, value: figure(row.estimate)),
            BookRow(label: l.materialReceivedShort, value: figure(row.received)),
            BookRow(label: l.materialUsedShort, value: figure(row.used)),
            BookRow(label: l.materialShouldBe, value: figure(row.shouldBeInStock)),
            BookRow(label: l.materialOnSite, total: true, value: figure(row.stock)),
            if (!row.unaccounted.isZero) BookRow(label: l.materialUnaccounted, value: figure(row.unaccounted)),
            if (!row.shortDelivered.isZero) BookRow(label: l.materialShort, value: figure(row.shortDelivered)),
            if (row.lastCounted != null)
              BookRow.words(label: l.materialLastCounted, value: _day(gateContext, row.lastCounted)!, ruled: false),
            for (final MaterialFlag flag in open) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Text(flag.message, style: N360Type.fit(N360Type.body, flag.message).copyWith(color: N360Colors.laterite)),
              if (canReason) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                GloveButton(
                  key: ValueKey<String>('material-flag-${flag.type}-reason'),
                  label: l.materialReasonWrite,
                  icon: Icons.edit_note,
                  kind: GloveButtonKind.secondary,
                  fullWidth: true,
                  onPressed: () => _answer(context, flag),
                ),
              ],
            ],
            if (open.isNotEmpty && !canReason) ...<Widget>[
              const SizedBox(height: N360Space.xs),
              Text(l.materialReasonNotYours, style: N360Type.fit(N360Type.caption, l.materialReasonNotYours)),
            ],
            for (final MaterialFlag flag in answered) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Text(flag.message, style: N360Type.fit(N360Type.body, flag.message).copyWith(color: N360Colors.ink2)),
              const SizedBox(height: N360Space.xxs),
              Text(
                <String>[
                  l.materialReasonAnswered,
                  if (flag.reasonBy != null) l.materialReasonFrom(flag.reasonBy!),
                ].join(' · '),
                style: N360Type.fit(N360Type.caption, l.materialReasonAnswered),
              ),
              Text(flag.reason!, style: N360Type.fit(N360Type.body, flag.reason!)),
            ],
            if (canCount) ...<Widget>[
              const SizedBox(height: N360Space.md),
              GloveButton(
                label: l.materialCount,
                icon: Icons.fact_check_outlined,
                kind: GloveButtonKind.secondary,
                fullWidth: true,
                onPressed: () {
                  Navigator.of(context).pop();
                  gateContext.push('${Routes.materialCount}?material=${row.materialId}');
                },
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _DeliveryTile extends StatelessWidget {
  const _DeliveryTile({required this.row, required this.overview});

  final MaterialDelivery row;
  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String subtitle = <String>[?row.supplier, ?row.challanNo, ?_day(context, row.date)].join(' · ');
    final int? id = row.id;
    // The bill is chased by whoever holds material_control.bills; nobody else is nagged about it.
    final bool chasesBills = context.select((AppGate gate) => gate.state.can('material_control.bills'));
    return LedgerListTile(
      key: id == null ? null : ValueKey<String>('material-challan-$id'),
      title: overview.materialName(row.materialId),
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: row.notSent
          ? _notSent(l, true)
          : row.reversed
          ? StatusPill(kind: StampKind.rejected, label: l.procurementReversed)
          : row.billPending && chasesBills
          ? StatusPill(kind: StampKind.inReview, label: l.materialBillPending)
          : null,
      trailing: _figure('${formatQty(row.qty)} ${overview.unitLabel(row.materialId, row.unitId)}'.trim()),
      // A challan the server holds opens in full: photo, bill, shortages.
      onTap: id == null ? null : () => context.push(Routes.materialChallanAt(id)),
    );
  }
}

class _UseTile extends StatelessWidget {
  const _UseTile({required this.row, required this.overview});

  final MaterialUse row;
  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String subtitle = <String>[?row.activity, ?row.issuedTo, ?_day(context, row.date)].join(' · ');
    return LedgerListTile(
      title: overview.materialName(row.materialId),
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: row.reversed ? StatusPill(kind: StampKind.rejected, label: l.procurementReversed) : _notSent(l, row.notSent),
      trailing: _figure('${formatQty(row.qty)} ${overview.unitLabel(row.materialId, row.unitId)}'.trim()),
    );
  }
}

class _CountTile extends StatelessWidget {
  const _CountTile({required this.row, required this.overview});

  final StockCount row;
  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = overview.unitLabel(row.materialId, row.unitId);
    final bool differs = row.variance != null && !row.variance!.isZero;
    final String subtitle = <String>[
      ?_day(context, row.date),
      if (differs) l.materialDifference('${formatQty(row.variance!)} ${overview.baseUnit(row.materialId)}'.trim()),
    ].join(' · ');
    return LedgerListTile(
      title: overview.materialName(row.materialId),
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: _notSent(l, row.notSent),
      trailing: _figure('${formatQty(row.counted)} $unit'.trim()),
    );
  }
}

/// Purchase orders waiting for goods, then the receipts made against them. A
/// tap on an order starts a receipt for it. Quantities only.
class _ReceiptsSliver extends StatelessWidget {
  const _ReceiptsSliver({required this.overview});

  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<OpenOrder> open = overview.ordersDue;
    final List<(GoodsReceipt, ReceiptLine)> lines = <(GoodsReceipt, ReceiptLine)>[
      for (final GoodsReceipt receipt in overview.receipts)
        for (final ReceiptLine line in receipt.lines) (receipt, line),
    ];
    return SliverMainAxisGroup(
      slivers: <Widget>[
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.xs),
            child: Text(l.procurementOpenOrders, style: N360Type.fit(N360Type.label, l.procurementOpenOrders)),
          ),
        ),
        if (open.isEmpty)
          SliverToBoxAdapter(child: EmptyLedger(title: l.procurementNoOrdersTitle, message: l.procurementNoOrdersMessage))
        else
          _List(
            count: open.length,
            builder: (context, index) {
              final OpenOrder order = open[index];
              return LedgerListTile(
                key: ValueKey<String>('material-order-${order.id}'),
                title: order.number,
                subtitle: <String>[?order.vendor, l.procurementOrderDue(order.due.length)].join(' · '),
                onTap: () => context.push('${Routes.materialReceiveOrder}?order=${order.id}'),
              );
            },
          ),
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.xs),
            child: Text(l.procurementReceivedHeading, style: N360Type.fit(N360Type.label, l.procurementReceivedHeading)),
          ),
        ),
        if (lines.isEmpty)
          SliverToBoxAdapter(child: EmptyLedger(title: l.procurementNoReceiptsTitle, message: l.materialEmptyMessage))
        else
          _List(
            count: lines.length,
            builder: (context, index) => _ReceiptTile(receipt: lines[index].$1, line: lines[index].$2, overview: overview),
          ),
      ],
    );
  }
}

class _ReceiptTile extends StatelessWidget {
  const _ReceiptTile({required this.receipt, required this.line, required this.overview});

  final GoodsReceipt receipt;
  final ReceiptLine line;
  final MaterialOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = overview.unitLabel(line.materialId, line.unitId);
    final String subtitle = <String>[
      ?(receipt.number ?? receipt.orderNumber),
      ?receipt.challanNo,
      ?_day(context, receipt.date),
      // What was turned away is part of the record, beside what was accepted.
      if (!line.rejected.isZero) l.procurementRejectedFigure('${formatQty(line.rejected)} $unit'.trim()),
    ].join(' · ');
    return LedgerListTile(
      title: overview.materialName(line.materialId),
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: receipt.reversed ? StatusPill(kind: StampKind.rejected, label: l.procurementReversed) : _notSent(l, receipt.notSent),
      trailing: _figure('${formatQty(line.qty)} $unit'.trim()),
    );
  }
}
