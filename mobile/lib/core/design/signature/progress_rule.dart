import 'package:flutter/material.dart';

import '../motion.dart';
import '../tokens.dart';

/// A thin rule drawn across the top of a sheet while syncing. A ruling pen is
/// the metaphor: a stroke grows from the left, reaches the edge and starts over.
/// Pass [value] for determinate progress. With reduced motion the rule is simply
/// there, so the state still shows.
class ProgressRule extends StatefulWidget {
  const ProgressRule({
    super.key,
    this.active = true,
    this.value,
    this.color = N360Colors.laterite,
    this.height = 3,
    this.semanticsLabel,
  });

  final bool active;

  /// 0 to 1. Null for an indeterminate sweep.
  final double? value;
  final Color color;
  final double height;
  final String? semanticsLabel;

  @override
  State<ProgressRule> createState() => _ProgressRuleState();
}

class _ProgressRuleState extends State<ProgressRule> with SingleTickerProviderStateMixin {
  late final AnimationController _sweep =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1250));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _sync();
  }

  @override
  void didUpdateWidget(covariant ProgressRule oldWidget) {
    super.didUpdateWidget(oldWidget);
    _sync();
  }

  void _sync() {
    final bool shouldRun = widget.active && widget.value == null && !context.motion.reduced;
    if (shouldRun && !_sweep.isAnimating) {
      _sweep.repeat();
    } else if (!shouldRun && _sweep.isAnimating) {
      _sweep.stop();
    }
  }

  @override
  void dispose() {
    _sweep.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final Widget bar;
    if (!widget.active) {
      bar = const SizedBox.expand();
    } else if (widget.value case final double fraction) {
      bar = TweenAnimationBuilder<double>(
        tween: Tween<double>(end: fraction.clamp(0.0, 1.0)),
        duration: context.motion.quick,
        curve: N360Curves.rise,
        builder: (context, value, _) => CustomPaint(
          painter: _RulePainter(color: widget.color, from: 0, to: value),
        ),
      );
    } else if (context.motion.reduced) {
      bar = CustomPaint(painter: _DashedRulePainter(color: widget.color));
    } else {
      bar = CustomPaint(
        painter: _RulePainter.sweeping(color: widget.color, sweep: _sweep),
      );
    }

    return Semantics(
      label: widget.semanticsLabel,
      child: RepaintBoundary(child: SizedBox(height: widget.height, width: double.infinity, child: bar)),
    );
  }
}

class _RulePainter extends CustomPainter {
  _RulePainter({required this.color, required this.from, required this.to})
      : _sweep = null;

  _RulePainter.sweeping({required this.color, required Animation<double> sweep})
      : from = 0,
        to = 0,
        _sweep = sweep,
        super(repaint: sweep);

  final Color color;
  final double from;
  final double to;
  final Animation<double>? _sweep;

  @override
  void paint(Canvas canvas, Size size) {
    double left = from;
    double right = to;
    final sweep = _sweep;
    if (sweep != null) {
      final double t = sweep.value;
      left = N360Curves.rise.transform((t * 1.3 - 0.3).clamp(0.0, 1.0));
      right = N360Curves.rise.transform((t * 1.3).clamp(0.0, 1.0));
    }
    if (right <= left) return;
    canvas.drawRect(
      Rect.fromLTRB(left * size.width, 0, right * size.width, size.height),
      Paint()..color = color,
    );
  }

  @override
  bool shouldRepaint(_RulePainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.from != from || oldDelegate.to != to;
}

/// The still form of a working rule: dashes read as unfinished, where a solid
/// line would read as done.
class _DashedRulePainter extends CustomPainter {
  const _DashedRulePainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.clipRect(Offset.zero & size);
    final Paint paint = Paint()..color = color;
    for (double x = 0; x < size.width; x += 18) {
      canvas.drawRect(Rect.fromLTWH(x, 0, 10, size.height), paint);
    }
  }

  @override
  bool shouldRepaint(_DashedRulePainter oldDelegate) => oldDelegate.color != color;
}
