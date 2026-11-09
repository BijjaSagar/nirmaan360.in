import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/design/design.dart';
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

    expect(tester.getSize(find.byType(GloveButton)).height, greaterThanOrEqualTo(N360Size.tap));
    expect(tester.getSize(find.text('मराठी')).height, lessThan(N360Size.tap));
    expect(
      tester.getSize(find.byWidgetPredicate((w) => w is Semantics && w.properties.label == 'मराठी')).height,
      greaterThanOrEqualTo(N360Size.tap),
    );
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

  testWidgets('success goes to home through the first pull', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await fillAndSubmit(tester);

    expect(find.byType(HomeScreen), findsOneWidget);
  });
}
