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
        if (state.loading) {
          return Scaffold(
            backgroundColor: Colors.transparent,
            body: LedgerPaper(
              child: SafeArea(
                child: Padding(
                  padding: const EdgeInsets.all(N360Space.gutter),
                  child: LedgerSkeleton(figure: true, lines: 3, semanticsLabel: l.measurementLoading),
                ),
              ),
            ),
          );
        }
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
                top: false,
                bottom: false,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    BookTitleBar(title: docTypeWord(l, row.docType), onBack: () => _leave(context)),
                    if (!state.online) OfflineBanner(label: l.approvalsOffline, detail: l.approvalsOfflineDetail),
                    Expanded(
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(N360Space.gutter, 6, N360Space.gutter, N360Space.lg),
                        children: <Widget>[
                          _Hero(row: row, now: now, share: shareOf(row, state.boq)),
                          if (isDeviationDoc(row)) ...<Widget>[
                            const SizedBox(height: N360Space.md),
                            TintedNote(
                              icon: Icons.warning_amber,
                              ink: N360Colors.stampRed,
                              edge: N360Colors.stampRed,
                              title: l.approvalsDeviation,
                              body: l.approvalsDeviationBody,
                            ),
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
  const _Hero({required this.row, required this.now, this.share});

  final ApprovalRow row;
  final DateTime now;
  final ApprovalShare? share;

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
    final TextStyle heroStyle = N360Type.figureHero.copyWith(color: figureInk);
    final ApprovalShare? portion = share;
    final String? formula = formulaOf(row);

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(docTypeIcon(row.docType), size: N360IconSize.lg, color: N360Colors.ink2),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    if (project.isNotEmpty)
                      Text(project, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
                    Text(headlineOf(row), style: N360Type.heading),
                  ],
                ),
              ),
            ],
          ),
          if (figure != null) ...<Widget>[
            const SizedBox(height: N360Space.md),
            Row(
              children: <Widget>[
                Expanded(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: isMoneyDoc(row.docType)
                        ? RollingMoney(
                            key: const ValueKey<String>('approval-figure'),
                            value: figure.value,
                            style: heroStyle,
                            animateEntry: true,
                          )
                        : RollingQty(
                            key: const ValueKey<String>('approval-figure'),
                            value: figure.value,
                            unit: figure.unit,
                            style: heroStyle,
                            unitStyle: N360Type.heading.copyWith(color: figureInk, fontWeight: FontWeight.w500),
                            animateEntry: true,
                          ),
                  ),
                ),
                if (portion != null) ...<Widget>[
                  const SizedBox(width: N360Space.sm),
                  Semantics(
                    label: '${portion.label} ${l.measurementShareOfBoq}',
                    child: ExcludeSemantics(
                      child: SquarePercent(fraction: portion.fraction, label: portion.label),
                    ),
                  ),
                ],
              ],
            ),
          ],
          if (formula != null)
            FittedBox(
              alignment: Alignment.centerLeft,
              fit: BoxFit.scaleDown,
              child: Text(formula, style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
            ),
          const SizedBox(height: N360Space.sm),
          Row(
            children: <Widget>[
              const Icon(Icons.person_outline, size: N360IconSize.md, color: N360Colors.ink2),
              const SizedBox(width: 6),
              Expanded(child: Text(who, style: N360Type.bodyStrong)),
              if (row.status == 'IN_REVIEW') StatusPill(kind: StampKind.inReview, label: l.statusInReview),
            ],
          ),
        ],
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
    return N360ActionBar(
      above: !state.online
          ? Row(
              children: <Widget>[
                const Icon(Icons.cloud_off, size: N360IconSize.md, color: N360Colors.ink2),
                const SizedBox(width: 6),
                Expanded(child: Text(l.approvalsNeedsNetwork, style: N360Type.bodyStrong.copyWith(height: 1.3))),
              ],
            )
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
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
    );
  }
}
