import 'package:flutter/widgets.dart';

import '../tokens.dart';

export '../motion.dart' show pressedScale;

/// Kept for screens that still call it. The design paints no shadows: cards and
/// tiles are white with a hairline, so this draws nothing.
void paintSoftShadow(
  Canvas canvas,
  RRect shape, {
  double spread = 18,
  double drop = 10,
  double strength = 1,
  int steps = 10,
}) {}

/// A flat surface: a solid fill (or, for screens that still pass one, a
/// gradient) and a hairline edge. Shared by every card, tile and control.
///
/// [shadow] is accepted and ignored: structure comes from the edge, not from
/// a halo.
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

  /// Ignored. There are no shadows in this design.
  final bool shadow;
  final double shadowStrength;

  @override
  void paint(Canvas canvas, Size size) {
    final Rect rect = Offset.zero & size;
    final RRect shape = RRect.fromRectAndRadius(rect, Radius.circular(radius));

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
      oldDelegate.edgeWidth != edgeWidth;
}
