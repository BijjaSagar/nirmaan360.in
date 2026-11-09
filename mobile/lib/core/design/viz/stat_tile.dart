import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// A compact figure card: a small title, a figure, a caption, and a corner
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

  static const double minHeight = 84;

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
          style: N360Type.fit(N360Type.label, title).copyWith(
            height: 1.3,
            fontWeight: FontWeight.w500,
            color: emphasized ? N360Colors.ink : N360Colors.ink2,
          ),
        ),
        const SizedBox(height: N360Space.xs),
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
                      style: N360Type.fit(N360Type.caption, caption!).copyWith(color: secondary, height: 1.3),
                    ),
                ],
              ),
            ),
            if (corner != null) ...<Widget>[
              const SizedBox(width: N360Space.sm),
              SizedBox.square(
                dimension: N360Size.tileSmall,
                child: CustomPaint(
                  painter: SoftSurfacePainter(
                    radius: N360Radius.small,
                    fill: emphasized ? const Color(0x40FFFFFF) : N360Colors.paper2,
                  ),
                  child: Center(child: Icon(corner, size: N360IconSize.md, color: N360Colors.ink)),
                ),
              ),
            ],
          ],
        ),
      ],
    );

    final SoftSurfacePainter surface = emphasized
        ? const SoftSurfacePainter(gradient: N360Gradients.gold, edge: Color(0x55FFFFFF))
        : const SoftSurfacePainter(edge: N360Colors.cardEdge, shadow: true, shadowStrength: 0.8);

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
              child: Padding(padding: const EdgeInsets.all(N360Space.card), child: content),
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
      child: Padding(padding: const EdgeInsets.all(N360Space.card), child: content),
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
