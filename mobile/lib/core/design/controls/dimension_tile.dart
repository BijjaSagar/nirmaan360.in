import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../signature/rolling_number.dart';
import '../tokens.dart';
import '../typography.dart';

/// One measured dimension: Nos, L, B or D. The active tile carries a drafting
/// dimension line, the web's `.dim-line`, so the eye knows where digits will go.
class DimensionTile extends StatelessWidget {
  const DimensionTile({
    super.key,
    required this.label,
    required this.value,
    required this.onTap,
    this.active = false,
    this.unit,
    this.placeholder = '0',
  });

  final String label;

  /// The string being typed, passed straight through.
  final String value;
  final bool active;
  final String? unit;
  final VoidCallback onTap;

  /// Shown when [value] is empty.
  final String placeholder;

  @override
  Widget build(BuildContext context) {
    final bool empty = value.isEmpty;
    final TextStyle numberStyle = N360Type.figure.copyWith(
      fontSize: 19,
      color: empty ? N360Colors.inkLabel : N360Colors.ink,
    );

    final Widget number = FittedBox(
      fit: BoxFit.scaleDown,
      alignment: Alignment.centerLeft,
      child: RollingNumber(value: empty ? placeholder : value, style: numberStyle),
    );

    final Widget contents = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(
              child: Text(
                N360Type.eyebrowText(label),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.eyebrowFor(label).copyWith(
                  color: active ? N360Colors.stampRed : N360Colors.inkLabel,
                ),
              ),
            ),
            if (unit != null) Text(unit!, style: N360Type.caption.copyWith(fontSize: 13)),
          ],
        ),
        const SizedBox(height: 2),
        number,
      ],
    );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: 72,
      semanticsLabel: '$label ${empty ? placeholder : value}${unit == null ? '' : ' $unit'}',
      selected: active,
      child: contents,
      builder: (context, depth, child) {
        return CustomPaint(
          painter: _DimensionPainter(active: active, depth: depth),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 72),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.sm, N360Space.sm, N360Space.sm, N360Space.md + 2),
              child: child,
            ),
          ),
        );
      },
    );
  }
}

class _DimensionPainter extends CustomPainter {
  const _DimensionPainter({required this.active, required this.depth});

  final bool active;
  final double depth;

  @override
  void paint(Canvas canvas, Size size) {
    final RRect tile = RRect.fromRectAndRadius(
      (Offset.zero & size).deflate(active ? 1 : 0.5),
      const Radius.circular(N360Radius.small),
    );
    canvas.drawRRect(tile, Paint()..color = N360Colors.sheet);
    canvas.drawRRect(
      tile,
      Paint()
        ..color = active ? N360Colors.laterite : N360Colors.ruleStrong
        ..style = PaintingStyle.stroke
        ..strokeWidth = active ? 2 : 1,
    );
    if (!active) return;

    // A dimension line under the figure, with the tilted ticks of a drawing.
    final Paint line = Paint()
      ..color = N360Colors.laterite
      ..strokeWidth = 1.4;
    final double y = size.height - 8.5;
    const inset = 9.0;
    canvas.drawLine(Offset(inset, y), Offset(size.width - inset, y), line);
    const tick = 5.0;
    void mark(double x) => canvas.drawLine(Offset(x - 2.6, y + tick), Offset(x + 2.6, y - tick), line);
    mark(inset);
    mark(size.width - inset);
  }

  @override
  bool shouldRepaint(_DimensionPainter oldDelegate) =>
      oldDelegate.active != active || oldDelegate.depth != depth;
}
