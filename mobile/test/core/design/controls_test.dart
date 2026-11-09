import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';

import 'test_helpers.dart';

void main() {
  setUpAll(loadRealFonts);

  group('tap targets are at least 56dp', () {
    testWidgets('GloveButton in every kind and state', (tester) async {
      final List<Widget> buttons = <Widget>[
        for (final kind in GloveButtonKind.values)
          GloveButton(label: 'Do it', kind: kind, onPressed: () {}),
        const GloveButton(label: 'Loading', loading: true, onPressed: null),
        const GloveButton(label: 'Disabled', onPressed: null),
        GloveButton(label: 'Large', large: true, onPressed: () {}),
      ];
      for (final button in buttons) {
        await pumpDesign(tester, button);
        expectTapHeight(tester, find.byType(GloveButton));
      }
      await pumpDesign(tester, GloveButton(label: 'Large', large: true, onPressed: () {}));
      expectTapHeight(tester, find.byType(GloveButton), min: N360Size.tapLarge);
    });

    testWidgets('GloveStepper keys', (tester) async {
      await pumpDesign(tester, SizedBox(width: 320, child: GloveStepper(value: 5, onChanged: (_) {})));
      for (final finder in <Finder>[find.byIcon(Icons.remove), find.byIcon(Icons.add)]) {
        final Size size = tester.getSize(find.ancestor(of: finder, matching: find.byType(GestureDetector)).first);
        expect(size.height, greaterThanOrEqualTo(N360Size.tapLarge));
        expect(size.width, greaterThanOrEqualTo(N360Size.tap));
      }
    });

    testWidgets('GloveKeypad keys are at least 64dp', (tester) async {
      await pumpDesign(
        tester,
        SizedBox(
          width: 328,
          child: GloveKeypad(value: '12', onChanged: (_) {}, onNext: () {}, nextLabel: 'Next'),
        ),
      );
      for (final key in <String>['0', '1', '5', '9', '.']) {
        final Size size = tester.getSize(find.ancestor(of: find.text(key), matching: find.byType(Semantics)).first);
        expect(size.height, greaterThanOrEqualTo(N360Size.key), reason: key);
      }
      expect(find.text('Next'), findsOneWidget);
      expect(find.text('Delete'), findsOneWidget);
    });

    testWidgets('DimensionTile', (tester) async {
      await pumpDesign(
        tester,
        SizedBox(width: 90, child: DimensionTile(label: 'L', value: '10.000', onTap: () {})),
      );
      expectTapHeight(tester, find.byType(DimensionTile));
    });

    testWidgets('GloveTextField input is large', (tester) async {
      await pumpDesign(tester, const SizedBox(width: 320, child: GloveTextField(label: 'Note')));
      expectTapHeight(tester, find.byType(TextField));
      expect(find.text('Note'), findsOneWidget);
    });

    testWidgets('PictoChoice tiles', (tester) async {
      await pumpDesign(
        tester,
        SizedBox(
          width: 328,
          child: PictoChoice<String>(
            value: 'Rain',
            onChanged: (_) {},
            options: <PictoOption<String>>[
              for (final p in N360Pictograms.weatherKinds) PictoOption(value: p.key, icon: p.icon, label: p.key),
            ],
          ),
        ),
      );
      for (final p in N360Pictograms.weatherKinds) {
        final Size size = tester.getSize(find.ancestor(of: find.text(p.key), matching: find.byType(Semantics)).first);
        expect(size.height, greaterThanOrEqualTo(N360Size.tap), reason: p.key);
      }
    });

    testWidgets('HajiriSegment', (tester) async {
      await pumpDesign(tester, SizedBox(width: 320, child: HajiriSegment(value: null, onChanged: (_) {})));
      expectTapHeight(tester, find.byType(HajiriSegment));
    });

    testWidgets('ActionTile', (tester) async {
      await pumpDesign(
        tester,
        SizedBox(width: 160, child: ActionTile(icon: Icons.home, label: 'Home', badge: '2', onTap: () {})),
      );
      expectTapHeight(tester, find.byType(ActionTile));
    });

    testWidgets('N360BottomBar is 64dp and every item is tappable', (tester) async {
      var tapped = -1;
      await pumpDesign(
        tester,
        SizedBox(
          width: 360,
          child: N360BottomBar(
            currentIndex: 0,
            onTap: (i) => tapped = i,
            items: const <N360BarItem>[
              N360BarItem(icon: Icons.home, label: 'Home'),
              N360BarItem(icon: Icons.architecture, label: 'Measure'),
            ],
          ),
        ),
      );
      expectTapHeight(tester, find.byType(N360BottomBar), min: N360Size.bar);
      await tester.tap(find.text('Measure'));
      await tester.pump(const Duration(milliseconds: 300));
      expect(tapped, 1);
    });

    testWidgets('SyncPill, OutboxSlip, and a tappable LedgerCard', (tester) async {
      await pumpDesign(tester, SyncPill(state: SyncVisualState.synced, label: 'All sent', onTap: () {}));
      expectTapHeight(tester, find.byType(SyncPill));

      await pumpDesign(
        tester,
        SizedBox(
          width: 320,
          child: OutboxSlip(title: 'Slip', status: SlipStatus.waiting, statusLabel: 'Waiting', onTap: () {}),
        ),
      );
      expectTapHeight(tester, find.byType(OutboxSlip));

      await pumpDesign(tester, LedgerCard(onTap: () {}, semanticsLabel: 'Sheet', child: const Text('Sheet')));
      expectTapHeight(tester, find.byType(LedgerCard));
    });
  });

  group('GloveStepper', () {
    Future<void> pump(WidgetTester tester, ValueNotifier<int> v, {int min = 0, int max = 10}) async {
      await pumpDesign(
        tester,
        SizedBox(
          width: 320,
          child: ValueListenableBuilder<int>(
            valueListenable: v,
            builder: (_, value, _) => GloveStepper(
              value: value,
              onChanged: (next) => v.value = next,
              min: min,
              max: max,
              label: 'Masons',
              icon: Icons.foundation,
            ),
          ),
        ),
      );
    }

    testWidgets('a tap steps once on release', (tester) async {
      final v = ValueNotifier<int>(5);
      addTearDown(v.dispose);
      await pump(tester, v);
      await tester.tap(find.byIcon(Icons.add));
      await tester.pump(const Duration(milliseconds: 50));
      expect(v.value, 6);
      await tester.tap(find.byIcon(Icons.remove));
      await tester.pump(const Duration(milliseconds: 50));
      expect(v.value, 5);
    });

    testWidgets('stops at the bounds', (tester) async {
      final v = ValueNotifier<int>(10);
      addTearDown(v.dispose);
      await pump(tester, v);
      await tester.tap(find.byIcon(Icons.add), warnIfMissed: false);
      await tester.pump(const Duration(milliseconds: 50));
      expect(v.value, 10);

      v.value = 0;
      await tester.pump();
      await tester.tap(find.byIcon(Icons.remove), warnIfMissed: false);
      await tester.pump(const Duration(milliseconds: 50));
      expect(v.value, 0);
    });

    testWidgets('holding repeats with acceleration, and stops at the limit', (tester) async {
      final v = ValueNotifier<int>(0);
      addTearDown(v.dispose);
      await pump(tester, v, max: 30);
      final TestGesture gesture = await tester.startGesture(tester.getCenter(find.byIcon(Icons.add)));
      await tester.pump(const Duration(milliseconds: 400));
      expect(v.value, greaterThanOrEqualTo(1));
      final int after400 = v.value;
      await tester.pump(const Duration(milliseconds: 600));
      await tester.pump(const Duration(milliseconds: 700));
      final int gained = v.value - after400;
      expect(gained, greaterThan(5), reason: 'repeats accelerate');
      for (var i = 0; i < 60; i++) {
        await tester.pump(const Duration(milliseconds: 60));
      }
      expect(v.value, 30);
      await gesture.up();
      await tester.pump(const Duration(milliseconds: 200));
      expect(v.value, 30, reason: 'release after hold adds nothing extra');
    });

    testWidgets('semantics expose the value and the increase action', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      final v = ValueNotifier<int>(5);
      addTearDown(v.dispose);
      await pump(tester, v);
      final SemanticsNode node = tester.getSemantics(find.byType(GloveStepper));
      final SemanticsData data = node.getSemanticsData();
      expect(data.value, '5');
      expect(data.increasedValue, '6');
      expect(data.decreasedValue, '4');
      expect(data.label, 'Masons');
      handle.dispose();
    });
  });

  group('GloveKeypad', () {
    testWidgets('digits, point, backspace, next', (tester) async {
      final v = ValueNotifier<String>('');
      addTearDown(v.dispose);
      var nexts = 0;
      await pumpDesign(
        tester,
        SizedBox(
          width: 328,
          child: ValueListenableBuilder<String>(
            valueListenable: v,
            builder: (_, value, _) => GloveKeypad(
              value: value,
              onChanged: (s) => v.value = s,
              onNext: () => nexts++,
              nextLabel: 'Next',
              decimalPlaces: 3,
            ),
          ),
        ),
      );

      Future<void> press(String key) async {
        await tester.tap(find.text(key).last);
        await tester.pump(const Duration(milliseconds: 40));
      }

      await press('1');
      await press('2');
      await press('.');
      await press('5');
      expect(v.value, '12.5');
      await press('.');
      expect(v.value, '12.5', reason: 'a second point is ignored');
      await press('0');
      await press('0');
      expect(v.value, '12.500');
      await press('7');
      expect(v.value, '12.500', reason: 'no more than three decimals');

      await tester.tap(find.text('Delete'));
      await tester.pump(const Duration(milliseconds: 40));
      expect(v.value, '12.50');

      await tester.tap(find.text('Next'));
      await tester.pump(const Duration(milliseconds: 40));
      expect(nexts, 1);
    });

    testWidgets('long press on delete clears the field', (tester) async {
      final v = ValueNotifier<String>('48.5');
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        SizedBox(
          width: 328,
          child: ValueListenableBuilder<String>(
            valueListenable: v,
            builder: (_, value, _) => GloveKeypad(
              value: value,
              onChanged: (s) => v.value = s,
              onNext: null,
              nextLabel: 'Done',
            ),
          ),
        ),
      );
      await tester.longPress(find.text('Delete'));
      await tester.pump(const Duration(milliseconds: 50));
      expect(v.value, '');
    });

    test('applyKey rules', () {
      String apply(String v, String k, {int dp = 3}) => GloveKeypad.applyKey(v, k, decimalPlaces: dp);
      expect(apply('', '5'), '5');
      expect(apply('0', '5'), '5', reason: 'no leading zero');
      expect(apply('0', '.'), '0.');
      expect(apply('', '.'), '0.', reason: 'starting with a point adds a zero');
      expect(apply('1.2', '.'), '1.2');
      expect(apply('1.23', '4', dp: 2), '1.23', reason: 'money stops at paise');
      expect(apply('7', '.', dp: 0), '7', reason: 'integer-only fields have no point');
      expect(apply('12.5', 'backspace'), '12.');
      expect(apply('', 'backspace'), '');
      expect(GloveKeypad.applyKey('123456789', '0', maxIntegerDigits: 9), '123456789');
    });
  });

  group('HajiriSegment', () {
    testWidgets('one tap per segment sets the value at once', (tester) async {
      final v = ValueNotifier<HajiriValue?>(null);
      addTearDown(v.dispose);
      await pumpDesign(
        tester,
        SizedBox(
          width: 320,
          child: ValueListenableBuilder<HajiriValue?>(
            valueListenable: v,
            builder: (_, value, _) => HajiriSegment(value: value, onChanged: (next) => v.value = next),
          ),
        ),
      );
      final Rect rect = tester.getRect(find.byType(HajiriSegment));
      for (var i = 0; i < 4; i++) {
        await tester.tapAt(Offset(rect.left + rect.width * (i + 0.5) / 4, rect.center.dy));
        await tester.pump();
        expect(v.value, HajiriValue.values[i]);
      }
    });

    testWidgets('semantics name each choice, with the worker if given', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      await pumpDesign(
        tester,
        SizedBox(
          width: 320,
          child: HajiriSegment(value: HajiriValue.present, onChanged: (_) {}, semanticsLabel: 'Ramesh'),
        ),
      );
      final SemanticsNode bar = tester.getSemantics(find.byType(HajiriSegment));
      final labels = <String>[];
      void visit(SemanticsNode node) {
        labels.add(node.getSemanticsData().label);
        node.visitChildren((child) {
          visit(child);
          return true;
        });
      }
      visit(bar);
      expect(labels, containsAll(<String>['Ramesh, Present', 'Ramesh, Half day', 'Ramesh, Overtime', 'Ramesh, Absent']));
      handle.dispose();
    });

    testWidgets('dozens on screen pump cheaply with no exception', (tester) async {
      await pumpDesign(
        tester,
        SizedBox(
          width: 320,
          height: 700,
          child: ListView.builder(
            itemCount: 200,
            itemBuilder: (_, i) => Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: HajiriSegment(value: HajiriValue.values[i % 4], onChanged: (_) {}),
            ),
          ),
        ),
      );
      expect(find.byType(HajiriSegment), findsWidgets);
      expect(tester.takeException(), isNull);
    });
  });

  group('GloveButton', () {
    testWidgets('fires on tap with a haptic-safe press, ignores taps while loading', (tester) async {
      var taps = 0;
      await pumpDesign(tester, GloveButton(label: 'Save', onPressed: () => taps++));
      await tester.tap(find.text('Save'));
      await tester.pump(const Duration(milliseconds: 200));
      expect(taps, 1);

      await pumpDesign(tester, GloveButton(label: 'Save', loading: true, onPressed: () => taps++));
      await tester.tap(find.text('Save'), warnIfMissed: false);
      await tester.pump(const Duration(milliseconds: 200));
      expect(taps, 1);
    });

    testWidgets('has a semantics label on every kind', (tester) async {
      final SemanticsHandle handle = tester.ensureSemantics();
      for (final kind in GloveButtonKind.values) {
        await pumpDesign(tester, GloveButton(label: 'Save', kind: kind, onPressed: () {}));
        expect(find.bySemanticsLabel('Save'), findsOneWidget, reason: '$kind');
      }
      handle.dispose();
    });

    testWidgets('press sinks with a transform, layout does not move', (tester) async {
      await pumpDesign(tester, GloveButton(label: 'Save', onPressed: () {}));
      final Rect before = tester.getRect(find.byType(GloveButton));
      final TestGesture gesture = await tester.startGesture(tester.getCenter(find.text('Save')));
      await tester.pump(const Duration(milliseconds: 100));
      expect(tester.getRect(find.byType(GloveButton)), before);
      await gesture.up();
      await tester.pump(const Duration(milliseconds: 200));
    });
  });

  group('text survives scale and Devanagari', () {
    testWidgets('PictoChoice never breaks a word at 1.3 scale', (tester) async {
      await pumpDesign(
        tester,
        SizedBox(
          width: 328,
          child: PictoChoice<String>(
            value: null,
            columns: 4,
            onChanged: (_) {},
            options: const <PictoOption<String>>[
              PictoOption(value: 'a', icon: Icons.electrical_services, label: 'इलेक्ट्रिशियन'),
              PictoOption(value: 'b', icon: Icons.engineering, label: 'सुपरवायझर'),
            ],
          ),
        ),
        textScale: 1.3,
      );
      expect(tester.takeException(), isNull);
    });
  });
}
