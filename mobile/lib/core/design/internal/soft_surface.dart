import 'package:flutter/widgets.dart';

import '../tokens.dart';

export '../motion.dart' show pressedScale;

/// Draws a card's one soft warm shadow with no blur filter.
///
/// Thin rings stepping outward with falling alpha read as a soft shadow on a
/// light page. The rings only cover the visible halo, so nothing is drawn twice
/// and a screen of cards stays cheap on a low-end GPU.
void paintSoftShadow(
  Canvas canvas,
  RRect shape, {
  double spread = 18,
  double drop = 10,
  double strength = 1,
  int steps = 10,
}) {
  final Paint paint = Paint();
  var inner = shape;
  for (var i = 1; i <= steps; i++) {
    final double t = i / steps;
    final double falloff = 1 - (i - 0.5) / steps;
    final double grow = spread * t;
    final RRect outer = RRect.fromLTRBR(
      shape.left - grow,
      shape.top - grow * 0.3,
      shape.right + grow,
      shape.bottom + grow + drop * t,
      Radius.circular(shape.tlRadiusX + grow),
    );
    paint.color = N360Colors.shadow.withValues(alpha: 0.14 * strength * falloff * falloff);
    canvas.drawDRRect(outer, inner, paint);
    inner = outer;
  }
}

/// A rounded surface: solid or gradient fill, a thin edge, an optional soft
/// shadow. Static, and shared by every card and tile.
class SoftSurfacePainter extends CustomPainter {
  const SoftSurfacePainter({
    this.radius = N360Radius.card,
    this.fill = N360Colors.sheet,
    this.gradient,
    this.edge,
    this.edgeWidth = 1,
    this.shadow = false,
    this.shadowStrength = 1,
  });

  final double radius;
  final Color fill;
  final Gradient? gradient;
  final Color? edge;
  final double edgeWidth;

  /// Only cards get a shadow, never rows of a long list.
  final bool shadow;
  final double shadowStrength;

  @override
  void paint(Canvas canvas, Size size) {
    final Rect rect = Offset.zero & size;
    final RRect shape = RRect.fromRectAndRadius(rect, Radius.circular(radius));
    if (shadow) paintSoftShadow(canvas, shape, strength: shadowStrength);

    final Paint face = Paint();
    if (gradient != null) {
      face.shader = gradient!.createShader(rect);
    } else {
      face.color = fill;
    }
    canvas.drawRRect(shape, face);

    final Color? line = edge;
    if (line != null) {
      canvas.drawRRect(
        shape.deflate(edgeWidth / 2),
        Paint()
          ..color = line
          ..style = PaintingStyle.stroke
          ..strokeWidth = edgeWidth,
      );
    }
  }

  @override
  bool shouldRepaint(SoftSurfacePainter oldDelegate) =>
      oldDelegate.radius != radius ||
      oldDelegate.fill != fill ||
      oldDelegate.gradient != gradient ||
      oldDelegate.edge != edge ||
      oldDelegate.edgeWidth != edgeWidth ||
      oldDelegate.shadow != shadow ||
      oldDelegate.shadowStrength != shadowStrength;
}
