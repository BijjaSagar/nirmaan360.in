import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/approvals_inbox_cubit.dart';
import '../data/approval_repository.dart';
import '../../measurement/view/widgets/book_kit.dart';
import 'widgets/approval_card.dart';
import 'widgets/approval_read.dart';

/// What waits for this person's decision, newest first.
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

sealed class _Slot {
  const _Slot();
}

class _Live extends _Slot {
  const _Live(this.row, this.order);

  final ApprovalRow row;
  final int order;
}

class _Gone extends _Slot {
  const _Gone(this.leaving);

  final LeavingCard leaving;
}

class _InboxView extends StatelessWidget {
  const _InboxView();

  List<_Slot> _slots(ApprovalsInboxState state) {
    final List<_Slot> slots = <_Slot>[for (final (index, row) in state.items.indexed) _Live(row, index)];
    final LeavingCard? leaving = state.leaving;
    if (leaving != null) {
      slots.insert(leaving.index.clamp(0, slots.length), _Gone(leaving));
    }
    return slots;
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
              final List<_Slot> slots = _slots(state);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  BookTitleBar(
                    eyebrow: state.actionable > 0 ? l.approvalsEyebrow(state.actionable) : null,
                    title: l.approvalsTitle,
                  ),
                  if (state.failure != null && state.online) _FailureLine(failure: state.failure!, l: l),
                  Expanded(child: _list(context, l, state, cubit, slots)),
                  _Footer(state: state, onRefresh: cubit.refresh),
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
    List<_Slot> slots,
  ) {
    if (state.loading) {
      return ListView.builder(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, 6, N360Space.gutter, N360Space.md),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: 3,
        itemBuilder: (context, index) => Padding(
          padding: const EdgeInsets.only(bottom: N360Space.md),
          child: LedgerSkeleton(figure: true, flat: true, semanticsLabel: l.measurementLoading),
        ),
      );
    }
    if (slots.isEmpty) {
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
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, 6, N360Space.gutter, N360Space.md),
        itemCount: slots.length,
        itemBuilder: (context, index) => switch (slots[index]) {
          _Live(:final row, :final order) => Padding(
            key: ValueKey<int>(row.id),
            padding: const EdgeInsets.only(bottom: N360Space.md),
            child: StaggeredRise(
              index: order,
              child: ApprovalCard(row: row, now: now, share: shareOf(row, state.boq), onTap: () => _open(context, row)),
            ),
          ),
          _Gone(:final leaving) => LeavingApprovalCard(
            key: ValueKey<String>('leaving-${leaving.row.id}'),
            leaving: leaving,
            now: now,
            onDone: cubit.leavingDone,
          ),
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
            child: Text(text, style: N360Type.bodyStrong.copyWith(color: N360Colors.stampRed, height: 1.3)),
          ),
        ],
      ),
    );
  }
}

class _Footer extends StatelessWidget {
  const _Footer({required this.state, required this.onRefresh});

  final ApprovalsInboxState state;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
      child: GloveButton(
        key: const ValueKey<String>('approvals-refresh'),
        label: state.refreshing ? l.approvalsRefreshing : l.approvalsRefresh,
        icon: Icons.refresh,
        kind: GloveButtonKind.secondary,
        fullWidth: true,
        loading: state.refreshing,
        onPressed: state.online ? onRefresh : null,
      ),
    );
  }
}
