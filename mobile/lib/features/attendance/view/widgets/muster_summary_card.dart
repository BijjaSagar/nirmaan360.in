import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/attendance_cubit.dart';

/// Height of the big "marked so far" row, which folds away as the list scrolls.
const double kSummaryHeroHeight = 74;

/// Height of the card once folded: five totals and the gauge stay on screen.
const double kSummaryCollapsedHeight = 100;

/// The muster's summary card. [fold] goes from 0 (the big figure showing) to 1
/// (only the totals and a thin gauge left) as the list scrolls up.
class MusterSummaryCard extends StatelessWidget {
  const MusterSummaryCard({super.key, required this.fold});

  final double fold;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final double scale = MediaQuery.textScalerOf(context).scale(1);

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
            padding: const EdgeInsets.fromLTRB(14, 8, 14, 10),
            child: BlocSelector<AttendanceCubit, AttendanceState, MusterTotals>(
              selector: (state) => state.totals,
              builder: (context, totals) {
                final double fraction = totals.total == 0 ? 0 : totals.marked / totals.total;
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    Expanded(
                      child: fold > 0.9
                          ? const SizedBox.shrink()
                          : ClipRect(
                              child: OverflowBox(
                                alignment: Alignment.topCenter,
                                minHeight: 0,
                                maxHeight: kSummaryHeroHeight * scale,
                                child: _HeroRow(totals: totals, fraction: fraction),
                              ),
                            ),
                    ),
                    Row(
                      children: <Widget>[
                        _Total(accent: N360Colors.moss, value: totals.present, label: l.designAttendancePresent),
                        _Total(accent: N360Colors.haldi, value: totals.half, label: l.designAttendanceHalf),
                        _Total(accent: N360Colors.neel, value: totals.overtime, label: l.designAttendanceOvertime),
                        _Total(accent: N360Colors.stampRed, value: totals.absent, label: l.designAttendanceAbsent),
                        _Total(accent: N360Colors.ink, value: totals.left, label: l.attendanceLeft, emphasised: true),
                      ],
                    ),
                    const SizedBox(height: 6),
                    TickGauge(
                      fraction: fraction,
                      ticks: math.max(totals.total, 12).clamp(12, 60),
                      height: lerpDouble(20, 10, fold)!,
                      semanticLabel: l.attendanceGroupProgress(totals.marked, totals.total),
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

class _HeroRow extends StatelessWidget {
  const _HeroRow({required this.totals, required this.fraction});

  final MusterTotals totals;
  final double fraction;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Row(
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(l.attendanceMarkedSoFar, style: N360Type.bodyStrong.copyWith(height: 1.25, color: N360Colors.ink2)),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: RollingNumber(
                  value: '${totals.marked}',
                  style: N360Type.figureHero.copyWith(fontSize: 40, height: 1.15),
                  suffix: ' / ${totals.total}',
                  suffixStyle: N360Type.figureLarge.copyWith(fontSize: 22, color: N360Colors.inkLabel),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: N360Space.sm),
        SquarePercent(
          fraction: fraction,
          label: '${(fraction * 100).round()}%',
          size: 60,
        ),
      ],
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
            padding: const EdgeInsets.symmetric(horizontal: 2),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                DecoratedBox(
                  decoration: BoxDecoration(color: accent, borderRadius: N360Radius.sm),
                  child: const SizedBox(width: 22, height: 4),
                ),
                const SizedBox(height: 3),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: RollingNumber(
                    value: '$value',
                    style: N360Type.figureLarge.copyWith(fontSize: 22, height: 1.15),
                  ),
                ),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    label,
                    maxLines: 1,
                    style: N360Type.caption.copyWith(
                      fontSize: 13,
                      height: 1.25,
                      color: emphasised ? N360Colors.ink : N360Colors.inkLabel,
                      fontWeight: emphasised ? FontWeight.w700 : FontWeight.w600,
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
