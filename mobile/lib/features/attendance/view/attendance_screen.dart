import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/attendance_cubit.dart';
import 'widgets/add_worker_sheet.dart';
import 'widgets/day_strip.dart';
import 'widgets/field_kit.dart';
import 'widgets/muster_row.dart';
import 'widgets/muster_summary_card.dart';

/// The muster roll: one tap per worker, written at once, no save button.
class AttendanceScreen extends StatelessWidget {
  const AttendanceScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<AttendanceCubit>(
      create: (context) => AttendanceCubit(context.read<AppServices>()),
      child: const _AttendanceView(),
    );
  }
}

class _AttendanceView extends StatelessWidget {
  const _AttendanceView();

  /// How far a toast sits above the screen foot: the action bar with its hint
  /// line and a little air.
  static const double _toastLift =
      N360Size.tapLarge + N360Space.xxxl + N360Space.md;

  void _onNotice(BuildContext context, AttendanceState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    switch (state.notice) {
      case MarkFailed():
        N360Haptics.warning();
        showPaperToast(
          context,
          l.attendanceSaveFailed,
          kind: PaperToastKind.error,
          bottomOffset: _toastLift,
        );
      case AllPresentDone(:final count):
        N360Haptics.success();
        showPaperToast(
          context,
          l.attendanceAllPresentDone(count),
          kind: PaperToastKind.success,
          icon: Icons.task_alt,
          bottomOffset: _toastLift,
          duration: const Duration(milliseconds: 1800),
        );
      case null:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    return BlocListener<AttendanceCubit, AttendanceState>(
      listenWhen: (a, b) => a.noticeId != b.noticeId,
      listener: _onNotice,
      child: Scaffold(
        body: LedgerPaper(
          child: SafeArea(
            top: false,
            bottom: false,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                const _Header(),
                const Expanded(child: _MusterScroll()),
                const _BottomActions(),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _MusterScroll extends StatelessWidget {
  const _MusterScroll();

  @override
  Widget build(BuildContext context) {
    final double scale = MediaQuery.textScalerOf(
      context,
    ).clamp(maxScaleFactor: 1.3).scale(1);
    return BlocSelector<AttendanceCubit, AttendanceState, bool>(
      // A card of zeros says nothing while loading, with no project, or with
      // no workers, so it waits until there is a muster to total.
      selector: (state) =>
          state.load == AttendanceLoad.ready && state.workerCount > 0,
      builder: (context, hasMuster) {
        return CustomScrollView(
          slivers: <Widget>[
            const SliverToBoxAdapter(child: _DayRow()),
            if (hasMuster)
              SliverPersistentHeader(
                pinned: true,
                delegate: _SummaryDelegate(
                  collapsed: summaryCollapsedHeight(scale),
                  expanded: summaryExpandedHeight(scale),
                ),
              ),
            const SliverPadding(
              padding: EdgeInsets.only(top: N360Space.xs),
              sliver: _MusterList(),
            ),
          ],
        );
      },
    );
  }
}

class _SummaryDelegate extends SliverPersistentHeaderDelegate {
  const _SummaryDelegate({required this.collapsed, required this.expanded});

  final double collapsed;
  final double expanded;

  @override
  double get minExtent => collapsed;

  @override
  double get maxExtent => expanded;

  @override
  Widget build(
    BuildContext context,
    double shrinkOffset,
    bool overlapsContent,
  ) {
    return MediaQuery.withClampedTextScaling(
      maxScaleFactor: 1.3,
      child: MusterSummaryCard(
        fold: (shrinkOffset / (expanded - collapsed)).clamp(0.0, 1.0),
      ),
    );
  }

  @override
  bool shouldRebuild(_SummaryDelegate oldDelegate) =>
      oldDelegate.collapsed != collapsed || oldDelegate.expanded != expanded;
}

class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<AttendanceCubit, AttendanceState>(
      buildWhen: (a, b) => a.date != b.date || a.project?.id != b.project?.id,
      builder: (context, state) {
        final String locale = Localizations.localeOf(context).toString();
        final String date = DateFormat(
          'd MMM',
          locale,
        ).format(DateTime.parse(state.date));
        final String? projectCode = state.project?.code;

        return FieldTopBar(
          title: state.isToday
              ? l.attendanceTodayTitle
              : l.attendanceDateTitle(date),
          subtitle: projectCode == null
              ? l.attendanceEyebrow
              : '${l.attendanceEyebrow} · $projectCode',
        );
      },
    );
  }
}

/// The strip of days under the bar, once there is a project to mark.
class _DayRow extends StatelessWidget {
  const _DayRow();

  @override
  Widget build(BuildContext context) {
    return BlocSelector<AttendanceCubit, AttendanceState, bool>(
      selector: (state) => state.project != null,
      builder: (context, hasProject) {
        if (!hasProject) return const SizedBox.shrink();
        return const Padding(
          padding: EdgeInsets.only(top: N360Space.xs, bottom: N360Space.xs),
          child: DayStrip(),
        );
      },
    );
  }
}

class _MusterList extends StatelessWidget {
  const _MusterList();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<AttendanceCubit, AttendanceState>(
      buildWhen: (a, b) => a.load != b.load || !identical(a.groups, b.groups),
      builder: (context, state) {
        switch (state.load) {
          case AttendanceLoad.loading:
            return SliverToBoxAdapter(
              child: _MusterSkeleton(label: l.attendanceLoading),
            );
          case AttendanceLoad.noProject:
            return SliverToBoxAdapter(
              child: EmptyLedger(
                title: l.attendanceNoProjectTitle,
                message: l.attendanceNoProjectMessage,
                action: GloveButton(
                  label: l.attendanceChooseProject,
                  icon: Icons.swap_horiz,
                  onPressed: () => context.push(Routes.projectsSwitch),
                ),
              ),
            );
          case AttendanceLoad.failed:
            return SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.all(N360Space.gutter),
                child: ErrorSlip(
                  title: l.attendanceLoadFailedTitle,
                  message: l.attendanceLoadFailedMessage,
                  actionLabel: l.commonRetry,
                  onAction: context.read<AttendanceCubit>().retry,
                ),
              ),
            );
          case AttendanceLoad.ready:
            if (state.groups.isEmpty) {
              return SliverToBoxAdapter(
                child: EmptyLedger(
                  title: l.attendanceNoWorkersTitle,
                  message: context.read<AppServices>().auth.can('worker.manage')
                      ? l.attendanceNoWorkersMessage
                      : l.attendanceNoWorkersAsk,
                ),
              );
            }
            // Where each trade's first row falls in the whole muster, for the
            // entrance.
            final List<int> starts = <int>[];
            var running = 0;
            for (final group in state.groups) {
              starts.add(running);
              running += group.workers.length + 1;
            }
            return SliverPadding(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                0,
                N360Space.gutter,
                N360Space.lg,
              ),
              sliver: SliverList.separated(
                itemCount: state.groups.length,
                separatorBuilder: (_, _) =>
                    const SizedBox(height: N360Space.cardGap),
                itemBuilder: (context, index) => _TradeList(
                  key: ValueKey<String>('group-${state.groups[index].trade}'),
                  group: state.groups[index],
                  firstIndex: starts[index],
                ),
              ),
            );
        }
      },
    );
  }
}

/// The muster's shape while it loads: the summary card, then a trade card.
class _MusterSkeleton extends StatelessWidget {
  const _MusterSkeleton({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          LedgerSkeleton(figure: true, lines: 2, semanticsLabel: label),
          const SizedBox(height: N360Space.cardGap),
          const ExcludeSemantics(child: LedgerSkeleton(lines: 4)),
        ],
      ),
    );
  }
}

/// One trade's workers as a [LedgerList]: a heading with the trade's count,
/// then a hairline between rows. Rows carry no surface or shadow of their own,
/// and each reads only its own mark.
class _TradeList extends StatelessWidget {
  const _TradeList({super.key, required this.group, required this.firstIndex});

  final MusterGroup group;

  /// Position of this list's heading in the whole muster, for the entrance.
  final int firstIndex;

  @override
  Widget build(BuildContext context) {
    return LedgerList(
      header: StaggeredRise(
        index: firstIndex,
        maxAnimated: 8,
        child: MusterGroupHeader(group: group),
      ),
      children: <Widget>[
        for (final (i, worker) in group.workers.indexed)
          StaggeredRise(
            key: ValueKey<int>(worker.id),
            index: firstIndex + 1 + i,
            maxAnimated: 8,
            child: MusterRow(worker: worker),
          ),
      ],
    );
  }
}

/// All present and Add worker, in the thumb zone.
class _BottomActions extends StatelessWidget {
  const _BottomActions();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocSelector<
      AttendanceCubit,
      AttendanceState,
      (int left, int total, bool ready)
    >(
      selector: (state) => (
        state.totals.left,
        state.workerCount,
        state.load == AttendanceLoad.ready,
      ),
      builder: (context, data) {
        final (left, total, ready) = data;
        if (!ready) return const SizedBox.shrink();

        final bool showAllPresent = total > 0;
        final bool done = left == 0;
        final AttendanceCubit cubit = context.read<AttendanceCubit>();
        final bool animate = !context.motion.reduced;
        final bool canAddWorker = context.read<AppServices>().auth.can(
          'worker.manage',
        );

        if (total == 0 && !canAddWorker) return const SizedBox.shrink();

        return FieldActionBar(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              // Only when it can be misread: some marked, some not.
              if (showAllPresent && !done && left < total)
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    N360Space.xs,
                    0,
                    N360Space.xs,
                    N360Space.sm,
                  ),
                  child: Text(
                    l.attendanceAllPresentHelper,
                    style: N360Type.fit(
                      N360Type.caption,
                      l.attendanceAllPresentHelper,
                    ).copyWith(color: N360Colors.ink2),
                  ),
                ),
              Row(
                children: <Widget>[
                  if (showAllPresent)
                    Expanded(
                      child: GloveButton(
                        label: done
                            ? l.attendanceAllMarked
                            : l.attendanceAllPresent(left),
                        icon: done
                            ? Icons.check_circle_outline
                            : Icons.done_all,
                        large: true,
                        fullWidth: true,
                        onPressed: done
                            ? null
                            : () => cubit.markAllPresent(animate: animate),
                      ),
                    ),
                  if (showAllPresent && canAddWorker) ...<Widget>[
                    const SizedBox(width: N360Space.sm),
                    FieldSideTile(
                      icon: Icons.person_add_alt_1,
                      label: l.attendanceAddWorker,
                      onTap: () => showAddWorkerSheet(context),
                    ),
                  ],
                  if (!showAllPresent && canAddWorker)
                    Expanded(
                      child: GloveButton(
                        label: l.attendanceAddFirstWorker,
                        icon: Icons.person_add_alt_1,
                        large: true,
                        fullWidth: true,
                        onPressed: () => showAddWorkerSheet(context),
                      ),
                    ),
                ],
              ),
              // Marking with the camera. It needs workers, whose cards are scanned.
              if (total > 0 && context.read<AppServices>().attendance.canCapture) ...<Widget>[
                const SizedBox(height: N360Space.sm),
                Row(
                  children: <Widget>[
                    Expanded(
                      child: GloveButton(
                        key: const ValueKey<String>('attendance-scan'),
                        label: l.attendanceScanCard,
                        icon: Icons.qr_code_scanner,
                        kind: GloveButtonKind.secondary,
                        fullWidth: true,
                        onPressed: () => context.push(Routes.attendanceScan),
                      ),
                    ),
                    const SizedBox(width: N360Space.sm),
                    Expanded(
                      child: GloveButton(
                        key: const ValueKey<String>('attendance-selfie'),
                        label: l.attendanceSelfie,
                        icon: Icons.photo_camera_outlined,
                        kind: GloveButtonKind.secondary,
                        fullWidth: true,
                        onPressed: () => context.push(Routes.attendanceSelfie),
                      ),
                    ),
                  ],
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}
