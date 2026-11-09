import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/core/sync/background_sync.dart';
import 'package:nirmaan360/core/sync/sync_status.dart';
import 'package:nirmaan360/features/auth/data/auth_repository.dart' show SignInResult;
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/contractor_portal/data/portal_repository.dart';
import 'package:nirmaan360/features/contractor_portal/view/portal_bill_screen.dart';
import 'package:nirmaan360/features/contractor_portal/view/portal_contract_screen.dart';
import 'package:nirmaan360/features/contractor_portal/view/portal_home_screen.dart';
import 'package:nirmaan360/features/contractor_portal/view/portal_lists_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';
import 'package:nirmaan360/features/settings/view/settings_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ServerFailure _refused = ServerFailure('This action is unauthorized.', statusCode: 403);

/// A phone where a contractor login has signed in, as the app really does it.
Future<TestApp> contractorApp({void Function(FakeApi api)? setup, bool signIn = true}) async {
  final FakeApi server = FakeApi()..becomeContractor();
  setup?.call(server);
  final TestApp app = await TestApp.create(seed: false, signedIn: false, api: server);
  if (signIn) await app.services.auth.signIn(email: 'contractor@demo.test', password: 'password');
  return app;
}

Key k(String value) => ValueKey<String>(value);

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('signing in', () {
    test('a contractor reads the portal and nothing is pulled or pushed', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      expect(app.services.session.user!.isContractor, isTrue);
      expect(app.api.pullCalls, isEmpty);
      expect(app.api.pushCalls, isEmpty);
      expect(app.api.portalReads, greaterThan(0));
      expect(app.api.meReads, 1);
      expect(await app.db.readMeta(MetaKey.watermark), isNull);
      expect(await app.db.readMeta(MetaKey.lastSyncedAt), isNull);
    });

    test('the session remembers it is a contractor, so a restart offline lands on the portal', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.offline = true;
      final SessionState restored = await app.services.session.restore();

      expect(restored.status, SessionStatus.signedIn);
      expect(restored.user!.isContractor, isTrue);
      expect(restored.user!.contractorPartyId, 2);
      expect(restored.user!.contractorName, 'Shree Formwork Works');
    });

    test('a failed first read still signs in, and the portal reads again when it opens', () async {
      final TestApp app = await contractorApp(signIn: false);
      addTearDown(app.dispose);
      app.api.portalFailure = const ServerFailure('boom', statusCode: 500);

      final SignInResult result = await app.services.auth.signIn(email: 'contractor@demo.test', password: 'password');

      expect(result.firstSync.outcome, SyncOutcome.serverError);
      expect(app.services.session.current.status, SessionStatus.signedIn);
      expect(await app.services.portal.read(), isNull);

      app.api.portalFailure = null;
      await app.services.portal.refresh();
      expect(await app.services.portal.read(), isNotNull);
    });
  });

  group('reading', () {
    test('keeps the overview, payments, bills and the details for offline use', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      final PortalSnapshot seen = (await app.services.portal.read())!;
      expect(seen.refused, isFalse);
      expect(seen.contractorName, 'Shree Formwork Works');
      expect(seen.summary.contracts, 2);
      expect(seen.summary.contractsAhead, 1);
      expect(seen.summary.figures.paid!.toWire(), '1200000.00');
      expect(seen.summary.figures.paidAhead, isTrue);
      expect(seen.contracts.map((PortalContract c) => c.number), <String>['WO-KT-B-001', 'WO-MH-07-004']);
      expect(seen.contracts.first.deadlineLabel, 'Before Lakshmi Puja');
      expect(seen.payments.map((PortalPayment p) => p.number), <String>[
        'CP-2026-27-0003',
        'CP-2026-27-0002',
        'CP-2026-27-0001',
      ]);
      expect(seen.payments.last.isAdvance, isTrue);
      expect(seen.bills.map((PortalBill b) => b.status), <String?>['APPROVED', 'PAID']);

      final PortalContractDetail detail = seen.details[1]!;
      expect(detail.lines.single.doneQty!.toWire(), '465.250');
      expect(detail.parts.map((PortalPart p) => p.state), <String?>['paid', 'part_paid']);
      expect(detail.payments, hasLength(3));
      expect(detail.bills, hasLength(2));

      final PortalBill opened = seen.billDetails[2]!;
      expect(opened.lines!.single.currentQty!.toWire(), '50.000');
      expect(opened.deductions!.single.label, 'Retention');
      expect(seen.bill(1)!.isPaid, isTrue);
    });

    test('the last answer stays when the server cannot be reached', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.offline = true;
      await expectLater(app.services.portal.refresh(), throwsA(isA<ApiFailure>()));

      expect((await app.services.portal.read())!.contracts, hasLength(2));
    });

    test('a server error keeps the last answer too', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.portalFailure = const ServerFailure('boom', statusCode: 500);
      await expectLater(app.services.portal.refresh(), throwsA(isA<ServerFailure>()));

      expect((await app.services.portal.read())!.refused, isFalse);
    });

    test('a refusal replaces what was kept, and is not an error', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.portalFailure = _refused;
      await app.services.portal.refresh();

      final PortalSnapshot seen = (await app.services.portal.read())!;
      expect(seen.refused, isTrue);
      expect(seen.contracts, isEmpty);
      expect(seen.payments, isEmpty);
    });

    test('a login without the permission or the plan does not ask', () async {
      final TestApp noPermission = await contractorApp(
        setup: (FakeApi api) => (api.user['can'] as Map)['contractor_portal.view'] = false,
      );
      addTearDown(noPermission.dispose);
      final TestApp noPlan = await contractorApp(
        setup: (FakeApi api) => ((api.user['organization'] as Map)['features'] as List).remove('contractor_portal'),
      );
      addTearDown(noPlan.dispose);

      expect(noPermission.api.portalReads, 0);
      expect(noPlan.api.portalReads, 0);
      expect((await noPermission.services.portal.read())!.refused, isTrue);
      expect((await noPlan.services.portal.read())!.refused, isTrue);
    });

    test('a change to the login reaches the phone: it is a member of staff now', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final int before = app.api.portalReads;

      app.api.user = FakeApi().user;
      await app.services.portal.refresh();

      expect(app.services.session.user!.isContractor, isFalse);
      expect(app.api.portalReads, before);
    });

    test('another contractor\'s copy is never shown', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final Map<String, dynamic> stored = (jsonDecode((await app.db.readMeta(MetaKey.portal))!) as Map).cast<String, dynamic>();
      stored['party_id'] = 99;
      await app.db.writeMeta(MetaKey.portal, jsonEncode(stored));

      expect(await app.services.portal.read(), isNull);
    });

    test('PHP\'s empty array where a map belongs does not stop it opening', () async {
      final TestApp app = await contractorApp(
        setup: (FakeApi api) {
          api.portalOverviewBody = <String, dynamic>{'contractor': <Object>[], 'summary': <Object>[], 'contracts': <Object>[]};
          api.portalPaymentRows = <Map<String, dynamic>>[];
          api.portalBillRows = <Map<String, dynamic>>[];
        },
      );
      addTearDown(app.dispose);

      final PortalSnapshot seen = (await app.services.portal.read())!;
      expect(seen.contracts, isEmpty);
      expect(seen.summary.contracts, 0);
      expect(seen.summary.figures.paid, isNull);
    });

    test('a contract the server stops listing does not linger', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      expect((await app.services.portal.read())!.details.keys, containsAll(<int>[1, 5]));

      (app.api.portalOverviewBody['contracts'] as List).removeWhere((Object? row) => (row as Map)['id'] == 5);
      await app.services.portal.refresh();

      expect((await app.services.portal.read())!.details.keys, <int>[1]);
    });

    test('opening a contract reads that contract again', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final int before = app.api.portalContractReads;

      await app.services.portal.refreshContract(1);
      await app.services.portal.refreshBill(2);

      expect(app.api.portalContractReads, before + 1);
      expect(app.api.portalBillReads, greaterThan(0));
    });

    test('the copy on the phone round-trips as stored JSON', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final Object? stored = jsonDecode((await app.db.readMeta(MetaKey.portal))!);
      expect((stored as Map).keys, containsAll(<String>['party_id', 'overview', 'payments', 'bills', 'contracts', 'bill_details', 'fetched_at']));
    });

    test('signing out clears it', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      await app.services.auth.signOut();

      expect(await app.db.readMeta(MetaKey.portal), isNull);
      expect(app.services.session.current.status, SessionStatus.signedOut);
    });

    test('a staff login after a contractor on the same phone starts clean', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      app.api.user = FakeApi().user;
      app.api.seedDemo();
      await app.services.auth.signIn(email: 'engineer@demo.test', password: 'password');

      expect(await app.db.readMeta(MetaKey.portal), isNull);
      expect(app.services.session.user!.isContractor, isFalse);
      expect(app.api.pullCalls, isNotEmpty);
    });
  });

  group('nothing for the site team runs for a contractor', () {
    test('the sync engine makes no call, whatever asks it to run', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      // A pull would fail loudly: the server refuses a contractor with a 403.
      app.api.failNextPull(_refused);
      app.api.failNextPush(_refused);

      final SyncRunResult result = await app.services.sync.syncNow();

      expect(result.isSuccess, isTrue);
      expect(app.api.pullCalls, isEmpty);
      expect(app.api.pushCalls, isEmpty);
      expect(app.services.session.current.status, SessionStatus.signedIn);
    });

    test('the timer, a resume and a return of the network all end at the same no-op', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.services.scheduler.start();

      app.services.scheduler.onResume();
      app.connectivity
        ..setOnline(false)
        ..setOnline(true);
      await Future<void>.delayed(const Duration(milliseconds: 150));

      expect(app.api.pullCalls, isEmpty);
      expect(app.api.pushCalls, isEmpty);
      expect(app.services.session.current.status, SessionStatus.signedIn);
    });

    test('the background task, which has no session of its own, makes no call either', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      final bool ok = await runBackgroundSyncOnce(
        db: app.db,
        api: app.api,
        attachments: app.attachments,
        connectivity: app.connectivity,
      );

      expect(ok, isTrue);
      expect(app.api.pullCalls, isEmpty);
      expect(app.api.pushCalls, isEmpty);
    });

    test('the afterPull refreshes do nothing for a login with no site permission', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);

      await app.services.progress.refreshCurrent(force: true);
      await app.services.material.refreshCurrent(force: true);
      await app.services.siteAlerts.refreshCurrent(force: true);
      await app.services.contractors.refreshCurrent(force: true);

      expect(app.api.pullCalls, isEmpty);
    });
  });

  group('staff behaviour is the same as before', () {
    test('a staff sign-in pulls once and never touches the portal', () async {
      final FakeApi server = FakeApi()..seedDemo();
      final TestApp app = await TestApp.create(seed: false, signedIn: false, api: server);
      addTearDown(app.dispose);

      await app.services.auth.signIn(email: 'engineer@demo.test', password: 'password');

      expect(server.pullCalls, hasLength(1));
      expect(server.portalReads, 0);
      expect(server.meReads, 0);
      expect(app.services.session.user!.isContractor, isFalse);
      expect(await app.db.readMeta(MetaKey.portal), isNull);
      expect(await app.db.readMeta(MetaKey.watermark), isNotNull);
    });

    test('a user saved by an older build, with no kind, is staff', () {
      final Map<String, dynamic> old = Map<String, dynamic>.of(FakeApi().user)
        ..remove('kind')
        ..remove('contractor');
      final CurrentUser user = CurrentUser.fromJson(old);

      expect(user.isContractor, isFalse);
      expect(user.kind, CurrentUser.staffKind);
      expect(user.contractorPartyId, isNull);
      expect(CurrentUser.fromJson(user.toJson()).isContractor, isFalse);
    });

    test('a contractor survives the round trip through the saved user', () {
      final FakeApi server = FakeApi()..becomeContractor();
      final CurrentUser user = CurrentUser.fromJson(server.user);
      final CurrentUser again = CurrentUser.fromJson(user.toJson());

      expect(again.isContractor, isTrue);
      expect(again.contractorPartyId, 2);
      expect(again.contractorName, 'Shree Formwork Works');
      expect(again.can('contractor_portal.view'), isTrue);
      expect(again.can('dpr.view'), isFalse);
    });

    test('the sync engine still pulls and pushes for staff', () async {
      final TestApp app = await TestApp.create();
      addTearDown(app.dispose);
      final int before = app.api.pullCalls.length;

      await app.sync();

      expect(app.api.pullCalls.length, before + 1);
    });
  });

  group('where each kind of login may go', () {
    ProjectRow project(int id) => ProjectRow(
      id: id,
      code: 'P$id',
      name: 'Project $id',
      location: null,
      status: 'active',
      startDate: null,
      endDate: null,
      measurementTolerancePct: '5.00',
      updatedAt: null,
      imageUrl: null,
    );

    final List<String> paths = <String>[
      Routes.signIn,
      Routes.projects,
      Routes.projectsSwitch,
      Routes.projectForm,
      Routes.boq,
      Routes.boqForm,
      Routes.home,
      Routes.outbox,
      Routes.settings,
      Routes.dprList,
      Routes.dprForm,
      Routes.dprDetail('abc'),
      Routes.measurements,
      Routes.measurementForm,
      Routes.measurementDetail('abc'),
      Routes.approvals,
      Routes.approvalDetail(3),
      Routes.attendance,
      Routes.issues,
      Routes.issueForm,
      Routes.issueDetail('abc'),
      Routes.pettyCash,
      Routes.expenseForm,
      Routes.walletForm,
      Routes.topUp,
      Routes.progress,
      Routes.progressForm,
      Routes.progressFloor(2),
      Routes.siteDashboard,
      Routes.contractors,
      Routes.contractorRequest,
      Routes.contractorDetail(4),
      Routes.material,
      Routes.materialReceive,
      Routes.materialUse,
      Routes.materialCount,
      '/something-unknown',
    ];

    final List<String> portalPaths = <String>[
      Routes.portal,
      Routes.portalContract(1),
      Routes.portalPayments,
      Routes.portalBills,
      Routes.portalBill(2),
    ];

    // Every permission a path can ask for, so each can be given on its own.
    final List<String> permissions = <String>[
      'project.manage',
      'boq.view',
      'boq.manage',
      'petty_cash.manage',
      'progress.view',
      'progress.create',
      'contractor.view',
      'contractor.request',
      'material_control.view',
      'material_control.challan',
      'material_control.consume',
      'material_control.count',
      'measurement.approve',
      'dpr.approve',
      'ra_bill.approve',
    ];

    // The site team's redirect exactly as it was before the portal existed.
    const Map<String, String> legacyPermissions = <String, String>{
      '/projects/new': 'project.manage',
      '/boq': 'boq.view',
      '/boq/form': 'boq.manage',
      '/petty-cash/wallet': 'petty_cash.manage',
      '/petty-cash/top-up': 'petty_cash.manage',
      '/progress': 'progress.view',
      '/progress/form': 'progress.create',
      '/site': 'progress.view',
      '/contractors': 'contractor.view',
      '/contractors/request': 'contractor.request',
      '/material': 'material_control.view',
      '/material/receive': 'material_control.challan',
      '/material/use': 'material_control.consume',
      '/material/count': 'material_control.count',
    };

    String? legacyRedirect(GateState s, Uri uri) {
      final String path = uri.path;
      final bool atSignIn = path == '/sign-in';
      if (!s.isSignedIn) return atSignIn ? null : '/sign-in';
      if (atSignIn) {
        if (s.signingIn) return null;
        return s.hasProject ? '/home' : '/projects';
      }
      if (!s.hasProject) {
        if (path == '/projects' || path == '/settings') return null;
        if (path == '/projects/new' && s.canManageProjects) return null;
        return '/projects';
      }
      if (path == '/projects' && s.onlyOneProject) {
        final bool onPurpose = uri.queryParameters['switch'] == '1';
        if (!(onPurpose && s.canManageProjects)) return '/home';
      }
      if (!s.canApprove && path.startsWith('/approvals')) return '/home';
      final String? needed = legacyPermissions[path];
      if (needed != null && !s.can(needed)) return '/home';
      return null;
    }

    CurrentUser staff(Set<String> given) {
      final Map<String, dynamic> json = Map<String, dynamic>.of(FakeApi().user);
      json['can'] = <String, bool>{for (final String p in permissions) p: given.contains(p)};
      return CurrentUser.fromJson(json);
    }

    test('for the site team every path goes exactly where it went before', () async {
      final TestApp app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      final AppGate gate = AppGate(app.services);
      addTearDown(gate.close);

      final List<Set<String>> grants = <Set<String>>[
        <String>{},
        permissions.toSet(),
        for (final String p in permissions) <String>{p},
      ];
      final List<List<ProjectRow>> projectLists = <List<ProjectRow>>[
        <ProjectRow>[],
        <ProjectRow>[project(1)],
        <ProjectRow>[project(1), project(2)],
      ];
      var compared = 0;
      for (final SessionStatus status in <SessionStatus>[
        SessionStatus.signedIn,
        SessionStatus.signedOut,
        SessionStatus.expired,
      ]) {
        for (final bool signingIn in <bool>[false, true]) {
          for (final List<ProjectRow> projects in projectLists) {
            for (final int? selected in <int?>[null, 1]) {
              for (final Set<String> given in grants) {
                final GateState state = GateState(
                  status: status,
                  user: staff(given),
                  projects: projects,
                  selectedProjectId: selected,
                  selectionKnown: true,
                  localeKnown: true,
                  signingIn: signingIn,
                );
                // The gate answers from its own state, so give it this one.
                gate.emit(state);
                for (final String path in paths) {
                  final Uri uri = Uri.parse(path);
                  expect(
                    gate.redirect(uri),
                    legacyRedirect(state, uri),
                    reason: '$path as $status, signingIn $signingIn, ${projects.length} projects, picked $selected, $given',
                  );
                  compared++;
                }
              }
            }
          }
        }
      }
      expect(compared, greaterThan(5000));
    });

    test('the site team has no business in the portal and is sent where a missing page used to land', () async {
      final TestApp app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      final AppGate gate = AppGate(app.services);
      addTearDown(gate.close);

      gate.emit(GateState(status: SessionStatus.signedIn, user: staff(<String>{}), projects: <ProjectRow>[project(1)], selectedProjectId: 1, selectionKnown: true, localeKnown: true));
      for (final String path in portalPaths) {
        expect(gate.redirect(Uri.parse(path)), Routes.home, reason: path);
      }
      gate.emit(GateState(status: SessionStatus.signedIn, user: staff(<String>{}), projects: <ProjectRow>[], selectionKnown: true, localeKnown: true));
      for (final String path in portalPaths) {
        expect(gate.redirect(Uri.parse(path)), Routes.projects, reason: path);
      }
      gate.emit(const GateState(status: SessionStatus.signedOut, projects: <ProjectRow>[], selectionKnown: true, localeKnown: true));
      for (final String path in portalPaths) {
        expect(gate.redirect(Uri.parse(path)), Routes.signIn, reason: path);
      }
    });

    test('a contractor reaches the portal and settings, and everything else is the portal', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final AppGate gate = AppGate(app.services);
      addTearDown(gate.close);
      final CurrentUser user = CurrentUser.fromJson(app.api.user);

      // Even a phone that somehow holds projects: they are not shown.
      for (final List<ProjectRow> projects in <List<ProjectRow>>[<ProjectRow>[], <ProjectRow>[project(1)], <ProjectRow>[project(1), project(2)]]) {
        gate.emit(GateState(status: SessionStatus.signedIn, user: user, projects: projects, selectedProjectId: projects.isEmpty ? null : 1, selectionKnown: true, localeKnown: true));
        for (final String path in paths) {
          final Uri uri = Uri.parse(path);
          final String? expected = path == Routes.signIn
              ? Routes.portal
              : path == Routes.settings
              ? null
              : Routes.portal;
          expect(gate.redirect(uri), expected, reason: path);
        }
        for (final String path in portalPaths) {
          expect(gate.redirect(Uri.parse(path)), isNull, reason: path);
        }
      }
    });

    test('a contractor stays on the sign-in screen while it signs in, then goes to the portal', () async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      final AppGate gate = AppGate(app.services);
      addTearDown(gate.close);
      final CurrentUser user = CurrentUser.fromJson(app.api.user);

      gate.emit(GateState(status: SessionStatus.signedIn, user: user, projects: const <ProjectRow>[], selectionKnown: true, localeKnown: true, signingIn: true));
      expect(gate.redirect(Uri.parse(Routes.signIn)), isNull);
      gate.emit(GateState(status: SessionStatus.signedIn, user: user, projects: const <ProjectRow>[], selectionKnown: true, localeKnown: true));
      expect(gate.redirect(Uri.parse(Routes.signIn)), Routes.portal);
    });
  });

  group('screens', () {
    testWidgets('home is the portal: no project picker, no tabs, no sync tag', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.byType(PortalHomeScreen), findsOneWidget);
      expect(find.byType(HomeScreen), findsNothing);
      expect(find.byType(ProjectPickerScreen), findsNothing);
      expect(find.byType(N360BottomBar), findsNothing);
      expect(find.text(en.shellTabMeasure), findsNothing);
      expect(find.text(en.shellTabOutbox), findsNothing);
      expect(find.text('Shree Formwork Works'), findsWidgets);
      expect(find.text('Sahyadri Infra Pvt Ltd'), findsOneWidget);
      expect(find.text(en.shellSettings), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('home shows the figures, the way to payments and bills, and each contract', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.text('₹21,33,000'), findsOneWidget);
      expect(find.text(en.portalPaidVsWork('56.26%', '46.88%')), findsOneWidget);
      expect(find.text(en.contractorPaidAhead), findsOneWidget);
      expect(find.text(en.portalPaymentsCount(3)), findsOneWidget);
      expect(find.text(en.portalBillsCount(2)), findsOneWidget);
      expect(find.byKey(k('portal-contract-1')), findsOneWidget);
      expect(find.byKey(k('portal-contract-5')), findsOneWidget);
      expect(find.text('Kalyani Tower B'), findsOneWidget);
      expect(find.textContaining(en.portalDaysLeft(10)), findsOneWidget);
      expect(find.text(en.portalOverdueBy(8)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a contractor cannot be put on a site screen, however it is asked for', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      for (final String location in <String>[Routes.projects, Routes.home, Routes.dprList, Routes.approvals, Routes.contractors]) {
        await pumpShellApp(tester, app, location: location);
        expect(find.byType(PortalHomeScreen), findsOneWidget, reason: location);
        await tester.pumpWidget(const SizedBox.shrink());
      }
    });

    testWidgets('opens with no network from the last copy', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app);

      expect(find.byKey(k('portal-contract-1')), findsOneWidget);
      expect(find.text('₹21,33,000'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a contract opens with its lines, parts, payments and bills, offline too', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app);

      await tester.tap(find.byKey(k('portal-contract-1')));
      await settle(tester);

      expect(find.byType(PortalContractScreen), findsOneWidget);
      expect(find.text('2.1 · RCC M25 in slabs and beams including shuttering'), findsOneWidget);
      expect(find.text('Plinth complete'), findsOneWidget);
      expect(find.text('First slab'), findsOneWidget);
      // The page is a lazy list: scroll to what is below the fold.
      for (final String key in <String>['portal-payment-3', 'portal-bill-2']) {
        await tester.scrollUntilVisible(find.byKey(k(key)), 300, scrollable: find.byType(Scrollable).first);
        expect(find.byKey(k(key)), findsOneWidget);
      }
      expect(tester.takeException(), isNull);
    });

    testWidgets('a bill opens with its lines and deductions', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app, location: Routes.portalBill(2));

      expect(find.byType(PortalBillScreen), findsOneWidget);
      expect(find.text('WO-KT-B-001/RA/2'), findsWidgets);
      expect(find.text(en.statusApproved), findsOneWidget);
      expect(find.text('₹1,90,000'), findsWidgets);
      expect(find.text('Retention'), findsOneWidget);
      expect(find.text('5.00%'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.pumpWidget(const SizedBox.shrink());
      await pumpShellApp(tester, app, location: Routes.portalBill(1));
      expect(find.text(en.contractorPaid), findsWidgets);
      expect(find.text(en.statusApproved), findsNothing);
    });

    testWidgets('payments and bills list everything, newest first, with the advance named', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.portalPayments);

      expect(find.byType(PortalPaymentsScreen), findsOneWidget);
      expect(find.byKey(k('portal-payment-3')), findsOneWidget);
      expect(find.byKey(k('portal-payment-1')), findsOneWidget);
      expect(find.text(en.contractorAdvance), findsOneWidget);
      expect(tester.getTopLeft(find.byKey(k('portal-payment-3'))).dy, lessThan(tester.getTopLeft(find.byKey(k('portal-payment-1'))).dy));

      await tester.pumpWidget(const SizedBox.shrink());
      await pumpShellApp(tester, app, location: Routes.portalBills);
      expect(find.byType(PortalBillsScreen), findsOneWidget);
      expect(find.byKey(k('portal-bill-2')), findsOneWidget);
      expect(find.byKey(k('portal-bill-1')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('an empty portal says so', (WidgetTester tester) async {
      final TestApp app = await contractorApp(
        setup: (FakeApi api) {
          api.portalOverviewBody = <String, dynamic>{'contractor': <String, dynamic>{'party_id': 2, 'name': 'Shree Formwork Works'}, 'summary': <String, dynamic>{}, 'contracts': <Object>[]};
          api.portalPaymentRows = <Map<String, dynamic>>[];
          api.portalBillRows = <Map<String, dynamic>>[];
        },
      );
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);
      expect(find.text(en.contractorEmptyTitle), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await pumpShellApp(tester, app, location: Routes.portalPayments);
      expect(find.text(en.portalNoPaymentsTitle), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await pumpShellApp(tester, app, location: Routes.portalBills);
      expect(find.text(en.portalNoBillsTitle), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a 403 is a calm page, not an error', (WidgetTester tester) async {
      final TestApp app = await contractorApp(setup: (FakeApi api) => api.portalFailure = _refused);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.text(en.portalRefusedTitle), findsOneWidget);
      expect(find.text(en.portalRefusedMessage), findsOneWidget);
      expect(find.text(en.shellSettings), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('nothing on the phone and no network says to connect once', (WidgetTester tester) async {
      final TestApp app = await contractorApp(setup: (FakeApi api) => api.portalFailure = const ServerFailure('boom', statusCode: 500));
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      expect(find.text(en.portalNoDataMessage), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('settings is one tap away: language, who is signed in, sign out', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      await tester.tap(find.text(en.shellSettings));
      await settle(tester);

      expect(find.byType(SettingsScreen), findsOneWidget);
      expect(find.text('Ramesh Pawar'), findsOneWidget);
      expect(find.text('Shree Formwork Works'), findsOneWidget);
      expect(find.text(en.shellSignOut), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('signing out of the portal returns to sign-in', (WidgetTester tester) async {
      final TestApp app = await contractorApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app);

      await app.services.auth.signOut();
      await settle(tester);

      expect(find.byType(SignInScreen), findsOneWidget);
    });

    for (final String locale in <String>['mr', 'hi']) {
      for (final String location in <String>[
        Routes.portal,
        Routes.portalContract(1),
        Routes.portalContract(5),
        Routes.portalPayments,
        Routes.portalBills,
        Routes.portalBill(2),
        Routes.portalBill(1),
      ]) {
        testWidgets('$location fits 360dp in $locale', (WidgetTester tester) async {
          final TestApp app = await contractorApp();
          addTearDown(app.dispose);
          await pumpShellApp(tester, app, location: location, locale: locale);
          expect(tester.takeException(), isNull);
        });
      }

      testWidgets('home fits 360dp in $locale at a large text size', (WidgetTester tester) async {
        final TestApp app = await contractorApp();
        addTearDown(app.dispose);
        await pumpShellApp(tester, app, locale: locale, textScale: 1.3);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
