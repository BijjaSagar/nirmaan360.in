import 'package:flutter/material.dart';

import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// Progress as a thin flat bar: orange done, the light surface tone for the
/// rest, and a marker labelled for "today". The marker's label is painted from
/// the first frame; only the fill runs in, once, over [N360Durations.fill].
///
/// The name is kept from the earlier design, which hatched the remainder. The
/// bar is plain now.
///
/// [fraction] is a layout value: compute it from `Decimal` before passing it.
class HatchedProgressBar extends StatelessWidget {
  const HatchedProgressBar({
    super.key,
    required this.fraction,
    this.markerLabel,
    this.height = 8,
    this.semanticLabel,
    this.delayed = false,
  });

  /// How far along, 0 to 1.
  final double fraction;

  /// Text beside the marker, such as "Today". Null shows no text.
  final String? markerLabel;

  /// The bar itself. 8 for a card, 6 for a row.
  final double height;

  /// Spoken instead of the percent, such as "70 percent complete".
  final String? semanticLabel;

  /// Fills in the urgent red-orange instead of orange, for work behind plan.
  final bool delayed;

  @override
  Widget build(BuildContext context) {
    final double target = fraction.clamp(0.0, 1.0);
    final motion = context.motion;
    final TextScaler scaler = N360TextScale.of(context);
    final double headroom = markerLabel == null ? 6 : 22 * scaler.scale(1);

    return Semantics(
      container: true,
      label: semanticLabel ?? '${(target * 100).round()}%${markerLabel == null ? '' : ', $markerLabel'}',
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: TweenAnimationBuilder<double>(
            tween: Tween<double>(begin: motion.reduced ? target : 0, end: target),
            duration: motion.fill,
            curve: N360Curves.rise,
            builder: (context, value, _) => CustomPaint(
              size: Size(double.infinity, height + headroom),
              painter: _BarPainter(
                fraction: value,
                label: markerLabel,
                barHeight: height,
                headroom: headroom,
                scaler: scaler,
                fill: delayed ? N360Colors.accent : N360Colors.orange,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BarPainter extends CustomPainter {
  const _BarPainter({
    required this.fraction,
    required this.label,
    required this.barHeight,
    required this.headroom,
    required this.scaler,
    required this.fill,
  });

  final double fraction;
  final String? label;
  final double barHeight;
  final double headroom;
  final TextScaler scaler;
  final Color fill;

  @override
  void paint(Canvas canvas, Size size) {
    final Rect bar = Rect.fromLTWH(0, headroom, size.width, barHeight);
    final double splitX = size.width * fraction;
    final Radius radius = Radius.circular(barHeight / 2);

    canvas.drawRRect(RRect.fromRectAndRadius(bar, radius), Paint()..color = N360Colors.paper2);
    if (splitX > 0) {
      final Rect done = Rect.fromLTRB(0, bar.top, splitX.clamp(barHeight, size.width), bar.bottom);
      canvas.drawRRect(RRect.fromRectAndRadius(done, radius), Paint()..color = fill);
    }

    _marker(canvas, size, splitX, bar);
  }

  void _marker(Canvas canvas, Size size, double x, Rect bar) {
    final String? text = label;
    if (text == null || text.isEmpty) return;
    // A small dot above the bar, not a line through it.
    final double px = x.clamp(3.0, size.width - 3.0);
    canvas.drawCircle(Offset(px, bar.top - 6), 3, Paint()..color = N360Colors.ink2);
    final TextPainter painter = TextPainter(
      text: TextSpan(
        text: text,
        style: N360Type.fit(N360Type.caption, text).copyWith(color: N360Colors.ink2, height: 1.2),
      ),
      textDirection: TextDirection.ltr,
      textScaler: scaler,
      maxLines: 1,
    )..layout(maxWidth: size.width);
    final double textX = (px - painter.width / 2).clamp(0.0, size.width - painter.width);
    painter.paint(canvas, Offset(textX, bar.top - 11 - painter.height));
    painter.dispose();
  }

  @override
  bool shouldRepaint(_BarPainter oldDelegate) =>
      oldDelegate.fraction != fraction ||
      oldDelegate.label != label ||
      oldDelegate.barHeight != barHeight ||
      oldDelegate.scaler != scaler ||
      oldDelegate.fill != fill;
}
