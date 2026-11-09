import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../tokens.dart';
import '../typography.dart';

/// A home-screen tile: a big picture sitting on a surveyor's station mark, with
/// its name beneath. A badge counts what is waiting.
class ActionTile extends StatelessWidget {
  const ActionTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.badge,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  /// Shown as a small stamped count, such as a pending total. Null hides it.
  final String? badge;

  @override
  Widget build(BuildContext context) {
    final Widget contents = Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        SizedBox(
          height: 64,
          width: 72,
          child: CustomPaint(
            painter: const _StationMarkPainter(),
            child: Center(child: Icon(icon, size: 40, color: N360Colors.ink)),
          ),
        ),
        const SizedBox(height: N360Space.xs),
        Text(
          label,
          textAlign: TextAlign.center,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: N360Type.heading.copyWith(height: 1.26, fontSize: 21),
        ),
      ],
    );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      minHeight: 124,
      semanticsLabel: badge == null ? label : '$label, $badge',
      child: contents,
      builder: (context, depth, child) {
        final double sink = depth * (N360Size.edge - 1);
        return Stack(
          fit: StackFit.passthrough,
          clipBehavior: Clip.none,
          children: <Widget>[
            CustomPaint(
              painter: _ActionPainter(sink: sink),
              child: Padding(
                padding: const EdgeInsets.only(bottom: N360Size.edge),
                child: Transform.translate(
                  offset: Offset(0, sink),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(minHeight: 121),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.sm, N360Space.md, N360Space.sm, N360Space.md),
                      child: Center(child: child),
                    ),
                  ),
                ),
              ),
            ),
            if (badge != null)
              Positioned(
                top: 6 + sink,
                right: 6,
                child: _CountBadge(text: badge!),
              ),
          ],
        );
      },
    );
  }
}

class _ActionPainter extends CustomPainter {
  const _ActionPainter({required this.sink});

  final double sink;

  @override
  void paint(Canvas canvas, Size size) {
    const radius = Radius.circular(N360Radius.small);
    canvas.drawRRect(
      RRect.fromLTRBR(0, N360Size.edge, size.width, size.height, radius),
      Paint()..color = N360Colors.paperEdge,
    );
    final RRect face = RRect.fromLTRBR(0, sink, size.width, size.height - N360Size.edge + sink, radius);
    canvas.drawRRect(face, Paint()..color = N360Colors.sheet);
    canvas.drawRRect(
      face.deflate(0.5),
      Paint()
        ..color = N360Colors.rule
        ..style = PaintingStyle.stroke,
    );
  }

  @override
  bool shouldRepaint(_ActionPainter oldDelegate) => oldDelegate.sink != sink;
}

/// A survey station: a ring and crosshair drawn behind the icon.
class _StationMarkPainter extends CustomPainter {
  const _StationMarkPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final Paint line = Paint()
      ..color = N360Colors.paperEdge
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;
    final Offset c = size.center(Offset.zero);
    final double r = size.shortestSide / 2 - 1;
    canvas.drawCircle(c, r, line);
    canvas.drawLine(Offset(c.dx - r - 8, c.dy), Offset(c.dx + r + 8, c.dy), line);
    canvas.drawLine(Offset(c.dx, c.dy - r - 2), Offset(c.dx, c.dy + r + 2), line);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _CountBadge extends StatelessWidget {
  const _CountBadge({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Transform.rotate(
      angle: -7 * math.pi / 180,
      child: DecoratedBox(
        decoration: const BoxDecoration(color: N360Colors.laterite, borderRadius: N360Radius.sm),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
          child: Text(
            text,
            style: N360Type.figure.copyWith(color: N360Colors.sheet, fontSize: 16),
          ),
        ),
      ),
    );
  }
}
