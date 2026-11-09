import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// A filter chip: a word, an optional icon and an optional count. The visible
/// chip is [N360Size.control] tall inside a full [N360Size.tap] target.
class N360Chip extends StatelessWidget {
  const N360Chip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.count,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;

  /// A short count after the word. Null hides it.
  final String? count;

  @override
  Widget build(BuildContext context) {
    final Color ink = selected ? N360Colors.onPrimary : N360Colors.ink;
    final TextStyle text = N360Type.fit(N360Type.label, label).copyWith(
      color: ink,
      fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
    );
    final Widget inner = Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (icon != null) ...<Widget>[
          Icon(icon, size: N360IconSize.sm, color: ink),
          const SizedBox(width: 6),
        ],
        Text(label, style: text, maxLines: 1),
        if (count != null) ...<Widget>[
          const SizedBox(width: 6),
          Text(count!, style: N360Type.figureSmall.copyWith(color: ink)),
        ],
      ],
    );
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: N360Size.tap,
      minWidth: 0,
      semanticsLabel: count == null ? label : '$label, $count',
      selected: selected,
      child: inner,
      builder: (context, depth, child) => Center(
        widthFactor: 1,
        child: Transform.scale(
          scale: pressedScale(depth),
          child: CustomPaint(
            painter: selected
                ? const SoftSurfacePainter(radius: N360Radius.medium, fill: N360Colors.ink)
                : const SoftSurfacePainter(radius: N360Radius.medium, edge: N360Colors.rule),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: N360Size.control),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.xs),
                child: Center(child: child),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
