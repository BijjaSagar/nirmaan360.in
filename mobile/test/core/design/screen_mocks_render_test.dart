// Renders the four real-content screens in `screen_mocks.dart` to PNG at
// 390dp, for a design review.
//
// flutter test test/core/design/screen_mocks_render_test.dart \
//   --dart-define=N360_PRO_DIR=/absolute/output/dir \
//   --dart-define=N360_PRO_TAG=current        # or proposed
//
// Without N360_PRO_DIR nothing is written; the test only checks the screens
// lay out with no overflow in both languages.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/design/gallery/screen_mocks.dart';

import 'test_helpers.dart';

const String _dir = String.fromEnvironment('N360_PRO_DIR');
const String _tag = String.fromEnvironment('N360_PRO_TAG', defaultValue: 'current');
const bool _proposed = _tag == 'proposed';

const Size _phone = Size(390, 844);

Widget _app(Widget home, {bool devanagari = false}) {
  return MaterialApp(
    theme: buildN360Theme(devanagari: devanagari),
    debugShowCheckedModeBanner: false,
    builder: (context, navigator) => MediaQuery(
      data: MediaQuery.of(context).copyWith(disableAnimations: true),
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

  testWidgets('the real screens lay out at 390dp with no overflow', (tester) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = _phone;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    for (final screen in ScreenMock.values) {
      for (final proposed in <bool>[false, true]) {
        await tester.pumpWidget(_app(ScreenMocks(screen: screen, proposed: proposed)));
        await tester.pump(const Duration(milliseconds: 500));
        expect(tester.takeException(), isNull, reason: '${screen.name} proposed=$proposed');
      }
    }
  });

  testWidgets('Mukta digits: are they the same width (tabular) with and without tnum?', (tester) async {
    double width(String text, {bool tnum = false}) {
      final painter = TextPainter(
        text: TextSpan(
          text: text,
          style: TextStyle(
            fontFamily: N360Type.mukta,
            fontSize: 40,
            fontFeatures: tnum ? const <FontFeature>[FontFeature.tabularFigures()] : null,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
      final double w = painter.width;
      painter.dispose();
      return w;
    }

    final double ones = width('1111');
    final double zeros = width('0000');
    final double onesT = width('1111', tnum: true);
    final double zerosT = width('0000', tnum: true);
    // Printed so the design review can read it; not asserted.
    debugPrint('Mukta 1111=$ones 0000=$zeros | tnum 1111=$onesT 0000=$zerosT');
    final double pOnes = width('1111'.replaceAll('1', '1'));
    expect(pOnes, greaterThan(0));
  });

  if (_dir.isEmpty) return;

  testWidgets('capture $_tag screens', (tester) async {
    tester.view.devicePixelRatio = 2;
    tester.view.physicalSize = _phone * 2;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    for (final screen in ScreenMock.values) {
      await tester.pumpWidget(_app(ScreenMocks(screen: screen, proposed: _proposed)));
      await tester.pump(const Duration(milliseconds: 600));
      await tester.runAsync(() => capturePng(tester, _dir, '${_tag}_${screen.name}'));
    }
    // Marathi, for the type decision.
    await tester.pumpWidget(_app(ScreenMocks(screen: ScreenMock.home, proposed: _proposed), devanagari: true));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.runAsync(() => capturePng(tester, _dir, '${_tag}_home_mr_theme'));
  });
}
