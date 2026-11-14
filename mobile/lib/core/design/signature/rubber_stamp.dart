import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';

import '../haptics.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../surfaces/ledger_paper.dart';
import '../tokens.dart';
import '../typography.dart';

enum StampKind { draft, inReview, approved, returned, rejected, queued, synced }

/// How a status is shown.
///
/// [small] and [medium] are quiet pills: straight, a dot and the word, for
/// lists and headers. [large] and [hero] are the one real stamp, kept for the
/// approval moment ([showStampThud]) and a document's final state.
enum StampSize {
  small(fontSize: 12, stroke: 1.5, gap: 1.2, hPad: 8, vPad: 2, tilt: 0),
  medium(fontSize: 12, stroke: 1.5, gap: 1.2, hPad: 9, vPad: 3, tilt: 0),
  large(fontSize: 18, stroke: 2, gap: 1.6, hPad: 12, vPad: 4, tilt: 6),
  hero(fontSize: 30, stroke: 3, gap: 2.4, hPad: 18, vPad: 6, tilt: 10);

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

  /// True for the quiet pill form.
  bool get isPill => this == small || this == medium;
}

const double _restAngle = -4 * math.pi / 180;
const double _liftedAngle = -10 * math.pi / 180;
const double _liftedScale = 2.0;

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

  /// The pill's fill: a faint, opaque wash of the ink on white.
  Color get washColor => Color.alphaBlend(inkColor.withValues(alpha: 0.1), N360Colors.sheet);
}

/// A status. Colour is never the only signal: the caller's translated [label]
/// is always on the face.
///
/// At [StampSize.small] or [StampSize.medium] this is a quiet pill and
/// [animate] does nothing. At [StampSize.large] or [StampSize.hero] it is the
/// stamp: with [animate] it lands with a thud, lifted and turned, then pressed
/// down past its resting size and released. It is opaque from the first frame,
/// because a status must never depend on an animation to be read.
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

  /// Called once, as the stamp first touches the page. A pill calls it on its
  /// first frame.
  final VoidCallback? onLanded;

  @override
  State<RubberStamp> createState() => _RubberStampState();
}

/// The quiet status pill, by its own name for screen authors.
class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.kind, required this.label});

  final StampKind kind;
  final String label;

  @override
  Widget build(BuildContext context) => RubberStamp(kind: kind, label: label, size: StampSize.small);
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
    if (!widget.animate || _landed && _thud == null) return;
    if (widget.size.isPill || context.motion.reduced) {
      if (_thud == null && !_landed) {
        _landed = true;
        WidgetsBinding.instance.addPostFrameCallback((_) => widget.onLanded?.call());
      }
      return;
    }
    if (_thud == null) _play();
  }

  @override
  void didUpdateWidget(covariant RubberStamp oldWidget) {
    super.didUpdateWidget(oldWidget);
    final bool changed = oldWidget.kind != widget.kind || oldWidget.label != widget.label;
    if (widget.animate && (!oldWidget.animate || changed) && !widget.size.isPill && !context.motion.reduced) {
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
    if (widget.size.isPill) return _PillFace(kind: widget.kind, label: widget.label, size: widget.size);
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

/// The quiet pill: a faint wash, a dot in the status ink, and the word as the
/// caller wrote it. Queued shows a ring instead of a dot: not there yet.
class _PillFace extends StatelessWidget {
  const _PillFace({required this.kind, required this.label, required this.size});

  final StampKind kind;
  final String label;
  final StampSize size;

  @override
  Widget build(BuildContext context) {
    final Color ink = kind.inkColor;
    final bool hollow = kind == StampKind.queued;
    return Semantics(
      container: true,
      label: label,
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: kind.washColor,
            borderRadius: const BorderRadius.all(Radius.circular(N360Size.pill)),
          ),
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: size.hPad, vertical: size.vPad),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                SizedBox.square(
                  dimension: 7,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: hollow ? null : ink,
                      border: hollow ? Border.all(color: ink, width: 1.5) : null,
                    ),
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  label,
                  maxLines: 1,
                  softWrap: false,
                  style: N360Type.fit(N360Type.caption, label).copyWith(color: ink, height: 1.3),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The one real stamp: capitals in a double ring, pressed at a slight angle.
/// Clean ink, no distressing.
class _StampFace extends StatelessWidget {
  const _StampFace({required this.kind, required this.label, required this.size});

  final StampKind kind;
  final String label;
  final StampSize size;

  @override
  Widget build(BuildContext context) {
    final Color ink = kind.inkColor;
    final String text = N360Type.stampText(label);
    final bool dashed = kind == StampKind.queued;
    final double inset = size.stroke + size.gap + 1;

    final TextStyle style = TextStyle(
      fontFamily: N360Type.mukta,
      fontSize: size.fontSize,
      fontWeight: FontWeight.w700,
      height: 1.35,
      leadingDistribution: TextLeadingDistribution.even,
      decoration: TextDecoration.none,
      letterSpacing: N360Type.trackingFor(label, size.fontSize * 0.08),
      color: ink,
    );

    final TextScaler scaler = N360TextScale.of(context);

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

/// Slams the stamp onto a slip in the middle of the screen, for approvals and
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
      child: _StampFace(kind: widget.kind, label: widget.label, size: StampSize.hero),
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

/// The white card the stamp lands on.
class _ThudSlip extends StatelessWidget {
  const _ThudSlip({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: const SoftSurfacePainter(edge: N360Colors.rule),
      child: SizedBox(
        width: 280,
        height: 176,
        child: Padding(
          padding: const EdgeInsets.all(N360Space.lg),
          child: Center(child: FittedBox(fit: BoxFit.scaleDown, child: child)),
        ),
      ),
    );
  }
}
