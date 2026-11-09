import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/app_info.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/sync_center/view/sync_center_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

void main() {
  final en = lookupAppLocalizations(const Locale('en'));

  testWidgets('shows who is signed in, the version and the language switch', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.settings);

    expect(find.text('Sachin More'), findsOneWidget);
    expect(find.text('Site Engineer'), findsOneWidget);
    expect(find.text(AppInfo.label), findsOneWidget);

    await tester.tap(find.text('हिन्दी'));
    await settle(tester);
    expect(await app.services.auth.readLocale(), 'hi');
    expect(find.text('साइन आउट'), findsOneWidget);
  });

  testWidgets('with nothing unsent a plain confirm is enough', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await pumpShellApp(tester, app, location: Routes.settings);

    await tester.tap(find.text(en.shellSignOut));
    await settle(tester);
    expect(find.text(en.shellSignOutConfirmTitle), findsOneWidget);

    await tester.tap(find.text(en.shellSignOut).last);
    await settle(tester);
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('unsent work warns with the count and needs a deliberate hold', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app, location: Routes.settings, disableAnimations: false);

    await tester.tap(find.text(en.shellSignOut));
    await settle(tester);
    expect(find.text(en.shellSignOutUnsentTitle(3)), findsOneWidget);

    // A quick tap on the destructive control does nothing.
    await tester.tap(find.text(en.shellSignOutHold));
    await settle(tester);
    expect(find.text(en.shellSignOutUnsentTitle(3)), findsOneWidget);
    expect(await app.services.auth.unsentWorkCount(), 3);

    // Pressing and holding does.
    final gesture = await tester.startGesture(tester.getCenter(find.text(en.shellSignOutHold)));
    await tester.pump(); // The first frame only starts the hold's clock.
    await tester.pump(const Duration(milliseconds: 1500));
    await gesture.up();
    await settle(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(await app.services.auth.unsentWorkCount(), 0);
  });

  testWidgets('unsent work can go to the outbox instead', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await pumpShellApp(tester, app, location: Routes.settings);

    await tester.tap(find.text(en.shellSignOut));
    await settle(tester);
    await tester.tap(find.text(en.shellSignOutSendFirst));
    await settle(tester);

    expect(find.byType(SyncCenterScreen), findsOneWidget);
    expect(await app.services.auth.unsentWorkCount(), 3);
  });
}
