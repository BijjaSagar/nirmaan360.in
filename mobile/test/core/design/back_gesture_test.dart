import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';

void main() {
  /// A first page with a button that pushes [second] through an [N360Page].
  Future<void> pumpStack(WidgetTester tester, {required Widget second}) async {
    tester.view.physicalSize = const Size(390, 844) * 2;
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      MaterialApp(
        theme: buildN360Theme(),
        home: Builder(
          builder: (context) => Scaffold(
            body: Center(
              child: TextButton(
                onPressed: () => Navigator.of(context).push(N360Page<void>(child: second).createRoute(context)),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
  }

  final TargetPlatformVariant ios = TargetPlatformVariant.only(TargetPlatform.iOS);

  testWidgets('on iOS a left-edge swipe pops the pushed page and the page follows the finger', (tester) async {
    await pumpStack(tester, second: const Scaffold(body: Center(child: Text('Second'))));
    expect(find.text('Second'), findsOneWidget);
    final double rest = tester.getTopLeft(find.text('Second')).dx;

    final TestGesture finger = await tester.startGesture(const Offset(8, 400));
    await finger.moveBy(const Offset(120, 0));
    await tester.pump();
    // Mid-swipe the page sits under the finger, not at rest and not gone.
    final double moved = tester.getTopLeft(find.text('Second')).dx;
    expect(moved, greaterThan(rest + 60));
    expect(moved, lessThan(rest + 140));
    expect(find.text('Second'), findsOneWidget);

    await finger.moveBy(const Offset(200, 0));
    await tester.pump();
    await finger.up();
    await tester.pumpAndSettle();

    expect(find.text('Second'), findsNothing);
    expect(find.text('Open'), findsOneWidget);
  }, variant: ios);

  testWidgets('on iOS a short swipe that is let go settles the page back', (tester) async {
    await pumpStack(tester, second: const Scaffold(body: Center(child: Text('Second'))));
    final double rest = tester.getTopLeft(find.text('Second')).dx;

    final TestGesture finger = await tester.startGesture(const Offset(8, 400));
    await finger.moveBy(const Offset(60, 0));
    await tester.pump();
    await finger.up();
    await tester.pumpAndSettle();

    expect(find.text('Second'), findsOneWidget);
    expect(tester.getTopLeft(find.text('Second')).dx, rest);
  }, variant: ios);

  testWidgets('on iOS a page whose PopScope holds the pop cannot be swiped away',
      (tester) async {
    var asked = 0;
    await pumpStack(
      tester,
      second: PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) {
          if (!didPop) asked++;
        },
        child: const Scaffold(body: Center(child: Text('Form'))),
      ),
    );
    final double rest = tester.getTopLeft(find.text('Form')).dx;

    final TestGesture finger = await tester.startGesture(const Offset(8, 400));
    await finger.moveBy(const Offset(150, 0));
    await tester.pump();
    // Typed work is never dragged away: the page does not move.
    expect(tester.getTopLeft(find.text('Form')).dx, rest);
    await finger.moveBy(const Offset(120, 0));
    await tester.pump();
    await finger.up();
    await tester.pumpAndSettle();

    expect(find.text('Form'), findsOneWidget);
    expect(asked, 0, reason: 'the gesture is off, so the form stays open with its work');
  }, variant: ios);

  testWidgets('on Android the edge strip is not there; the system back is', (tester) async {
    await pumpStack(tester, second: const Scaffold(body: Center(child: Text('Second'))));
    final double rest = tester.getTopLeft(find.text('Second')).dx;

    final TestGesture finger = await tester.startGesture(const Offset(8, 400));
    await finger.moveBy(const Offset(300, 0));
    await tester.pump();
    expect(tester.getTopLeft(find.text('Second')).dx, rest);
    await finger.up();
    await tester.pumpAndSettle();
    expect(find.text('Second'), findsOneWidget);
  }, variant: TargetPlatformVariant.only(TargetPlatform.android));
}
