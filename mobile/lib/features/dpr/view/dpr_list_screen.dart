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
              bottom: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  DprTopBar(title: l.dprListTitle),
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
    if (state.loading) return const SizedBox.shrink();
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
    return ListView.builder(
      padding: EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.xs,
        N360Space.gutter,
        DprActionLayout.clearance(context),
      ),
      itemCount: state.rows.length + 1,
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.only(bottom: N360Space.md),
            child: _MonthCard(state: state),
          );
        }
        final SyncedRecord<DprRow> record = state.rows[index - 1];
        return Padding(
          key: ValueKey<String>(record.record.clientUuid),
          padding: const EdgeInsets.only(bottom: N360Space.sm),
          child: StaggeredRise(
            index: index,
            child: _ReportRow(
              record: record,
              today: record.record.reportDate == state.today,
              onTap: () => _open(context, record),
            ),
          ),
        );
      },
    );
  }
}

/// How many days of this month have a report: the one number that shows whether
/// the book is being kept.
class _MonthCard extends StatelessWidget {
  const _MonthCard({required this.state});

  final DprListState state;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final int days = state.daysThisMonth;
    final int filed = state.filedThisMonth;
    return LedgerCard(
        padding: const EdgeInsets.all(N360Space.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (state.project != null) SectionLabel(state.project!.name),
                      Text(l.dprReportsThisMonth, style: N360Type.heading),
                    ],
                  ),
                ),
                IconTile(icon: Icons.calendar_month, semanticLabel: l.dprReportsThisMonth),
              ],
            ),
            const SizedBox(height: N360Space.xs),
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: <Widget>[
                Text('$filed', style: N360Type.figureHero),
                const SizedBox(width: N360Space.sm),
                Flexible(
                  child: Text(
                    l.dprOfDays(days),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.bodyStrong.copyWith(color: N360Colors.ink2),
                  ),
                ),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            HatchedProgressBar(
              fraction: days == 0 ? 0 : (filed / days),
              height: 36,
              semanticLabel: '$filed ${l.dprOfDays(days)}',
            ),
          ],
        ),
    );
  }
}

class _ReportRow extends StatelessWidget {
  const _ReportRow({required this.record, required this.today, required this.onTap});

  final SyncedRecord<DprRow> record;
  final bool today;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final DprRow dpr = record.record;
    final stamp = dprStampFor(l, record);
    final String date = dprDateLabel(context, dpr.reportDate);
    return LedgerCard(
      onTap: onTap,
      semanticsLabel:
          '$date, ${l.dprWorkersCount(dprWorkerTotal(dpr))}, ${l.dprRowsCount(dpr.workItems.length)}, ${stamp.label}',
      padding: const EdgeInsets.all(N360Space.md),
      child: LedgerHero(
        tag: dprHeroTag(dpr.clientUuid),
        child: DprReportFace(record: record, today: today),
      ),
    );
  }
}

/// A report at a glance: the day, totals, where it is, and its stamp. The list
/// row and the detail masthead share it, so the hero has one shape to fly.
class DprReportFace extends StatelessWidget {
  const DprReportFace({
    super.key,
    required this.record,
    this.today = false,
    this.large = false,
  });

  final SyncedRecord<DprRow> record;
  final bool today;

  /// The detail masthead: no totals, because tiles below it carry them.
  final bool large;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final DprRow dpr = record.record;
    final stamp = dprStampFor(l, record);
    final int workers = dprWorkerTotal(dpr);

    final Widget day = Row(
      children: <Widget>[
        Flexible(
          child: Text(
            dprWeekday(context, dpr.reportDate),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: N360Type.heading,
          ),
        ),
        if (today) ...<Widget>[
          const SizedBox(width: N360Space.sm),
          Text(
            l.commonToday,
            style: N360Type.caption.copyWith(
              color: N360Colors.laterite,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ],
    );
    final Widget mark = RubberStamp(
      kind: stamp.kind,
      label: stamp.label,
      size: large ? StampSize.medium : StampSize.small,
    );

    return Row(
      children: <Widget>[
        DprDateTile(ymd: dpr.reportDate, today: today),
        const SizedBox(width: N360Space.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              // The stamp shares the short first line, so the totals get the
              // full width and never truncate.
              if (large)
                day
              else
                Row(
                  children: <Widget>[
                    Expanded(child: day),
                    const SizedBox(width: N360Space.sm),
                    mark,
                  ],
                ),
              if (!large)
                Text(
                  '${l.dprWorkersCount(workers)} · ${l.dprRowsCount(dpr.workItems.length)}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.body,
                ),
              const SizedBox(height: 2),
              DprSyncTag(record: record),
            ],
          ),
        ),
        if (large) ...<Widget>[
          const SizedBox(width: N360Space.sm),
          mark,
        ],
      ],
    );
  }
}
