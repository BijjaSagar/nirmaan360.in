import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/issue_detail_cubit.dart';
import '../bloc/issue_helpers.dart';
import '../data/issue_repository.dart';
import 'widgets/issue_widgets.dart';

class IssueDetailScreen extends StatelessWidget {
  const IssueDetailScreen({super.key, required this.clientUuid});

  final String clientUuid;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<IssueDetailCubit>(
      create: (context) => IssueDetailCubit(context.read<AppServices>(), clientUuid),
      child: const _IssueDetailView(),
    );
  }
}

class _IssueDetailView extends StatelessWidget {
  const _IssueDetailView();

  void _onProblem(BuildContext context, IssueDetailState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    N360Haptics.warning();
    showPaperToast(context, l.issuesStatusFailed, kind: PaperToastKind.error);
  }

  void _onSaved(BuildContext context, IssueDetailState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    if (state.savedWhileOffline) {
      showPaperToast(
        context,
        l.issuesSavedOffline,
        kind: PaperToastKind.info,
        icon: Icons.phone_android,
        duration: const Duration(seconds: 3),
      );
    }
    final String? status = state.savedStatus;
    if (status == IssueStatus.resolved && !context.motion.reduced) {
      final String label = l.pictogramLabel(N360Pictograms.issueStatus(status!));
      showStampThud(context, StampKind.approved, label);
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return MultiBlocListener(
      listeners: <BlocListener<IssueDetailCubit, IssueDetailState>>[
        BlocListener<IssueDetailCubit, IssueDetailState>(
          listenWhen: (a, b) => a.problemId != b.problemId,
          listener: _onProblem,
        ),
        BlocListener<IssueDetailCubit, IssueDetailState>(
          listenWhen: (a, b) => a.savedId != b.savedId,
          listener: _onSaved,
        ),
      ],
      child: Scaffold(
        body: LedgerPaper(
          child: SafeArea(
            bottom: false,
            child: BlocBuilder<IssueDetailCubit, IssueDetailState>(
              builder: (context, state) {
                switch (state.load) {
                  case IssueDetailLoad.loading:
                    return const SizedBox.shrink();
                  case IssueDetailLoad.missing:
                    return ListView(
                      padding: const EdgeInsets.all(N360Space.gutter),
                      children: <Widget>[
                        _BackLink(l: l),
                        EmptyLedger(
                          title: l.issuesDetailMissingTitle,
                          message: l.issuesDetailMissingMessage,
                        ),
                      ],
                    );
                  case IssueDetailLoad.ready:
                    return _Body(state: state);
                }
              },
            ),
          ),
        ),
      ),
    );
  }
}

class _BackLink extends StatelessWidget {
  const _BackLink({required this.l});

  final AppLocalizations l;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: GloveButton(
        label: l.commonBack,
        kind: GloveButtonKind.ghost,
        icon: Icons.arrow_back,
        onPressed: () => Navigator.of(context).maybePop(),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.state});

  final IssueDetailState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final SyncedRecord<IssueRow> record = state.issue!;
    final IssueRow issue = record.record;
    final String? assignee = state.userNames[issue.assignedTo];
    final String? raisedBy = state.userNames[issue.raisedBy];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.xs,
              N360Space.gutter,
              N360Space.gutter,
            ),
            children: <Widget>[
              _BackLink(l: l),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Expanded(
                    child: LedgerHero(
                      tag: 'issue-title-${issue.clientUuid}',
                      child: Text(issue.title, style: N360Type.title),
                    ),
                  ),
                  const SizedBox(width: N360Space.sm),
                  IssueStatusStamp(
                    status: issue.status,
                    size: StampSize.medium,
                    animate: true,
                  ),
                ],
              ),
              if (issue.locationNote != null && issue.locationNote!.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: N360Space.xs),
                  child: Row(
                    children: <Widget>[
                      const Icon(Icons.place_outlined, size: 20, color: N360Colors.ink2),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(issue.locationNote!, style: N360Type.bodyLarge),
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: N360Space.sm),
              const RuledDivider(color: N360Colors.ink2),
              const SizedBox(height: N360Space.md),
              StaggeredRise(
                index: 0,
                child: LedgerCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      LedgerRow(
                        label: l.issuesDetailPriority,
                        valueWidget: PriorityMarker(priority: issue.priority, compact: true),
                      ),
                      LedgerRow(
                        label: l.issuesDetailDue,
                        valueWidget: _DueValue(issue: issue, today: state.today),
                      ),
                      LedgerRow(
                        label: l.issuesDetailAssignee,
                        valueWidget: Text(
                          assignee ??
                              (issue.assignedTo == null ? l.issuesUnassigned : l.issuesAssignedUnknown),
                          style: N360Type.bodyStrong,
                        ),
                      ),
                      LedgerRow(
                        label: l.issuesDetailRaisedBy,
                        ruled: issue.resolvedAt != null,
                        valueWidget: Text(raisedBy ?? '-', style: N360Type.bodyStrong),
                      ),
                      if (issue.resolvedAt != null)
                        LedgerRow(
                          label: l.issuesDetailResolvedOn,
                          ruled: false,
                          valueWidget: Text(
                            shortDate(context, issue.resolvedAt!.toIso8601String()),
                            style: N360Type.bodyStrong,
                          ),
                        ),
                    ],
                  ),
                ),
              ),
              if (issue.description != null && issue.description!.isNotEmpty) ...<Widget>[
                const SizedBox(height: N360Space.md),
                StaggeredRise(
                  index: 1,
                  child: LedgerCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        SectionLabel(l.issuesDetailDescription),
                        const SizedBox(height: N360Space.xs),
                        Text(issue.description!, style: N360Type.body),
                      ],
                    ),
                  ),
                ),
              ],
              if (record.syncState != LocalSyncState.synced) ...<Widget>[
                const SizedBox(height: N360Space.md),
                Align(
                  alignment: Alignment.centerLeft,
                  child: IssueSyncStamp(state: record.syncState),
                ),
              ],
            ],
          ),
        ),
        _StatusActions(status: issue.status, savingStatus: state.savingStatus),
      ],
    );
  }
}

class _DueValue extends StatelessWidget {
  const _DueValue({required this.issue, required this.today});

  final IssueRow issue;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? due = issue.dueDate;
    if (due == null) {
      return Text(l.issuesNoDueDate, style: N360Type.body);
    }
    if (isOverdue(issue, today)) {
      return Text(
        l.issuesOverdue(daysBetween(due, today)),
        style: N360Type.bodyStrong.copyWith(color: N360Colors.stampRed),
      );
    }
    return Text(shortDate(context, due), style: N360Type.bodyStrong);
  }
}

/// Start, resolve and close, large and in the thumb zone.
class _StatusActions extends StatelessWidget {
  const _StatusActions({required this.status, required this.savingStatus});

  final String status;
  final String? savingStatus;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final IssueDetailCubit cubit = context.read<IssueDetailCubit>();
    final List<_StatusAction> actions = switch (status) {
      IssueStatus.open => <_StatusAction>[
        _StatusAction(l.issuesActionStart, Icons.play_arrow, IssueStatus.inProgress),
        _StatusAction(l.issuesActionResolve, Icons.task_alt, IssueStatus.resolved, primary: false),
      ],
      IssueStatus.inProgress => <_StatusAction>[
        _StatusAction(l.issuesActionResolve, Icons.task_alt, IssueStatus.resolved),
        _StatusAction(l.issuesActionClose, Icons.lock_outline, IssueStatus.closed, primary: false),
      ],
      IssueStatus.resolved => <_StatusAction>[
        _StatusAction(l.issuesActionClose, Icons.lock_outline, IssueStatus.closed),
        _StatusAction(l.issuesActionReopen, Icons.replay, IssueStatus.open, primary: false),
      ],
      _ => <_StatusAction>[
        _StatusAction(l.issuesActionReopen, Icons.replay, IssueStatus.open, primary: false),
      ],
    };

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
              for (final (index, action) in actions.indexed) ...<Widget>[
                if (index > 0) const SizedBox(height: N360Space.sm),
                GloveButton(
                  label: action.label,
                  icon: action.icon,
                  kind: action.primary ? GloveButtonKind.primary : GloveButtonKind.secondary,
                  large: action.primary,
                  fullWidth: true,
                  loading: savingStatus == action.target,
                  onPressed: savingStatus == null ? () => cubit.changeStatus(action.target) : null,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusAction {
  const _StatusAction(this.label, this.icon, this.target, {this.primary = true});

  final String label;
  final IconData icon;
  final String target;
  final bool primary;
}
