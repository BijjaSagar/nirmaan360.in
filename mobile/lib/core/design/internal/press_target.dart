import 'package:flutter/material.dart';

import '../haptics.dart';
import '../motion.dart';
import '../tokens.dart';

/// Builds a control's face from how far it is pressed: 0 at rest, 1 fully in.
typedef PressBuilder = Widget Function(BuildContext context, double depth, Widget? child);

/// The tap surface under every control: at least 56dp tall, a press that sinks
/// rather than ripples, a haptic, and semantics. Layout never changes on press.
class PressTarget extends StatefulWidget {
  const PressTarget({
    super.key,
    required this.builder,
    this.child,
    this.onTap,
    this.onLongPress,
    this.haptic = N360Haptics.tap,
    this.semanticsLabel,
    this.semanticsHint,
    this.selected,
    this.minHeight = N360Size.tap,
    this.minWidth = N360Size.tap,
  });

  final PressBuilder builder;

  /// A subtree that does not change while pressed, passed back to [builder].
  final Widget? child;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;

  /// Fired as the finger lands, so it feels instant. Null for silent.
  final Future<void> Function()? haptic;

  /// The spoken name of the control. When null, the caller owns semantics.
  final String? semanticsLabel;
  final String? semanticsHint;
  final bool? selected;
  final double minHeight;
  final double minWidth;

  @override
  State<PressTarget> createState() => _PressTargetState();
}

class _PressTargetState extends State<PressTarget> with SingleTickerProviderStateMixin {
  late final AnimationController _depth = AnimationController(vsync: this);
  bool _down = false;

  bool get _enabled => widget.onTap != null || widget.onLongPress != null;

  void _press() {
    if (!_enabled) return;
    _down = true;
    widget.haptic?.call();
    _depth.animateTo(1, duration: context.motion.press, curve: N360Curves.press);
  }

  void _release() {
    _down = false;
    final motion = context.motion;
    // Let a very quick tap finish sinking so the press is always seen.
    _depth.animateTo(1, duration: motion.press, curve: N360Curves.press).whenCompleteOrCancel(() {
      if (!mounted || _down) return;
      _depth.animateBack(0, duration: motion.release, curve: N360Curves.press);
    });
  }

  @override
  void dispose() {
    _depth.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final Widget surface = GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: _enabled ? (_) => _press() : null,
      onTapUp: _enabled ? (_) => _release() : null,
      onTapCancel: _enabled ? _release : null,
      onTap: widget.onTap,
      onLongPress: widget.onLongPress,
      child: ConstrainedBox(
        constraints: BoxConstraints(minHeight: widget.minHeight, minWidth: widget.minWidth),
        child: AnimatedBuilder(
          animation: _depth,
          child: widget.child,
          builder: (context, child) => widget.builder(context, _depth.value, child),
        ),
      ),
    );

    final label = widget.semanticsLabel;
    if (label == null) return surface;
    return Semantics(
      container: true,
      button: true,
      enabled: _enabled,
      selected: widget.selected,
      label: label,
      hint: widget.semanticsHint,
      onTap: widget.onTap,
      onLongPress: widget.onLongPress,
      child: ExcludeSemantics(child: surface),
    );
  }
}
