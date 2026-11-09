import 'package:flutter/material.dart';

import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// A rounded-square progress mark: a gold line runs around the square, with the
/// percent in the middle. The caller formats [label], usually "90%". The line
/// draws round once on entry, over [N360Durations.fill]; the label is painted
/// from the first frame.
///
/// [fraction] is a layout value: compute it from `Decimal` before passing it.
class SquarePercent extends StatelessWidget {
  const SquarePercent({super.key, required this.fraction, required this.label, this.size = N360Size.tile});

  final double fraction;
  final String label;

  /// The side of the square. 44 beside a list row, 56 in a summary card.
  final double size;

  @override
  Widget build(BuildContext context) {
    final double target = fraction.clamp(0.0, 1.0);
    final motion = context.motion;
    final double fontSize = size >= 52 ? 14 : N360Type.minimumSize;
    return Semantics(
      container: true,
      label: label,
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: SizedBox.square(
            dimension: size,
            child: TweenAnimationBuilder<double>(
              tween: Tween<double>(begin: motion.reduced ? target : 0, end: target),
              duration: motion.fill,
              curve: N360Curves.rise,
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(7),
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(label, style: N360Type.button.copyWith(fontSize: fontSize, height: 1.2)),
                  ),
                ),
              ),
              builder: (context, value, child) => CustomPaint(
                painter: _SquareArcPainter(fraction: value, radius: size * 0.3),
                child: child,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SquareArcPainter extends CustomPainter {
  const _SquareArcPainter({required this.fraction, required this.radius});

  final double fraction;
  final double radius;

  @override
  void paint(Canvas canvas, Size size) {
    const double stroke = 3;
    final Rect rect = (Offset.zero & size).deflate(stroke / 2);
    final RRect shape = RRect.fromRectAndRadius(rect, Radius.circular(radius));

    canvas.drawRRect(shape, Paint()..color = N360Colors.sheet);
    canvas.drawRRect(
      shape,
      Paint()
        ..color = N360Colors.rule
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke,
    );
    if (fraction <= 0) return;

    final Path outline = Path()..addRRect(shape);
    final metric = outline.computeMetrics().first;
    final Path arc = metric.extractPath(0, metric.length * fraction);
    canvas.drawPath(
      arc,
      Paint()
        ..shader = N360Gradients.gold.createShader(rect)
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeWidth = stroke,
    );
  }

  @override
  bool shouldRepaint(_SquareArcPainter oldDelegate) =>
      oldDelegate.fraction != fraction || oldDelegate.radius != radius;
}
