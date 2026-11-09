import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// A faint section through a building, in the drafting grid's colours, painted
/// once behind the first screens so they read as a drawing, not a blank page.
class SitePlan extends StatelessWidget {
  const SitePlan({super.key});

  @override
  Widget build(BuildContext context) {
    return const RepaintBoundary(child: CustomPaint(painter: _SitePlanPainter(), isComplex: true));
  }
}

class _SitePlanPainter extends CustomPainter {
  const _SitePlanPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final Paint ink = Paint()
      ..color = N360Colors.paperEdge
      ..strokeWidth = 1.2
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.square;
    final Paint soft = Paint()
      ..color = N360Colors.rule
      ..strokeWidth = 1
      ..style = PaintingStyle.stroke;
    final Paint dim = Paint()
      ..color = N360Colors.marginRed
      ..strokeWidth = 1
      ..style = PaintingStyle.stroke;

    final double w = size.width;
    final double h = size.height;
    final double ground = h - 26;
    const int floors = 4;
    final double storey = (ground - 32) / floors;
    final double left = w * 0.14;
    final double right = w * 0.86;
    final double bay = (right - left) / 3;

    // Ground line with earth ticks.
    canvas.drawLine(Offset(0, ground), Offset(w, ground), ink);
    for (double x = 6; x < w; x += 14) {
      canvas.drawLine(Offset(x, ground), Offset(x - 7, ground + 7), soft);
    }

    // Slabs and columns.
    for (var floor = 0; floor <= floors; floor++) {
      final double y = ground - storey * floor;
      canvas.drawRect(Rect.fromLTRB(left - 8, y - 4, right + 8, y), ink);
      if (floor < floors) {
        for (var column = 0; column <= 3; column++) {
          final double x = left + bay * column;
          canvas.drawRect(Rect.fromLTRB(x - 3, y - storey, x + 3, y - 4), soft);
        }
      }
    }

    // Footings below the ground line.
    for (var column = 0; column <= 3; column++) {
      final double x = left + bay * column;
      canvas.drawRect(Rect.fromLTRB(x - 9, ground + 8, x + 9, ground + 14), soft);
      canvas.drawLine(Offset(x, ground), Offset(x, ground + 8), soft);
    }

    // A dimension line up the left side, with ticks at each level.
    final double dx = left - 26;
    canvas.drawLine(Offset(dx, ground), Offset(dx, ground - storey * floors), dim);
    for (var floor = 0; floor <= floors; floor++) {
      final double y = ground - storey * floor;
      canvas.drawLine(Offset(dx - 4, y + 4), Offset(dx + 4, y - 4), dim);
    }

    // A level mark on the right.
    final double mx = right + 26;
    final double my = ground - storey * 2;
    canvas.drawLine(Offset(mx - 12, my), Offset(mx + 12, my), dim);
    final Path mark = Path()
      ..moveTo(mx - 5, my - 9)
      ..lineTo(mx + 5, my - 9)
      ..lineTo(mx, my)
      ..close();
    canvas.drawPath(mark, dim);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
