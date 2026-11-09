import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// A compact figure card: a small title, a figure, a caption, and a quiet
/// icon at the corner. [value] is a formatted string, so it never touches a
/// `double`.
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

  /// Shown small at the corner. Defaults to an arrow when tappable.
  final IconData? icon;
  final VoidCallback? onTap;

  /// Fills the card with the primary colour, white text on it.
  final bool emphasized;

  static const double minHeight = 84;

  @override
  Widget build(BuildContext context) {
    final IconData? corner = icon ?? (onTap != null ? Icons.north_east : null);
    final Color ink = emphasized ? N360Colors.onPrimary : N360Colors.ink;
    final Color secondary = emphasized ? N360Colors.onPrimary : N360Colors.inkLabel;
    final Color glyph = emphasized ? N360Colors.onPrimary : N360Colors.ink2;

    final Widget content = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Expanded(
              child: Text(
                title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.fit(N360Type.caption, title).copyWith(color: secondary),
              ),
            ),
            if (corner != null) ...<Widget>[
              const SizedBox(width: N360Space.sm),
              Icon(corner, size: N360IconSize.md, color: glyph),
            ],
          ],
        ),
        const SizedBox(height: N360Space.sm),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(value, maxLines: 1, style: N360Type.figureLarge.copyWith(color: ink)),
        ),
        if (caption != null)
          Text(
            caption!,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: N360Type.fit(N360Type.caption, caption!).copyWith(color: secondary, height: 1.3),
          ),
      ],
    );

    final SoftSurfacePainter surface = emphasized
        ? const SoftSurfacePainter(fill: N360Colors.primary)
        : const SoftSurfacePainter(edge: N360Colors.rule);

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
