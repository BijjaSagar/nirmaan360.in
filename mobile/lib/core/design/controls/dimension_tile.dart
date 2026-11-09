import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../signature/rolling_number.dart';
import '../tokens.dart';
import '../typography.dart';

/// One measured dimension: Nos, L, B or D. The active tile has a gold outline
/// and a gold bar under the figure, so the eye knows where digits will go.
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

  static const double _minHeight = 60;

  @override
  Widget build(BuildContext context) {
    final bool empty = value.isEmpty;
    final TextStyle numberStyle = N360Type.figureLarge.copyWith(
      fontSize: 17,
      letterSpacing: 0,
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
                label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.eyebrowFor(label).copyWith(
                  color: active ? N360Colors.laterite : N360Colors.inkLabel,
                ),
              ),
            ),
            if (unit != null) Text(unit!, style: N360Type.caption),
          ],
        ),
        const SizedBox(height: 1),
        number,
      ],
    );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: _minHeight,
      semanticsLabel: '$label ${empty ? placeholder : value}${unit == null ? '' : ' $unit'}',
      selected: active,
      child: contents,
      builder: (context, depth, child) {
        return Transform.scale(
          scale: pressedScale(depth),
          child: CustomPaint(
            painter: active
                ? const SoftSurfacePainter(
                    radius: N360Radius.medium,
                    fill: N360Colors.goldTint,
                    edge: N360Colors.orange,
                    edgeWidth: 1.5,
                  )
                : const SoftSurfacePainter(radius: N360Radius.medium, edge: N360Colors.rule),
            foregroundPainter: active ? const _ActiveBarPainter() : null,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: _minHeight),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(N360Space.sm, 6, 6, N360Space.sm + 2),
                child: child,
              ),
            ),
          ),
        );
      },
    );
  }
}

class _ActiveBarPainter extends CustomPainter {
  const _ActiveBarPainter();

  @override
  void paint(Canvas canvas, Size size) {
    const double inset = 8;
    final Rect bar = Rect.fromLTWH(inset, size.height - 6.5, size.width - inset * 2, 2.5);
    canvas.drawRRect(
      RRect.fromRectAndRadius(bar, const Radius.circular(1.25)),
      Paint()..shader = N360Gradients.gold.createShader(bar),
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
