import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../bloc/attendance_cubit.dart';
import 'field_kit.dart';

/// The last seven days as filter chips, newest on the right where the thumb
/// rests. There is nothing to the right of today, so a future date cannot be
/// chosen. Each chip is one line: the day, then its date.
class DayStrip extends StatelessWidget {
  const DayStrip({super.key});

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).toString();

    return BlocBuilder<AttendanceCubit, AttendanceState>(
      buildWhen: (a, b) => a.date != b.date,
      builder: (context, state) {
        return FieldChoiceStrip(
          reverse: true,
          itemCount: state.recentDates.length,
          itemBuilder: (context, index) {
            final String date = state.recentDates[index];
            final DateTime day = DateTime.parse(date);
            return N360Chip(
              label: switch (index) {
                0 => l.attendanceDayToday,
                1 => l.attendanceDayYesterday,
                _ => DateFormat.E(locale).format(day),
              },
              count: DateFormat('d MMM', locale).format(day),
              selected: date == state.date,
              onTap: () => context.read<AttendanceCubit>().selectDate(date),
            );
          },
        );
      },
    );
  }
}
