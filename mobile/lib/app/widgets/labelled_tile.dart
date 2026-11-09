import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// An [IconTile] with its word underneath, so no icon relies on being
/// recognised. The word is part of the tap target.
class LabelledTile extends StatelessWidget {
  const LabelledTile({
    super.key,
    required this.icon,
    required this.label,
    this.semanticLabel,
    this.onTap,
    this.badge,
    this.highlight = false,
    this.labelColor = N360Colors.ink2,
  });

  final IconData icon;

  /// The word under the tile.
  final String label;

  /// Spoken instead of [label], when the word alone is not enough.
  final String? semanticLabel;
  final VoidCallback? onTap;
  final String? badge;
  final bool highlight;
  final Color labelColor;

  static const double width = 64;

  /// The tile, the gap and the fixed line of its word.
  static const double height = N360Size.tap + 2 + 18;

  @override
  Widget build(BuildContext context) {
    final Widget column = SizedBox(
      width: width,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          IconTile(
            icon: icon,
            semanticLabel: semanticLabel ?? label,
            onTap: onTap,
            badge: badge,
            highlight: highlight,
          ),
          const SizedBox(height: 2),
          ExcludeSemantics(
            child: MediaQuery.withClampedTextScaling(
              maxScaleFactor: 1.15,
              // A fixed line, so a longer word never changes the height of the header.
              child: SizedBox(
                height: 18,
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    label,
                    maxLines: 1,
                    style: N360Type.fit(N360Type.caption, label).copyWith(height: 1.25, color: labelColor),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
    final tap = onTap;
    if (tap == null) return column;
    return GestureDetector(behavior: HitTestBehavior.opaque, onTap: tap, child: column);
  }
}
