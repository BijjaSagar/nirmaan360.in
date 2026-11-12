import 'package:flutter/material.dart';

import '../haptics.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// A red-edged bar that fills while pressed and fires only when full. Letting
/// go early empties it again. With animations off it is a plain button,
/// because the screen around it is already a second step.
class HoldToConfirm extends StatefulWidget {
  const HoldToConfirm({super.key, required this.label, required this.spokenLabel, required this.onConfirmed});

  final String label;
  final String spokenLabel;
  final VoidCallback onConfirmed;

  @override
  State<HoldToConfirm> createState() => _HoldToConfirmState();
}

class _HoldToConfirmState extends State<HoldToConfirm> with SingleTickerProviderStateMixin {
  static const Duration _hold = Duration(milliseconds: 1400);

  late final AnimationController _fill;

  @override
  void initState() {
    super.initState();
    _fill = AnimationController(vsync: this, duration: _hold)
      ..addStatusListener((status) {
        if (status == AnimationStatus.completed) {
          N360Haptics.thud();
          widget.onConfirmed();
        }
      });
  }

  @override
  void dispose() {
    _fill.dispose();
    super.dispose();
  }

  void _start() {
    N360Haptics.tap();
    _fill.forward();
  }

  void _stop() {
    if (_fill.status != AnimationStatus.completed) _fill.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final bool reduced = context.motion.reduced;
    final Widget label = Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.lg, vertical: N360Space.sm),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          const Icon(Icons.touch_app_outlined, size: N360IconSize.button, color: N360Colors.stampRed),
          const SizedBox(width: N360Space.sm),
          Flexible(
            child: Text(
              widget.label,
              textAlign: TextAlign.center,
              style: N360Type.fit(N360Type.button, widget.label).copyWith(color: N360Colors.stampRed),
            ),
          ),
        ],
      ),
    );

    final Widget face = ConstrainedBox(
      constraints: const BoxConstraints(minHeight: N360Size.tap),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: N360Colors.sheet,
          borderRadius: N360Radius.tileRadius,
          border: Border.all(color: N360Colors.stampRed, width: 1.25),
        ),
        child: ClipRRect(
          borderRadius: N360Radius.tileRadius,
          child: Stack(
            alignment: Alignment.center,
            children: <Widget>[
              if (!reduced)
                Positioned.fill(
                  child: AnimatedBuilder(
                    animation: _fill,
                    builder: (context, _) => FractionallySizedBox(
                      alignment: Alignment.centerLeft,
                      widthFactor: _fill.value,
                      heightFactor: 1,
                      child: ColoredBox(color: N360Colors.stampRed.withValues(alpha: 0.16)),
                    ),
                  ),
                ),
              label,
            ],
          ),
        ),
      ),
    );

    return Semantics(
      container: true,
      button: true,
      label: widget.spokenLabel,
      onTap: widget.onConfirmed,
      child: ExcludeSemantics(
        child: reduced
            ? GestureDetector(behavior: HitTestBehavior.opaque, onTap: widget.onConfirmed, child: face)
            : Listener(
                behavior: HitTestBehavior.opaque,
                onPointerDown: (_) => _start(),
                onPointerUp: (_) => _stop(),
                onPointerCancel: (_) => _stop(),
                child: face,
              ),
      ),
    );
  }
}
