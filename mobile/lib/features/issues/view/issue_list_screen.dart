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
import 'widgets/group_slice.dart';
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

  /// Pulls from the office. When the server cannot be reached the list stays
  /// as it was, and a toast says so rather than leaving the pull unexplained.
  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<IssueListCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.issuesRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.issuesLoading,
      child: CustomScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
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
                  return SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(
                        N360Space.gutter,
                        N360Space.sm,
                        N360Space.gutter,
                        N360Space.md,
                      ),
                      child: LedgerSkeletonList(lines: 2, semanticsLabel: l.issuesLoading),
                    ),
                  );
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
                  return _issues(context, state);
              }
            },
          ),
        ],
      ),
    );
  }

  /// Every status heading and every issue in one lazily built list. A project
  /// collects closed issues for years, so the list builds only what is on
  /// screen.
  Widget _issues(BuildContext context, IssueListState state) {
    final List<_Entry> entries = <_Entry>[
      for (final (status, rows) in _sections(state.visible)) ...<_Entry>[
        _HeadEntry(status, rows.length),
        for (final (index, record) in rows.indexed)
          _IssueEntry(record, indexInStatus: index, last: index == rows.length - 1),
      ],
    ];
    return SliverList.builder(
      itemCount: entries.length,
      itemBuilder: (context, index) {
        return switch (entries[index]) {
          _HeadEntry(:final status, :final count) => _StatusHead(status: status, count: count),
          _IssueEntry(:final record, :final indexInStatus, :final last) => StaggeredRise(
            key: ValueKey<String>(record.record.clientUuid),
            index: index,
            child: GroupSlice(
              first: false,
              last: last,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (indexInStatus > 0) const RuledDivider(indent: N360Space.card),
                  IssueTile(
                    record: record,
                    today: state.today,
                    assignee: state.userNames[record.record.assignedTo],
                    onTap: () => context.push(Routes.issueDetail(record.record.clientUuid)),
                  ),
                ],
              ),
            ),
          ),
        };
      },
    );
  }
}

sealed class _Entry {
  const _Entry();
}

/// A status heading: the first slice of its group.
class _HeadEntry extends _Entry {
  const _HeadEntry(this.status, this.count);

  final String status;
  final int count;
}

class _IssueEntry extends _Entry {
  const _IssueEntry(this.record, {required this.indexInStatus, required this.last});

  final SyncedRecord<IssueRow> record;
  final int indexInStatus;

  /// The last issue of its status, so the group closes under it.
  final bool last;
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
    return BlocBuilder<IssueListCubit, IssueListState>(
      buildWhen: (a, b) => a.filter != b.filter || !identical(a.all, b.all),
      builder: (context, state) {
        final AppLocalizations l = AppLocalizations.of(context);
        // Five chips do not fit 360dp, so the strip scrolls; the next chip
        // showing at the edge says so. It takes its height from the chips.
        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            0,
            N360Space.gutter,
            N360Space.sm,
          ),
          child: Row(
            children: <Widget>[
              for (final (index, filter) in IssueFilter.values.indexed) ...<Widget>[
                if (index > 0) const SizedBox(width: N360Space.sm),
                N360Chip(
                  label: _label(l, filter),
                  icon: _icon(filter),
                  count: '${state.countFor(filter)}',
                  selected: filter == state.filter,
                  onTap: () => context.read<IssueListCubit>().setFilter(filter),
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

/// The heading of a status group: the word and how many, over a full-width
/// hairline. It is the top slice of the group's white card.
class _StatusHead extends StatelessWidget {
  const _StatusHead({required this.status, required this.count});

  final String status;
  final int count;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String word = l.pictogramLabel(N360Pictograms.issueStatus(status));
    return GroupSlice(
      first: true,
      last: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.card,
              N360Space.md,
              N360Space.card,
              N360Space.sm,
            ),
            child: Row(
              children: <Widget>[
                Expanded(child: Text(word, style: N360Type.fit(N360Type.heading, word))),
                Text('$count', style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
              ],
            ),
          ),
          const RuledDivider(),
        ],
      ),
    );
  }
}
