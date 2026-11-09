import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/attendance_cubit.dart';

/// Base height of the pinned totals before any text scaling.
const double kTotalsStripHeight = 74;

/// The figures that stay on screen while the muster scrolls. Each cell has its
/// status colour across the top, so the eye learns the mapping once.
class TotalsStrip extends StatelessWidget {
  const TotalsStrip({super.key});

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return ColoredBox(
      color: N360Colors.paper,
      child: BlocSelector<AttendanceCubit, AttendanceState, MusterTotals>(
        selector: (state) => state.totals,
        builder: (context, totals) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(
                    N360Space.gutter,
                    N360Space.xs,
                    N360Space.gutter,
                    N360Space.xs,
                  ),
                  child: Row(
                    children: <Widget>[
                      _Cell(
                        rule: N360Colors.moss,
                        value: totals.present,
                        label: l.designAttendancePresent,
                      ),
                      _Cell(
                        rule: N360Colors.haldi,
                        value: totals.half,
                        label: l.designAttendanceHalf,
                      ),
                      _Cell(
                        rule: N360Colors.neel,
                        value: totals.overtime,
                        label: l.designAttendanceOvertime,
                      ),
                      _Cell(
                        rule: N360Colors.stampRed,
                        value: totals.absent,
                        label: l.designAttendanceAbsent,
                      ),
                      _Cell(
                        rule: N360Colors.ink,
                        value: totals.left,
                        label: l.attendanceLeft,
                        emphasised: true,
                      ),
                    ],
                  ),
                ),
              ),
              ProgressRule(
                value: totals.total == 0 ? 0 : totals.marked / totals.total,
                color: N360Colors.laterite,
                height: 3,
              ),
              const SizedBox(height: 1, child: ColoredBox(color: N360Colors.ink2)),
            ],
          );
        },
      ),
    );
  }
}

class _Cell extends StatelessWidget {
  const _Cell({
    required this.rule,
    required this.value,
    required this.label,
    this.emphasised = false,
  });

  final Color rule;
  final int value;
  final String label;
  final bool emphasised;

  @override
  Widget build(BuildContext context) {
    final TextStyle figure = N360Type.figureLarge.copyWith(
      fontSize: 24,
      height: 1.1,
      fontWeight: emphasised ? FontWeight.w700 : FontWeight.w500,
    );
    return Expanded(
      child: Semantics(
        container: true,
        label: '$value, $label',
        liveRegion: emphasised,
        child: ExcludeSemantics(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 3),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                SizedBox(
                  height: 3,
                  width: double.infinity,
                  child: ColoredBox(color: rule),
                ),
                const SizedBox(height: 4),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: RollingNumber(value: '$value', style: figure),
                ),
                const SizedBox(height: 1),
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
