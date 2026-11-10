import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/widgets/record_photos.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
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
    showPaperToast(
      context,
      state.problem == IssueDetailProblem.photoFailed ? l.issuesPhotoFailed : l.issuesStatusFailed,
      kind: PaperToastKind.error,
    );
  }

  void _onPhotoSaved(BuildContext context, IssueDetailState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    N360Haptics.success();
    showPaperToast(
      context,
      context.read<AppServices>().offlinePlan.isLocalOnly
          ? l.offlineSavedOnPhone
          : (state.photoSavedWhileOffline ? l.issuesPhotoSavedOffline : l.issuesPhotoSavedOnline),
      kind: state.photoSavedWhileOffline ? PaperToastKind.info : PaperToastKind.success,
      icon: state.photoSavedWhileOffline ? Icons.phone_android : null,
      duration: const Duration(seconds: 3),
    );
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
        BlocListener<IssueDetailCubit, IssueDetailState>(
          listenWhen: (a, b) => a.photoSavedId != b.photoSavedId,
          listener: _onPhotoSaved,
        ),
      ],
      child: Scaffold(
        body: LedgerPaper(
          child: SafeArea(
            top: false,
            bottom: false,
            child: BlocBuilder<IssueDetailCubit, IssueDetailState>(
              builder: (context, state) {
                switch (state.load) {
                  case IssueDetailLoad.loading:
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        FieldTopBar(title: l.issuesTitle),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
                          child: LedgerSkeleton(lines: 3, semanticsLabel: l.issuesLoading),
                        ),
                      ],
                    );
                  case IssueDetailLoad.missing:
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        FieldTopBar(title: l.issuesTitle),
                        Expanded(
                          child: ListView(
                            children: <Widget>[
                              EmptyLedger(
                                title: l.issuesDetailMissingTitle,
                                message: l.issuesDetailMissingMessage,
                              ),
                            ],
                          ),
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
    final bool hasDescription = issue.description != null && issue.description!.isNotEmpty;
    final List<Widget> flags = issueFlags(
      context,
      issue: issue,
      today: state.today,
      syncState: record.syncState,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        FieldTopBar(title: l.issuesTitle),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.xs,
              N360Space.gutter,
              N360Space.sm,
            ),
            children: <Widget>[
              LedgerCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Expanded(
                          child: LedgerHero(
                            tag: 'issue-title-${issue.clientUuid}',
                            child: Text(issue.title, style: N360Type.fit(N360Type.title, issue.title)),
                          ),
                        ),
                        const SizedBox(width: N360Space.sm),
                        IssueStatusPill(status: issue.status),
                      ],
                    ),
                    if (issue.locationNote != null && issue.locationNote!.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: N360Space.xs),
                        child: IssueMeta(icon: Icons.place_outlined, text: issue.locationNote!),
                      ),
                    if (flags.isNotEmpty) ...<Widget>[
                      const SizedBox(height: N360Space.sm),
                      Wrap(spacing: N360Space.sm, runSpacing: N360Space.xs, children: flags),
                    ],
                  ],
                ),
              ),
              // What is wrong comes before who and when: the description is the
              // part a person reads to decide what to do.
              if (hasDescription) ...<Widget>[
                const SizedBox(height: N360Space.cardGap),
                StaggeredRise(
                  index: 0,
                  child: LedgerCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      mainAxisSize: MainAxisSize.min,
                      children: <Widget>[
                        SectionLabel(l.issuesDetailDescription),
                        const SizedBox(height: N360Space.xs),
                        Text(
                          issue.description!,
                          style: N360Type.fit(N360Type.body, issue.description!),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
              const SizedBox(height: N360Space.cardGap),
              StaggeredRise(
                index: 1,
                child: LedgerCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      LedgerRow(
                        label: l.issuesDetailPriority,
                        valueWidget: PriorityPill(priority: issue.priority),
                      ),
                      LedgerRow(
                        label: l.issuesDetailDue,
                        valueWidget: _DueValue(issue: issue),
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
                            shortDay(context, issue.resolvedAt!),
                            style: N360Type.bodyStrong,
                          ),
                        ),
                    ],
                  ),
                ),
              ),
              if (state.canAddPhotos || state.photos.isNotEmpty) ...<Widget>[
                const SizedBox(height: N360Space.cardGap),
                StaggeredRise(index: 2, child: _PhotosCard(state: state)),
              ],
            ],
          ),
        ),
        _StatusActions(status: issue.status, savingStatus: state.savingStatus),
      ],
    );
  }
}

/// Before and after photos: the server's and those still on the phone.
class _PhotosCard extends StatelessWidget {
  const _PhotosCard({required this.state});

  final IssueDetailState state;

  Future<void> _add(BuildContext context, String kind) async {
    final AppServices services = context.read<AppServices>();
    final IssueDetailCubit cubit = context.read<IssueDetailCubit>();
    final String? path = await services.photos.capture();
    if (path != null) await cubit.addPhoto(kind, path);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final AppServices services = context.read<AppServices>();
    final bool canAdd = state.canAddPhotos;

    Widget group(String kind, String title, String addLabel) {
      final photos = state.photos.where((photo) => photo.photoKind == kind).toList();
      return RecordPhotoGroup(
        key: ValueKey<String>('issue-photos-$kind'),
        title: title,
        photos: photos,
        loadBytes: services.issues.photoBytes,
        localPath: services.context.photos.absolutePath,
        emptyLabel: l.issuesPhotosNone,
        addLabel: addLabel,
        onAdd: canAdd && photos.length < RecordPhotos.maxPerKind ? () => _add(context, kind) : null,
      );
    }

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SectionLabel(l.issuesPhotosTitle),
          const SizedBox(height: N360Space.sm),
          group(IssuePhotoKind.before, l.issuesPhotosBefore, l.issuesPhotoAddBefore),
          const SizedBox(height: N360Space.lg),
          group(IssuePhotoKind.after, l.issuesPhotosAfter, l.issuesPhotoAddAfter),
        ],
      ),
    );
  }
}

class _DueValue extends StatelessWidget {
  const _DueValue({required this.issue});

  final IssueRow issue;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? due = issue.dueDate;
    if (due == null) {
      return Text(l.issuesNoDueDate, style: N360Type.fit(N360Type.body, l.issuesNoDueDate));
    }
    return Text(shortDate(context, due), style: N360Type.fit(N360Type.bodyStrong, shortDate(context, due)));
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

    return FieldActionBar(
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
