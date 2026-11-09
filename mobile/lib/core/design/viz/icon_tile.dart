import 'package:flutter/material.dart';

import '../controls/action_tile.dart';
import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';

/// A 56dp rounded-square icon tile, with a spoken name for screen readers.
///
/// Use it for an icon that has a label beside it. Where an icon stands alone,
/// pair it with a [Text] so nothing depends on recognising the picture.
class IconTile extends StatelessWidget {
  const IconTile({
    super.key,
    required this.icon,
    required this.semanticLabel,
    this.onTap,
    this.badge,
    this.highlight = false,
  });

  final IconData icon;
  final String semanticLabel;
  final VoidCallback? onTap;

  /// A short count on the corner. Null hides it.
  final String? badge;

  /// Fills the tile with gold.
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final String spoken = badge == null ? semanticLabel : '$semanticLabel, $badge';

    final Widget tile = SizedBox.square(
      dimension: N360Size.tap,
      child: Stack(
        clipBehavior: Clip.none,
        children: <Widget>[
          Positioned.fill(
            child: CustomPaint(
              painter: highlight
                  ? const SoftSurfacePainter(
                      radius: N360Radius.medium,
                      gradient: N360Gradients.gold,
                      edge: Color(0x55FFFFFF),
                    )
                  : const SoftSurfacePainter(radius: N360Radius.medium, fill: N360Colors.paper2),
              child: Center(child: Icon(icon, size: 26, color: N360Colors.ink)),
            ),
          ),
          if (badge != null) Positioned(top: -4, right: -4, child: N360Badge(text: badge!)),
        ],
      ),
    );

    final tap = onTap;
    if (tap == null) {
      return Semantics(container: true, image: true, label: spoken, child: ExcludeSemantics(child: tile));
    }

    return PressTarget(
      onTap: tap,
      haptic: N360Haptics.tap,
      semanticsLabel: spoken,
      child: tile,
      builder: (context, depth, child) => Transform.scale(scale: pressedScale(depth), child: child),
    );
  }
}
