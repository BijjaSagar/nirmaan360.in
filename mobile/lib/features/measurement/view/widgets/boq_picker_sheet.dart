import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart';
import '../../bloc/boq_picker_cubit.dart';
import '../../bloc/measurement_book.dart';
import 'book_kit.dart';

/// Opens the BOQ line picker. Returns the chosen line, or null.
Future<BoqLine?> showBoqPicker(
  BuildContext context, {
  required AppServices services,
  required int projectId,
  int? selectedId,
}) {
  return showModalBottomSheet<BoqLine>(
    context: context,
    useRootNavigator: true,
    isScrollControlled: true,
    useSafeArea: true,
    sheetAnimationStyle: context.motion.reduced ? AnimationStyle.noAnimation : null,
    builder: (sheetContext) => BlocProvider<BoqPickerCubit>(
      create: (_) => BoqPickerCubit(services: services, projectId: projectId)..start(),
      child: _BoqPicker(selectedId: selectedId),
    ),
  );
}

sealed class _Item {
  const _Item();
}

class _Heading extends _Item {
  const _Heading(this.text);

  final String text;
}

class _Line extends _Item {
  const _Line(this.line);

  final BoqLine line;
}

class _BoqPicker extends StatelessWidget {
  const _BoqPicker({this.selectedId});

  final int? selectedId;

  List<_Item> _items(BoqPickerState state, AppLocalizations l) {
    final List<_Item> items = <_Item>[];
    final List<BoqLine> recent = state.recent;
    if (recent.isNotEmpty) {
      items
        ..add(_Heading(l.measurementRecentLines))
        ..addAll(recent.map(_Line.new));
    }
    final bool searching = state.query.trim().isNotEmpty;
    String? section;
    for (final BoqLine line in state.others) {
      if (!searching) {
        final String here = line.item.section ?? l.measurementAllLines;
        if (here != section) {
          section = here;
          items.add(_Heading(here));
        }
      }
      items.add(_Line(line));
    }
    return items;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MediaQueryData media = MediaQuery.of(context);
    return SizedBox(
      height: media.size.height * 0.84,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.md, N360Space.xs),
            child: Row(
              children: <Widget>[
                Expanded(child: Text(l.measurementChooseLine, style: N360Type.title)),
                GloveButton(
                  label: l.measurementClose,
                  kind: GloveButtonKind.ghost,
                  onPressed: () => Navigator.of(context).maybePop(),
                ),
              ],
            ),
          ),
          Expanded(
            child: BlocBuilder<BoqPickerCubit, BoqPickerState>(
              builder: (context, state) {
                final List<_Item> items = _items(state, l);
                if (!state.loading && items.isEmpty) {
                  return SingleChildScrollView(
                    child: EmptyLedger(
                      title: state.query.trim().isEmpty ? l.measurementNoBoqTitle : l.measurementNoMatchTitle,
                      message: state.query.trim().isEmpty ? l.measurementNoBoqBody : null,
                    ),
                  );
                }
                return ListView.builder(
                  padding: const EdgeInsets.only(bottom: N360Space.sm),
                  itemCount: items.length,
                  itemBuilder: (context, index) => switch (items[index]) {
                    _Heading(:final text) => _SectionHead(text),
                    _Line(:final line) => StaggeredRise(
                      index: index,
                      maxAnimated: 8,
                      child: _BoqRow(
                        line: line,
                        selected: line.item.id == selectedId,
                        onTap: () => Navigator.of(context).pop(line),
                      ),
                    ),
                  },
                );
              },
            ),
          ),
          Padding(
            padding: EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.sm,
              N360Space.gutter,
              N360Space.md + media.viewInsets.bottom,
            ),
            child: Builder(
              builder: (context) => GloveTextField(
                label: l.measurementSearchLines,
                hint: l.measurementSearchHint,
                prefixIcon: Icons.search,
                textCapitalization: TextCapitalization.none,
                onChanged: context.read<BoqPickerCubit>().search,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionHead extends StatelessWidget {
  const _SectionHead(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.xs),
      child: SectionLabel(text),
    );
  }
}

class _BoqRow extends StatelessWidget {
  const _BoqRow({required this.line, required this.selected, required this.onTap});

  final BoqLine line;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = line.item.unit;
    final String balance = formatQty(line.previewBalance);
    final Color balanceInk = line.previewBalance.isNegative ? N360Colors.laterite : N360Colors.ink;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter - 4, vertical: 2),
      child: PressTarget(
        onTap: onTap,
        haptic: N360Haptics.select,
        minHeight: 76,
        selected: selected,
        semanticsLabel: '${line.item.code} ${line.item.description}. ${l.measurementBalance} $balance $unit',
        builder: (context, depth, child) => Transform.scale(
          scale: pressScale(depth),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: selected ? N360Colors.goldTint : N360Colors.sheet,
              borderRadius: N360Radius.tileRadius,
            ),
            child: child,
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(4, N360Space.sm, 4, N360Space.sm),
          child: Row(
            children: <Widget>[
              CodeTile(code: line.item.code),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(
                      line.item.description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.bodyStrong.copyWith(height: 1.3),
                    ),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.baseline,
                      textBaseline: TextBaseline.alphabetic,
                      children: <Widget>[
                        Text(unit, style: N360Type.caption),
                        const Spacer(),
                        Text('${l.measurementBalance} ', style: N360Type.caption),
                        Text(
                          balance,
                          style: N360Type.figureSmall.copyWith(color: balanceInk, fontWeight: FontWeight.w500),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (selected) ...const <Widget>[
                SizedBox(width: N360Space.sm),
                Icon(Icons.check, size: 24, color: N360Colors.ink),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
