import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/dpr_detail_cubit.dart';
import '../bloc/dpr_list_cubit.dart';
import '../data/dpr_repository.dart';
import 'widgets/dpr_format.dart';
import 'widgets/dpr_ui.dart';

/// The reports for the current project, newest first.
class DprListScreen extends StatelessWidget {
  const DprListScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final services = context.read<AppServices>();
    return BlocProvider<DprListCubit>(
      create: (_) => DprListCubit(services)..start(),
      child: const _DprListBody(),
    );
  }
}

class _DprListBody extends StatelessWidget {
  const _DprListBody();

  void _open(BuildContext context, SyncedRecord<DprRow> record) {
    final uuid = record.record.clientUuid;
    if (dprOpensForm(record)) {
      dprOpenForm(context, uuid: uuid);
    } else {
      primeDprDetail(record);
      dprOpenDetail(context, uuid);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return BlocBuilder<DprListCubit, DprListState>(
      builder: (context, state) {
        return Scaffold(
          body: LedgerPaper(
            child: SafeArea(
              top: false,
              bottom: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  DprTopBar(title: l.dprListTitle, subtitle: state.project?.name),
                  Expanded(
                    child: DprActionLayout(
                      body: _content(context, state, l),
                      action: state.project == null
                          ? null
                          : GloveButton(
                              label: state.hasToday ? l.dprOpenToday : l.dprWriteToday,
                              icon: state.hasToday ? Icons.edit_note : Icons.add,
                              large: true,
                              fullWidth: true,
                              onPressed: () => dprOpenForm(context, date: state.today),
                            ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _content(BuildContext context, DprListState state, AppLocalizations l) {
    if (state.loading) {
      return Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(rows: 4, semanticsLabel: l.dprLoading),
      );
    }
    if (state.project == null) {
      return Center(child: EmptyLedger(title: l.dprNoProject));
    }
    if (state.rows.isEmpty) {
      return Padding(
        padding: EdgeInsets.only(bottom: DprActionLayout.clearance(context)),
        child: Center(
          child: EmptyLedger(title: l.dprEmptyTitle, message: l.dprEmptyMessage),
        ),
      );
    }
    final groups = _byMonth(state.rows);
    return ListView.builder(
      padding: EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.xs,
        N360Space.gutter,
        DprActionLayout.clearance(context),
      ),
      itemCount: groups.length + 1,
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.only(bottom: N360Space.cardGap),
            child: _MonthCard(state: state),
          );
        }
        final group = groups[index - 1];
        return Padding(
          key: ValueKey<String>(group.first.record.reportDate.substring(0, 7)),
          padding: const EdgeInsets.only(bottom: N360Space.cardGap),
          child: StaggeredRise(
            index: index,
            child: LedgerList(
              header: Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      dprMonthLabel(context, group.first.record.reportDate),
                      style: N360Type.heading,
                    ),
                  ),
                  Text('${group.length}', style: N360Type.label),
                ],
              ),
              children: <Widget>[
                for (final record in group)
                  _ReportRow(
                    key: ValueKey<String>(record.record.clientUuid),
                    record: record,
                    today: state.today,
                    onTap: () => _open(context, record),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  /// Rows arrive newest first, so a month is one unbroken run.
  static List<List<SyncedRecord<DprRow>>> _byMonth(List<SyncedRecord<DprRow>> rows) {
    final groups = <List<SyncedRecord<DprRow>>>[];
    for (final row in rows) {
      final month = row.record.reportDate.substring(0, 7);
      if (groups.isEmpty || groups.last.first.record.reportDate.substring(0, 7) != month) {
        groups.add(<SyncedRecord<DprRow>>[row]);
      } else {
        groups.last.add(row);
      }
    }
    return groups;
  }
}

/// How many days of this month have a report: the one number that shows
/// whether the book is being kept.
class _MonthCard extends StatelessWidget {
  const _MonthCard({required this.state});

  final DprListState state;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final int days = state.daysThisMonth;
    final int filed = state.filedThisMonth;
    final List<String> missed = state.missedDays;
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.dprReportsThisMonth, style: N360Type.bodyStrong),
          const SizedBox(height: N360Space.xs),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: <Widget>[
              RollingNumber(value: '$filed', style: N360Type.figureLarge, animateEntry: true),
              const SizedBox(width: N360Space.sm),
              Flexible(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: N360Space.xxs),
                  child: Text(
                    l.dprOfDays(days),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.label,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: N360Space.sm),
          HatchedProgressBar(
            fraction: days == 0 ? 0 : (filed / days),
            semanticLabel: '$filed ${l.dprOfDays(days)}',
          ),
          if (missed.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Text(l.dprMissedDays, style: N360Type.label),
            Row(
              children: <Widget>[
                for (final String ymd in missed.take(2))
                  Flexible(
                    child: Padding(
                      padding: const EdgeInsets.only(right: N360Space.sm),
                      child: FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: Alignment.centerLeft,
                        child: N360Chip(
                          label: dprDayMonth(context, ymd),
                          icon: Icons.add,
                          selected: false,
                          onTap: () => dprOpenForm(context, date: ymd),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _ReportRow extends StatelessWidget {
  const _ReportRow({
    super.key,
    required this.record,
    required this.today,
    required this.onTap,
  });

  final SyncedRecord<DprRow> record;
  final String today;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final DprRow dpr = record.record;
    final stamp = dprStampFor(l, record);
    final String date = dprDayLabel(context, l, dpr.reportDate, today);
    final String totals =
        '${l.dprWorkersCount(dprWorkerTotal(dpr))} · ${l.dprRowsCount(dpr.workItems.length)}';
    return LedgerHero(
      tag: dprHeroTag(dpr.clientUuid),
      child: LedgerListTile(
        title: date,
        subtitle: '$totals · ${dprSyncText(l, record)}',
        status: StatusPill(kind: stamp.kind, label: stamp.label),
        onTap: onTap,
        semanticsLabel: '$date, $totals, ${stamp.label}, ${dprSyncText(l, record)}',
      ),
    );
  }
}
