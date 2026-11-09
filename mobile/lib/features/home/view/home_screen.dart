import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/offline_slot.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/brand_mark.dart';
import '../../../app/widgets/labelled_tile.dart';
import '../../../app/widgets/press_row.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../core/session/current_user.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../projects/data/project_repository.dart';
import '../../sync_center/data/sync_repository.dart';
import '../bloc/home_cubit.dart';
import '../data/home_repository.dart';

/// The site engineer's day at a glance.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final ProjectRow? project = context.select((AppGate gate) => gate.state.currentProject);
    if (project == null) return const SizedBox.shrink();
    return BlocProvider<HomeCubit>(
      key: ValueKey<int>(project.id),
      create: (context) => HomeCubit(services: context.read<AppServices>(), projectId: project.id),
      child: _HomeView(project: project),
    );
  }
}

class _HomeView extends StatelessWidget {
  const _HomeView({required this.project});

  final ProjectRow project;

  @override
  Widget build(BuildContext context) {
    final double scale = MediaQuery.textScalerOf(context).scale(1);

    return Material(
      type: MaterialType.transparency,
      child: LayoutBuilder(
        builder: (context, constraints) {
          return BlocBuilder<HomeCubit, HomeState>(
            builder: (context, state) {
              final List<_TileSpec> tiles = _tilesFor(context, state);
              // Pinned tiles stay in the thumb zone. If they would crush the
              // progress card, everything scrolls together instead.
              final int rows = (tiles.length / 2).ceil();
              final double panel = rows * (StatTile.minHeight + N360Space.sm + 6) * scale + N360Space.sm;
              final bool pinGrid = constraints.maxHeight - 88 * scale - panel >= 236;
              final Widget grid = _ActionGrid(tiles: tiles);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  _HomeHeader(project: project),
                  Expanded(
                    child: Stack(
                      children: <Widget>[
                        Positioned.fill(
                          child: _HomeList(project: project, state: state, trailing: pinGrid ? null : grid),
                        ),
                        if (pinGrid) const Positioned(left: 0, right: 0, bottom: 0, child: _ScrollFade()),
                      ],
                    ),
                  ),
                  if (pinGrid)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.xs),
                      child: grid,
                    ),
                ],
              );
            },
          );
        },
      ),
    );
  }

  ({IconData icon, String word}) _report(AppLocalizations t, DprDayState state) {
    return switch (state) {
      DprDayState.notStarted => (icon: Icons.assignment, word: t.shellReportNotStarted),
      DprDayState.draft => (icon: Icons.edit_note, word: t.statusDraft),
      DprDayState.queued => (icon: Icons.schedule, word: t.statusQueued),
      DprDayState.saved => (icon: Icons.cloud_done, word: t.shellReportSaved),
      DprDayState.inReview => (icon: Icons.hourglass_top, word: t.statusInReview),
      DprDayState.approved => (icon: Icons.task_alt, word: t.statusApproved),
      DprDayState.returned => (icon: Icons.undo, word: t.statusReturned),
      DprDayState.rejected => (icon: Icons.block, word: t.statusRejected),
      DprDayState.attention => (icon: Icons.error_outline, word: t.shellReportAttention),
    };
  }

  void _openReport(BuildContext context, HomeSummary? data, bool canWrite) {
    final String? uuid = data?.dprClientUuid;
    switch (data?.dprState) {
      case DprDayState.notStarted when canWrite:
        context.push(Routes.dprForm);
      case DprDayState.draft || DprDayState.returned when uuid != null && canWrite:
        context.push(Uri(path: Routes.dprForm, queryParameters: <String, String>{'uuid': uuid}).toString());
      case _ when uuid != null:
        context.push(Routes.dprDetail(uuid));
      case _:
        context.push(Routes.dprList);
    }
  }

  List<_TileSpec> _tilesFor(BuildContext context, HomeState state) {
    final t = AppLocalizations.of(context);
    final CurrentUser? user = context.select((AppGate gate) => gate.state.user);
    bool can(String permission) => user?.can(permission) ?? false;
    final bool canApprove = can('measurement.approve') || can('dpr.approve') || can('ra_bill.approve');
    final HomeSummary? data = state.summary;
    final report = _report(t, data?.dprState ?? DprDayState.notStarted);
    final int marked = data?.attendanceMarked ?? 0;
    final int total = data?.attendanceTotal ?? 0;

    return <_TileSpec>[
      if (can('dpr.view'))
        _TileSpec(
          title: t.shellTileDaily,
          value: report.word,
          caption: t.shellCaptionToday,
          icon: report.icon,
          // The one thing still owed today gets the gold.
          emphasized: data?.dprState == DprDayState.notStarted && can('dpr.create'),
          onTap: () => _openReport(context, data, can('dpr.create')),
        ),
      if (can('attendance.view'))
        _TileSpec(
          title: t.shellTileAttendance,
          value: total > 0 ? '$marked/$total' : '$marked',
          caption: t.shellCaptionMarked,
          icon: Icons.groups,
          onTap: () => context.push(Routes.attendance),
        ),
      if (can('measurement.view'))
        _TileSpec(
          title: t.shellTileMeasure,
          value: '${data?.measurementsToday ?? 0}',
          caption: t.shellCaptionToday,
          icon: Icons.architecture,
          onTap: () => context.go(Routes.measurements),
        ),
      if (can('petty_cash.view'))
        _TileSpec(
          title: t.shellTileExpenses,
          value: data == null ? '₹0' : AppFormat.rupees(data.expensesToday),
          caption: t.shellCaptionToday,
          icon: Icons.account_balance_wallet,
          onTap: () => context.push(Routes.pettyCash),
        ),
      if (can('issue.view'))
        _TileSpec(
          title: t.shellTileIssues,
          value: '${state.openIssues}',
          caption: t.shellCaptionOpen,
          icon: Icons.report_problem_outlined,
          onTap: () => context.push(Routes.issues),
        ),
      if (canApprove)
        _TileSpec(
          title: t.shellTileApprovals,
          value: '${state.approvalsCount}',
          caption: t.shellPillWaiting,
          icon: Icons.approval_outlined,
          onTap: () => context.go(Routes.approvals),
        ),
    ];
  }
}

/// Softens the cut where the list scrolls under the pinned tiles. Painted once.
class _ScrollFade extends StatelessWidget {
  const _ScrollFade();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: SizedBox(
        height: N360Space.lg,
        child: DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: <Color>[N360Colors.paper.withValues(alpha: 0), N360Colors.paper],
            ),
          ),
        ),
      ),
    );
  }
}

class _HomeHeader extends StatelessWidget {
  const _HomeHeader({required this.project});

  final ProjectRow project;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    // An owner with one project still needs the picker, to add a second.
    final bool canSwitch = context.select((AppGate gate) => !gate.state.onlyOneProject || gate.state.canManageProjects);
    final String? location = project.location?.trim().isEmpty ?? true ? null : project.location;
    final String detail = <String>[project.code, ?location].join(' · ');

    final Widget name = Row(
      children: <Widget>[
        const ExcludeSemantics(child: BrandMark(size: 44)),
        const SizedBox(width: N360Space.sm + 2),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                project.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.heading.copyWith(height: 1.3),
              ),
              // The chevron sits on the smaller line, so the name keeps its width.
              Row(
                children: <Widget>[
                  Flexible(
                    child: Text(detail, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption.copyWith(height: 1.3)),
                  ),
                  if (canSwitch) const Icon(Icons.expand_more, size: 20, color: N360Colors.ink2),
                ],
              ),
            ],
          ),
        ),
      ],
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.sm, N360Space.xs),
      child: Row(
        children: <Widget>[
          Expanded(
            child: canSwitch
                ? Semantics(
                    container: true,
                    button: true,
                    label: '${project.name}. ${t.shellSwitchProject}',
                    onTap: () => context.push(Routes.projectsSwitch),
                    child: ExcludeSemantics(
                      child: PressRow(onTap: () => context.push(Routes.projectsSwitch), child: name),
                    ),
                  )
                : name,
          ),
          const _SyncTile(),
          LabelledTile(
            icon: Icons.settings_outlined,
            label: t.shellSettings,
            onTap: () => context.push(Routes.settings),
          ),
        ],
      ),
    );
  }
}

/// Sync state as a tile: a picture, a count and a word, one tap from the outbox.
class _SyncTile extends StatelessWidget {
  const _SyncTile();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return BlocBuilder<SyncStatusCubit, SyncStatus>(
      builder: (context, status) {
        final (IconData icon, int? count, String label, Color ink) = switch (status) {
          SyncStatus(attentionCount: > 0) => (
              Icons.error_outline,
              status.attentionCount,
              t.shellPillAttention,
              N360Colors.stampRed,
            ),
          SyncStatus(isSyncing: true) => (Icons.sync, status.pendingCount, t.shellPillSending, N360Colors.ink2),
          SyncStatus(pendingCount: > 0) => (Icons.schedule, status.pendingCount, t.shellPillWaiting, N360Colors.ink2),
          SyncStatus(online: false) => (Icons.cloud_off, null, t.shellOfflineLabel, N360Colors.ink2),
          _ => (Icons.cloud_done_outlined, null, t.shellPillAllSent, N360Colors.mossInk),
        };
        return LabelledTile(
          icon: icon,
          label: label,
          semanticLabel: count == null ? label : '$count $label',
          badge: count == null || count == 0 ? null : '$count',
          highlight: count != null && count > 0,
          labelColor: ink,
          onTap: () => context.go(Routes.outbox),
        );
      },
    );
  }
}

/// Everything above the tile grid. Returned work always comes first.
class _HomeList extends StatelessWidget {
  const _HomeList({required this.project, required this.state, this.trailing});

  final ProjectRow project;
  final HomeState state;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final List<Widget> children = <Widget>[];
    var rise = 0;

    for (final item in state.returned) {
      children.add(
        Padding(
          padding: const EdgeInsets.only(bottom: N360Space.sm),
          child: StaggeredRise(index: rise++, child: _ReturnedSlip(item: item)),
        ),
      );
    }

    final List<_SetupStep> steps = _SetupStep.visibleFor(context, state.setup);
    final bool setupPending = steps.any((step) => !step.done);
    if (setupPending) {
      children.add(
        Padding(
          padding: const EdgeInsets.only(bottom: N360Space.sm),
          child: StaggeredRise(index: rise++, child: _SetupCard(steps: steps)),
        ),
      );
    }

    // With the BOQ still to add, the setup card already says so, and says what
    // to do about it. The empty progress card would only repeat it.
    final bool boqStepOpen = steps.any((step) => step.isBoq && !step.done);
    if (state.progress != null || !boqStepOpen) {
      children.add(StaggeredRise(index: rise++, child: _ProgressCard(project: project, progress: state.progress)));
    }

    if (trailing != null) {
      children.add(Padding(padding: const EdgeInsets.only(top: N360Space.md), child: trailing));
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
      children: children,
    );
  }
}

/// One thing a new site still needs.
class _SetupStep {
  const _SetupStep({
    required this.icon,
    required this.word,
    required this.count,
    required this.route,
    this.isBoq = false,
  });

  final IconData icon;
  final String word;
  final int count;
  final String route;
  final bool isBoq;

  bool get done => count > 0;

  /// The steps this user is allowed to complete.
  static List<_SetupStep> visibleFor(BuildContext context, ProjectSetup? setup) {
    if (setup == null) return const <_SetupStep>[];
    final t = AppLocalizations.of(context);
    final CurrentUser? user = context.select((AppGate gate) => gate.state.user);
    bool can(String permission) => user?.can(permission) ?? false;
    return <_SetupStep>[
      if (can('boq.manage'))
        _SetupStep(
          icon: Icons.format_list_numbered,
          word: t.shellSetupBoq,
          count: setup.boqCount,
          route: Routes.boq,
          isBoq: true,
        ),
      if (can('worker.manage'))
        _SetupStep(icon: Icons.groups, word: t.shellSetupWorkers, count: setup.workerCount, route: Routes.attendance),
      if (can('petty_cash.manage'))
        _SetupStep(
          icon: Icons.account_balance_wallet,
          word: t.shellSetupWallet,
          count: setup.walletCount,
          route: Routes.pettyCash,
        ),
    ];
  }
}

/// What a new site still needs, in the order to do it. The next step is gold,
/// so a brand-new owner can see where to tap without reading the rest.
class _SetupCard extends StatelessWidget {
  const _SetupCard({required this.steps});

  final List<_SetupStep> steps;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final int done = steps.where((step) => step.done).length;
    final int next = steps.indexWhere((step) => !step.done);
    return LedgerCard(
      padding: const EdgeInsets.all(N360Space.md),
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
                    Text(t.shellSetupTitle, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.heading.copyWith(height: 1.3)),
                    Text(
                      t.shellSetupNext(steps[next].word),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.caption.copyWith(color: N360Colors.laterite, fontWeight: FontWeight.w700, height: 1.3),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.sm),
              SquarePercent(fraction: done / steps.length, label: '$done/${steps.length}', size: 52),
            ],
          ),
          const SizedBox(height: N360Space.sm),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                for (final (i, step) in steps.indexed) ...<Widget>[
                  if (i > 0) const SizedBox(width: N360Space.sm - 2),
                  Expanded(child: _StepTile(step: step, isNext: i == next)),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _StepTile extends StatelessWidget {
  const _StepTile({required this.step, required this.isNext});

  final _SetupStep step;
  final bool isNext;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    void open() => context.push(step.route);
    final BoxDecoration surface = isNext
        ? const BoxDecoration(gradient: N360Gradients.gold, borderRadius: N360Radius.md)
        : const BoxDecoration(color: N360Colors.paper2, borderRadius: N360Radius.md);

    return Semantics(
      container: true,
      button: true,
      label: '${t.shellSetupStepSemantics(step.word, step.count)}${step.done ? ', ${t.commonDone}' : ''}',
      onTap: open,
      child: ExcludeSemantics(
        child: PressRow(
          onTap: open,
          minHeight: 76,
          child: DecoratedBox(
            decoration: surface,
            child: Padding(
              padding: const EdgeInsets.all(N360Space.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Icon(step.icon, size: 22, color: N360Colors.ink),
                      const SizedBox(width: N360Space.xs),
                      Text('${step.count}', style: N360Type.bodyStrong.copyWith(height: 1.2)),
                      const Spacer(),
                      if (step.done) const Icon(Icons.check_circle, size: 20, color: N360Colors.mossInk),
                    ],
                  ),
                  const SizedBox(height: N360Space.xxs),
                  Text(
                    step.word,
                    maxLines: 2,
                    style: N360Type.caption.copyWith(color: N360Colors.ink, fontWeight: FontWeight.w600, height: 1.25),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The showpiece: how far the building has come.
class _ProgressCard extends StatelessWidget {
  const _ProgressCard({required this.project, required this.progress});

  final ProjectRow project;
  final HomeProgress? progress;

  static const double _artWidth = 124;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final String today = context.read<AppServices>().context.today;
    final HomeProgress? data = progress;

    // The slot has a fixed height, so these two lines stop growing at 1.1x.
    final Widget title = MediaQuery.withClampedTextScaling(
      maxScaleFactor: 1.1,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.sm + 2, _artWidth - N360Space.xxl, 0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(t.shellProgressTitle, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.heading.copyWith(height: 1.3)),
            if (data != null)
              Text(t.shellProgressBasis, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption.copyWith(height: 1.3)),
          ],
        ),
      ),
    );

    final Widget art = ExcludeSemantics(
      child: BuildingProgressArt(fraction: data?.fraction ?? 0, animateEntry: data != null),
    );

    return LedgerCard(
      padding: EdgeInsets.zero,
      child: Stack(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.lg, OfflineSlot.height, N360Space.lg, N360Space.md + 2),
            child: data == null
                ? _ProgressEmpty(artWidth: _artWidth)
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 296 - _artWidth - N360Space.xs),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: FittedBox(
                            fit: BoxFit.scaleDown,
                            alignment: Alignment.centerLeft,
                            child: RollingNumber(
                              value: data.percent,
                              suffix: '%',
                              style: N360Type.figureHero.copyWith(fontSize: 40),
                              semanticsLabel: t.shellProgressSemantics(data.percent),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: N360Space.xxs),
                      _DatesRow(endDate: project.endDate, today: today, locale: locale),
                      const SizedBox(height: N360Space.sm),
                      HatchedProgressBar(
                        fraction: data.fraction,
                        markerLabel: t.commonToday,
                        height: 30,
                        semanticLabel: t.shellProgressSemantics(data.percent),
                      ),
                    ],
                  ),
          ),
          Positioned(top: 42, right: N360Space.sm, width: _artWidth, child: art),
          Positioned(top: 0, left: 0, right: 0, child: OfflineSlot(online: title)),
        ],
      ),
    );
  }
}

class _ProgressEmpty extends StatelessWidget {
  const _ProgressEmpty({required this.artWidth});

  final double artWidth;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Padding(
      padding: EdgeInsets.only(right: artWidth - N360Space.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(t.shellProgressEmptyTitle, style: N360Type.bodyStrong),
          const SizedBox(height: N360Space.xxs),
          Text(t.shellProgressEmptyBody, style: N360Type.body.copyWith(color: N360Colors.ink2)),
          const SizedBox(height: N360Space.xl),
        ],
      ),
    );
  }
}

class _DatesRow extends StatelessWidget {
  const _DatesRow({required this.endDate, required this.today, required this.locale});

  final String? endDate;
  final String today;
  final String locale;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String? end = endDate?.trim().isEmpty ?? true ? null : endDate;
    final DateTime? endDay = end == null ? null : DateTime.tryParse(end);
    final int? daysLeft = endDay?.difference(DateTime.parse(today)).inDays;
    final bool late = daysLeft != null && daysLeft < 0;
    return Padding(
      padding: const EdgeInsets.only(right: _ProgressCard._artWidth - N360Space.lg),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Expanded(
            flex: 3,
            child: _Fact(
              label: t.shellProgressEnd,
              value: end == null ? t.shellProgressNoEnd : AppFormat.dayMonthYear(end, locale),
            ),
          ),
          if (daysLeft != null) ...<Widget>[
            const SizedBox(width: N360Space.sm),
            Expanded(
              flex: 2,
              child: _Fact(
                label: late ? t.shellProgressDaysLate : t.shellProgressDaysLeft,
                value: '${daysLeft.abs()}',
                alert: late,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value, this.alert = false});

  final String label;
  final String value;
  final bool alert;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption.copyWith(height: 1.25)),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            value,
            maxLines: 1,
            style: N360Type.bodyStrong.copyWith(color: alert ? N360Colors.stampRed : N360Colors.ink),
          ),
        ),
      ],
    );
  }
}

/// A document sent back for correction, kept to one compact row: the stamp,
/// what it is, what the checker said, and one tap into fixing it.
class _ReturnedSlip extends StatelessWidget {
  const _ReturnedSlip({required this.item});

  final ReturnedItem item;

  void _fix(BuildContext context) {
    final String path = item.kind == ReturnedKind.dpr ? Routes.dprForm : Routes.measurementForm;
    context.push(Uri(path: path, queryParameters: <String, String>{'uuid': item.uuid}).toString());
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final String date = AppFormat.dayMonth(item.date, locale);
    final String title = item.kind == ReturnedKind.dpr
        ? t.shellReturnedDpr(date)
        : t.shellReturnedMeasurement(item.code ?? '', item.qty ?? '', item.unit ?? '').trim();
    final String remark = (item.remark?.trim().isNotEmpty ?? false) ? item.remark!.trim() : t.shellReturnedNoRemark;

    return LedgerCard(
      flat: true,
      onTap: () => _fix(context),
      semanticsLabel: '${t.shellReturnedTitle}. $title. $remark. ${t.shellReturnedFix}',
      padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.xs, N360Space.xs + 2, N360Space.sm),
      child: Row(
        children: <Widget>[
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Row(
                  children: <Widget>[
                    RubberStamp(kind: StampKind.returned, label: t.statusReturned, size: StampSize.small),
                    const SizedBox(width: N360Space.xs),
                    Expanded(
                      child: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.label.copyWith(color: N360Colors.ink)),
                    ),
                  ],
                ),
                Text(remark, maxLines: 2, overflow: TextOverflow.ellipsis, style: N360Type.caption.copyWith(height: 1.3, color: N360Colors.ink2)),
              ],
            ),
          ),
          const SizedBox(width: N360Space.sm),
          SizedBox(
            width: 60,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                const IconTile(icon: Icons.edit_outlined, semanticLabel: '', highlight: true),
                const SizedBox(height: 1),
                Text(
                  t.shellReturnedFix,
                  maxLines: 1,
                  overflow: TextOverflow.fade,
                  softWrap: false,
                  style: N360Type.caption.copyWith(color: N360Colors.laterite, fontWeight: FontWeight.w700, height: 1.2, fontSize: 13),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TileSpec {
  const _TileSpec({
    required this.title,
    required this.value,
    required this.caption,
    required this.icon,
    required this.onTap,
    this.emphasized = false,
  });

  final String title;
  final String value;
  final String caption;
  final IconData icon;
  final VoidCallback onTap;
  final bool emphasized;
}

/// Today's work and the main actions, two across, in the thumb zone.
class _ActionGrid extends StatelessWidget {
  const _ActionGrid({required this.tiles});

  final List<_TileSpec> tiles;

  @override
  Widget build(BuildContext context) {
    const double gap = N360Space.sm;
    final List<Widget> rows = <Widget>[];
    for (var start = 0; start < tiles.length; start += 2) {
      final List<_TileSpec> row = tiles.sublist(start, (start + 2).clamp(0, tiles.length));
      rows.add(
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              for (final (i, spec) in row.indexed) ...<Widget>[
                if (i > 0) const SizedBox(width: gap),
                Expanded(
                  child: StaggeredRise(
                    index: 2 + start + i,
                    child: StatTile(
                      title: spec.title,
                      value: spec.value,
                      caption: spec.caption,
                      icon: spec.icon,
                      emphasized: spec.emphasized,
                      onTap: spec.onTap,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        for (final (i, row) in rows.indexed) ...<Widget>[
          if (i > 0) const SizedBox(height: gap),
          row,
        ],
      ],
    );
  }
}
