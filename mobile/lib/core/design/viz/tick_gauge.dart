import 'package:flutter/material.dart';

import '../tokens.dart';

/// A strip of ticks: done ticks run amber to orange, the rest stay pale.
///
/// [fraction] is a layout value: compute it from `Decimal` before passing it.
class TickGauge extends StatelessWidget {
  const TickGauge({
    super.key,
    required this.fraction,
    this.ticks = 40,
    this.height = 44,
    this.semanticLabel,
  });

  final double fraction;

  /// How many ticks, at least 2.
  final int ticks;
  final double height;

  /// Spoken instead of the percent.
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final double target = fraction.clamp(0.0, 1.0);
    return Semantics(
      container: true,
      label: semanticLabel ?? '${(target * 100).round()}%',
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: CustomPaint(
            size: Size(double.infinity, height),
            painter: _TickPainter(fraction: target, ticks: ticks < 2 ? 2 : ticks),
          ),
        ),
      ),
    );
  }
}

class _TickPainter extends CustomPainter {
  const _TickPainter({required this.fraction, required this.ticks});

  final double fraction;
  final int ticks;

  @override
  void paint(Canvas canvas, Size size) {
    final double slot = size.width / ticks;
    final double width = (slot * 0.56).clamp(2.0, 6.0);
    final double lit = fraction * ticks;
    final Paint paint = Paint();
    const radius = Radius.circular(2);

    for (var i = 0; i < ticks; i++) {
      final bool on = i < lit;
      paint.color = on
          ? Color.lerp(N360Colors.amber, N360Colors.accent, i / (ticks - 1))!
          : N360Colors.rule;
      final double x = i * slot + (slot - width) / 2;
      canvas.drawRRect(
        RRect.fromRectAndRadius(Rect.fromLTWH(x, 0, width, size.height), radius),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(_TickPainter oldDelegate) =>
      oldDelegate.fraction != fraction || oldDelegate.ticks != ticks;
}
