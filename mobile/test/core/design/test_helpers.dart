import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';

/// Flutter tests render text in a placeholder font, so a real layout and a real
/// picture both need the bundled fonts loaded first.
Future<void> loadRealFonts() async {
  Future<void> family(String name, List<String> paths) async {
    final loader = FontLoader(name);
    for (final path in paths) {
      loader.addFont(rootBundle.load(path));
    }
    await loader.load();
  }

  await family('Poppins', <String>[
    'assets/fonts/Poppins-Medium.ttf',
    'assets/fonts/Poppins-SemiBold.ttf',
    'assets/fonts/Poppins-Bold.ttf',
  ]);
  await family('Mukta', <String>[
    'assets/fonts/Mukta-Regular.ttf',
    'assets/fonts/Mukta-Medium.ttf',
    'assets/fonts/Mukta-SemiBold.ttf',
    'assets/fonts/Mukta-Bold.ttf',
  ]);
  await family('DMMono', <String>[
    'assets/fonts/DMMono-Regular.ttf',
    'assets/fonts/DMMono-Medium.ttf',
  ]);
  await family('MaterialIcons', <String>['fonts/MaterialIcons-Regular.otf']);
}

/// Pumps [child] the way the app would: real theme, phone size.
Future<void> pumpDesign(
  WidgetTester tester,
  Widget child, {
  bool disableAnimations = false,
  double textScale = 1,
  Size size = const Size(360, 800),
  bool root = false,
}) async {
  tester.view.physicalSize = size * 3;
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(
    MaterialApp(
      theme: buildN360Theme(),
      debugShowCheckedModeBanner: false,
      builder: (context, navigator) {
        final data = MediaQuery.of(context).copyWith(
          disableAnimations: disableAnimations,
          textScaler: TextScaler.linear(textScale),
        );
        final Widget app = root ? LedgerPaper.root(child: navigator) : navigator!;
        return MediaQuery(data: data, child: app);
      },
      home: Scaffold(body: Center(child: child)),
    ),
  );
}

/// Asserts the widget found by [finder] paints at least [min] logical pixels high.
void expectTapHeight(WidgetTester tester, Finder finder, {double min = N360Size.tap}) {
  final Size size = tester.getSize(finder);
  expect(size.height, greaterThanOrEqualTo(min), reason: 'tap target is ${size.height}dp high');
}

/// Saves the current frame of a [RepaintBoundary] as a PNG in [dir].
Future<void> capturePng(WidgetTester tester, String dir, String name, {Finder? finder}) async {
  final Finder target = finder ?? find.byType(MaterialApp);
  final Element element = target.evaluate().first;
  final ui.Image image = await captureImage(element);
  final ByteData? bytes = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();
  await File('$dir/$name.png').writeAsBytes(bytes!.buffer.asUint8List());
}
