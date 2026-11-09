import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart';
import '../../../projects/view/boq_missing_prompt.dart';
import '../../bloc/boq_picker_cubit.dart';
import '../../bloc/measurement_book.dart';

/// Opens the BOQ line picker. Returns the chosen line, or null.
Future<BoqLine?> showBoqPicker(
  BuildContext context, {
  required AppServices services,
  required int projectId,
  int? selectedId,
}) {
  return showLedgerSheet<BoqLine>(
    context,
    isScrollControlled: true,
    builder: (sheetContext) => BlocProvider<BoqPickerCubit>(
      create: (_) => BoqPickerCubit(services: services, projectId: projectId)..start(),
      child: _BoqPicker(services: services, selectedId: selectedId),
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
  const _BoqPicker({required this.services, this.selectedId});

  final AppServices services;
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
      height: media.size.height * 0.8,
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
                if (state.loading) {
                  return ListView.builder(
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                    physics: const NeverScrollableScrollPhysics(),
                    itemCount: 4,
                    itemBuilder: (context, index) => Padding(
                      padding: const EdgeInsets.only(bottom: N360Space.sm),
                      child: LedgerSkeleton(flat: true, lines: 1, semanticsLabel: l.measurementLoading),
                    ),
                  );
                }
                final List<_Item> items = _items(state, l);
                if (!state.loading && items.isEmpty) {
                  if (state.query.trim().isEmpty) return BoqMissingPrompt(services: services);
                  return SingleChildScrollView(child: EmptyLedger(title: l.measurementNoMatchTitle));
                }
                return StaggeredRise(
                  index: 0,
                  child: ListView.builder(
                    padding: const EdgeInsets.fromLTRB(N360Space.xxs, 0, N360Space.xxs, N360Space.sm),
                    itemCount: items.length,
                    itemBuilder: (context, index) => switch (items[index]) {
                      _Heading(:final text) => _SectionHead(text),
                      _Line(:final line) => Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          if (index > 0 && items[index - 1] is _Line) const RuledDivider(indent: N360Space.card),
                          _BoqRow(
                            line: line,
                            selected: line.item.id == selectedId,
                            onTap: () => Navigator.of(context).pop(line),
                          ),
                        ],
                      ),
                    },
                  ),
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
      padding: const EdgeInsets.fromLTRB(N360Space.card, N360Space.md, N360Space.card, N360Space.xs),
      child: SectionLabel(text),
    );
  }
}

/// One BOQ line: what it is, and how much of it is left. The sheet is already
/// white, so rows sit straight on it and the list stays lazy however long the
/// BOQ is.
class _BoqRow extends StatelessWidget {
  const _BoqRow({required this.line, required this.selected, required this.onTap});

  final BoqLine line;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String balance = formatQty(line.previewBalance);
    final Widget tile = LedgerListTile(
      title: '${line.item.code} · ${line.item.description}',
      subtitle: l.measurementBalance,
      leading: selected ? const Icon(Icons.check, size: N360IconSize.lg, color: N360Colors.laterite) : null,
      semanticsLabel: '${line.item.code}, ${line.item.description}, ${l.measurementBalance} $balance ${line.item.unit}',
      trailing: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(
            balance,
            style: N360Type.figure.copyWith(
              color: line.previewBalance.isNegative ? N360Colors.laterite : N360Colors.ink,
            ),
          ),
          Text(line.item.unit, style: N360Type.caption),
        ],
      ),
      onTap: onTap,
    );
    if (!selected) return tile;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.primaryTint,
        borderRadius: N360Radius.tileRadius,
        border: Border.all(color: N360Colors.primary),
      ),
      child: tile,
    );
  }
}
