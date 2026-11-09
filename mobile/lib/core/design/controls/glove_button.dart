import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

enum GloveButtonKind { primary, secondary, ghost, danger }

/// A button made for a gloved thumb. The primary is a gold gradient with dark
/// ink on it, and every button squeezes under the thumb. The words are required.
class GloveButton extends StatelessWidget {
  const GloveButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.kind = GloveButtonKind.primary,
    this.icon,
    this.fullWidth = false,
    this.loading = false,
    this.large = false,
  });

  final String label;

  /// Null shows the button as unavailable.
  final VoidCallback? onPressed;
  final GloveButtonKind kind;
  final IconData? icon;
  final bool fullWidth;

  /// Shows a spinner, keeps the label, and ignores taps.
  final bool loading;

  /// 64dp instead of 56dp, for the main action of a screen.
  final bool large;

  @override
  Widget build(BuildContext context) {
    final _Look look = _Look.of(kind, enabled: onPressed != null);
    final double height = large ? N360Size.tapLarge : N360Size.tap;

    final Widget text = Flexible(
      child: Text(
        label,
        maxLines: 2,
        textAlign: TextAlign.center,
        overflow: TextOverflow.ellipsis,
        style: N360Type.button.copyWith(color: look.text),
      ),
    );

    final Widget content = Row(
      mainAxisSize: fullWidth ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: <Widget>[
        if (loading) ...<Widget>[
          _BusyMark(color: look.text),
          const SizedBox(width: N360Space.sm + 2),
        ] else if (icon != null) ...<Widget>[
          Icon(icon, size: 23, color: look.text),
          const SizedBox(width: N360Space.sm + 2),
        ],
        text,
      ],
    );

    return Semantics(
      container: true,
      button: true,
      enabled: onPressed != null && !loading,
      label: label,
      onTap: loading ? null : onPressed,
      child: ExcludeSemantics(
        child: PressTarget(
          onTap: loading ? null : onPressed,
          haptic: N360Haptics.tap,
          minHeight: height,
          builder: (context, depth, child) {
            return Transform.scale(
              scale: pressedScale(depth),
              child: CustomPaint(
                painter: look.surface,
                child: ConstrainedBox(
                  constraints: BoxConstraints(minHeight: height),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: N360Space.lg + 2, vertical: N360Space.sm),
                    child: Center(widthFactor: 1, child: child),
                  ),
                ),
              ),
            );
          },
          child: content,
        ),
      ),
    );
  }
}

class _Look {
  const _Look({required this.surface, required this.text});

  factory _Look.of(GloveButtonKind kind, {required bool enabled}) {
    const double r = N360Radius.tile;
    if (!enabled) {
      return kind == GloveButtonKind.ghost
          ? const _Look(
              surface: SoftSurfacePainter(radius: r, fill: Color(0x00000000)),
              text: N360Colors.inkLabel,
            )
          : const _Look(
              surface: SoftSurfacePainter(radius: r, fill: N360Colors.paper2),
              text: N360Colors.inkLabel,
            );
    }
    return switch (kind) {
      GloveButtonKind.primary => const _Look(
          surface: SoftSurfacePainter(radius: r, gradient: N360Gradients.gold, edge: Color(0x55FFFFFF)),
          text: N360Colors.ink,
        ),
      GloveButtonKind.secondary => const _Look(
          surface: SoftSurfacePainter(radius: r, edge: N360Colors.ruleStrong, edgeWidth: 1.25),
          text: N360Colors.ink,
        ),
      GloveButtonKind.ghost => const _Look(
          surface: SoftSurfacePainter(radius: r, fill: N360Colors.paper2),
          text: N360Colors.ink,
        ),
      GloveButtonKind.danger => const _Look(
          surface: SoftSurfacePainter(radius: r, edge: N360Colors.stampRed, edgeWidth: 1.5),
          text: N360Colors.stampRed,
        ),
    };
  }

  final SoftSurfacePainter surface;
  final Color text;
}

/// A small rotating arc for busy buttons. It is static under reduced motion.
class _BusyMark extends StatefulWidget {
  const _BusyMark({required this.color});

  final Color color;

  @override
  State<_BusyMark> createState() => _BusyMarkState();
}

class _BusyMarkState extends State<_BusyMark> with SingleTickerProviderStateMixin {
  late final AnimationController _spin =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 900));

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
      child: SizedBox.square(
        dimension: 21,
        child: RotationTransition(
          turns: _spin,
          child: CustomPaint(painter: _ArcPainter(color: widget.color)),
        ),
      ),
    );
  }
}

class _ArcPainter extends CustomPainter {
  const _ArcPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawArc(
      (Offset.zero & size).deflate(2),
      0,
      math.pi * 1.45,
      false,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeWidth = 3,
    );
  }

  @override
  bool shouldRepaint(_ArcPainter oldDelegate) => oldDelegate.color != color;
}
