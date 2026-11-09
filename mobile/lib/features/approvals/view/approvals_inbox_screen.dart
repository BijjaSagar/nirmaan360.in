import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../bloc/approvals_inbox_cubit.dart';
import '../data/approval_repository.dart';
import 'widgets/approval_card.dart';

/// What waits for this person's decision, a list to each kind of document.
class ApprovalsInboxScreen extends StatelessWidget {
  const ApprovalsInboxScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ApprovalsInboxCubit>(
      create: (context) => ApprovalsInboxCubit(services: context.read<AppServices>())..start(),
      child: const _InboxView(),
    );
  }
}

class _Group {
  _Group(this.title, this.entries);

  final String title;
  final List<Object> entries;

  int get waiting => entries.whereType<ApprovalRow>().length;
}

class _InboxView extends StatelessWidget {
  const _InboxView();

  /// What this person can decide, then what sits with someone else. A row
  /// that has just been decided goes back to where it was for the moment it
  /// takes to leave.
  List<_Group> _groups(AppLocalizations l, ApprovalsInboxState state) {
    final List<Object> entries = List<Object>.of(state.items);
    final LeavingCard? leaving = state.leaving;
    if (leaving != null) entries.insert(leaving.index.clamp(0, entries.length), leaving);

    final List<Object> forYou = <Object>[];
    final List<Object> others = <Object>[];
    for (final Object entry in entries) {
      final bool mine = entry is LeavingCard || (entry as ApprovalRow).canAct;
      (mine ? forYou : others).add(entry);
    }
    return <_Group>[
      if (forYou.isNotEmpty) _Group(l.approvalsForYou, forYou),
      if (others.isNotEmpty) _Group(l.approvalsOnOthers, others),
    ];
  }

  Future<void> _open(BuildContext context, ApprovalRow row) async {
    final ApprovalsInboxCubit cubit = context.read<ApprovalsInboxCubit>();
    final N360Motion motion = context.motion;
    final ApprovalOutcome? outcome = await context.push<ApprovalOutcome>(Routes.approvalDetail(row.id));
    if (outcome != null && !motion.reduced) cubit.showLeaving(outcome);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          child: BlocBuilder<ApprovalsInboxCubit, ApprovalsInboxState>(
            builder: (context, state) {
              final ApprovalsInboxCubit cubit = context.read<ApprovalsInboxCubit>();
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  if (!state.online) OfflineBanner(label: l.approvalsOffline, detail: l.approvalsOfflineDetail),
                  BookTitleBar(title: l.approvalsTitle),
                  if (state.failure != null && state.online) _FailureLine(failure: state.failure!, l: l),
                  Expanded(child: _list(context, l, state, cubit, _groups(l, state))),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
                    child: GloveButton(
                      key: const ValueKey<String>('approvals-refresh'),
                      label: state.refreshing ? l.approvalsRefreshing : l.approvalsRefresh,
                      icon: Icons.refresh,
                      kind: GloveButtonKind.secondary,
                      fullWidth: true,
                      loading: state.refreshing,
                      onPressed: state.online ? cubit.refresh : null,
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

  Widget _list(
    BuildContext context,
    AppLocalizations l,
    ApprovalsInboxState state,
    ApprovalsInboxCubit cubit,
    List<_Group> groups,
  ) {
    if (state.loading) {
      return ListView.builder(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: 3,
        itemBuilder: (context, index) => Padding(
          padding: const EdgeInsets.only(bottom: N360Space.cardGap),
          child: LedgerSkeleton(flat: true, semanticsLabel: l.measurementLoading),
        ),
      );
    }
    if (groups.isEmpty) {
      return Center(
        child: SingleChildScrollView(
          child: EmptyLedger(title: l.approvalsEmptyTitle, message: l.approvalsEmptyBody),
        ),
      );
    }
    final DateTime now = cubit.now;
    return LedgerRefresh(
      onRefresh: cubit.refresh,
      semanticsLabel: l.approvalsRefreshing,
      child: ListView.builder(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
        itemCount: groups.length,
        itemBuilder: (context, index) {
          final _Group group = groups[index];
          return Padding(
            key: ValueKey<String>(group.title),
            padding: const EdgeInsets.only(bottom: N360Space.cardGap),
            child: StaggeredRise(
              index: index,
              child: LedgerList(
                header: Row(
                  children: <Widget>[
                    Expanded(child: Text(group.title, style: N360Type.heading)),
                    Text('${group.waiting}', style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
                  ],
                ),
                children: <Widget>[
                  for (final Object entry in group.entries)
                    if (entry is LeavingCard)
                      LeavingApprovalTile(
                        key: ValueKey<String>('leaving-${entry.row.id}'),
                        leaving: entry,
                        now: now,
                        onDone: cubit.leavingDone,
                      )
                    else
                      ApprovalTile(
                        key: ValueKey<int>((entry as ApprovalRow).id),
                        row: entry,
                        now: now,
                        onTap: () => _open(context, entry),
                      ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _FailureLine extends StatelessWidget {
  const _FailureLine({required this.failure, required this.l});

  final InboxFailure failure;
  final AppLocalizations l;

  @override
  Widget build(BuildContext context) {
    final String text = switch (failure) {
      InboxFailure.offline => l.approvalsCouldNotRefreshOffline,
      InboxFailure.session => l.approvalsSessionExpired,
      InboxFailure.server => l.approvalsCouldNotRefresh,
    };
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter, vertical: N360Space.xs),
      child: Row(
        children: <Widget>[
          const Icon(Icons.error_outline, size: N360IconSize.md, color: N360Colors.stampRed),
          const SizedBox(width: 6),
          Expanded(
            child: Text(text, style: N360Type.bodyStrong.copyWith(color: N360Colors.stampRed)),
          ),
        ],
      ),
    );
  }
}
