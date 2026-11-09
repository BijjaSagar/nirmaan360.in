import 'package:flutter/material.dart';

import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';
import 'rolling_number.dart';

enum SyncVisualState { synced, pending, syncing, offline, attention }

/// The sync status tag for the top of a screen. The name follows the brief; the
/// shape is a square-cornered ledger tag. An icon and words are always shown,
/// so colour never carries the meaning alone.
class SyncPill extends StatelessWidget {
  const SyncPill({
    super.key,
    required this.state,
    required this.label,
    this.count,
    this.onTap,
  });

  final SyncVisualState state;

  /// Translated text such as "3 waiting".
  final String label;

  /// Items still to send. Rolls down as they leave.
  final int? count;
  final VoidCallback? onTap;

  ({IconData icon, Color ink}) get _look => switch (state) {
        SyncVisualState.synced => (icon: Icons.cloud_done, ink: N360Colors.mossInk),
        SyncVisualState.pending => (icon: Icons.schedule, ink: N360Colors.haldiInk),
        SyncVisualState.syncing => (icon: Icons.sync, ink: N360Colors.neel),
        SyncVisualState.offline => (icon: Icons.cloud_off, ink: N360Colors.ink2),
        SyncVisualState.attention => (icon: Icons.error_outline, ink: N360Colors.stampRed),
      };

  @override
  Widget build(BuildContext context) {
    final look = _look;
    final Widget inner = Row(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (state == SyncVisualState.syncing)
          _SpinningIcon(icon: look.icon, color: look.ink)
        else
          Icon(look.icon, size: 20, color: look.ink),
        const SizedBox(width: 6),
        if (count != null) ...<Widget>[
          RollingNumber(
            value: '$count',
            style: N360Type.figureSmall.copyWith(color: look.ink, fontWeight: FontWeight.w500),
          ),
          const SizedBox(width: 5),
        ],
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: N360Type.label.copyWith(color: look.ink),
          ),
        ),
      ],
    );

    return Semantics(
      container: true,
      button: onTap != null,
      label: count == null ? label : '$count $label',
      onTap: onTap,
      child: ExcludeSemantics(
        child: PressTarget(
          onTap: onTap,
          minHeight: N360Size.tap,
          child: inner,
          builder: (context, depth, child) => Center(
            widthFactor: 1,
            child: Transform.translate(
              offset: Offset(0, depth * 1),
              child: CustomPaint(
                painter: const SoftSurfacePainter(radius: 14, edge: N360Colors.rule),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 40),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    child: child,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SpinningIcon extends StatefulWidget {
  const _SpinningIcon({required this.icon, required this.color});

  final IconData icon;
  final Color color;

  @override
  State<_SpinningIcon> createState() => _SpinningIconState();
}

class _SpinningIconState extends State<_SpinningIcon> with SingleTickerProviderStateMixin {
  late final AnimationController _spin =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (context.motion.reduced) {
      _spin.stop();
    } else if (!_spin.isAnimating) {
      _spin.repeat();
    }
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(
      child: RotationTransition(
        turns: _spin,
        child: Icon(widget.icon, size: 20, color: widget.color),
      ),
    );
  }
}
