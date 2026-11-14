import 'package:flutter/material.dart';

import '../motion.dart';
import 'building_progress_art.dart';

/// The app's few looping illustrations, rendered from [BuildingProgressArt] by
/// `tool/build_animations.py` into animated WebP under `assets/animations/`.
///
/// Each one means something and has one place:
///
/// * [craneHoist]: the crane lifting a beam onto a half-built floor. For first
///   run and empty states, where it says "the site is waiting for your entry".
/// * [allSent]: the finished building with light moving through its windows.
///   For the moment the outbox empties, where it says "it has all reached the
///   office".
enum LoopingArtKind {
  craneHoist('assets/animations/crane_hoist.webp', fraction: 0.42, hoist: 1, glow: -1),
  allSent('assets/animations/all_sent.webp', fraction: 1, hoist: 1, glow: 0.7);

  const LoopingArtKind(this.asset, {required this.fraction, required this.hoist, required this.glow});

  /// The animated WebP.
  final String asset;

  /// The still frame's parameters, used when motion is reduced and while the
  /// file loads, so the picture is there from the first frame.
  final double fraction;
  final double hoist;
  final double glow;
}

/// One looping illustration, with a still fallback.
///
/// Flutter plays animated WebP natively with full alpha, so the edges sit clean
/// on the warm gradient where a GIF would be jagged. The file is decoded at the
/// width it is laid out at ([Image.asset]'s `cacheWidth`), so a 176dp picture
/// on a 3x phone costs a 528px bitmap, not the whole file.
///
/// Where it fits: an empty state ([craneHoist]), the sync centre once
/// everything has gone ([allSent]), the first-run welcome. Never in a list, a
/// row, or anywhere two of them could be on screen at once.
///
/// With `MediaQuery.disableAnimations` the vector drawing is shown at its final
/// state instead, at once.
class LoopingArt extends StatelessWidget {
  const LoopingArt({super.key, required this.kind, required this.semanticLabel});

  const LoopingArt.craneHoist({super.key, required this.semanticLabel}) : kind = LoopingArtKind.craneHoist;

  const LoopingArt.allSent({super.key, required this.semanticLabel}) : kind = LoopingArtKind.allSent;

  final LoopingArtKind kind;

  /// What the picture means here, spoken by a screen reader.
  final String semanticLabel;

  /// Width over height, the same as the drawing it was rendered from.
  static const double aspectRatio = BuildingProgressArt.aspectRatio;

  Widget _still() => BuildingProgressArt(
        fraction: kind.fraction,
        hoist: kind.hoist,
        glow: kind.glow,
        animateEntry: false,
      );

  @override
  Widget build(BuildContext context) {
    if (context.motion.reduced) {
      return Semantics(
        container: true,
        image: true,
        label: semanticLabel,
        child: ExcludeSemantics(child: _still()),
      );
    }
    return Semantics(
      container: true,
      image: true,
      label: semanticLabel,
      child: ExcludeSemantics(
        child: RepaintBoundary(
          child: AspectRatio(
            aspectRatio: aspectRatio,
            child: LayoutBuilder(
              builder: (context, constraints) {
                final double dpr = MediaQuery.devicePixelRatioOf(context);
                final int cacheWidth = (constraints.maxWidth * dpr).round();
                return Image.asset(
                  kind.asset,
                  cacheWidth: cacheWidth > 0 ? cacheWidth : null,
                  fit: BoxFit.contain,
                  filterQuality: FilterQuality.low,
                  gaplessPlayback: true,
                  excludeFromSemantics: true,
                  // The still drawing while the file loads, and if it cannot.
                  frameBuilder: (context, child, frame, loaded) => frame == null ? _still() : child,
                  errorBuilder: (context, error, stack) => _still(),
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}
