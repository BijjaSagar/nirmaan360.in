import 'package:flutter/material.dart';

import '../internal/press_target.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// A loose sheet on the desk. Depth comes from the visible edge of the sheet
/// beneath it, never from a soft shadow.
class LedgerCard extends StatelessWidget {
  const LedgerCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(N360Space.lg),
    this.onTap,
    this.semanticsLabel,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;

  /// Makes the sheet pressable. A tappable sheet sinks onto the one below it.
  final VoidCallback? onTap;
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) {
    final Widget content = Padding(padding: padding, child: child);
    final tap = onTap;
    if (tap == null) {
      return CustomPaint(
        painter: const SheetEdgePainter(),
        child: Padding(padding: const EdgeInsets.only(bottom: N360Size.edge), child: content),
      );
    }
    return PressTarget(
      onTap: tap,
      semanticsLabel: semanticsLabel,
      child: content,
      builder: (context, depth, child) {
        final double sink = depth * (N360Size.edge - 1);
        return CustomPaint(
          painter: SheetEdgePainter(sink: sink),
          child: Padding(
            padding: const EdgeInsets.only(bottom: N360Size.edge),
            child: Transform.translate(offset: Offset(0, sink), child: child),
          ),
        );
      },
    );
  }
}

/// Paints a sheet with the edge of the page below showing under it.
class SheetEdgePainter extends CustomPainter {
  const SheetEdgePainter({
    this.sink = 0,
    this.fill = N360Colors.sheet,
    this.edge = N360Colors.paperEdge,
    this.border = N360Colors.rule,
    this.edgeHeight = N360Size.edge,
  });

  /// How far the top sheet has been pressed towards the one below.
  final double sink;
  final Color fill;
  final Color edge;
  final Color border;
  final double edgeHeight;

  @override
  void paint(Canvas canvas, Size size) {
    const radius = Radius.circular(N360Radius.small);
    final faceHeight = size.height - edgeHeight;
    final underside = RRect.fromLTRBR(0, edgeHeight, size.width, size.height, radius);
    canvas.drawRRect(underside, Paint()..color = edge);

    final face = RRect.fromLTRBR(0, sink, size.width, faceHeight + sink, radius);
    canvas.drawRRect(face, Paint()..color = fill);
    canvas.drawRRect(
      face.deflate(0.5),
      Paint()
        ..color = border
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1,
    );
  }

  @override
  bool shouldRepaint(SheetEdgePainter oldDelegate) =>
      oldDelegate.sink != sink ||
      oldDelegate.fill != fill ||
      oldDelegate.edge != edge ||
      oldDelegate.border != border ||
      oldDelegate.edgeHeight != edgeHeight;
}

/// One ledger line: a label on the left, a figure ruled off on the right.
class LedgerRow extends StatelessWidget {
  const LedgerRow({
    super.key,
    required this.label,
    this.value,
    this.valueWidget,
    this.hint,
    this.ruled = true,
    this.total = false,
    this.valueStyle,
  }) : assert(value != null || valueWidget != null);

  final String label;

  /// A formatted figure. Use [valueWidget] for rolling numbers and stamps.
  final String? value;
  final Widget? valueWidget;

  /// A smaller line under the label.
  final String? hint;
  final bool ruled;

  /// Totals get a double rule beneath, as in a paper ledger.
  final bool total;
  final TextStyle? valueStyle;

  @override
  Widget build(BuildContext context) {
    final TextStyle figureStyle = valueStyle ??
        (total ? N360Type.figure.copyWith(fontSize: 20) : N360Type.figure);
    final labelText = total ? label.toUpperCase() : label;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 46),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        total ? N360Type.eyebrowText(labelText) : labelText,
                        style: total ? N360Type.label.copyWith(color: N360Colors.ink) : N360Type.body,
                      ),
                      if (hint != null) Text(hint!, style: N360Type.caption),
                    ],
                  ),
                ),
                const SizedBox(width: N360Space.md),
                DefaultTextStyle.merge(
                  style: figureStyle,
                  textAlign: TextAlign.right,
                  child: valueWidget ?? Text(value!, style: figureStyle),
                ),
              ],
            ),
          ),
        ),
        if (total)
          const RuledDivider.double()
        else if (ruled)
          const RuledDivider(),
      ],
    );
  }
}

/// A hairline, optionally drawn in from the left like the web's `rule`.
class RuledDivider extends StatefulWidget {
  const RuledDivider({
    super.key,
    this.drawIn = false,
    this.color = N360Colors.rule,
    this.thickness = N360Size.hairline,
  }) : _double = false;

  /// Two rules, the way a ledger closes a total.
  const RuledDivider.double({
    super.key,
    this.drawIn = false,
    this.color = N360Colors.ink2,
    this.thickness = N360Size.hairline,
  }) : _double = true;

  final bool drawIn;
  final Color color;
  final double thickness;
  final bool _double;

  @override
  State<RuledDivider> createState() => _RuledDividerState();
}

class _RuledDividerState extends State<RuledDivider> with SingleTickerProviderStateMixin {
  AnimationController? _draw;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (widget.drawIn && _draw == null && !context.motion.reduced) {
      _draw = AnimationController(vsync: this, duration: context.motion.rule)..forward();
    }
  }

  @override
  void dispose() {
    _draw?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final double height = widget._double ? widget.thickness * 2 + 2 : widget.thickness;
    final draw = _draw;
    if (draw == null) {
      return SizedBox(
        height: height,
        child: CustomPaint(
          painter: _RulePainter(color: widget.color, thickness: widget.thickness, doubled: widget._double),
        ),
      );
    }
    return RepaintBoundary(
      child: SizedBox(
        height: height,
        child: CustomPaint(
          painter: _RulePainter(
            color: widget.color,
            thickness: widget.thickness,
            doubled: widget._double,
            progress: draw.drive(CurveTween(curve: N360Curves.rise)),
          ),
        ),
      ),
    );
  }
}

class _RulePainter extends CustomPainter {
  _RulePainter({required this.color, required this.thickness, required this.doubled, this.progress})
      : super(repaint: progress);

  final Color color;
  final double thickness;
  final bool doubled;
  final Animation<double>? progress;

  @override
  void paint(Canvas canvas, Size size) {
    final width = size.width * (progress?.value ?? 1);
    if (width <= 0) return;
    final paint = Paint()..color = color;
    canvas.drawRect(Rect.fromLTWH(0, 0, width, thickness), paint);
    if (doubled) {
      canvas.drawRect(Rect.fromLTWH(0, thickness + 2, width, thickness), paint);
    }
  }

  @override
  bool shouldRepaint(_RulePainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.progress != progress;
}

/// A sheet's title block: eyebrow, Khand title, optional stamp, double rule.
class SheetHeader extends StatelessWidget {
  const SheetHeader({
    super.key,
    required this.title,
    this.eyebrow,
    this.trailing,
    this.ruled = true,
  });

  final String title;
  final String? eyebrow;
  final Widget? trailing;
  final bool ruled;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  if (eyebrow != null) SectionLabel(eyebrow!),
                  Text(title, style: N360Type.title),
                ],
              ),
            ),
            if (trailing != null) ...<Widget>[
              const SizedBox(width: N360Space.md),
              trailing!,
            ],
          ],
        ),
        if (ruled) ...const <Widget>[
          SizedBox(height: N360Space.sm),
          SizedBox(height: 1.5, child: ColoredBox(color: N360Colors.ink)),
          SizedBox(height: 2),
          RuledDivider(),
        ],
      ],
    );
  }
}

/// The web's eyebrow: small tracked mono caps. Devanagari sets as Mukta with no
/// tracking so the words stay joined.
class SectionLabel extends StatelessWidget {
  const SectionLabel(this.text, {super.key, this.color});

  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final style = N360Type.eyebrowFor(text);
    return Text(
      N360Type.eyebrowText(text),
      style: color == null ? style : style.copyWith(color: color),
    );
  }
}
