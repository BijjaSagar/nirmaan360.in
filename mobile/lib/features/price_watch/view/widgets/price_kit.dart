import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../app/app_format.dart';
import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../attendance/view/widgets/field_kit.dart';
import '../../../measurement/bloc/measurement_book.dart' show formatQty;
import '../../../measurement/view/widgets/book_kit.dart' show BookRow;
import '../../../site_progress/data/progress_models.dart' show percentText;
import '../../bloc/price_watch_cubit.dart';
import '../../data/price_watch_models.dart';

/// A page of recorded prices: the top bar, pull to refresh, and a note that
/// says what these figures are. The page decides what goes under it.
class PriceFrame extends StatelessWidget {
  const PriceFrame({super.key, required this.title, required this.slivers, this.subtitle});

  final String title;
  final String? subtitle;
  final List<Widget> Function(BuildContext context, PriceWatchState state) slivers;

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<PriceWatchCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              FieldTopBar(title: title, subtitle: subtitle),
              Expanded(
                child: LedgerRefresh(
                  onRefresh: () => _refresh(context),
                  semanticsLabel: l.priceWatchLoading,
                  child: BlocBuilder<PriceWatchCubit, PriceWatchState>(
                    builder: (context, state) => CustomScrollView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      slivers: slivers(context, state),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The states every page shares before there is a board to show. Null when there is one.
List<Widget>? priceGate(BuildContext context, PriceWatchState state) {
  final AppLocalizations l = AppLocalizations.of(context);
  SliverToBoxAdapter empty(String title, String message) =>
      SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
  Widget skeleton() => SliverToBoxAdapter(
    child: Padding(
      padding: const EdgeInsets.all(N360Space.gutter),
      child: LedgerSkeletonList(lines: 3, semanticsLabel: l.priceWatchLoading),
    ),
  );
  if (state.load == PriceLoad.loading) return <Widget>[skeleton()];
  final PriceBoardCopy? board = state.board;
  if (board == null) {
    return <Widget>[if (state.refreshing) skeleton() else empty(l.progressNoDataTitle, l.priceWatchNoDataMessage)];
  }
  if (board.refused) return <Widget>[empty(l.priceWatchRefusedTitle, l.priceWatchRefusedMessage)];
  return null;
}

/// What these figures are, and how fresh the copy on the phone is.
class RecordedNote extends StatelessWidget {
  const RecordedNote({super.key, required this.fetchedAt});

  final DateTime? fetchedAt;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final DateTime? at = fetchedAt;
    final String checked = at == null ? '' : l.priceWatchChecked(AppFormat.ago(l, at, context.read<AppServices>().context.clock.now()));
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.priceWatchNote, style: N360Type.fit(N360Type.caption, l.priceWatchNote)),
          if (checked.isNotEmpty) Text(checked, style: N360Type.fit(N360Type.caption, checked)),
        ],
      ),
    );
  }
}

/// The day a price was recorded for, always as a date and never as "Yesterday":
/// a price is read for the day it was written down. The year shows when it is
/// not this year's.
String priceDay(BuildContext context, String date) {
  final DateTime? parsed = DateTime.tryParse(date);
  if (parsed == null) return date;
  final DateTime? today = DateTime.tryParse(context.read<AppServices>().context.today);
  final String locale = Localizations.localeOf(context).languageCode;
  return today != null && today.year != parsed.year
      ? AppFormat.dayMonthYear(date, locale)
      : AppFormat.dayMonth(date, locale);
}

enum PriceDirection { up, down, same, unknown }

PriceDirection directionOf(String? pct) {
  final Decimal? value = pct == null ? null : Decimal.tryParse(pct);
  if (value == null) return PriceDirection.unknown;
  if (value > Decimal.zero) return PriceDirection.up;
  if (value < Decimal.zero) return PriceDirection.down;
  return PriceDirection.same;
}

/// A percent without its sign, since the words say which way it went.
String unsignedPercent(String? pct) => percentText(pct?.replaceFirst('-', ''));

/// "₹36,000 more", "₹14,000 less" or "No effect": a sign never stands alone.
String effectWords(AppLocalizations l, Money amount) {
  if (amount.isZero) return l.priceWatchNoEffect;
  final String shown = AppFormat.rupees(amount.abs());
  return amount.isNegative ? l.priceWatchLess(shown) : l.priceWatchMore(shown);
}

Color effectColor(Money amount) => amount.isZero
    ? N360Colors.ink2
    : amount.isNegative
    ? N360Colors.mossInk
    : N360Colors.laterite;

/// How the latest recorded price compares with the one before it, in words.
String changeWords(BuildContext context, WatchedMaterial material) {
  final AppLocalizations l = AppLocalizations.of(context);
  final RecordedPrice? previous = material.previous;
  if (previous == null) return l.priceWatchOnlyOne;
  final String rate = AppFormat.rupees(previous.rate);
  final String date = priceDay(context, previous.pricedOn);
  final String pct = unsignedPercent(material.changePct);
  return switch (directionOf(material.changePct)) {
    PriceDirection.up => l.priceWatchUp(pct, rate, date),
    PriceDirection.down => l.priceWatchDown(pct, rate, date),
    _ => l.priceWatchSame(rate, date),
  };
}

IconData changeIcon(String? pct) => switch (directionOf(pct)) {
  PriceDirection.up => Icons.trending_up,
  PriceDirection.down => Icons.trending_down,
  _ => Icons.trending_flat,
};

/// "Recorded 8 Nov · Dealer quote".
String recordedWords(BuildContext context, RecordedPrice price) {
  final AppLocalizations l = AppLocalizations.of(context);
  return <String>[l.priceWatchRecordedOn(priceDay(context, price.pricedOn)), ?price.source].join(' · ');
}

StatusPill? stalePill(AppLocalizations l, RecordedPrice? price, String today, int staleDays) {
  if (price == null || !price.isStale(today, staleDays)) return null;
  return StatusPill(kind: StampKind.returned, label: l.priceWatchStale(staleDays));
}

/// The latest recorded rate with the unit it is quoted per.
class RateFigure extends StatelessWidget {
  const RateFigure({super.key, required this.price, required this.unit, this.large = false});

  final RecordedPrice price;
  final String unit;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String per = l.priceWatchPerUnit(unit);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 150),
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerRight,
            child: Text(AppFormat.rupees(price.rate), style: large ? N360Type.figure : N360Type.figureSmall),
          ),
        ),
        if (unit.isNotEmpty) Text(per, style: N360Type.fit(N360Type.caption, per)),
      ],
    );
  }
}

/// One watched material on the board.
class PriceTile extends StatelessWidget {
  const PriceTile({super.key, required this.material, required this.staleDays, required this.onTap});

  final WatchedMaterial material;
  final int staleDays;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final RecordedPrice? price = material.current;
    final StatusPill? stale = stalePill(l, price, today, staleDays);
    final String recorded = price == null ? l.priceWatchNone : recordedWords(context, price);
    final String change = price == null ? '' : changeWords(context, material);
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: <String>[material.name, if (price != null) AppFormat.rupees(price.rate), recorded].join('. '),
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Expanded(child: Text(material.name, style: N360Type.fit(N360Type.bodyStrong, material.name))),
                  if (price != null) ...<Widget>[
                    const SizedBox(width: N360Space.sm),
                    RateFigure(price: price, unit: material.unit),
                  ],
                  const SizedBox(width: N360Space.xs),
                  const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink2),
                ],
              ),
              Text(recorded, style: N360Type.fit(N360Type.caption, recorded)),
              if (change.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: N360Space.xxs),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Icon(changeIcon(material.changePct), size: N360IconSize.sm, color: N360Colors.ink2),
                      const SizedBox(width: N360Space.xs),
                      Expanded(child: Text(change, style: N360Type.fit(N360Type.caption, change))),
                    ],
                  ),
                ),
              if (stale != null) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Align(alignment: Alignment.centerLeft, child: stale),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

String gapWords(AppLocalizations l, ImpactGap gap) => switch (gap) {
  ImpactGap.noPrice => l.priceWatchNone,
  ImpactGap.noBoqRate => l.priceWatchGapNoBoqRate,
  ImpactGap.noQty => l.priceWatchGapNoQty,
  ImpactGap.unitMismatch => l.priceWatchGapUnit,
};

/// The working behind one material's effect on the budget, as lines of words.
class ImpactLines extends StatelessWidget {
  const ImpactLines({super.key, required this.impact});

  final MaterialImpact impact;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final WatchedMaterial material = impact.material;
    final ImpactGap? gap = impact.gap;
    final Money? rate = impact.boqRate;
    final RecordedPrice? price = material.current;
    final Money? difference = impact.difference;
    final Qty? remaining = impact.remaining;
    final String? basis = switch (impact.needBasis) {
      'norms' => l.priceWatchBasisNorms,
      'typed' => l.priceWatchBasisTyped,
      _ => null,
    };
    final List<String> lines = <String>[
      if (rate != null && price != null)
        '${l.priceWatchBoqRate} ${AppFormat.rupees(rate)} · ${l.priceWatchRecordedRate} ${AppFormat.rupees(price.rate)}',
      if (difference != null)
        if (difference.isZero)
          l.priceWatchLevel
        else if (difference.isNegative)
          l.priceWatchBelow(AppFormat.rupees(difference.abs()), unsignedPercent(impact.abovePct))
        else
          l.priceWatchAbove(AppFormat.rupees(difference), unsignedPercent(impact.abovePct)),
      if (remaining != null) <String>[l.priceWatchStillToBuy(formatQty(remaining), material.unit).trim(), ?basis].join(' · '),
      if (gap != null) gapWords(l, gap),
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        for (final String line in lines) Text(line, style: N360Type.fit(N360Type.caption, line)),
      ],
    );
  }
}

/// One material in the budget list: its effect in words, and the working under it.
class ImpactTile extends StatelessWidget {
  const ImpactTile({super.key, required this.impact, required this.staleDays, required this.onTap});

  final MaterialImpact impact;
  final int staleDays;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final WatchedMaterial material = impact.material;
    final Money? effect = impact.impact;
    final StatusPill? stale = stalePill(l, material.current, today, staleDays);
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: <String>[material.name, if (effect != null) effectWords(l, effect)].join('. '),
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Expanded(child: Text(material.name, style: N360Type.fit(N360Type.bodyStrong, material.name))),
                  if (effect != null) ...<Widget>[
                    const SizedBox(width: N360Space.sm),
                    Flexible(
                      child: Text(
                        effectWords(l, effect),
                        textAlign: TextAlign.right,
                        style: N360Type.fit(N360Type.bodyStrong, effectWords(l, effect)).copyWith(color: effectColor(effect)),
                      ),
                    ),
                  ],
                  const SizedBox(width: N360Space.xs),
                  const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink2),
                ],
              ),
              ImpactLines(impact: impact),
              if (stale != null) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Align(alignment: Alignment.centerLeft, child: stale),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// The project's total effect, and the figures it is measured against.
class ImpactSummaryCard extends StatelessWidget {
  const ImpactSummaryCard({super.key, required this.summary});

  final ImpactSummary summary;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Money? total = summary.impact;
    final Money? fresh = summary.freshImpact;
    final String? share = summary.impactPct == null ? null : unsignedPercent(summary.impactPct);
    final List<(String, String)> rows = <(String, String)>[
      if (fresh != null) (l.priceWatchFresh, effectWords(l, fresh)),
      if (share != null) (summary.basis == 'budget' ? l.priceWatchShareBudget : l.priceWatchShareBoq, share),
      if (summary.boq != null) (l.priceWatchBoqValue, AppFormat.rupees(summary.boq!)),
      if (summary.budget != null) (l.priceWatchBudget, AppFormat.rupees(summary.budget!)),
      if (summary.projected != null) (l.priceWatchProjected, AppFormat.rupees(summary.projected!)),
    ];
    return LedgerCard(
      key: const ValueKey<String>('price-summary'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.priceWatchBudgetTitle, style: N360Type.fit(N360Type.label, l.priceWatchBudgetTitle)),
          if (total != null) ...<Widget>[
            const SizedBox(height: N360Space.xxs),
            Text(
              effectWords(l, total),
              style: N360Type.fit(N360Type.title, effectWords(l, total)).copyWith(color: effectColor(total)),
            ),
          ],
          const SizedBox(height: N360Space.xxs),
          Text(l.priceWatchBudgetNote, style: N360Type.fit(N360Type.caption, l.priceWatchBudgetNote)),
          if (summary.beyondMargin || summary.ceiling != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Align(
              alignment: Alignment.centerLeft,
              child: summary.beyondMargin
                  ? StatusPill(kind: StampKind.rejected, label: l.priceWatchBeyond)
                  : StatusPill(kind: StampKind.approved, label: l.priceWatchWithin),
            ),
          ],
          if (rows.isNotEmpty) const SizedBox(height: N360Space.xs),
          for (final (int index, (String label, String value) row) in rows.indexed)
            BookRow.words(label: row.$1, value: row.$2, ruled: index < rows.length - 1),
        ],
      ),
    );
  }
}
