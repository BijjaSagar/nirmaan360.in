import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/design/surfaces/ledger_paper.dart' show paperJolt;

import 'test_helpers.dart';

/// Records every haptic the design system fires.
List<String> recordHaptics(WidgetTester tester) {
  final log = <String>[];
  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
    if (call.method == 'HapticFeedback.vibrate') log.add(call.arguments as String);
    return null;
  });
  addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
  return log;
}

/// Text must be opaque: the web app shipped a bug where text was invisible until an animation ran.
void expectTextOpaque(WidgetTester tester, String text) {
  final Finder finder = find.text(text);
  expect(finder, findsWidgets, reason: '"$text" is not in the tree');
  final Text widget = tester.widget<Text>(finder.first);
  final double alpha = widget.style?.color?.a ?? 1;
  expect(alpha, 1, reason: '"$text" is transparent');
  expect(find.ancestor(of: finder.first, matching: find.byType(Opacity)), findsNothing);
  expect(find.ancestor(of: finder.first, matching: find.byType(FadeTransition)), findsNothing);
}

/// Counts pixels clearly darker than the paper in the region of [finder].
Future<int> inkedPixels(WidgetTester tester, Finder finder) async {
  final int count = (await tester.runAsync(() async {
    final ui.Image image = await captureImage(finder.evaluate().first);
    final ByteData? data = await image.toByteData(format: ui.ImageByteFormat.rawRgba);
    final Uint8List bytes = data!.buffer.asUint8List();
    var dark = 0;
    for (var i = 0; i < bytes.length; i += 4) {
      final int luma = bytes[i] + bytes[i + 1] + bytes[i + 2];
      if (bytes[i + 3] > 200 && luma < 330) dark++;
    }
    image.dispose();
    return dark;
  }))!;
  return count;
}

/// Steps time in 16ms frames so tickers and post-frame callbacks all run.
Future<void> pumpMs(WidgetTester tester, int ms) async {
  for (var left = ms; left > 0; left -= 16) {
    await tester.pump(const Duration(milliseconds: 16));
  }
}

Widget _onPaper(Widget child) => LedgerPaper(child: Center(child: child));

void main() {
  setUpAll(loadRealFonts);

  group('RubberStamp', () {
    testWidgets('with reduced motion it is at rest on the first frame', (tester) async {
      await pumpDesign(
        tester,
        const RubberStamp(kind: StampKind.approved, label: 'Approved', animate: true),
        disableAnimations: true,
      );
      expect(find.byType(RepaintBoundary), findsWidgets);
      expect(tester.takeException(), isNull);
      final Transform rest = tester.widget<Transform>(
        find.ancestor(of: find.text('APPROVED'), matching: find.byType(Transform)).first,
      );
      expect(rest.transform.getRotation().getColumn(0).y, closeTo(-0.12187, 0.01));
    });

    testWidgets('animated: opaque at frame 0, starts large, lands at rest', (tester) async {
      await pumpDesign(tester, const RubberStamp(kind: StampKind.rejected, label: 'Rejected', animate: true));
      expectTextOpaque(tester, 'REJECTED');
      final List<Transform> startFrames = tester
          .widgetList<Transform>(find.ancestor(of: find.text('REJECTED'), matching: find.byType(Transform)))
          .toList();
      expect(startFrames.any((t) => (t.transform.getMaxScaleOnAxis() - 2.2).abs() < 0.01), isTrue);

      await tester.pump(const Duration(milliseconds: 600));
      final List<Transform> endFrames = tester
          .widgetList<Transform>(find.ancestor(of: find.text('REJECTED'), matching: find.byType(Transform)))
          .toList();
      expect(endFrames.every((t) => (t.transform.getMaxScaleOnAxis() - 1).abs() < 0.001), isTrue);
    });

    testWidgets('onLanded fires as it touches the page, not at the start', (tester) async {
      var landed = 0;
      await pumpDesign(
        tester,
        RubberStamp(kind: StampKind.approved, label: 'Approved', animate: true, onLanded: () => landed++),
      );
      expect(landed, 0);
      await tester.pump(const Duration(milliseconds: 80));
      expect(landed, 0);
      await tester.pump(const Duration(milliseconds: 120));
      expect(landed, 1);
      await tester.pump(const Duration(milliseconds: 500));
      expect(landed, 1);
    });

    testWidgets('a not-animated stamp has no thud at all', (tester) async {
      await pumpDesign(tester, const RubberStamp(kind: StampKind.draft, label: 'Draft'));
      final List<Transform> transforms = tester
          .widgetList<Transform>(find.ancestor(of: find.text('DRAFT'), matching: find.byType(Transform)))
          .toList();
      expect(transforms, hasLength(1));
    });
  });

  group('showStampThud', () {
    Future<(BuildContext, List<String>)> pumpHost(WidgetTester tester, {bool reduced = false}) async {
      final log = recordHaptics(tester);
      await pumpDesign(tester, const SizedBox(width: 10, height: 10), disableAnimations: reduced, root: true);
      return (tester.element(find.byType(Scaffold)), log);
    }

    testWidgets('the haptic fires on impact, the page jolts, and it clears in under a second', (tester) async {
      final (context, log) = await pumpHost(tester);
      var done = false;
      showStampThud(context, StampKind.approved, 'Approved').then((_) => done = true);

      await tester.pump();
      expect(log, isEmpty, reason: 'no haptic at the start');
      expect(find.text('APPROVED'), findsOneWidget);
      expectTextOpaque(tester, 'APPROVED');

      await tester.pump(const Duration(milliseconds: 170));
      expect(log, contains('HapticFeedbackType.heavyImpact'));
      expect(paperJolt.value.dy, greaterThan(0));

      await tester.pump(const Duration(milliseconds: 800));
      await tester.pump();
      expect(done, isTrue);
      expect(find.text('APPROVED'), findsNothing);
      expect(paperJolt.value, Offset.zero);
    });

    testWidgets('with reduced motion it shows briefly with no movement', (tester) async {
      final (context, log) = await pumpHost(tester, reduced: true);
      var done = false;
      showStampThud(context, StampKind.synced, 'Sent').then((_) => done = true);
      await tester.pump();
      expect(find.text('SENT'), findsOneWidget);
      expect(log, contains('HapticFeedbackType.heavyImpact'));
      final List<Transform> transforms = tester
          .widgetList<Transform>(find.ancestor(of: find.text('SENT'), matching: find.byType(Transform)))
          .toList();
      expect(transforms.every((t) => (t.transform.getMaxScaleOnAxis() - 1).abs() < 0.001), isTrue);
      await pumpMs(tester, N360Durations.thudStill.inMilliseconds + 64);
      expect(done, isTrue);
      expect(paperJolt.value, Offset.zero);
    });
  });

  group('RollingNumber', () {
    testWidgets('with reduced motion the final digits are there at once', (tester) async {
      await pumpDesign(tester, const RollingMoney(value: '1,234.50'), disableAnimations: true);
      expect(await inkedPixels(tester, find.byType(RollingNumber)), greaterThan(30));
    });

    testWidgets('with reduced motion a change lands on the very next frame', (tester) async {
      final ValueNotifier<String> v = ValueNotifier('12');
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        ValueListenableBuilder<String>(
          valueListenable: v,
          builder: (_, value, _) => RollingNumber(value: value, style: N360Type.figureLarge),
        ),
        disableAnimations: true,
      );
      final double before = tester.getSize(find.byType(RollingNumber)).width;
      v.value = '12,345.678';
      await tester.pump();
      expect(tester.getSize(find.byType(RollingNumber)).width, greaterThan(before * 3));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('digits are painted and opaque at frame 0 and during a roll', (tester) async {
      final ValueNotifier<String> v = ValueNotifier('999.875');
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        ValueListenableBuilder<String>(
          valueListenable: v,
          builder: (_, value, _) => RollingQty(value: value, unit: 'cum', style: N360Type.figureLarge),
        ),
      );
      expect(await inkedPixels(tester, find.byType(RollingNumber)), greaterThan(50));

      v.value = '1250.000';
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 40));
      expect(await inkedPixels(tester, find.byType(RollingNumber)), greaterThan(50));

      await tester.pump(const Duration(seconds: 1));
      expect(await inkedPixels(tester, find.byType(RollingNumber)), greaterThan(50));
      expect(tester.takeException(), isNull);
    });

    testWidgets('semantics read the whole value exactly once', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      await pumpDesign(tester, const RollingMoney(value: '12,500.00'));
      expect(find.bySemanticsLabel('₹12,500.00'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('width glides when the digit count grows, with no jump', (tester) async {
      final ValueNotifier<String> v = ValueNotifier('999');
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        ValueListenableBuilder<String>(
          valueListenable: v,
          builder: (_, value, _) => RollingNumber(value: value, style: N360Type.figureLarge),
        ),
      );
      final double before = tester.getSize(find.byType(RollingNumber)).width;

      v.value = '10,000';
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 30));
      final double early = tester.getSize(find.byType(RollingNumber)).width;
      await tester.pump(const Duration(milliseconds: 30));
      final double later = tester.getSize(find.byType(RollingNumber)).width;
      await tester.pump(const Duration(seconds: 1));
      final double after = tester.getSize(find.byType(RollingNumber)).width;

      expect(early, greaterThan(before));
      expect(later, greaterThanOrEqualTo(early));
      expect(after, greaterThan(later));
      expect(tester.takeException(), isNull);
    });

    testWidgets('digits removed and retargeted mid-roll do not throw', (tester) async {
      final ValueNotifier<String> v = ValueNotifier('12,345.67');
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        ValueListenableBuilder<String>(
          valueListenable: v,
          builder: (_, value, _) => RollingMoney(value: value),
        ),
      );
      for (final next in <String>['12', '12.', '12.5', '12.50', '9', '', '1,00,000.00']) {
        v.value = next;
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 20));
        expect(tester.takeException(), isNull, reason: next);
      }
      await tester.pump(const Duration(seconds: 1));
      expect(await inkedPixels(tester, find.byType(RollingNumber)), greaterThan(20));
    });

    testWidgets('survives being squeezed by a narrow parent', (tester) async {
      await pumpDesign(
        tester,
        const SizedBox(width: 40, child: RollingNumber(value: '1,23,456.789', style: N360Type.figureLarge)),
      );
      expect(tester.takeException(), isNull);
    });
  });

  group('StaggeredRise', () {
    testWidgets('reduced motion: child in place on the first frame', (tester) async {
      await pumpDesign(tester, const StaggeredRise(index: 3, child: Text('Row')), disableAnimations: true);
      expect(find.text('Row'), findsOneWidget);
      expect(find.ancestor(of: find.text('Row'), matching: find.byType(Transform)), findsNothing);
    });

    testWidgets('animated: readable at frame 0, translated only', (tester) async {
      await pumpDesign(tester, const StaggeredRise(index: 0, child: Text('Row')));
      expectTextOpaque(tester, 'Row');
      final Transform t = tester.widget<Transform>(find.ancestor(of: find.text('Row'), matching: find.byType(Transform)).first);
      expect(t.transform.getTranslation().y, closeTo(18, 0.5));

      await tester.pump(const Duration(seconds: 1));
      final Transform end = tester.widget<Transform>(find.ancestor(of: find.text('Row'), matching: find.byType(Transform)).first);
      expect(end.transform.getTranslation().y, closeTo(0, 0.01));
    });

    testWidgets('long lists are capped: late rows do not animate or wait', (tester) async {
      await pumpDesign(tester, const StaggeredRise(index: 40, child: Text('Row')));
      expect(find.ancestor(of: find.text('Row'), matching: find.byType(Transform)), findsNothing);
    });
  });

  group('N360Page', () {
    Widget app({required bool second, bool reduced = false}) => MediaQuery(
          data: MediaQueryData(size: const Size(360, 800), disableAnimations: reduced),
          child: MaterialApp(
            theme: buildN360Theme(),
            builder: (context, child) => LedgerPaper.root(child: child),
            home: Navigator(
              pages: <Page<void>>[
                const N360Page<void>(key: ValueKey<String>('first'), child: Scaffold(body: Center(child: Text('First')))),
                if (second)
                  const N360Page<void>(key: ValueKey<String>('second'), child: Scaffold(body: Center(child: Text('Second')))),
              ],
              onDidRemovePage: (_) {},
            ),
          ),
        );

    testWidgets('slides over the paper: content readable, nothing fades', (tester) async {
      await tester.pumpWidget(app(second: false));
      await tester.pumpWidget(app(second: true));
      await tester.pump();
      expectTextOpaque(tester, 'Second');
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.text('First'), findsOneWidget);
      expect(find.text('Second'), findsOneWidget);
      await pumpMs(tester, 500);
      expect(find.text('First'), findsNothing);
      expect(find.text('Second'), findsOneWidget);
    });

    testWidgets('reduced motion: no slide, the page is simply there', (tester) async {
      await tester.pumpWidget(app(second: false, reduced: true));
      await tester.pumpWidget(app(second: true, reduced: true));
      await tester.pump();
      expect(find.text('Second'), findsOneWidget);
      expect(find.ancestor(of: find.text('Second'), matching: find.byType(SlideTransition)), findsNothing);
      await pumpMs(tester, 500);
    });

    testWidgets('LedgerHero: with reduced motion there is no flight at all', (tester) async {
      await pumpDesign(
        tester,
        const LedgerHero(tag: 'a', child: Text('Row')),
        disableAnimations: true,
      );
      expect(find.byType(Hero), findsNothing);
      expect(find.text('Row'), findsOneWidget);
    });

    testWidgets('LedgerHero flies text on a sheet', (tester) async {
      Widget hero(bool second) => MaterialApp(
            theme: buildN360Theme(),
            home: Navigator(
              observers: <NavigatorObserver>[HeroController()],
              pages: <Page<void>>[
                const N360Page<void>(
                  key: ValueKey<String>('list'),
                  child: Scaffold(
                    body: Column(
                      children: <Widget>[
                        LedgerHero(tag: 'a', child: SizedBox(width: 300, height: 60, child: Text('Footing F12'))),
                      ],
                    ),
                  ),
                ),
                if (second)
                  const N360Page<void>(
                    key: ValueKey<String>('detail'),
                    child: Scaffold(
                      body: Column(
                        children: <Widget>[
                          SizedBox(height: 200),
                          LedgerHero(tag: 'a', child: SizedBox(width: 320, height: 120, child: Text('Footing F12'))),
                        ],
                      ),
                    ),
                  ),
              ],
              onDidRemovePage: (_) {},
            ),
          );
      await tester.pumpWidget(hero(false));
      await tester.pumpWidget(hero(true));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(tester.takeException(), isNull);
      expect(find.text('Footing F12'), findsWidgets);
      await pumpMs(tester, 500);
      expect(tester.takeException(), isNull);
    });
  });

  group('ProgressRule', () {
    testWidgets('reduced motion: still, no ticking', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 200, child: ProgressRule()), disableAnimations: true);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });

    testWidgets('animated: stops when inactive and shows a determinate value', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 200, child: ProgressRule(value: 0.4)));
      await tester.pump(const Duration(milliseconds: 500));
      await pumpDesign(tester, const SizedBox(width: 200, child: ProgressRule()));
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.hasRunningAnimations, isTrue);
      await pumpDesign(tester, const SizedBox(width: 200, child: ProgressRule(active: false)));
      await tester.pumpAndSettle();
    });
  });

  group('SyncPill', () {
    testWidgets('reduced motion: count there at once, still icon', (tester) async {
      await pumpDesign(tester, const SyncPill(state: SyncVisualState.syncing, count: 3, label: 'sending'), disableAnimations: true);
      await tester.pumpAndSettle();
      expect(find.text('sending'), findsOneWidget);
      expect(find.byType(RollingNumber), findsOneWidget);
    });

    testWidgets('always has an icon and words', (tester) async {
      for (final state in SyncVisualState.values) {
        await pumpDesign(tester, SyncPill(state: state, label: 'status'), disableAnimations: true);
        expect(find.byType(Icon), findsOneWidget, reason: '$state');
        expect(find.text('status'), findsOneWidget, reason: '$state');
      }
    });

    testWidgets('a syncing pill rolls down', (tester) async {
      final ValueNotifier<int> count = ValueNotifier(4);
      addTearDown(count.dispose);
      await pumpDesign(
        tester,
        ValueListenableBuilder<int>(
          valueListenable: count,
          builder: (_, value, _) => SyncPill(state: SyncVisualState.syncing, count: value, label: 'sending'),
        ),
      );
      count.value = 3;
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('SlipStack', () {
    SlipData slip(String id, SlipStatus status) => SlipData(
          id: id,
          title: 'Slip $id',
          status: status,
          statusLabel: status.name,
        );

    testWidgets('reduced motion: a sent slip is gone at once', (tester) async {
      final ValueNotifier<List<SlipData>> v = ValueNotifier(<SlipData>[slip('a', SlipStatus.waiting), slip('b', SlipStatus.waiting)]);
      addTearDown(v.dispose);
      final gone = <String>[];
      await pumpDesign(
        tester,
        ValueListenableBuilder<List<SlipData>>(
          valueListenable: v,
          builder: (_, slips, _) => SlipStack(count: slips.length, slips: slips, onSlipGone: gone.add),
        ),
        disableAnimations: true,
      );
      expect(find.text('Slip a'), findsOneWidget);
      v.value = <SlipData>[slip('a', SlipStatus.sent), slip('b', SlipStatus.waiting)];
      await tester.pump();
      expect(find.text('Slip a'), findsNothing);
      expect(find.text('Slip b'), findsOneWidget);
    });

    testWidgets('a sent slip ticks, slides out, and the tray closes', (tester) async {
      final ValueNotifier<List<SlipData>> v = ValueNotifier(<SlipData>[slip('a', SlipStatus.sending), slip('b', SlipStatus.waiting)]);
      addTearDown(v.dispose);
      final gone = <String>[];
      final log = recordHaptics(tester);
      await pumpDesign(
        tester,
        ValueListenableBuilder<List<SlipData>>(
          valueListenable: v,
          builder: (_, slips, _) => SlipStack(count: slips.length, slips: slips, onSlipGone: gone.add),
        ),
      );
      final double fullHeight = tester.getSize(find.byType(SlipStack)).height;

      v.value = <SlipData>[slip('a', SlipStatus.sent), slip('b', SlipStatus.waiting)];
      await tester.pump();
      expect(find.text('Slip a'), findsOneWidget);
      expectTextOpaque(tester, 'Slip a');
      await pumpMs(tester, 300);
      expect(log, contains('HapticFeedbackType.mediumImpact'));
      expect(find.text('Slip a'), findsOneWidget);

      await pumpMs(tester, 360);
      final double midHeight = tester.getSize(find.byType(SlipStack)).height;
      expect(midHeight, lessThan(fullHeight));

      await pumpMs(tester, 500);
      expect(gone, <String>['a']);
      expect(find.text('Slip a'), findsNothing);
      expect(find.text('Slip b'), findsOneWidget);
      expect(tester.getSize(find.byType(SlipStack)).height, lessThan(fullHeight));
      expect(tester.takeException(), isNull);
    });

    testWidgets('a slip dropped by the caller still gets its exit', (tester) async {
      final ValueNotifier<List<SlipData>> v = ValueNotifier(<SlipData>[slip('a', SlipStatus.sending), slip('b', SlipStatus.waiting)]);
      addTearDown(v.dispose);
      final gone = <String>[];
      await pumpDesign(
        tester,
        ValueListenableBuilder<List<SlipData>>(
          valueListenable: v,
          builder: (_, slips, _) => SlipStack(count: slips.length, slips: slips, onSlipGone: gone.add),
        ),
      );
      v.value = <SlipData>[slip('b', SlipStatus.waiting)];
      await tester.pump();
      expect(find.text('Slip a'), findsOneWidget);
      await pumpMs(tester, 1400);
      expect(gone, <String>['a']);
      expect(find.text('Slip a'), findsNothing);
    });

    testWidgets('slips beyond maxVisible do not render as live slips', (tester) async {
      await pumpDesign(
        tester,
        SlipStack(
          count: 9,
          maxVisible: 2,
          slips: <SlipData>[slip('a', SlipStatus.waiting), slip('b', SlipStatus.waiting), slip('c', SlipStatus.waiting)],
        ),
      );
      expect(find.byType(OutboxSlip), findsNWidgets(2));
      expect(find.text('Slip c'), findsNothing);
    });
  });

  group('small animated parts', () {
    testWidgets('RuledDivider draw-in is final at once with reduced motion', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 200, child: RuledDivider(drawIn: true)), disableAnimations: true);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });

    testWidgets('OutboxSlip: reduced motion shows the tick at once and settles', (tester) async {
      var settled = 0;
      await pumpDesign(
        tester,
        SizedBox(
          width: 320,
          child: OutboxSlip(
            title: 'Fuel',
            status: SlipStatus.sent,
            statusLabel: 'Sent',
            onSettled: () => settled++,
          ),
        ),
        disableAnimations: true,
      );
      expect(find.text('Sent'), findsOneWidget);
      await tester.pump();
      expect(settled, 1);
      expect(tester.takeException(), isNull);
    });

    testWidgets('GloveButton press: reduced motion still works without movement frames', (tester) async {
      var taps = 0;
      await pumpDesign(tester, GloveButton(label: 'Save', onPressed: () => taps++), disableAnimations: true);
      await tester.tap(find.text('Save'));
      await tester.pump();
      expect(taps, 1);
    });

    testWidgets('PaperToast: reduced motion shows it in place', (tester) async {
      await pumpDesign(tester, const SizedBox.shrink(), disableAnimations: true);
      final BuildContext context = tester.element(find.byType(Scaffold));
      showPaperToast(context, 'Saved');
      await tester.pump();
      final Transform t = tester.widget<Transform>(
        find.ancestor(of: find.text('Saved'), matching: find.byType(Transform)).first,
      );
      expect(t.transform.getTranslation().y, closeTo(0, 0.01));
      await tester.pump(const Duration(seconds: 4));
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('PaperToast message is readable at frame 0 and leaves on its own', (tester) async {
      await pumpDesign(tester, const SizedBox.shrink());
      final BuildContext context = tester.element(find.byType(Scaffold));
      showPaperToast(context, 'Saved', kind: PaperToastKind.success);
      await tester.pump();
      expect(find.text('Saved'), findsOneWidget);
      expectTextOpaque(tester, 'Saved');
      await tester.pump(const Duration(seconds: 4));
      await tester.pump(const Duration(seconds: 1));
      expect(find.text('Saved'), findsNothing);
    });

    testWidgets('LedgerPaper.root paints grid once, and pages under it do not double it', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: buildN360Theme(),
          builder: (context, child) => LedgerPaper.root(child: child),
          home: const Scaffold(body: LedgerPaper(marginRule: true, child: Text('Page'))),
        ),
      );
      final int boundaries = find.byWidgetPredicate((w) => w is RepaintBoundary).evaluate().length;
      expect(boundaries, greaterThan(0));
      expect(find.text('Page'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('_onPaper standalone paper renders', (tester) async {
      await pumpDesign(tester, _onPaper(const Text('Loose sheet')));
      expect(find.text('Loose sheet'), findsOneWidget);
    });
  });
}

