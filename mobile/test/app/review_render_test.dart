// Renders the screens the final review touched, pushed over a page the way
// the app opens them, on two phones in two languages, so the one header and
// the keypad steps can be judged side by side.
//
// flutter test test/app/review_render_test.dart --dart-define=N360_REVIEW_DIR=/abs/dir
//
// Without the define the layout check still runs and nothing is written.
import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/attendance/view/attendance_screen.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_form_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_list_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_list_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/expense_form_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';

import '../core/design/test_helpers.dart' show capturePng, loadRealFonts;
import '../features/attendance/view/field_test_support.dart';
import '../features/measurement/view/book_test_kit.dart' show pumpBook;
import 'polish_harness.dart' show Phone;

const String _dir = String.fromEnvironment('N360_REVIEW_DIR');

const List<(String, Locale)> _locales = <(String, Locale)>[('en', Locale('en')), ('mr', Locale('mr'))];
const List<Phone> _phones = <Phone>[Phone.android, Phone.iphone];

/// A site with a BOQ, a wallet, workers and one report, as an engineer's
/// phone would hold after the first pull.
Future<TestApp> _site() async {
  final api = FakeApi()..grant('boq.manage');
  final app = await TestApp.create(api: api);
  await app.sync();
  await app.services.dpr.saveDraft(
    DprInput(
      projectId: 1,
      reportDate: '2026-11-09',
      siteId: 1,
      weather: 'Clear',
      manpower: const <ManpowerLine>[ManpowerLine(trade: 'Mason', count: 12), ManpowerLine(trade: 'Helper', count: 18)],
      workItems: <WorkLine>[WorkLine(boqItemId: 1, qty: Qty.parse('15.5'))],
    ),
  );
  await app.services.issues.raise(
    projectId: 1,
    title: 'Crack in column C3',
    description: 'Diagonal crack on the east face.',
    priority: 'critical',
    locationNote: 'Grid C, third floor',
    dueDate: '2026-11-06',
  );
  return app;
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  Future<void> shoot(WidgetTester tester, String name) async {
    expect(tester.takeException(), isNull, reason: name);
    if (_dir.isEmpty) return;
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  void phone(WidgetTester tester, Phone phone) {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = phone.size * 2.5;
    tester.view.padding = FakeViewPadding(top: phone.safeTop * 2.5, bottom: phone.safeBottom * 2.5);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetPadding);
  }

  Future<void> settle(WidgetTester tester, [int ms = 800]) async {
    await pumpDrift(tester);
    await tester.pump(Duration(milliseconds: ms));
  }

  /// Opens [screen] over a blank page, so it has somewhere to go back to.
  Future<void> push(WidgetTester tester, TestApp app, Widget screen, Locale locale) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    await tester.pumpWidget(fieldApp(app.services, const Scaffold(body: SizedBox.expand()), locale: locale));
    await tester.pump();
    final NavigatorState navigator = Navigator.of(tester.element(find.byType(Scaffold).first));
    unawaited(navigator.push(MaterialPageRoute<void>(builder: (_) => screen)));
    await settle(tester);
  }

  Future<void> keys(WidgetTester tester, String digits) async {
    for (final String key in digits.split('')) {
      await tester.tap(find.descendant(of: find.byType(GloveKeypad), matching: find.text(key)));
      await tester.pump();
    }
    await tester.pump(const Duration(milliseconds: 400));
  }

  for (final device in _phones) {
    for (final (name, locale) in _locales) {
      final String tag = '${device.name}_$name';
      final String next = name == 'en' ? 'Next' : 'पुढे';

      testWidgets('field screens on ${device.name} ($name)', (tester) async {
        phone(tester, device);
        final app = await _site();
        addTearDown(app.dispose);

        await push(tester, app, const AttendanceScreen(), locale);
        await shoot(tester, '${tag}_attendance');

        await push(tester, app, const IssueListScreen(), locale);
        await shoot(tester, '${tag}_issues');

        await push(tester, app, const BoqScreen(), locale);
        await shoot(tester, '${tag}_boq');

        await push(tester, app, const DprListScreen(), locale);
        await shoot(tester, '${tag}_dpr_list');

        await push(tester, app, const DprFormScreen(date: '2026-11-09'), locale);
        await shoot(tester, '${tag}_dpr_form');

        await push(tester, app, const WalletFormScreen(), locale);
        await tester.tap(find.bySemanticsLabel(next));
        await tester.pump();
        await keys(tester, '25000');
        await shoot(tester, '${tag}_wallet_amount');

        await push(tester, app, const TopUpScreen(), locale);
        await keys(tester, '5000');
        await shoot(tester, '${tag}_topup_amount');

        await push(tester, app, ExpenseFormScreen(captureBill: () async => '/no/such/bill.jpg'), locale);
        await keys(tester, '1550');
        await shoot(tester, '${tag}_expense_amount');

        await push(tester, app, const BoqFormScreen(), locale);
        await tester.enterText(find.byType(TextField).first, 'RCC M25 in slab');
        await tester.tap(find.text('cum'));
        await tester.pump();
        await tester.tap(find.widgetWithIcon(GloveButton, Icons.arrow_forward));
        await tester.pump();
        await keys(tester, '620');
        await tester.tap(find.byType(DimensionTile).last);
        await tester.pump();
        await keys(tester, '7450');
        await shoot(tester, '${tag}_boq_figures');

        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump(const Duration(seconds: 1));
      });

      testWidgets('measurement entry on ${device.name} ($name)', (tester) async {
        final app = await _site();
        addTearDown(app.dispose);
        await pumpBook(tester, app, start: Routes.measurementForm, locale: name, size: device.size, pixelRatio: 2.5);
        await settle(tester);
        await shoot(tester, '${tag}_measure_form');

        await pumpBook(tester, app, start: Routes.measurements, locale: name, size: device.size, pixelRatio: 2.5);
        await settle(tester);
        await shoot(tester, '${tag}_measure_book');
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump(const Duration(seconds: 1));
      });
    }
  }
}
