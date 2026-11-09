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
    const backgrounds = <Color>[N360Colors.paper, N360Colors.sheet, N360Colors.paper2];

    test('body text reaches 7:1 on paper and sheet', () {
      for (final bg in <Color>[N360Colors.paper, N360Colors.sheet]) {
        expect(_contrast(N360Colors.ink, bg), greaterThanOrEqualTo(7));
        expect(_contrast(N360Colors.ink2, bg), greaterThanOrEqualTo(7));
      }
    });

    test('labels reach 4.5:1 on every paper surface', () {
      for (final bg in backgrounds) {
        expect(_contrast(N360Colors.inkLabel, bg), greaterThanOrEqualTo(4.5));
        expect(_contrast(N360Colors.stampRed, bg), greaterThanOrEqualTo(4.5));
        expect(_contrast(N360Colors.haldiInk, bg), greaterThanOrEqualTo(4.5));
        expect(_contrast(N360Colors.mossInk, bg), greaterThanOrEqualTo(4.5));
        expect(_contrast(N360Colors.neel, bg), greaterThanOrEqualTo(4.5));
      }
    });

    test('ink3 is decoration only: it fails label contrast on paper', () {
      expect(_contrast(N360Colors.ink3, N360Colors.paper), lessThan(4.5));
    });

    test('every stamp ink holds 4.5:1 on every paper surface', () {
      for (final kind in StampKind.values) {
        for (final bg in backgrounds) {
          expect(
            _contrast(kind.inkColor, bg),
            greaterThanOrEqualTo(4.5),
            reason: '$kind on $bg',
          );
        }
      }
    });

    test('text on filled controls reaches 4.5:1', () {
      expect(_contrast(N360Colors.sheet, N360Colors.laterite), greaterThanOrEqualTo(4.5));
      expect(_contrast(N360Colors.ink, N360Colors.haldi), greaterThanOrEqualTo(4.5));
      expect(_contrast(N360Colors.sheet, N360Colors.moss), greaterThanOrEqualTo(4.5));
      expect(_contrast(N360Colors.sheet, N360Colors.neel), greaterThanOrEqualTo(4.5));
      expect(_contrast(N360Colors.sheet, N360Colors.stampRed), greaterThanOrEqualTo(4.5));
    });
  });

  group('typography', () {
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
        expect(style.height, greaterThanOrEqualTo(1.25));
      }
    });

    test('figures fall back to Mukta for the rupee sign and are tabular', () {
      expect(N360Type.figure.fontFamilyFallback, contains('Mukta'));
      expect(N360Type.figure.fontFeatures, contains(const FontFeature.tabularFigures()));
    });

    test('eyebrow sets Devanagari without tracking or uppercasing', () {
      expect(N360Type.eyebrowFor('Item 2.1').letterSpacing, greaterThan(0));
      expect(N360Type.eyebrowFor('मोजमाप').letterSpacing ?? 0, 0);
      expect(N360Type.eyebrowText('item'), 'ITEM');
      expect(N360Type.eyebrowText('मोजमाप'), 'मोजमाप');
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

    test('never uses a blue primary', () {
      final ThemeData theme = buildN360Theme();
      expect(theme.colorScheme.primary, N360Colors.laterite);
    });
  });
}
