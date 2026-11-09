import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../controls/action_tile.dart';
import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';

/// A rounded-square icon tile, with a spoken name for screen readers.
///
/// The tile itself is [size] across ([N360Size.tile], 44dp, by default) so it
/// sits lightly beside text. When it is tappable the hit area grows to at
/// least [N360Size.tap] around it, so a gloved thumb still lands on it.
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
    this.size = N360Size.tile,
    this.iconSize = N360IconSize.tile,
  });

  final IconData icon;
  final String semanticLabel;
  final VoidCallback? onTap;

  /// A short count on the corner. Null hides it.
  final String? badge;

  /// Fills the tile with gold.
  final bool highlight;

  /// The visible side of the tile. The tap target is never smaller than
  /// [N360Size.tap] regardless.
  final double size;
  final double iconSize;

  @override
  Widget build(BuildContext context) {
    final String spoken = badge == null ? semanticLabel : '$semanticLabel, $badge';

    final Widget tile = SizedBox.square(
      dimension: size,
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
              child: Center(child: Icon(icon, size: iconSize, color: N360Colors.ink)),
            ),
          ),
          if (badge != null) Positioned(top: -5, right: -5, child: N360Badge(text: badge!)),
        ],
      ),
    );

    final tap = onTap;
    if (tap == null) {
      return Semantics(container: true, image: true, label: spoken, child: ExcludeSemantics(child: tile));
    }

    final double target = math.max(size, N360Size.tap);
    return PressTarget(
      onTap: tap,
      haptic: N360Haptics.tap,
      semanticsLabel: spoken,
      minHeight: target,
      minWidth: target,
      child: SizedBox.square(dimension: target, child: Center(child: tile)),
      builder: (context, depth, child) => Transform.scale(scale: pressedScale(depth), child: child),
    );
  }
}
