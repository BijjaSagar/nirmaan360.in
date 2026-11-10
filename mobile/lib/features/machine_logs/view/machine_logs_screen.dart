import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../../sync_center/data/sync_repository.dart' show OutboxItem;
import '../../sync_center/view/sync_labels.dart';
import '../bloc/machine_logs_cubit.dart';
import '../data/machine_repository.dart';
import 'machine_words.dart';

/// What each machine worked, day by day, and a quick way to add a day. Hours and
/// km only: this screen never shows a rate or a cost.
class MachineLogsScreen extends StatelessWidget {
  const MachineLogsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MachineLogsCubit>(
      create: (context) => MachineLogsCubit(context.read<AppServices>()),
      child: const _LogsView(),
    );
  }
}

class _LogsView extends StatelessWidget {
  const _LogsView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canLog = context.select((AppGate gate) => gate.state.canLogMachines);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<MachineLogsCubit, MachineLogsState>(
            builder: (context, state) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.machinesTitle, subtitle: state.project?.code),
                  const Expanded(child: _LogsScroll()),
                  if (canLog && state.canLog)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('machines-add'),
                        label: l.machinesAdd,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => context.push(Routes.machineLogForm),
                      ),
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

class _LogsScroll extends StatelessWidget {
  const _LogsScroll();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<MachineLogsCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.machinesLoading,
      child: BlocBuilder<MachineLogsCubit, MachineLogsState>(
        builder: (context, state) => CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: _slivers(context, l, state),
        ),
      ),
    );
  }

  List<Widget> _slivers(BuildContext context, AppLocalizations l, MachineLogsState state) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
    Widget skeleton() => SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.machinesLoading),
      ),
    );

    if (state.load == MachineLogsLoad.loading) return <Widget>[skeleton()];
    if (state.load == MachineLogsLoad.noProject) {
      return <Widget>[empty(l.progressNoProjectTitle, l.progressNoProjectMessage)];
    }
    final MachineOverview? overview = state.overview;
    if (overview == null) {
      return <Widget>[if (state.refreshing) skeleton() else empty(l.progressNoDataTitle, l.machinesNoDataMessage)];
    }
    if (overview.refused) return <Widget>[empty(l.machinesRefusedTitle, l.machinesRefusedMessage)];
    if (overview.machines.isEmpty) return <Widget>[empty(l.machinesNoMachinesTitle, l.machinesNoMachinesMessage)];
    if (overview.logs.isEmpty) return <Widget>[empty(l.machinesEmptyTitle, l.machinesEmptyMessage)];

    return <Widget>[
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.md)),
      FieldGroupSliver(
        padding: EdgeInsets.zero,
        sliver: SliverList.builder(
          itemCount: overview.logs.length,
          itemBuilder: (context, index) => Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              if (index > 0) const RuledDivider(indent: N360Space.card),
              _LogTile(log: overview.logs[index], overview: overview),
            ],
          ),
        ),
      ),
      if (overview.more)
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
            child: Text(l.machinesLatestOnly, style: N360Type.fit(N360Type.caption, l.machinesLatestOnly)),
          ),
        ),
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }
}

/// One day of one machine. A log still waiting on this phone says so, and one
/// the server refused says why in its own words.
class _LogTile extends StatelessWidget {
  const _LogTile({required this.log, required this.overview});

  final MachineLog log;
  final MachineOverview overview;

  String? _refusal(AppLocalizations l) {
    final OutboxRow? outbox = log.unsent;
    if (outbox == null || !log.needsAttention) return null;
    if (outbox.attentionCode == AttentionCode.validation && (outbox.attentionReason?.isNotEmpty ?? false)) {
      return outbox.attentionReason;
    }
    return l.syncReasonFor(OutboxItem(row: outbox, isBlocked: false));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final Machine? machine = overview.machine(log.assetId);
    final String title = l.machineLogTitle(machine, log.assetId);
    final String figures = l.machineLogFigures(log).join(' · ');
    final String detail = <String>[
      if (log.logDate.isNotEmpty) dayLabel(context, log.logDate, today),
      if (figures.isNotEmpty) figures,
      if (log.operatorName != null) l.machinesOperatorValue(log.operatorName!),
    ].join(' · ');
    final String? refusal = _refusal(l);
    final Widget? status = log.notSent
        ? StatusPill(
            kind: log.needsAttention ? StampKind.returned : StampKind.queued,
            label: log.needsAttention ? l.shellPillAttention : l.progressNotSent,
          )
        : null;
    final Widget body = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: Text(title, style: N360Type.fit(N360Type.bodyStrong, title))),
              if (log.needsAttention) ...<Widget>[
                const SizedBox(width: N360Space.xs),
                const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink2),
              ],
            ],
          ),
          if (detail.isNotEmpty) Text(detail, style: N360Type.fit(N360Type.caption, detail)),
          if (status != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Align(alignment: Alignment.centerLeft, child: status),
          ],
          if (refusal != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(refusal, style: N360Type.fit(N360Type.body, refusal).copyWith(color: N360Colors.laterite)),
          ],
        ],
      ),
    );
    final Widget row = ConstrainedBox(constraints: const BoxConstraints(minHeight: N360Size.tap), child: body);
    final String spoken = <String>[title, detail, if (log.notSent) log.needsAttention ? l.shellPillAttention : l.progressNotSent, ?refusal]
        .where((String part) => part.isNotEmpty)
        .join('. ');
    // Only a log the server refused leads anywhere: to the sending list, where it can be tried again or dropped.
    if (!log.needsAttention) {
      return Semantics(
        container: true,
        label: spoken,
        child: ExcludeSemantics(child: KeyedSubtree(key: ValueKey<String>('machine-log-${log.key}'), child: row)),
      );
    }
    return PressTarget(
      key: ValueKey<String>('machine-log-${log.key}'),
      onTap: () => context.go(Routes.outbox),
      haptic: N360Haptics.select,
      semanticsLabel: spoken,
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: row,
    );
  }
}
