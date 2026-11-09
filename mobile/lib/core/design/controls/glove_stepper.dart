import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../signature/rolling_number.dart';
import '../tokens.dart';
import '../typography.dart';

/// Minus and plus around a rolling figure, for headcounts and similar.
///
/// A tap steps once, on release, so a thumb brushing it while scrolling changes
/// nothing. Holding repeats, faster the longer it is held, and the step grows
/// on wide ranges, so reaching 120 does not take a minute of pressing.
class GloveStepper extends StatefulWidget {
  const GloveStepper({
    super.key,
    required this.value,
    required this.onChanged,
    this.min = 0,
    this.max = 9999,
    this.step = 1,
    this.label,
    this.icon,
    this.decreaseLabel = 'Decrease',
    this.increaseLabel = 'Increase',
  });

  final int value;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;
  final int step;
  final String? label;
  final IconData? icon;

  /// Spoken only, so translate if you can.
  final String decreaseLabel;
  final String increaseLabel;

  /// Width of each key. Height is [N360Size.tap].
  static const double keyWidth = 64;

  @override
  State<GloveStepper> createState() => _GloveStepperState();
}

class _GloveStepperState extends State<GloveStepper> {
  late int _value = widget.value;

  @override
  void didUpdateWidget(covariant GloveStepper oldWidget) {
    super.didUpdateWidget(oldWidget);
    _value = widget.value;
  }

  void _bump(int direction, {int multiplier = 1}) {
    final int next = (_value + direction * widget.step * multiplier).clamp(widget.min, widget.max);
    if (next == _value) return;
    setState(() => _value = next);
    N360Haptics.step();
    widget.onChanged(next);
  }

  @override
  Widget build(BuildContext context) {
    final bool canDecrease = _value > widget.min;
    final bool canIncrease = _value < widget.max;
    final bool wideRange = widget.max - widget.min > 200;

    return Semantics(
      container: true,
      label: widget.label,
      value: '$_value',
      increasedValue: canIncrease ? '${_value + widget.step}' : null,
      decreasedValue: canDecrease ? '${_value - widget.step}' : null,
      onIncrease: canIncrease ? () => _bump(1) : null,
      onDecrease: canDecrease ? () => _bump(-1) : null,
      child: ExcludeSemantics(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (widget.label != null) ...<Widget>[
              Row(
                children: <Widget>[
                  if (widget.icon != null) ...<Widget>[
                    Icon(widget.icon, size: N360IconSize.md, color: N360Colors.ink2),
                    const SizedBox(width: N360Space.sm),
                  ],
                  Expanded(child: Text(widget.label!, style: N360Type.fit(N360Type.label, widget.label!))),
                ],
              ),
              const SizedBox(height: N360Space.xs),
            ],
            Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: <Widget>[
                _StepKey(
                  icon: Icons.remove,
                  enabled: canDecrease,
                  wideRange: wideRange,
                  onStep: (m) => _bump(-1, multiplier: m),
                ),
                Expanded(
                  child: SizedBox(
                    height: N360Size.tap,
                    child: Center(
                      child: RollingNumber(value: '$_value', style: N360Type.figureLarge.copyWith(fontSize: 26, letterSpacing: 0)),
                    ),
                  ),
                ),
                _StepKey(
                  icon: Icons.add,
                  filled: true,
                  enabled: canIncrease,
                  wideRange: wideRange,
                  onStep: (m) => _bump(1, multiplier: m),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _StepKey extends StatefulWidget {
  const _StepKey({
    required this.icon,
    required this.enabled,
    required this.wideRange,
    required this.onStep,
    this.filled = false,
  });

  final IconData icon;
  final bool enabled;
  final bool wideRange;
  final bool filled;
  final ValueChanged<int> onStep;

  @override
  State<_StepKey> createState() => _StepKeyState();
}

class _StepKeyState extends State<_StepKey> with SingleTickerProviderStateMixin {
  late final AnimationController _depth = AnimationController(vsync: this);
  Timer? _timer;
  bool _repeated = false;
  int _repeats = 0;

  static const Duration _holdDelay = Duration(milliseconds: 380);

  void _down() {
    if (!widget.enabled) return;
    N360Haptics.tap();
    _repeated = false;
    _repeats = 0;
    _depth.animateTo(1, duration: context.motion.press, curve: N360Curves.press);
    _timer = Timer(_holdDelay, _repeat);
  }

  void _repeat() {
    if (!mounted) return;
    _repeated = true;
    if (widget.wideRange && _repeats > 22) {
      widget.onStep(10);
    } else {
      widget.onStep(1);
    }
    _repeats++;
    final int wait = math.max(45, (165 * math.pow(0.88, _repeats)).round());
    _timer = Timer(Duration(milliseconds: wait), _repeat);
  }

  void _up() {
    _timer?.cancel();
    if (!_repeated && widget.enabled) widget.onStep(1);
    _settle();
  }

  void _cancel() {
    _timer?.cancel();
    _settle();
  }

  void _settle() {
    _depth.animateBack(0, duration: context.motion.release, curve: N360Curves.press);
  }

  @override
  void didUpdateWidget(covariant _StepKey oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!widget.enabled && _timer?.isActive == true) {
      _timer?.cancel();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _depth.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final bool on = widget.enabled;
    final SoftSurfacePainter surface = !on
        ? const SoftSurfacePainter(radius: N360Radius.tile, fill: N360Colors.paper2)
        : widget.filled
            ? const SoftSurfacePainter(radius: N360Radius.tile, fill: N360Colors.primary)
            : const SoftSurfacePainter(radius: N360Radius.tile, edge: N360Colors.ruleStrong);
    final Color ink = !on
        ? N360Colors.inkLabel
        : widget.filled
            ? N360Colors.onPrimary
            : N360Colors.ink;

    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: on ? (_) => _down() : null,
      onTapUp: on ? (_) => _up() : null,
      onTapCancel: on ? _cancel : null,
      child: SizedBox(
        width: GloveStepper.keyWidth,
        height: N360Size.tap,
        child: AnimatedBuilder(
          animation: _depth,
          builder: (context, child) => Transform.scale(
            scale: pressedScale(_depth.value),
            child: CustomPaint(
              painter: surface,
              child: Center(child: Icon(widget.icon, size: N360IconSize.xxl, color: ink)),
            ),
          ),
        ),
      ),
    );
  }
}
