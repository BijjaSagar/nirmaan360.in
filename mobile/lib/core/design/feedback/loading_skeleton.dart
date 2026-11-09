import 'package:flutter/material.dart';

import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';

/// A calm loading placeholder: the shape of the card that is coming, drawn as
/// soft bars on white, with one slow sheen crossing it. No spinner.
///
/// Everything is one `CustomPainter` in a `RepaintBoundary`; the sheen is a
/// shader offset, so a frame costs one draw. With reduced motion the bars are
/// still. Give it the [lines] the real card will have so nothing jumps when
/// the content lands.
class LedgerSkeleton extends StatefulWidget {
  const LedgerSkeleton({
    super.key,
    this.lines = 2,
    this.figure = false,
    this.flat = false,
    this.semanticsLabel = 'Loading',
  });

  /// Text lines under the title.
  final int lines;

  /// Leaves room for a large figure, as a stat or hero card has.
  final bool figure;

  /// No shadow: for a row inside a list.
  final bool flat;

  /// Spoken once. Pass the translated word.
  final String semanticsLabel;

  @override
  State<LedgerSkeleton> createState() => _LedgerSkeletonState();
}

class _LedgerSkeletonState extends State<LedgerSkeleton> with SingleTickerProviderStateMixin {
  AnimationController? _sheen;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final bool reduced = context.motion.reduced;
    if (reduced) {
      _sheen?.stop();
    } else if (_sheen == null) {
      _sheen = AnimationController(vsync: this, duration: N360Durations.sheen)..repeat();
    } else if (!_sheen!.isAnimating) {
      _sheen!.repeat();
    }
  }

  @override
  void dispose() {
    _sheen?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final double height = N360Space.card * 2 + 16 + (widget.figure ? 36 : 0) + widget.lines * 18 + 6;
    return Semantics(
      container: true,
      liveRegion: true,
      label: widget.semanticsLabel,
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: CustomPaint(
            painter: SoftSurfacePainter(edge: N360Colors.cardEdge, shadow: !widget.flat, shadowStrength: 0.7),
            foregroundPainter: _SkeletonPainter(
              lines: widget.lines,
              figure: widget.figure,
              sheen: _sheen,
            ),
            child: SizedBox(height: height, width: double.infinity),
          ),
        ),
      ),
    );
  }
}

class _SkeletonPainter extends CustomPainter {
  _SkeletonPainter({required this.lines, required this.figure, required this.sheen}) : super(repaint: sheen);

  final int lines;
  final bool figure;
  final Animation<double>? sheen;

  static const Color _bar = N360Colors.paper2;
  static const Color _light = Color(0xFFF2EAE5);

  @override
  void paint(Canvas canvas, Size size) {
    final double t = sheen?.value ?? 0.5;
    final Paint paint = Paint();
    // The sheen is a light band sliding across every bar at once.
    final double bandX = -size.width * 0.5 + t * size.width * 2;
    paint.shader = LinearGradient(
      colors: const <Color>[_bar, _light, _bar],
      stops: const <double>[0.35, 0.5, 0.65],
    ).createShader(Rect.fromLTWH(bandX - size.width * 0.5, 0, size.width, size.height));

    const double pad = N360Space.card;
    const Radius r = Radius.circular(5);
    double y = pad;

    void bar(double x, double width, double height) {
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(x, y, width, height), r), paint);
    }

    // Title.
    bar(pad, size.width * 0.42, 12);
    y += 16 + 6;
    if (figure) {
      bar(pad, size.width * 0.3, 26);
      y += 36;
    }
    for (var i = 0; i < lines; i++) {
      final double w = i == lines - 1 ? size.width * 0.5 : size.width - pad * 2 - (i.isOdd ? 24 : 0);
      bar(pad, w, 10);
      y += 18;
    }
  }

  @override
  bool shouldRepaint(_SkeletonPainter oldDelegate) =>
      oldDelegate.lines != lines || oldDelegate.figure != figure || oldDelegate.sheen != sheen;
}

/// A stack of [LedgerSkeleton] rows standing in for a list while it loads.
class LedgerSkeletonList extends StatelessWidget {
  const LedgerSkeletonList({super.key, this.rows = 3, this.lines = 1, this.semanticsLabel = 'Loading'});

  final int rows;
  final int lines;
  final String semanticsLabel;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: semanticsLabel,
      child: ExcludeSemantics(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            for (var i = 0; i < rows; i++) ...<Widget>[
              if (i > 0) const SizedBox(height: N360Space.cardGap),
              LedgerSkeleton(lines: lines, flat: true, semanticsLabel: semanticsLabel),
            ],
          ],
        ),
      ),
    );
  }
}
