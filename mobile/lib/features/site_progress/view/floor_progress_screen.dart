import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/bloc/measurement_book.dart';
import '../bloc/progress_cubit.dart';
import '../data/progress_repository.dart';
import 'progress_screen.dart';
import 'widgets/entry_widgets.dart';
import 'widgets/progress_widgets.dart';

/// One floor: its flats, its stages, and how much of each item is done.
class FloorProgressScreen extends StatelessWidget {
  const FloorProgressScreen({super.key, required this.floorId});

  final int floorId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProgressCubit>(
      create: (context) => ProgressCubit(context.read<AppServices>()),
      child: _FloorView(floorId: floorId),
    );
  }
}

class _FloorView extends StatefulWidget {
  const _FloorView({required this.floorId});

  final int floorId;

  @override
  State<_FloorView> createState() => _FloorViewState();
}

class _FloorViewState extends State<_FloorView> {
  FloorDetail? _detail;
  bool _loading = true;
  RecentEntries? _recent;
  StreamSubscription<RecentEntries>? _recentSub;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
    _watchEntries();
  }

  @override
  void dispose() {
    unawaited(_recentSub?.cancel());
    super.dispose();
  }

  /// The floor's latest entries: what this phone kept, read again from the
  /// server when it can be reached.
  void _watchEntries() {
    final AppServices services = context.read<AppServices>();
    final int? projectId = context.read<AppGate>().state.currentProject?.id;
    if (projectId == null) return;
    _recentSub = services.progress.watchFloorEntries(projectId, widget.floorId).listen((recent) {
      if (mounted) setState(() => _recent = recent);
    });
    unawaited(
      services.progress.refreshFloorEntries(projectId, widget.floorId).then<void>((_) {}, onError: (Object error) {
        // Offline, or not allowed: the list the phone has stands.
        if (error is! ApiFailure) throw error;
      }),
    );
  }

  Future<void> _correct(FloorEntry entry, ProgressOverview? overview, FloorChoice? choice) async {
    final AppServices services = context.read<AppServices>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? projectId = context.read<AppGate>().state.currentProject?.id;
    if (projectId == null) return;
    final ProgressItem? item = overview?.item(entry.boqItemId);
    final CorrectionAnswer? answer = await showCorrectionSheet(context, entry: entry, unit: item?.unit ?? '');
    if (answer == null || !mounted) return;
    try {
      await services.progress.correctEntry(
        projectId: projectId,
        entryId: entry.id,
        boqItemId: entry.boqItemId,
        serverQty: entry.net,
        rightQty: answer.qty,
        remark: answer.remark,
        title: item == null ? '' : '${item.code} ${item.description}'.trim(),
        subtitle: choice?.label,
      );
      if (!mounted) return;
      N360Haptics.success();
      showPaperToast(
        context,
        answer.qty == entry.net
            ? l.progressCorrectTakenBack
            : (services.connectivity.isOnline ? l.progressCorrectSavedOnline : l.progressCorrectSavedOffline),
        kind: PaperToastKind.success,
      );
    } on Object {
      if (!mounted) return;
      N360Haptics.warning();
      showPaperToast(context, l.progressCorrectFailed, kind: PaperToastKind.error);
    }
  }

  Future<void> _load() async {
    final AppServices services = context.read<AppServices>();
    final int? projectId = context.read<AppGate>().state.currentProject?.id;
    FloorDetail? detail;
    if (projectId != null) detail = await services.progress.floorDetail(projectId, widget.floorId);
    if (!mounted) return;
    setState(() {
      _detail = detail;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canAdd = context.select((AppGate gate) => gate.state.can('progress.create'));
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<ProgressCubit, ProgressState>(
            builder: (context, state) {
              final ProgressOverview? overview = state.overview;
              final FloorChoice? choice = overview?.floorChoice(widget.floorId);
              final String title = choice?.floor.name ?? _detail?.floor.name ?? l.progressFloorTitle;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: title, subtitle: choice?.parentName ?? l.progressFloorTitle),
                  Expanded(child: _body(context, l, overview, choice, canAdd)),
                  if (canAdd)
                    FieldActionBar(
                      child: GloveButton(
                        label: l.progressAdd,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => context.push('${Routes.progressForm}?floor=${widget.floorId}'),
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

  Widget _body(BuildContext context, AppLocalizations l, ProgressOverview? overview, FloorChoice? choice, bool canAdd) {
    final FloorDetail? detail = _detail;
    final ProgressFloor? floor = choice?.floor ?? detail?.floor;
    final StageMatrix? matrix = overview?.matrix;
    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        if (floor != null) ...<Widget>[
          LedgerCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(l.progressWorkDone, style: N360Type.fit(N360Type.label, l.progressWorkDone)),
                Text(percentText(floor.percent), style: N360Type.figureLarge),
                HatchedProgressBar(
                  fraction: percentFraction(floor.percent),
                  semanticLabel: '${l.progressWorkDone} ${percentText(floor.percent)}',
                ),
              ],
            ),
          ),
          const SizedBox(height: N360Space.md),
        ],
        if (floor != null && floor.children.isNotEmpty) ...<Widget>[
          SectionLabel(l.progressFloorFlats),
          const SizedBox(height: N360Space.xs),
          Wrap(
            spacing: N360Space.sm,
            children: <Widget>[
              for (final ProgressFloor flat in floor.children)
                IntrinsicWidth(
                  child: N360Chip(
                    label: '${flat.name} · ${percentText(flat.percent)}',
                    selected: false,
                    onTap: () => context.push(Routes.progressFloor(flat.id)),
                  ),
                ),
            ],
          ),
          const SizedBox(height: N360Space.md),
        ],
        if (matrix != null && matrix.row(widget.floorId) != null) ...<Widget>[
          SectionLabel(l.progressFloorStages),
          const SizedBox(height: N360Space.xs),
          LedgerCard(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                for (final StageTrade trade in matrix.trades) ...<Widget>[
                  LedgerListTile(
                    title: trade.label,
                    subtitle: () {
                      final ({int done, int total}) tally = matrix.tally(matrix.row(widget.floorId)!, trade);
                      return l.progressStagesDone(tally.done, tally.total);
                    }(),
                    onTap: () => context.push(Routes.progressStage(widget.floorId, trade.key)),
                  ),
                  const RuledDivider(indent: N360Space.card),
                ],
                LedgerListTile(
                  key: const ValueKey<String>('progress-before-finishing'),
                  leading: const Icon(Icons.checklist, size: N360IconSize.lg, color: N360Colors.ink2),
                  title: l.progressBeforeFinishing,
                  subtitle: l.progressFinishingHint,
                  onTap: () => context.push(Routes.progressBeforeFinishing(widget.floorId)),
                ),
              ],
            ),
          ),
          const SizedBox(height: N360Space.md),
        ],
        if (_loading)
          LedgerSkeletonList(lines: 2, semanticsLabel: l.progressLoading)
        else if (detail == null)
          EmptyLedger(title: l.progressNoDataTitle, message: l.progressFloorOffline)
        else ...<Widget>[
          SectionLabel(l.progressFloorLines),
          const SizedBox(height: N360Space.xs),
          if (detail.lines.isEmpty)
            LedgerCard(child: Text(l.progressFloorNoLines, style: N360Type.body))
          else
            LedgerCard(
              padding: EdgeInsets.zero,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  for (final (index, line) in detail.lines.indexed) ...<Widget>[
                    if (index > 0) const RuledDivider(indent: N360Space.card),
                    ProgressBarTile(
                      title: '${line.code} · ${line.description}',
                      percent: line.percent,
                      caption:
                          '${l.progressFloorDone} ${bookFigure(line.done)} / ${l.progressFloorPlanned} ${bookFigure(line.planned)} ${line.unit}'.trim(),
                    ),
                  ],
                ],
              ),
            ),
          if (detail.pending.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.md),
            SectionLabel(l.progressFloorPendingHeading),
            const SizedBox(height: N360Space.xs),
            LedgerCard(
              padding: EdgeInsets.zero,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  for (final (index, entry) in detail.pending.indexed) ...<Widget>[
                    if (index > 0) const RuledDivider(indent: N360Space.card),
                    PendingEntryTile(entry: entry),
                  ],
                ],
              ),
            ),
          ],
        ],
        if (_recent != null && !_recent!.isEmpty) ...<Widget>[
          const SizedBox(height: N360Space.md),
          SectionLabel(l.progressRecentEntries),
          const SizedBox(height: N360Space.xs),
          LedgerCard(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                for (final (index, entry) in _recent!.entries.indexed) ...<Widget>[
                  if (index > 0) const RuledDivider(indent: N360Space.card),
                  RecentEntryTile(
                    key: ValueKey<int>(entry.id),
                    entry: entry,
                    item: overview?.item(entry.boqItemId),
                    today: context.read<AppServices>().context.today,
                    onCorrect: canAdd ? () => _correct(entry, overview, choice) : null,
                  ),
                ],
              ],
            ),
          ),
        ],
      ],
    );
  }
}

/// What must be finished here before plaster, POP or paint covers it.
class BeforeFinishingScreen extends StatelessWidget {
  const BeforeFinishingScreen({super.key, required this.floorId});

  final int floorId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProgressCubit>(
      create: (context) => ProgressCubit(context.read<AppServices>()),
      child: Builder(
        builder: (context) {
          final AppLocalizations l = AppLocalizations.of(context);
          return Scaffold(
            body: LedgerPaper(
              child: SafeArea(
                top: false,
                bottom: false,
                child: BlocBuilder<ProgressCubit, ProgressState>(
                  builder: (context, state) {
                    final StageMatrix? matrix = state.overview?.matrix;
                    final BeforeFinishing? check = matrix?.beforeFinishing(floorId);
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        FieldTopBar(title: l.progressBeforeFinishing, subtitle: check?.floorName),
                        Expanded(
                          child: matrix == null || check == null
                              ? EmptyLedger(title: l.progressNoDataTitle, message: l.progressNoDataMessage)
                              : _FinishingList(check: check, matrix: matrix),
                        ),
                      ],
                    );
                  },
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _FinishingList extends StatelessWidget {
  const _FinishingList({required this.check, required this.matrix});

  final BeforeFinishing check;
  final StageMatrix matrix;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    if (check.ready) {
      return ListView(
        padding: const EdgeInsets.all(N360Space.gutter),
        children: <Widget>[
          LedgerCard(
            child: Row(
              children: <Widget>[
                const Icon(Icons.verified_outlined, size: N360IconSize.xl, color: N360Colors.moss),
                const SizedBox(width: N360Space.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(l.progressReadyTitle, style: N360Type.fit(N360Type.heading, l.progressReadyTitle)),
                      Text(l.progressReadyMessage, style: N360Type.fit(N360Type.body, l.progressReadyMessage)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      );
    }

    Widget group(String heading, List<FinishingLine> lines) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SectionLabel(heading),
          const SizedBox(height: N360Space.xs),
          LedgerCard(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                for (final (index, line) in lines.indexed) ...<Widget>[
                  if (index > 0) const RuledDivider(indent: N360Space.card),
                  LedgerListTile(
                    title: line.stage.label,
                    subtitle: <String>[line.rowLabel, line.trade.label, ?line.remark].join(' · '),
                    status: StatusPill(kind: stageKind(line.status), label: matrix.statusLabel(line.status)),
                    onTap: () => context.push(Routes.progressStage(line.floorId, line.trade.key)),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: N360Space.md),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        if (check.pending.isNotEmpty) group(l.progressFinishingPending, check.pending),
        if (check.unfinished.isNotEmpty) group(l.progressFinishingUnfinished, check.unfinished),
      ],
    );
  }
}
