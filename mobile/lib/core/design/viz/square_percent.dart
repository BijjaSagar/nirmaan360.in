import 'package:flutter/material.dart';

import '../tokens.dart';
import '../typography.dart';

/// A rounded-square progress mark: a gold line runs around the square, with the
/// percent in the middle. The caller formats [label], usually "90%".
///
/// [fraction] is a layout value: compute it from `Decimal` before passing it.
class SquarePercent extends StatelessWidget {
  const SquarePercent({super.key, required this.fraction, required this.label, this.size = 56});

  final double fraction;
  final String label;
  final double size;

  @override
  Widget build(BuildContext context) {
    final double target = fraction.clamp(0.0, 1.0);
    return Semantics(
      container: true,
      label: label,
      child: ExcludeSemantics(
        child: SizedBox.square(
          dimension: size,
          child: CustomPaint(
            painter: _SquareArcPainter(fraction: target),
            child: Center(
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(label, style: N360Type.button.copyWith(fontSize: 14, height: 1.2)),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SquareArcPainter extends CustomPainter {
  const _SquareArcPainter({required this.fraction});

  final double fraction;

  @override
  void paint(Canvas canvas, Size size) {
    const double stroke = 3.5;
    final Rect rect = (Offset.zero & size).deflate(stroke / 2);
    final RRect shape = RRect.fromRectAndRadius(rect, const Radius.circular(15));

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
  bool shouldRepaint(_SquareArcPainter oldDelegate) => oldDelegate.fraction != fraction;
}
