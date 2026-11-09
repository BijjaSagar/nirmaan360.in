import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../haptics.dart';
import '../motion.dart';
import '../signature/rolling_number.dart';
import '../tokens.dart';
import '../typography.dart';

/// Big minus and plus around a rolling figure, for headcounts and similar.
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
                    Icon(widget.icon, size: 22, color: N360Colors.ink2),
                    const SizedBox(width: N360Space.sm),
                  ],
                  Expanded(child: Text(widget.label!, style: N360Type.label)),
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
                    height: N360Size.tapLarge,
                    child: Center(
                      child: RollingNumber(value: '$_value', style: N360Type.figureLarge.copyWith(fontSize: 34)),
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
    final Color face = !on
        ? N360Colors.paper2
        : (widget.filled ? N360Colors.ink : N360Colors.sheet);
    final Color edge = !on
        ? N360Colors.rule
        : (widget.filled ? N360Colors.inkEdge : N360Colors.ink2);
    final Color ink = !on
        ? N360Colors.ink3
        : (widget.filled ? N360Colors.sheet : N360Colors.ink);

    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: on ? (_) => _down() : null,
      onTapUp: on ? (_) => _up() : null,
      onTapCancel: on ? _cancel : null,
      child: SizedBox(
        width: 76,
        height: N360Size.tapLarge,
        child: AnimatedBuilder(
          animation: _depth,
          builder: (context, child) {
            final double sink = _depth.value * (N360Size.edge - 1);
            return CustomPaint(
              painter: StepKeyPainter(face: face, edge: edge, sink: sink, outlined: !widget.filled && on),
              child: Padding(
                padding: const EdgeInsets.only(bottom: N360Size.edge),
                child: Transform.translate(
                  offset: Offset(0, sink),
                  child: Center(child: Icon(widget.icon, size: 34, color: ink)),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

/// A chunky square key with a visible block under its face.
class StepKeyPainter extends CustomPainter {
  const StepKeyPainter({required this.face, required this.edge, required this.sink, this.outlined = true});

  final Color face;
  final Color edge;
  final double sink;
  final bool outlined;

  @override
  void paint(Canvas canvas, Size size) {
    const radius = Radius.circular(N360Radius.small);
    canvas.drawRRect(
      RRect.fromLTRBR(0, N360Size.edge, size.width, size.height, radius),
      Paint()..color = edge,
    );
    final RRect top = RRect.fromLTRBR(0, sink, size.width, size.height - N360Size.edge + sink, radius);
    canvas.drawRRect(top, Paint()..color = face);
    if (outlined) {
      canvas.drawRRect(
        top.deflate(0.75),
        Paint()
          ..color = N360Colors.ink2
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5,
      );
    }
  }

  @override
  bool shouldRepaint(StepKeyPainter oldDelegate) =>
      oldDelegate.face != face || oldDelegate.edge != edge || oldDelegate.sink != sink || oldDelegate.outlined != outlined;
}
