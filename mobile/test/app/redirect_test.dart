import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
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
}
