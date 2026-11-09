import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// The app's tilted stamp, the same mark as the native launch screen.
class StampMark extends StatelessWidget {
  const StampMark({super.key, this.size = 96});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(
      dimension: size,
      child: const CustomPaint(painter: StampMarkPainter()),
    );
  }
}

class StampMarkPainter extends CustomPainter {
  const StampMarkPainter();

  // Measured off the launch artwork, relative to a square canvas of 1.
  static const double _side = 0.7215;
  static const double _tilt = -7.5 * math.pi / 180;

  @override
  void paint(Canvas canvas, Size size) {
    final double unit = size.shortestSide;
    canvas.save();
    canvas.translate(size.width / 2, size.height / 2);
    canvas.rotate(_tilt);

    final double half = unit * _side / 2;
    final Rect face = Rect.fromCenter(center: Offset.zero, width: half * 2, height: half * 2);
    canvas.drawRect(face, Paint()..color = N360Colors.laterite);

    final Paint paper = Paint()..color = N360Colors.paper;
    canvas.drawRect(
      face.deflate(unit * 0.05),
      Paint()
        ..color = N360Colors.paper
        ..style = PaintingStyle.stroke
        ..strokeWidth = unit * 0.012,
    );

    // A block capital N, drawn so it is correct before any font has loaded.
    final double x = unit * 0.167;
    final double y = unit * 0.16;
    final double bar = unit * 0.082;
    final double neck = unit * 0.1;
    final Path letter = Path()
      ..moveTo(-x, y)
      ..lineTo(-x, -y)
      ..lineTo(-x + neck, -y)
      ..lineTo(x - bar, y - neck * 0.9)
      ..lineTo(x - bar, -y)
      ..lineTo(x, -y)
      ..lineTo(x, y)
      ..lineTo(x - neck, y)
      ..lineTo(-x + bar, -y + neck * 0.9)
      ..lineTo(-x + bar, y)
      ..close();
    canvas.drawPath(letter, paper);
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
