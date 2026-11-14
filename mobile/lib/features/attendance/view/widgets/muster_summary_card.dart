import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/attendance_cubit.dart';

// The card's height is what its pieces take: some are fixed (paddings, gaps,
// the accent bars, the progress bar) and some grow with the text size (the
// figures and their words), so the two are counted apart and only the second is
// scaled. Type sizes and the bar's height are read from the design system, so a
// change of scale moves the card too.

/// One line of [style], at the default text size.
double _line(TextStyle style) => style.fontSize! * style.height!;

/// Fixed height of the folded card: its padding, the accent bar and gap, the
/// gap above the progress bar and the bar with the room it paints in, and a
/// little air so that a fraction of a point in a font's metrics never clips a
/// line.
double get _foldedFixed =>
    (N360Space.xs + N360Space.sm) +
    (N360Space.sm + N360Space.tile) +
    (N360Space.xs + N360Space.xs) +
    N360Space.sm +
    (const HatchedProgressBar(fraction: 0).height + N360Space.sm) +
    N360Space.xs;

/// A total's figure and its word, the word with Devanagari's taller line.
double get _foldedText =>
    _line(N360Type.figureLarge) + _line(N360Type.forDevanagari(N360Type.label));

/// The "marked so far" word and the hero figure, beside a [N360Size.tap]
/// square that does not scale.
double get _heroText =>
    _line(N360Type.forDevanagari(N360Type.label)) + _line(N360Type.figureHero);

/// Height of the card once folded: five totals and the bar stay on screen.
double summaryCollapsedHeight(double textScale) =>
    _foldedFixed + _foldedText * textScale;

/// Height of the big "marked so far" row, which folds away as the list scrolls.
double summaryHeroHeight(double textScale) =>
    math.max(_heroText * textScale + N360Space.xs, N360Size.tap);

/// Height of the card with the big row showing.
double summaryExpandedHeight(double textScale) =>
    summaryCollapsedHeight(textScale) +
    summaryHeroHeight(textScale) +
    N360Space.cardGap;

/// The muster's summary card. [fold] goes from 0 (the big figure showing) to 1
/// (only the totals and a thin bar left) as the list scrolls up.
///
/// The hero figure counts up once, the first time the card appears. The card
/// keeps its state while the list scrolls, so folding it away and back never
/// replays the count.
class MusterSummaryCard extends StatefulWidget {
  const MusterSummaryCard({super.key, required this.fold});

  final double fold;

  @override
  State<MusterSummaryCard> createState() => _MusterSummaryCardState();
}

class _MusterSummaryCardState extends State<MusterSummaryCard> {
  /// Kept mounted while folded away, so its count-up plays once.
  late final Widget _hero = const _HeroRow();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final double scale = MediaQuery.textScalerOf(context).scale(1);
    final double fold = widget.fold;

    // The page colour hides rows passing behind the card and in its margins.
    return ColoredBox(
      color: N360Colors.paper,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          N360Space.gutter,
          N360Space.xs,
          N360Space.gutter,
          N360Space.sm,
        ),
        child: RepaintBoundary(
          child: LedgerCard(
            padding: const EdgeInsets.fromLTRB(
              N360Space.card,
              N360Space.sm,
              N360Space.card,
              N360Space.tile,
            ),
            child: BlocSelector<AttendanceCubit, AttendanceState, MusterTotals>(
              selector: (state) => state.totals,
              builder: (context, totals) {
                final double fraction = totals.total == 0
                    ? 0
                    : totals.marked / totals.total;
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Expanded(
                      child: Offstage(
                        offstage: fold > 0.9,
                        child: ClipRect(
                          child: OverflowBox(
                            alignment: Alignment.topCenter,
                            minHeight: 0,
                            maxHeight: summaryHeroHeight(scale),
                            child: _hero,
                          ),
                        ),
                      ),
                    ),
                    Row(
                      children: <Widget>[
                        _Total(
                          accent: N360Colors.moss,
                          value: totals.present,
                          label: l.designAttendancePresent,
                        ),
                        _Total(
                          accent: N360Colors.haldi,
                          value: totals.half,
                          label: l.designAttendanceHalf,
                        ),
                        _Total(
                          accent: N360Colors.neel,
                          value: totals.overtime,
                          label: l.designAttendanceOvertime,
                        ),
                        _Total(
                          accent: N360Colors.stampRed,
                          value: totals.absent,
                          label: l.designAttendanceAbsent,
                        ),
                        _Total(
                          accent: N360Colors.ink,
                          value: totals.left,
                          label: l.attendanceLeft,
                          emphasised: true,
                        ),
                      ],
                    ),
                    const SizedBox(height: N360Space.sm),
                    HatchedProgressBar(
                      fraction: fraction,
                      semanticLabel: l.attendanceGroupProgress(
                        totals.marked,
                        totals.total,
                      ),
                    ),
                  ],
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}

/// The big figure and its square. It reads the totals itself, so the card
/// can hold one instance for as long as it lives.
class _HeroRow extends StatelessWidget {
  const _HeroRow();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocSelector<AttendanceCubit, AttendanceState, MusterTotals>(
      selector: (state) => state.totals,
      builder: (context, totals) {
        final double fraction = totals.total == 0
            ? 0
            : totals.marked / totals.total;
        return Row(
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    l.attendanceMarkedSoFar,
                    style: N360Type.fit(
                      N360Type.label,
                      l.attendanceMarkedSoFar,
                    ).copyWith(color: N360Colors.inkLabel),
                  ),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: RollingNumber(
                      value: '${totals.marked}',
                      style: N360Type.figureHero,
                      suffix: ' / ${totals.total}',
                      suffixStyle: N360Type.figureLarge.copyWith(
                        color: N360Colors.inkLabel,
                      ),
                      animateEntry: true,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: N360Space.sm),
            SquarePercent(
              fraction: fraction,
              label: '${(fraction * 100).round()}%',
              size: N360Size.tap,
            ),
          ],
        );
      },
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({
    required this.accent,
    required this.value,
    required this.label,
    this.emphasised = false,
  });

  final Color accent;
  final int value;
  final String label;
  final bool emphasised;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Semantics(
        container: true,
        label: '$value, $label',
        liveRegion: emphasised,
        child: ExcludeSemantics(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.xxs),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                DecoratedBox(
                  decoration: BoxDecoration(
                    color: accent,
                    borderRadius: N360Radius.sm,
                  ),
                  child: const SizedBox(
                    width: N360Space.xl,
                    height: N360Space.xs,
                  ),
                ),
                const SizedBox(height: N360Space.xs),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: RollingNumber(
                    value: '$value',
                    style: N360Type.figureLarge,
                  ),
                ),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    label,
                    maxLines: 1,
                    style: N360Type.fit(N360Type.label, label).copyWith(
                      color: emphasised ? N360Colors.ink : N360Colors.inkLabel,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
