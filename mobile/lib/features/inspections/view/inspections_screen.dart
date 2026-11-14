import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../../sync_center/data/sync_repository.dart' show OutboxItem;
import '../../sync_center/view/sync_labels.dart';
import '../bloc/inspections_cubit.dart';
import '../data/inspection_repository.dart';
import 'inspection_words.dart';

/// Checklists filled in on site. An inspection is started from a checklist,
/// marked item by item on the phone, and finished: it is then saved here and
/// sent when there is a network, and once sent it can no longer be changed.
class InspectionsScreen extends StatelessWidget {
  const InspectionsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<InspectionsCubit>(
      create: (context) => InspectionsCubit(context.read<AppServices>()),
      child: const _InspectionsView(),
    );
  }
}

class _InspectionsView extends StatelessWidget {
  const _InspectionsView();

  Future<void> _start(BuildContext context, InspectionsState state) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final InspectionsCubit cubit = context.read<InspectionsCubit>();
    final GoRouter router = GoRouter.of(context);
    final InspectionOverview? overview = state.overview;
    if (overview == null) return;
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.inspectionsChecklistSearch,
      emptyTitle: l.inspectionsSearchNone,
      options: <FieldPickOption>[
        for (final ChecklistTemplate row in overview.templates)
          if (row.items.isNotEmpty)
            FieldPickOption(
              id: row.id,
              title: row.name,
              subtitle: <String>[?l.checklistCategoryWord(row.category), l.inspectionsItemCount(row.items.length)].join(' · '),
            ),
      ],
    );
    if (id == null) return;
    final String? uuid = await cubit.start(id);
    if (uuid == null) return;
    router.push(Uri(path: Routes.inspectionFill, queryParameters: <String, String>{'uuid': uuid}).toString());
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canFill = context.select((AppGate gate) => gate.state.canInspect);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<InspectionsCubit, InspectionsState>(
            builder: (context, state) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.inspectionsTitle, subtitle: state.project?.code),
                  const Expanded(child: _InspectionsScroll()),
                  if (canFill && state.canStart)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('inspections-start'),
                        label: l.inspectionsStart,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => _start(context, state),
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
}

class _InspectionsScroll extends StatelessWidget {
  const _InspectionsScroll();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<InspectionsCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.inspectionsLoading,
      child: BlocBuilder<InspectionsCubit, InspectionsState>(
        builder: (context, state) => CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: _slivers(context, l, state),
        ),
      ),
    );
  }

  List<Widget> _slivers(BuildContext context, AppLocalizations l, InspectionsState state) {
    SliverToBoxAdapter empty(String title, String message) =>
        SliverToBoxAdapter(child: EmptyLedger(title: title, message: message));
    Widget skeleton() => SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.inspectionsLoading),
      ),
    );
    Widget heading(String text) => SliverToBoxAdapter(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.sm),
        child: SectionLabel(text),
      ),
    );

    if (state.load == InspectionsLoad.loading) return <Widget>[skeleton()];
    if (state.load == InspectionsLoad.noProject) {
      return <Widget>[empty(l.progressNoProjectTitle, l.progressNoProjectMessage)];
    }
    final InspectionOverview? overview = state.overview;
    // A draft belongs to this phone, so it is shown even before anything was read.
    if (overview == null && state.drafts.isEmpty) {
      return <Widget>[if (state.refreshing) skeleton() else empty(l.progressNoDataTitle, l.inspectionsNoDataMessage)];
    }
    if (overview != null && overview.refused && state.drafts.isEmpty) {
      return <Widget>[empty(l.inspectionsRefusedTitle, l.inspectionsRefusedMessage)];
    }

    final List<Inspection> rows = overview?.inspections ?? const <Inspection>[];
    return <Widget>[
      if (state.drafts.isNotEmpty) ...<Widget>[
        heading(l.inspectionsDraftsHeading),
        _List(
          count: state.drafts.length,
          builder: (context, index) => _DraftTile(draft: state.drafts[index]),
        ),
      ],
      if (rows.isEmpty && state.drafts.isEmpty)
        empty(l.inspectionsEmptyTitle, l.inspectionsEmptyMessage)
      else if (rows.isNotEmpty) ...<Widget>[
        heading(l.inspectionsRecentHeading),
        _List(
          count: rows.length,
          builder: (context, index) => _InspectionTile(row: rows[index], overview: overview!),
        ),
        if (overview!.more)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
              child: Text(l.inspectionsLatestOnly, style: N360Type.fit(N360Type.caption, l.inspectionsLatestOnly)),
            ),
          ),
      ],
      const SliverToBoxAdapter(child: SizedBox(height: N360Space.lg)),
    ];
  }
}

class _List extends StatelessWidget {
  const _List({required this.count, required this.builder});

  final int count;
  final IndexedWidgetBuilder builder;

  @override
  Widget build(BuildContext context) {
    return FieldGroupSliver(
      padding: EdgeInsets.zero,
      sliver: SliverList.builder(
        itemCount: count,
        itemBuilder: (context, index) => Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (index > 0) const RuledDivider(indent: N360Space.card),
            builder(context, index),
          ],
        ),
      ),
    );
  }
}

/// One row: a title with the state under it in words, and anything the server
/// said when it refused. At least [N360Size.tap] tall.
class _Row extends StatelessWidget {
  const _Row({
    super.key,
    required this.title,
    required this.status,
    required this.onTap,
    this.detail = '',
    this.note,
    this.semantics,
  });

  final String title;
  final String detail;
  final Widget status;
  final String? note;
  final VoidCallback onTap;
  final String? semantics;

  @override
  Widget build(BuildContext context) {
    final Widget body = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: Text(title, style: N360Type.fit(N360Type.bodyStrong, title))),
              const SizedBox(width: N360Space.xs),
              const Icon(Icons.chevron_right, size: N360IconSize.md, color: N360Colors.ink2),
            ],
          ),
          if (detail.isNotEmpty) Text(detail, style: N360Type.fit(N360Type.caption, detail)),
          const SizedBox(height: N360Space.xs),
          Align(alignment: Alignment.centerLeft, child: status),
          if (note != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(note!, style: N360Type.fit(N360Type.body, note!).copyWith(color: N360Colors.laterite)),
          ],
        ],
      ),
    );
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: semantics ?? title,
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: ConstrainedBox(constraints: const BoxConstraints(minHeight: N360Size.tap), child: body),
    );
  }
}

class _DraftTile extends StatelessWidget {
  const _DraftTile({required this.draft});

  final InspectionDraft draft;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final String detail = <String>[
      l.inspectionFillMarked(draft.markedCount, draft.items.length),
      if (draft.location.trim().isNotEmpty) draft.location.trim(),
      dayLabel(context, draft.inspectedOn, today),
    ].join(' · ');
    return _Row(
      key: ValueKey<String>('inspection-draft-${draft.uuid}'),
      title: draft.templateName,
      detail: detail,
      status: StatusPill(kind: StampKind.draft, label: l.inspectionsDraftWord),
      semantics: '${draft.templateName}. $detail. ${l.inspectionsDraftWord}',
      onTap: () => context.push(
        Uri(path: Routes.inspectionFill, queryParameters: <String, String>{'uuid': draft.uuid}).toString(),
      ),
    );
  }
}

class _InspectionTile extends StatelessWidget {
  const _InspectionTile({required this.row, required this.overview});

  final Inspection row;
  final InspectionOverview overview;

  /// The server's own words when it refused the inspection.
  String? _refusal(AppLocalizations l) {
    final OutboxRow? outbox = row.unsent;
    if (outbox == null || !row.needsAttention) return null;
    if (outbox.attentionCode == AttentionCode.validation && (outbox.attentionReason?.isNotEmpty ?? false)) {
      return outbox.attentionReason;
    }
    return l.syncReasonFor(OutboxItem(row: outbox, isBlocked: false));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    // The list carries no items. The latest few were read in full.
    final Inspection full = overview.details[row.id] ?? row;
    final int failed = full.count(ItemResult.fail);
    final String detail = <String>[
      if (row.inspectedOn.isNotEmpty) dayLabel(context, row.inspectedOn, today),
      ?row.location,
      if (failed > 0) l.inspectionsFailedCount(failed),
    ].join(' · ');
    final String? refusal = _refusal(l);
    return _Row(
      key: ValueKey<String>('inspection-${row.key}'),
      title: row.title,
      detail: detail,
      status: StatusPill(kind: inspectionStampKind(row), label: l.inspectionStatusWord(row)),
      note: refusal,
      semantics: <String>[row.title, detail, l.inspectionStatusWord(row), ?refusal].where((String s) => s.isNotEmpty).join('. '),
      onTap: () => row.needsAttention
          ? context.go(Routes.outbox)
          : context.push(Routes.inspectionDetail(row.key)),
    );
  }
}
