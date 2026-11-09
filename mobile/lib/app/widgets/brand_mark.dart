import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// The app's mark: a rounded tile with a block capital N, drawn in code so it
/// is right on the very first frame, before any font has loaded.
///
/// With [launch] it reproduces the native launch artwork. Everywhere else the
/// tile is the gold of the app's actions, with dark ink on it.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 44, this.launch = false});

  final double size;
  final bool launch;

  @override
  Widget build(BuildContext context) {
    return SizedBox.square(
      dimension: size,
      child: CustomPaint(painter: _BrandMarkPainter(launch: launch)),
    );
  }
}

class _BrandMarkPainter extends CustomPainter {
  const _BrandMarkPainter({required this.launch});

  final bool launch;

  // Measured off the launch artwork, relative to a square canvas of 1.
  static const double _launchSide = 0.722;
  static const double _launchRadius = 0.153;

  @override
  void paint(Canvas canvas, Size size) {
    final double unit = size.shortestSide;
    final Offset centre = size.center(Offset.zero);
    final Rect tile = launch
        ? Rect.fromCenter(center: centre, width: unit * _launchSide, height: unit * _launchSide)
        : Offset.zero & size;
    final RRect shape = RRect.fromRectAndRadius(tile, Radius.circular(unit * (launch ? _launchRadius : 0.3)));
    final Paint face = Paint();
    if (launch) {
      face.color = N360Colors.orange;
    } else {
      face.shader = N360Gradients.gold.createShader(tile);
    }
    canvas.drawRRect(shape, face);

    final double scale = launch ? 1 : 1.3;
    final double x = unit * 0.158 * scale;
    final double y = unit * 0.156 * scale;
    final double bar = unit * 0.087 * scale;
    final double neck = unit * 0.105 * scale;
    final Path letter = Path()
      ..moveTo(-x, y)
      ..lineTo(-x, -y)
      ..lineTo(-x + neck, -y)
      ..lineTo(x - bar, y - neck)
      ..lineTo(x - bar, -y)
      ..lineTo(x, -y)
      ..lineTo(x, y)
      ..lineTo(x - neck, y)
      ..lineTo(-x + bar, -y + neck)
      ..lineTo(-x + bar, y)
      ..close();
    canvas.drawPath(
      letter.shift(centre),
      Paint()..color = launch ? N360Colors.sheet : N360Colors.ink,
    );
  }

  @override
  bool shouldRepaint(_BrandMarkPainter oldDelegate) => oldDelegate.launch != launch;
}
