import 'package:flutter/material.dart';

import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// A large white card with a thin light edge and one soft warm shadow.
class LedgerCard extends StatelessWidget {
  const LedgerCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(N360Space.lg),
    this.onTap,
    this.semanticsLabel,
    this.flat = false,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;

  /// Makes the card pressable.
  final VoidCallback? onTap;
  final String? semanticsLabel;

  /// Drops the shadow. Use it for each row of a long list.
  final bool flat;

  @override
  Widget build(BuildContext context) {
    // A card built by a list builder is a row, and rows carry no shadow, so a
    // long list stays cheap without every screen having to say so.
    final bool lazyRow =
        context.findAncestorWidgetOfExactType<SliverList>()?.delegate is SliverChildBuilderDelegate;
    final SoftSurfacePainter surface = SoftSurfacePainter(
      edge: N360Colors.cardEdge,
      shadow: !flat && !lazyRow,
    );
    final tap = onTap;
    if (tap == null) {
      return CustomPaint(
        painter: surface,
        child: Padding(padding: padding, child: child),
      );
    }
    return PressTarget(
      onTap: tap,
      semanticsLabel: semanticsLabel,
      child: Padding(padding: padding, child: child),
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth * 0.5),
        child: CustomPaint(painter: surface, child: child),
      ),
    );
  }
}

/// A soft rounded tile with a small lower lip, kept for screens that draw their
/// own pressable tiles. The tile sinks by [sink] when pressed.
class SheetEdgePainter extends CustomPainter {
  const SheetEdgePainter({
    this.sink = 0,
    this.fill = N360Colors.sheet,
    this.edge = N360Colors.paperEdge,
    this.border = N360Colors.cardEdge,
    this.edgeHeight = N360Size.edge,
  });

  /// How far the tile has been pressed towards its lip.
  final double sink;
  final Color fill;
  final Color edge;
  final Color border;
  final double edgeHeight;

  @override
  void paint(Canvas canvas, Size size) {
    const radius = Radius.circular(N360Radius.medium);
    final double faceHeight = size.height - edgeHeight;
    canvas.drawRRect(
      RRect.fromLTRBR(0, edgeHeight, size.width, size.height, radius),
      Paint()..color = edge.withValues(alpha: edge.a * 0.6),
    );

    final RRect face = RRect.fromLTRBR(0, sink, size.width, faceHeight + sink, radius);
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

/// One calm line: a label on the left, a value on the right.
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

  /// Adds a very faint separator below the row.
  final bool ruled;

  /// Totals sit on a soft gold wash, with a heavier figure.
  final bool total;
  final TextStyle? valueStyle;

  @override
  Widget build(BuildContext context) {
    final TextStyle figureStyle = valueStyle ??
        (total ? N360Type.figureLarge.copyWith(fontSize: 20) : N360Type.figure);
    final Widget row = Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: <Widget>[
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(label, style: total ? N360Type.bodyStrong : N360Type.body),
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
    );

    if (total) {
      return DecoratedBox(
        decoration: const BoxDecoration(color: N360Colors.goldTint, borderRadius: N360Radius.sm),
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 52),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.sm),
            child: row,
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
            child: row,
          ),
        ),
        if (ruled) const RuledDivider(),
      ],
    );
  }
}

/// A very faint separator, optionally drawn in from the left.
class RuledDivider extends StatefulWidget {
  const RuledDivider({
    super.key,
    this.drawIn = false,
    this.color = N360Colors.rule,
    this.thickness = N360Size.hairline,
  });

  /// Kept for screens that ask for a heavier close. It is now a single rule.
  const RuledDivider.double({
    super.key,
    this.drawIn = false,
    this.color = N360Colors.rule,
    this.thickness = N360Size.hairline,
  });

  final bool drawIn;
  final Color color;
  final double thickness;

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
    final draw = _draw;
    if (draw == null) {
      return SizedBox(
        height: widget.thickness,
        child: CustomPaint(painter: _RulePainter(color: widget.color, thickness: widget.thickness)),
      );
    }
    return RepaintBoundary(
      child: SizedBox(
        height: widget.thickness,
        child: CustomPaint(
          painter: _RulePainter(
            color: widget.color,
            thickness: widget.thickness,
            progress: draw.drive(CurveTween(curve: N360Curves.rise)),
          ),
        ),
      ),
    );
  }
}

class _RulePainter extends CustomPainter {
  _RulePainter({required this.color, required this.thickness, this.progress})
      : super(repaint: progress);

  final Color color;
  final double thickness;
  final Animation<double>? progress;

  @override
  void paint(Canvas canvas, Size size) {
    final double width = size.width * (progress?.value ?? 1);
    if (width <= 0) return;
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(0, 0, width, thickness),
        Radius.circular(thickness / 2),
      ),
      Paint()..color = color,
    );
  }

  @override
  bool shouldRepaint(_RulePainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.progress != progress;
}

/// A title block: a small line above, a title, and an optional trailing widget.
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

  /// Kept so existing screens compile. Headers have no rule now.
  final bool ruled;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.sm),
      child: Row(
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
    );
  }
}

/// A small, calm section label.
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
