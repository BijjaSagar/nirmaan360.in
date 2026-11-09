import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/progress_cubit.dart';
import '../data/progress_repository.dart';
import 'widgets/progress_widgets.dart';

/// How far the work has come: the whole project first, then by floor, by
/// plumbing and electrical stage, and what is pending.
class ProgressScreen extends StatelessWidget {
  const ProgressScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProgressCubit>(
      create: (context) => ProgressCubit(context.read<AppServices>()),
      child: const _ProgressView(),
    );
  }
}

class _ProgressView extends StatelessWidget {
  const _ProgressView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canAdd = context.select((AppGate gate) => gate.state.can('progress.create'));
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              BlocBuilder<ProgressCubit, ProgressState>(
                buildWhen: (a, b) => a.project?.id != b.project?.id,
                builder: (context, state) => FieldTopBar(title: l.progressTitle, subtitle: state.project?.code),
              ),
              const Expanded(child: _ProgressScroll()),
              if (canAdd)
                FieldActionBar(
                  child: GloveButton(
                    key: const ValueKey<String>('progress-add'),
                    label: l.progressAdd,
                    icon: Icons.add,
                    large: true,
                    fullWidth: true,
                    onPressed: () => context.push(Routes.progressForm),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ProgressScroll extends StatelessWidget {
  const _ProgressScroll();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<ProgressCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.progressLoading,
      child: BlocBuilder<ProgressCubit, ProgressState>(
        builder: (context, state) {
          return CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: _slivers(context, l, state),
          );
        },
      ),
    );
  }

  List<Widget> _slivers(BuildContext context, AppLocalizations l, ProgressState state) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));

    if (state.load == ProgressLoad.loading) {
      return <Widget>[
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: LedgerSkeletonList(lines: 3, semanticsLabel: l.progressLoading),
          ),
        ),
      ];
    }
    if (state.load == ProgressLoad.noProject) {
      return <Widget>[empty(l.progressNoProjectTitle, l.progressNoProjectMessage)];
    }

    final ProgressOverview? overview = state.overview;
    if (overview == null) {
      if (state.refreshing) {
        return <Widget>[
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(lines: 3, semanticsLabel: l.progressLoading),
            ),
          ),
        ];
      }
      return <Widget>[empty(l.progressNoDataTitle, l.progressNoDataMessage)];
    }

    return <Widget>[
      SliverToBoxAdapter(child: _SummaryCard(overview: overview)),
      SliverToBoxAdapter(child: _TabStrip(state: state, overview: overview)),
      ..._tab(context, l, state, overview),
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }

  List<Widget> _tab(BuildContext context, AppLocalizations l, ProgressState state, ProgressOverview overview) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));

    switch (state.tab) {
      case ProgressTab.floors:
        if (overview.floors.isEmpty) return <Widget>[empty(l.progressNoFloorsTitle, l.progressNoFloorsMessage)];
        return <Widget>[_FloorList(floors: overview.floors)];
      case ProgressTab.stages:
        final StageTrade? trade = state.currentTrade;
        if (trade == null || overview.matrix.rows.isEmpty) {
          return <Widget>[empty(l.progressNoFloorsTitle, l.progressNoFloorsMessage)];
        }
        return <Widget>[
          SliverToBoxAdapter(child: _TradeStrip(matrix: overview.matrix, current: trade)),
          _StageRows(matrix: overview.matrix, trade: trade),
        ];
      case ProgressTab.pending:
        if (overview.pending.isEmpty) return <Widget>[empty(l.progressNoPendingTitle, l.progressNoPendingMessage)];
        return <Widget>[_PendingList(entries: overview.pending)];
    }
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.overview});

  final ProgressOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressSummary summary = overview.summary;
    final String work = percentText(summary.workPercent);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.sm),
      child: LedgerCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(l.progressWorkDone, style: N360Type.fit(N360Type.label, l.progressWorkDone)),
                      Text(work, style: N360Type.figureLarge),
                    ],
                  ),
                ),
                if (summary.isBehind)
                  StatusPill(kind: StampKind.returned, label: l.progressBehind(percentText(summary.lag)))
                else if (summary.status == 'on_track')
                  StatusPill(kind: StampKind.approved, label: l.progressOnTrack),
              ],
            ),
            HatchedProgressBar(
              fraction: percentFraction(summary.workPercent),
              delayed: summary.isBehind,
              semanticLabel: '${l.progressWorkDone} $work',
            ),
            if (summary.timePercent != null)
              Text(
                l.progressTimeUsed(percentText(summary.timePercent)),
                style: N360Type.fit(N360Type.caption, l.progressTitle),
              ),
            if (overview.unsent > 0)
              Padding(
                padding: const EdgeInsets.only(top: N360Space.xs),
                child: Row(
                  children: <Widget>[
                    const Icon(Icons.cloud_upload_outlined, size: N360IconSize.md, color: N360Colors.ink2),
                    const SizedBox(width: N360Space.xs),
                    Expanded(
                      child: Text(
                        l.syncPendingCount(overview.unsent),
                        style: N360Type.fit(N360Type.caption, l.progressTitle),
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _TabStrip extends StatelessWidget {
  const _TabStrip({required this.state, required this.overview});

  final ProgressState state;
  final ProgressOverview overview;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressCubit cubit = context.read<ProgressCubit>();
    final List<(ProgressTab, String, String?)> tabs = <(ProgressTab, String, String?)>[
      (ProgressTab.floors, l.progressTabFloors, null),
      (ProgressTab.stages, l.progressTabStages, null),
      (ProgressTab.pending, l.progressTabPending, '${overview.pending.length}'),
    ];
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: Row(
        children: <Widget>[
          for (final (index, (tab, label, count)) in tabs.indexed) ...<Widget>[
            if (index > 0) const SizedBox(width: N360Space.sm),
            N360Chip(label: label, count: count, selected: state.tab == tab, onTap: () => cubit.setTab(tab)),
          ],
        ],
      ),
    );
  }
}

class _TradeStrip extends StatelessWidget {
  const _TradeStrip({required this.matrix, required this.current});

  final StageMatrix matrix;
  final StageTrade current;

  @override
  Widget build(BuildContext context) {
    final ProgressCubit cubit = context.read<ProgressCubit>();
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: Row(
        children: <Widget>[
          for (final (index, trade) in matrix.trades.indexed) ...<Widget>[
            if (index > 0) const SizedBox(width: N360Space.sm),
            N360Chip(label: trade.label, selected: trade.key == current.key, onTap: () => cubit.setTrade(trade.key)),
          ],
        ],
      ),
    );
  }
}

class _FloorList extends StatelessWidget {
  const _FloorList({required this.floors});

  final List<ProgressFloor> floors;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return FieldGroupSliver(
      padding: EdgeInsets.zero,
      sliver: SliverList.builder(
        itemCount: floors.length,
        itemBuilder: (context, index) {
          final ProgressFloor floor = floors[index];
          final int pending = floor.pending + floor.mepPending;
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              if (index > 0) const RuledDivider(indent: N360Space.card),
              ProgressBarTile(
                key: ValueKey<String>('progress-floor-${floor.id}'),
                title: floor.name,
                percent: floor.percent,
                alert: pending > 0 ? l.progressPendingCount(pending) : null,
                onTap: () => context.push(Routes.progressFloor(floor.id)),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _StageRows extends StatelessWidget {
  const _StageRows({required this.matrix, required this.trade});

  final StageMatrix matrix;
  final StageTrade trade;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return FieldGroupSliver(
      padding: EdgeInsets.zero,
      sliver: SliverList.builder(
        itemCount: matrix.rows.length,
        itemBuilder: (context, index) {
          final StageRow row = matrix.rows[index];
          final ({int done, int total}) tally = matrix.tally(row, trade);
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              if (index > 0) const RuledDivider(indent: N360Space.card),
              LedgerListTile(
                key: ValueKey<String>('progress-stage-row-${row.id}'),
                title: row.label,
                subtitle: l.progressStagesDone(tally.done, tally.total),
                status: row.pending > 0
                    ? StatusPill(kind: StampKind.returned, label: l.progressPendingCount(row.pending))
                    : null,
                onTap: () => context.push(Routes.progressStage(row.id, trade.key)),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _PendingList extends StatelessWidget {
  const _PendingList({required this.entries});

  final List<PendingEntry> entries;

  @override
  Widget build(BuildContext context) {
    return FieldGroupSliver(
      padding: EdgeInsets.zero,
      sliver: SliverList.builder(
        itemCount: entries.length,
        itemBuilder: (context, index) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (index > 0) const RuledDivider(indent: N360Space.card),
            PendingEntryTile(entry: entries[index]),
          ],
        ),
      ),
    );
  }
}

/// One pending item. Tapping it marks it resolved, once it is on the server.
class PendingEntryTile extends StatelessWidget {
  const PendingEntryTile({super.key, required this.entry});

  final PendingEntry entry;

  Future<void> _open(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final int? entryId = entry.id;
    if (entryId == null) {
      showPaperToast(context, l.progressResolveWait, kind: PaperToastKind.info);
      return;
    }
    final String? remark = await showLedgerSheet<String>(
      context,
      isScrollControlled: true,
      builder: (context) => const _ResolveSheet(),
    );
    if (remark == null || !context.mounted) return;
    final AppServices services = context.read<AppServices>();
    final int? projectId = context.read<AppGate>().state.currentProject?.id;
    if (projectId == null) return;
    await services.progress.resolvePending(
      projectId: projectId,
      entryId: entryId,
      remark: remark.trim().isEmpty ? null : remark.trim(),
      title: _title,
    );
    if (context.mounted) showPaperToast(context, l.progressResolved, kind: PaperToastKind.success);
  }

  String get _title => '${entry.itemCode ?? ''} ${entry.itemDescription ?? ''}'.trim();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canResolve = context.select((AppGate gate) => gate.state.can('progress.create'));
    final String subtitle = <String>[?entry.floorName, ?entry.remark].join(' · ');
    return LedgerListTile(
      title: _title.isEmpty ? l.progressTabPending : _title,
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: entry.notSentYet ? StatusPill(kind: StampKind.queued, label: l.progressNotSent) : null,
      onTap: canResolve ? () => _open(context) : null,
    );
  }
}

class _ResolveSheet extends StatefulWidget {
  const _ResolveSheet();

  @override
  State<_ResolveSheet> createState() => _ResolveSheetState();
}

class _ResolveSheetState extends State<_ResolveSheet> {
  final TextEditingController _remark = TextEditingController();

  @override
  void dispose() {
    _remark.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.sm,
        N360Space.gutter,
        N360Space.lg + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.progressResolveTitle, style: N360Type.fit(N360Type.heading, l.progressResolveTitle)),
          const SizedBox(height: N360Space.md),
          GloveTextField(label: l.progressResolveRemark, controller: _remark, maxLines: 3),
          const SizedBox(height: N360Space.md),
          GloveButton(
            key: const ValueKey<String>('progress-resolve-save'),
            label: l.progressResolveSave,
            icon: Icons.task_alt,
            fullWidth: true,
            onPressed: () => Navigator.of(context).pop(_remark.text),
          ),
        ],
      ),
    );
  }
}
