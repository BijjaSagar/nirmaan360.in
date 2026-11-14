import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/config/app_config.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/auth/view/sign_up_screen.dart';
import 'package:nirmaan360/features/projects/view/project_picker_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  /// A phone nobody has signed in on, and a server with no company for them yet.
  Future<TestApp> create() => TestApp.create(seed: false, signedIn: false);

  Future<void> fill(
    WidgetTester tester, {
    String company = 'Deccan Builders',
    String name = 'Asha Patil',
    String email = 'asha@deccan.test',
    String phone = '',
    String password = 'site-office-8',
    String? confirm,
  }) async {
    final Finder fields = find.byType(TextField);
    await tester.enterText(fields.at(0), company);
    await tester.enterText(fields.at(1), name);
    await tester.enterText(fields.at(2), email);
    await tester.enterText(fields.at(3), phone);
    await tester.enterText(fields.at(4), password);
    await tester.enterText(fields.at(5), confirm ?? password);
    await tester.pump();
  }

  Future<void> submit(WidgetTester tester) async {
    await tester.tap(find.text(en.shellSignUpButton));
    await settle(tester);
  }

  /// Every piece of text on the screen, as drawn.
  List<String> shownText(WidgetTester tester) =>
      tester.widgetList<RichText>(find.byType(RichText)).map((r) => r.text.toPlainText()).toList();

  testWidgets('sign-in offers it, and it opens empty', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signIn);

    await tester.tap(find.text(en.shellSignInNewCompany));
    await settle(tester);

    expect(find.byType(SignUpScreen), findsOneWidget);
    final fields = tester.widgetList<TextField>(find.byType(TextField));
    expect(fields, hasLength(6));
    for (final field in fields) {
      expect(field.controller!.text, isEmpty);
    }

    await tester.ensureVisible(find.text(en.shellSignUpHaveAccount));
    await tester.tap(find.text(en.shellSignUpHaveAccount));
    await settle(tester);
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('every control is at least 56dp tall', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp);

    for (final Element button in find.byType(GloveButton).evaluate()) {
      expect(tester.getSize(find.byWidget(button.widget)).height, greaterThanOrEqualTo(N360Size.tap));
    }
    for (final Element field in find.byType(TextField).evaluate()) {
      expect(tester.getSize(find.byWidget(field.widget)).height, greaterThanOrEqualTo(N360Size.tap));
    }
  });

  testWidgets('nothing on it is about plans, prices or trials', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp);
    await submit(tester); // Every field message shows too.

    final String all = shownText(tester).join('\n').toLowerCase();
    expect(all, contains(en.shellSignUpIntro.toLowerCase()));
    for (final String word in <String>['₹', 'trial', 'plan', 'upgrade', 'price', 'month']) {
      expect(all, isNot(contains(word)), reason: '"$word" is on the sign-up screen');
    }
  });

  testWidgets('each mistake is named under its field before anything is sent', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp);

    await submit(tester);
    expect(find.text(en.shellSignUpCompanyMissing), findsOneWidget);
    expect(find.text(en.shellSignUpNameMissing), findsOneWidget);
    expect(find.text(en.shellSignInEmailRequired), findsOneWidget);
    expect(find.text(en.shellSignUpPasswordShort), findsOneWidget);

    await fill(tester, company: 'x' * 121, email: 'not-an-email', confirm: 'something-else');
    await submit(tester);
    expect(find.text(en.shellSignUpTooLong(120)), findsOneWidget);
    expect(find.text(en.shellSignUpEmailInvalid), findsOneWidget);
    expect(find.text(en.shellSignUpPasswordMismatch), findsOneWidget);

    expect(app.api.registerCalls, isEmpty);
  });

  testWidgets('a taken email says so and leads back to sign-in', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    app.api.takenEmails.add('asha@deccan.test');
    await pumpShellApp(tester, app, location: Routes.signIn);
    await tester.tap(find.text(en.shellSignInNewCompany));
    await settle(tester);

    await fill(tester);
    await submit(tester);

    expect(find.text(en.shellSignUpEmailTakenTitle), findsOneWidget);
    expect(find.text(en.shellSignUpEmailTakenBody), findsOneWidget);
    await tester.tap(find.text(en.shellSignInButton));
    await settle(tester);
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('a taken mobile number is named under its field', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    app.api.takenPhones.add('98200 11111');
    await pumpShellApp(tester, app, location: Routes.signUp);

    await fill(tester, phone: '98200 11111');
    await submit(tester);

    expect(find.text(en.shellSignUpPhoneTaken), findsOneWidget);
    expect(find.byType(SignUpScreen), findsOneWidget);
  });

  testWidgets('no network, too many tries and a server fault each in plain words', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp);
    await fill(tester);

    app.api.offline = true;
    await submit(tester);
    expect(find.text(en.shellSignInNetworkTitle), findsOneWidget);

    app.api.offline = false;
    app.api.failNextRegister = const RateLimited(retryAfter: Duration(seconds: 30), serverMessage: 'Too Many Attempts.');
    await submit(tester);
    expect(find.text(en.shellSignInTooManyTitle), findsOneWidget);
    expect(find.text('Too Many Attempts.'), findsNothing);

    app.api.failNextRegister = const ServerFailure('SQLSTATE[23505] boom', statusCode: 500);
    await submit(tester);
    expect(find.text(en.shellSignInServerTitle), findsOneWidget);
    expect(find.textContaining('SQLSTATE'), findsNothing);
    expect(find.byType(SignUpScreen), findsOneWidget);
  });

  testWidgets('a sign-up the server is not taking says so in the slip, in the app\'s words', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp);
    await fill(tester);

    const String serverText = 'A new company cannot be started from the app right now. Try again later.';
    app.api.failNextRegister = const ValidationFailure(
      serverText,
      errors: <String, List<String>>{
        'signup': <String>[serverText],
      },
    );
    await submit(tester);

    expect(find.byType(ErrorSlip), findsOneWidget);
    expect(find.text(en.shellSignUpClosedTitle), findsOneWidget);
    expect(find.text(en.shellSignUpClosedBody), findsOneWidget);
    expect(find.text(serverText), findsNothing);
    // Not a mistake in any field, so nothing is said under the company name.
    expect(find.text(en.shellSignUpFieldInvalid), findsNothing);
    expect(find.byType(SignUpScreen), findsOneWidget);
    expect(app.services.auth.currentUser, isNull);
  });

  testWidgets('success opens the first-project welcome, sending the email lowercased and the language', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp, locale: 'mr');
    final mr = lookupAppLocalizations(const Locale('mr'));

    await fill(tester, email: '  Asha@Deccan.TEST ', phone: ' 98200 22222 ');
    await tester.tap(find.text(mr.shellSignUpButton));
    await settle(tester);

    expect(find.byType(ProjectPickerScreen), findsOneWidget);
    expect(find.text(mr.shellPickerFirstButton), findsOneWidget);
    final call = app.api.registerCalls.single;
    expect(call['email'], 'asha@deccan.test');
    expect(call['company'], 'Deccan Builders');
    expect(call['phone'], '98200 22222');
    expect(call['locale'], 'mr');
    expect(call['device_name'], AppConfig.deviceName);
    expect(call.containsKey('plan'), isFalse);
    expect(app.services.auth.currentUser!.role, 'owner');
  });

  testWidgets('a reply lost on the way back finishes by signing in', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.signUp);
    await fill(tester);

    // The server made the account; the phone never heard.
    app.api.loseNextRegisterResponse = true;
    await submit(tester);
    expect(find.text(en.shellSignInNetworkTitle), findsOneWidget);

    // Trying again finds the email taken, by this very attempt: signing in finishes it.
    await submit(tester);
    expect(app.api.registerCalls, hasLength(2));
    expect(find.byType(ProjectPickerScreen), findsOneWidget);
    expect(app.services.auth.currentUser!.email, 'asha@deccan.test');
  });

  testWidgets('a taken email with no lost reply before it is not signed in to', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    app.api.takenEmails.add('asha@deccan.test');
    await pumpShellApp(tester, app, location: Routes.signUp);

    await fill(tester);
    await submit(tester);
    await submit(tester);

    expect(find.text(en.shellSignUpEmailTakenTitle), findsOneWidget);
    expect(app.services.auth.currentUser, isNull);
  });

  testWidgets("another person's unsent work stops it before the server is asked", (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    app.services.session.markExpired();
    await pumpShellApp(tester, app, location: Routes.signUp);

    await fill(tester);
    await submit(tester);

    expect(find.text(en.shellSignInOtherUserTitle), findsOneWidget);
    expect(app.api.registerCalls, isEmpty);
    expect(await app.services.auth.unsentWorkCount(), 3);
  });

  testWidgets('the terms and the privacy policy open on the website', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    final List<Uri> opened = <Uri>[];
    app.services.openLink = (uri) async {
      opened.add(uri);
      return true;
    };
    await pumpShellApp(tester, app, location: Routes.signUp);

    void tapLink(String label) {
      final RichText consent = tester
          .widgetList<RichText>(find.descendant(of: find.byType(SignUpConsent), matching: find.byType(RichText)))
          .single;
      bool found = false;
      consent.text.visitChildren((span) {
        if (span is TextSpan && span.text == label) {
          (span.recognizer! as TapGestureRecognizer).onTap!();
          found = true;
          return false;
        }
        return true;
      });
      expect(found, isTrue, reason: '$label is a link');
    }

    tapLink(en.shellSignUpTerms);
    tapLink(en.shellSignUpPrivacy);
    await settle(tester);

    expect(opened, <Uri>[Uri.parse('${AppConfig.siteUrl}/terms'), Uri.parse('${AppConfig.siteUrl}/privacy')]);
    expect(AppConfig.siteUrl, 'https://nirmaan360.in');
  });

  testWidgets('a link the phone cannot open is shown to copy', (tester) async {
    final app = await create();
    addTearDown(app.dispose);
    app.services.openLink = (uri) async => false;
    await pumpShellApp(tester, app, location: Routes.signUp);

    final RichText consent = tester
        .widgetList<RichText>(find.descendant(of: find.byType(SignUpConsent), matching: find.byType(RichText)))
        .single;
    consent.text.visitChildren((span) {
      if (span is TextSpan && span.text == en.shellSignUpPrivacy) {
        (span.recognizer! as TapGestureRecognizer).onTap!();
        return false;
      }
      return true;
    });
    await settle(tester);

    expect(find.text(en.shellLinkFailedTitle), findsOneWidget);
    expect(find.text('${AppConfig.siteUrl}/privacy'), findsOneWidget);
    expect(find.text(en.shellCopyLink), findsOneWidget);
  });
}
