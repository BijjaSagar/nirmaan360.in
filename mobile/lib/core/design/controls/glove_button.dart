import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

enum GloveButtonKind { primary, secondary, ghost, danger }

/// A button made for a gloved thumb. It has thickness: the face sits proud of
/// its block and presses down into it. The icon is optional, the words are not.
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

    final Widget label0 = Flexible(
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
          Icon(icon, size: 24, color: look.text),
          const SizedBox(width: N360Space.sm + 2),
        ],
        label0,
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
            final double sink = depth * (look.edge > 0 ? look.edge - 1 : 0);
            return CustomPaint(
              painter: _ButtonPainter(look: look, sink: sink),
              child: Padding(
                padding: EdgeInsets.only(bottom: look.edge),
                child: Transform.translate(
                  offset: Offset(0, sink),
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minHeight: height - look.edge),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: N360Space.lg + 2, vertical: N360Space.sm),
                      child: Center(widthFactor: 1, child: child),
                    ),
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
  const _Look({required this.face, required this.block, required this.text, this.border, this.edge = N360Size.edge});

  factory _Look.of(GloveButtonKind kind, {required bool enabled}) {
    if (!enabled) {
      return kind == GloveButtonKind.ghost
          ? const _Look(face: Color(0x00000000), block: Color(0x00000000), text: N360Colors.inkLabel, edge: 0)
          : const _Look(
              face: N360Colors.paper2,
              block: N360Colors.rule,
              text: N360Colors.inkLabel,
              border: N360Colors.rule,
            );
    }
    return switch (kind) {
      GloveButtonKind.primary => const _Look(
          face: N360Colors.laterite,
          block: N360Colors.lateriteEdge,
          text: N360Colors.sheet,
        ),
      GloveButtonKind.secondary => const _Look(
          face: N360Colors.sheet,
          block: N360Colors.ink2,
          text: N360Colors.ink,
          border: N360Colors.ink2,
        ),
      GloveButtonKind.ghost => const _Look(
          face: Color(0x00000000),
          block: Color(0x00000000),
          text: N360Colors.ink,
          edge: 0,
        ),
      GloveButtonKind.danger => const _Look(
          face: N360Colors.sheet,
          block: N360Colors.stampRed,
          text: N360Colors.stampRed,
          border: N360Colors.stampRed,
        ),
    };
  }

  final Color face;
  final Color block;
  final Color text;
  final Color? border;
  final double edge;
}

class _ButtonPainter extends CustomPainter {
  const _ButtonPainter({required this.look, required this.sink});

  final _Look look;
  final double sink;

  @override
  void paint(Canvas canvas, Size size) {
    const radius = Radius.circular(N360Radius.small);
    if (look.edge > 0) {
      canvas.drawRRect(
        RRect.fromLTRBR(0, look.edge, size.width, size.height, radius),
        Paint()..color = look.block,
      );
    }
    final RRect face = RRect.fromLTRBR(0, sink, size.width, size.height - look.edge + sink, radius);
    if (look.face.a > 0) canvas.drawRRect(face, Paint()..color = look.face);
    final border = look.border;
    if (border != null) {
      canvas.drawRRect(
        face.deflate(0.75),
        Paint()
          ..color = border
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5,
      );
    } else if (look.face.a == 0) {
      // Ghost: a ruled underline, the way a ledger marks a link.
      canvas.drawLine(
        Offset(0, size.height - 7.5 + sink),
        Offset(size.width, size.height - 7.5 + sink),
        Paint()
          ..color = look.text.withValues(alpha: 0.55)
          ..strokeWidth = 1,
      );
    }
  }

  @override
  bool shouldRepaint(_ButtonPainter oldDelegate) => oldDelegate.look != look || oldDelegate.sink != sink;
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
        dimension: 22,
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
        ..strokeCap = StrokeCap.butt
        ..strokeWidth = 3,
    );
  }

  @override
  bool shouldRepaint(_ArcPainter oldDelegate) => oldDelegate.color != color;
}
