import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/petty_cash_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';

import '../../attendance/view/field_test_support.dart';

FakeApi _apiWithout(String permission) {
  final api = FakeApi();
  (api.user['can'] as Map)[permission] = false;
  return api;
}

Future<TestApp> _bareProject({FakeApi? api}) async {
  final fake = api ?? FakeApi();
  final app = await TestApp.create(seed: false, api: fake);
  fake.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
  fake.users.add({'id': 4, 'name': 'Sachin More', 'role': 'engineer'});
  await app.sync();
  return app;
}

Future<void> _openWallet(WidgetTester tester, TestApp app) async {
  await tester.pumpWidget(
    fieldRouterApp(
      app.services,
      initial: Routes.pettyCash,
      routes: {
        Routes.pettyCash: (_) => const PettyCashScreen(),
        Routes.walletForm: (_) => const WalletFormScreen(),
        Routes.topUp: (_) => const TopUpScreen(),
      },
    ),
  );
  await pumpDrift(tester);
}

void main() {
  testWidgets('no wallet with the permission: Create wallet is the action', (tester) async {
    setPhone(tester);
    final app = await _bareProject();
    addTearDown(app.dispose);

    await _openWallet(tester, app);

    expect(find.text('No wallet on this project yet'), findsOneWidget);
    await tester.tap(find.text('Create wallet'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 500));

    expect(find.text('New wallet'), findsOneWidget);
    expect(find.text('Sachin More'), findsOneWidget, reason: 'the holder defaults to me');
    expect(find.text('KT-B site cash'), findsOneWidget, reason: 'a sensible default name');
  });

  testWidgets('no wallet without the permission keeps the plain message', (tester) async {
    setPhone(tester);
    final app = await _bareProject(api: _apiWithout('petty_cash.manage'));
    addTearDown(app.dispose);

    await _openWallet(tester, app);

    expect(find.textContaining('project manager'), findsOneWidget);
    expect(find.text('Create wallet'), findsNothing);
  });

  testWidgets('with a wallet, Add money sits beside the primary Add expense', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await _openWallet(tester, app);

    expect(find.text('Add expense'), findsOneWidget);
    await tester.tap(find.text('Add money'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text('Money into the wallet'), findsOneWidget);
  });

  testWidgets('Add money is hidden without the permission', (tester) async {
    setPhone(tester);
    final app = await TestApp.create(api: _apiWithout('petty_cash.manage'));
    addTearDown(app.dispose);

    await _openWallet(tester, app);

    expect(find.text('Add expense'), findsOneWidget);
    expect(find.text('Add money'), findsNothing);
  });

  testWidgets('the wallet form says when offline and will not create', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, const WalletFormScreen()));
    await pumpDrift(tester);

    expect(find.byType(OfflineBanner), findsOneWidget);
    await tester.tap(find.text('Next'));
    await tester.pump();

    final keypad = tester.widget<GloveKeypad>(find.byType(GloveKeypad));
    expect(keypad.onNext, isNull);
  });

  testWidgets('a top-up saved offline says so plainly', (tester) async {
    setPhone(tester);
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.connectivity.setOnline(false);

    await tester.pumpWidget(fieldApp(app.services, const TopUpScreen()));
    await pumpDrift(tester);

    await tester.tap(find.text('5'));
    await tester.pump();
    await tester.tap(find.text('0').last);
    await tester.pump();
    await tester.tap(find.text('Next'));
    await pumpDrift(tester);
    await tester.tap(find.text('Save money received'));
    await pumpDrift(tester);
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Saved on phone. It will send when network returns.'), findsOneWidget);
    final entry = (await app.db.select(app.db.pettyCashEntries).get()).single;
    expect(entry.type, 'topup');
    await settleToasts(tester);
  });

  group('attendance with no workers', () {
    testWidgets('with worker.manage, Add first worker is the action', (tester) async {
      setPhone(tester);
      final app = await _bareProject();
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      final button = tester.widget<GloveButton>(
        find.widgetWithText(GloveButton, 'Add first worker'),
      );
      expect(button.kind, GloveButtonKind.primary);
    });

    testWidgets('without it, the screen says who to ask', (tester) async {
      setPhone(tester);
      final app = await _bareProject(api: _apiWithout('worker.manage'));
      addTearDown(app.dispose);

      await tester.pumpWidget(fieldApp(app.services, const AttendanceScreen()));
      await pumpDrift(tester);

      expect(find.textContaining('Ask the office'), findsOneWidget);
      expect(find.text('Add first worker'), findsNothing);
    });
  });
}
