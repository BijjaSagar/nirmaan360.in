import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';

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

  void _onNotice(BuildContext context, AttendanceState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    switch (state.notice) {
      case MarkFailed():
        N360Haptics.warning();
        showPaperToast(
          context,
          l.attendanceSaveFailed,
          kind: PaperToastKind.error,
          bottomOffset: 132,
        );
      case AllPresentDone(:final count):
        N360Haptics.success();
        showPaperToast(
          context,
          l.attendanceAllPresentDone(count),
          kind: PaperToastKind.success,
          icon: Icons.task_alt,
          bottomOffset: 132,
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
            bottom: false,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
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
    final double scale = MediaQuery.textScalerOf(context).clamp(maxScaleFactor: 1.3).scale(1);
    return CustomScrollView(
      slivers: <Widget>[
        const SliverToBoxAdapter(child: _Header()),
        SliverPersistentHeader(
          pinned: true,
          delegate: _SummaryDelegate(
            collapsed: kSummaryCollapsedHeight * scale,
            expanded: (kSummaryCollapsedHeight + kSummaryHeroHeight + 10) * scale,
          ),
        ),
        const SliverPadding(
          padding: EdgeInsets.only(top: N360Space.xs),
          sliver: _MusterList(),
        ),
      ],
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
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
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
        final String date = DateFormat('d MMM', locale).format(DateTime.parse(state.date));
        final String? projectCode = state.project?.code;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            FieldTopBar(
              title: state.isToday ? l.attendanceTodayTitle : l.attendanceDateTitle(date),
              subtitle: projectCode == null
                  ? l.attendanceEyebrow
                  : '${l.attendanceEyebrow} · $projectCode',
            ),
            const DayStrip(),
            const SizedBox(height: N360Space.xs),
          ],
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
            return const SliverToBoxAdapter(child: SizedBox(height: 180));
          case AttendanceLoad.noProject:
            return SliverToBoxAdapter(
              child: EmptyLedger(
                title: l.attendanceNoProjectTitle,
                message: l.attendanceNoProjectMessage,
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
                  message: l.attendanceNoWorkersMessage,
                ),
              );
            }
            var firstIndex = 0;
            return SliverMainAxisGroup(
              slivers: <Widget>[
                for (final group in state.groups)
                  () {
                    final int start = firstIndex;
                    firstIndex += group.workers.length + 1;
                    return _TradeCard(group: group, firstIndex: start);
                  }(),
              ],
            );
        }
      },
    );
  }
}

/// One trade's workers on a single white card. Rows are built lazily and carry
/// no surface or shadow of their own.
class _TradeCard extends StatelessWidget {
  const _TradeCard({required this.group, required this.firstIndex});

  final MusterGroup group;

  /// Position of this card's first row in the whole muster, for the entrance.
  final int firstIndex;

  @override
  Widget build(BuildContext context) {
    return FieldGroupSliver(
      sliver: SliverList.builder(
        itemCount: group.workers.length + 1,
        itemBuilder: (context, index) {
          return StaggeredRise(
            index: firstIndex + index,
            maxAnimated: 8,
            child: index == 0
                ? MusterGroupHeader(
                    key: ValueKey<String>('group-${group.trade}'),
                    group: group,
                  )
                : MusterRow(
                    key: ValueKey<int>(group.workers[index - 1].id),
                    worker: group.workers[index - 1],
                  ),
          );
        },
      ),
    );
  }
}

/// All present and Add worker, in the thumb zone.
class _BottomActions extends StatelessWidget {
  const _BottomActions();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocSelector<AttendanceCubit, AttendanceState, (int left, int total, bool ready)>(
      selector: (state) => (state.totals.left, state.workerCount, state.load == AttendanceLoad.ready),
      builder: (context, data) {
        final (left, total, ready) = data;
        final bool showAllPresent = ready && total > 0;
        final bool done = left == 0;
        final AttendanceCubit cubit = context.read<AttendanceCubit>();
        final bool animate = !context.motion.reduced;

        return FieldActionBar(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              if (showAllPresent && !done)
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, 0, 4, N360Space.xs + 2),
                  child: Text(
                    l.attendanceAllPresentHelper,
                    style: N360Type.caption.copyWith(color: N360Colors.ink2),
                  ),
                ),
              Row(
                children: <Widget>[
                  if (showAllPresent)
                    Expanded(
                      child: GloveButton(
                        label: done ? l.attendanceAllMarked : l.attendanceAllPresent(left),
                        icon: done ? Icons.check_circle_outline : Icons.done_all,
                        large: true,
                        fullWidth: true,
                        onPressed: done ? null : () => cubit.markAllPresent(animate: animate),
                      ),
                    ),
                  if (showAllPresent) ...<Widget>[
                    const SizedBox(width: N360Space.sm),
                    _AddWorkerTile(onTap: () => showAddWorkerSheet(context)),
                  ] else
                    Expanded(
                      child: GloveButton(
                        label: l.attendanceAddWorker,
                        kind: GloveButtonKind.secondary,
                        icon: Icons.person_add_alt_1,
                        large: true,
                        fullWidth: true,
                        onPressed: () => showAddWorkerSheet(context),
                      ),
                    ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}

/// Add worker as a small tile with its word under the icon, so "All present"
/// keeps a whole line and the bar stays one row high.
class _AddWorkerTile extends StatelessWidget {
  const _AddWorkerTile({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return PressTarget(
      onTap: onTap,
      minHeight: N360Size.tapLarge,
      semanticsLabel: l.attendanceAddWorker,
      child: SizedBox(
        width: 92,
        height: N360Size.tapLarge,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.xs),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              const Icon(Icons.person_add_alt_1, size: 24, color: N360Colors.ink),
              const SizedBox(height: 2),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  l.attendanceAddWorker,
                  maxLines: 1,
                  style: N360Type.label.copyWith(height: 1.2, color: N360Colors.ink),
                ),
              ),
            ],
          ),
        ),
      ),
      builder: (context, depth, child) => Transform.scale(
        scale: 1 - 0.035 * depth,
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.paper2,
            borderRadius: N360Radius.tileRadius,
          ),
          child: child,
        ),
      ),
    );
  }
}
