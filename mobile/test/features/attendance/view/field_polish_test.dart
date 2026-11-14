// How the muster and the wallet carry themselves: a skeleton while loading,
// one counting figure per screen, targets that never drop under 56dp, and a
// hint only where it can be misread.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/attendance/view/widgets/muster_summary_card.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/widgets/petty_widgets.dart';

import '../../../core/design/test_helpers.dart' show loadRealFonts;
import 'field_test_support.dart';

/// The one figure that counts up once, found by what it is rather than where.
final Finder _countingFigure = find.byWidgetPredicate(
  (widget) => widget is RollingNumber && widget.animateEntry,
  skipOffstage: false,
);

Future<TestApp> _bareProject() async {
  final app = await TestApp.create(seed: false);
  app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  await app.sync();
  return app;
}

/// Every pressable thing on screen must be at least 56dp on both sides.
void _expectEveryTargetIsGloveSized(WidgetTester tester) {
  final List<Element> targets = find.byType(PressTarget).evaluate().toList();
  expect(targets, isNotEmpty);
  for (final element in targets) {
    final Size size = (element.renderObject! as RenderBox).size;
    expect(
      size.height,
      greaterThanOrEqualTo(N360Size.tap),
      reason: 'height of ${element.widget}',
    );
    expect(
      size.width,
      greaterThanOrEqualTo(N360Size.tap),
      reason: 'width of ${element.widget}',
    );
  }
}

void main() {
  // Layout at the largest text size is only true with the real fonts.
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
  });

  group('muster', () {
    testWidgets('loads behind a skeleton, never a spinner', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      expect(find.byType(LedgerSkeleton), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(
        find.byType(MusterSummaryCard),
        findsNothing,
        reason: 'a card of zeros says nothing',
      );

      await pumpDrift(tester);
      expect(find.byType(LedgerSkeleton), findsNothing);
      expect(find.byType(MusterSummaryCard), findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('has no summary card and no day strip without a muster', (
      tester,
    ) async {
      setPhone(tester);
      final app = await _bareProject();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(find.text('No workers on the muster'), findsOneWidget);
      expect(find.byType(MusterSummaryCard), findsNothing);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets(
      'keeps the add button out of the way until the muster is ready',
      (tester) async {
        setPhone(tester);
        final app = await TestApp.create();
        addTearDown(app.dispose);

        await tester.pumpWidget(
          fieldApp(app.services, const AttendanceScreen()),
        );
        expect(
          find.text('Add worker'),
          findsNothing,
          reason: 'nothing to press while loading',
        );
        await pumpDrift(tester);
        expect(find.text('Add worker'), findsOneWidget);
        await tester.pump(const Duration(seconds: 1));
      },
    );

    testWidgets('the marked count is the one figure that counts up', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(_countingFigure, findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets(
      'the count does not start again when the summary folds and returns',
      (tester) async {
        setPhone(tester);
        final app = await TestApp.create(
          api: FakeApi()..addWorker2(id: 10, name: 'Anil Gaikwad'),
        );
        for (var i = 0; i < 10; i++) {
          app.api.addWorker2(id: 20 + i, name: 'Worker $i');
        }
        await app.sync();
        addTearDown(app.dispose);

        await tester.pumpWidget(
          fieldApp(app.services, const AttendanceScreen()),
        );
        await pumpDrift(tester);
        final State<StatefulWidget> before = tester.state(_countingFigure);

        await tester.drag(find.byType(CustomScrollView), const Offset(0, -900));
        await tester.pump(const Duration(milliseconds: 400));
        await tester.drag(find.byType(CustomScrollView), const Offset(0, 1500));
        await tester.pump(const Duration(milliseconds: 400));

        expect(
          tester.state(_countingFigure),
          same(before),
          reason: 'same figure, so no replay',
        );
        await tester.pump(const Duration(seconds: 1));
      },
    );

    testWidgets(
      'with motion on, the count rises and the screen still settles',
      (tester) async {
        setPhone(tester);
        final app = await TestApp.create();
        addTearDown(app.dispose);

        await tester.pumpWidget(
          fieldApp(
            app.services,
            const AttendanceScreen(),
            disableAnimations: false,
          ),
        );
        await pumpDrift(tester);
        await tester.pump(const Duration(milliseconds: 100));
        expect(_countingFigure, findsOneWidget);
        await tester.pump(const Duration(seconds: 2));
        expect(tester.takeException(), isNull);
        expect(
          find.text('Ramesh Pawar'),
          findsOneWidget,
          reason: 'text is never hidden by the entrance',
        );
      },
    );

    testWidgets('names the way out when no project is chosen', (tester) async {
      setPhone(tester);
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(find.text('No project chosen'), findsOneWidget);
      expect(
        find.text('Choose a project'),
        findsOneWidget,
        reason: 'not a dead end',
      );
      expect(
        find.text('Add worker'),
        findsNothing,
        reason: 'nobody to add a worker to',
      );
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('explains All present only once some workers are marked', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);
      expect(find.text('Skips anyone already marked.'), findsNothing);

      final Rect row = tester.getRect(find.byType(HajiriSegment).first);
      await tester.tapAt(Offset(row.left + row.width / 8, row.center.dy));
      await pumpDrift(tester);
      expect(find.text('Skips anyone already marked.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('every target is at least 56dp', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);
      await tester.tap(find.byType(HajiriSegment).first);
      await pumpDrift(tester);

      _expectEveryTargetIsGloveSized(tester);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('fits every text size in English and Marathi, folded or not', (
      tester,
    ) async {
      setPhone(tester, height: 640);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      for (final locale in const <Locale>[
        Locale('en'),
        Locale('mr'),
        Locale('hi'),
      ]) {
        for (final scale in const <double>[0.9, 1, 1.15, 1.3]) {
          await tester.pumpWidget(
            fieldApp(
              app.services,
              const AttendanceScreen(),
              locale: locale,
              textScale: scale,
            ),
          );
          await pumpDrift(tester);
          await tester.pump(const Duration(milliseconds: 400));
          expect(
            tester.takeException(),
            isNull,
            reason: '${locale.languageCode} x$scale',
          );

          await tester.drag(
            find.byType(CustomScrollView),
            const Offset(0, -400),
          );
          await tester.pump(const Duration(milliseconds: 400));
          expect(
            tester.takeException(),
            isNull,
            reason: '${locale.languageCode} x$scale folded',
          );
        }
      }
      await tester.pump(const Duration(seconds: 1));
    });
  });

  group('petty cash', () {
    /// Types an amount on the keypad and presses its Next key.
    Future<void> next(WidgetTester tester, String amount) async {
      tester.widget<GloveKeypad>(find.byType(GloveKeypad)).onChanged(amount);
      await pumpDrift(tester, 30);
      await tester.pump(const Duration(milliseconds: 200));
      tester.widget<GloveKeypad>(find.byType(GloveKeypad)).onNext!();
      await pumpDrift(tester);
      await tester.pump(const Duration(milliseconds: 300));
      expect(
        find.byType(PettyAmountSummary),
        findsOneWidget,
        reason: 'now on the second step',
      );
    }

    testWidgets(
      'every screen and step fits every text size in three languages',
      (tester) async {
        setPhone(tester, height: 640);
        final app = await TestApp.create();
        app.api.addWallet(id: 2, projectId: 1, balance: '4200.00', userId: 4);
        app.api.users.add(<String, dynamic>{
          'id': 4,
          'name': 'Sachin More',
          'role': 'engineer',
        });
        await app.sync();
        addTearDown(app.dispose);

        final Map<String, Future<void> Function(WidgetTester)> steps =
            <String, Future<void> Function(WidgetTester)>{
              'wallet': (_) async {},
              'expense amount': (_) async {},
              'expense details': (t) => next(t, '450'),
              'top-up amount': (_) async {},
              'top-up details': (t) => next(t, '450'),
              'wallet form details': (_) async {},
              'wallet form amount': (t) async {
                tester
                    .widget<GloveButton>(find.byType(GloveButton).last)
                    .onPressed!();
                await pumpDrift(t);
                expect(
                  find.byType(GloveKeypad),
                  findsOneWidget,
                  reason: 'now on the amount step',
                );
              },
            };
        final Map<String, Widget> screens = <String, Widget>{
          'wallet': const PettyCashScreen(),
          'expense amount': const ExpenseFormScreen(),
          'expense details': const ExpenseFormScreen(),
          'top-up amount': const TopUpScreen(),
          'top-up details': const TopUpScreen(),
          'wallet form details': const WalletFormScreen(),
          'wallet form amount': const WalletFormScreen(),
        };

        for (final locale in const <Locale>[
          Locale('en'),
          Locale('mr'),
          Locale('hi'),
        ]) {
          for (final scale in const <double>[0.9, 1, 1.3]) {
            for (final entry in screens.entries) {
              await tester.pumpWidget(
                fieldApp(
                  app.services,
                  entry.value,
                  locale: locale,
                  textScale: scale,
                ),
              );
              await pumpDrift(tester);
              await tester.pump(const Duration(milliseconds: 400));
              await steps[entry.key]!(tester);
              expect(
                tester.takeException(),
                isNull,
                reason: '${entry.key} ${locale.languageCode} x$scale',
              );
              await tester.pumpWidget(const SizedBox.shrink());
              await tester.pump();
            }
          }
        }
        await tester.pump(const Duration(seconds: 1));
      },
    );
  });

  group('wallet', () {
    testWidgets('loads behind a skeleton, never a spinner', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
      expect(find.byType(LedgerSkeleton), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);

      await pumpDrift(tester);
      expect(find.byType(LedgerSkeleton), findsNothing);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('the balance is the one figure that counts up', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
      await pumpDrift(tester);

      expect(_countingFigure, findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('every target is at least 56dp', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
      await pumpDrift(tester);

      _expectEveryTargetIsGloveSized(tester);
      await tester.pump(const Duration(seconds: 1));
    });
  });
}
