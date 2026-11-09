// Renders the design gallery to PNG with the real fonts so a person can judge it.
//
// flutter test test/core/design/gallery_render_test.dart \
//   --dart-define=N360_GALLERY_DIR=/absolute/output/dir
//
// Every section is shot at 360dp and 390dp, in English and Marathi, plus a few
// frames caught mid-motion. Without the define the PNGs are skipped, so a
// normal test run writes nothing.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/design/gallery/design_gallery.dart';

import 'test_helpers.dart';

const String _dir = String.fromEnvironment('N360_GALLERY_DIR');

const List<double> _widths = <double>[360, 390];

Widget _app(Widget home, {double textScale = 1, bool disableAnimations = true, bool devanagari = false}) {
  return MaterialApp(
    theme: buildN360Theme(devanagari: devanagari),
    debugShowCheckedModeBanner: false,
    builder: (context, navigator) => MediaQuery(
      data: MediaQuery.of(context).copyWith(
        textScaler: TextScaler.linear(textScale),
        disableAnimations: disableAnimations,
      ),
      child: N360TextScaling(child: LedgerPaper.root(child: navigator)),
    ),
    home: home,
  );
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  testWidgets('every gallery page renders at both widths in both languages with no overflow', (tester) async {
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    for (final width in _widths) {
      tester.view.physicalSize = Size(width, 1800);
      for (final marathi in <bool>[false, true]) {
        for (final scale in <double>[1, 1.3]) {
          for (final section in GallerySection.values) {
            await tester.pumpWidget(_app(
              DesignGallery(section: section, marathi: marathi),
              textScale: scale,
              devanagari: marathi,
            ));
            await tester.pump(const Duration(milliseconds: 800));
            expect(tester.takeException(), isNull,
                reason: 'section ${section.name} width=$width marathi=$marathi scale=$scale');
          }
        }
      }
    }
  });

  if (_dir.isEmpty) return;

  testWidgets('capture PNGs', (tester) async {
    Future<void> shot(String name, Widget home,
        {double textScale = 1, double? tall, double width = 360, bool devanagari = false}) async {
      tester.view.devicePixelRatio = 2.5;
      tester.view.physicalSize = Size(width, tall ?? 800) * 2.5;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(_app(home, textScale: textScale, devanagari: devanagari));
      await tester.pump(const Duration(milliseconds: 900));
      await tester.runAsync(() => capturePng(tester, _dir, name));
    }

    for (final width in _widths) {
      final String w = width.round().toString();
      for (final section in GallerySection.values) {
        final double? tall = switch (section) {
          GallerySection.choices => 1040,
          GallerySection.art => 1100,
          GallerySection.progress ||
          GallerySection.plan ||
          GallerySection.buttons ||
          GallerySection.loading =>
            900,
          _ => null,
        };
        await shot('${w}_en_${section.name}', DesignGallery(section: section), tall: tall, width: width);
        await shot('${w}_mr_${section.name}', DesignGallery(section: section, marathi: true),
            tall: tall, width: width, devanagari: true);
      }
    }
    await shot('360_mr_muster_scaled', const DesignGallery(section: GallerySection.muster, marathi: true),
        textScale: 1.3, tall: 900, devanagari: true);
    await shot('360_mr_buttons_scaled', const DesignGallery(section: GallerySection.buttons, marathi: true),
        textScale: 1.3, tall: 1000, devanagari: true);
    await shot('360_mr_progress_scaled', const DesignGallery(section: GallerySection.progress, marathi: true),
        textScale: 1.3, tall: 1000, devanagari: true);
  });

  testWidgets('capture motion frames', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 800) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    // The progress page entering: hero counting up, bar and building filling,
    // bottom bar tile settling. All start at t = 0.
    await tester.pumpWidget(_app(
      const DesignGallery(section: GallerySection.progress),
      disableAnimations: false,
    ));
    await tester.pump();
    var at = 0;
    for (final ms in <int>[0, 120, 300, 600, 1200]) {
      await tester.pump(Duration(milliseconds: ms - at));
      at = ms;
      await tester.runAsync(() => capturePng(tester, _dir, 'frame_entry_${ms}ms'));
    }
    await tester.pump(const Duration(seconds: 1));

    // Loading placeholders mid-sheen.
    await tester.pumpWidget(_app(
      const DesignGallery(section: GallerySection.loading),
      disableAnimations: false,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await tester.runAsync(() => capturePng(tester, _dir, 'frame_skeleton_500ms'));
    await tester.pumpWidget(const SizedBox.shrink());

    await tester.pumpWidget(_app(
      const _RollingFrames(),
      disableAnimations: false,
    ));
    final _RollingFramesState state = tester.state(find.byType(_RollingFrames));
    await tester.pump(const Duration(milliseconds: 60));
    state.bump();
    await tester.pump();
    for (final at in <int>[80, 150]) {
      await tester.pump(Duration(milliseconds: at == 80 ? 80 : 70));
      await tester.runAsync(() => capturePng(tester, _dir, 'frame_rolling_${at}ms'));
    }
    await tester.pump(const Duration(milliseconds: 500));
    await tester.runAsync(() => capturePng(tester, _dir, 'frame_rolling_final'));

    await tester.pumpWidget(_app(
      Builder(
        builder: (context) {
          return Scaffold(
            body: Center(
              child: Padding(
                padding: const EdgeInsets.all(N360Space.gutter),
                child: LedgerCard(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: const <Widget>[
                      SheetHeader(eyebrow: 'Item 2.1', title: 'Footing F12'),
                      LedgerRow(label: 'Quantity', value: '50.000 cum', total: true),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
      disableAnimations: false,
    ));
    await tester.pump();

    final BuildContext context = tester.element(find.byType(LedgerCard));
    final Future<void> done = showStampThud(context, StampKind.approved, 'Approved');
    await tester.pump();
    var elapsed = 0;
    for (final at in <int>[0, 70, 140, 165, 240, 400, 760]) {
      await tester.pump(Duration(milliseconds: at - elapsed));
      elapsed = at;
      await tester.runAsync(() => capturePng(tester, _dir, 'frame_thud_${at}ms'));
    }
    await tester.pump(const Duration(milliseconds: 400));
    await done;

    // Slips: one becomes sent, gets its tick, then leaves the tray.
    await tester.pumpWidget(_app(const _SlipFrames(), disableAnimations: false));
    await tester.pump();
    final _SlipFramesState slips = tester.state(find.byType(_SlipFrames));
    slips.send();
    await tester.pump();
    var slipAt = 0;
    for (final at in <int>[60, 130, 240, 620, 720, 820, 1100]) {
      await tester.pump(Duration(milliseconds: at - slipAt));
      slipAt = at;
      await tester.runAsync(() => capturePng(tester, _dir, 'frame_slip_${at}ms'));
    }
    await tester.pump(const Duration(seconds: 1));

    // The page turn, caught a third of the way: the new page carries its paper,
    // the old one has drifted back beneath it.
    await tester.pumpWidget(_app(const _PageFrames(), disableAnimations: false));
    await tester.pump();
    final _PageFramesState pages = tester.state(find.byType(_PageFrames));
    pages.push();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 110));
    await tester.runAsync(() => capturePng(tester, _dir, 'frame_page_110ms'));
    await tester.pump(const Duration(milliseconds: 110));
    await tester.runAsync(() => capturePng(tester, _dir, 'frame_page_220ms'));
    await tester.pump(const Duration(seconds: 1));

    // The stamp on its own.
    await tester.pumpWidget(_app(
      const Scaffold(
        body: Center(
          child: RubberStamp(kind: StampKind.rejected, label: 'Rejected', size: StampSize.large, animate: true),
        ),
      ),
      disableAnimations: false,
    ));
    await tester.pump(const Duration(milliseconds: 90));
    await tester.runAsync(() => capturePng(tester, _dir, 'frame_stamp_90ms'));
  });
}

class _PageFrames extends StatefulWidget {
  const _PageFrames();

  @override
  State<_PageFrames> createState() => _PageFramesState();
}

class _PageFramesState extends State<_PageFrames> {
  bool _second = false;

  void push() => setState(() => _second = true);

  @override
  Widget build(BuildContext context) {
    return Navigator(
      pages: <Page<void>>[
        const N360Page<void>(
          key: ValueKey<String>('first'),
          child: DesignGallery(section: GallerySection.progress),
        ),
        if (_second)
          const N360Page<void>(
            key: ValueKey<String>('second'),
            child: DesignGallery(section: GallerySection.installed),
          ),
      ],
      onDidRemovePage: (_) {},
    );
  }
}

class _SlipFrames extends StatefulWidget {
  const _SlipFrames();

  @override
  State<_SlipFrames> createState() => _SlipFramesState();
}

class _SlipFramesState extends State<_SlipFrames> {
  bool _sent = false;

  void send() => setState(() => _sent = true);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(N360Space.gutter),
          child: SlipStack(
            count: 6,
            trayLabel: 'on phone',
            slips: <SlipData>[
              SlipData(
                id: 'a',
                title: 'Measurement 2.1',
                subtitle: '15.000 cum · 9 Nov',
                status: _sent ? SlipStatus.sent : SlipStatus.sending,
                statusLabel: _sent ? 'Sent' : 'Sending',
                icon: Icons.architecture,
              ),
              const SlipData(
                id: 'b',
                title: 'Daily report',
                subtitle: '9 Nov · 3 photos',
                status: SlipStatus.waiting,
                statusLabel: 'Waiting',
                icon: Icons.assignment,
              ),
              const SlipData(
                id: 'c',
                title: 'Fuel ₹2,450.00',
                status: SlipStatus.waiting,
                statusLabel: 'Waiting',
                icon: Icons.local_gas_station,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RollingFrames extends StatefulWidget {
  const _RollingFrames();

  @override
  State<_RollingFrames> createState() => _RollingFramesState();
}

class _RollingFramesState extends State<_RollingFrames> {
  int _step = 0;

  void bump() => setState(() => _step++);

  @override
  Widget build(BuildContext context) {
    final bool after = _step > 0;
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(N360Space.gutter),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: <Widget>[
              RollingMoney(value: after ? '1,23,456.50' : '98,765.40', style: N360Type.figureHero),
              const SizedBox(height: N360Space.lg),
              RollingQty(value: after ? '1250.000' : '999.875', unit: 'cum', style: N360Type.figureLarge),
              const SizedBox(height: N360Space.lg),
              RollingNumber(value: after ? '12.5' : '12', style: N360Type.figureLarge),
            ],
          ),
        ),
      ),
    );
  }
}
