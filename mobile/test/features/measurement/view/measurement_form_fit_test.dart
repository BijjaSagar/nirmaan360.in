// Whether the entry page picks the layout that fits the phone. The full layout
// puts Add / Deduct and the details key in a list above the quantity band; on
// a phone too short for it, that list cut them in half under the band.
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/features/measurement/view/widgets/book_kit.dart';
import 'package:nirmaan360/features/measurement/view/widgets/form_parts.dart';

import '../../../core/design/test_helpers.dart';
import 'book_test_kit.dart';

const String _longTitle = 'RCC M25 in slabs and beams including shuttering';
const Key _photos = ValueKey<String>('measurement-form-photos');

/// One project with a BOQ line whose title takes two lines in the full layout.
Future<TestApp> _longLineApp() async {
  final FakeApi server = FakeApi()
    ..addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B')
    ..addBoqItem(id: 1, projectId: 1, code: '2.1', qty: '620.000', unit: 'cum');
  server.boqItems.last['description'] = _longTitle;
  final TestApp app = await TestApp.create(seed: false, api: server);
  await app.sync();
  return app;
}

/// Opens a new entry on a phone [size] dp with the given status bar and
/// gesture bar, and picks the long line.
Future<void> _openEntry(
  WidgetTester tester,
  TestApp app, {
  required Size size,
  double top = 24,
  double bottom = 24,
  double textScale = 1,
  String locale = 'en',
}) async {
  const double ratio = 2.625;
  tester.view.padding = FakeViewPadding(top: top * ratio, bottom: bottom * ratio);
  addTearDown(tester.view.resetPadding);
  await pumpBook(
    tester,
    app,
    start: Routes.measurementForm,
    size: size,
    pixelRatio: ratio,
    textScale: textScale,
    locale: locale,
  );
  await tester.pump(const Duration(milliseconds: 300));
  await tester.tap(find.byType(LineHeader));
  await tester.pump();
  await pumpDrift(tester, 8);
  await tester.pump(const Duration(milliseconds: 400));
  await tester.tap(find.textContaining('2.1 · ').last);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
  await pumpDrift(tester, 8);
  expect(tester.takeException(), isNull);
}

/// A finger landing anywhere on [finder], corners included, reaches it: nothing
/// covers it and no list cuts it off.
void _expectWhollyTappable(WidgetTester tester, Finder finder) {
  expect(finder, findsOneWidget);
  final RenderObject target = tester.renderObject(finder);
  final Rect inner = tester.getRect(finder).deflate(3);
  for (final Offset point in <Offset>[
    inner.topLeft,
    inner.topRight,
    inner.center,
    inner.bottomLeft,
    inner.bottomRight,
  ]) {
    final HitTestResult hit = tester.hitTestOnBinding(point);
    expect(
      hit.path.any((HitTestEntry entry) => entry.target == target),
      isTrue,
      reason: '$finder is covered or cut off at $point',
    );
  }
}

Finder _choice(String label) => find.widgetWithText(ChoiceTile, label);

Future<void> _closeSheet(WidgetTester tester) async {
  await tester.tapAt(const Offset(20, 40));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 500));
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
  });

  // 1080 × 1920 at 420 dpi. With the bars taken off there is no room for the
  // full layout, so Add / Deduct folds into a tile beside the dimensions.
  for (final double statusBar in <double>[24, 40]) {
    testWidgets('a 411 × 731 phone with a two-line BOQ title keeps Add / Deduct whole (status bar $statusBar)', (
      tester,
    ) async {
      final TestApp app = await _longLineApp();
      addTearDown(app.dispose);
      await _openEntry(tester, app, size: const Size(411.43, 731.43), top: statusBar);

      // Whichever layout is on screen, Add / Deduct and the details key are
      // whole under the finger.
      if (find.byType(SignToggle).evaluate().isNotEmpty) {
        _expectWhollyTappable(tester, _choice('Add'));
        _expectWhollyTappable(tester, _choice('Deduct'));
      } else {
        _expectWhollyTappable(tester, find.byType(SignFlipTile));
      }
      _expectWhollyTappable(tester, find.byType(DetailsKey));

      // Here that is the compact layout, and both keys work.
      expect(find.byType(SignFlipTile), findsOneWidget);
      expect(find.byType(SignToggle), findsNothing);

      await tester.tap(find.byType(SignFlipTile));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.descendant(of: find.byType(SignFlipTile), matching: find.text('Deduct')), findsOneWidget);
      await tester.tap(find.byType(SignFlipTile));
      await tester.pump(const Duration(milliseconds: 300));
      expect(find.descendant(of: find.byType(SignFlipTile), matching: find.text('Add')), findsOneWidget);

      await tester.tap(find.byType(DetailsKey));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      expect(find.byKey(_photos), findsOneWidget);
      await _closeSheet(tester);
      await tester.pump(const Duration(seconds: 1));
    });
  }

  // Each phone, text size and language: the layout chosen, and in the full
  // layout Add, Deduct and the details key all whole under the finger.
  final List<({String name, Size size, double top, double bottom, double text, bool full})> phones = [
    (name: '360 × 640, no bars', size: const Size(360, 640), top: 0, bottom: 0, text: 1, full: false),
    (name: '360 × 800, no bars', size: const Size(360, 800), top: 0, bottom: 0, text: 1, full: true),
    (name: '360 × 800, no bars, text 130%', size: const Size(360, 800), top: 0, bottom: 0, text: 1.3, full: true),
    (name: '411 × 731', size: const Size(411.43, 731.43), top: 24, bottom: 24, text: 1, full: false),
    (name: '411 × 760', size: const Size(411.43, 760), top: 24, bottom: 24, text: 1, full: true),
    (name: '412 × 915', size: const Size(412, 915), top: 24, bottom: 24, text: 1, full: true),
    (name: '412 × 915, text 150%', size: const Size(412, 915), top: 24, bottom: 24, text: 1.5, full: true),
    (name: '412 × 915, text 200%', size: const Size(412, 915), top: 24, bottom: 24, text: 2, full: false),
  ];
  for (final String locale in <String>['en', 'mr']) {
    for (final phone in phones) {
      testWidgets('${phone.name}, $locale: ${phone.full ? 'full' : 'compact'} layout, nothing cut', (tester) async {
        final TestApp app = await _longLineApp();
        addTearDown(app.dispose);
        await _openEntry(
          tester,
          app,
          size: phone.size,
          top: phone.top,
          bottom: phone.bottom,
          textScale: phone.text,
          locale: locale,
        );

        if (phone.full) {
          expect(find.byType(SignFlipTile), findsNothing);
          final String add = locale == 'en' ? 'Add' : 'जोडा';
          final String deduct = locale == 'en' ? 'Deduct' : 'वजा करा';
          _expectWhollyTappable(tester, _choice(add));
          _expectWhollyTappable(tester, _choice(deduct));
          _expectWhollyTappable(tester, find.byType(DetailsKey));

          await tester.tap(_choice(deduct));
          await tester.pump(const Duration(milliseconds: 300));
          expect(tester.widget<ChoiceTile>(_choice(deduct)).selected, isTrue);
        } else {
          expect(find.byType(SignToggle), findsNothing);
          _expectWhollyTappable(tester, find.byType(SignFlipTile));
        }
        await tester.pump(const Duration(seconds: 1));
      });
    }
  }
}
