// Renders the design gallery to PNG with the real fonts so a person can judge it.
//
// flutter test test/core/design/gallery_render_test.dart \
//   --dart-define=N360_GALLERY_DIR=/absolute/output/dir
//
// Without the define the PNGs are skipped, so a normal test run writes nothing.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/design/gallery/design_gallery.dart';

import 'test_helpers.dart';

const String _dir = String.fromEnvironment('N360_GALLERY_DIR');

const Size _phone = Size(360, 800);

Widget _app(Widget home, {double textScale = 1, bool disableAnimations = true}) {
  return MaterialApp(
    theme: buildN360Theme(),
    debugShowCheckedModeBanner: false,
    builder: (context, navigator) => MediaQuery(
      data: MediaQuery.of(context).copyWith(
        textScaler: TextScaler.linear(textScale),
        disableAnimations: disableAnimations,
      ),
      child: LedgerPaper.root(child: navigator),
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

  testWidgets('every gallery page renders in both languages with no overflow', (tester) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(360, 1800);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    for (final marathi in <bool>[false, true]) {
      for (final scale in <double>[1, 1.3]) {
        for (final section in GallerySection.values) {
          await tester.pumpWidget(_app(DesignGallery(section: section, marathi: marathi), textScale: scale));
          await tester.pump(const Duration(milliseconds: 800));
          expect(tester.takeException(), isNull, reason: 'section ${section.name} marathi=$marathi scale=$scale');
        }
      }
    }
  });

  if (_dir.isEmpty) return;

  testWidgets('capture PNGs', (tester) async {
    Future<void> shot(String name, Widget home, {double textScale = 1, double? tall}) async {
      tester.view.devicePixelRatio = 2.5;
      tester.view.physicalSize = Size(_phone.width, tall ?? _phone.height) * 2.5;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(_app(home, textScale: textScale));
      await tester.pump(const Duration(milliseconds: 900));
      await tester.runAsync(() => capturePng(tester, _dir, name));
    }

    for (final section in GallerySection.values) {
      final double? tall = section == GallerySection.choices ? 980 : null;
      await shot('en_${section.name}', DesignGallery(section: section), tall: tall);
      await shot('mr_${section.name}', DesignGallery(section: section, marathi: true), tall: tall);
    }
    await shot('mr_muster_scaled', const DesignGallery(section: GallerySection.muster, marathi: true), textScale: 1.3, tall: 900);
    await shot('mr_buttons_scaled', const DesignGallery(section: GallerySection.buttons, marathi: true), textScale: 1.3, tall: 900);
  });

  testWidgets('capture motion frames', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = _phone * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

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
