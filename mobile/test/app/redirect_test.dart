import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/auth/view/sign_up_screen.dart';
import 'package:nirmaan360/features/contractor_portal/view/portal_home_screen.dart';
import 'package:nirmaan360/features/settings/view/account_deletion_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/top_up_screen.dart';
import 'package:nirmaan360/features/petty_cash/view/wallet_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_form_screen.dart';
import 'package:nirmaan360/features/projects/view/boq_screen.dart';
import 'package:nirmaan360/features/projects/view/project_form_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../support/test_app.dart';
import 'shell_test_helpers.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  testWidgets('signed out goes to sign-in', (tester) async {
    final app = await TestApp.create(seed: false, signedIn: false);
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.shellSignInExpired), findsNothing);
    expect(find.text(en.shellSignInWelcome), findsOneWidget);
  });

  testWidgets('expired goes to sign-in and says nothing was lost', (tester) async {
    final app = await TestApp.create(seed: false, signedIn: false);
    addTearDown(app.dispose);
    await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
    await app.services.session.markExpired();

    await pumpShellApp(tester, app);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.shellSignInExpired), findsOneWidget);
    expect(app.services.session.current.status, SessionStatus.expired);
  });

  testWidgets('a 401 during use lands on sign-in with the explanation', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);
    expect(find.byType(HomeScreen), findsOneWidget);

    await app.services.session.markExpired();
    await settle(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.shellSignInExpired), findsOneWidget);
  });

  testWidgets('signed in with several projects and none chosen goes to the picker', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api
      ..seedDemo()
      ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights');
    await app.sync();

    await pumpShellApp(tester, app);

    expect(find.byType(ProjectPickerScreen), findsOneWidget);
    expect(find.byType(HomeScreen), findsNothing);
  });

  testWidgets('one project skips the picker and is selected', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);

    await pumpShellApp(tester, app);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(find.byType(ProjectPickerScreen), findsNothing);
    await tester.pump();
    expect(await app.services.projects.readSelectedProjectId(), 1);
  });

  testWidgets('a chosen project opens home straight away', (tester) async {
    final app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api
      ..seedDemo()
      ..addProject(id: 2, code: 'MH-07', name: 'Mahalaxmi Heights');
    await app.sync();
    await app.services.projects.selectProject(2);

    await pumpShellApp(tester, app);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(find.text('Mahalaxmi Heights'), findsOneWidget);
  });

  testWidgets('signing out returns to sign-in', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app);

    await app.services.auth.signOut();
    await settle(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('approvals are hidden and guarded without permission', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.approvals);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(find.text(en.shellTabApprovals), findsNothing);
  });

  testWidgets('approvals tab shows with permission', (tester) async {
    final app = await TestApp.create(seed: false, signedIn: false);
    addTearDown(app.dispose);
    (app.api.user['can'] as Map)['measurement.approve'] = true;
    await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
    app.api.seedDemo();
    await app.sync();

    await pumpShellApp(tester, app);

    expect(find.text(en.shellTabApprovals), findsWidgets);
  });

  group('setup routes', () {
    Future<TestApp> withPermissions(Map<String, bool> permissions) async {
      final app = await TestApp.create(seed: false, signedIn: false);
      (app.api.user['can'] as Map).addAll(permissions);
      await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
      app.api.seedDemo();
      await app.sync();
      return app;
    }

    testWidgets('an owner with no project can open the project form', (tester) async {
      final app = await TestApp.create(seed: false, api: FakeApi()..grant('project.manage'));
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectForm);
      expect(find.byType(ProjectFormScreen), findsOneWidget);
    });

    testWidgets('without permission the project form is not reachable', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.projectForm);
      expect(find.byType(ProjectFormScreen), findsNothing);
      expect(find.byType(ProjectPickerScreen), findsOneWidget);
    });

    testWidgets('the BOQ form needs boq.manage', (tester) async {
      final app = await withPermissions(<String, bool>{'boq.manage': false});
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.boqForm);
      expect(find.byType(BoqFormScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('the BOQ list needs boq.view', (tester) async {
      final app = await withPermissions(<String, bool>{'boq.view': false});
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.boq);
      expect(find.byType(BoqScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('wallet and top-up need petty_cash.manage', (tester) async {
      final app = await withPermissions(<String, bool>{'petty_cash.manage': false});
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.walletForm);
      expect(find.byType(WalletFormScreen), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await pumpShellApp(tester, app, location: Routes.topUp);
      expect(find.byType(TopUpScreen), findsNothing);
      expect(find.byType(HomeScreen), findsOneWidget);
    });

    testWidgets('the setup routes open with the right permissions', (tester) async {
      final app = await withPermissions(<String, bool>{'boq.manage': true});
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.boqForm);
      expect(find.byType(BoqFormScreen), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      await pumpShellApp(tester, app, location: Routes.walletForm);
      expect(find.byType(WalletFormScreen), findsOneWidget);
    });
  });

  testWidgets('the app uses the stored language, else the server language', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, locale: 'mr');

    expect(find.text('मुख्य'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('falls back to the user locale when none is stored', (tester) async {
    final app = await TestApp.create(seed: false, signedIn: false);
    addTearDown(app.dispose);
    app.api.user['locale'] = 'hi';
    await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
    app.api.seedDemo();
    await app.sync();

    await pumpShellApp(tester, app);

    expect(find.text('होम'), findsOneWidget);
  });

  group('signing up and deleting an account', () {
    testWidgets('sign-up is open while signed out', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.signUp);
      expect(find.byType(SignUpScreen), findsOneWidget);
    });

    testWidgets('signed in, sign-up goes on to the app', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.signUp);
      expect(find.byType(SignUpScreen), findsNothing);
      expect(find.byType(ProjectPickerScreen), findsOneWidget);
    });

    testWidgets('a sign-up in flight holds the screen through the first pull', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.signUp);
      final AppGate gate = tester.element(find.byType(SignUpScreen)).read<AppGate>();

      gate.beginSignIn();
      await app.services.session.startSession('t', CurrentUser.fromJson(app.api.user));
      await settle(tester);
      expect(find.byType(SignUpScreen), findsOneWidget);

      gate.endSignIn();
      await settle(tester);
      expect(find.byType(SignUpScreen), findsNothing);
      expect(find.byType(ProjectPickerScreen), findsOneWidget);
    });

    testWidgets('deleting the account is open with no project yet', (tester) async {
      final app = await TestApp.create(seed: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.deleteAccount);
      expect(find.byType(AccountDeletionScreen), findsOneWidget);
    });

    testWidgets('a contractor login can delete its account too', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false, api: FakeApi()..becomeContractor());
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'contractor@demo.test', password: 'password');
      await pumpShellApp(tester, app, location: Routes.deleteAccount);
      expect(find.byType(AccountDeletionScreen), findsOneWidget);
      expect(find.byType(PortalHomeScreen), findsNothing);
    });
  });
}
