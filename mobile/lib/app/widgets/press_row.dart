import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// A row that squeezes a little under the thumb. The caller owns semantics.
class PressRow extends StatelessWidget {
  const PressRow({super.key, required this.onTap, required this.child, this.minHeight = N360Size.tap});

  final VoidCallback onTap;
  final Widget child;
  final double minHeight;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      minHeight: minHeight,
      child: child,
      builder: (context, depth, child) => Transform.scale(scale: 1 - 0.02 * depth, child: child),
    );
  }
}
