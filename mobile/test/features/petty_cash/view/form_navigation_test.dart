// The Android back gesture and the end of a save, on the three forms that have
// a second step. Back on the second step goes back one step; a save leaves.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';

import '../../attendance/view/field_test_support.dart';

/// A screen to come back to, with one button that opens [target].
class _Home extends StatelessWidget {
  const _Home(this.target);

  final String target;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: TextButton(
          onPressed: () => context.push(target),
          child: const Text('open'),
        ),
      ),
    );
  }
}

Widget _app(TestApp app, String path, Widget screen) {
  return fieldRouterApp(
    app.services,
    initial: '/',
    routes: <String, Widget Function(GoRouterState)>{
      '/': (_) => _Home(path),
      path: (_) => screen,
    },
  );
}

Future<void> _open(WidgetTester tester) async {
  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
  await pumpDrift(tester);
}

Future<void> _type(WidgetTester tester, List<String> keys) async {
  for (final key in keys) {
    await tester.tap(find.text(key).first);
    await tester.pump();
  }
}

/// Presses the system back button, as the Android gesture does.
Future<void> _systemBack(WidgetTester tester) async {
  await tester.binding.handlePopRoute();
  await tester.pumpAndSettle();
}

void main() {
  group('expense form', () {
    testWidgets('back on the second step returns to the amount, then leaves', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await tester.pumpWidget(_app(app, '/expense', const ExpenseFormScreen()));
      await _open(tester);

      await _type(tester, <String>['4', '5', '0']);
      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      expect(find.text('What was it for?'), findsOneWidget);

      await _systemBack(tester);
      expect(
        find.text('What was it for?'),
        findsNothing,
        reason: 'one step back',
      );
      expect(
        find.text('Add an expense'),
        findsOneWidget,
        reason: 'still on the form',
      );
      expect(find.text('open'), findsNothing);

      await _systemBack(tester);
      expect(
        find.text('open'),
        findsOneWidget,
        reason: 'back on the first step leaves',
      );
    });

    testWidgets('saving leaves the form', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await tester.pumpWidget(_app(app, '/expense', const ExpenseFormScreen()));
      await _open(tester);

      await _type(tester, <String>['4', '5', '0']);
      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      await tester.tap(find.text('Fuel'));
      await tester.pump();
      await tester.tap(find.text('Save expense'));
      await pumpDrift(tester);
      await tester.pumpAndSettle();

      expect(
        find.text('open'),
        findsOneWidget,
        reason: 'the form is gone after a save',
      );
      expect(await app.db.select(app.db.pettyCashEntries).get(), hasLength(1));
      await settleToasts(tester);
    });

    testWidgets(
      'a photographed bill shows as a picture with a way to retake it',
      (tester) async {
        setPhone(tester);
        final app = await TestApp.create();
        addTearDown(app.dispose);
        await tester.pumpWidget(
          fieldApp(
            app.services,
            ExpenseFormScreen(captureBill: () async => '/no/such/bill.jpg'),
          ),
        );
        await pumpDrift(tester);

        await _type(tester, <String>['4', '5', '0']);
        await tester.tap(find.text('Next'));
        await pumpDrift(tester);
        await tester.tap(find.text('Photo of bill'));
        await pumpDrift(tester);

        expect(find.text('Bill photo added'), findsOneWidget);
        expect(find.text('Take again'), findsOneWidget);
        expect(find.text('Photo of bill'), findsNothing);
      },
    );

    testWidgets('the amount can be changed from the second step, and says so', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await tester.pumpWidget(
        fieldApp(app.services, const ExpenseFormScreen()),
      );
      await pumpDrift(tester);

      await _type(tester, <String>['4', '5', '0']);
      await tester.tap(find.text('Next'));
      await pumpDrift(tester);

      expect(find.text('Tap to change'), findsOneWidget);
      await tester.tap(find.text('Tap to change'));
      await pumpDrift(tester);
      expect(find.text('What was it for?'), findsNothing);
      await tester.pump(const Duration(seconds: 1));
    });

    testWidgets('a failed save is said beside the Save button', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await tester.pumpWidget(
        fieldApp(app.services, const ExpenseFormScreen()),
      );
      await pumpDrift(tester);

      await _type(tester, <String>['4', '5', '0']);
      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      // No category chosen: the problem is named under the category, where
      // the eye already is, and the form stays put.
      await tester.tap(find.text('Save expense'));
      await pumpDrift(tester);
      expect(find.text('Choose what it was for.'), findsOneWidget);
      expect(find.text('Save expense'), findsOneWidget);
      await tester.pump(const Duration(seconds: 1));
    });
  });

  group('top-up form', () {
    testWidgets('back on the second step returns to the amount', (
      tester,
    ) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await tester.pumpWidget(_app(app, '/top-up', const TopUpScreen()));
      await _open(tester);

      await _type(tester, <String>['5', '0']);
      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      expect(find.text('Save money received'), findsOneWidget);

      await _systemBack(tester);
      expect(find.text('Save money received'), findsNothing);
      expect(find.text('open'), findsNothing);
    });

    testWidgets('saving leaves the form', (tester) async {
      setPhone(tester);
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await tester.pumpWidget(_app(app, '/top-up', const TopUpScreen()));
      await _open(tester);

      await _type(tester, <String>['5', '0']);
      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      await tester.tap(find.text('Save money received'));
      await pumpDrift(tester);
      await tester.pumpAndSettle();

      expect(
        find.text('open'),
        findsOneWidget,
        reason: 'the form is gone after a save',
      );
      await settleToasts(tester);
    });
  });

  group('wallet form', () {
    Future<TestApp> bare() async {
      final app = await TestApp.create(seed: false);
      app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      app.api.users.add(<String, dynamic>{
        'id': 4,
        'name': 'Sachin More',
        'role': 'engineer',
      });
      await app.sync();
      return app;
    }

    testWidgets('creating the wallet leaves the form', (tester) async {
      setPhone(tester);
      final app = await bare();
      addTearDown(app.dispose);
      await tester.pumpWidget(_app(app, '/wallet', const WalletFormScreen()));
      await _open(tester);

      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      expect(find.text('Opening amount'), findsOneWidget);

      await _systemBack(tester);
      expect(
        find.text('Opening amount'),
        findsNothing,
        reason: 'back goes to the details',
      );
      expect(find.text('Wallet name'), findsOneWidget);

      await tester.tap(find.text('Next'));
      await pumpDrift(tester);
      await tester.tap(find.text('Create').last);
      await pumpDrift(tester);
      await tester.pumpAndSettle();

      expect(
        find.text('open'),
        findsOneWidget,
        reason: 'the form is gone once the wallet exists',
      );
      await settleToasts(tester);
    });
  });

  testWidgets('the Back tile has a 56dp target around a lighter tile', (
    tester,
  ) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await tester.pumpWidget(_app(app, '/expense', const ExpenseFormScreen()));
    await _open(tester);

    final Finder back = find.ancestor(
      of: find.text('Back'),
      matching: find.byType(PressTarget),
    );
    final Size target = tester.getSize(back.first);
    expect(target.height, greaterThanOrEqualTo(N360Size.tap));
    expect(target.width, greaterThanOrEqualTo(N360Size.tap));
    await tester.pump(const Duration(seconds: 1));
  });
}
