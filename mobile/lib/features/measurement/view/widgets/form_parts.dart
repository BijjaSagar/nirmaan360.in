import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../core/design/internal/press_target.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart';
import '../../bloc/measurement_book.dart';
import '../../bloc/measurement_form_cubit.dart';
import 'book_kit.dart';

/// The chosen BOQ line, heading the page like the title block of a sheet.
class LineHeader extends StatelessWidget {
  const LineHeader({super.key, required this.line, required this.onBack, required this.onChange, this.compact = false});

  final BoqLine? line;
  final VoidCallback onBack;
  final VoidCallback onChange;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final BoqLine? chosen = line;
    final String spoken = chosen == null
        ? l.measurementChooseLine
        : '${l.measurementChangeLine}. ${chosen.item.code} ${chosen.item.description}, '
              '${l.measurementBoqQty} ${formatQty(chosen.item.qty)} ${chosen.item.unit}, '
              '${l.measurementBalance} ${formatQty(chosen.previewBalance)} ${chosen.item.unit}';

    return Padding(
      padding: const EdgeInsets.fromLTRB(2, N360Space.xs, N360Space.gutter, 0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          LedgerBackKey(onTap: onBack),
          const SizedBox(width: 6),
          Expanded(
            child: PressTarget(
              onTap: onChange,
              haptic: N360Haptics.select,
              semanticsLabel: spoken,
              builder: (context, depth, child) => Transform.translate(offset: Offset(0, depth * 1.5), child: child),
              child: chosen == null ? _NoLine(l: l) : _ChosenLine(line: chosen, l: l, compact: compact),
            ),
          ),
        ],
      ),
    );
  }
}

class _NoLine extends StatelessWidget {
  const _NoLine({required this.l});

  final AppLocalizations l;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 4, bottom: 6),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: N360Colors.sheet,
          borderRadius: N360Radius.sm,
          border: Border.all(color: N360Colors.laterite, width: 2),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.sm + 2),
          child: Row(
            children: <Widget>[
              Expanded(
                child: Text(l.measurementChooseLine, style: N360Type.heading.copyWith(color: N360Colors.stampRed)),
              ),
              const Icon(Icons.expand_more, size: 30, color: N360Colors.stampRed),
            ],
          ),
        ),
      ),
    );
  }
}

class _ChosenLine extends StatelessWidget {
  const _ChosenLine({required this.line, required this.l, required this.compact});

  final BoqLine line;
  final AppLocalizations l;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final String eyebrow = '${l.measurementItem} ${line.item.code} · ${line.item.unit}';
    final bool overdrawn = line.previewBalance.isNegative;
    final TextStyle figure = N360Type.figureSmall.copyWith(fontWeight: FontWeight.w500);

    return Padding(
      padding: const EdgeInsets.only(top: 2, bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              Expanded(
                child: Text(
                  N360Type.eyebrowText(eyebrow),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.eyebrowFor(eyebrow),
                ),
              ),
              Text(
                l.measurementChange,
                style: N360Type.label.copyWith(color: N360Colors.stampRed, fontSize: 14, height: 1.3),
              ),
              const Icon(Icons.expand_more, size: 20, color: N360Colors.stampRed),
            ],
          ),
          Text(
            line.item.description,
            maxLines: compact ? 1 : 2,
            overflow: TextOverflow.ellipsis,
            style: N360Type.heading.copyWith(fontSize: 19, height: 1.25),
          ),
          const SizedBox(height: 2),
          Row(
            children: <Widget>[
              Text('${l.measurementBoqQty} ', style: N360Type.caption),
              Flexible(
                child: Text(formatQty(line.item.qty), maxLines: 1, overflow: TextOverflow.ellipsis, style: figure),
              ),
              const SizedBox(width: N360Space.md),
              Text('${l.measurementBalance} ', style: N360Type.caption),
              Flexible(
                child: Text(
                  formatQty(line.previewBalance),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: figure.copyWith(color: overdrawn ? N360Colors.laterite : N360Colors.ink),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Add or Deduct, as two tiles side by side. Deduct fills with laterite so
/// a negative row cannot be mistaken for a positive one.
class SignToggle extends StatelessWidget {
  const SignToggle({super.key, required this.deduction, required this.onChanged});

  final bool deduction;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Row(
      children: <Widget>[
        Expanded(
          child: _SignTile(
            icon: Icons.add,
            label: l.measurementAdd,
            selected: !deduction,
            fill: N360Colors.ink,
            edge: N360Colors.inkEdge,
            onTap: () => onChanged(false),
          ),
        ),
        const SizedBox(width: N360Space.sm),
        Expanded(
          child: _SignTile(
            icon: Icons.remove,
            label: l.measurementDeduct,
            selected: deduction,
            fill: N360Colors.laterite,
            edge: N360Colors.lateriteEdge,
            onTap: () => onChanged(true),
          ),
        ),
      ],
    );
  }
}

class _SignTile extends StatelessWidget {
  const _SignTile({
    required this.icon,
    required this.label,
    required this.selected,
    required this.fill,
    required this.edge,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final Color fill;
  final Color edge;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final Color ink = selected ? N360Colors.sheet : N360Colors.ink;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: label,
      selected: selected,
      builder: (context, depth, child) {
        final double sink = depth * (N360Size.edge - 1);
        return CustomPaint(
          painter: SheetEdgePainter(
            sink: sink,
            fill: selected ? fill : N360Colors.sheet,
            edge: selected ? edge : N360Colors.paperEdge,
            border: selected ? fill : N360Colors.ruleStrong,
          ),
          child: Padding(
            padding: const EdgeInsets.only(bottom: N360Size.edge),
            child: Transform.translate(offset: Offset(0, sink), child: child),
          ),
        );
      },
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap - N360Size.edge),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(icon, size: 24, color: ink),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.button.copyWith(color: ink, fontSize: 20),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The four tiles of a row, in the order they are read out on site.
class DimensionRow extends StatelessWidget {
  const DimensionRow({super.key, required this.state, required this.onTap, this.leading});

  final MeasurementFormState state;
  final ValueChanged<DimField> onTap;

  /// Shown before the tiles: the Add or Deduct tile on a short phone.
  final Widget? leading;

  /// Metres, when the BOQ unit is one a tape measures.
  String? get _dimensionUnit {
    final String? unit = state.unit?.toLowerCase().trim();
    const Set<String> taped = <String>{'cum', 'sqm', 'rmt', 'rm', 'm', 'm2', 'm3', 'sq.m', 'cu.m', 'mtr'};
    return taped.contains(unit) ? 'm' : null;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? unit = _dimensionUnit;
    Widget tile(DimField field, String label) => Expanded(
      child: DimensionTile(
        key: ValueKey<String>('tile-${field.name}'),
        label: label,
        value: state.text(field),
        placeholder: '—',
        unit: field == DimField.nos ? null : unit,
        active: state.active == field,
        onTap: () => onTap(field),
      ),
    );

    return Row(
      children: <Widget>[
        if (leading != null) ...<Widget>[leading!, const SizedBox(width: 4)],
        tile(DimField.nos, l.measurementNos),
        const SizedBox(width: 4),
        tile(DimField.length, l.measurementLengthShort),
        const SizedBox(width: 4),
        tile(DimField.breadth, l.measurementBreadthShort),
        const SizedBox(width: 4),
        tile(DimField.depth, l.measurementDepthShort),
      ],
    );
  }
}

/// The live quantity, written the way the book writes it, with what the
/// balance becomes. All of it updates on every key.
class QuantityBand extends StatelessWidget {
  const QuantityBand({super.key, required this.state, this.compact = false});

  final MeasurementFormState state;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool negative = state.qty.isNegative || (state.isDeduction && state.nos != null);
    final Color qtyInk = negative ? N360Colors.laterite : N360Colors.ink;
    final TextStyle formulaStyle = N360Type.figureSmall.copyWith(
      color: negative ? N360Colors.laterite : N360Colors.ink2,
    );

    final Widget bigFigure = FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerRight,
      child: RollingQty(
        key: const ValueKey<String>('measurement-qty'),
        value: formatQty(state.qty),
        unit: state.unit,
        style: (compact ? N360Type.figureLarge : N360Type.figureHero).copyWith(color: qtyInk),
        unitStyle: N360Type.figure.copyWith(color: qtyInk, fontSize: compact ? 16 : 20, fontWeight: FontWeight.w400),
      ),
    );

    final Widget balanceRow = _BalanceRow(state: state, l: l);

    if (compact) {
      final String? factors = state.factors;
      return LedgerCard(
        padding: const EdgeInsets.fromLTRB(N360Space.md, 2, N360Space.md, 0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            SizedBox(
              height: 42,
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: FittedBox(
                      alignment: Alignment.centerLeft,
                      fit: BoxFit.scaleDown,
                      child: factors == null
                          ? Text(l.measurementFormulaHint, style: N360Type.caption)
                          : Text(factors, key: const ValueKey<String>('measurement-formula'), style: formulaStyle),
                    ),
                  ),
                  const SizedBox(width: N360Space.sm),
                  ConstrainedBox(constraints: const BoxConstraints(maxWidth: 180), child: bigFigure),
                ],
              ),
            ),
            const RuledDivider(),
            balanceRow,
          ],
        ),
      );
    }

    final String? formula = state.formula;
    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.sm, N360Space.md, N360Space.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SizedBox(
            height: 22,
            child: FittedBox(
              alignment: Alignment.centerLeft,
              fit: BoxFit.scaleDown,
              child: formula == null
                  ? Text(l.measurementFormulaHint, style: N360Type.caption)
                  : Text(formula, key: const ValueKey<String>('measurement-formula'), style: formulaStyle),
            ),
          ),
          Align(alignment: Alignment.centerRight, child: bigFigure),
          const RuledDivider(),
          balanceRow,
        ],
      ),
    );
  }
}

class _BalanceRow extends StatelessWidget {
  const _BalanceRow({required this.state, required this.l});

  final MeasurementFormState state;
  final AppLocalizations l;

  @override
  Widget build(BuildContext context) {
    final Qty? balance = state.balanceAfter;
    final bool overdrawn = balance?.isNegative ?? false;
    return SizedBox(
      height: 30,
      child: Row(
        children: <Widget>[
          Expanded(
            child: Text.rich(
              TextSpan(
                text: l.measurementBalanceAfter,
                children: <InlineSpan>[
                  TextSpan(text: '  ${l.measurementPreviewTag}', style: N360Type.caption.copyWith(fontSize: 13)),
                ],
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: N360Type.label,
            ),
          ),
          const SizedBox(width: N360Space.sm),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 170),
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: RollingQty(
                key: const ValueKey<String>('measurement-balance'),
                value: balance == null ? '—' : formatQty(balance),
                unit: balance == null ? null : state.unit,
                style: N360Type.figure.copyWith(fontSize: 19, color: overdrawn ? N360Colors.laterite : N360Colors.ink),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A stamped warning that this row goes over BOQ plus tolerance. It warns and
/// says what happens next. It never stops the user saving.
class DeviationStrip extends StatelessWidget {
  const DeviationStrip({super.key, required this.tolerancePct});

  final String tolerancePct;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String sentence = l.measurementDeviationBody(tolerancePercentLabel(tolerancePct));
    return Semantics(
      container: true,
      liveRegion: true,
      label: '${l.measurementDeviation}. $sentence',
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.sm,
            border: Border.all(color: N360Colors.laterite, width: 1.5),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.sm, vertical: N360Space.xs),
            child: Row(
              children: <Widget>[
                RubberStamp(
                  kind: StampKind.rejected,
                  label: l.measurementDeviation,
                  size: StampSize.small,
                  animate: true,
                ),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: Text(
                    sentence,
                    style: N360Type.caption.copyWith(color: N360Colors.ink, fontWeight: FontWeight.w600, height: 1.3),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// What this entry still needs, in one short line.
class ProblemLine extends StatelessWidget {
  const ProblemLine({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      liveRegion: true,
      label: message,
      child: ExcludeSemantics(
        child: Row(
          children: <Widget>[
            const Icon(Icons.error_outline, size: 20, color: N360Colors.stampRed),
            const SizedBox(width: 6),
            Expanded(
              child: Text(message, style: N360Type.bodyStrong.copyWith(color: N360Colors.stampRed, height: 1.3)),
            ),
          ],
        ),
      ),
    );
  }
}

/// Opens the optional fields. Shows the date when it is not today, and a dot
/// when a location or note has been written.
class DetailsKey extends StatelessWidget {
  const DetailsKey({super.key, required this.label, required this.spoken, required this.filled, required this.onTap});

  final String label;
  final String spoken;
  final bool filled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: spoken,
      builder: (context, depth, child) {
        final double sink = depth * (N360Size.edge - 1);
        return CustomPaint(
          painter: SheetEdgePainter(sink: sink, border: N360Colors.ruleStrong),
          child: Padding(
            padding: const EdgeInsets.only(bottom: N360Size.edge),
            child: Transform.translate(offset: Offset(0, sink), child: child),
          ),
        );
      },
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap - N360Size.edge),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                const Icon(Icons.edit_note, size: 22, color: N360Colors.ink2),
                if (filled) ...const <Widget>[
                  SizedBox(width: 3),
                  SizedBox.square(
                    dimension: 7,
                    child: DecoratedBox(
                      decoration: BoxDecoration(color: N360Colors.laterite, shape: BoxShape.circle),
                    ),
                  ),
                ],
              ],
            ),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: N360Type.caption.copyWith(color: N360Colors.ink, fontSize: 13, height: 1.2),
            ),
          ],
        ),
      ),
    );
  }
}

/// Add or Deduct as a single tile, for a phone too short for the full row.
class SignFlipTile extends StatelessWidget {
  const SignFlipTile({super.key, required this.deduction, required this.onChanged});

  final bool deduction;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String word = deduction ? l.measurementDeduct : l.measurementAdd;
    final Color fill = deduction ? N360Colors.laterite : N360Colors.ink;
    final Color edge = deduction ? N360Colors.lateriteEdge : N360Colors.inkEdge;
    return SizedBox(
      width: 58,
      child: PressTarget(
        onTap: () => onChanged(!deduction),
        haptic: N360Haptics.select,
        minHeight: 72,
        semanticsLabel: word,
        builder: (context, depth, child) {
          final double sink = depth * (N360Size.edge - 1);
          return CustomPaint(
            painter: SheetEdgePainter(sink: sink, fill: fill, edge: edge, border: fill),
            child: Padding(
              padding: const EdgeInsets.only(bottom: N360Size.edge),
              child: Transform.translate(offset: Offset(0, sink), child: child),
            ),
          );
        },
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 69),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Icon(deduction ? Icons.remove : Icons.add, size: 26, color: N360Colors.sheet),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 2),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    word,
                    style: N360Type.button.copyWith(color: N360Colors.sheet, fontSize: 16, height: 1.2),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
