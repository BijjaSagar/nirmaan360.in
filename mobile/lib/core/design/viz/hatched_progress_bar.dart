import 'package:flutter/material.dart';

import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// Progress as a gold fill and a diagonally hatched remainder, with a marker
/// labelled for "today". The marker's label is painted from the first frame;
/// only the fill runs in.
///
/// [fraction] is a layout value: compute it from `Decimal` before passing it.
class HatchedProgressBar extends StatelessWidget {
  const HatchedProgressBar({
    super.key,
    required this.fraction,
    this.markerLabel,
    this.height = 44,
    this.semanticLabel,
  });

  /// How far along, 0 to 1.
  final double fraction;

  /// Text beside the marker, such as "Today". Null shows no text.
  final String? markerLabel;
  final double height;

  /// Spoken instead of the percent, such as "70 percent complete".
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final double target = fraction.clamp(0.0, 1.0);
    final motion = context.motion;
    final TextScaler scaler = MediaQuery.textScalerOf(context).clamp(maxScaleFactor: 1.3);
    final double headroom = markerLabel == null ? 8 : 26 * scaler.scale(1);

    return Semantics(
      container: true,
      label: semanticLabel ?? '${(target * 100).round()}%${markerLabel == null ? '' : ', $markerLabel'}',
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: TweenAnimationBuilder<double>(
            tween: Tween<double>(begin: motion.reduced ? target : 0, end: target),
            duration: motion.reduced ? Duration.zero : const Duration(milliseconds: 620),
            curve: N360Curves.rise,
            builder: (context, value, _) => CustomPaint(
              size: Size(double.infinity, height + headroom),
              painter: _HatchedBarPainter(
                fraction: value,
                label: markerLabel,
                barHeight: height,
                headroom: headroom,
                scaler: scaler,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _HatchedBarPainter extends CustomPainter {
  const _HatchedBarPainter({
    required this.fraction,
    required this.label,
    required this.barHeight,
    required this.headroom,
    required this.scaler,
  });

  final double fraction;
  final String? label;
  final double barHeight;
  final double headroom;
  final TextScaler scaler;

  static const double _stripeStep = 11;
  static const double _stripeWidth = 4.5;

  @override
  void paint(Canvas canvas, Size size) {
    final Rect bar = Rect.fromLTWH(0, headroom, size.width, barHeight);
    final double splitX = size.width * fraction;
    final RRect shape = RRect.fromRectAndRadius(bar, const Radius.circular(12));

    canvas.drawRRect(shape, Paint()..color = N360Colors.paper2);

    canvas.save();
    canvas.clipRRect(shape);

    // The filled part: a warm wash with white hatching across it.
    final Rect filled = Rect.fromLTRB(0, bar.top, splitX, bar.bottom);
    if (filled.width > 0) {
      canvas.drawRect(filled, Paint()..shader = N360Gradients.goldFade.createShader(filled));
      _stripes(canvas, filled, Paint()..color = N360Colors.sheet.withValues(alpha: 0.38));
    }

    // The remainder: orange hatching on the pale track.
    final Rect rest = Rect.fromLTRB(splitX, bar.top, size.width, bar.bottom);
    if (rest.width > 0) {
      _stripes(canvas, rest, Paint()..color = N360Colors.orange.withValues(alpha: 0.72));
    }
    canvas.restore();

    _marker(canvas, size, splitX, bar);
  }

  void _stripes(Canvas canvas, Rect area, Paint paint) {
    canvas.save();
    canvas.clipRect(area);
    final double h = area.height;
    final Path stripes = Path();
    // Stripes are anchored to the bar, so they stay put as the fill grows.
    for (double x = -h; x < area.right + h; x += _stripeStep) {
      if (x + h + _stripeWidth < area.left) continue;
      stripes
        ..moveTo(x, area.bottom)
        ..lineTo(x + h, area.top)
        ..lineTo(x + h + _stripeWidth, area.top)
        ..lineTo(x + _stripeWidth, area.bottom)
        ..close();
    }
    canvas.drawPath(stripes, paint);
    canvas.restore();
  }

  void _marker(Canvas canvas, Size size, double x, Rect bar) {
    final double px = x.clamp(1.0, size.width - 1.0);
    final Paint line = Paint()
      ..color = N360Colors.orange
      ..strokeWidth = 2;
    final double dotY = label == null ? bar.top - 4 : bar.top - 7;
    canvas.drawLine(Offset(px, dotY), Offset(px, bar.bottom), line);
    canvas.drawCircle(Offset(px, dotY), 4, Paint()..color = N360Colors.orange);
    canvas.drawCircle(Offset(px, dotY), 1.6, Paint()..color = N360Colors.sheet);

    final String? text = label;
    if (text == null || text.isEmpty) return;
    final TextPainter painter = TextPainter(
      text: TextSpan(
        text: text,
        style: N360Type.caption.copyWith(color: N360Colors.ink2, fontSize: 13, height: 1.3),
      ),
      textDirection: TextDirection.ltr,
      textScaler: scaler,
      maxLines: 1,
    )..layout(maxWidth: size.width);
    final double textX = (px - painter.width / 2).clamp(0.0, size.width - painter.width);
    painter.paint(canvas, Offset(textX, dotY - 8 - painter.height));
    painter.dispose();
  }

  @override
  bool shouldRepaint(_HatchedBarPainter oldDelegate) =>
      oldDelegate.fraction != fraction ||
      oldDelegate.label != label ||
      oldDelegate.barHeight != barHeight ||
      oldDelegate.scaler != scaler;
}
