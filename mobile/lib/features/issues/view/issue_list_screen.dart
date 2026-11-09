import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
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
              FieldActionBar(
                child: GloveButton(
                  label: l.issuesRaise,
                  icon: Icons.add,
                  large: true,
                  fullWidth: true,
                  onPressed: () => context.push(Routes.issueForm),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One status and its issues, in the order the list shows them.
typedef _Section = (String status, List<SyncedRecord<IssueRow>> rows);

class _IssueScroll extends StatelessWidget {
  const _IssueScroll();

  List<_Section> _sections(List<SyncedRecord<IssueRow>> visible) {
    final byStatus = <String, List<SyncedRecord<IssueRow>>>{};
    for (final record in visible) {
      byStatus.putIfAbsent(record.record.status, () => []).add(record);
    }
    return <_Section>[for (final entry in byStatus.entries) (entry.key, entry.value)];
  }

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
                          )
                        : EmptyLedger(
                            title: l.issuesEmptyFilterTitle,
                            message: l.issuesEmptyFilterMessage,
                          ),
                  );
                }
                var firstIndex = 0;
                return SliverMainAxisGroup(
                  slivers: <Widget>[
                    for (final (status, rows) in _sections(state.visible)) ...() {
                      final int start = firstIndex;
                      firstIndex += rows.length;
                      return <Widget>[
                        SliverToBoxAdapter(
                          child: _SectionTitle(status: status, count: rows.length),
                        ),
                        _IssueGroup(rows: rows, state: state, firstIndex: start),
                      ];
                    }(),
                  ],
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
        return FieldTopBar(
          title: l.issuesTitle,
          subtitle: state.project?.code ?? l.issuesEyebrow,
        );
      },
    );
  }
}

class _FilterStrip extends StatelessWidget {
  const _FilterStrip();

  String _label(AppLocalizations l, IssueFilter filter) {
    final String? status = filter.status;
    return status == null
        ? l.issuesFilterAll
        : l.pictogramLabel(N360Pictograms.issueStatus(status));
  }

  IconData? _icon(IssueFilter filter) {
    final String? status = filter.status;
    return status == null ? null : N360Pictograms.issueStatus(status).icon;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<IssueListCubit, IssueListState>(
      buildWhen: (a, b) => a.filter != b.filter || !identical(a.all, b.all),
      builder: (context, state) {
        return SizedBox(
          height: 66,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
            itemCount: IssueFilter.values.length,
            separatorBuilder: (_, _) => const SizedBox(width: N360Space.sm),
            itemBuilder: (context, index) {
              final IssueFilter filter = IssueFilter.values[index];
              return Center(
                child: FieldChoiceTile(
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

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.status, required this.count});

  final String status;
  final int count;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Pictogram picture = N360Pictograms.issueStatus(status);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter + 4, N360Space.sm, N360Space.gutter + 4, N360Space.sm),
      child: Row(
        children: <Widget>[
          Icon(picture.icon, size: 20, color: N360Colors.ink),
          const SizedBox(width: N360Space.sm),
          Expanded(
            child: Text(l.pictogramLabel(picture), style: N360Type.heading),
          ),
          Text('$count', style: N360Type.bodyStrong.copyWith(color: N360Colors.inkLabel)),
        ],
      ),
    );
  }
}

/// One status group on a single white card, with its rows built lazily.
class _IssueGroup extends StatelessWidget {
  const _IssueGroup({required this.rows, required this.state, required this.firstIndex});

  final List<SyncedRecord<IssueRow>> rows;
  final IssueListState state;

  /// Position of this group's first row in the whole list, for the entrance.
  final int firstIndex;

  @override
  Widget build(BuildContext context) {
    return FieldGroupSliver(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
      sliver: SliverList.builder(
        itemCount: rows.length,
        itemBuilder: (context, index) {
          final SyncedRecord<IssueRow> record = rows[index];
          return StaggeredRise(
            key: ValueKey<String>(record.record.clientUuid),
            index: firstIndex + index,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (index > 0)
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 12),
                    child: RuledDivider(),
                  ),
                _IssueRow(
                  record: record,
                  today: state.today,
                  assignee: state.userNames[record.record.assignedTo],
                ),
              ],
            ),
          );
        },
      ),
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

    return PressTarget(
      onTap: () => context.push(Routes.issueDetail(issue.clientUuid)),
      semanticsLabel: '${l.pictogramLabel(N360Pictograms.priority(issue.priority))}, ${issue.title}',
      builder: (context, depth, child) => Transform.scale(
        scale: 1 - 0.0175 * depth,
        child: Padding(padding: const EdgeInsets.fromLTRB(8, 12, 10, 12), child: child),
      ),
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
                          style: N360Type.heading.copyWith(fontSize: 16, height: 1.3),
                        ),
                      ),
                    ),
                    if (unsent) ...<Widget>[
                      const SizedBox(width: N360Space.xs),
                      IssueSyncStamp(state: record.syncState),
                    ],
                  ],
                ),
                const SizedBox(height: 3),
                if (issue.locationNote != null && issue.locationNote!.isNotEmpty)
                  IssueMeta(icon: Icons.place_outlined, text: issue.locationNote!),
                DueLine(issue: issue, today: today),
                IssueMeta(
                  icon: Icons.person_outline,
                  text: assignee ??
                      (issue.assignedTo == null ? l.issuesUnassigned : l.issuesAssignedUnknown),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
