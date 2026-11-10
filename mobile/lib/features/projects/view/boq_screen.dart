import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../issues/view/widgets/group_slice.dart';
import '../../tenders/data/tender_models.dart';
import '../bloc/boq_cubit.dart';

/// The BOQ of the current project, grouped by section. Adding lines is for
/// those with `boq.manage`; everyone else can read.
class BoqScreen extends StatelessWidget {
  const BoqScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<BoqCubit>(
      create: (context) => BoqCubit(context.read<AppServices>()),
      child: const _BoqView(),
    );
  }
}

class _BoqView extends StatelessWidget {
  const _BoqView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canManage = context.read<AppServices>().auth.can('boq.manage');
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              const _BoqBar(),
              Expanded(child: _BoqScroll(canManage: canManage)),
              if (canManage)
                FieldActionBar(
                  child: GloveButton(
                    label: l.boqAddItem,
                    icon: Icons.add,
                    large: true,
                    fullWidth: true,
                    onPressed: () => context.push(Routes.boqForm),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The bar, named for the project once it is known.
class _BoqBar extends StatelessWidget {
  const _BoqBar();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocSelector<BoqCubit, BoqState, String?>(
      selector: (state) => state.project?.name,
      builder: (context, name) => FieldTopBar(title: l.boqTitle, subtitle: name),
    );
  }
}

class _BoqScroll extends StatelessWidget {
  const _BoqScroll({required this.canManage});

  final bool canManage;

  /// Pulls from the office. When the server cannot be reached the list stays
  /// as it was, and a toast says so rather than leaving the pull unexplained.
  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<BoqCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.boqRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return LedgerRefresh(
      onRefresh: () => _refresh(context),
      semanticsLabel: l.boqLoading,
      child: BlocBuilder<BoqCubit, BoqState>(
        builder: (context, state) {
          return CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
            slivers: <Widget>[
              // With no lines there is nothing to search, so the box stays away.
              if (state.all.isNotEmpty)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(
                      N360Space.gutter,
                      0,
                      N360Space.gutter,
                      N360Space.sm,
                    ),
                    child: GloveTextField(
                      label: l.boqSearchLabel,
                      hint: l.boqSearchHint,
                      prefixIcon: Icons.search,
                      textCapitalization: TextCapitalization.none,
                      textInputAction: TextInputAction.search,
                      onChanged: context.read<BoqCubit>().search,
                    ),
                  ),
                ),
              _body(l, state),
            ],
          );
        },
      ),
    );
  }

  Widget _body(AppLocalizations l, BoqState state) {
    switch (state.load) {
      case BoqLoad.loading:
        return SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.sm,
              N360Space.gutter,
              N360Space.md,
            ),
            child: LedgerSkeletonList(lines: 2, semanticsLabel: l.boqLoading),
          ),
        );
      case BoqLoad.noProject:
        return SliverToBoxAdapter(
          child: EmptyLedger(
            title: l.boqNoProjectTitle,
            message: l.boqNoProjectMessage,
          ),
        );
      case BoqLoad.ready:
        if (state.all.isEmpty) {
          return SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.boqEmptyTitle,
              message: canManage ? l.boqEmptyExplain : l.boqEmptyAsk,
            ),
          );
        }
        if (state.sections.isEmpty) {
          return SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.boqNoMatchTitle,
              message: l.boqNoMatchMessage,
            ),
          );
        }
        return _lines(l, state);
    }
  }

  /// Every heading and every line in one lazily built list, so a BOQ of
  /// thousands of lines builds only the rows on screen.
  Widget _lines(AppLocalizations l, BoqState state) {
    final List<_Entry> entries = <_Entry>[
      for (final section in state.sections) ...<_Entry>[
        _HeadEntry(section.name ?? l.boqNoSection, section.lines.length),
        for (final (index, line) in section.lines.indexed)
          _LineEntry(line, indexInSection: index, last: index == section.lines.length - 1),
      ],
    ];
    return SliverList.builder(
      itemCount: entries.length,
      itemBuilder: (context, index) {
        return switch (entries[index]) {
          _HeadEntry(:final name, :final count) => _SectionHead(name: name, count: count),
          _LineEntry(:final line, :final indexInSection, :final last) => StaggeredRise(
            key: ValueKey<int>(line.item.id),
            index: index,
            maxAnimated: 8,
            child: GroupSlice(
              first: false,
              last: last,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (indexInSection > 0) const RuledDivider(indent: N360Space.card),
                  _BoqRow(line: line),
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

/// A section heading: the first slice of its group.
class _HeadEntry extends _Entry {
  const _HeadEntry(this.name, this.count);

  final String name;
  final int count;
}

class _LineEntry extends _Entry {
  const _LineEntry(this.line, {required this.indexInSection, required this.last});

  final BoqLine line;
  final int indexInSection;

  /// The last line of its section, so the group closes under it.
  final bool last;
}

/// The heading of a section: its name and its line count, over a full-width
/// hairline. It is the top slice of the section's white group.
class _SectionHead extends StatelessWidget {
  const _SectionHead({required this.name, required this.count});

  final String name;
  final int count;

  @override
  Widget build(BuildContext context) {
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
                Expanded(child: Text(name, style: N360Type.fit(N360Type.heading, name))),
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

/// One BOQ line. The code, the work, what the BOQ says, and what is left to
/// measure, with the left-over as a figure on the right edge so the balances of
/// every line line up under each other.
class _BoqRow extends StatelessWidget {
  const _BoqRow({required this.line});

  final BoqLine line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final item = line.item;
    final MeasuredShare share = measuredShareOf(line);
    final bool over = line.previewBalance.isNegative;
    final Color ink = over ? N360Colors.stampRed : N360Colors.ink;
    final String word = over ? l.boqOverLabel : l.boqBalanceLabel;
    final String figure = (over ? line.previewBalance.abs() : line.previewBalance).format();
    final String spoken = over
        ? l.boqOver(figure, item.unit)
        : l.boqBalance(figure, item.unit);
    final String code = l.boqItemCode(item.code);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(code, style: N360Type.fit(N360Type.caption, code)),
                    Text(
                      item.description,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.fit(N360Type.bodyStrong, item.description),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.sm),
              Semantics(
                container: true,
                label: l.boqMeasuredShare(share.percent),
                child: ExcludeSemantics(
                  child: SquarePercent(fraction: share.fraction, label: '${share.percent}%'),
                ),
              ),
            ],
          ),
          const SizedBox(height: N360Space.xxs),
          Text(
            // Site staff are sent quantities only, so a rate would read as zero.
            context.read<AppServices>().auth.can('money.view')
                ? '${item.qty.format()} ${item.unit} × ${item.rate.inr()}'
                : '${item.qty.format()} ${item.unit}',
            style: N360Type.caption,
          ),
          const SizedBox(height: N360Space.xs),
          Semantics(
            container: true,
            label: spoken,
            child: ExcludeSemantics(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: <Widget>[
                  if (over) ...<Widget>[
                    Icon(Icons.warning_amber, size: N360IconSize.sm, color: ink),
                    const SizedBox(width: N360Space.xs),
                  ],
                  Expanded(
                    child: Text(
                      word,
                      style: N360Type.fit(N360Type.label, word).copyWith(color: ink),
                    ),
                  ),
                  Text(figure, style: N360Type.figure.copyWith(color: ink)),
                  const SizedBox(width: N360Space.xs),
                  // A fixed room for the unit keeps the decimal points aligned.
                  ConstrainedBox(
                    constraints: const BoxConstraints(minWidth: N360Space.xxl),
                    child: Text(item.unit, style: N360Type.caption.copyWith(color: ink)),
                  ),
                ],
              ),
            ),
          ),
          if (context.read<BoqCubit>().tenders.canView) _TenderLink(boqItemId: item.id, unit: item.unit),
        ],
      ),
    );
  }
}

/// Under a line: its tenders and quotes, or the way to ask contractors for some.
class _TenderLink extends StatelessWidget {
  const _TenderLink({required this.boqItemId, required this.unit});

  final int boqItemId;
  final String unit;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final TenderMarker? marker = context.select<BoqCubit, TenderMarker?>(
      (cubit) => cubit.state.markers[boqItemId],
    );
    final Money? lowest = marker?.lowestRate;
    final String text = marker == null
        ? l.tenderLinkEmpty
        : <String>[
            l.tenderCount(marker.tenders),
            l.tenderQuoteCount(marker.quotes),
            if (lowest != null) l.tenderLowest(l.tenderPerUnit(lowest.inr(), unit)),
          ].join(' · ');
    return Semantics(
      button: true,
      label: text,
      child: ExcludeSemantics(
        child: InkWell(
          key: ValueKey<String>('boq-tenders-$boqItemId'),
          onTap: () => context.push(Routes.boqTenders(boqItemId)),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: N360Size.tap),
            child: Row(
              children: <Widget>[
                Icon(
                  marker == null ? Icons.request_quote_outlined : Icons.gavel,
                  size: N360IconSize.sm,
                  color: N360Colors.laterite,
                ),
                const SizedBox(width: N360Space.xs),
                Expanded(
                  child: Text(
                    text,
                    style: N360Type.fit(N360Type.label, text).copyWith(color: N360Colors.laterite),
                  ),
                ),
                const Icon(Icons.chevron_right, size: N360IconSize.sm, color: N360Colors.laterite),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
