import 'package:decimal/decimal.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/app.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../support/test_app.dart';

const String kToday = '2026-11-09';

/// Pumps the whole app, real router included, on [app]'s repositories.
Future<void> pumpShellApp(
  WidgetTester tester,
  TestApp app, {
  String location = Routes.home,
  String? locale,
  bool disableAnimations = true,
  Size size = const Size(360, 800),
  double pixelRatio = 1,
  double textScale = 1,
}) async {
  if (app.services.session.current.status == SessionStatus.restoring) {
    await app.services.auth.restoreSession();
  }
  if (locale != null) await app.services.auth.setLocale(locale);
  tester.view.devicePixelRatio = pixelRatio;
  tester.view.physicalSize = size * pixelRatio;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
  tester.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
  if (disableAnimations) {
    tester.platformDispatcher.accessibilityFeaturesTestValue =
        const FakeAccessibilityFeatures(disableAnimations: true);
    addTearDown(tester.platformDispatcher.clearAccessibilityFeaturesTestValue);
  }

  await tester.pumpWidget(Nirmaan360App(services: app.services, initialLocation: location));
  await settle(tester);
}

/// Lets drift streams land and the page settle, without `pumpAndSettle`.
Future<void> settle(WidgetTester tester, {Duration extra = const Duration(milliseconds: 800)}) async {
  await pumpDrift(tester, 10);
  await tester.pump(extra);
  await pumpDrift(tester, 5);
}

/// A second project, so the picker is not skipped.
void addSecondProject(TestApp app) {
  app.api.addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights');
}

/// A report and a measurement, both sent back by the checker. Returns their uuids.
Future<({String dpr, String measurement})> seedReturned(TestApp app) async {
  final uuid = await app.services.dpr.saveDraft(const DprInput(projectId: 1, reportDate: '2026-11-08'));
  await app.services.dpr.send(uuid);
  await app.sync();
  app.api.setServerStatus(
    app.api.dprByUuid(uuid),
    'RETURNED',
    returnRemark: 'Add the steel consumption figure before resubmitting.',
  );

  final measurement = await app.services.measurements.create(
    MeasurementInput(
      projectId: 1,
      boqItemId: 1,
      measuredOn: '2026-11-07',
      nos: Qty.fromInt(2),
      length: Qty.parse('10'),
      breadth: Qty.parse('5'),
      depth: Qty.parse('0.15'),
      location: 'Footing F12',
    ),
  );
  await app.services.measurements.sendForApproval(measurement);
  await app.sync();
  app.api.setServerStatus(
    app.api.measurementByUuid(measurement),
    'RETURNED',
    returnRemark: 'Depth does not match the site register.',
  );
  await app.sync();
  return (dpr: uuid, measurement: measurement);
}

/// Some of today's work, so the strip has figures to show.
Future<void> seedToday(TestApp app) async {
  await app.services.attendance.mark(projectId: 1, workerId: 1, date: kToday, status: 'present');
  await app.services.measurements.create(
    MeasurementInput(
      projectId: 1,
      boqItemId: 1,
      measuredOn: kToday,
      nos: Qty.fromInt(1),
      length: Qty.parse('4'),
      breadth: Qty.parse('2'),
      depth: Qty.parse('0.5'),
    ),
  );
  await app.services.pettyCash.addExpense(
    walletId: 1,
    amount: Money.of(Decimal.parse('1240.00')),
    entryDate: kToday,
    category: 'Fuel',
  );
}
