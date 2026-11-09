// Lays out every shell screen on two phones in two languages and, with the
// define, renders them to PNG with the real fonts so a person can judge them.
//
// flutter test test/app/polish_render_test.dart --dart-define=N360_POLISH_DIR=/abs/dir
//
// Without the define the layout check still runs and the capture is skipped.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';

import '../core/design/test_helpers.dart';
import '../support/test_app.dart';
import 'polish_harness.dart';
import 'shell_test_helpers.dart' show seedProgress, seedReturned, seedToday;

const String _dir = String.fromEnvironment('N360_POLISH_DIR');

const List<String> _locales = <String>['en', 'mr'];
const List<Phone> _phones = <Phone>[Phone.android, Phone.iphone, Phone.large];

/// Signed in as someone who can set up a site, with nothing on the server yet.
Future<TestApp> _owner() => TestApp.create(
      seed: false,
      api: FakeApi()
        ..grant('project.manage')
        ..grant('boq.manage'),
    );

typedef _Scene = ({String name, Future<TestApp> Function() app, String location});

/// Every screen the shell owns, in the state that matters most.
final List<_Scene> _scenes = <_Scene>[
  (
    name: 'signin',
    app: () => TestApp.create(seed: false, signedIn: false),
    location: Routes.signIn,
  ),
  (
    name: 'picker',
    app: () async {
      final app = await TestApp.create(seed: false);
      app.api
        ..seedDemo()
        ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights')
        ..addProject(id: 3, code: 'SHR', name: 'Shivneri Riverside Phase 2');
      await app.sync();
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'picker_none',
    app: () => TestApp.create(seed: false),
    location: Routes.home,
  ),
  (name: 'owner_first', app: _owner, location: Routes.home),
  (name: 'project_form', app: _owner, location: Routes.projectForm),
  (
    name: 'home',
    app: () async {
      final app = await TestApp.create();
      await seedToday(app);
      await app.sync();
      await seedProgress(app);
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'home_returned',
    app: () async {
      final app = await TestApp.create();
      await seedReturned(app);
      await seedToday(app);
      await seedProgress(app);
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'home_offline',
    app: () async {
      final app = await TestApp.create();
      await seedToday(app);
      await seedProgress(app);
      app.connectivity.setOnline(false);
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'owner_home_setup0',
    app: () async {
      final app = await _owner();
      app.api.addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
      await app.sync();
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'home_zero',
    app: () async {
      final app = await TestApp.create(seed: false);
      app.api
        ..addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B')
        ..addBoqItem(id: 1, projectId: 1, code: '2.1', qty: '620.000', rate: '7450.00')
        ..addWorker2(id: 1, name: 'Ramesh Pawar');
      await app.sync();
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'home_noboq',
    app: () async {
      final app = await TestApp.create(seed: false);
      app.api
        ..addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B')
        ..addWorker2(id: 1, name: 'Ramesh Pawar');
      await app.sync();
      return app;
    },
    location: Routes.home,
  ),
  (
    name: 'outbox',
    app: () async {
      final app = await TestApp.create();
      await seedToday(app);
      return app;
    },
    location: Routes.outbox,
  ),
  (
    name: 'outbox_allsent',
    app: () => TestApp.create(),
    location: Routes.outbox,
  ),
  (name: 'settings', app: () => TestApp.create(), location: Routes.settings),
];

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    if (_dir.isNotEmpty) await Directory(_dir).create(recursive: true);
  });

  Future<void> shoot(WidgetTester tester, String name) async {
    if (_dir.isEmpty) return;
    await tester.runAsync(() => capturePng(tester, _dir, name));
  }

  for (final phone in _phones) {
    for (final locale in _locales) {
      for (final scene in _scenes) {
        testWidgets('${scene.name} on ${phone.name} ($locale)', (tester) async {
          final app = await scene.app();
          addTearDown(app.dispose);
          await pumpPolishApp(tester, app, locale: locale, location: scene.location, phone: phone, pixelRatio: 2.5);
          expect(tester.takeException(), isNull, reason: '${scene.name} ${phone.name} $locale');
          await shoot(tester, '${phone.name}_${locale}_${scene.name}');
        });
      }
    }
  }

  testWidgets('with two returned items the progress card is whole and the first tiles are on screen', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await seedToday(app);
    await seedProgress(app);
    await pumpPolishApp(tester, app, phone: Phone.iphone);

    final Rect bar = tester.getRect(find.byType(HatchedProgressBar));
    final Rect firstTile = tester.getRect(find.byType(StatTile).first);
    expect(bar.bottom, lessThanOrEqualTo(firstTile.top));
    expect(firstTile.bottom, lessThanOrEqualTo(Phone.iphone.size.height - Phone.iphone.safeBottom - N360Size.bar));
    expect(find.byType(RubberStamp), findsNWidgets(2));
    expect(tester.takeException(), isNull);
  });

  testWidgets('with no project, settings and sign-out are still reachable', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    await pumpPolishApp(tester, app);

    expect(find.byType(ProjectPickerScreen), findsOneWidget);
    await tester.tap(find.bySemanticsLabel('Settings'));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 600));
    expect(find.text('Sign out'), findsOneWidget);
  });

  for (final scene in _scenes) {
    testWidgets('${scene.name} fits larger Marathi text without overflow', (tester) async {
      final app = await scene.app();
      addTearDown(app.dispose);
      await pumpPolishApp(tester, app, locale: 'mr', location: scene.location, textScale: 1.3);
      expect(tester.takeException(), isNull, reason: '${scene.name} at 1.3x in Marathi');
    });
  }

  testWidgets('home entry frames', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedReturned(app);
    await seedToday(app);
    await seedProgress(app);
    tester.view.devicePixelRatio = 2.5;
    tester.view.physicalSize = Phone.iphone.size * 2.5;
    tester.view.padding = const FakeViewPadding(top: 59 * 2.5, bottom: 34 * 2.5);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    addTearDown(tester.view.resetPadding);
    await app.services.auth.setLocale('en');

    await tester.pumpWidget(PolishApp(services: app.services));
    await pumpDrift(tester, 10);
    var at = 0;
    for (final ms in <int>[0, 120, 300, 600, 1200]) {
      await tester.pump(Duration(milliseconds: ms - at));
      at = ms;
      // Every word is on the page from the first frame; only positions, the
      // figure's digits and the fills are still moving.
      expect(find.byType(HomeScreen), findsOneWidget);
      expect(find.text('Construction progress'), findsOneWidget);
      await shoot(tester, 'frame_home_${ms}ms');
    }
    await tester.pump(const Duration(seconds: 1));
    expect(tester.takeException(), isNull);
  });
}
