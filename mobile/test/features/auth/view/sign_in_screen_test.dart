import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/auth/view/sign_up_screen.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

class _DownApi extends FakeApi {
  @override
  Future<LoginResult> login({required String email, required String password, required String deviceName}) async {
    throw const ServerFailure('boom', statusCode: 500);
  }
}

void main() {
  final en = lookupAppLocalizations(const Locale('en'));
  final mr = lookupAppLocalizations(const Locale('mr'));

  Future<TestApp> create({FakeApi? api}) async {
    final app = await TestApp.create(seed: false, signedIn: false, api: api);
    app.api.seedDemo();
    return app;
  }

  Future<void> fillAndSubmit(WidgetTester tester, {String password = 'secret'}) async {
    await tester.enterText(find.byType(TextField).at(0), 'a@b.test');
    await tester.enterText(find.byType(TextField).at(1), password);
    await tester.pump();
    await tester.tap(find.text(en.shellSignInButton));
    await settle(tester);
  }

  testWidgets('no credentials are ever prefilled', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    final fields = tester.widgetList<TextField>(find.byType(TextField));
    expect(fields, hasLength(2));
    for (final field in fields) {
      expect(field.controller!.text, isEmpty);
    }
  });

  testWidgets('every control is at least 56dp tall', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    // Sign in, and the new-company button under the card.
    expect(find.byType(GloveButton), findsNWidgets(2));
    for (final Element button in find.byType(GloveButton).evaluate()) {
      expect(tester.getSize(find.byWidget(button.widget)).height, greaterThanOrEqualTo(N360Size.tap));
    }
    expect(tester.getSize(find.text('मराठी')).height, lessThan(N360Size.tap));
    expect(
      tester.getSize(find.byWidgetPredicate((w) => w is Semantics && w.properties.label == 'मराठी')).height,
      greaterThanOrEqualTo(N360Size.tap),
    );
  });

  testWidgets('opening the keyboard reflows nothing', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);
    final Finder email = find.byType(TextField).first;
    final Offset before = tester.getTopLeft(email);

    // The keyboard comes up with nothing focused: no field may move at all.
    tester.view.viewInsets = FakeViewPadding(bottom: 280 * tester.view.devicePixelRatio);
    addTearDown(tester.view.resetViewInsets);
    await settle(tester);

    expect(tester.getTopLeft(email), before);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the focused field stays visible above the keyboard', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);
    final Finder email = find.byType(TextField).first;
    final double before = tester.getTopLeft(email).dy;

    await tester.showKeyboard(email);
    tester.view.viewInsets = FakeViewPadding(bottom: 280 * tester.view.devicePixelRatio);
    addTearDown(tester.view.resetViewInsets);
    await settle(tester);

    final double screen = tester.view.physicalSize.height / tester.view.devicePixelRatio;
    final double bottom = tester.getBottomLeft(email).dy;
    expect(bottom, lessThanOrEqualTo(screen - 280));
    // It moved only by the scroll needed to clear the keyboard, not by a reflow.
    final double overlap = before + (bottom - tester.getTopLeft(email).dy) - (screen - 280);
    expect(before - tester.getTopLeft(email).dy, lessThanOrEqualTo(overlap + 24));
    expect(tester.takeException(), isNull);
  });

  testWidgets('missing fields say which one', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await tester.tap(find.text(en.shellSignInButton));
    await settle(tester);

    expect(find.text(en.shellSignInEmailRequired), findsOneWidget);
    expect(find.text(en.shellSignInPasswordRequired), findsOneWidget);
  });

  testWidgets('wrong password in plain words', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await fillAndSubmit(tester, password: 'wrong');

    expect(find.text(en.shellSignInWrongTitle), findsOneWidget);
  });

  testWidgets('no network in plain words', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    app.api.offline = true;
    await pumpShellApp(tester, app, location: Routes.signIn);

    await fillAndSubmit(tester);

    expect(find.text(en.shellSignInNetworkTitle), findsOneWidget);
  });

  testWidgets('server down in plain words', (tester) async {
    final app = await create(api: _DownApi());
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await fillAndSubmit(tester);

    expect(find.text(en.shellSignInServerTitle), findsOneWidget);
  });

  testWidgets('errors follow the chosen language', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    app.api.offline = true;
    await pumpShellApp(tester, app, location: Routes.signIn);

    await tester.tap(find.text('मराठी'));
    await settle(tester);

    expect(await app.services.auth.readLocale(), 'mr');
    expect(find.text(mr.shellSignInWelcome), findsOneWidget);
    await tester.enterText(find.byType(TextField).at(0), 'a@b.test');
    await tester.enterText(find.byType(TextField).at(1), 'secret');
    await tester.pump();
    await tester.tap(find.text(mr.shellSignInButton));
    await settle(tester);
    expect(find.text(mr.shellSignInNetworkTitle), findsOneWidget);
  });

  testWidgets('show password reveals the password', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    expect(tester.widget<TextField>(find.byType(TextField).at(1)).obscureText, isTrue);
    await tester.tap(find.text(en.shellSignInShow));
    await settle(tester);
    expect(tester.widget<TextField>(find.byType(TextField).at(1)).obscureText, isFalse);
    expect(find.text(en.shellSignInHide), findsOneWidget);
  });

  testWidgets('a new company is one tap away', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await tester.tap(find.byKey(const ValueKey<String>('sign-in-new-company')));
    await settle(tester);

    expect(find.byType(SignUpScreen), findsOneWidget);
  });

  testWidgets('the new-company button keeps the graphic out of the way of the keyboard', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn, size: const Size(360, 640));

    expect(find.text(en.shellSignInNewCompany), findsOneWidget);
    expect(tester.takeException(), isNull);
    final double screen = tester.view.physicalSize.height / tester.view.devicePixelRatio;
    expect(tester.getBottomLeft(find.text(en.shellSignInButton)).dy, lessThan(screen));
  });

  testWidgets('after a deletion it says so once', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.settings);

    await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');
    await settle(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.accountDeletedToast), findsOneWidget);
    // Not the "signed out, nothing is lost" note: that would be untrue.
    expect(find.text(en.shellSignInExpired), findsNothing);

    // The screen took it: a sign-in screen opened later says nothing.
    expect(app.services.auth.takeDeletedNotice(), isNull);
    await tester.pumpWidget(const SizedBox());
    await pumpShellApp(tester, app, location: Routes.signIn);
    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.accountDeletedToast), findsNothing);
  });

  testWidgets('a company set to be erased gives the date to sign in by', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.api.scheduleCompanyDeletion(eraseAfter: '2026-12-09T23:30:00+05:30');
    await pumpShellApp(tester, app, location: Routes.settings);

    await app.services.auth.deleteAccount(
      mode: AccountDeletionMode.company,
      password: 'secret',
      confirmName: 'Sahyadri Infra Pvt Ltd',
    );
    await settle(tester);

    expect(find.text(en.accountDeletedCompanyToast('9 Dec 2026')), findsOneWidget);
  });

  testWidgets('success goes to home through the first pull', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await fillAndSubmit(tester);

    expect(find.byType(HomeScreen), findsOneWidget);
  });
}
