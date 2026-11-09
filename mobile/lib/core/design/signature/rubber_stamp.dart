import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';

import '../haptics.dart';
import '../motion.dart';
import '../surfaces/ledger_paper.dart';
import '../tokens.dart';
import '../typography.dart';

enum StampKind { draft, inReview, approved, returned, rejected, queued, synced }

enum StampSize {
  small(fontSize: 13.5, stroke: 1.4, gap: 1.2, hPad: 7, vPad: 1.5, tilt: 3),
  medium(fontSize: 18, stroke: 1.9, gap: 1.6, hPad: 11, vPad: 3, tilt: 6),
  large(fontSize: 26, stroke: 2.5, gap: 2, hPad: 15, vPad: 5, tilt: 10),
  hero(fontSize: 44, stroke: 4, gap: 3, hPad: 22, vPad: 7, tilt: 18);

  const StampSize({
    required this.fontSize,
    required this.stroke,
    required this.gap,
    required this.hPad,
    required this.vPad,
    required this.tilt,
  });

  final double fontSize;
  final double stroke;
  final double gap;
  final double hPad;
  final double vPad;

  /// Vertical room reserved for the corners of the rotated face.
  final double tilt;
}

const double _restAngle = -7 * math.pi / 180;
const double _liftedAngle = -14 * math.pi / 180;
const double _liftedScale = 2.2;

extension StampKindInk on StampKind {
  /// Every kind stays at 4.5:1 or better on paper, sheet and paper2.
  Color get inkColor => switch (this) {
        StampKind.draft => N360Colors.ink2,
        StampKind.inReview => N360Colors.neel,
        StampKind.approved => N360Colors.mossInk,
        StampKind.returned => N360Colors.haldiInk,
        StampKind.rejected => N360Colors.stampRed,
        StampKind.queued => N360Colors.ink2,
        StampKind.synced => N360Colors.mossInk,
      };
}

/// A status pressed into the page at a slight angle. Colour is never the only
/// signal: the caller's translated [label] is always on the face.
///
/// With [animate] it lands with a thud: lifted large and turned further, then
/// pressed down past its resting size and released. It is opaque from the first
/// frame, because a status must never depend on an animation to be read.
class RubberStamp extends StatefulWidget {
  const RubberStamp({
    super.key,
    required this.kind,
    required this.label,
    this.size = StampSize.medium,
    this.animate = false,
    this.onLanded,
  });

  final StampKind kind;
  final String label;
  final StampSize size;
  final bool animate;

  /// Called once, as the stamp first touches the page.
  final VoidCallback? onLanded;

  @override
  State<RubberStamp> createState() => _RubberStampState();
}

class _RubberStampState extends State<RubberStamp> with SingleTickerProviderStateMixin {
  AnimationController? _thud;
  bool _landed = false;

  /// The stamp curve first reaches its resting size at this point, 152ms into
  /// the 450ms web timing. That is the moment of contact.
  static const double _contact = 0.34;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (widget.animate && _thud == null && !context.motion.reduced) {
      _play();
    } else if (widget.animate && _thud == null && !_landed) {
      _landed = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => widget.onLanded?.call());
    }
  }

  @override
  void didUpdateWidget(covariant RubberStamp oldWidget) {
    super.didUpdateWidget(oldWidget);
    final bool changed = oldWidget.kind != widget.kind || oldWidget.label != widget.label;
    if (widget.animate && (!oldWidget.animate || changed) && !context.motion.reduced) {
      _play();
    }
  }

  void _play() {
    _landed = false;
    final controller = _thud ??= AnimationController(vsync: this, duration: context.motion.stamp)
      ..addListener(_watchContact);
    controller.forward(from: 0);
  }

  void _watchContact() {
    if (!_landed && _thud!.value >= _contact) {
      _landed = true;
      widget.onLanded?.call();
    }
  }

  @override
  void dispose() {
    _thud?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final Widget face = _StampFace(kind: widget.kind, label: widget.label, size: widget.size);
    final thud = _thud;
    if (thud == null || !widget.animate) return face;
    return RepaintBoundary(
      child: AnimatedBuilder(
        animation: thud,
        child: face,
        builder: (context, child) {
          final double v = N360Curves.stamp.transform(thud.value);
          return Transform.rotate(
            angle: lerpDouble(_liftedAngle - _restAngle, 0, v)!,
            child: Transform.scale(scale: lerpDouble(_liftedScale, 1, v)!, child: child),
          );
        },
      ),
    );
  }
}

class _StampFace extends StatelessWidget {
  const _StampFace({required this.kind, required this.label, required this.size, this.onSlip = false});

  final StampKind kind;
  final String label;
  final StampSize size;

  /// Painted over ruled lines on the hero slip, so the ink wear matches.
  final bool onSlip;

  @override
  Widget build(BuildContext context) {
    final Color ink = kind.inkColor;
    final String text = N360Type.eyebrowText(label);
    final bool dashed = kind == StampKind.queued;
    final double inset = size.stroke + size.gap + 1;

    final TextStyle style = TextStyle(
      fontFamily: N360Type.khand,
      fontFamilyFallback: const <String>[N360Type.mukta],
      fontSize: size.fontSize,
      fontWeight: FontWeight.w700,
      height: 1.34,
      leadingDistribution: TextLeadingDistribution.even,
      decoration: TextDecoration.none,
      letterSpacing: N360Type.trackingFor(label, size.fontSize * 0.06),
      color: ink,
    );

    final TextScaler scaler = MediaQuery.textScalerOf(context).clamp(maxScaleFactor: 1.3);

    return Semantics(
      container: true,
      label: label,
      child: ExcludeSemantics(
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: size.tilt, horizontal: size.tilt * 0.5),
          child: Transform.rotate(
            angle: _restAngle,
            child: CustomPaint(
              painter: _StampBorderPainter(color: ink, stroke: size.stroke, gap: size.gap, dashed: dashed),
              foregroundPainter: _StampWearPainter(seed: label.hashCode, onSlip: onSlip),
              child: Padding(
                padding: EdgeInsets.symmetric(horizontal: size.hPad + inset, vertical: size.vPad + inset),
                child: Text(text, style: style, textScaler: scaler, maxLines: 1, softWrap: false),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _StampBorderPainter extends CustomPainter {
  const _StampBorderPainter({required this.color, required this.stroke, required this.gap, required this.dashed});

  final Color color;
  final double stroke;
  final double gap;
  final bool dashed;

  @override
  void paint(Canvas canvas, Size size) {
    final outer = RRect.fromRectAndRadius(
      (Offset.zero & size).deflate(stroke / 2),
      const Radius.circular(N360Radius.small),
    );
    final inner = outer.deflate(stroke / 2 + gap + 0.5);
    final Paint paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    if (dashed) {
      _dashRect(canvas, outer.outerRect, paint);
    } else {
      canvas.drawRRect(outer, paint);
    }
    canvas.drawRRect(
      inner,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = math.max(1, stroke * 0.5),
    );
  }

  void _dashRect(Canvas canvas, Rect r, Paint paint) {
    final double dash = stroke * 4;
    final double skip = stroke * 2.2;
    void run(Offset a, Offset b) {
      final total = (b - a).distance;
      final dir = (b - a) / total;
      for (double d = 0; d < total; d += dash + skip) {
        canvas.drawLine(a + dir * d, a + dir * math.min(d + dash, total), paint);
      }
    }

    run(r.topLeft, r.topRight);
    run(r.topRight, r.bottomRight);
    run(r.bottomRight, r.bottomLeft);
    run(r.bottomLeft, r.topLeft);
  }

  @override
  bool shouldRepaint(_StampBorderPainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.stroke != stroke || oldDelegate.dashed != dashed;
}

/// Knocks a few flecks out of the ink so the impression looks pressed, not
/// printed. The positions come from the label, so a stamp never shimmers.
class _StampWearPainter extends CustomPainter {
  const _StampWearPainter({required this.seed, required this.onSlip});

  final int seed;
  final bool onSlip;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = (onSlip ? N360Colors.sheet : N360Colors.paper).withValues(alpha: 0.82);
    var s = (seed & 0x7fffffff) | 1;
    int next() => s = (s * 1103515245 + 12345) & 0x7fffffff;
    final bool small = size.height < 36;
    final count = small ? 5 + (size.width / 26).floor() : 10 + (size.width / 18).floor();
    for (var i = 0; i < count; i++) {
      final dx = (next() % 1000) / 1000 * size.width;
      final dy = (next() % 1000) / 1000 * size.height;
      final radius = 0.3 + (next() % 100) / 100 * (size.height > 60 ? 1.5 : (small ? 0.35 : 0.6));
      canvas.drawCircle(Offset(dx, dy), radius, paint);
    }
  }

  @override
  bool shouldRepaint(_StampWearPainter oldDelegate) => oldDelegate.seed != seed;
}

/// Slams a stamp onto a slip in the middle of the screen, for approvals and
/// submissions. The page behind jolts on impact and a heavy haptic fires at
/// that same moment, never at the start. Clears itself in under a second and
/// the returned future completes when it has gone.
///
/// With reduced motion the stamp simply shows for a moment without moving.
Future<void> showStampThud(BuildContext context, StampKind kind, String label) {
  final overlay = Overlay.of(context, rootOverlay: true);
  final completer = Completer<void>();
  final textDirection = Directionality.maybeOf(context) ?? TextDirection.ltr;
  unawaited(SemanticsService.sendAnnouncement(View.of(context), label, textDirection));

  late final OverlayEntry entry;
  entry = OverlayEntry(
    builder: (context) => Material(
      type: MaterialType.transparency,
      child: _StampThudMoment(
        kind: kind,
        label: label,
        onDone: () {
          entry.remove();
          entry.dispose();
          completer.complete();
        },
      ),
    ),
  );
  overlay.insert(entry);
  return completer.future;
}

class _StampThudMoment extends StatefulWidget {
  const _StampThudMoment({required this.kind, required this.label, required this.onDone});

  final StampKind kind;
  final String label;
  final VoidCallback onDone;

  @override
  State<_StampThudMoment> createState() => _StampThudMomentState();
}

class _StampThudMomentState extends State<_StampThudMoment> with SingleTickerProviderStateMixin {
  // The slam lasts 18 percent of the timeline, then the stamp is released.
  static const double _impact = 0.18;
  static const double _settled = 0.42;
  static const double _leaving = 0.84;
  static const double _scrimPeak = 0.42;

  late final AnimationController _timeline = AnimationController(vsync: this);
  bool _started = false;
  bool _hit = false;
  late bool _reduced;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    _reduced = context.motion.reduced;
    _timeline.duration = _reduced ? N360Durations.thudStill : context.motion.thud;
    _timeline.addListener(_onTick);
    _timeline.forward().whenComplete(_finish);
    if (_reduced) N360Haptics.thud();
  }

  void _onTick() {
    if (_reduced) return;
    final double t = _timeline.value;
    if (!_hit && t >= _impact) {
      _hit = true;
      N360Haptics.thud();
    }
    // A short, sharp push down into the desk that decays back to rest.
    if (t >= _impact && t < _settled) {
      final double p = (t - _impact) / (_settled - _impact);
      paperJolt.value = Offset(0, 4 * math.sin(p * math.pi) * (1 - p));
    } else if (paperJolt.value != Offset.zero) {
      paperJolt.value = Offset.zero;
    }
  }

  void _finish() {
    if (!mounted) return;
    paperJolt.value = Offset.zero;
    widget.onDone();
  }

  @override
  void dispose() {
    paperJolt.value = Offset.zero;
    _timeline.dispose();
    super.dispose();
  }

  /// Scale of the stamp above the page, then squashing into it and recovering.
  double _stampScale(double t) {
    if (t < _impact) {
      return lerpDouble(_liftedScale, 0.9, N360Curves.slam.transform(t / _impact))!;
    }
    if (t < _settled) {
      return lerpDouble(0.9, 1, Curves.easeOutBack.transform((t - _impact) / (_settled - _impact)))!;
    }
    return 1;
  }

  double _stampAngle(double t) {
    if (t >= _impact) return 0;
    return lerpDouble(_liftedAngle - _restAngle, 0, N360Curves.slam.transform(t / _impact))!;
  }

  double _exitOffset(double t, double height) {
    if (t <= _leaving) return 0;
    return -height * 0.72 * Curves.easeInCubic.transform((t - _leaving) / (1 - _leaving));
  }

  double _scrim(double t) {
    if (t < 0.1) return _scrimPeak * (t / 0.1);
    if (t > _leaving) return _scrimPeak * (1 - (t - _leaving) / (1 - _leaving));
    return _scrimPeak;
  }

  @override
  Widget build(BuildContext context) {
    final Size screen = MediaQuery.sizeOf(context);
    final Widget slip = _ThudSlip(
      child: _StampFace(kind: widget.kind, label: widget.label, size: StampSize.hero, onSlip: true),
    );

    if (_reduced) {
      return AbsorbPointer(
        child: ColoredBox(
          color: N360Colors.ink.withValues(alpha: _scrimPeak),
          child: Center(child: slip),
        ),
      );
    }

    return AbsorbPointer(
      child: RepaintBoundary(
        child: AnimatedBuilder(
          animation: _timeline,
          builder: (context, _) {
            final double t = _timeline.value;
            final double jolt = paperJolt.value.dy;
            return Stack(
              fit: StackFit.expand,
              children: <Widget>[
                ColoredBox(color: N360Colors.ink.withValues(alpha: _scrim(t))),
                Center(
                  child: Transform.translate(
                    offset: Offset(0, _exitOffset(t, screen.height) + jolt * 1.5),
                    child: _ThudSlip(
                      child: Transform.rotate(
                        angle: _stampAngle(t),
                        child: Transform.scale(
                          scale: _stampScale(t),
                          child: _StampFace(
                            kind: widget.kind,
                            label: widget.label,
                            size: StampSize.hero,
                            onSlip: true,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// The sheet the stamp lands on: ruled, with a red margin, like a torn page.
class _ThudSlip extends StatelessWidget {
  const _ThudSlip({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: const _ThudSlipPainter(),
      child: SizedBox(
        width: 300,
        height: 216,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.lg, N360Space.lg, N360Space.lg + N360Space.sm),
          child: Center(child: FittedBox(fit: BoxFit.scaleDown, child: child)),
        ),
      ),
    );
  }
}

class _ThudSlipPainter extends CustomPainter {
  const _ThudSlipPainter();

  @override
  void paint(Canvas canvas, Size size) {
    const edge = 5.0;
    final face = Rect.fromLTWH(0, 0, size.width, size.height - edge);
    canvas.drawRect(Rect.fromLTWH(3, edge, size.width - 3, size.height - edge), Paint()..color = N360Colors.paperEdge);
    canvas.drawRect(face, Paint()..color = N360Colors.sheet);

    final rule = Paint()
      ..color = N360Colors.rule
      ..strokeWidth = 1;
    for (double y = 35.5; y < face.height; y += 36) {
      canvas.drawLine(Offset(0, y), Offset(face.width, y), rule);
    }
    final margin = Paint()
      ..color = N360Colors.marginRed
      ..strokeWidth = 1;
    canvas.drawLine(const Offset(34.5, 0), Offset(34.5, face.height), margin);
    canvas.drawLine(const Offset(37.5, 0), Offset(37.5, face.height), margin);
    canvas.drawRect(
      face.deflate(0.5),
      Paint()
        ..color = N360Colors.ruleStrong
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
