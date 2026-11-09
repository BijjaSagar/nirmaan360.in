import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/attendance_cubit.dart';

/// One worker. Reads only this worker's mark, so a tap rebuilds this row and
/// nothing else in the list.
class MusterRow extends StatelessWidget {
  const MusterRow({super.key, required this.worker});

  final MusterWorker worker;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Pictogram pictogram = N360Pictograms.trade(worker.trade);

    return BlocSelector<AttendanceCubit, AttendanceState, DayMark?>(
      selector: (state) => state.marks[worker.id],
      builder: (context, mark) {
        final bool overtime = mark?.value == HajiriValue.overtime;
        return Padding(
          padding: const EdgeInsets.only(bottom: N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              ConstrainedBox(
                constraints: BoxConstraints(
                  minHeight: overtime ? N360Size.tap : 32,
                ),
                child: Row(
                  children: <Widget>[
                    Icon(pictogram.icon, size: 20, color: N360Colors.ink2),
                    const SizedBox(width: N360Space.sm),
                    Expanded(
                      child: Text(
                        worker.name,
                        maxLines: overtime ? 2 : 1,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.bodyStrong.copyWith(fontSize: 17, height: 1.25),
                      ),
                    ),
                    if (overtime)
                      _OvertimeHours(
                        workerId: worker.id,
                        workerName: worker.name,
                        hours: mark!.otHours,
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 2),
              HajiriSegment(
                value: mark?.value,
                labels: l.hajiriLabels,
                semanticsLabel: worker.name,
                onChanged: (value) =>
                    context.read<AttendanceCubit>().mark(worker.id, value),
              ),
            ],
          ),
        );
      },
    );
  }
}

/// A trade heading with the group's progress. It rebuilds only when that
/// group's count changes.
class MusterGroupHeader extends StatelessWidget {
  const MusterGroupHeader({super.key, required this.group});

  final MusterGroup group;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Pictogram pictogram = N360Pictograms.trade(group.trade);
    final String label = l.pictogramLabel(pictogram);
    final int total = group.workers.length;

    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.md),
      child: Row(
        children: <Widget>[
          IconTile(icon: pictogram.icon, semanticLabel: label),
          const SizedBox(width: N360Space.md),
          Expanded(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: N360Type.heading,
            ),
          ),
          BlocSelector<AttendanceCubit, AttendanceState, int>(
            selector: (state) =>
                group.workers.where((w) => state.marks.containsKey(w.id)).length,
            builder: (context, marked) => Semantics(
              container: true,
              label: l.attendanceGroupProgress(marked, total),
              child: ExcludeSemantics(
                child: SquarePercent(
                  fraction: total == 0 ? 0 : marked / total,
                  label: '$marked/$total',
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A small hours control beside the name, with no dialog in the way.
class _OvertimeHours extends StatelessWidget {
  const _OvertimeHours({
    required this.workerId,
    required this.workerName,
    required this.hours,
  });

  final int workerId;
  final String workerName;
  final Hours hours;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final AttendanceCubit cubit = context.read<AttendanceCubit>();
    final bool canLess = hours - overtimeStep >= overtimeMin;
    final bool canMore = hours + overtimeStep <= overtimeMax;

    void change(Hours next) => cubit.setOvertimeHours(workerId, next);

    String spoken(Hours h) => '${h.toWire()} ${l.attendanceHoursUnit}';

    return Semantics(
      container: true,
      label: '$workerName, ${l.attendanceOtHours}',
      value: spoken(hours),
      increasedValue: canMore ? spoken(hours + overtimeStep) : null,
      decreasedValue: canLess ? spoken(hours - overtimeStep) : null,
      onIncrease: canMore ? () => change(hours + overtimeStep) : null,
      onDecrease: canLess ? () => change(hours - overtimeStep) : null,
      child: ExcludeSemantics(
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            _HoursKey(
              icon: Icons.remove,
              label: l.attendanceOtLess,
              onTap: canLess ? () => change(hours - overtimeStep) : null,
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: N360Space.sm),
              child: RollingNumber(
                value: hours.toWire(),
                style: N360Type.figure.copyWith(fontSize: 20),
                suffix: ' ${l.attendanceHoursUnit}',
                suffixStyle: N360Type.caption.copyWith(fontWeight: FontWeight.w600),
              ),
            ),
            _HoursKey(
              icon: Icons.add,
              label: l.attendanceOtMore,
              onTap: canMore ? () => change(hours + overtimeStep) : null,
            ),
          ],
        ),
      ),
    );
  }
}

class _HoursKey extends StatelessWidget {
  const _HoursKey({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final Widget key = IconTile(icon: icon, semanticLabel: label, onTap: onTap);
    // A stepper at its limit looks spent rather than pressable.
    return onTap == null ? Opacity(opacity: 0.4, child: key) : key;
  }
}
