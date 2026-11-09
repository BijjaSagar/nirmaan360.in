import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/attendance_cubit.dart';

/// The last seven days, newest on the right where the thumb rests. There is
/// nothing to the right of today, so a future date cannot be chosen.
class DayStrip extends StatelessWidget {
  const DayStrip({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<AttendanceCubit, AttendanceState>(
      buildWhen: (a, b) => a.date != b.date,
      builder: (context, state) {
        return SizedBox(
          height: 62,
          child: ListView.separated(
            reverse: true,
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
            itemCount: state.recentDates.length,
            separatorBuilder: (_, _) => const SizedBox(width: N360Space.sm),
            itemBuilder: (context, index) {
              final String date = state.recentDates[index];
              return _DayTile(
                date: date,
                index: index,
                selected: date == state.date,
                onTap: () => context.read<AttendanceCubit>().selectDate(date),
              );
            },
          ),
        );
      },
    );
  }
}

class _DayTile extends StatelessWidget {
  const _DayTile({
    required this.date,
    required this.index,
    required this.selected,
    required this.onTap,
  });

  final String date;

  /// 0 is today, 1 yesterday.
  final int index;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).toString();
    final DateTime day = DateTime.parse(date);
    final String top = switch (index) {
      0 => l.attendanceDayToday,
      1 => l.attendanceDayYesterday,
      _ => DateFormat.E(locale).format(day),
    };
    final String bottom = DateFormat('d MMM', locale).format(day);

    return SizedBox(
      width: 78,
      child: LedgerCard(
        padding: EdgeInsets.zero,
        onTap: onTap,
        semanticsLabel: '$top, $bottom',
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: selected ? N360Colors.ink : null,
            borderRadius: N360Radius.sm,
          ),
          child: SizedBox(
            height: 59,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    child: Text(
                      top,
                      maxLines: 1,
                      style: N360Type.bodyStrong.copyWith(
                        height: 1.2,
                        color: selected ? N360Colors.sheet : N360Colors.ink,
                      ),
                    ),
                  ),
                ),
                Text(
                  bottom,
                  maxLines: 1,
                  style: N360Type.figureSmall.copyWith(
                    fontSize: 13,
                    height: 1.2,
                    color: selected ? N360Colors.sheet : N360Colors.inkLabel,
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
