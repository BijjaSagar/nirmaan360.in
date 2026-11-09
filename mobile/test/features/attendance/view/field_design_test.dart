// The restrained design as it shows on these screens: one white list per group
// with hairline rows, status as a pill, filters as chips, selected tiles in the
// primary tint, and a main action that sits on the page with no panel behind it.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/attendance/view/widgets/field_kit.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';

import '../../../core/design/test_helpers.dart' show loadRealFonts;
import 'field_test_support.dart';

/// The painted height of the first chip, apart from the target around it.
double _chipFaceHeight(WidgetTester tester) {
  final Finder face = find
      .descendant(
        of: find.byType(N360Chip).first,
        matching: find.byType(CustomPaint),
      )
      .first;
  return tester.getSize(face).height;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
  });

  group('muster', () {
    testWidgets('is one LedgerList per trade, with a hairline between rows', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.api.addWorker2(id: 5, name: 'Anil Gaikwad', trade: 'Helper');
      await app.sync();

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(
        find.byType(LedgerList),
        findsNWidgets(2),
        reason: 'Mason, Helper',
      );
      expect(find.byType(LedgerCard), findsWidgets);
      expect(
        find.descendant(
          of: find.byType(LedgerList).first,
          matching: find.byType(RuledDivider),
        ),
        findsWidgets,
      );
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('picks the day with chips, one line each, Today chosen', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(find.byType(N360Chip, skipOffstage: false), findsNWidgets(7));
      expect(
        tester.widget<N360Chip>(find.byType(N360Chip).last).selected,
        isTrue,
        reason: 'today sits at the right, under the thumb',
      );
      expect(_chipFaceHeight(tester), lessThanOrEqualTo(N360Size.control + 1));
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('puts the main action on the page, in no panel', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(find.byType(FieldActionBar), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(FieldActionBar),
          matching: find.byType(LedgerCard),
        ),
        findsNothing,
      );
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('keeps one tap per worker, and a tap rebuilds only that row', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      final Rect row = tester.getRect(find.byType(HajiriSegment).first);
      await tester.tapAt(Offset(row.left + row.width / 8, row.center.dy));
      await pumpDrift(tester);

      final List<HajiriSegment> segments = tester
          .widgetList<HajiriSegment>(find.byType(HajiriSegment))
          .toList();
      expect(segments.first.value, HajiriValue.present);
      expect(segments.last.value, isNull, reason: 'the other row did not move');
      await tester.pump(const Duration(seconds: 1));
    });
  });

  group('wallet', () {
    Future<TestApp> seeded() async {
      final app = await TestApp.create();
      final cash = app.services.pettyCash;
      await cash.addExpense(
        walletId: 1,
        amount: Money.parse('850.50'),
        entryDate: '2026-11-09',
        category: 'Food',
        description: 'Tea and lunch for crew',
      );
      await cash.addExpense(
        walletId: 1,
        amount: Money.parse('1200.00'),
        entryDate: '2026-11-08',
        category: 'Tools',
      );
      await cash.addExpense(
        walletId: 1,
        amount: Money.parse('300.00'),
        entryDate: '2026-11-07',
        category: 'Travel',
      );
      return app;
    }

    testWidgets('lists entries as one LedgerList per day, newest first', (
      tester,
    ) async {
      setPhone(tester);
      final app = await seeded();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
      await pumpDrift(tester);

      expect(find.text('Today · 9 Nov'), findsOneWidget);
      expect(find.text('Yesterday · 8 Nov'), findsOneWidget);
      expect(find.byType(LedgerListTile), findsWidgets);
      expect(find.text('Tea and lunch for crew'), findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('says where an unsent entry is with a pill, not a card', (
      tester,
    ) async {
      setPhone(tester);
      final app = await seeded();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
      await pumpDrift(tester);

      // Every entry is still on this phone, and the balance says so too.
      expect(find.byType(StatusPill), findsWidgets);
      expect(find.bySemanticsLabel('Provisional'), findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('switches wallet with chips', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      app.api.addWallet(id: 2, projectId: 1, balance: '4200.00', userId: 4);
      await app.sync();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const PettyCashScreen()));
      await pumpDrift(tester);

      expect(find.byType(N360Chip), findsNWidgets(2));
      expect(_chipFaceHeight(tester), lessThanOrEqualTo(N360Size.control + 1));
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('a chosen date tile is the primary tint with a primary edge', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(
        fieldApp(app.services, const ExpenseFormScreen()),
      );
      await pumpDrift(tester);
      tester.widget<GloveKeypad>(find.byType(GloveKeypad)).onChanged('450');
      await pumpDrift(tester, 30);
      await tester.pump(const Duration(milliseconds: 200));
      tester.widget<GloveKeypad>(find.byType(GloveKeypad)).onNext!();
      await pumpDrift(tester);

      final Finder today = find.ancestor(
        of: find.text('Today'),
        matching: find.byType(FieldChoiceTile),
      );
      final BoxDecoration face =
          tester
                  .widget<DecoratedBox>(
                    find
                        .descendant(
                          of: today,
                          matching: find.byType(DecoratedBox),
                        )
                        .first,
                  )
                  .decoration
              as BoxDecoration;
      expect(face.color, N360Colors.primaryTint);
      expect((face.border! as Border).top.color, N360Colors.primary);
      await tester.pump(const Duration(seconds: 1));
    });
  });
}
