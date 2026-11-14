import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/floor_setup_cubit.dart';
import '../data/floor_setup_repository.dart';
import 'floor_setup_screen.dart';
import 'widgets/progress_widgets.dart';

/// One week of the project, Monday to Sunday: what was done and on which
/// floor, what is pending, and the work under way. Read from the server each
/// time. Needs `progress.view`.
class ProgressWeekScreen extends StatelessWidget {
  const ProgressWeekScreen({super.key, this.date});

  /// A day of the week to show, `yyyy-mm-dd`. Null for this week.
  final String? date;

  @override
  Widget build(BuildContext context) {
    final int? projectId = context.select((AppGate gate) => gate.state.currentProject?.id);
    if (projectId == null) return const Scaffold(body: LedgerPaper(child: SizedBox.expand()));
    return BlocProvider<ProgressWeekCubit>(
      key: ValueKey<int>(projectId),
      create: (context) => ProgressWeekCubit(floorSetupRepository(context), projectId)..load(date: date),
      child: const _WeekView(),
    );
  }
}

class _WeekView extends StatelessWidget {
  const _WeekView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          child: BlocBuilder<ProgressWeekCubit, ProgressWeekState>(
            builder: (context, state) {
              final ProgressWeekCubit cubit = context.read<ProgressWeekCubit>();
              final ProgressWeek? week = state.week;
              final bool loading = state.load == SetupLoad.loading;
              final String? range = week == null
                  ? null
                  : l.progressWeekRange(AppFormat.dayMonth(week.start, locale), AppFormat.dayMonthYear(week.end, locale));
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.progressWeekTitle, subtitle: range),
                  if (week != null)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
                      child: Row(
                        children: <Widget>[
                          Expanded(
                            child: GloveButton(
                              key: const ValueKey<String>('week-previous'),
                              label: l.progressWeekPrevious,
                              icon: Icons.chevron_left,
                              kind: GloveButtonKind.secondary,
                              fullWidth: true,
                              onPressed: loading ? null : cubit.previous,
                            ),
                          ),
                          const SizedBox(width: N360Space.sm),
                          Expanded(
                            child: GloveButton(
                              key: const ValueKey<String>('week-next'),
                              label: l.progressWeekNext,
                              icon: Icons.chevron_right,
                              kind: GloveButtonKind.secondary,
                              fullWidth: true,
                              // Nothing is entered for a week that has not come yet.
                              onPressed: loading || week.isCurrent ? null : cubit.next,
                            ),
                          ),
                        ],
                      ),
                    ),
                  Expanded(
                    child: switch (state.load) {
                      SetupLoad.offline => SetupLoadProblem(title: l.progressWeekOffline, onRetry: cubit.retry),
                      SetupLoad.failed => SetupLoadProblem(title: l.progressWeekFailed, onRetry: cubit.retry),
                      SetupLoad.loading => Padding(
                        padding: const EdgeInsets.all(N360Space.gutter),
                        child: LedgerSkeletonList(lines: 4, semanticsLabel: l.progressLoading),
                      ),
                      SetupLoad.ready => _WeekBody(week: week!),
                    },
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _WeekBody extends StatelessWidget {
  const _WeekBody({required this.week});

  final ProgressWeek week;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    Widget heading(String text, {String? count}) => Padding(
      padding: const EdgeInsets.only(top: N360Space.lg, bottom: N360Space.xs),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(text, style: N360Type.eyebrowFor(text))),
          if (count != null) Text(count, style: N360Type.fit(N360Type.caption, count).copyWith(color: N360Colors.ink2)),
        ],
      ),
    );
    Widget card(List<Widget> rows) => LedgerCard(
      padding: EdgeInsets.zero,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          for (final (int index, Widget row) in rows.indexed) ...<Widget>[
            if (index > 0) const RuledDivider(indent: N360Space.card),
            row,
          ],
        ],
      ),
    );
    String name(ProgressItem item) => '${item.code} ${item.description}'.trim();

    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        heading(l.progressWeekDone, count: week.entryCount > 0 ? l.progressWeekEntries(week.entryCount) : null),
        if (week.done.isEmpty)
          Text(
            l.progressWeekNothingDone,
            key: const ValueKey<String>('week-nothing-done'),
            style: N360Type.fit(N360Type.body, l.progressWeekNothingDone).copyWith(color: N360Colors.ink2),
          )
        else
          card(<Widget>[
            for (final WeekLine line in week.done)
              LedgerListTile(
                key: ValueKey<String>('week-done-${line.item.id}'),
                title: name(line.item),
                subtitle: <String>[
                  l.progressWeekQty(line.weekQty.format(), line.item.unit),
                  for (final ({String name, Qty qty}) floor in line.floors) '${floor.name}: ${floor.qty.format()}',
                ].join('\n'),
                trailing: Text(percentText(line.item.percent), style: N360Type.figureSmall),
              ),
          ]),
        if (week.pending.isNotEmpty) ...<Widget>[
          heading(l.progressWeekPending, count: '${week.pending.length}'),
          card(<Widget>[
            for (final PendingEntry entry in week.pending)
              LedgerListTile(
                title: '${entry.itemCode ?? ''} ${entry.itemDescription ?? ''}'.trim(),
                subtitle: <String>[?entry.floorName, ?entry.remark].join(' · '),
                status: StatusPill(kind: StampKind.returned, label: l.progressWeekPending),
              ),
          ]),
        ],
        if (week.next.isNotEmpty) ...<Widget>[
          heading(l.progressWeekUnderWay),
          card(<Widget>[
            for (final ProgressItem item in week.next)
              ProgressBarTile(
                key: ValueKey<String>('week-next-${item.id}'),
                title: name(item),
                percent: item.percent,
                caption: l.progressWeekLeft(item.balance.format(), item.unit, percentText(item.percent)),
              ),
          ]),
          if (week.nextMore > 0)
            Padding(
              padding: const EdgeInsets.only(top: N360Space.xs),
              child: Text(
                l.progressWeekMore(week.nextMore),
                style: N360Type.fit(N360Type.caption, l.progressWeekMore(week.nextMore)).copyWith(color: N360Colors.ink2),
              ),
            ),
        ],
        if (week.notStarted > 0)
          Padding(
            padding: const EdgeInsets.only(top: N360Space.lg),
            child: Text(
              l.progressWeekNotStarted(week.notStarted),
              key: const ValueKey<String>('week-not-started'),
              style: N360Type.fit(N360Type.label, l.progressWeekNotStarted(week.notStarted)).copyWith(color: N360Colors.ink2),
            ),
          ),
      ],
    );
  }
}
