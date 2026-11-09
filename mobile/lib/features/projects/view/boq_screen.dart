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

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<BoqCubit, BoqState>(
      builder: (context, state) {
        return CustomScrollView(
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          slivers: <Widget>[
            SliverToBoxAdapter(
              child: FieldTopBar(title: l.boqTitle, subtitle: state.project?.name),
            ),
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
    );
  }

  Widget _body(AppLocalizations l, BoqState state) {
    switch (state.load) {
      case BoqLoad.loading:
        return const SliverToBoxAdapter(child: SizedBox(height: 160));
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
        N360Space.gutter + 4,
        N360Space.sm,
        N360Space.gutter + 4,
        N360Space.sm,
      ),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(name, style: N360Type.heading)),
          Text('$count', style: N360Type.bodyStrong.copyWith(color: N360Colors.inkLabel)),
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
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
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

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
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
                  style: N360Type.bodyStrong.copyWith(height: 1.3),
                ),
                const SizedBox(height: 2),
                Text(
                  '${item.qty.format()} ${item.unit} × ${item.rate.inr()}',
                  style: N360Type.caption.copyWith(color: N360Colors.ink2),
                ),
                Text(
                  l.boqBalance(line.previewBalance.format(), item.unit),
                  style: N360Type.bodyStrong.copyWith(
                    fontSize: 15,
                    height: 1.3,
                    color: over ? N360Colors.stampRed : N360Colors.ink,
                  ),
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

class _CodeTile extends StatelessWidget {
  const _CodeTile({required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(
      dimension: 52,
      child: DecoratedBox(
        decoration: const BoxDecoration(color: N360Colors.paper2, borderRadius: N360Radius.md),
        child: Padding(
          padding: const EdgeInsets.all(6),
          child: Center(
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(code, maxLines: 1, style: N360Type.figure.copyWith(fontSize: 16)),
            ),
          ),
        ),
      ),
    );
  }
}
