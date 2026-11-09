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

/// The reports book for the current project, newest first.
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
                  const DprTopBar(),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
                    child: SheetHeader(
                      eyebrow: state.project?.name,
                      title: l.dprListTitle,
                    ),
                  ),
                  Expanded(child: _content(context, state, l)),
                  if (state.project != null)
                    DprBottomAction(
                      label: state.hasToday ? l.dprOpenToday : l.dprWriteToday,
                      icon: state.hasToday ? Icons.edit_note : Icons.add,
                      onPressed: () => dprOpenForm(context, date: state.today),
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
      return Center(
        child: EmptyLedger(title: l.dprEmptyTitle, message: l.dprEmptyMessage),
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
      itemCount: state.rows.length,
      itemBuilder: (context, index) {
        final SyncedRecord<DprRow> record = state.rows[index];
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
      padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: 10),
      child: LedgerHero(
        tag: dprHeroTag(dpr.clientUuid),
        child: DprReportFace(record: record, today: today),
      ),
    );
  }
}

/// A report at a glance: date, totals, where it is, and its stamp. The list
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
  final bool large;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final DprRow dpr = record.record;
    final stamp = dprStampFor(l, record);
    final int workers = dprWorkerTotal(dpr);
    return Row(
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Flexible(
                    child: Text(
                      dprDateLabel(context, dpr.reportDate),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: large ? N360Type.title : N360Type.heading,
                    ),
                  ),
                  if (today) ...<Widget>[
                    const SizedBox(width: N360Space.sm),
                    Text(
                      l.commonToday,
                      style: N360Type.caption.copyWith(
                        color: N360Colors.stampRed,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ],
              ),
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
        const SizedBox(width: N360Space.sm),
        RubberStamp(
          kind: stamp.kind,
          label: stamp.label,
          size: large ? StampSize.medium : StampSize.small,
        ),
      ],
    );
  }
}
