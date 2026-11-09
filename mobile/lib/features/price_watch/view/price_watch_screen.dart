import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/price_watch_cubit.dart';
import '../data/price_watch_models.dart';
import 'widgets/price_kit.dart';

/// The prices the company has recorded for the materials it watches, each with
/// its date, and for an owner or manager what they do to this project's budget.
/// Read only. Nothing here is a price the app looked up; every figure was
/// written down by a person, and says when.
class PriceWatchScreen extends StatelessWidget {
  const PriceWatchScreen({super.key, this.tab = PriceTab.prices});

  final PriceTab tab;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PriceWatchCubit>(
      create: (context) => PriceWatchCubit(context.read<AppServices>(), tab: tab),
      child: BlocBuilder<PriceWatchCubit, PriceWatchState>(
        buildWhen: (a, b) => a.tab != b.tab || a.project?.id != b.project?.id,
        builder: (context, state) {
          final AppLocalizations l = AppLocalizations.of(context);
          final bool budget = state.tab == PriceTab.budget;
          return PriceFrame(
            title: l.priceWatchTitle,
            subtitle: budget ? state.project?.code : null,
            slivers: _slivers,
          );
        },
      ),
    );
  }

  static List<Widget> _slivers(BuildContext context, PriceWatchState state) {
    final List<Widget>? gate = priceGate(context, state);
    if (gate != null) return gate;
    final PriceBoardCopy board = state.board!;
    final bool seesBudget = context.select((AppGate g) => g.state.can('money.overview'));
    final bool hasBudgetTab = seesBudget && state.project != null;
    final PriceTab tab = hasBudgetTab ? state.tab : PriceTab.prices;
    return <Widget>[
      SliverToBoxAdapter(child: RecordedNote(fetchedAt: board.fetchedAt)),
      if (hasBudgetTab) const SliverToBoxAdapter(child: _Tabs()),
      ...switch (tab) {
        PriceTab.prices => _prices(context, board),
        PriceTab.budget => _budget(context, state, board),
      },
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }

  static List<Widget> _prices(BuildContext context, PriceBoardCopy board) {
    final AppLocalizations l = AppLocalizations.of(context);
    if (board.rows.isEmpty) {
      return <Widget>[SliverToBoxAdapter(child: EmptyLedger(title: l.priceWatchEmptyTitle, message: l.priceWatchEmptyMessage))];
    }
    return <Widget>[
      _List(
        count: board.rows.length,
        builder: (context, index) {
          final WatchedMaterial material = board.rows[index];
          return PriceTile(
            key: ValueKey<String>('price-${material.id}'),
            material: material,
            staleDays: board.staleAfterDays,
            onTap: () => context.push(Routes.priceMaterial(material.id)),
          );
        },
      ),
    ];
  }

  static List<Widget> _budget(BuildContext context, PriceWatchState state, PriceBoardCopy board) {
    final AppLocalizations l = AppLocalizations.of(context);
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
    final ProjectImpactCopy? impact = state.impact;
    if (impact == null) {
      return <Widget>[
        if (state.refreshing)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(lines: 3, semanticsLabel: l.priceWatchLoading),
            ),
          )
        else
          empty(l.progressNoDataTitle, l.priceWatchBudgetNoData),
      ];
    }
    if (impact.refused) return <Widget>[empty(l.priceWatchRefusedTitle, l.priceWatchBudgetRefused)];
    if (impact.rows.isEmpty) return <Widget>[empty(l.priceWatchEmptyTitle, l.priceWatchEmptyMessage)];
    return <Widget>[
      SliverPadding(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.cardGap),
        sliver: SliverToBoxAdapter(child: ImpactSummaryCard(summary: impact.summary)),
      ),
      _List(
        count: impact.rows.length,
        builder: (context, index) {
          final MaterialImpact row = impact.rows[index];
          return ImpactTile(
            key: ValueKey<String>('impact-${row.material.id}'),
            impact: row,
            staleDays: impact.staleAfterDays,
            onTap: () => context.push(Routes.priceMaterial(row.material.id)),
          );
        },
      ),
    ];
  }
}

class _Tabs extends StatelessWidget {
  const _Tabs();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final PriceWatchCubit cubit = context.read<PriceWatchCubit>();
    final PriceTab tab = context.select((PriceWatchCubit c) => c.state.tab);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.sm),
      child: Wrap(
        spacing: N360Space.sm,
        runSpacing: N360Space.sm,
        children: <Widget>[
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('price-tab-prices'),
              label: l.priceWatchTabPrices,
              selected: tab == PriceTab.prices,
              onTap: () => cubit.setTab(PriceTab.prices),
            ),
          ),
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('price-tab-budget'),
              label: l.priceWatchTabBudget,
              selected: tab == PriceTab.budget,
              onTap: () => cubit.setTab(PriceTab.budget),
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
