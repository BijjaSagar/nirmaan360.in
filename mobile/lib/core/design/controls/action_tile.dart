import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// A home-screen tile: a quiet icon, its name beneath, on a white card with a
/// hairline. A small count sits at the corner when something is waiting.
class ActionTile extends StatelessWidget {
  const ActionTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.badge,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  /// Shown as a small count. Null hides it.
  final String? badge;

  static const double _minHeight = 92;

  @override
  Widget build(BuildContext context) {
    final Widget contents = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Icon(icon, size: N360IconSize.xl, color: N360Colors.laterite),
            const Spacer(),
            if (badge != null) N360Badge(text: badge!),
          ],
        ),
        const SizedBox(height: N360Space.md),
        Text(
          label,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: N360Type.fit(N360Type.bodyStrong, label),
        ),
      ],
    );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      minHeight: _minHeight,
      semanticsLabel: badge == null ? label : '$label, $badge',
      child: contents,
      builder: (context, depth, child) {
        return Transform.scale(
          scale: pressedScale(depth * 0.7),
          child: CustomPaint(
            painter: const SoftSurfacePainter(edge: N360Colors.rule),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: _minHeight),
              child: Padding(
                padding: const EdgeInsets.all(N360Space.card),
                child: child,
              ),
            ),
          ),
        );
      },
    );
  }
}

/// A small count on the primary colour, with white figures.
class N360Badge extends StatelessWidget {
  const N360Badge({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: const SoftSurfacePainter(radius: N360Radius.small, fill: N360Colors.primary),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
        child: Text(
          text,
          style: N360Type.figureSmall.copyWith(
            fontSize: N360Type.minimumSize,
            height: 1.3,
            fontWeight: FontWeight.w600,
            color: N360Colors.onPrimary,
          ),
        ),
      ),
    );
  }
}
