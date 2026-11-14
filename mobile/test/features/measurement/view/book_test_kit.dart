import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/approvals/view/approval_detail_screen.dart';
import 'package:nirmaan360/features/approvals/view/approvals_inbox_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_detail_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_form_screen.dart';
import 'package:nirmaan360/features/measurement/view/measurement_list_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../support/test_app.dart';

export '../../../support/test_app.dart';

/// A real router over the screens under test, with the app's theme and paper.
Future<GoRouter> pumpBook(
  WidgetTester tester,
  TestApp app, {
  required String start,
  String locale = 'en',
  bool disableAnimations = true,
  Size size = const Size(360, 800),
  double pixelRatio = 3,
  double textScale = 1,
}) async {
  tester.view.devicePixelRatio = pixelRatio;
  tester.view.physicalSize = size * pixelRatio;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final GoRouter router = GoRouter(
    initialLocation: start,
    routes: <RouteBase>[
      GoRoute(path: Routes.approvals, builder: (_, _) => const ApprovalsInboxScreen()),
      GoRoute(
        path: '/approvals/:id',
        builder: (_, state) => ApprovalDetailScreen(approvalId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(path: Routes.measurements, builder: (_, _) => const MeasurementListScreen()),
      GoRoute(
        path: Routes.measurementForm,
        builder: (_, state) => MeasurementFormScreen(clientUuid: state.uri.queryParameters['uuid']),
      ),
      GoRoute(
        path: '/measurements/:uuid',
        builder: (_, state) => MeasurementDetailScreen(clientUuid: state.pathParameters['uuid']!),
      ),
    ],
  );
  addTearDown(router.dispose);

  await tester.pumpWidget(
    RepositoryProvider<AppServices>.value(
      value: app.services,
      child: MaterialApp.router(
        routerConfig: router,
        theme: buildN360Theme(),
        debugShowCheckedModeBanner: false,
        locale: Locale(locale),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        builder: (context, navigator) => MediaQuery(
          data: MediaQuery.of(
            context,
          ).copyWith(disableAnimations: disableAnimations, textScaler: TextScaler.linear(textScale)),
          child: LedgerPaper.root(child: navigator),
        ),
      ),
    ),
  );
  await pumpDrift(tester);
  return router;
}

/// Taps a digit on the on-screen keypad, one character at a time.
Future<void> typeKeys(WidgetTester tester, String keys) async {
  for (final String key in keys.split('')) {
    await tester.tap(find.descendant(of: find.byType(GloveKeypad), matching: find.text(key)));
    await tester.pump();
  }
}

/// Presses the keypad's Next key.
Future<void> pressNext(WidgetTester tester, {String label = 'Next'}) async {
  await tester.tap(find.descendant(of: find.byType(GloveKeypad), matching: find.text(label)));
  await tester.pump();
}

/// The value a rolling figure is showing. They paint without `Text`.
String rollingValue(WidgetTester tester, Key key) {
  final Finder qty = find.byKey(key);
  final Finder number = find.descendant(of: qty, matching: find.byType(RollingNumber));
  return tester.widget<RollingNumber>(number).value;
}

/// A server with one project and a BOQ line of exactly [qty].
Future<TestApp> bookApp({
  String qty = '100.000',
  String unit = 'cum',
  FakeConnectivity? connectivity,
  FakeApi? api,
}) async {
  final FakeApi server = api ?? FakeApi();
  server
    ..addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B')
    ..addBoqItem(id: 1, projectId: 1, code: '2.1', qty: qty, unit: unit);
  server.boqItems.last['description'] = 'RCC M25 slab at level 6';
  final TestApp app = await TestApp.create(seed: false, api: server, connectivity: connectivity);
  await app.sync();
  return app;
}
