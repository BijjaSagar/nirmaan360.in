import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// A press that sinks the row a little, for rows and slips that paint their own
/// sheet. Layout never changes.
class PressRow extends StatefulWidget {
  const PressRow({super.key, required this.onTap, required this.child, this.minHeight = N360Size.tap});

  final VoidCallback onTap;
  final Widget child;
  final double minHeight;

  @override
  State<PressRow> createState() => _PressRowState();
}

class _PressRowState extends State<PressRow> {
  bool _down = false;

  void _set(bool down) {
    if (_down != down) setState(() => _down = down);
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) {
        _set(true);
        N360Haptics.tap();
      },
      onTapUp: (_) => _set(false),
      onTapCancel: () => _set(false),
      onTap: widget.onTap,
      child: ConstrainedBox(
        constraints: BoxConstraints(minHeight: widget.minHeight),
        child: AnimatedContainer(
          duration: context.motion.press,
          curve: N360Curves.press,
          transform: Matrix4.translationValues(0, _down ? 1.5 : 0, 0),
          child: widget.child,
        ),
      ),
    );
  }
}
