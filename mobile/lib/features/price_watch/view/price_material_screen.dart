import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/price_watch_cubit.dart';
import '../data/price_watch_models.dart';
import 'widgets/price_kit.dart';

/// One watched material: its latest recorded price, every price recorded for
/// it with the day and where it came from, and for an owner or manager what
/// the latest one does to this project's budget.
class PriceMaterialScreen extends StatelessWidget {
  const PriceMaterialScreen({super.key, required this.materialId});

  final int materialId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PriceWatchCubit>(
      create: (context) => PriceWatchCubit(context.read<AppServices>()),
      child: BlocBuilder<PriceWatchCubit, PriceWatchState>(
        buildWhen: (a, b) => a.board != b.board,
        builder: (context, state) {
          final AppLocalizations l = AppLocalizations.of(context);
          final WatchedMaterial? material = state.board?.material(materialId);
          return PriceFrame(
            title: material?.name ?? l.priceWatchTitle,
            subtitle: material == null || material.unit.isEmpty ? null : l.priceWatchPerUnit(material.unit),
            slivers: (context, state) => _slivers(context, state, materialId),
          );
        },
      ),
    );
  }

  static List<Widget> _slivers(BuildContext context, PriceWatchState state, int materialId) {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<Widget>? gate = priceGate(context, state);
    if (gate != null) return gate;
    final PriceBoardCopy board = state.board!;
    final WatchedMaterial? material = board.material(materialId);
    if (material == null) {
      return <Widget>[SliverToBoxAdapter(child: EmptyLedger(title: l.priceWatchMaterialMissing))];
    }
    final bool seesBudget = context.select((AppGate g) => g.state.can('money.overview'));
    final MaterialImpact? impact = seesBudget ? state.impact?.forMaterial(materialId) : null;
    final RecordedPrice? price = material.current;
    final List<RecordedPrice> prices = material.prices ?? const <RecordedPrice>[];
    return <Widget>[
      SliverToBoxAdapter(child: RecordedNote(fetchedAt: board.fetchedAt)),
      SliverPadding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
        sliver: SliverList.list(
          children: <Widget>[
            _Header(material: material, staleDays: board.staleAfterDays),
            if (impact != null) ...<Widget>[
              const SizedBox(height: N360Space.cardGap),
              _Budget(impact: impact, staleDays: state.impact!.staleAfterDays),
            ],
            const SizedBox(height: N360Space.cardGap),
            if (price == null)
              EmptyLedger(title: l.priceWatchNone)
            else
              LedgerList(
                header: Text(l.priceWatchHistory, style: N360Type.fit(N360Type.label, l.priceWatchHistory)),
                children: material.prices == null
                    ? <Widget>[
                        // The whole list has not been read yet: the few dated prices on the board row.
                        for (final PricePoint point in material.trend.reversed)
                          LedgerListTile(
                            title: AppFormat.rupees(point.rate),
                            subtitle: l.priceWatchRecordedOn(priceDay(context, point.pricedOn)),
                          ),
                      ]
                    : <Widget>[
                        for (final (int index, RecordedPrice row) in prices.indexed)
                          _PriceRow(price: row, unit: material.unit, latest: index == 0),
                      ],
              ),
            const SizedBox(height: N360Space.lg),
          ],
        ),
      ),
    ];
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.material, required this.staleDays});

  final WatchedMaterial material;
  final int staleDays;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final RecordedPrice? price = material.current;
    final StatusPill? stale = stalePill(l, price, today, staleDays);
    return LedgerCard(
      key: const ValueKey<String>('price-header'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.priceWatchRecordedRate, style: N360Type.fit(N360Type.label, l.priceWatchRecordedRate)),
          const SizedBox(height: N360Space.xxs),
          if (price == null)
            Text(l.priceWatchNone, style: N360Type.fit(N360Type.body, l.priceWatchNone))
          else ...<Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: <Widget>[
                Flexible(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(AppFormat.rupees(price.rate), style: N360Type.figureLarge),
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Flexible(
                  child: Text(
                    l.priceWatchPerUnit(material.unit),
                    style: N360Type.fit(N360Type.caption, l.priceWatchPerUnit(material.unit)),
                  ),
                ),
              ],
            ),
            const SizedBox(height: N360Space.xs),
            Text(recordedWords(context, price), style: N360Type.fit(N360Type.body, recordedWords(context, price))),
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xxs),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Icon(changeIcon(material.changePct), size: N360IconSize.sm, color: N360Colors.ink2),
                  const SizedBox(width: N360Space.xs),
                  Expanded(
                    child: Text(changeWords(context, material), style: N360Type.fit(N360Type.caption, changeWords(context, material))),
                  ),
                ],
              ),
            ),
            if (stale != null) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Align(alignment: Alignment.centerLeft, child: stale),
            ],
          ],
        ],
      ),
    );
  }
}

class _Budget extends StatelessWidget {
  const _Budget({required this.impact, required this.staleDays});

  final MaterialImpact impact;
  final int staleDays;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Money? effect = impact.impact;
    return LedgerCard(
      key: const ValueKey<String>('price-budget'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.priceWatchBudgetTitle, style: N360Type.fit(N360Type.label, l.priceWatchBudgetTitle)),
          if (effect != null) ...<Widget>[
            const SizedBox(height: N360Space.xxs),
            Text(
              effectWords(l, effect),
              style: N360Type.fit(N360Type.title, effectWords(l, effect)).copyWith(color: effectColor(effect)),
            ),
          ],
          const SizedBox(height: N360Space.xs),
          ImpactLines(impact: impact),
        ],
      ),
    );
  }
}

class _PriceRow extends StatelessWidget {
  const _PriceRow({required this.price, required this.unit, required this.latest});

  final RecordedPrice price;
  final String unit;
  final bool latest;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? by = price.notedBy == null ? null : l.priceWatchNotedBy(price.notedBy!);
    final String subtitle = <String>[recordedWords(context, price), ?by].join('\n');
    return LedgerListTile(
      key: ValueKey<String>('price-row-${price.id}'),
      title: '${AppFormat.rupees(price.rate)} ${l.priceWatchPerUnit(unit)}'.trim(),
      subtitle: subtitle,
      status: latest ? StatusPill(kind: StampKind.approved, label: l.priceWatchLatest) : null,
    );
  }
}
