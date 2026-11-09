import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// A page of the measurement book resting at the bottom of the screen, with a
/// perforated top edge and a red margin rule. Full width and square-cornered,
/// so it reads as paper on a desk, not a card.
class LedgerSheet extends StatelessWidget {
  const LedgerSheet({
    super.key,
    required this.child,
    this.marginX = N360Space.margin,
    this.padding = const EdgeInsets.fromLTRB(N360Space.marginText - 4, 22, N360Space.gutter, N360Space.lg),
  });

  final Widget child;

  /// Where the double red rule is drawn, from the left edge.
  final double marginX;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: _LedgerSheetPainter(marginX: marginX),
      child: Padding(padding: padding, child: child),
    );
  }
}

class _LedgerSheetPainter extends CustomPainter {
  const _LedgerSheetPainter({required this.marginX});

  final double marginX;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(Offset.zero & size, Paint()..color = N360Colors.sheet);
    canvas.drawRect(Rect.fromLTWH(0, 0, size.width, 1.5), Paint()..color = N360Colors.ruleStrong);

    final Paint hole = Paint()..color = N360Colors.paper;
    final Paint holeEdge = Paint()
      ..color = N360Colors.rule
      ..style = PaintingStyle.stroke;
    for (double x = 11; x < size.width; x += 20) {
      canvas.drawCircle(Offset(x, 9), 3.5, hole);
      canvas.drawCircle(Offset(x, 9), 3.5, holeEdge);
    }

    final Paint margin = Paint()
      ..color = N360Colors.marginRed
      ..strokeWidth = 1;
    canvas.drawLine(Offset(marginX - 0.5, 18), Offset(marginX - 0.5, size.height), margin);
    canvas.drawLine(Offset(marginX + 2.5, 18), Offset(marginX + 2.5, size.height), margin);
  }

  @override
  bool shouldRepaint(_LedgerSheetPainter oldDelegate) => oldDelegate.marginX != marginX;
}
