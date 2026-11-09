import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/issue_helpers.dart';
import '../bloc/issue_list_cubit.dart';
import '../data/issue_repository.dart';
import 'widgets/issue_widgets.dart';

class IssueListScreen extends StatelessWidget {
  const IssueListScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<IssueListCubit>(
      create: (context) => IssueListCubit(context.read<AppServices>()),
      child: const _IssueListView(),
    );
  }
}

class _IssueListView extends StatelessWidget {
  const _IssueListView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const Expanded(child: _IssueScroll()),
              _RaiseBar(label: l.issuesRaise),
            ],
          ),
        ),
      ),
    );
  }
}

class _IssueScroll extends StatelessWidget {
  const _IssueScroll();

  @override
  Widget build(BuildContext context) {
    return CustomScrollView(
      slivers: <Widget>[
        const SliverToBoxAdapter(child: _Header()),
        const SliverToBoxAdapter(child: _FilterStrip()),
        BlocBuilder<IssueListCubit, IssueListState>(
          buildWhen: (a, b) =>
              a.load != b.load ||
              a.filter != b.filter ||
              !identical(a.visible, b.visible) ||
              a.userNames != b.userNames,
          builder: (context, state) {
            final AppLocalizations l = AppLocalizations.of(context);
            switch (state.load) {
              case IssueListLoad.loading:
                return const SliverToBoxAdapter(child: SizedBox(height: 160));
              case IssueListLoad.noProject:
                return SliverToBoxAdapter(
                  child: EmptyLedger(
                    title: l.issuesNoProjectTitle,
                    message: l.issuesNoProjectMessage,
                  ),
                );
              case IssueListLoad.ready:
                if (state.visible.isEmpty) {
                  return SliverToBoxAdapter(
                    child: state.all.isEmpty
                        ? EmptyLedger(
                            title: l.issuesEmptyTitle,
                            message: l.issuesEmptyMessage,
                            action: GloveButton(
                              label: l.issuesRaise,
                              icon: Icons.add,
                              onPressed: () => context.push(Routes.issueForm),
                            ),
                          )
                        : EmptyLedger(
                            title: l.issuesEmptyFilterTitle,
                            message: l.issuesEmptyFilterMessage,
                          ),
                  );
                }
                return SliverPadding(
                  padding: const EdgeInsets.fromLTRB(
                    N360Space.gutter,
                    N360Space.sm,
                    N360Space.gutter,
                    N360Space.xl,
                  ),
                  sliver: SliverList.builder(
                    itemCount: state.visible.length,
                    itemBuilder: (context, index) {
                      final record = state.visible[index];
                      return StaggeredRise(
                        key: ValueKey<String>(record.record.clientUuid),
                        index: index,
                        child: _IssueRow(
                          record: record,
                          today: state.today,
                          assignee: state.userNames[record.record.assignedTo],
                        ),
                      );
                    },
                  ),
                );
            }
          },
        ),
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<IssueListCubit, IssueListState>(
      buildWhen: (a, b) => a.project?.id != b.project?.id,
      builder: (context, state) {
        return Padding(
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            N360Space.xs,
            N360Space.gutter,
            N360Space.sm,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              if (Navigator.of(context).canPop())
                Align(
                  alignment: Alignment.centerLeft,
                  child: GloveButton(
                    label: l.commonBack,
                    kind: GloveButtonKind.ghost,
                    icon: Icons.arrow_back,
                    onPressed: () => Navigator.of(context).maybePop(),
                  ),
                ),
              SheetHeader(
                eyebrow: state.project?.code ?? l.issuesEyebrow,
                title: l.issuesTitle,
              ),
            ],
          ),
        );
      },
    );
  }
}

class _FilterStrip extends StatelessWidget {
  const _FilterStrip();

  String _label(AppLocalizations l, IssueFilter filter) => switch (filter) {
    IssueFilter.all => l.issuesFilterAll,
    IssueFilter.open => l.pictogramLabel(N360Pictograms.issueStatus(IssueStatus.open)),
    IssueFilter.inProgress => l.pictogramLabel(N360Pictograms.issueStatus(IssueStatus.inProgress)),
    IssueFilter.resolved => l.pictogramLabel(N360Pictograms.issueStatus(IssueStatus.resolved)),
    IssueFilter.closed => l.pictogramLabel(N360Pictograms.issueStatus(IssueStatus.closed)),
  };

  IconData? _icon(IssueFilter filter) {
    final status = filter.status;
    return status == null ? null : N360Pictograms.issueStatus(status).icon;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<IssueListCubit, IssueListState>(
      buildWhen: (a, b) => a.filter != b.filter || !identical(a.all, b.all),
      builder: (context, state) {
        return SizedBox(
          height: 62,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
            itemCount: IssueFilter.values.length,
            separatorBuilder: (_, _) => const SizedBox(width: N360Space.sm),
            itemBuilder: (context, index) {
              final IssueFilter filter = IssueFilter.values[index];
              return Center(
                child: SelectTile(
                  label: _label(l, filter),
                  icon: _icon(filter),
                  trailing: '${state.countFor(filter)}',
                  selected: filter == state.filter,
                  onTap: () => context.read<IssueListCubit>().setFilter(filter),
                ),
              );
            },
          ),
        );
      },
    );
  }
}

class _IssueRow extends StatelessWidget {
  const _IssueRow({
    required this.record,
    required this.today,
    required this.assignee,
  });

  final SyncedRecord<IssueRow> record;
  final String today;
  final String? assignee;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final IssueRow issue = record.record;
    final bool unsent = record.syncState != LocalSyncState.synced;

    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.sm),
      child: LedgerCard(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
        onTap: () => context.push(Routes.issueDetail(issue.clientUuid)),
        semanticsLabel: '${l.pictogramLabel(N360Pictograms.priority(issue.priority))}, ${issue.title}',
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            PriorityMarker(priority: issue.priority),
            const SizedBox(width: 10),
            Expanded(
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
                          child: Text(
                            issue.title,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: N360Type.heading.copyWith(fontSize: 19, height: 1.25),
                          ),
                        ),
                      ),
                      const SizedBox(width: N360Space.sm),
                      IssueStatusStamp(status: issue.status),
                    ],
                  ),
                  const SizedBox(height: 3),
                  if (issue.locationNote != null && issue.locationNote!.isNotEmpty)
                    IssueMeta(icon: Icons.place_outlined, text: issue.locationNote!),
                  DueLine(issue: issue, today: today),
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: IssueMeta(
                          icon: Icons.person_outline,
                          text: assignee ??
                              (issue.assignedTo == null ? l.issuesUnassigned : l.issuesAssignedUnknown),
                        ),
                      ),
                      if (unsent) ...<Widget>[
                        const SizedBox(width: N360Space.xs),
                        IssueSyncStamp(state: record.syncState),
                      ],
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RaiseBar extends StatelessWidget {
  const _RaiseBar({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
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
          child: GloveButton(
            label: label,
            icon: Icons.add,
            large: true,
            fullWidth: true,
            onPressed: () => context.push(Routes.issueForm),
          ),
        ),
      ),
    );
  }
}
