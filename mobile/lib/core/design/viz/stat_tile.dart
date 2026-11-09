import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// A compact figure card: a small title, a big value, a caption, and a corner
/// icon. [value] is a formatted string, so it never touches a `double`.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.title,
    required this.value,
    this.caption,
    this.icon,
    this.onTap,
    this.emphasized = false,
  });

  final String title;
  final String value;
  final String? caption;

  /// Shown in a rounded tile at the corner. Defaults to an arrow when tappable.
  final IconData? icon;
  final VoidCallback? onTap;

  /// Fills the card with gold.
  final bool emphasized;

  static const double minHeight = 96;

  @override
  Widget build(BuildContext context) {
    final IconData? corner = icon ?? (onTap != null ? Icons.north_east : null);
    final Color secondary = emphasized ? N360Colors.ink : N360Colors.inkLabel;

    final Widget content = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(
          title,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: N360Type.bodyStrong.copyWith(height: 1.3, color: emphasized ? N360Colors.ink : N360Colors.ink2),
        ),
        const SizedBox(height: 2),
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(value, maxLines: 1, style: N360Type.figureLarge),
                  ),
                  if (caption != null)
                    Text(
                      caption!,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.caption.copyWith(color: secondary, fontSize: 13, height: 1.3),
                    ),
                ],
              ),
            ),
            if (corner != null) ...<Widget>[
              const SizedBox(width: N360Space.sm),
              SizedBox.square(
                dimension: 38,
                child: CustomPaint(
                  painter: SoftSurfacePainter(
                    radius: 12,
                    fill: emphasized ? const Color(0x40FFFFFF) : N360Colors.paper2,
                  ),
                  child: Center(child: Icon(corner, size: 19, color: N360Colors.ink)),
                ),
              ),
            ],
          ],
        ),
      ],
    );

    final SoftSurfacePainter surface = emphasized
        ? const SoftSurfacePainter(radius: 22, gradient: N360Gradients.gold, edge: Color(0x55FFFFFF))
        : const SoftSurfacePainter(radius: 22, edge: N360Colors.cardEdge, shadow: true, shadowStrength: 0.8);

    final String spoken = caption == null ? '$title, $value' : '$title, $value, $caption';
    final tap = onTap;
    if (tap == null) {
      return Semantics(
        container: true,
        label: spoken,
        child: ExcludeSemantics(
          child: CustomPaint(
            painter: surface,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: minHeight),
              child: Padding(padding: const EdgeInsets.all(14), child: content),
            ),
          ),
        ),
      );
    }

    return PressTarget(
      onTap: tap,
      haptic: N360Haptics.tap,
      minHeight: minHeight,
      semanticsLabel: spoken,
      child: Padding(padding: const EdgeInsets.all(14), child: content),
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth * 0.7),
        child: CustomPaint(
          painter: surface,
          child: ConstrainedBox(constraints: const BoxConstraints(minHeight: minHeight), child: child),
        ),
      ),
    );
  }
}
