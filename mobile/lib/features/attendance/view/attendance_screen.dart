import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/attendance_cubit.dart';
import 'widgets/add_worker_sheet.dart';
import 'widgets/day_strip.dart';
import 'widgets/muster_row.dart';
import 'widgets/totals_strip.dart';

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
          delegate: _TotalsDelegate(extent: kTotalsStripHeight * scale),
        ),
        const SliverPadding(
          padding: EdgeInsets.only(top: N360Space.xs),
          sliver: _MusterList(),
        ),
        const SliverPadding(padding: EdgeInsets.only(bottom: N360Space.xl)),
      ],
    );
  }
}

class _TotalsDelegate extends SliverPersistentHeaderDelegate {
  const _TotalsDelegate({required this.extent});

  final double extent;

  @override
  double get minExtent => extent;

  @override
  double get maxExtent => extent;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return MediaQuery.withClampedTextScaling(
      maxScaleFactor: 1.3,
      child: const TotalsStrip(),
    );
  }

  @override
  bool shouldRebuild(_TotalsDelegate oldDelegate) => oldDelegate.extent != extent;
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
        final DateTime day = DateTime.parse(state.date);
        final String date = DateFormat('d MMM', locale).format(day);
        final String title = state.isToday
            ? l.attendanceTodayTitle
            : l.attendanceDateTitle(date);
        final String? projectCode = state.project?.code;

        return Padding(
          padding: const EdgeInsets.only(top: N360Space.xs),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  N360Space.gutter,
                  0,
                  N360Space.gutter,
                  0,
                ),
                child: Row(
                  children: <Widget>[
                    if (Navigator.of(context).canPop())
                      GloveButton(
                        label: l.commonBack,
                        kind: GloveButtonKind.ghost,
                        icon: Icons.arrow_back,
                        onPressed: () => Navigator.of(context).maybePop(),
                      ),
                    Expanded(
                      child: SectionLabel(
                        projectCode == null
                            ? '${l.attendanceEyebrow} · $date'
                            : '${l.attendanceEyebrow} · $projectCode',
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
                child: SheetHeader(title: title),
              ),
              const SizedBox(height: N360Space.sm),
              const DayStrip(),
              const SizedBox(height: N360Space.xs),
            ],
          ),
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
      buildWhen: (a, b) => a.load != b.load || !identical(a.items, b.items),
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
            if (state.items.isEmpty) {
              return SliverToBoxAdapter(
                child: EmptyLedger(
                  title: l.attendanceNoWorkersTitle,
                  message: l.attendanceNoWorkersMessage,
                ),
              );
            }
            return SliverPadding(
              padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
              sliver: SliverList.builder(
                itemCount: state.items.length,
                itemBuilder: (context, index) {
                  final MusterItem item = state.items[index];
                  return StaggeredRise(
                    index: index,
                    maxAnimated: 8,
                    child: switch (item) {
                      MusterGroup() => MusterGroupHeader(
                        key: ValueKey<String>('group-${item.trade}'),
                        group: item,
                      ),
                      MusterWorker() => MusterRow(
                        key: ValueKey<int>(item.id),
                        worker: item,
                      ),
                    },
                  );
                },
              ),
            );
        }
      },
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

        return DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.sheet,
            border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
          ),
          child: SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.sm,
                N360Space.gutter,
                N360Space.sm,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (showAllPresent && !done)
                    Padding(
                      padding: const EdgeInsets.only(bottom: N360Space.xs),
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
                            onPressed: done
                                ? null
                                : () => cubit.markAllPresent(animate: animate),
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
            ),
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
    return SizedBox(
      width: 92,
      height: N360Size.tapLarge,
      child: LedgerCard(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.xs),
        onTap: onTap,
        semanticsLabel: l.attendanceAddWorker,
        child: SizedBox(
          height: N360Size.tapLarge - N360Size.edge,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              const Icon(Icons.person_add_alt_1, size: 26, color: N360Colors.ink),
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
    );
  }
}
