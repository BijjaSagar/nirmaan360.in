import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// A home-screen tile: a large picture in a rounded square with its name
/// beneath. A small gold badge counts what is waiting.
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

  /// Shown as a small gold count. Null hides it.
  final String? badge;

  @override
  Widget build(BuildContext context) {
    final Widget contents = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            SizedBox.square(
              dimension: 56,
              child: CustomPaint(
                painter: const SoftSurfacePainter(radius: N360Radius.medium, fill: N360Colors.goldTint),
                child: Center(child: Icon(icon, size: 30, color: N360Colors.laterite)),
              ),
            ),
            const Spacer(),
            if (badge != null) N360Badge(text: badge!),
          ],
        ),
        const SizedBox(height: N360Space.sm + 2),
        Text(
          label,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: N360Type.heading,
        ),
      ],
    );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      minHeight: 124,
      semanticsLabel: badge == null ? label : '$label, $badge',
      child: contents,
      builder: (context, depth, child) {
        return Transform.scale(
          scale: pressedScale(depth * 0.7),
          child: CustomPaint(
            painter: const SoftSurfacePainter(radius: 22, edge: N360Colors.cardEdge, shadow: true, shadowStrength: 0.8),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 124),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: child,
              ),
            ),
          ),
        );
      },
    );
  }
}

/// A small gold count, used on tiles and in the bottom bar. Dark ink on gold.
class N360Badge extends StatelessWidget {
  const N360Badge({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: const SoftSurfacePainter(radius: 8, gradient: N360Gradients.gold),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
        child: Text(
          text,
          style: N360Type.button.copyWith(fontSize: 13, height: 1.3, fontWeight: FontWeight.w600),
        ),
      ),
    );
  }
}
