import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

double _contrast(Color a, Color b) {
  final double la = a.computeLuminance();
  final double lb = b.computeLuminance();
  final double light = la > lb ? la : lb;
  final double dark = la > lb ? lb : la;
  return (light + 0.05) / (dark + 0.05);
}

void main() {
  group('outdoor contrast', () {
    const backgrounds = <Color>[N360Colors.paper, N360Colors.sheet, N360Colors.paper2, N360Colors.peach];

    test('body text reaches 7:1 on every page surface, the peach foot included', () {
      for (final bg in backgrounds) {
        expect(_contrast(N360Colors.ink, bg), greaterThanOrEqualTo(7), reason: 'ink on $bg');
      }
      expect(N360Type.body.color, N360Colors.ink);
    });

    test('labels reach 4.5:1 on every page surface', () {
      for (final bg in backgrounds) {
        for (final ink in <Color>[
          N360Colors.ink2,
          N360Colors.inkLabel,
          N360Colors.stampRed,
          N360Colors.haldiInk,
          N360Colors.mossInk,
          N360Colors.neel,
          N360Colors.laterite,
        ]) {
          expect(_contrast(ink, bg), greaterThanOrEqualTo(4.5), reason: '$ink on $bg');
        }
      }
    });

    test('ink3 is decoration only: it fails label contrast on paper', () {
      expect(_contrast(N360Colors.ink3, N360Colors.paper), lessThan(4.5));
    });

    test('every stamp ink holds 4.5:1 on every page surface', () {
      for (final kind in StampKind.values) {
        for (final bg in backgrounds) {
          expect(_contrast(kind.inkColor, bg), greaterThanOrEqualTo(4.5), reason: '$kind on $bg');
        }
      }
    });

    test('text on gold is dark ink, and it passes across the whole gradient', () {
      for (final gold in <Color>[N360Colors.amber, N360Colors.haldi, N360Colors.orange]) {
        expect(_contrast(N360Colors.ink, gold), greaterThanOrEqualTo(4.5), reason: '$gold');
      }
      expect(N360Gradients.gold.colors, containsAll(<Color>[N360Colors.amber, N360Colors.orange]));
    });

    test('white on orange fails outdoors, which is why gold carries dark ink', () {
      expect(_contrast(N360Colors.sheet, N360Colors.orange), lessThan(4.5));
    });

    test('white text on the filled status colours reaches 4.5:1', () {
      for (final fill in <Color>[N360Colors.laterite, N360Colors.moss, N360Colors.neel, N360Colors.stampRed]) {
        expect(_contrast(N360Colors.sheet, fill), greaterThanOrEqualTo(4.5), reason: '$fill');
      }
    });
  });

  group('typography', () {
    test('headings use Poppins, body uses Mukta, and Khand is gone', () {
      for (final style in <TextStyle>[
        N360Type.displayLarge,
        N360Type.display,
        N360Type.title,
        N360Type.heading,
        N360Type.button,
        N360Type.figureLarge,
        N360Type.figureHero,
      ]) {
        expect(style.fontFamily, 'Poppins');
      }
      expect(N360Type.body.fontFamily, 'Mukta');
      expect(N360Type.figure.fontFamily, 'DMMono');
    });

    test('Devanagari line heights are generous', () {
      for (final style in <TextStyle>[
        N360Type.bodyLarge,
        N360Type.body,
        N360Type.label,
        N360Type.caption,
      ]) {
        expect(style.height, greaterThanOrEqualTo(1.35));
      }
      for (final style in <TextStyle>[
        N360Type.displayLarge,
        N360Type.display,
        N360Type.title,
        N360Type.heading,
        N360Type.button,
      ]) {
        expect(style.height, greaterThanOrEqualTo(1.3));
      }
    });

    test('column figures fall back to Mukta for the rupee sign and are tabular', () {
      expect(N360Type.figure.fontFamilyFallback, contains('Mukta'));
      expect(N360Type.figure.fontFeatures, contains(const FontFeature.tabularFigures()));
    });

    test('section labels are calm: no tracking, no capitals', () {
      expect(N360Type.eyebrowFor('Item 2.1').letterSpacing ?? 0, 0);
      expect(N360Type.eyebrowFor('मोजमाप').letterSpacing ?? 0, 0);
      expect(N360Type.eyebrowText('Item 2.1'), 'Item 2.1');
    });

    test('stamps stay in capitals for Latin and leave Devanagari alone', () {
      expect(N360Type.stampText('Approved'), 'APPROVED');
      expect(N360Type.stampText('मंजूर'), 'मंजूर');
    });

    test('the scale sits at the reference proportion and nothing is under 12', () {
      expect(N360Type.body.fontSize, 14);
      expect(N360Type.label.fontSize, 13);
      expect(N360Type.caption.fontSize, 12);
      expect(N360Type.heading.fontSize, 15);
      expect(N360Type.title.fontSize, inInclusiveRange(17, 18));
      expect(N360Type.button.fontSize, 15);
      expect(N360Type.figureHero.fontSize, inInclusiveRange(30, 36));
      expect(N360Type.figureLarge.fontSize, inInclusiveRange(20, 24));
      for (final style in <TextStyle>[
        N360Type.displayLarge,
        N360Type.display,
        N360Type.title,
        N360Type.heading,
        N360Type.button,
        N360Type.bodyLarge,
        N360Type.body,
        N360Type.bodyStrong,
        N360Type.label,
        N360Type.caption,
        N360Type.figureSmall,
        N360Type.figure,
        N360Type.figureLarge,
        N360Type.figureHero,
        N360Type.eyebrowFor('Item'),
        N360Type.eyebrowFor('बाब'),
      ]) {
        expect(style.fontSize, greaterThanOrEqualTo(N360Type.minimumSize), reason: '$style');
      }
      for (final size in StampSize.values) {
        expect(size.fontSize, greaterThanOrEqualTo(N360Type.minimumSize), reason: '$size');
      }
    });

    test('Devanagari gets one more point at small sizes and a 1.4 line; Latin is untouched', () {
      expect(N360Type.fit(N360Type.caption, 'Today').fontSize, 12);
      expect(N360Type.fit(N360Type.caption, 'आज').fontSize, 13);
      expect(N360Type.fit(N360Type.body, 'आज').fontSize, 15);
      expect(N360Type.fit(N360Type.heading, 'आज').fontSize, 16);
      expect(N360Type.fit(N360Type.heading, 'आज').height, greaterThanOrEqualTo(1.4));
      expect(N360Type.fit(N360Type.title, 'आज').fontSize, 18, reason: 'larger sizes are left alone');
      expect(N360Type.fit(N360Type.figureHero, '70.50%'), N360Type.figureHero);
      expect(N360Type.forDevanagari(N360Type.label).fontSize, 14);
    });

    test('icons are quiet: 14 to 24, with 18 to 22 for everything on a screen', () {
      expect(N360IconSize.xs, 14);
      expect(N360IconSize.md, 18);
      expect(N360IconSize.tile, inInclusiveRange(18, 22));
      expect(N360IconSize.button, inInclusiveRange(18, 22));
      expect(N360IconSize.bar, inInclusiveRange(18, 22));
      expect(N360IconSize.xxl, lessThanOrEqualTo(24));
    });

    test('visible sizes are separate from hit areas, and the hit area never drops under 56', () {
      expect(N360Size.tap, 56);
      expect(N360Size.tile, inInclusiveRange(40, 46));
      expect(N360Size.tile, lessThan(N360Size.tap));
      expect(N360Size.barTile, lessThan(N360Size.bar));
      expect(N360Size.bar, greaterThanOrEqualTo(N360Size.tap));
      expect(N360Size.key, greaterThanOrEqualTo(N360Size.tap));
      expect(N360Space.card, inInclusiveRange(14, 16));
    });

    testWidgets('N360TextScaling clamps the system text size at the root', (tester) async {
      late TextScaler seen;
      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(textScaler: TextScaler.linear(2)),
          child: N360TextScaling(
            child: Builder(
              builder: (context) {
                seen = MediaQuery.textScalerOf(context);
                return const SizedBox.shrink();
              },
            ),
          ),
        ),
      );
      expect(seen.scale(10), closeTo(10 * N360TextScale.max, 0.001));
    });

    test('the theme can be fitted for Devanagari', () {
      final ThemeData en = buildN360Theme();
      final ThemeData mr = buildN360Theme(devanagari: true);
      expect(en.textTheme.bodyMedium!.fontSize, 14);
      expect(mr.textTheme.bodyMedium!.fontSize, 15);
      expect(mr.textTheme.bodySmall!.fontSize, 13);
      expect(mr.textTheme.titleLarge!.fontSize, 18);
      expect(en.iconTheme.size, N360IconSize.lg);
    });
  });

  group('motion', () {
    testWidgets('context.motion resolves to zero with reduced motion', (tester) async {
      late N360Motion normal;
      late N360Motion reduced;
      await tester.pumpWidget(
        Directionality(
          textDirection: TextDirection.ltr,
          child: MediaQuery(
            data: const MediaQueryData(),
            child: Builder(
              builder: (context) {
                normal = context.motion;
                return MediaQuery(
                  data: const MediaQueryData(disableAnimations: true),
                  child: Builder(
                    builder: (context) {
                      reduced = context.motion;
                      return const SizedBox.shrink();
                    },
                  ),
                );
              },
            ),
          ),
        ),
      );
      expect(normal.reduced, isFalse);
      expect(normal.stamp, N360Durations.stamp);
      expect(reduced.reduced, isTrue);
      expect(reduced.stamp, Duration.zero);
      expect(reduced.rise, Duration.zero);
      expect(reduced.roll, Duration.zero);
    });

    test('stamp curve overshoots and lands', () {
      expect(N360Curves.stamp.transform(0.5), greaterThan(1));
      expect(N360Curves.stamp.transform(1), 1);
      expect(N360Curves.rise.transform(1), 1);
    });

    test('settle overshoots only a little and exits ease in', () {
      var peak = 0.0;
      for (var i = 0; i <= 100; i++) {
        peak = peak > N360Curves.settle.transform(i / 100) ? peak : N360Curves.settle.transform(i / 100);
      }
      expect(peak, greaterThan(1));
      expect(peak, lessThan(1.06));
      expect(N360Curves.settle.transform(1), 1);
      expect(N360Curves.exit.transform(0.5), lessThan(0.5));
    });

    test('the entry choreography is over within about a second, and the thud outlasts every other moment', () {
      for (final d in <Duration>[
        N360Durations.page,
        N360Durations.rise + N360Durations.riseStep * 10,
        N360Durations.fill,
        N360Durations.count,
      ]) {
        expect(d, lessThanOrEqualTo(const Duration(milliseconds: 800)));
      }
      expect(N360Durations.build, lessThanOrEqualTo(const Duration(milliseconds: 1200)));
      for (final moment in <Duration>[N360Durations.stamp, N360Durations.tick, N360Durations.toast, N360Durations.sheet]) {
        expect(N360Durations.thud, greaterThan(moment));
      }
      expect(N360Durations.press, lessThan(N360Durations.release));
    });

    testWidgets('every new duration resolves to zero under reduced motion', (tester) async {
      late N360Motion reduced;
      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: Builder(
            builder: (context) {
              reduced = context.motion;
              return const SizedBox.shrink();
            },
          ),
        ),
      );
      for (final d in <Duration>[reduced.fill, reduced.count, reduced.build, reduced.sheet, reduced.bar]) {
        expect(d, Duration.zero);
      }
      expect(pressedScale(0), 1);
      expect(pressedScale(1), lessThan(1));
    });
  });

  group('pictograms', () {
    test('known values get their icon, case and underscores ignored', () {
      expect(N360Pictograms.trade('Mason').icon, Icons.foundation);
      expect(N360Pictograms.trade('bar bender').key, 'Bar bender');
      expect(N360Pictograms.issueStatus('in_progress').key, 'in_progress');
      expect(N360Pictograms.expense('fuel').icon, Icons.local_gas_station);
      expect(N360Pictograms.weather('Rain').icon, Icons.umbrella);
      expect(N360Pictograms.attendance('OVERTIME').key, 'overtime');
      expect(N360Pictograms.priority('critical').icon, Icons.priority_high);
    });

    test('an unknown server value keeps its text and gets the fallback icon', () {
      final p = N360Pictograms.trade('Glazier');
      expect(p.key, 'Glazier');
      expect(p.icon, N360Pictograms.fallback);
    });

    test('every vocabulary is covered', () {
      expect(N360Pictograms.trades, hasLength(10));
      expect(N360Pictograms.expenseCategories, hasLength(8));
      expect(N360Pictograms.weatherKinds, hasLength(5));
      expect(N360Pictograms.issuePriorities, hasLength(4));
      expect(N360Pictograms.issueStatuses, hasLength(4));
      expect(N360Pictograms.attendanceStatuses, hasLength(4));
    });

    test('labels translate, and unknown values stay readable', () {
      final AppLocalizations mr = lookupAppLocalizations(const Locale('mr'));
      expect(mr.pictogramLabel(N360Pictograms.trade('Mason')), 'गवंडी');
      expect(mr.pictogramLabel(N360Pictograms.issueStatus('in_progress')), 'सुरू आहे');
      expect(mr.pictogramLabel(N360Pictograms.trade('Glazier')), 'Glazier');
    });
  });

  group('theme', () {
    test('has no surface tint and a paper scaffold', () {
      final ThemeData theme = buildN360Theme();
      expect(theme.scaffoldBackgroundColor, N360Colors.paper);
      expect(theme.colorScheme.surfaceTint.a, 0);
      expect(theme.appBarTheme.scrolledUnderElevation, 0);
      expect(theme.cardTheme.elevation, 0);
      expect(theme.splashColor, N360Colors.ink.withValues(alpha: 0.08));
    });

    test('the primary is gold with dark ink on it', () {
      final ThemeData theme = buildN360Theme();
      expect(theme.colorScheme.primary, N360Colors.haldi);
      expect(theme.colorScheme.onPrimary, N360Colors.ink);
    });
  });
}
