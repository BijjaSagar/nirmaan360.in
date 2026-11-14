import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/material.dart';

import '../motion.dart';

/// The building under construction: the main graphic on Home.
///
/// An original isometric drawing. Floors up to [fraction] are finished warm
/// concrete with gold edges, the floor in progress is partly closed in, and the
/// floors above it are a bare frame under a tower crane.
///
/// The frame and crane are there from the first frame; the finished floors rise
/// on entry over [N360Durations.build] through `context.motion`. After that it
/// repaints only when [fraction] changes, and it sits in a `RepaintBoundary`.
///
/// [hoist] and [glow] exist for the looping illustrations rendered by
/// `tool/build_animations.py`; a screen leaves them at their defaults.
///
/// [fraction] is a layout value: compute it from `Decimal` before passing it.
class BuildingProgressArt extends StatelessWidget {
  const BuildingProgressArt({
    super.key,
    required this.fraction,
    this.semanticLabel,
    this.animateEntry = true,
    this.hoist = 1,
    this.glow = -1,
  });

  /// How much is built, 0 to 1.
  final double fraction;

  /// Spoken description. Defaults to the percent.
  final String? semanticLabel;

  /// Set false to show the final state at once, as an empty state does.
  final bool animateEntry;

  /// Where the beam on the crane's hook is: 0 at the ground, 1 at the working
  /// floor. Screens leave it at 1.
  final double hoist;

  /// Below 0 the windows are lit as drawn. From 0 to 1 a band of light moves
  /// through them, for the "all sent" loop.
  final double glow;

  /// Width over height of the drawing.
  static const double aspectRatio = 1.13;

  @override
  Widget build(BuildContext context) {
    final double target = fraction.clamp(0.0, 1.0);
    final motion = context.motion;
    final bool still = motion.reduced || !animateEntry;
    return Semantics(
      container: true,
      image: true,
      label: semanticLabel ?? '${(target * 100).round()}%',
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: AspectRatio(
            aspectRatio: aspectRatio,
            child: TweenAnimationBuilder<double>(
              tween: Tween<double>(begin: still ? target : 0, end: target),
              duration: motion.build,
              curve: N360Curves.rise,
              builder: (context, value, _) => CustomPaint(
                size: Size.infinite,
                painter: BuildingArtPainter(fraction: value, hoist: hoist, glow: glow),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Quiet concrete in the palette's own warm greys, with the orange kept for
/// the crane and the finished slab edges, so the drawing reads as a plan
/// diagram rather than a cartoon.
abstract final class _Art {
  static const Color slabTop = Color(0xFFF4F0EA);
  static const Color slabTopBack = Color(0xFFEAE4DC);
  static const Color wallLeft = Color(0xFFEDE7E0);
  static const Color wallRight = Color(0xFFD8CFC6);
  static const Color wallShadeLeft = Color(0xFFDDD5CC);
  static const Color wallShadeRight = Color(0xFFC4B9AE);
  static const Color windowLeft = Color(0xFF575250);
  static const Color windowRight = Color(0xFF45413F);
  static const Color windowGlow = Color(0xFFE1C9BB);
  static const Color goldLeft = Color(0xFFBC5E2F);
  static const Color goldRight = Color(0xFF8F4622);
  static const Color goldTop = Color(0xFFE1985E);

  static const Color frameTop = Color(0xFFF1EDE7);
  static const Color frameLeft = Color(0xFFE3DCD3);
  static const Color frameRight = Color(0xFFCBC2B8);
  static const Color rebar = Color(0xFF8C8378);

  static const Color groundTop = Color(0xFFEFECEB);
  static const Color groundLeft = Color(0xFFE3D6CF);
  static const Color groundRight = Color(0xFFCFC2B8);
  static const Color groundLine = Color(0xFFE3D6CF);

  static const Color craneMain = Color(0xFFBC5E2F);
  static const Color craneDark = Color(0xFF8F4622);
  static const Color craneCounter = Color(0xFF575250);
  static const Color cable = Color(0xFF575250);

  static const Color pipeBody = Color(0xFF9FA2A7);
  static const Color pipeLight = Color(0xFFC9CBCE);
  static const Color pipeEnd = Color(0xFF6F7276);
  static const Color pallet = Color(0xFFA89C8E);
  static const Color block = Color(0xFFE3DCD3);
  static const Color blockSide = Color(0xFFCBC2B8);
}

/// World units: one bay is 1, one floor is [_floor]. X runs down to the right on
/// screen, Y runs down to the left, Z is up.
class BuildingArtPainter extends CustomPainter {
  const BuildingArtPainter({required this.fraction, this.hoist = 1, this.glow = -1});

  final double fraction;

  /// Beam height on the hook, 0 at the ground to 1 at the working floor.
  final double hoist;

  /// Below 0: windows lit as drawn. 0 to 1: a band of light sweeps the windows.
  final double glow;

  static const int _bayX = 7;
  static const int _bayY = 4;
  static const int _floors = 5;
  static const double _w = 7;
  static const double _d = 4;
  static const double _floor = 1.1;
  static const double _slab = 0.17;
  static const double _over = 0.1; // how far a slab overhangs the wall

  static const double _cosA = 0.875;
  static const double _sinA = 0.455;

  // The plate the building stands on.
  static const double _px0 = -1.05;
  static const double _px1 = _w + 1.05;
  static const double _py0 = -0.55;
  static const double _py1 = _d + 1.15;

  // The tower crane.
  static const double _mastX = -0.7;
  static const double _mastY = _d + 0.25;
  static const double _mastW = 0.42;
  static const double _jibZ = _floors * _floor + 1.95;
  static const double _jibLen = 8.6;
  static const double _counterLen = 2.5;

  @override
  void paint(Canvas canvas, Size size) {
    final cam = _Camera.fit(
      size,
      left: (_mastX - _mastY) * _cosA - (_counterLen + 0.4) * 2 * 0.7071 * _cosA,
      right: (_px1 - _py0) * _cosA,
      top: ((_mastX + _mastY) * _sinA) - (_jibZ + 1.3),
      bottom: (_px1 + _py1) * _sinA + 0.42,
      cosA: _cosA,
      sinA: _sinA,
    );

    _ground(canvas, cam);
    _castShadow(canvas, cam);
    _craneMast(canvas, cam);

    final double built = fraction * _floors;
    for (var k = 0; k < _floors; k++) {
      final double local = (built - k).clamp(0.0, 1.0);
      if (local >= 1) {
        _doneFloor(canvas, cam, k);
      } else {
        _openFloor(canvas, cam, k, local, working: k == built.floor());
      }
    }
    _roof(canvas, cam, built);
    _weathering(canvas, cam, built);
    _props(canvas, cam);
    _craneJib(canvas, cam, built);
  }

  // Geometry helpers ----------------------------------------------------------

  void _poly(Canvas canvas, List<Offset> points, Paint paint) {
    final Path path = Path()..moveTo(points.first.dx, points.first.dy);
    for (var i = 1; i < points.length; i++) {
      path.lineTo(points[i].dx, points[i].dy);
    }
    path.close();
    canvas.drawPath(path, paint);
  }

  void _quadTop(Canvas canvas, _Camera cam, double x0, double x1, double y0, double y1, double z, Paint paint) {
    _poly(canvas, <Offset>[cam.at(x0, y0, z), cam.at(x1, y0, z), cam.at(x1, y1, z), cam.at(x0, y1, z)], paint);
  }

  /// The face looking down-left on screen (constant Y).
  void _faceLeft(Canvas canvas, _Camera cam, double y, double x0, double x1, double z0, double z1, Paint paint) {
    _poly(canvas, <Offset>[cam.at(x0, y, z0), cam.at(x1, y, z0), cam.at(x1, y, z1), cam.at(x0, y, z1)], paint);
  }

  /// The face looking down-right on screen (constant X).
  void _faceRight(Canvas canvas, _Camera cam, double x, double y0, double y1, double z0, double z1, Paint paint) {
    _poly(canvas, <Offset>[cam.at(x, y0, z0), cam.at(x, y1, z0), cam.at(x, y1, z1), cam.at(x, y0, z1)], paint);
  }

  /// A solid box, drawing the three faces the camera can see.
  void _box(
    Canvas canvas,
    _Camera cam,
    double x0,
    double x1,
    double y0,
    double y1,
    double z0,
    double z1, {
    required Color top,
    required Color left,
    required Color right,
  }) {
    _faceLeft(canvas, cam, y1, x0, x1, z0, z1, Paint()..color = left);
    _faceRight(canvas, cam, x1, y0, y1, z0, z1, Paint()..color = right);
    _quadTop(canvas, cam, x0, x1, y0, y1, z1, Paint()..color = top);
  }

  // Scene pieces --------------------------------------------------------------

  void _ground(Canvas canvas, _Camera cam) {
    const double t = 0.42;
    _box(canvas, cam, _px0, _px1, _py0, _py1, -t, 0,
        top: _Art.groundTop, left: _Art.groundLeft, right: _Art.groundRight);

    // Paving seams.
    final Paint seam = Paint()
      ..color = _Art.groundLine
      ..strokeWidth = cam.px(1);
    for (double x = _px0 + 1; x < _px1; x += 1.4) {
      canvas.drawLine(cam.at(x, _py0, 0), cam.at(x, _py1, 0), seam);
    }
    for (double y = _py0 + 1; y < _py1; y += 1.4) {
      canvas.drawLine(cam.at(_px0, y, 0), cam.at(_px1, y, 0), seam);
    }

    // A gold line around the site, set in from the edge.
    final Paint line = Paint()
      ..color = _Art.goldLeft
      ..style = PaintingStyle.stroke
      ..strokeWidth = cam.px(1.4);
    const double m = 0.22;
    final Path site = Path();
    final Offset a = cam.at(_px0 + m, _py0 + m, 0);
    site
      ..moveTo(a.dx, a.dy)
      ..lineTo(cam.at(_px1 - m, _py0 + m, 0).dx, cam.at(_px1 - m, _py0 + m, 0).dy)
      ..lineTo(cam.at(_px1 - m, _py1 - m, 0).dx, cam.at(_px1 - m, _py1 - m, 0).dy)
      ..lineTo(cam.at(_px0 + m, _py1 - m, 0).dx, cam.at(_px0 + m, _py1 - m, 0).dy)
      ..close();
    canvas.drawPath(site, line);
  }

  void _castShadow(Canvas canvas, _Camera cam) {
    // Three stacked shapes read as a soft-edged shadow with no blur, clipped so
    // the shadow stays on the plate.
    const Color tone = Color(0xFF5A4532);
    canvas.save();
    canvas.clipPath(
      Path()
        ..addPolygon(
          <Offset>[
            cam.at(_px0, _py0, 0),
            cam.at(_px1, _py0, 0),
            cam.at(_px1, _py1, 0),
            cam.at(_px0, _py1, 0),
          ],
          true,
        ),
    );
    for (var i = 0; i < 3; i++) {
      final double grow = 0.36 * i;
      _poly(
        canvas,
        <Offset>[
          cam.at(_w - 0.1, -0.02 - grow, 0),
          cam.at(_w + 1.15 + grow, 0.32 - grow, 0),
          cam.at(_w + 1.15 + grow, _d + 0.42 + grow, 0),
          cam.at(0.5, _d + 0.42 + grow, 0),
          cam.at(0, _d, 0),
          cam.at(_w, _d, 0),
        ],
        Paint()..color = tone.withValues(alpha: 0.075),
      );
    }
    canvas.restore();
  }

  void _slabPlate(Canvas canvas, _Camera cam, double z0, {required bool gold}) {
    final double z1 = z0 + _slab;
    const double e = _over;
    _faceLeft(canvas, cam, _d + e, -e, _w + e, z0, z1, Paint()..color = gold ? _Art.goldLeft : _Art.frameLeft);
    _faceRight(canvas, cam, _w + e, -e, _d + e, z0, z1, Paint()..color = gold ? _Art.goldRight : _Art.frameRight);
    final Rect bounds = Rect.fromPoints(cam.at(-e, -e, z1), cam.at(_w + e, _d + e, z1));
    final Paint top = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: <Color>[_Art.slabTopBack, _Art.slabTop],
      ).createShader(bounds);
    _quadTop(canvas, cam, -e, _w + e, -e, _d + e, z1, top);
  }

  void _doneFloor(Canvas canvas, _Camera cam, int k) {
    final double z0 = k * _floor;
    _slabPlate(canvas, cam, z0, gold: true);
    _wallBays(canvas, cam, z0, _bayX, _bayY);
  }

  /// Closes in [countX] bays of the left face and [countY] of the right, counted
  /// from the corner nearest the camera.
  void _wallBays(Canvas canvas, _Camera cam, double z0, int countX, int countY) {
    final double wallBottom = z0 + _slab;
    final double wallTop = z0 + _floor;
    final Paint leftWall = Paint()..color = _Art.wallLeft;
    final Paint rightWall = Paint()..color = _Art.wallRight;
    final Paint leftShade = Paint()..color = _Art.wallShadeLeft;
    final Paint rightShade = Paint()..color = _Art.wallShadeRight;

    for (var i = 0; i < countX; i++) {
      final double x1 = _w - i.toDouble();
      final double x0 = x1 - 1;
      _faceLeft(canvas, cam, _d, x0, x1, wallBottom, wallTop, leftWall);
      _faceLeft(canvas, cam, _d, x0, x1, wallTop - 0.12, wallTop, leftShade);
      _window(canvas, cam, k: z0, along: x0, isLeft: true);
    }
    for (var j = 0; j < countY; j++) {
      final double y1 = _d - j.toDouble();
      final double y0 = y1 - 1;
      _faceRight(canvas, cam, _w, y0, y1, wallBottom, wallTop, rightWall);
      _faceRight(canvas, cam, _w, y0, y1, wallTop - 0.12, wallTop, rightShade);
      _window(canvas, cam, k: z0, along: y0, isLeft: false);
    }
  }

  void _window(Canvas canvas, _Camera cam, {required double k, required double along, required bool isLeft}) {
    final double zb = k + _slab + 0.22;
    final double zt = k + _floor - 0.2;
    final double a0 = along + 0.2;
    final double a1 = along + 0.8;
    final int seed = (k * 3 + along * 7).round();
    bool lit = seed % 5 == 1;
    if (glow >= 0) {
      // A band of light travelling up and across the finished building: windows
      // are ordered floor by floor, left face then right, and the band covers
      // a little over half of them at any moment, so the loop never jumps.
      final double floorIndex = (k / _floor).roundToDouble();
      final double order = floorIndex * (_bayX + _bayY) + (isLeft ? along : _bayX + along);
      const double total = _floors * (_bayX + _bayY) * 1.0;
      lit = lit || ((order / total - glow) % 1.0) < 0.55;
    }
    final Color frame = isLeft ? _Art.windowLeft : _Art.windowRight;

    void quad(double a, double b, double z0, double z1, Color color) {
      final Paint p = Paint()..color = color;
      if (isLeft) {
        _faceLeft(canvas, cam, _d, a, b, z0, z1, p);
      } else {
        _faceRight(canvas, cam, _w, a, b, z0, z1, p);
      }
    }

    quad(a0 - 0.05, a1 + 0.05, zb - 0.05, zt + 0.05, const Color(0xFFF7F1E6));
    quad(a0, a1, zb, zt, lit ? _Art.windowGlow : frame);
    if (cam.u > 9) {
      final double mid = (a0 + a1) / 2;
      quad(mid - 0.02, mid + 0.02, zb, zt, const Color(0xFFEFE6D8));
      if (!lit) quad(a0, mid - 0.04, zb + (zt - zb) * 0.55, zt, const Color(0x22FFFFFF));
    }
  }

  void _openFloor(Canvas canvas, _Camera cam, int k, double local, {required bool working}) {
    final double z0 = k * _floor;
    final bool started = local > 0;
    _slabPlate(canvas, cam, z0, gold: started);

    final double z1 = z0 + _floor;
    final double columnBottom = z0 + _slab;
    final bool detailed = cam.u > 11;
    const double w = 0.09;

    // Back columns first, front columns after, so the frame layers correctly.
    final List<(double, double)> posts = <(double, double)>[];
    for (var i = 0; i <= _bayX; i++) {
      for (var j = 0; j <= _bayY; j++) {
        final bool perimeter = i == 0 || i == _bayX || j == 0 || j == _bayY;
        if (perimeter || detailed) posts.add((i.toDouble(), j.toDouble()));
      }
    }
    posts.sort((a, b) => (a.$1 + a.$2).compareTo(b.$1 + b.$2));
    for (final (cx, cy) in posts) {
      _box(canvas, cam, cx - w, cx + w, cy - w, cy + w, columnBottom, z1,
          top: _Art.frameTop, left: _Art.frameLeft, right: _Art.frameRight);
    }

    // The floor in progress is partly closed in.
    final int bays = (local * (_bayX + _bayY)).ceil();
    if (local > 0) {
      final int xCount = math.min(bays, _bayX);
      final int yCount = math.max(0, math.min(bays - _bayX, _bayY));
      _wallBays(canvas, cam, z0, xCount, yCount);
    }

    // The floor being worked on has a gold safety rail along its open edges.
    if (working) {
      final Paint rail = Paint()
        ..color = _Art.craneMain
        ..strokeWidth = cam.px(1.5)
        ..strokeCap = StrokeCap.round;
      final double y = columnBottom + 0.44;
      canvas.drawLine(cam.at(-_over * 0.3, _d + _over * 0.3, y), cam.at(_w, _d + _over * 0.3, y), rail);
      canvas.drawLine(cam.at(_w + _over * 0.3, _d, y), cam.at(_w + _over * 0.3, 0, y), rail);
      for (var i = 0; i <= _bayX; i += 1) {
        canvas.drawLine(cam.at(i.toDouble(), _d + _over * 0.3, columnBottom), cam.at(i.toDouble(), _d + _over * 0.3, y), rail);
      }
      for (var j = 0; j <= _bayY; j += 1) {
        canvas.drawLine(cam.at(_w + _over * 0.3, j.toDouble(), columnBottom), cam.at(_w + _over * 0.3, j.toDouble(), y), rail);
      }
    }
  }

  void _roof(Canvas canvas, _Camera cam, double built) {
    final double z = _floors * _floor;
    final bool done = built >= _floors;
    _slabPlate(canvas, cam, z, gold: done);
    final double top = z + _slab;

    if (done) {
      // A small plant room and a parapet line on the finished roof.
      _box(canvas, cam, 2.7, 4.3, 1.2, 2.6, top, top + 0.66,
          top: _Art.slabTop, left: _Art.wallLeft, right: _Art.wallRight);
      _box(canvas, cam, 2.65, 4.35, 1.15, 2.65, top + 0.66, top + 0.76,
          top: _Art.goldTop, left: _Art.goldLeft, right: _Art.goldRight);
      return;
    }

    // Starter bars sticking up where the next columns will go.
    if (cam.u > 8) {
      final Paint bar = Paint()
        ..color = _Art.rebar
        ..strokeWidth = cam.px(1.1)
        ..strokeCap = StrokeCap.round;
      for (var i = 0; i <= _bayX; i += 2) {
        for (var j = 0; j <= _bayY; j += 2) {
          for (final (dx, dy) in const <(double, double)>[(-0.05, 0.0), (0.05, 0.05)]) {
            final Offset base = cam.at(i + dx, j + dy, top);
            canvas.drawLine(base, cam.at(i + dx, j + dy, top + 0.42), bar);
          }
        }
      }
    }

    // Gold safety rail around the working deck.
    final Paint rail = Paint()
      ..color = _Art.craneMain
      ..strokeWidth = cam.px(1.4)
      ..strokeCap = StrokeCap.round;
    const double r = _over * 0.6;
    final List<(double, double)> corners = const <(double, double)>[
      (-r, -r),
      (_w + r, -r),
      (_w + r, _d + r),
      (-r, _d + r),
    ];
    for (var i = 0; i < 4; i++) {
      final (ax, ay) = corners[i];
      final (bx, by) = corners[(i + 1) % 4];
      canvas.drawLine(cam.at(ax, ay, top + 0.38), cam.at(bx, by, top + 0.38), rail);
      canvas.drawLine(cam.at(ax, ay, top), cam.at(ax, ay, top + 0.38), rail);
    }
  }

  /// A very soft darkening of the lower floors, so the block feels grounded.
  void _weathering(Canvas canvas, _Camera cam, double built) {
    final int done = built.floor();
    if (done < 1) return;
    final double top = done * _floor;
    for (final isLeft in <bool>[true, false]) {
      final Path path = Path();
      final List<Offset> pts = isLeft
          ? <Offset>[cam.at(0, _d, 0), cam.at(_w, _d, 0), cam.at(_w, _d, top), cam.at(0, _d, top)]
          : <Offset>[cam.at(_w, 0, 0), cam.at(_w, _d, 0), cam.at(_w, _d, top), cam.at(_w, 0, top)];
      path.addPolygon(pts, true);
      canvas.drawPath(
        path,
        Paint()
          ..shader = LinearGradient(
            begin: Alignment.bottomCenter,
            end: Alignment.topCenter,
            colors: <Color>[
              const Color(0xFF6B4F31).withValues(alpha: isLeft ? 0.16 : 0.22),
              const Color(0x006B4F31),
            ],
          ).createShader(path.getBounds()),
      );
    }
  }

  void _props(Canvas canvas, _Camera cam) {
    // A stack of pipes in front of the left face.
    _pipe(canvas, cam, 4.0, _d + 0.32, 1.7, 0.2);
    _pipe(canvas, cam, 4.0, _d + 0.74, 1.7, 0.2);
    _pipe(canvas, cam, 4.0, _d + 0.53, 1.7, 0.2, lift: 0.34);

    // A pallet of blocks at the front left.
    _box(canvas, cam, 1.0, 2.5, _d + 0.36, _d + 0.94, 0, 0.1,
        top: _Art.pallet, left: _Art.pallet, right: const Color(0xFF7D6146));
    for (var layer = 0; layer < 2; layer++) {
      final double z = 0.1 + layer * 0.24;
      for (var i = 0; i < 3 - layer; i++) {
        final double x = 1.05 + layer * 0.24 + i * 0.48;
        _box(canvas, cam, x, x + 0.44, _d + 0.42, _d + 0.88, z, z + 0.24,
            top: _Art.slabTop, left: _Art.block, right: _Art.blockSide);
      }
    }
  }

  /// A pipe lying along the X axis.
  void _pipe(Canvas canvas, _Camera cam, double x0, double y, double length, double r, {double lift = 0}) {
    final double z = r + lift;
    final Offset a = cam.at(x0, y, z);
    final Offset b = cam.at(x0 + length, y, z);
    final double radius = r * cam.u;
    final Offset normal = (b - a).direction.isFinite ? Offset.fromDirection((b - a).direction - math.pi / 2) : Offset.zero;
    canvas.drawPath(
      Path()..addPolygon(<Offset>[a + normal * radius, b + normal * radius, b - normal * radius, a - normal * radius], true),
      Paint()..color = _Art.pipeBody,
    );
    canvas.drawPath(
      Path()
        ..addPolygon(
          <Offset>[a + normal * radius, b + normal * radius, b + normal * radius * 0.25, a + normal * radius * 0.25],
          true,
        ),
      Paint()..color = _Art.pipeLight,
    );
    canvas.drawOval(Rect.fromCenter(center: b, width: radius * 1.5, height: radius * 2), Paint()..color = _Art.pipeLight);
    canvas.drawOval(Rect.fromCenter(center: b, width: radius * 0.8, height: radius * 1.2), Paint()..color = _Art.pipeEnd);
  }

  void _lattice(Canvas canvas, Offset a, Offset b, Offset c, Offset d, int bays, Paint rail, Paint web) {
    canvas.drawLine(a, b, rail);
    canvas.drawLine(c, d, rail);
    for (var i = 0; i < bays; i++) {
      final double t0 = i / bays;
      final double t1 = (i + 1) / bays;
      final Offset p0 = Offset.lerp(a, b, t0)!;
      final Offset p1 = Offset.lerp(d, c, t1)!;
      final Offset p2 = Offset.lerp(a, b, t1)!;
      canvas.drawLine(p0, p1, web);
      canvas.drawLine(p1, p2, web);
    }
  }

  void _craneMast(Canvas canvas, _Camera cam) {
    const double hw = _mastW / 2;
    const double z1 = _jibZ;
    final Paint rail = Paint()
      ..color = _Art.craneMain
      ..strokeWidth = cam.px(2.4)
      ..strokeCap = StrokeCap.round;
    final Paint web = Paint()
      ..color = _Art.craneDark
      ..strokeWidth = cam.px(1.1)
      ..strokeCap = StrokeCap.round;

    // Base pad.
    _box(canvas, cam, _mastX - 0.42, _mastX + 0.42, _mastY - 0.42, _mastY + 0.42, 0, 0.14,
        top: _Art.slabTop, left: _Art.frameLeft, right: _Art.frameRight);

    final int bays = cam.u > 12 ? 14 : 9;
    // Left visible face, then right visible face.
    _lattice(
      canvas,
      cam.at(_mastX - hw, _mastY + hw, 0.14),
      cam.at(_mastX - hw, _mastY + hw, z1),
      cam.at(_mastX + hw, _mastY + hw, z1),
      cam.at(_mastX + hw, _mastY + hw, 0.14),
      bays,
      rail,
      web,
    );
    _lattice(
      canvas,
      cam.at(_mastX + hw, _mastY + hw, 0.14),
      cam.at(_mastX + hw, _mastY + hw, z1),
      cam.at(_mastX + hw, _mastY - hw, z1),
      cam.at(_mastX + hw, _mastY - hw, 0.14),
      bays,
      rail,
      web,
    );
  }

  void _craneJib(Canvas canvas, _Camera cam, double built) {
    const double dirX = 0.7071;
    const double dirY = -0.7071;

    Offset along(double s, double z) => cam.at(_mastX + dirX * s, _mastY + dirY * s, z);

    final Paint rail = Paint()
      ..color = _Art.craneMain
      ..strokeWidth = cam.px(2.6)
      ..strokeCap = StrokeCap.round;
    final Paint web = Paint()
      ..color = _Art.craneDark
      ..strokeWidth = cam.px(1.15)
      ..strokeCap = StrokeCap.round;

    // Slewing unit and cab.
    const double hw = _mastW / 2 + 0.05;
    _box(canvas, cam, _mastX - hw, _mastX + hw, _mastY - hw, _mastY + hw, _jibZ - 0.02, _jibZ + 0.26,
        top: _Art.goldTop, left: _Art.goldLeft, right: _Art.goldRight);
    _box(canvas, cam, _mastX + hw, _mastX + hw + 0.5, _mastY - 0.24, _mastY + 0.26, _jibZ - 0.62, _jibZ + 0.08,
        top: _Art.goldTop, left: const Color(0xFF4A4036), right: _Art.goldRight);

    // Counter-jib with its weights.
    final Offset cjTop = along(-_counterLen, _jibZ + 0.38);
    final Offset cjBottom = along(-_counterLen, _jibZ + 0.1);
    canvas.drawLine(along(0, _jibZ + 0.38), cjTop, rail);
    canvas.drawLine(along(0, _jibZ + 0.1), cjBottom, rail);
    canvas.drawLine(cjTop, cjBottom, rail);
    final Offset w0 = along(-_counterLen + 0.1, _jibZ + 0.08);
    final Offset w1 = along(-_counterLen + 0.1 + 1.0, _jibZ + 0.08);
    final double weightH = 0.62 * cam.u;
    canvas.drawPath(
      Path()
        ..addPolygon(<Offset>[w0, w1, w1 + Offset(0, weightH), w0 + Offset(0, weightH)], true),
      Paint()..color = _Art.craneCounter,
    );

    // The main jib, a triangular truss.
    final Offset bottom0 = along(0, _jibZ + 0.1);
    final Offset bottomEnd = along(_jibLen, _jibZ + 0.1);
    final Offset topStart = along(0.3, _jibZ + 0.62);
    final Offset topEnd = along(_jibLen * 0.8, _jibZ + 0.5);
    final int segments = cam.u > 12 ? 11 : 7;
    final List<Offset> upper = <Offset>[
      for (var i = 0; i <= segments; i++) Offset.lerp(topStart, topEnd, i / segments)!,
    ];
    final List<Offset> lower = <Offset>[
      for (var i = 0; i <= segments; i++) Offset.lerp(bottom0, Offset.lerp(bottom0, bottomEnd, 0.92)!, i / segments)!,
    ];
    canvas.drawLine(bottom0, bottomEnd, rail);
    canvas.drawLine(topStart, topEnd, rail);
    canvas.drawLine(topEnd, bottomEnd, web);
    for (var i = 0; i < segments; i++) {
      canvas.drawLine(lower[i], upper[i], web);
      canvas.drawLine(upper[i], lower[i + 1], web);
    }

    // Tower top and stays.
    final Offset peak = along(0, _jibZ + 1.25);
    canvas.drawLine(along(0, _jibZ + 0.2), peak, rail);
    final Paint stay = Paint()
      ..color = _Art.cable
      ..strokeWidth = cam.px(0.8);
    canvas.drawLine(peak, topEnd, stay);
    canvas.drawLine(peak, cjTop, stay);

    // Trolley, cable and a beam on the hook, hanging over the working floor.
    // With [hoist] under 1 the beam is still on its way up from the ground and
    // swings a little, as a load on a long cable does.
    const double trolleyS = 5.9;
    final Offset trolley = along(trolleyS, _jibZ + 0.08);
    final double hookTop = math.min(math.max(built.floor() * _floor + 1.5, _floor * 1.6), _jibZ - 0.9);
    final double lift = hoist.clamp(0.0, 1.0);
    final double hookZ = lerpDouble(0.55, hookTop, lift)!;
    final double sway = 0.09 * math.sin(lift * math.pi);
    final double hx = _mastX + dirX * trolleyS + sway;
    final double hy = _mastY + dirY * trolleyS + sway;
    final Offset hook = cam.at(hx, hy, hookZ);
    canvas.drawRect(Rect.fromCenter(center: trolley, width: 0.5 * cam.u, height: 0.14 * cam.u), Paint()..color = _Art.craneDark);
    canvas.drawLine(trolley, hook, stay);

    final double beamHalf = 0.75;
    final Offset b0 = cam.at(hx - beamHalf, hy, hookZ - 0.1);
    final Offset b1 = cam.at(hx + beamHalf, hy, hookZ - 0.1);
    final double t = 0.13 * cam.u;
    canvas.drawPath(
      Path()..addPolygon(<Offset>[b0, b1, b1 + Offset(0, t), b0 + Offset(0, t)], true),
      Paint()..color = _Art.goldRight,
    );
    canvas.drawPath(
      Path()..addPolygon(<Offset>[b0 - Offset(0, t * 0.5), b1 - Offset(0, t * 0.5), b1, b0], true),
      Paint()..color = _Art.goldTop,
    );
  }

  @override
  bool shouldRepaint(BuildingArtPainter oldDelegate) =>
      oldDelegate.fraction != fraction || oldDelegate.hoist != hoist || oldDelegate.glow != glow;
}

class _Camera {
  const _Camera(this.origin, this.u, this.cosA, this.sinA);

  factory _Camera.fit(
    Size size, {
    required double left,
    required double right,
    required double top,
    required double bottom,
    required double cosA,
    required double sinA,
  }) {
    final double u = math.min(size.width / (right - left), size.height / (bottom - top));
    final double usedW = (right - left) * u;
    final double usedH = (bottom - top) * u;
    final Offset origin = Offset(
      (size.width - usedW) / 2 - left * u,
      (size.height - usedH) / 2 - top * u,
    );
    return _Camera(origin, u, cosA, sinA);
  }

  final Offset origin;

  /// Pixels per world unit.
  final double u;
  final double cosA;
  final double sinA;

  Offset at(double x, double y, double z) =>
      origin + Offset((x - y) * cosA * u, ((x + y) * sinA - z) * u);

  /// A stroke width that scales with the drawing but never vanishes.
  double px(double base) => (base * u / 22).clamp(0.6, 2.6);
}
