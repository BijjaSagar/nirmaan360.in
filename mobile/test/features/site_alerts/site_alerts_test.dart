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
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/site_alerts/data/site_alerts_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ServerFailure _refused = ServerFailure('This action is unauthorized.', statusCode: 403);

/// A phone with the site on it. `permission` is the dashboard's (`progress.view`),
/// `alerts` the alerts' (`alerts.view`), `manage` the scan's (`alerts.manage`).
Future<TestApp> siteApp({
  bool permission = true,
  bool alerts = true,
  bool manage = false,
  bool feature = true,
  void Function(FakeApi api)? setup,
}) async {
  final FakeApi server = FakeApi();
  if (permission) server.grant('progress.view');
  if (alerts) server.grant('alerts.view');
  if (manage) server.grant('alerts.manage');
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
      final TestApp neither = await siteApp(permission: false, alerts: false);
      addTearDown(neither.dispose);
      final TestApp noPlan = await siteApp(feature: false);
      addTearDown(noPlan.dispose);

      expect(neither.api.siteDashboardReads, 0);
      expect(neither.api.siteAlertsReads, 0);
      expect(noPlan.api.siteDashboardReads, 0);
      expect(noPlan.api.siteAlertsReads, 0);
      expect(await neither.services.siteAlerts.readOverview(1), isNull);
    });

    test('the alerts are asked for with alerts.view, the dashboard with progress.view, each on its own', () async {
      final TestApp dashboardOnly = await siteApp(alerts: false);
      addTearDown(dashboardOnly.dispose);
      final TestApp alertsOnly = await siteApp(permission: false);
      addTearDown(alertsOnly.dispose);

      expect(dashboardOnly.api.siteAlertsReads, 0, reason: 'no alerts.view, so no ask');
      expect(dashboardOnly.api.siteDashboardReads, greaterThan(0));
      final SiteOverview a = (await dashboardOnly.services.siteAlerts.readOverview(1))!;
      expect(a.alertsRefused, isTrue);
      expect(a.dashboardRefused, isFalse);
      expect(a.cards, isNotEmpty);

      expect(alertsOnly.api.siteDashboardReads, 0, reason: 'no progress.view, so no ask');
      expect(alertsOnly.api.siteAlertsReads, greaterThan(0));
      final SiteOverview b = (await alertsOnly.services.siteAlerts.readOverview(1))!;
      expect(b.dashboardRefused, isTrue);
      expect(b.alerts, hasLength(2));
    });

    test('a person with alerts and no dashboard is not sent away empty handed', () async {
      final TestApp app = await siteApp(permission: false);
      addTearDown(app.dispose);

      expect((await app.services.siteAlerts.readOverview(1))!.refused, isFalse);
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

  group('scan now', () {
    test('asks the server, keeps what it counted, and reads the list again', () async {
      final TestApp app = await siteApp(manage: true);
      addTearDown(app.dispose);
      final int reads = app.api.siteAlertsReads;

      final AlertScan found = await app.services.siteAlerts.scan(1);

      expect(found, const AlertScan(added: 1, open: 3, resolved: 0));
      expect(app.api.siteScanCalls, 1);
      expect(app.api.siteAlertsReads, reads + 1);
    });

    test('a count the server leaves out is nothing, and the scan still counts as done', () {
      expect(AlertScan.fromJson(const <String, dynamic>{'new': 2}), const AlertScan(added: 2, open: 0, resolved: 0));
      expect(AlertScan.fromJson(const <String, dynamic>{}), const AlertScan(added: 0, open: 0, resolved: 0));
    });

    test('without alerts.manage nothing is sent', () async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);

      await expectLater(app.services.siteAlerts.scan(1), throwsA(isA<ServerFailure>()));

      expect(app.api.siteScanCalls, 0);
    });

    test('with no network it says so and sends nothing', () async {
      final TestApp app = await siteApp(manage: true);
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);

      await expectLater(app.services.siteAlerts.scan(1), throwsA(isA<OfflineFailure>()));

      expect(app.api.siteScanCalls, 0);
    });

    test('a scan that worked is not undone by a list that cannot be read after it', () async {
      final TestApp app = await siteApp(manage: true);
      addTearDown(app.dispose);
      app.api.siteAlertsFailure = const ServerFailure('Boom', statusCode: 500);

      expect(await app.services.siteAlerts.scan(1), const AlertScan(added: 1, open: 3, resolved: 0));
    });

    testWidgets('the button is for alerts.manage and nobody else', (WidgetTester tester) async {
      final TestApp manager = await siteApp(manage: true);
      addTearDown(manager.dispose);
      await pumpSite(tester, manager, start: '${Routes.siteDashboard}?tab=alerts');
      expect(find.byKey(k('site-scan-now')), findsOneWidget);
      expect(find.text(en.alertsScanNow), findsOneWidget);

      final TestApp reader = await siteApp();
      addTearDown(reader.dispose);
      await pumpSite(tester, reader, start: '${Routes.siteDashboard}?tab=alerts');
      expect(find.text('Behind schedule'), findsOneWidget, reason: 'the alerts themselves are there');
      expect(find.byKey(k('site-scan-now')), findsNothing);
      expect(find.text(en.alertsScanNow), findsNothing);
      expect(reader.api.siteScanCalls, 0);
    });

    testWidgets('it is not on the dashboard tab', (WidgetTester tester) async {
      final TestApp app = await siteApp(manage: true);
      addTearDown(app.dispose);
      await pumpSite(tester, app);

      expect(find.byKey(k('site-scan-now')), findsNothing);
    });

    testWidgets('a tap scans, says what was found, and the list is read again', (WidgetTester tester) async {
      final TestApp app = await siteApp(manage: true);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');
      final int reads = app.api.siteAlertsReads;

      await tester.tap(find.byKey(k('site-scan-now')));
      await settle(tester);

      expect(app.api.siteScanCalls, 1);
      expect(find.text(en.alertsScanDone(1, 3, 0)), findsOneWidget);
      expect(app.api.siteAlertsReads, greaterThan(reads));
      expect(tester.takeException(), isNull);
    });

    testWidgets('the rate limit is shown in the server\'s own words', (WidgetTester tester) async {
      final TestApp app = await siteApp(
        manage: true,
        setup: (FakeApi api) => api.siteScanFailure = const RateLimited(
          retryAfter: Duration(seconds: 42),
          serverMessage: 'Too Many Attempts.',
        ),
      );
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      await tester.tap(find.byKey(k('site-scan-now')));
      await settle(tester);

      expect(find.text(en.alertsScanRefused('Too Many Attempts.')), findsOneWidget);
      expect(find.text(en.alertsScanWait(42)), findsOneWidget);
      expect(find.text(en.alertsScanDone(1, 3, 0)), findsNothing);
      expect(app.api.siteScanCalls, 1);
    });

    testWidgets('a rate limit with no words and no wait is a plain failure, not an invention', (WidgetTester tester) async {
      final TestApp app = await siteApp(manage: true, setup: (FakeApi api) => api.siteScanFailure = const RateLimited());
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      await tester.tap(find.byKey(k('site-scan-now')));
      await settle(tester);

      expect(find.text(en.alertsScanFailed), findsOneWidget);
    });

    testWidgets('a refusal says what the server said', (WidgetTester tester) async {
      final TestApp app = await siteApp(manage: true, setup: (FakeApi api) => api.siteScanFailure = _refused);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      await tester.tap(find.byKey(k('site-scan-now')));
      await settle(tester);

      expect(find.text(en.alertsScanRefused('This action is unauthorized.')), findsOneWidget);
    });

    testWidgets('a server error is a plain failure that does not pass its words on', (WidgetTester tester) async {
      final TestApp app = await siteApp(
        manage: true,
        setup: (FakeApi api) => api.siteScanFailure = const ServerFailure('SQLSTATE[HY000] oops', statusCode: 500),
      );
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      await tester.tap(find.byKey(k('site-scan-now')));
      await settle(tester);

      expect(find.text(en.alertsScanFailed), findsOneWidget);
      expect(find.textContaining('SQLSTATE'), findsNothing);
    });

    testWidgets('with no network it says so', (WidgetTester tester) async {
      final TestApp app = await siteApp(manage: true);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');
      app.connectivity.setOnline(false);

      await tester.tap(find.byKey(k('site-scan-now')));
      await settle(tester);

      expect(find.text(en.alertsScanOffline), findsOneWidget);
      expect(app.api.siteScanCalls, 0);
    });

    testWidgets('the button is there above an empty list too', (WidgetTester tester) async {
      final TestApp app = await siteApp(manage: true, setup: (FakeApi api) => api.siteAlertRows = <Map<String, dynamic>>[]);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text(en.alertsNoneTitle), findsOneWidget);
      expect(find.byKey(k('site-scan-now')), findsOneWidget);
    });

    for (final String locale in <String>['mr', 'hi']) {
      for (final (String name, void Function(FakeApi) arrange) in <(String, void Function(FakeApi))>[
        ('done', (FakeApi api) {}),
        ('rate limited', (FakeApi api) => api.siteScanFailure = const RateLimited(retryAfter: Duration(seconds: 42), serverMessage: 'Too Many Attempts.')),
        ('refused', (FakeApi api) => api.siteScanFailure = _refused),
        ('failed', (FakeApi api) => api.siteScanFailure = const ServerFailure('Boom', statusCode: 500)),
      ]) {
        testWidgets('fits 360dp in $locale: $name', (WidgetTester tester) async {
          final TestApp app = await siteApp(manage: true, setup: arrange);
          addTearDown(app.dispose);
          await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts', locale: locale);

          await tester.tap(find.byKey(k('site-scan-now')));
          await settle(tester);

          expect(find.byKey(k('site-scan-result')), findsOneWidget);
          expect(tester.takeException(), isNull);
        });
      }
    }
  });

  group('who sees what', () {
    testWidgets('someone with alerts and no dashboard gets the alerts, with no tabs to choose', (WidgetTester tester) async {
      final TestApp app = await siteApp(permission: false);
      addTearDown(app.dispose);
      await pumpSite(tester, app);

      expect(find.text('Behind schedule'), findsOneWidget);
      expect(find.byKey(k('site-tab-dashboard')), findsNothing);
      expect(find.byKey(k('site-tab-alerts')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('someone with the dashboard and no alerts gets the dashboard even if the alerts were asked for', (WidgetTester tester) async {
      final TestApp app = await siteApp(alerts: false);
      addTearDown(app.dispose);
      await pumpSite(tester, app, start: '${Routes.siteDashboard}?tab=alerts');

      expect(find.text('Work done'), findsWidgets);
      expect(find.text('Behind schedule'), findsNothing);
      expect(find.byKey(k('site-tab-alerts')), findsNothing);
      expect(find.byKey(k('site-scan-now')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('someone with both chooses', (WidgetTester tester) async {
      final TestApp app = await siteApp();
      addTearDown(app.dispose);
      await pumpSite(tester, app);

      expect(find.byKey(k('site-tab-dashboard')), findsOneWidget);
      expect(find.byKey(k('site-tab-alerts')), findsOneWidget);
    });

    testWidgets('someone with neither is sent Home from the site page', (WidgetTester tester) async {
      final TestApp app = await siteApp(permission: false, alerts: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.siteDashboard);

      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('a company without the plan has no row on Home, and a deep link meets the calm page', (WidgetTester tester) async {
      final TestApp app = await siteApp(feature: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);
      expect(find.byKey(k('home-site-alerts')), findsNothing);

      GoRouter.of(tester.element(find.byType(HomeScreen))).go(Routes.siteDashboard);
      await settle(tester);

      expect(find.byKey(k('site-scan-now')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home has the alerts row for alerts.view alone', (WidgetTester tester) async {
      final TestApp app = await siteApp(permission: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.byKey(k('home-site-alerts')), findsOneWidget);
      expect(find.descendant(of: find.byKey(k('home-site-alerts')), matching: find.text(en.alertsHomeTitle)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home has a dashboard row, and no word of alerts, for progress.view alone', (WidgetTester tester) async {
      final TestApp app = await siteApp(alerts: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.byKey(k('home-site-alerts')), findsOneWidget);
      expect(find.descendant(of: find.byKey(k('home-site-alerts')), matching: find.text(en.alertsHomeTitle)), findsNothing);
      expect(find.descendant(of: find.byKey(k('home-site-alerts')), matching: find.text(en.alertsHomeDashboardOnly)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Home has no row for someone with neither', (WidgetTester tester) async {
      final TestApp app = await siteApp(permission: false, alerts: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.byKey(k('home-site-alerts')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });
}
