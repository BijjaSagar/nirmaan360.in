import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/design/internal/soft_surface.dart';

import 'test_helpers.dart';

BuildingArtPainter _artPainter(WidgetTester tester) => tester
    .widget<CustomPaint>(find.descendant(of: find.byType(BuildingProgressArt), matching: find.byType(CustomPaint)))
    .painter! as BuildingArtPainter;

void main() {
  setUpAll(loadRealFonts);

  group('BuildingProgressArt', () {
    testWidgets('reduced motion: final state on the first frame, no animation', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 300, child: BuildingProgressArt(fraction: 0.7)), disableAnimations: true);
      expect(_artPainter(tester).fraction, closeTo(0.7, 0.0001));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('animated: rises from nothing built to the fraction, then is still', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 300, child: BuildingProgressArt(fraction: 0.7)));
      expect(_artPainter(tester).fraction, 0);
      await tester.pump(const Duration(milliseconds: 500));
      final double mid = _artPainter(tester).fraction;
      expect(mid, greaterThan(0));
      expect(mid, lessThanOrEqualTo(0.7));
      await tester.pump(const Duration(seconds: 2));
      expect(_artPainter(tester).fraction, closeTo(0.7, 0.0001));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('a changed fraction animates from where it was', (tester) async {
      final v = ValueNotifier<double>(0.2);
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        SizedBox(
          width: 300,
          child: ValueListenableBuilder<double>(
            valueListenable: v,
            builder: (_, value, _) => BuildingProgressArt(fraction: value, animateEntry: false),
          ),
        ),
      );
      expect(_artPainter(tester).fraction, closeTo(0.2, 0.0001));
      v.value = 0.9;
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));
      expect(_artPainter(tester).fraction, greaterThan(0.2));
      await tester.pump(const Duration(seconds: 2));
      expect(_artPainter(tester).fraction, closeTo(0.9, 0.0001));
    });

    testWidgets('sits in a RepaintBoundary and repaints only on a new fraction', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 300, child: BuildingProgressArt(fraction: 1)), disableAnimations: true);
      expect(find.descendant(of: find.byType(BuildingProgressArt), matching: find.byType(RepaintBoundary)), findsOneWidget);
      const a = BuildingArtPainter(fraction: 0.5);
      expect(a.shouldRepaint(const BuildingArtPainter(fraction: 0.5)), isFalse);
      expect(a.shouldRepaint(const BuildingArtPainter(fraction: 0.6)), isTrue);
    });

    testWidgets('draws at the sizes Home uses without throwing', (tester) async {
      for (final width in <double>[150, 240, 340]) {
        await pumpDesign(tester, SizedBox(width: width, child: const BuildingProgressArt(fraction: 0.36)), disableAnimations: true);
        expect(tester.takeException(), isNull);
      }
    });

    testWidgets('an out-of-range fraction is held to the ends', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 200, child: BuildingProgressArt(fraction: 2)), disableAnimations: true);
      expect(_artPainter(tester).fraction, 1);
    });
  });

  group('HatchedProgressBar', () {
    testWidgets('reduced motion: at its final state on the first frame', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 300, child: HatchedProgressBar(fraction: 0.5, markerLabel: 'Today')), disableAnimations: true);
      expect(tester.hasRunningAnimations, isFalse);
      expect(tester.takeException(), isNull);
    });

    testWidgets('speaks the percent and the marker', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      await pumpDesign(tester, const SizedBox(width: 300, child: HatchedProgressBar(fraction: 0.5, markerLabel: 'Today')), disableAnimations: true);
      expect(find.bySemanticsLabel('50%, Today'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('animated: runs in and settles', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 300, child: HatchedProgressBar(fraction: 0.5)));
      expect(tester.hasRunningAnimations, isTrue);
      await tester.pump(const Duration(seconds: 1));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('edge fractions do not throw', (tester) async {
      for (final f in <double>[0, 1, -1, 3]) {
        await pumpDesign(tester, SizedBox(width: 300, child: HatchedProgressBar(fraction: f, markerLabel: 'Today')), disableAnimations: true);
        expect(tester.takeException(), isNull);
      }
    });
  });

  group('small data widgets', () {
    testWidgets('TickGauge paints, speaks, and takes a tick count', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      await pumpDesign(tester, const SizedBox(width: 300, child: TickGauge(fraction: 0.25, ticks: 20)));
      expect(find.bySemanticsLabel('25%'), findsOneWidget);
      expect(tester.takeException(), isNull);
      handle.dispose();
    });

    testWidgets('SquarePercent shows its label and is 56dp', (tester) async {
      await pumpDesign(tester, const SquarePercent(fraction: 0.9, label: '90%'));
      expect(find.text('90%'), findsOneWidget);
      expect(tester.getSize(find.byType(SquarePercent)), const Size.square(56));
    });

    testWidgets('IconTile is 56dp, named, tappable, and shows a badge', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      var taps = 0;
      await pumpDesign(tester, IconTile(icon: Icons.home, semanticLabel: 'Home', badge: '3', onTap: () => taps++));
      expect(tester.getSize(find.byType(IconTile)), const Size.square(N360Size.tap));
      expect(find.text('3'), findsOneWidget);
      expect(find.bySemanticsLabel('Home, 3'), findsOneWidget);
      await tester.tap(find.byType(IconTile));
      await tester.pump(const Duration(milliseconds: 200));
      expect(taps, 1);
      handle.dispose();
    });

    testWidgets('StatTile is at least 96dp and keeps the value as text', (tester) async {
      var taps = 0;
      await pumpDesign(
        tester,
        SizedBox(
          width: 170,
          child: StatTile(title: 'Finishing work', value: '67%', caption: '25 Nov', onTap: () => taps++),
        ),
      );
      expect(tester.getSize(find.byType(StatTile)).height, greaterThanOrEqualTo(96));
      expect(find.text('67%'), findsOneWidget);
      await tester.tap(find.text('67%'));
      await tester.pump(const Duration(milliseconds: 200));
      expect(taps, 1);
    });

    testWidgets('an emphasised StatTile keeps dark ink on gold', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 170, child: StatTile(title: 'Work', value: '42%', emphasized: true)));
      expect(tester.widget<Text>(find.text('42%')).style!.color, N360Colors.ink);
    });
  });

  group('rules that shape the look', () {
    SoftSurfacePainter cardPainter(WidgetTester tester) => tester
        .widget<CustomPaint>(find.descendant(of: find.byType(LedgerCard), matching: find.byType(CustomPaint)).first)
        .painter! as SoftSurfacePainter;

    testWidgets('a card has a shadow, a row built by a list builder does not', (tester) async {
      await pumpDesign(tester, const LedgerCard(child: Text('Card')));
      expect(cardPainter(tester).shadow, isTrue);

      await pumpDesign(
        tester,
        SizedBox(
          width: 300,
          height: 400,
          child: ListView.builder(
            itemCount: 40,
            itemBuilder: (_, i) => LedgerCard(child: Text('Row $i')),
          ),
        ),
      );
      expect(cardPainter(tester).shadow, isFalse);
    });

    testWidgets('flat drops the shadow by request', (tester) async {
      await pumpDesign(tester, const LedgerCard(flat: true, child: Text('Card')));
      expect(cardPainter(tester).shadow, isFalse);
    });

    testWidgets('the bottom bar keeps a word under every icon and shows badges', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      await pumpDesign(
        tester,
        SizedBox(
          width: 360,
          child: N360BottomBar(
            currentIndex: 1,
            onTap: (_) {},
            items: const <N360BarItem>[
              N360BarItem(icon: Icons.home, label: 'Home'),
              N360BarItem(icon: Icons.approval, label: 'Approve', badge: '4'),
            ],
          ),
        ),
      );
      final Rect homeIcon = tester.getRect(find.byIcon(Icons.home));
      final Rect homeWord = tester.getRect(find.text('Home'));
      expect(homeWord.top, greaterThanOrEqualTo(homeIcon.bottom - 1));
      expect(find.text('4'), findsOneWidget);
      expect(find.bySemanticsLabel('Approve, 4'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('PressTarget is exported from the barrel', (tester) async {
      var taps = 0;
      await pumpDesign(
        tester,
        PressTarget(
          onTap: () => taps++,
          builder: (context, depth, child) => const SizedBox(width: 80, height: 80),
        ),
      );
      await tester.tap(find.byType(PressTarget));
      await tester.pump(const Duration(milliseconds: 200));
      expect(taps, 1);
    });
  });
}
