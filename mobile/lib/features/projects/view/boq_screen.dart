import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
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
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
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
              SliverToBoxAdapter(
                child: FieldTopBar(title: l.boqTitle, subtitle: state.project?.name),
              ),
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
        var firstIndex = 0;
        return SliverMainAxisGroup(
          slivers: <Widget>[
            for (final section in state.sections) ...() {
              final int start = firstIndex;
              firstIndex += section.lines.length;
              return <Widget>[
                SliverToBoxAdapter(
                  child: _SectionTitle(
                    name: section.name ?? l.boqNoSection,
                    count: section.lines.length,
                  ),
                ),
                _SectionCard(lines: section.lines, firstIndex: start),
              ];
            }(),
          ],
        );
    }
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.name, required this.count});

  final String name;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter + N360Space.xs,
        N360Space.sm,
        N360Space.gutter + N360Space.xs,
        N360Space.sm,
      ),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(name, style: N360Type.fit(N360Type.heading, name))),
          Text('$count', style: N360Type.label.copyWith(color: N360Colors.inkLabel)),
        ],
      ),
    );
  }
}

/// One section on a single white card, with its rows built lazily.
class _SectionCard extends StatelessWidget {
  const _SectionCard({required this.lines, required this.firstIndex});

  final List<BoqLine> lines;

  /// Position of this section's first row in the whole list, for the entrance.
  final int firstIndex;

  @override
  Widget build(BuildContext context) {
    return FieldGroupSliver(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.xs),
      sliver: SliverList.builder(
        itemCount: lines.length,
        itemBuilder: (context, index) {
          final BoqLine line = lines[index];
          return StaggeredRise(
            key: ValueKey<int>(line.item.id),
            index: firstIndex + index,
            maxAnimated: 8,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                if (index > 0) const RuledDivider(),
                _BoqRow(line: line),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _BoqRow extends StatelessWidget {
  const _BoqRow({required this.line});

  final BoqLine line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final item = line.item;
    final MeasuredShare share = measuredShareOf(line);
    final bool over = line.previewBalance.isNegative;
    // More measured than the BOQ allows is said in words, not only in red.
    final String balance = over
        ? l.boqOver(line.previewBalance.abs().format(), item.unit)
        : l.boqBalance(line.previewBalance.format(), item.unit);
    final Color balanceInk = over ? N360Colors.stampRed : N360Colors.ink;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: N360Space.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          _CodeTile(code: item.code),
          const SizedBox(width: N360Space.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  item.description,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.fit(N360Type.bodyStrong, item.description),
                ),
                const SizedBox(height: N360Space.xxs),
                Text(
                  '${item.qty.format()} ${item.unit} × ${item.rate.inr()}',
                  style: N360Type.caption,
                ),
                const SizedBox(height: N360Space.xxs),
                Row(
                  children: <Widget>[
                    if (over) ...<Widget>[
                      Icon(Icons.warning_amber, size: N360IconSize.sm, color: balanceInk),
                      const SizedBox(width: N360Space.xs),
                    ],
                    Flexible(
                      child: Text(
                        balance,
                        style: N360Type.fit(N360Type.label, balance).copyWith(color: balanceInk),
                      ),
                    ),
                  ],
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
    );
  }
}

/// The item code on a tile that is [N360Size.tile] square for a short code and
/// grows a little for a longer one, so the code stays at its real size instead
/// of shrinking under the 12 that nothing on screen goes below.
class _CodeTile extends StatelessWidget {
  const _CodeTile({required this.code});

  final String code;

  static const double _maxWidth = N360Size.tap + N360Space.xs;

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(
        minWidth: N360Size.tile,
        maxWidth: _maxWidth,
        minHeight: N360Size.tile,
      ),
      child: DecoratedBox(
        decoration: const BoxDecoration(color: N360Colors.paper2, borderRadius: N360Radius.md),
        child: Center(
          widthFactor: 1,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.xs + N360Space.xxs),
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(code, maxLines: 1, style: N360Type.figureSmall.copyWith(fontWeight: FontWeight.w500)),
            ),
          ),
        ),
      ),
    );
  }
}
