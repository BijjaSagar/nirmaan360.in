import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../measurement/view/widgets/book_kit.dart';
import '../bloc/approval_detail_cubit.dart';
import '../bloc/approvals_inbox_cubit.dart';
import '../data/approval_repository.dart';
import 'widgets/approval_document.dart';
import 'widgets/approval_read.dart';
import 'widgets/send_back_sheet.dart';

/// One document, in full, with the decision under the thumb. Approving is the
/// app's most weighty moment: the server answers first, then the stamp falls.
class ApprovalDetailScreen extends StatelessWidget {
  const ApprovalDetailScreen({super.key, required this.approvalId});

  final int approvalId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ApprovalDetailCubit>(
      create: (context) => ApprovalDetailCubit(services: context.read<AppServices>(), approvalId: approvalId)..start(),
      child: _DetailView(approvalId: approvalId),
    );
  }
}

class _DetailView extends StatelessWidget {
  const _DetailView({required this.approvalId});

  final int approvalId;

  void _leave(BuildContext context, [ApprovalOutcome? outcome]) {
    if (context.canPop()) {
      context.pop(outcome);
    } else {
      context.go(Routes.approvals);
    }
  }

  Future<void> _approve(BuildContext context) async {
    final ApprovalDetailCubit cubit = context.read<ApprovalDetailCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final ActResult result = await cubit.approve();
    if (result != ActResult.done || !context.mounted) return;
    await showStampThud(context, StampKind.approved, l.statusApproved);
    if (context.mounted) _leave(context, ApprovalOutcome(approvalId, ApprovalAction.approved));
  }

  Future<void> _sendBack(BuildContext context, ApprovalRow row) async {
    final ApprovalDetailCubit cubit = context.read<ApprovalDetailCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final String? remark = await showSendBackSheet(context, docType: row.docType);
    if (remark == null || !context.mounted) return;
    final ActResult result = await cubit.sendBack(remark);
    if (result != ActResult.done || !context.mounted) return;
    N360Haptics.success();
    showPaperToast(context, l.approvalsSentBackToast, kind: PaperToastKind.success);
    _leave(context, ApprovalOutcome(approvalId, ApprovalAction.sentBack));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<ApprovalDetailCubit, ApprovalDetailState>(
      builder: (context, state) {
        if (state.loading) return const Scaffold(backgroundColor: Colors.transparent);
        final ApprovalRow? row = state.item;
        if (state.gone || row == null) return _goneView(context, l);
        final AppServices services = context.read<AppServices>();
        final DateTime now = context.read<ApprovalDetailCubit>().now;

        return PopScope(
          canPop: !state.acting,
          child: Scaffold(
            backgroundColor: Colors.transparent,
            body: LedgerPaper(
              child: SafeArea(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    if (!state.online) OfflineBanner(label: l.approvalsOffline, detail: l.approvalsOfflineDetail),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(2, N360Space.xs, N360Space.gutter, 0),
                      child: Row(
                        children: <Widget>[
                          LedgerBackKey(onTap: () => _leave(context)),
                          const SizedBox(width: N360Space.xs),
                          Expanded(
                            child: Text(
                              docTypeWord(l, row.docType),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: N360Type.title,
                            ),
                          ),
                          if (row.status == 'IN_REVIEW')
                            RubberStamp(kind: StampKind.inReview, label: l.statusInReview, size: StampSize.small),
                        ],
                      ),
                    ),
                    Expanded(
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(
                          N360Space.gutter,
                          N360Space.sm,
                          N360Space.gutter,
                          N360Space.lg,
                        ),
                        children: <Widget>[
                          _Hero(row: row, now: now),
                          if (isDeviationDoc(row)) ...<Widget>[
                            const SizedBox(height: N360Space.md),
                            _DeviationNote(l: l),
                          ],
                          const SizedBox(height: N360Space.md),
                          ApprovalDocument(row: row, today: services.context.today),
                          const SizedBox(height: N360Space.md),
                          ApprovalChain(row: row, now: now),
                        ],
                      ),
                    ),
                    if (state.failure != null) _FailureSlip(state: state),
                    if (state.canAct || state.finished)
                      _ActionBar(
                        state: state,
                        onApprove: () => _approve(context),
                        onSendBack: () => _sendBack(context, row),
                      ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _goneView(BuildContext context, AppLocalizations l) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                EmptyLedger(title: l.approvalsGoneTitle, message: l.approvalsGoneBody),
                GloveButton(
                  label: l.approvalsBackToInbox,
                  large: true,
                  fullWidth: true,
                  kind: GloveButtonKind.secondary,
                  onPressed: () => _leave(context),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.row, required this.now});

  final ApprovalRow row;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ApprovalFigure? figure = figureOf(row, l);
    final String project = <String>[?row.projectCode, ?row.projectName].join(' · ');
    final String who = <String>[
      ?row.submittedByName,
      if (row.submittedAt != null) agoText(l, row.submittedAt, now),
    ].join(' · ');
    final Color figureInk = figure?.negative == true ? N360Colors.laterite : N360Colors.ink;

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.md, N360Space.lg, N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (project.isNotEmpty) SectionLabel(project),
          Text(row.title, style: N360Type.title.copyWith(fontSize: 22, height: 1.26)),
          if (figure != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: Text.rich(
                key: const ValueKey<String>('approval-figure'),
                TextSpan(
                  text: figure.value,
                  children: <InlineSpan>[
                    if (figure.unit != null)
                      TextSpan(
                        text: ' ${figure.unit}',
                        style: N360Type.figure.copyWith(color: figureInk, fontSize: 20, fontWeight: FontWeight.w400),
                      ),
                  ],
                ),
                style: N360Type.figureHero.copyWith(color: figureInk),
              ),
            ),
          ],
          const SizedBox(height: N360Space.sm),
          const RuledDivider.double(),
          const SizedBox(height: N360Space.sm),
          Row(
            children: <Widget>[
              const Icon(Icons.person_outline, size: 20, color: N360Colors.ink2),
              const SizedBox(width: 6),
              Expanded(child: Text(who, style: N360Type.bodyStrong.copyWith(height: 1.3))),
            ],
          ),
        ],
      ),
    );
  }
}

class _DeviationNote extends StatelessWidget {
  const _DeviationNote({required this.l});

  final AppLocalizations l;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.sm,
        border: Border.all(color: N360Colors.laterite, width: 1.5),
      ),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.md),
        child: Row(
          children: <Widget>[
            RubberStamp(kind: StampKind.rejected, label: l.approvalsDeviation, size: StampSize.medium),
            const SizedBox(width: N360Space.sm),
            Expanded(child: Text(l.approvalsDeviationBody, style: N360Type.body)),
          ],
        ),
      ),
    );
  }
}

/// A decision that did not land, said plainly, with the next step.
class _FailureSlip extends StatelessWidget {
  const _FailureSlip({required this.state});

  final ApprovalDetailState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ApprovalDetailCubit cubit = context.read<ApprovalDetailCubit>();
    final ApprovalFailure failure = state.failure!;
    final (String title, String? message) = switch (failure) {
      ApprovalFailure.offline => (l.approvalsFailOfflineTitle, l.approvalsFailOfflineBody),
      ApprovalFailure.raceLost => (l.approvalsFailRaceTitle, l.approvalsFailRaceBody),
      ApprovalFailure.session => (l.approvalsFailSessionTitle, l.approvalsFailSessionBody),
      ApprovalFailure.rejected => (l.approvalsFailRejectedTitle, state.failureDetail),
      ApprovalFailure.server => (l.approvalsFailServerTitle, l.approvalsFailServerBody),
    };
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.xs),
      child: ErrorSlip(
        title: title,
        message: message,
        actionLabel: failure == ApprovalFailure.raceLost ? l.approvalsRefresh : null,
        onAction: failure == ApprovalFailure.raceLost ? cubit.refresh : null,
      ),
    );
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({required this.state, required this.onApprove, required this.onSendBack});

  final ApprovalDetailState state;
  final VoidCallback onApprove;
  final VoidCallback onSendBack;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    // Once the server has accepted, the stamp's overlay holds the taps. The
    // buttons keep their look so the page does not change under the stamp.
    final bool enabled = state.actionsEnabled || state.finished;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.paper,
        border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (!state.online) ...<Widget>[
              Row(
                children: <Widget>[
                  const Icon(Icons.cloud_off, size: 20, color: N360Colors.ink2),
                  const SizedBox(width: 6),
                  Expanded(child: Text(l.approvalsNeedsNetwork, style: N360Type.bodyStrong.copyWith(height: 1.3))),
                ],
              ),
              const SizedBox(height: N360Space.sm),
            ],
            Row(
              children: <Widget>[
                Expanded(
                  child: GloveButton(
                    key: const ValueKey<String>('approval-send-back'),
                    label: l.approvalsSendBack,
                    icon: Icons.reply,
                    kind: GloveButtonKind.danger,
                    large: true,
                    fullWidth: true,
                    onPressed: enabled ? onSendBack : null,
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: GloveButton(
                    key: const ValueKey<String>('approval-approve'),
                    label: l.approvalsApprove,
                    icon: Icons.task_alt,
                    large: true,
                    fullWidth: true,
                    loading: state.acting,
                    onPressed: enabled ? onApprove : null,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
