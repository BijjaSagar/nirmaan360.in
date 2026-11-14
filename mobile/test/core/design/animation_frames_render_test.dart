// Renders the frames of the app's looping illustrations from the vector
// drawing, for `tool/build_animations.py` to assemble into animated WebP.
//
// flutter test test/core/design/animation_frames_render_test.dart \
//   --dart-define=N360_FRAMES_DIR=/absolute/output/dir
//
// Each loop writes `<name>_NNN.png` on a transparent background and a
// `<name>.json` sidecar with the frame duration. Without the define nothing is
// written and the test only checks that every frame paints.
import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data' show ByteData;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart' show RenderRepaintBoundary;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';

const String _dir = String.fromEnvironment('N360_FRAMES_DIR');

/// Rendered at this logical width, at 2x. The widget decodes at the width it
/// is laid out at, so a larger file would only cost download size.
const double _width = 232;
const double _dpr = 2;

/// One loop: how many frames, how long each shows, and the drawing at frame i.
class _Loop {
  const _Loop(this.name, {required this.frames, required this.frameMs, required this.at});

  final String name;
  final int frames;
  final int frameMs;
  final BuildingProgressArt Function(double t) at;
}

/// Eases a ping-pong: 0 → 1 → 0 across the loop, slow at both ends so the
/// beam pauses at the ground and at the floor.
double _pingPong(double t) {
  final double up = t < 0.5 ? t * 2 : 2 - t * 2;
  return Curves.easeInOutSine.transform(up.clamp(0.0, 1.0));
}

final List<_Loop> _loops = <_Loop>[
  // The crane lifts a beam to the third floor and lowers for the next one.
  _Loop(
    'crane_hoist',
    frames: 30,
    frameMs: 70,
    at: (t) => BuildingProgressArt(
      fraction: LoopingArtKind.craneHoist.fraction,
      hoist: _pingPong(t),
      animateEntry: false,
    ),
  ),
  // The finished building; a band of light moves through its windows.
  _Loop(
    'all_sent',
    frames: 26,
    frameMs: 90,
    at: (t) => BuildingProgressArt(
      fraction: LoopingArtKind.allSent.fraction,
      glow: t,
      animateEntry: false,
    ),
  ),
];

Widget _frame(Widget art) => Directionality(
      textDirection: TextDirection.ltr,
      child: MediaQuery(
        data: const MediaQueryData(disableAnimations: true, devicePixelRatio: _dpr),
        child: Center(
          child: SizedBox(
            width: _width,
            child: RepaintBoundary(key: const ValueKey<String>('art'), child: art),
          ),
        ),
      ),
    );

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  testWidgets('every frame of every loop paints', (tester) async {
    tester.view.devicePixelRatio = _dpr;
    tester.view.physicalSize = Size(_width, _width / BuildingProgressArt.aspectRatio) * _dpr;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    for (final loop in _loops) {
      for (var i = 0; i < loop.frames; i++) {
        final double t = i / loop.frames;
        await tester.pumpWidget(_frame(loop.at(t)));
        await tester.pump();
        expect(tester.takeException(), isNull, reason: '${loop.name} frame $i');
        if (_dir.isEmpty) continue;
        final RenderRepaintBoundary boundary =
            tester.renderObject<RenderRepaintBoundary>(find.byKey(const ValueKey<String>('art')));
        final ui.Image image = (await tester.runAsync(() => boundary.toImage(pixelRatio: _dpr)))!;
        final ByteData? bytes = await tester.runAsync<ByteData?>(() => image.toByteData(format: ui.ImageByteFormat.png));
        image.dispose();
        final String index = i.toString().padLeft(3, '0');
        await tester.runAsync(() => File('$_dir/${loop.name}_$index.png').writeAsBytes(bytes!.buffer.asUint8List()));
      }
      if (_dir.isNotEmpty) {
        File('$_dir/${loop.name}.json').writeAsStringSync(jsonEncode(<String, Object>{
          'frames': loop.frames,
          'frame_ms': loop.frameMs,
          'width': (_width * _dpr).round(),
          'height': (_width / BuildingProgressArt.aspectRatio * _dpr).round(),
        }));
      }
    }
    expect(math.max(_loops.length, 1), greaterThan(0));
  });
}
