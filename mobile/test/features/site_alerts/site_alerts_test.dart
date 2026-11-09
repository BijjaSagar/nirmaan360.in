import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/site_alerts/data/site_alerts_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ServerFailure _refused = ServerFailure('This action is unauthorized.', statusCode: 403);

Future<TestApp> siteApp({
  bool permission = true,
  bool feature = true,
  void Function(FakeApi api)? setup,
}) async {
  final FakeApi server = FakeApi();
  if (permission) server.grant('progress.view');
  if (feature) ((server.user['organization'] as Map)['features'] as List).add('site_control');
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> pumpSite(
  WidgetTester tester,
  TestApp app, {
  String start = Routes.siteDashboard,
  String locale = 'en',
}) async {
  tester.view.devicePixelRatio = 3;
  tester.view.physicalSize = const Size(360, 800) * 3;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final AppGate gate = AppGate(app.services);
  addTearDown(gate.close);
  await pumpDrift(tester);
  final GoRouter router = buildRouter(gate, initialLocation: start);
  addTearDown(router.dispose);

  await tester.pumpWidget(
    RepositoryProvider<AppServices>.value(
      value: app.services,
      child: BlocProvider<AppGate>.value(
        value: gate,
        child: MaterialApp.router(
          routerConfig: router,
          theme: buildN360Theme(),
          locale: Locale(locale),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          builder: (context, navigator) => MediaQuery(
            data: MediaQuery.of(context).copyWith(disableAnimations: true),
            child: LedgerPaper.root(child: navigator),
          ),
        ),
      ),
    ),
  );
  await settle(tester);
}

Key k(String value) => ValueKey<String>(value);

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('reading', () {
    test('a sync keeps the dashboard and open alerts on the phone', () async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);

      final SiteOverview seen = (await app.services.siteAlerts.readOverview(1))!;
      expect(seen.dashboardCards.map((SiteCard card) => card.key), <String>['work', 'pending']);
      expect(seen.cards.first.value, '35%');
      expect(seen.cards.first.meters.single.percent, '35.00');
      expect(seen.cards[1].lines.single.label, 'Open issues');
      expect(seen.alerts.map((SiteAlert alert) => alert.severity), <AlertSeverity>[AlertSeverity.critical, AlertSeverity.info]);
      expect(seen.alerts.first.firstSeen, '2026-11-02');
      expect(seen.alertsTotal, 2);
      expect(seen.criticalCount, 1);
    });

    test('the last answer stays when the server cannot be reached', () async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);

      app.api.offline = true;
      await expectLater(app.services.siteAlerts.refresh(1), throwsA(isA<ApiFailure>()));

      expect((await app.services.siteAlerts.readOverview(1))!.alerts, hasLength(2));
    });

    test('a role with no alerts still gets its dashboard', () async {
      final TestApp app = await siteApp(setup: (FakeApi api) => api.siteAlertsFailure = _refused);
      addTearDown(app.dispose);

      final SiteOverview seen = (await app.services.siteAlerts.readOverview(1))!;
      expect(seen.alertsRefused, isTrue);
      expect(seen.dashboardRefused, isFalse);
      expect(seen.refused, isFalse);
      expect(seen.cards, isNotEmpty);
    });

    test('a refusal replaces what was kept, and is not an error', () async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);

      app.api
        ..siteDashboardFailure = _refused
        ..siteAlertsFailure = _refused;
      await app.services.siteAlerts.refresh(1);

      final SiteOverview seen = (await app.services.siteAlerts.readOverview(1))!;
      expect(seen.refused, isTrue);
      expect(seen.cards, isEmpty);
      expect(seen.alerts, isEmpty);
    });

    test('a money card kept from before the permission went is not shown', () async {
      final TestApp app = await siteApp(
        setup: (FakeApi api) {
          (api.user['can'] as Map)['money.view'] = true;
          (api.siteDashboardBody['cards'] as List).add(<String, dynamic>{
            'key': 'money',
            'title': 'Money spent against budget',
            'value': '₹2,270.35',
            'tone': 'ok',
            'meters': <Object>[],
            'rows': <Object>[],
            'money': true,
          });
        },
      );
      addTearDown(app.dispose);
      expect((await app.services.siteAlerts.readOverview(1))!.cards.map((SiteCard card) => card.key), contains('money'));

      (app.api.user['can'] as Map)['money.view'] = false;
      await app.sync();

      expect((await app.services.siteAlerts.readOverview(1))!.cards.map((SiteCard card) => card.key), isNot(contains('money')));
    });

    test('without the permission or the plan the phone does not ask', () async {
      final TestApp noPermission = await siteApp(permission: false);
      addTearDown(noPermission.dispose);
      final TestApp noPlan = await siteApp(feature: false);
      addTearDown(noPlan.dispose);

      expect(noPermission.api.siteDashboardReads, 0);
      expect(noPlan.api.siteDashboardReads, 0);
      expect(await noPermission.services.siteAlerts.readOverview(1), isNull);
    });

    test('a removed project takes its copy with it', () async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.siteOverview(1)), isNotNull);

      app.api.pullBodyOverride = <String, dynamic>{
        'server_time': '2026-11-09T14:27:54+05:30',
        'deleted': <String, dynamic>{
          'projects': <int>[1],
        },
      };
      await app.sync();

      expect(await app.db.readMeta(MetaKey.siteOverview(1)), isNull);
    });

    test('the copy on the phone round-trips as stored JSON', () async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      final Object? stored = jsonDecode((await app.db.readMeta(MetaKey.siteOverview(1)))!);
      expect((stored as Map).keys, containsAll(<String>['dashboard', 'alerts', 'fetched_at']));
    });
  });

  group('screen', () {
    testWidgets('shows the dashboard, then the alerts with severity in words', (WidgetTester tester) async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      await pumpSite(tester, app);

      expect(find.text('Work done'), findsWidgets);
      expect(find.text('35%'), findsWidgets);
      expect(find.text('9'), findsNWidgets(2));
      // The alerts card stays with its list.
      expect(find.text('Open alerts'), findsNothing);

      await tester.tap(find.byKey(k('site-tab-alerts')));
      await settle(tester);

      expect(find.text(en.alertsSeverityCritical), findsOneWidget);
      expect(find.text(en.alertsSeverityInfo), findsOneWidget);
      expect(find.text('Behind schedule'), findsOneWidget);
      expect(find.text(en.alertsSince('2 Nov')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('opens on the alerts tab when asked', (WidgetTester tester) async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text('Behind schedule'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('opens with no network from the last copy', (WidgetTester tester) async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text('Behind schedule'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a 403 on alerts is a calm message, and the dashboard still works', (WidgetTester tester) async {
      final TestApp app = await siteApp(setup: (FakeApi api) => api.siteAlertsFailure = _refused);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text(en.alertsListRefusedMessage), findsOneWidget);
      await tester.tap(find.byKey(k('site-tab-dashboard')));
      await settle(tester);
      expect(find.text('Pending items'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a 403 on everything is the calm page, not an error', (WidgetTester tester) async {
      final TestApp app = await siteApp(
        setup: (FakeApi api) => api
          ..siteDashboardFailure = _refused
          ..siteAlertsFailure = _refused,
      );
      addTearDown(app.dispose);
      await pumpSite(tester, app);

      expect(find.text(en.alertsRefusedTitle), findsOneWidget);
      expect(find.byKey(k('site-tab-alerts')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('no alerts reads as good news', (WidgetTester tester) async {
      final TestApp app = await siteApp(setup: (FakeApi api) => api.siteAlertRows = <Map<String, dynamic>>[]);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text(en.alertsNoneTitle), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('says when the rest are on the website', (WidgetTester tester) async {
      final TestApp app = await siteApp(setup: (FakeApi api) => api.siteAlertsTotal = 42);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text(en.alertsShowingSome(2, 42)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('fits 360dp in $locale', (WidgetTester tester) async {
        final TestApp app = await siteApp();
        addTearDown(app.dispose);
        await pumpSite(tester, app, locale: locale);
        expect(tester.takeException(), isNull);

        await tester.tap(find.byKey(k('site-tab-alerts')));
        await settle(tester);
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('home', () {
    Finder row() => find.byKey(k('home-site-alerts'));

    testWidgets('shows how many are open and the worst, in words', (WidgetTester tester) async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(row(), findsOneWidget);
      expect(find.text('${en.alertsHomeOpen(2)} · ${en.alertsHomeCriticalCount(1)}'), findsOneWidget);
      expect(find.descendant(of: row(), matching: find.text(en.alertsSeverityCritical)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('is hidden without the permission or the plan', (WidgetTester tester) async {
      final TestApp noPlan = await siteApp(feature: false);
      addTearDown(noPlan.dispose);
      await pumpShellApp(tester, noPlan);

      expect(row(), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('is hidden when the server refuses the site entirely', (WidgetTester tester) async {
      final TestApp app = await siteApp(
        setup: (FakeApi api) => api
          ..siteDashboardFailure = _refused
          ..siteAlertsFailure = _refused,
      );
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(row(), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('no alerts says so', (WidgetTester tester) async {
      final TestApp app = await siteApp(setup: (FakeApi api) => api.siteAlertRows = <Map<String, dynamic>>[]);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.descendant(of: row(), matching: find.text(en.alertsNoneTitle)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
