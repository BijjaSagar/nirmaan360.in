import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../projects/view/widgets/project_photo.dart';
import '../../site_progress/data/progress_models.dart' show percentText;
import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
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
///
/// Top to bottom: the site and how the sending is going, anything sent back
/// for correction, what a new site still needs, how far the building has come,
/// and today's figures two across. One white page, hairlines, one colour.
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
    return Material(
      type: MaterialType.transparency,
      child: BlocBuilder<HomeCubit, HomeState>(
        builder: (context, state) {
          final List<_SetupStep> steps = _SetupStep.visibleFor(context, state.setup);
          final bool setupPending = steps.any((step) => !step.done);
          // With the BOQ still to add, the setup card already says so, and says
          // what to do about it. The empty progress card would only repeat it.
          final bool boqStepOpen = steps.any((step) => step.isBoq && !step.done);
          final bool seesMoney = context.select((AppGate gate) => gate.state.can('money.view'));
          final bool showProgress = state.progress != null || (!boqStepOpen && seesMoney);
          // The page has one filled surface at most. A returned document or a
          // site still being set up already says what to do next, so the
          // daily report tile stays white until those are dealt with.
          final bool quiet = state.returned.isNotEmpty || setupPending;
          final List<_TileSpec> tiles = _tilesFor(context, state, allowEmphasis: !quiet);

          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              _HomeBar(project: project),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                  child: StaggeredRise(
                    index: 0,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        if (state.returned.isNotEmpty) ...<Widget>[
                          LedgerList(
                            children: <Widget>[for (final item in state.returned) _ReturnedRow(item: item)],
                          ),
                          const SizedBox(height: N360Space.cardGap),
                        ],
                        if (setupPending) ...<Widget>[
                          _SetupCard(steps: steps),
                          const SizedBox(height: N360Space.cardGap),
                        ],
                        if (showProgress) ...<Widget>[
                          _ProgressCard(project: project, progress: state.progress),
                          const SizedBox(height: N360Space.cardGap),
                        ],
                        _TileGrid(tiles: tiles),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  ({IconData icon, String word}) _report(AppLocalizations t, DprDayState state) {
    return switch (state) {
      DprDayState.notStarted => (icon: Icons.assignment_outlined, word: t.shellReportNotStarted),
      DprDayState.draft => (icon: Icons.edit_note, word: t.statusDraft),
      DprDayState.queued => (icon: Icons.schedule, word: t.statusQueued),
      DprDayState.saved => (icon: Icons.cloud_done_outlined, word: t.shellReportSaved),
      DprDayState.inReview => (icon: Icons.hourglass_bottom, word: t.statusInReview),
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

  /// Today's figures, in the order a day on site runs. With [allowEmphasis]
  /// the one thing still owed today may take the colour.
  List<_TileSpec> _tilesFor(BuildContext context, HomeState state, {required bool allowEmphasis}) {
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
          // The one thing still owed today gets the colour, when nothing on
          // the page is more urgent.
          emphasized: allowEmphasis && data?.dprState == DprDayState.notStarted && can('dpr.create'),
          onTap: () => _openReport(context, data, can('dpr.create')),
        ),
      if (can('progress.view') && (user?.features.contains('site_control') ?? false))
        _TileSpec(
          title: t.shellTileProgress,
          value: percentText(state.siteWorkPercent),
          caption: t.shellCaptionWorkDone,
          icon: Icons.stacked_bar_chart,
          onTap: () => context.push(Routes.progress),
        ),
      if (can('material_control.view') && (user?.features.contains('site_control') ?? false))
        _TileSpec(
          title: t.shellTileMaterial,
          value: '${state.materialFlags ?? 0}',
          caption: t.shellCaptionToAnswer,
          icon: Icons.inventory_2_outlined,
          onTap: () => context.push(Routes.material),
        ),
      if (can('attendance.view'))
        _TileSpec(
          title: t.shellTileAttendance,
          value: total > 0 ? '$marked/$total' : '$marked',
          caption: t.shellCaptionMarked,
          icon: Icons.groups_outlined,
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
          icon: Icons.account_balance_wallet_outlined,
          onTap: () => context.push(Routes.pettyCash),
        ),
      if (can('issue.view'))
        _TileSpec(
          title: t.shellTileIssues,
          value: '${state.openIssues}',
          caption: t.shellCaptionOpen,
          icon: Icons.warning_amber_outlined,
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

/// The site is the bar's title. Beside it, how the sending is going, and
/// settings. Tapping the site name opens the picker when there is a choice to
/// make.
class _HomeBar extends StatelessWidget {
  const _HomeBar({required this.project});

  final ProjectRow project;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final N360BarTone tone = N360Chrome.topBar;
    // An owner with one project still needs the picker, to add a second.
    final bool canSwitch =
        context.select((AppGate gate) => !gate.state.onlyOneProject || gate.state.canManageProjects);
    final String? location = project.location?.trim().isEmpty ?? true ? null : project.location;
    final String detail = <String>[project.code, ?location].join(' · ');

    final Widget name = Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        ProjectPhoto(project: project, size: 44),
        const SizedBox(width: N360Space.sm),
        Flexible(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                project.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.fit(N360Type.title, project.name).copyWith(color: tone.ink, height: 1.2),
              ),
              Row(
                children: <Widget>[
                  Flexible(
                    child: Text(
                      detail,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.fit(N360Type.caption, detail).copyWith(color: tone.inkSoft),
                    ),
                  ),
                  if (canSwitch) Icon(Icons.expand_more, size: N360IconSize.sm, color: tone.inkSoft),
                ],
              ),
            ],
          ),
        ),
      ],
    );

    return N360TopBar(
      backLabel: t.commonBack,
      showBack: false,
      centerTitle: false,
      titleWidget: SizedBox(
        width: double.infinity,
        child: canSwitch
            ? Semantics(
                container: true,
                button: true,
                label: '${project.name}. ${t.shellSwitchProject}',
                onTap: () => context.push(Routes.projectsSwitch),
                child: ExcludeSemantics(
                  child: PressRow(
                    onTap: () => context.push(Routes.projectsSwitch),
                    child: Align(alignment: Alignment.centerLeft, child: name),
                  ),
                ),
              )
            : name,
      ),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const _SyncTag(),
          const SizedBox(width: N360Space.xs),
          // The gear carries its word, as every header tile does.
          N360TopTile(
            icon: Icons.settings_outlined,
            label: t.shellSettings,
            onTap: () => context.push(Routes.settings),
          ),
        ],
      ),
    );
  }
}

/// The sync state as a quiet tag: a glyph, a count and a word, one tap from
/// the outbox. Offline is said here too, so the page needs no banner.
class _SyncTag extends StatelessWidget {
  const _SyncTag();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return BlocBuilder<SyncStatusCubit, SyncStatus>(
      builder: (context, status) {
        final (SyncVisualState state, int? count, String label) = switch (status) {
          SyncStatus(attentionCount: > 0) => (SyncVisualState.attention, status.attentionCount, t.shellPillAttention),
          SyncStatus(isSyncing: true) => (SyncVisualState.syncing, status.pendingCount, t.shellPillSending),
          SyncStatus(pendingCount: > 0) => (SyncVisualState.pending, status.pendingCount, t.shellPillWaiting),
          SyncStatus(online: false) => (SyncVisualState.offline, null, t.shellOfflineLabel),
          _ => (SyncVisualState.synced, null, t.shellPillAllSent),
        };
        return SyncPill(
          state: state,
          label: label,
          count: count == null || count == 0 ? null : count,
          onTap: () => context.go(Routes.outbox),
        );
      },
    );
  }
}

/// A document sent back for correction: one row of the returned list, with
/// the status, what it is, what the checker said, and one tap into fixing it.
class _ReturnedRow extends StatelessWidget {
  const _ReturnedRow({required this.item});

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

    return PressTarget(
      onTap: () => _fix(context),
      semanticsLabel: '${t.shellReturnedTitle}. $title. $remark. ${t.shellReturnedFix}',
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
        child: Row(
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      StatusPill(kind: StampKind.returned, label: t.statusReturned),
                      const SizedBox(width: N360Space.sm),
                      Expanded(
                        child: Text(
                          title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: N360Type.fit(N360Type.bodyStrong, title),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: N360Space.xs),
                  Text(
                    remark,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.fit(N360Type.body, remark).copyWith(color: N360Colors.ink2),
                  ),
                ],
              ),
            ),
            const SizedBox(width: N360Space.sm),
            // The word stays short in every language; a long one gives way
            // to the row rather than crushing it.
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 112),
              child: Text(
                t.shellReturnedFix,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.fit(N360Type.label, t.shellReturnedFix).copyWith(color: N360Colors.laterite),
              ),
            ),
            const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.laterite),
          ],
        ),
      ),
      builder: (context, depth, child) => Transform.scale(scale: pressedScale(depth * 0.4), child: child),
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
        _SetupStep(
          icon: Icons.groups_outlined,
          word: t.shellSetupWorkers,
          count: setup.workerCount,
          route: Routes.attendance,
        ),
      if (can('petty_cash.manage'))
        _SetupStep(
          icon: Icons.account_balance_wallet_outlined,
          word: t.shellSetupWallet,
          count: setup.walletCount,
          route: Routes.pettyCash,
        ),
    ];
  }
}

/// What a new site still needs, in the order to do it. The next step is the
/// one tinted tile, so a brand-new owner can see where to tap.
class _SetupCard extends StatelessWidget {
  const _SetupCard({required this.steps});

  final List<_SetupStep> steps;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final int done = steps.where((step) => step.done).length;
    final int next = steps.indexWhere((step) => !step.done);
    final String nextLine = t.shellSetupNext(steps[next].word);
    return LedgerCard(
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
                    Text(
                      t.shellSetupTitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.fit(N360Type.heading, t.shellSetupTitle),
                    ),
                    Text(
                      nextLine,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.fit(N360Type.caption, nextLine).copyWith(color: N360Colors.laterite),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.sm),
              SquarePercent(fraction: done / steps.length, label: '$done/${steps.length}'),
            ],
          ),
          const SizedBox(height: N360Space.md),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                for (final (i, step) in steps.indexed) ...<Widget>[
                  if (i > 0) const SizedBox(width: N360Space.sm),
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

  /// Two lines of word under a count, with the tile's own padding.
  static const double minHeight = N360Size.tap + N360Space.lg;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    void open() => context.push(step.route);
    final BoxDecoration surface = BoxDecoration(
      color: isNext ? N360Colors.primaryTint : N360Colors.sheet,
      borderRadius: N360Radius.md,
      border: Border.all(color: isNext ? N360Colors.primary : N360Colors.rule),
    );

    return Semantics(
      container: true,
      button: true,
      label: '${t.shellSetupStepSemantics(step.word, step.count)}${step.done ? ', ${t.commonDone}' : ''}',
      onTap: open,
      child: ExcludeSemantics(
        child: PressRow(
          onTap: open,
          minHeight: minHeight,
          child: DecoratedBox(
            decoration: surface,
            child: Padding(
              padding: const EdgeInsets.all(N360Space.tile),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Row(
                    children: <Widget>[
                      Icon(step.icon, size: N360IconSize.lg, color: isNext ? N360Colors.laterite : N360Colors.ink2),
                      const SizedBox(width: N360Space.xs),
                      Text('${step.count}', style: N360Type.figure.copyWith(height: 1.2)),
                      const Spacer(),
                      if (step.done) const Icon(Icons.check_circle, size: N360IconSize.sm, color: N360Colors.mossInk),
                    ],
                  ),
                  const SizedBox(height: N360Space.xs),
                  Text(
                    step.word,
                    maxLines: 2,
                    style: N360Type.fit(N360Type.label, step.word).copyWith(height: 1.3),
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

/// How far the building has come: the figure, the dates, the building beside
/// them and a thin bar with today marked. The figure counts up, the bar fills
/// and the building rises, all once, on entry.
class _ProgressCard extends StatelessWidget {
  const _ProgressCard({required this.project, required this.progress});

  final ProjectRow project;
  final HomeProgress? progress;

  /// The drawing beside the figure, never wider than this.
  static const double artWidth = 120;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final String today = context.read<AppServices>().context.today;
    final HomeProgress? data = progress;

    final Widget words = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(t.shellProgressTitle, style: N360Type.fit(N360Type.heading, t.shellProgressTitle)),
        if (data == null) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          Text(t.shellProgressEmptyTitle, style: N360Type.fit(N360Type.bodyStrong, t.shellProgressEmptyTitle)),
          const SizedBox(height: N360Space.xxs),
          Text(
            t.shellProgressEmptyBody,
            style: N360Type.fit(N360Type.body, t.shellProgressEmptyBody).copyWith(color: N360Colors.ink2),
          ),
        ] else ...<Widget>[
          Text(t.shellProgressBasis, style: N360Type.fit(N360Type.caption, t.shellProgressBasis)),
          const SizedBox(height: N360Space.sm),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: RollingNumber(
              // Before anything is approved the figure is a plain 0: two
              // decimals of nothing read like a broken spreadsheet cell.
              value: data.isZero ? '0' : data.percent,
              suffix: '%',
              style: N360Type.figureHero,
              animateEntry: true,
              semanticsLabel: t.shellProgressSemantics(data.percent),
            ),
          ),
          if (data.isZero) ...<Widget>[
            Text(
              t.shellProgressNothingYet,
              style: N360Type.fit(N360Type.body, t.shellProgressNothingYet).copyWith(color: N360Colors.ink2),
            ),
          ],
          const SizedBox(height: N360Space.sm),
          _DatesRow(endDate: project.endDate, today: today, locale: locale),
        ],
      ],
    );

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: words),
              const SizedBox(width: N360Space.sm),
              SizedBox(
                width: artWidth,
                child: ExcludeSemantics(
                  child: BuildingProgressArt(fraction: data?.fraction ?? 0, animateEntry: data != null),
                ),
              ),
            ],
          ),
          if (data != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            HatchedProgressBar(
              fraction: data.fraction,
              markerLabel: t.commonToday,
              semanticLabel: t.shellProgressSemantics(data.percent),
            ),
          ],
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
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Expanded(
          child: _Meta(
            label: t.shellProgressEnd,
            value: end == null ? t.shellProgressNoEnd : AppFormat.dayMonthYear(end, locale),
          ),
        ),
        if (daysLeft != null)
          Expanded(
            child: _Meta(
              label: late ? t.shellProgressDaysLate : t.shellProgressDaysLeft,
              value: '${daysLeft.abs()}',
              alert: late,
            ),
          ),
      ],
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.label, required this.value, this.alert = false});

  final String label;
  final String value;
  final bool alert;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.fit(N360Type.caption, label)),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            value,
            maxLines: 1,
            style: N360Type.fit(N360Type.label, value).copyWith(color: alert ? N360Colors.stampRed : N360Colors.ink),
          ),
        ),
      ],
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

/// Today's figures, two across. An odd last one runs the full width.
class _TileGrid extends StatelessWidget {
  const _TileGrid({required this.tiles});

  final List<_TileSpec> tiles;

  @override
  Widget build(BuildContext context) {
    Widget tile(_TileSpec spec) => StatTile(
          title: spec.title,
          value: spec.value,
          caption: spec.caption,
          icon: spec.icon,
          emphasized: spec.emphasized,
          onTap: spec.onTap,
        );

    final List<Widget> rows = <Widget>[];
    for (var start = 0; start < tiles.length; start += 2) {
      if (start + 1 < tiles.length) {
        rows.add(
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Expanded(child: tile(tiles[start])),
                const SizedBox(width: N360Space.cardGap),
                Expanded(child: tile(tiles[start + 1])),
              ],
            ),
          ),
        );
      } else {
        rows.add(tile(tiles[start]));
      }
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        for (final (i, row) in rows.indexed) ...<Widget>[
          if (i > 0) const SizedBox(height: N360Space.cardGap),
          row,
        ],
      ],
    );
  }
}
