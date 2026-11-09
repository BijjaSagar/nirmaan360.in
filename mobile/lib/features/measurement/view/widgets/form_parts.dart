import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart';
import '../../bloc/measurement_book.dart';
import '../../bloc/measurement_form_cubit.dart';
import 'book_kit.dart';

/// The chosen BOQ line, heading the page.
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
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          LedgerBackKey(onTap: onBack),
          const SizedBox(width: N360Space.sm),
          Expanded(
            child: chosen == null
                ? _NoLine(l: l, onTap: onChange, spoken: spoken)
                : LedgerCard(
                    flat: true,
                    onTap: onChange,
                    semanticsLabel: spoken,
                    padding: const EdgeInsets.fromLTRB(N360Space.md, 6, N360Space.sm, 7),
                    child: _ChosenLine(line: chosen, l: l, compact: compact),
                  ),
          ),
        ],
      ),
    );
  }
}

class _NoLine extends StatelessWidget {
  const _NoLine({required this.l, required this.onTap, required this.spoken});

  final AppLocalizations l;
  final VoidCallback onTap;
  final String spoken;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: spoken,
      builder: (context, depth, child) => Transform.scale(scale: pressScale(depth), child: child),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: N360Colors.goldTint,
          borderRadius: N360Radius.tileRadius,
          border: Border.all(color: N360Colors.orange, width: 2),
        ),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: N360Size.tap),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.md),
            child: Row(
              children: <Widget>[
                Expanded(
                  child: Text(l.measurementChooseLine, style: N360Type.heading.copyWith(color: N360Colors.laterite)),
                ),
                const Icon(Icons.expand_more, size: 26, color: N360Colors.laterite),
              ],
            ),
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
    final TextStyle figure = N360Type.figureSmall.copyWith(fontSize: 13.5);
    final TextStyle tag = N360Type.caption.copyWith(fontSize: 13, height: 1.25);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(
              child: Text(
                eyebrow,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.caption.copyWith(height: 1.25),
              ),
            ),
            Text(
              l.measurementChange,
              style: N360Type.label.copyWith(color: N360Colors.laterite, fontSize: 14, height: 1.25),
            ),
            const Icon(Icons.expand_more, size: 18, color: N360Colors.laterite),
          ],
        ),
        Text(
          line.item.description,
          maxLines: compact ? 1 : 2,
          overflow: TextOverflow.ellipsis,
          style: N360Type.heading.copyWith(fontSize: 15.5, height: 1.3),
        ),
        Row(
          children: <Widget>[
            Text('${l.measurementBoqQty} ', style: tag),
            Flexible(
              child: Text(formatQty(line.item.qty), maxLines: 1, overflow: TextOverflow.ellipsis, style: figure),
            ),
            const SizedBox(width: N360Space.md),
            Text('${l.measurementBalance} ', style: tag),
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
    );
  }
}

/// Add or Deduct, side by side. Deduct fills with deep orange so a negative row
/// cannot be mistaken for a positive one.
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
          child: ChoiceTile(
            icon: Icons.add,
            label: l.measurementAdd,
            selected: !deduction,
            onTap: () => onChanged(false),
          ),
        ),
        const SizedBox(width: N360Space.sm),
        Expanded(
          child: ChoiceTile(
            icon: Icons.remove,
            label: l.measurementDeduct,
            selected: deduction,
            look: ChoiceLook.deduction,
            onTap: () => onChanged(true),
          ),
        ),
      ],
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
        if (leading != null) ...<Widget>[leading!, const SizedBox(width: 6)],
        tile(DimField.nos, l.measurementNos),
        const SizedBox(width: 6),
        tile(DimField.length, l.measurementLengthShort),
        const SizedBox(width: 6),
        tile(DimField.breadth, l.measurementBreadthShort),
        const SizedBox(width: 6),
        tile(DimField.depth, l.measurementDepthShort),
      ],
    );
  }
}

/// The hero of the page: the live quantity, the line as the book writes it, and
/// how much of the BOQ this leaves. All of it moves on every key.
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
    final Qty? used = state.usedAfter;
    final BoqLine? line = state.line;

    final Widget bigFigure = FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: RollingQty(
        key: const ValueKey<String>('measurement-qty'),
        value: formatQty(state.qty),
        unit: state.unit,
        style: (compact ? N360Type.figureLarge : N360Type.figureHero.copyWith(fontSize: 40)).copyWith(color: qtyInk),
        unitStyle: N360Type.heading.copyWith(color: qtyInk, fontWeight: FontWeight.w500),
      ),
    );

    final Widget gauge = line == null || used == null
        ? _BoqGauge.empty(l: l, compact: compact)
        : _BoqGauge(l: l, line: line, used: used, deviation: state.deviation, compact: compact);
    final Widget balanceRow = _BalanceRow(state: state, l: l);

    if (compact) {
      final String? factors = state.factors;
      return LedgerCard(
        padding: const EdgeInsets.fromLTRB(N360Space.md, 4, N360Space.md, 2),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            SizedBox(
              height: 40,
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
                  ConstrainedBox(constraints: const BoxConstraints(maxWidth: 170), child: bigFigure),
                ],
              ),
            ),
            gauge,
            balanceRow,
          ],
        ),
      );
    }

    final String? formula = state.formula;
    // A deduction can leave less used than nothing; the mark then reads zero.
    final String? percent = line == null || used == null
        ? null
        : (used.isNegative ? '0%' : shareLabel(used, line.item.qty));
    final double fraction = line == null || used == null || used.isNegative
        ? 0
        : (shareFraction(used, line.item.qty) ?? 0);

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.md, N360Space.lg, N360Space.xs + 2),
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
          Row(
            children: <Widget>[
              Expanded(child: bigFigure),
              if (percent != null) ...<Widget>[
                const SizedBox(width: N360Space.sm),
                Semantics(
                  label: l.measurementBoqUsed(percent),
                  child: ExcludeSemantics(
                    child: SquarePercent(fraction: fraction, label: percent, size: 54),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 2),
          gauge,
          const SizedBox(height: 2),
          balanceRow,
        ],
      ),
    );
  }
}

/// BOQ used so far and with this entry, on the hatched bar. Past BOQ plus
/// tolerance the whole bar turns to red hatching.
class _BoqGauge extends StatelessWidget {
  const _BoqGauge({
    required this.l,
    required BoqLine this.line,
    required Qty this.used,
    required this.deviation,
    required this.compact,
  });

  const _BoqGauge.empty({required this.l, required this.compact}) : line = null, used = null, deviation = false;

  final AppLocalizations l;
  final BoqLine? line;
  final Qty? used;
  final bool deviation;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final double height = compact ? 14 : 20;
    final BoqLine? boq = line;
    final Qty? spoken = used;
    if (deviation) {
      return Semantics(
        container: true,
        label: l.measurementDeviation,
        child: ExcludeSemantics(
          child: Padding(
            padding: const EdgeInsets.only(top: 8),
            child: SizedBox(
              height: height,
              width: double.infinity,
              child: const CustomPaint(painter: _OverBoqPainter()),
            ),
          ),
        ),
      );
    }
    final double fraction = boq == null || spoken == null || spoken.isNegative
        ? 0
        : (shareFraction(spoken, boq.item.qty) ?? 0);
    final String? percent = boq == null || spoken == null ? null : shareLabel(spoken, boq.item.qty);
    return HatchedProgressBar(
      fraction: fraction,
      height: height,
      semanticLabel: percent == null ? null : l.measurementBoqUsed(percent),
    );
  }
}

class _OverBoqPainter extends CustomPainter {
  const _OverBoqPainter();

  static const double _step = 11;
  static const double _width = 4.5;

  @override
  void paint(Canvas canvas, Size size) {
    final RRect shape = RRect.fromRectAndRadius(Offset.zero & size, const Radius.circular(10));
    canvas.drawRRect(shape, Paint()..color = N360Colors.accent);
    canvas.save();
    canvas.clipRRect(shape);
    final double h = size.height;
    final Path stripes = Path();
    for (double x = -h; x < size.width + h; x += _step) {
      stripes
        ..moveTo(x, h)
        ..lineTo(x + h, 0)
        ..lineTo(x + h + _width, 0)
        ..lineTo(x + _width, h)
        ..close();
    }
    canvas.drawPath(stripes, Paint()..color = N360Colors.sheet.withValues(alpha: 0.38));
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
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
                style: N360Type.figure.copyWith(fontSize: 18, color: overdrawn ? N360Colors.laterite : N360Colors.ink),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// An urgent strip saying this row goes over BOQ plus tolerance, and what
/// happens next. It warns; it never stops the user saving.
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
          decoration: const BoxDecoration(color: N360Colors.accent, borderRadius: N360Radius.md),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: 6),
            child: Row(
              children: <Widget>[
                const Icon(Icons.warning_amber, size: 24, color: N360Colors.ink),
                const SizedBox(width: N360Space.sm),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: <InlineSpan>[
                        TextSpan(
                          text: '${l.measurementDeviation}. ',
                          style: N360Type.bodyStrong.copyWith(fontWeight: FontWeight.w700),
                        ),
                        TextSpan(text: sentence),
                      ],
                    ),
                    style: N360Type.bodyStrong.copyWith(height: 1.3),
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
      builder: (context, depth, child) => Transform.scale(
        scale: pressScale(depth),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.tileRadius,
            border: Border.all(color: N360Colors.rule, width: 1.25),
          ),
          child: child,
        ),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
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
                      decoration: BoxDecoration(color: N360Colors.orange, shape: BoxShape.circle),
                    ),
                  ),
                ],
              ],
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2),
              child: Text(
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.caption.copyWith(color: N360Colors.ink, fontSize: 12.5, height: 1.2),
              ),
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
    return SizedBox(
      width: 58,
      child: PressTarget(
        onTap: () => onChanged(!deduction),
        haptic: N360Haptics.select,
        minHeight: 72,
        semanticsLabel: word,
        builder: (context, depth, child) => Transform.scale(
          scale: pressScale(depth),
          child: DecoratedBox(
            decoration: deduction
                ? const BoxDecoration(color: N360Colors.orange, borderRadius: N360Radius.md)
                : const BoxDecoration(gradient: N360Gradients.gold, borderRadius: N360Radius.md),
            child: child,
          ),
        ),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 72),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Icon(deduction ? Icons.remove : Icons.add, size: 24, color: N360Colors.ink),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 3),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(word, style: N360Type.button.copyWith(fontSize: 14, height: 1.25)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
