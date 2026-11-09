// Renders the shell screens to PNG with the real fonts so a person can judge
// them.
//
// flutter test test/app/shell_render_test.dart --dart-define=N360_SHELL_DIR=/abs/dir
//
// Without the define the capture is skipped, so a normal run writes nothing.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/app.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/session/current_user.dart';

import '../core/design/test_helpers.dart';
import '../support/test_app.dart';
import 'shell_test_helpers.dart';

const String _dir = String.fromEnvironment('N360_SHELL_DIR');

const List<String> _locales = <String>['en', 'mr'];

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  if (_dir.isEmpty) {
    test('render skipped: pass --dart-define=N360_SHELL_DIR=...', () {});
    return;
  }

  Future<void> shoot(WidgetTester tester, String name) async {
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  for (final locale in _locales) {
    testWidgets('sign in ($locale)', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, locale: locale, location: Routes.signIn, pixelRatio: 2.5);
      await shoot(tester, '${locale}_signin');
    });

    testWidgets('expired sign in ($locale)', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
      await app.services.session.markExpired();
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5);
      await shoot(tester, '${locale}_signin_expired');
    });

    testWidgets('project picker ($locale)', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      app.api
        ..seedDemo()
        ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights')
        ..addProject(id: 3, code: 'SHR', name: 'Shivneri Riverside Phase 2');
      await app.sync();
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5);
      await shoot(tester, '${locale}_picker');
    });

    testWidgets('home ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await seedToday(app);
      await app.sync();
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5);
      await shoot(tester, '${locale}_home');
    });

    testWidgets('home with returned work ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await seedReturned(app);
      await seedToday(app);
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5);
      await shoot(tester, '${locale}_home_returned');
    });

    testWidgets('home offline ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await seedToday(app);
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5);
      await shoot(tester, '${locale}_home_offline');
    });

    testWidgets('outbox with slips and attention ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await seedToday(app);
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5, location: Routes.outbox);
      await shoot(tester, '${locale}_outbox');
    });

    testWidgets('outbox attention ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      app.api.scriptPushStatuses(<String>['error']);
      await seedToday(app);
      await app.sync();
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5, location: Routes.outbox);
      await shoot(tester, '${locale}_outbox_attention');
    });

    testWidgets('outbox all sent ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5, location: Routes.outbox);
      await shoot(tester, '${locale}_outbox_allsent');
    });

    testWidgets('sign-out warning ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await seedToday(app);
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5, location: Routes.settings);
      await tester.tap(find.bySemanticsLabel(RegExp('साइन आउट|Sign out')).last);
      await settle(tester);
      await shoot(tester, '${locale}_signout_warning');
    });

    testWidgets('settings ($locale)', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, locale: locale, pixelRatio: 2.5, location: Routes.settings);
      await shoot(tester, '${locale}_settings');
    });
  }

  testWidgets('motion frames', (tester) async {
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = const Size(360, 800) * 2.5;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    // Home: the rise on entry, every word readable from the first frame.
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await seedToday(app);
    await tester.pumpWidget(Nirmaan360App(services: app.services));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 80));
    await shoot(tester, 'frame_home_80ms');
    await tester.pump(const Duration(milliseconds: 160));
    await shoot(tester, 'frame_home_240ms');

    // The hazard tape arriving over the date line.
    await tester.pump(const Duration(milliseconds: 600));
    app.connectivity.setOnline(false);
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 90));
    await shoot(tester, 'frame_offline_90ms');
    await tester.pump(const Duration(milliseconds: 400));

    // Sign-in: the stamp landing.
    await tester.pumpWidget(const SizedBox.shrink());
    await app.services.auth.signOut(discardUnsent: true);
    await tester.pumpWidget(Nirmaan360App(services: app.services, initialLocation: Routes.signIn));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 90));
    await shoot(tester, 'frame_signin_90ms');
    await tester.pump(const Duration(milliseconds: 500));
  });
}
