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

    return Padding(
      padding: const EdgeInsets.fromLTRB(0, N360Space.sm, 0, N360Space.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(pictogram.icon, size: 24, color: N360Colors.ink),
              const SizedBox(width: N360Space.sm),
              Expanded(
                child: Text(
                  l.pictogramLabel(pictogram),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.heading.copyWith(fontSize: 20),
                ),
              ),
              BlocSelector<AttendanceCubit, AttendanceState, int>(
                selector: (state) =>
                    group.workerIds.where(state.marks.containsKey).length,
                builder: (context, marked) => Text(
                  l.attendanceGroupProgress(marked, group.workerIds.length),
                  style: N360Type.figureSmall.copyWith(color: N360Colors.inkLabel),
                ),
              ),
            ],
          ),
          const SizedBox(height: N360Space.xs),
          const RuledDivider(color: N360Colors.ruleStrong),
          const SizedBox(height: N360Space.xs),
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
              onTap: canMore ? () => change(hours + overtimeStep) : null,
            ),
          ],
        ),
      ),
    );
  }
}

class _HoursKey extends StatelessWidget {
  const _HoursKey({required this.icon, required this.onTap});

  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(
      dimension: N360Size.tap,
      child: LedgerCard(
        padding: EdgeInsets.zero,
        onTap: onTap,
        child: SizedBox.square(
          dimension: 53,
          child: Icon(
            icon,
            size: 26,
            color: onTap == null ? N360Colors.ink3 : N360Colors.ink,
          ),
        ),
      ),
    );
  }
}
