import 'package:flutter/material.dart' hide MaterialState;
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/photo_capture.dart';
import 'package:nirmaan360/features/material_control/bloc/material_loop_cubits.dart';
import 'package:nirmaan360/features/material_control/data/material_loop_repository.dart';
import 'package:nirmaan360/features/material_control/data/material_repository.dart';
import 'package:nirmaan360/features/material_control/view/material_loop_screens.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

/// A company on a plan with site control and purchasing, and someone who can
/// read material. [grants] adds what the test is about.
Future<TestApp> loopApp({
  List<String> grants = const <String>[],
  bool procurement = true,
  void Function(FakeApi api)? setup,
}) async {
  final FakeApi server = FakeApi()..grant('material_control.view');
  // The fake's person sees money unless told otherwise; here site staff are the rule.
  (server.user['can'] as Map)['money.view'] = false;
  for (final String permission in grants) {
    server.grant(permission);
  }
  final List<dynamic> features = (server.user['organization'] as Map)['features'] as List;
  features.add('site_control');
  if (procurement && !features.contains('procurement')) features.add('procurement');
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

/// Lets a toast run out, so no timer is left behind.
Future<void> pastToast(WidgetTester tester) async {
  await tester.pump(const Duration(seconds: 4));
  await pumpDrift(tester, 5);
}

Future<void> pumpAt(WidgetTester tester, TestApp app, {String start = Routes.material, String locale = 'en'}) async {
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

Future<void> tap(WidgetTester tester, Key key) async {
  await tester.ensureVisible(find.byKey(key));
  await tester.pump();
  await tester.tap(find.byKey(key));
  await settle(tester);
}

Future<void> type(WidgetTester tester, Key field, String text) async {
  await tester.enterText(find.descendant(of: find.byKey(field), matching: find.byType(TextField)), text);
  await tester.pump();
}

void standCamera(String path) {
  PhotoCapture.stand = () async => path;
  addTearDown(() => PhotoCapture.stand = null);
}

void main() {
  // The placeholder test font is far wider than the app's, so layouts are checked with the real ones.
  setUpAll(loadRealFonts);

  group('a reason on a flag', () {
    testWidgets('someone who may explain a gap writes the reason, and the flag is answered', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.reason']);
      addTearDown(app.dispose);
      await pumpAt(tester, app);

      await tap(tester, k('material-row-5'));
      expect(find.textContaining('Where did it go?'), findsOneWidget);
      expect(find.text(en.materialReasonNotYours), findsNothing);

      await tap(tester, k('material-flag-unaccounted-reason'));
      expect(find.text(en.materialReasonLabel), findsOneWidget);

      // Nothing typed is refused here, before the server is asked.
      await tap(tester, k('flag-reason-save'));
      expect(find.text(en.materialReasonEmpty), findsOneWidget);
      expect(app.api.reasonAttempts, isEmpty);

      await type(tester, k('flag-reason-text'), '  Five bags went to the gate cabin.  ');
      await tap(tester, k('flag-reason-save'));

      final (int projectId, Map<String, dynamic> body) = app.api.reasonPosts.single;
      expect(projectId, 1);
      expect(body['material_id'], 5);
      expect(body['flag_type'], 'unaccounted');
      expect(body['reason'], 'Five bags went to the gate cabin.');
      expect(body['client_uuid'], isA<String>());
      expect(find.text(en.materialReasonSaved), findsOneWidget);
      await pastToast(tester);

      // The page was read again: nothing left to answer, and the reason shows on the material.
      expect(find.byKey(k('flag-reason-save')), findsNothing);
      await tap(tester, k('material-row-5'));
      expect(find.byKey(k('material-flag-unaccounted-reason')), findsNothing);
      expect(find.textContaining(en.materialReasonAnswered), findsOneWidget);
      expect(find.text('Five bags went to the gate cabin.'), findsOneWidget);
    });

    testWidgets('someone who may not sees who writes it, and no button', (tester) async {
      final TestApp app = await loopApp();
      addTearDown(app.dispose);
      await pumpAt(tester, app);

      await tap(tester, k('material-row-5'));
      expect(find.text(en.materialReasonNotYours), findsOneWidget);
      expect(find.byKey(k('material-flag-unaccounted-reason')), findsNothing);
    });

    testWidgets('with no network it says so, and the retry sends the same reason once', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.reason']);
      addTearDown(app.dispose);
      await pumpAt(tester, app);
      await tap(tester, k('material-row-5'));
      await tap(tester, k('material-flag-unaccounted-reason'));
      await type(tester, k('flag-reason-text'), 'Counted wrong last week.');

      app.api.materialLoopFailure = const OfflineFailure();
      await tap(tester, k('flag-reason-save'));
      expect(find.text(en.materialNeedsNetwork), findsOneWidget);
      expect(app.api.reasonPosts, isEmpty);

      await tap(tester, k('flag-reason-save'));
      expect(app.api.reasonPosts, hasLength(1));
      expect(app.api.reasonAttempts, hasLength(2));
      expect(app.api.reasonAttempts.toSet(), hasLength(1), reason: 'the retry carries the first client_uuid');
      await pastToast(tester);
    });

    testWidgets('a gap that has closed is refused in the server\'s own words', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.reason']);
      addTearDown(app.dispose);
      await pumpAt(tester, app);
      await tap(tester, k('material-row-5'));
      await tap(tester, k('material-flag-unaccounted-reason'));
      await type(tester, k('flag-reason-text'), 'Found them.');

      // Someone counted the stock in between, so the flag is gone on the server.
      ((app.api.materialCheckBody['rows'] as List).first as Map)['flags'] = <Map<String, dynamic>>[];
      await tap(tester, k('flag-reason-save'));
      expect(find.text('This gap has since closed, so no reason is needed.'), findsOneWidget);
      expect(app.api.reasonPosts, isEmpty);
    });

    test('a reason longer than the server takes is stopped on the phone', () async {
      final TestApp app = await loopApp(grants: <String>['material_control.reason']);
      addTearDown(app.dispose);
      final FlagReasonCubit cubit = FlagReasonCubit(app.services, projectId: 1, materialId: 5, flagType: 'unaccounted');
      addTearDown(cubit.close);

      expect(await cubit.send('a' * 2001), isFalse);
      expect(cubit.state.problem, LoopProblem.tooLong);
      expect(app.api.reasonAttempts, isEmpty);
    });
  });

  group('a challan in full', () {
    testWidgets('a challan opens from the list with its names, remarks and bill flag, and no figure for site staff', (
      tester,
    ) async {
      final TestApp app = await loopApp(
        setup: (api) => api.challanDetails[1]!.addAll(<String, dynamic>{
          'bill_status': 'attached',
          'bill_attached_by': 'Asha Kulkarni',
          'bill_no': 'GT/4471',
          'bill_amount': '15200.50',
          'has_bill_file': true,
          'supplier_warning': 'Delivered short 3 times.',
        }),
      );
      addTearDown(app.dispose);
      await pumpAt(tester, app);

      await tester.tap(find.text(en.materialTabDeliveries));
      await settle(tester);
      await tap(tester, k('material-challan-1'));

      expect(find.byType(ChallanScreen), findsOneWidget);
      expect(app.api.challanDetailReads, <int>[1]);
      expect(find.text('DC-2026-27-0001'), findsOneWidget);
      expect(find.text('Gupta Traders'), findsOneWidget);
      expect(find.text('Cement'), findsOneWidget);
      expect(find.text('200.000 bag'), findsOneWidget);
      expect(find.text('Unloaded at the north gate.'), findsOneWidget);
      expect(find.text('Sachin More'), findsOneWidget);
      expect(find.textContaining('Delivered short 3 times.'), findsOneWidget);
      expect(find.text(en.materialBillAttached), findsOneWidget);
      expect(find.text(en.materialBillAttachedBy('Asha Kulkarni')), findsOneWidget);

      // No money.view: no number, no amount, no way to the file, and no way to attach or report.
      expect(find.text('GT/4471'), findsNothing);
      expect(find.textContaining('15,200'), findsNothing);
      expect(find.byKey(k('challan-view-bill')), findsNothing);
      expect(find.byKey(k('challan-attach-bill')), findsNothing);
      expect(find.byKey(k('challan-report-short')), findsNothing);
    });

    testWidgets('money.view sees the bill figures and opens the file', (tester) async {
      final TestApp app = await loopApp(
        grants: <String>['money.view'],
        setup: (api) => api.challanDetails[1]!.addAll(<String, dynamic>{
          'bill_status': 'attached',
          'bill_no': 'GT/4471',
          'bill_date': '2026-11-02',
          'bill_amount': '15200.50',
          'has_bill_file': true,
        }),
      );
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));

      expect(find.text('GT/4471'), findsOneWidget);
      expect(find.text('₹15,200.50'), findsOneWidget);
      await tap(tester, k('challan-view-bill'));
      expect(app.api.challanBillReads, <int>[1]);
    });

    testWidgets('the challan photo opens from the server', (tester) async {
      final TestApp app = await loopApp();
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));

      await tap(tester, k('challan-view-photo'));
      expect(app.api.challanPhotoReads, <int>[1]);
    });

    testWidgets('a challan that cannot be read says why', (tester) async {
      final TestApp app = await loopApp();
      addTearDown(app.dispose);

      app.api.materialLoopFailure = const OfflineFailure();
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));
      expect(find.text(en.materialChallanOffline), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await pumpAt(tester, app, start: Routes.materialChallanAt(77));
      expect(find.text(en.materialChallanRefused), findsOneWidget);
    });

    testWidgets('a reversed challan offers no bill and no short', (tester) async {
      final TestApp app = await loopApp(
        grants: <String>['material_control.bills', 'material_control.challan'],
        setup: (api) => api.challanDetails[1]!.addAll(<String, dynamic>{
          'status': 'REVERSED',
          'reversed_by': 'Meera Joshi',
          'reversed_at': '2026-11-05',
          'reversal_reason': 'Wrong site',
        }),
      );
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));

      expect(find.text(en.procurementReversed), findsOneWidget);
      expect(find.textContaining('Wrong site'), findsOneWidget);
      expect(find.byKey(k('challan-attach-bill')), findsNothing);
      expect(find.byKey(k('challan-report-short')), findsNothing);
    });

    testWidgets('the challan reads in Marathi and Hindi without overflow', (tester) async {
      for (final String locale in <String>['mr', 'hi']) {
        final TestApp app = await loopApp(grants: <String>['material_control.bills', 'material_control.challan', 'money.view']);
        addTearDown(app.dispose);
        await pumpAt(tester, app, start: Routes.materialChallanAt(1), locale: locale);
        final AppLocalizations l = lookupAppLocalizations(Locale(locale));
        expect(find.text(l.materialChallanTitle), findsOneWidget);
        expect(find.text(l.materialBillPending), findsOneWidget);
        await tap(tester, k('challan-attach-bill'));
        expect(find.text(l.materialBillSend), findsOneWidget);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }
    });
  });

  group('the bill', () {
    testWidgets('a site engineer attaches a photo of the bill and is asked for no figure', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills']);
      addTearDown(app.dispose);
      standCamera('/tmp/bill-1.jpg');
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));

      expect(find.text(en.materialBillWaiting), findsOneWidget);
      await tap(tester, k('challan-attach-bill'));
      expect(find.byKey(k('bill-form-number')), findsNothing);
      expect(find.byKey(k('bill-form-amount')), findsNothing);

      // With nothing chosen, nothing is sent.
      await tap(tester, k('bill-form-save'));
      expect(find.text(en.materialBillNeeded), findsOneWidget);
      expect(app.api.billPosts, isEmpty);

      await tap(tester, k('bill-form-camera'));
      expect(find.byKey(k('bill-form-photo')), findsOneWidget);
      await tap(tester, k('bill-form-save'));

      final (int challanId, Map<String, dynamic> body, String? file) = app.api.billPosts.single;
      expect(challanId, 1);
      expect(file, '/tmp/bill-1.jpg');
      expect(body.containsKey('bill_no'), isFalse);
      expect(body.containsKey('bill_amount'), isFalse);
      expect(find.text(en.materialBillSaved), findsOneWidget);
      await pastToast(tester);

      expect(find.byKey(k('bill-form-save')), findsNothing);
      expect(find.text(en.materialBillAttached), findsOneWidget);
      expect(find.text(en.materialBillReplace), findsOneWidget);
    });

    testWidgets('money.view may enter the number alone, and a bad amount is stopped on the phone', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills', 'money.view']);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));

      await tap(tester, k('challan-attach-bill'));
      await type(tester, k('bill-form-number'), 'GT/4471');
      await type(tester, k('bill-form-amount'), '12.345');
      await tap(tester, k('bill-form-save'));
      expect(find.text(en.materialBillAmountInvalid), findsOneWidget);
      expect(app.api.billPosts, isEmpty);

      await type(tester, k('bill-form-amount'), '15200.5');
      await tap(tester, k('bill-form-save'));

      final (_, Map<String, dynamic> body, String? file) = app.api.billPosts.single;
      expect(file, isNull);
      expect(body['bill_no'], 'GT/4471');
      expect(body['bill_amount'], '15200.50');
      await pastToast(tester);
      expect(find.text('GT/4471'), findsOneWidget);
      expect(find.text('₹15,200.50'), findsOneWidget);
    });

    testWidgets('a refusal keeps the form open with the server\'s reason', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills']);
      addTearDown(app.dispose);
      standCamera('/tmp/bill-2.jpg');
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));
      await tap(tester, k('challan-attach-bill'));
      await tap(tester, k('bill-form-camera'));

      app.api.materialLoopFailure = const ValidationFailure(
        'x',
        errors: <String, List<String>>{
          'bill': <String>['The bill must not be greater than 10240 kilobytes.'],
        },
      );
      await tap(tester, k('bill-form-save'));
      expect(find.text('The bill must not be greater than 10240 kilobytes.'), findsOneWidget);
      expect(find.byKey(k('bill-form-save')), findsOneWidget);

      app.api.materialLoopFailure = const ServerFailure('Forbidden', statusCode: 403);
      await tap(tester, k('bill-form-save'));
      expect(find.text(en.materialNotAllowed), findsOneWidget);
      expect(app.api.billPosts, isEmpty);
    });
  });

  group('a short found later', () {
    testWidgets('a store keeper records it against the challan', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.challan']);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));

      await tap(tester, k('challan-report-short'));
      expect(find.text(en.materialShortHint), findsOneWidget);

      await tap(tester, k('short-form-save'));
      expect(find.text(en.materialShortQtyInvalid), findsOneWidget);
      expect(app.api.shortAttempts, isEmpty);

      await type(tester, k('short-form-qty'), '3');
      await type(tester, k('short-form-note'), 'Counted at unloading.');
      await tap(tester, k('short-form-save'));

      final (int challanId, Map<String, dynamic> body) = app.api.shortPosts.single;
      expect(challanId, 1);
      expect(body['short_qty'], '3.000');
      expect(body['found_on'], app.services.context.today);
      expect(body['note'], 'Counted at unloading.');
      expect(body['client_uuid'], isA<String>());
      expect(find.text(en.materialShortSaved), findsOneWidget);
      await pastToast(tester);

      expect(find.text(en.materialShortHeading), findsOneWidget);
      expect(find.text('3.000 bag'), findsOneWidget);
      expect(find.textContaining(en.materialShortLater), findsOneWidget);
      expect(find.textContaining('Counted at unloading.'), findsOneWidget);
    });

    testWidgets('more than the challan is refused under the field, and a lost send is retried as the same short', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.challan']);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialChallanAt(1));
      await tap(tester, k('challan-report-short'));

      await type(tester, k('short-form-qty'), '201');
      await tap(tester, k('short-form-save'));
      expect(find.text('A shortage cannot be more than the quantity on this challan.'), findsOneWidget);
      expect(app.api.shortPosts, isEmpty);

      await type(tester, k('short-form-qty'), '2');
      app.api.materialLoopFailure = const OfflineFailure();
      await tap(tester, k('short-form-save'));
      expect(find.text(en.materialNeedsNetwork), findsOneWidget);

      await tap(tester, k('short-form-save'));
      expect(app.api.shortPosts, hasLength(1));
      // Three sends in all, and every one under the first client_uuid until one was stored.
      expect(app.api.shortAttempts, hasLength(3));
      expect(app.api.shortAttempts.toSet(), hasLength(1));
      await pastToast(tester);

      // A second short on the same challan is a new record.
      await tap(tester, k('challan-report-short'));
      await type(tester, k('short-form-qty'), '1');
      await tap(tester, k('short-form-save'));
      expect(app.api.shortPosts, hasLength(2));
      expect(app.api.shortAttempts.toSet(), hasLength(2));
      await pastToast(tester);
    });

    test('a quantity with too many decimals never leaves the phone', () async {
      final TestApp app = await loopApp(grants: <String>['material_control.challan']);
      addTearDown(app.dispose);
      final ChallanCubit cubit = ChallanCubit(app.services, challanId: 1);
      addTearDown(cubit.close);
      await cubit.load();

      expect(await cubit.reportShort(qtyText: '1.2345', foundOn: app.services.context.today), isFalse);
      expect(await cubit.reportShort(qtyText: '0', foundOn: app.services.context.today), isFalse);
      expect(cubit.state.problem, LoopProblem.invalid);
      expect(app.api.shortAttempts, isEmpty);
    });
  });

  group('bills waiting', () {
    testWidgets('the list shows what waits and how long, and opens the challan', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills']);
      addTearDown(app.dispose);
      await pumpAt(tester, app);

      await tap(tester, k('material-open-bills'));
      expect(find.byType(BillsScreen), findsOneWidget);
      expect(find.text(en.materialBillsWaitingCount(2)), findsOneWidget);
      expect(find.text(en.materialBillsAlertNote(7)), findsOneWidget);
      expect(find.textContaining(en.materialBillsDays(9)), findsOneWidget);
      expect(find.text(en.materialBillsOverdue), findsOneWidget);
      expect(find.text('Patil Sand'), findsOneWidget);
      expect(find.byKey(k('bill-row-3')), findsNothing);

      await tap(tester, k('bills-tab-attached'));
      expect(app.api.billsReads.last, (true, 1));
      expect(find.byKey(k('bill-row-3')), findsOneWidget);
      // No money.view: the amount on an attached bill is not sent and not drawn.
      expect(find.textContaining('19,500'), findsNothing);

      await tap(tester, k('bills-tab-pending'));
      await tap(tester, k('bill-row-1'));
      expect(find.byType(ChallanScreen), findsOneWidget);
      expect(app.api.challanDetailReads, <int>[1]);
    });

    testWidgets('money.view sees the amount of an attached bill', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills', 'money.view']);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialBills);

      await tap(tester, k('bills-tab-attached'));
      expect(find.text('₹19,500'), findsOneWidget);
    });

    testWidgets('more pages are read on request', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills'], setup: (api) => api.billsLastPage = 2);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialBills);

      await tap(tester, k('bills-more'));
      expect(app.api.billsReads, <(bool, int)>[(false, 1), (false, 2)]);
      // The fake repeats its rows on page 2; a row already listed is not listed twice.
      expect(find.byKey(k('bill-row-1')), findsOneWidget);
      expect(find.byKey(k('bills-more')), findsNothing);
    });

    testWidgets('the way in shows only to someone who chases bills, and the page sends others Home', (tester) async {
      final TestApp none = await loopApp();
      addTearDown(none.dispose);
      await pumpAt(tester, none);
      expect(find.byKey(k('material-open-bills')), findsNothing);

      final AppGate gate = AppGate(none.services);
      addTearDown(gate.close);
      await pumpDrift(tester);
      expect(gate.redirect(Uri.parse(Routes.materialBills)), Routes.home);
      expect(gate.redirect(Uri.parse(Routes.materialChallanAt(1))), isNull);

      final TestApp allowed = await loopApp(grants: <String>['material_control.bills']);
      addTearDown(allowed.dispose);
      final AppGate open = AppGate(allowed.services);
      addTearDown(open.close);
      await pumpDrift(tester);
      expect(open.redirect(Uri.parse(Routes.materialBills)), isNull);
    });

    testWidgets('a challan with its bill pending is marked for whoever chases bills, and for nobody else', (tester) async {
      Future<void> check(List<String> grants, Matcher pill) async {
        final TestApp app = await loopApp(
          grants: grants,
          setup: (api) => api.materialChallanRows.first.addAll(<String, dynamic>{'bill_status': 'pending', 'status': 'POSTED'}),
        );
        addTearDown(app.dispose);
        await pumpAt(tester, app);
        await tester.tap(find.text(en.materialTabDeliveries));
        await settle(tester);
        expect(find.text(en.materialBillPending), pill);
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }

      await check(<String>['material_control.bills'], findsOneWidget);
      await check(const <String>[], findsNothing);
    });

    testWidgets('a refusal and no network each say so', (tester) async {
      final TestApp app = await loopApp(grants: <String>['material_control.bills']);
      addTearDown(app.dispose);

      app.api.materialLoopFailure = const ServerFailure('Forbidden', statusCode: 403);
      await pumpAt(tester, app, start: Routes.materialBills);
      expect(find.text(en.materialBillsRefused), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      app.api.materialLoopFailure = const OfflineFailure();
      await pumpAt(tester, app, start: Routes.materialBills);
      expect(find.text(en.materialBillsOffline), findsOneWidget);
    });
  });

  group('wastage against the BOQ norms', () {
    testWidgets('lines over their allowance come first, with what the norm allows', (tester) async {
      final TestApp app = await loopApp(grants: <String>['procurement.view']);
      addTearDown(app.dispose);
      await pumpAt(tester, app);

      await tap(tester, k('material-open-wastage'));
      expect(find.byType(WastageScreen), findsOneWidget);
      expect(app.api.reconciliationReads, <String?>['measured']);
      expect(find.text(en.materialWastageOverCount(1)), findsOneWidget);
      expect(find.text(en.materialWastageHint), findsOneWidget);
      expect(find.text(en.materialStatusWastage), findsOneWidget);
      expect(find.text(en.materialStatusNoNorm), findsOneWidget);
      expect(find.text(en.materialStatusOk), findsOneWidget);

      // Over the allowance first, then no norm, then within the norm.
      final double wastage = tester.getTopLeft(find.byKey(k('wastage-row-11-5'))).dy;
      final double noNorm = tester.getTopLeft(find.byKey(k('wastage-row-13-6'))).dy;
      final double within = tester.getTopLeft(find.byKey(k('wastage-row-12-5'))).dy;
      expect(wastage < noNorm && noNorm < within, isTrue);
      expect(find.textContaining(en.materialBeyondAllowed('21.600 bag')), findsOneWidget);

      await tester.scrollUntilVisible(
        find.text(en.materialReorderHeading),
        200,
        scrollable: find.descendant(of: find.byType(ListView), matching: find.byType(Scrollable)).first,
      );
      expect(find.text(en.materialUnplannedHeading), findsOneWidget);
      expect(find.text('Site office plinth'), findsOneWidget);
      expect(find.text(en.materialReorderLine('35.000', '100.000', 'bag')), findsOneWidget);
    });

    testWidgets('a line opens figure by figure, in quantities only', (tester) async {
      final TestApp app = await loopApp(grants: <String>['procurement.view']);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialWastage);

      await tap(tester, k('wastage-row-11-5'));
      expect(find.text('50.000 cum'), findsOneWidget);
      expect(find.text(en.materialNormPer('8.4', 'bag', 'cum')), findsOneWidget);
      expect(find.text('2%'), findsOneWidget);
      expect(find.text('420.000 bag'), findsOneWidget);
      expect(find.text('428.400 bag'), findsOneWidget);
      expect(find.text('30.000 bag · 7.14%'), findsOneWidget);
      expect(find.byKey(k('wastage-beyond')), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
    });

    testWidgets('the basis for work done can be changed', (tester) async {
      final TestApp app = await loopApp(grants: <String>['procurement.view']);
      addTearDown(app.dispose);
      await pumpAt(tester, app, start: Routes.materialWastage);

      await tap(tester, k('wastage-basis-approved'));
      await tap(tester, k('wastage-basis-dpr'));
      expect(app.api.reconciliationReads, <String?>['measured', 'approved', 'dpr']);
    });

    testWidgets('it needs procurement.view and the plan feature, or the way in is hidden and the page goes Home', (tester) async {
      Future<void> expectClosed(TestApp app) async {
        addTearDown(app.dispose);
        await pumpAt(tester, app);
        expect(find.byKey(k('material-open-wastage')), findsNothing);
        final AppGate gate = AppGate(app.services);
        await pumpDrift(tester);
        expect(gate.redirect(Uri.parse(Routes.materialWastage)), Routes.home);
        addTearDown(gate.close);
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }

      // An older server does not send the key at all.
      await expectClosed(await loopApp());
      await expectClosed(await loopApp(grants: <String>['procurement.view'], procurement: false));

      final TestApp open = await loopApp(grants: <String>['procurement.view']);
      addTearDown(open.dispose);
      final AppGate gate = AppGate(open.services);
      addTearDown(gate.close);
      await pumpDrift(tester);
      expect(gate.redirect(Uri.parse(Routes.materialWastage)), isNull);
    });

    testWidgets('it reads in Marathi and Hindi without overflow', (tester) async {
      for (final String locale in <String>['mr', 'hi']) {
        final TestApp app = await loopApp(grants: <String>['procurement.view', 'material_control.bills']);
        addTearDown(app.dispose);
        final AppLocalizations l = lookupAppLocalizations(Locale(locale));
        await pumpAt(tester, app, start: Routes.materialWastage, locale: locale);
        expect(find.text(l.materialWastageTitle), findsOneWidget);
        expect(find.text(l.materialWastageOverCount(1)), findsOneWidget);
        await tap(tester, k('wastage-row-11-5'));
        expect(find.text(l.materialAllowed), findsOneWidget);
        expect(tester.takeException(), isNull);

        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
        await pumpAt(tester, app, start: Routes.materialBills, locale: locale);
        expect(find.text(l.materialBillsWaitingCount(2)), findsOneWidget);
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }
    });
  });

  group('what the server sends', () {
    test('a challan without bill keys carries no bill, whatever else it says', () {
      final ChallanDetail site = ChallanDetail.fromJson(<String, dynamic>{
        'id': 4,
        'material_id': 5,
        'qty': '10.000',
        'bill_status': 'attached',
        'status': 'POSTED',
        'shorts': <Map<String, dynamic>>[
          <String, dynamic>{'id': 1, 'short_qty': '2.000', 'at_entry': true, 'status': 'POSTED'},
          <String, dynamic>{'id': 2, 'short_qty': '1.500', 'at_entry': false, 'status': 'POSTED'},
          <String, dynamic>{'id': 3, 'short_qty': '4.000', 'at_entry': false, 'status': 'REVERSED'},
        ],
      });
      expect(site.bill, isNull);
      expect(site.billPending, isFalse);
      expect(site.shortAfterEntry, Qty.parse('1.500'));

      final ChallanDetail office = ChallanDetail.fromJson(<String, dynamic>{
        'id': 4,
        'material_id': 5,
        'qty': '10.000',
        'bill_status': 'attached',
        'bill_no': null,
        'bill_amount': '120.00',
        'has_bill_file': false,
      });
      expect(office.bill!.amount, Money.parse('120.00'));
      expect(office.bill!.hasFile, isFalse);
    });

    test('the reconciliation keeps the server\'s verdict and works out what is beyond the allowance', () {
      final WastageRow row = WastageRow.fromJson(<String, dynamic>{
        'boq_item_id': 1,
        'material_id': 2,
        'executed': '50.000',
        'actual': '450.000',
        'theoretical': '420.000',
        'allowed': '428.400',
        'variance': '30.000',
        'variance_pct': '7.14',
        'status': 'wastage',
      });
      expect(row.status, WastageStatus.wastage);
      expect(row.beyondAllowed, Qty.parse('21.600'));

      final WastageRow within = WastageRow.fromJson(<String, dynamic>{
        'boq_item_id': 1,
        'material_id': 2,
        'executed': '50.000',
        'actual': '428.400',
        'allowed': '428.400',
        'status': 'ok',
      });
      expect(within.beyondAllowed, isNull);
      expect(
        WastageRow.fromJson(<String, dynamic>{'boq_item_id': 1, 'material_id': 2, 'status': 'something new'}).status,
        WastageStatus.ok,
      );
    });

    test('a delivery remembers its id and whether its bill is awaited', () {
      final MaterialDelivery pending = MaterialDelivery.fromJson(<String, dynamic>{
        'id': 9,
        'material_id': 5,
        'qty': '1.000',
        'bill_status': 'pending',
        'status': 'POSTED',
      });
      expect(pending.id, 9);
      expect(pending.billPending, isTrue);

      final MaterialDelivery reversed = MaterialDelivery.fromJson(<String, dynamic>{
        'id': 9,
        'material_id': 5,
        'qty': '1.000',
        'bill_status': 'pending',
        'status': 'REVERSED',
      });
      expect(reversed.billPending, isFalse);
      expect(reversed.reversed, isTrue);
    });
  });
}
