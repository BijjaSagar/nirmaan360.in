import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/features/auth/view/sign_in_screen.dart';
import 'package:nirmaan360/features/settings/view/account_deletion_screen.dart';
import 'package:nirmaan360/features/sync_center/view/sync_center_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../../app/shell_test_helpers.dart';
import '../../../support/test_app.dart';

const ValueKey<String> _password = ValueKey<String>('account-delete-password');
const ValueKey<String> _name = ValueKey<String>('account-delete-name');

void main() {
  final en = lookupAppLocalizations(const Locale('en'));
  const String company = 'Sahyadri Infra Pvt Ltd';

  /// The only owner of the company: deleting the account erases it.
  void soleOwner(FakeApi api, {int others = 2}) {
    api.deletionPreview = {
      'mode': 'company',
      'company_name': company,
      'other_people': others,
      'requires_confirm_name': true,
      'grace_days': 30,
      'erase_after': '2026-12-09T10:00:00+05:30',
      'pending': null,
    };
  }

  Future<void> open(WidgetTester tester, TestApp app, {bool disableAnimations = true}) async {
    await pumpShellApp(tester, app, location: Routes.settings, disableAnimations: disableAnimations);
    await tester.tap(find.byKey(const ValueKey<String>('settings-delete-account')));
    await settle(tester);
    expect(find.byType(AccountDeletionScreen), findsOneWidget);
  }

  /// With animations off the hold is a plain tap.
  Future<void> confirm(WidgetTester tester) async {
    await tester.tap(find.text(en.accountDeleteHold));
    await settle(tester);
  }

  testWidgets('a member is told what goes now and what stays with the company', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await open(tester, app);

    expect(find.text(en.accountDeleteMemberNow), findsOneWidget);
    expect(find.text(en.accountDeleteMemberWork(company)), findsOneWidget);
    expect(find.text(en.accountDeletePhoneCleared), findsOneWidget);
    expect(find.byKey(_name), findsNothing);
    expect(find.text(en.accountDeleteCompanyTitle), findsNothing);
    expect(find.text(en.accountDeleteHold), findsOneWidget);
  });

  testWidgets('the only owner is told the whole company goes, who loses access and when', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    soleOwner(app.api);
    await open(tester, app);

    expect(find.text(en.accountDeleteCompanyTitle), findsOneWidget);
    expect(find.text(en.accountDeleteCompanyErases(company)), findsOneWidget);
    expect(find.text(en.accountDeleteOthers(2)), findsOneWidget);
    expect(find.text(en.accountDeleteCompanyWhen('9 Dec 2026')), findsOneWidget);
    expect(find.text(en.accountDeleteTaxRecords), findsOneWidget);
    expect(find.text(en.accountDeleteHandOver), findsOneWidget);
    expect(find.text(en.accountDeleteConfirmName(company)), findsOneWidget);
  });

  testWidgets('no one else in the company is said plainly', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    soleOwner(app.api, others: 0);
    await open(tester, app);

    expect(find.text(en.accountDeleteOthers(0)), findsOneWidget);
    expect(en.accountDeleteOthers(0), 'No one else uses this company.');
    expect(en.accountDeleteOthers(1), '1 other person will lose access.');
  });

  testWidgets('a member deletes at once: the phone is cleared and sign-in says so', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await app.db.writeMeta(MetaKey.locale, 'en');
    await open(tester, app);

    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.accountDeletedToast), findsOneWidget);
    final call = app.api.deleteAccountCalls.single;
    expect(call.mode, AccountDeletionMode.account);
    expect(call.password, 'secret');
    expect(call.confirmName, isNull);
    // The token was revoked by the deletion itself: no sign-out was asked for.
    expect(app.api.logoutCalls, 0);
    expect(app.services.session.current.status, SessionStatus.signedOut);
    expect(app.services.auth.currentUser, isNull);
    expect(await app.db.select(app.db.projects).get(), isEmpty);
    expect(await app.db.readMeta(MetaKey.locale), 'en');
  });

  testWidgets('the only owner schedules the company, with its name typed', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    soleOwner(app.api);
    await open(tester, app);

    await tester.enterText(find.byKey(_name), '  $company ');
    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(en.accountDeletedCompanyToast('9 Dec 2026')), findsOneWidget);
    final call = app.api.deleteAccountCalls.single;
    expect(call.mode, AccountDeletionMode.company);
    expect(call.confirmName, company);
  });

  testWidgets('a wrong password is named under the field, and nothing changes', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await open(tester, app);

    await confirm(tester);
    expect(find.text(en.shellSignInPasswordRequired), findsOneWidget);
    expect(app.api.deleteAccountCalls, isEmpty);

    await tester.enterText(find.byKey(_password), 'not-it');
    await confirm(tester);

    expect(find.text(en.accountDeleteWrongPassword), findsOneWidget);
    expect(find.byType(AccountDeletionScreen), findsOneWidget);
    expect(app.services.session.current.status, SessionStatus.signedIn);
    expect(await app.db.select(app.db.projects).get(), isNotEmpty);

    // The bar can be used again straight away.
    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('a wrong company name is named under its field', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    soleOwner(app.api);
    await open(tester, app);

    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);
    expect(find.text(en.accountDeleteWrongName), findsOneWidget);
    expect(app.api.deleteAccountCalls, isEmpty);

    await tester.enterText(find.byKey(_name), 'Sahyadri');
    await confirm(tester);
    expect(find.text(en.accountDeleteWrongName), findsOneWidget);
    expect(app.api.deleteAccountCalls, hasLength(1));
    expect(find.byType(AccountDeletionScreen), findsOneWidget);
  });

  testWidgets('when the company changed meanwhile, it says so and shows what happens now', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await open(tester, app);
    expect(find.text(en.accountDeleteMemberNow), findsOneWidget);

    // The other owner left: this person is now the only one.
    soleOwner(app.api);
    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);

    expect(find.text(en.accountDeleteChanged), findsOneWidget);
    expect(find.text(en.accountDeleteCompanyErases(company)), findsOneWidget);
    expect(app.api.deletionPreviewReads, 2);
    expect(app.services.session.current.status, SessionStatus.signedIn);
  });

  testWidgets('too many tries and a server fault each in plain words', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await open(tester, app);
    await tester.enterText(find.byKey(_password), 'secret');

    app.api.failNextDeleteAccount = const RateLimited(serverMessage: 'Too Many Attempts.');
    await confirm(tester);
    expect(find.text(en.shellSignInTooManyTitle), findsOneWidget);

    app.api.failNextDeleteAccount = const ServerFailure('boom', statusCode: 500);
    await confirm(tester);
    expect(find.text(en.shellSignInServerTitle), findsOneWidget);
    expect(app.services.session.current.status, SessionStatus.signedIn);
  });

  testWidgets('it needs the internet, and says so with a way to try again', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.api.offline = true;
    await open(tester, app);

    expect(find.text(en.accountDeleteOfflineTitle), findsOneWidget);
    expect(find.text(en.accountDeleteHold), findsNothing);

    app.api.offline = false;
    await tester.tap(find.text(en.commonRetry));
    await settle(tester);
    expect(find.text(en.accountDeleteMemberNow), findsOneWidget);
  });

  testWidgets('a pending company deletion can be called off, and nothing else is offered', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    soleOwner(app.api);
    app.api.scheduleCompanyDeletion();
    await open(tester, app);

    expect(find.text(en.accountDeletePendingTitle), findsOneWidget);
    expect(find.text(en.accountDeletePendingBody(company, '9 Dec 2026')), findsOneWidget);
    expect(find.text(en.accountDeleteHold), findsNothing);

    await tester.tap(find.text(en.accountDeleteCancelButton));
    await settle(tester);

    expect(find.text(en.accountDeleteCancelled), findsOneWidget);
    expect(app.api.cancelDeletionCalls, 1);
    expect(find.text(en.accountDeletePendingTitle), findsNothing);
    // Back to how it was: deleting is offered again, as for any only owner.
    expect(find.text(en.accountDeleteCompanyErases(company)), findsOneWidget);
  });

  testWidgets('a member of a company on its way out sees the date but cannot call it off', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    app.api.scheduleCompanyDeletion(canCancel: false);
    app.api.deletionPreview = {...app.api.deletionPreview, 'mode': 'account', 'requires_confirm_name': false};
    await open(tester, app);

    expect(find.text(en.accountDeletePendingTitle), findsOneWidget);
    expect(find.text(en.accountDeleteCancelButton), findsNothing);
    expect(find.text(en.accountDeleteMemberNow), findsOneWidget);
    expect(find.text(en.accountDeleteHold), findsOneWidget);
  });

  testWidgets('unsent work is counted in red, with the outbox one tap away', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await open(tester, app);

    expect(find.text(en.accountDeleteUnsent(3)), findsOneWidget);
    await tester.ensureVisible(find.text(en.shellSignOutSendFirst));
    await tester.tap(find.text(en.shellSignOutSendFirst));
    await settle(tester);
    expect(find.byType(SyncCenterScreen), findsOneWidget);
    expect(app.api.deleteAccountCalls, isEmpty);
  });

  testWidgets('with the unsent count shown, deleting goes ahead and clears it', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await seedToday(app);
    await open(tester, app);

    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(await app.services.auth.unsentWorkCount(), 0);
  });

  testWidgets('the delete control has to be held: a tap does nothing', (tester) async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    await open(tester, app, disableAnimations: false);
    await tester.enterText(find.byKey(_password), 'secret');
    await tester.pump();

    await tester.tap(find.text(en.accountDeleteHold));
    await settle(tester);
    expect(app.api.deleteAccountCalls, isEmpty);
    expect(find.byType(AccountDeletionScreen), findsOneWidget);

    final gesture = await tester.startGesture(tester.getCenter(find.text(en.accountDeleteHold)));
    await tester.pump(); // The first frame only starts the hold's clock.
    await tester.pump(const Duration(milliseconds: 1500));
    await gesture.up();
    await settle(tester);

    expect(app.api.deleteAccountCalls, hasLength(1));
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('on the offline plan every local record is counted, and erased', (tester) async {
    final api = FakeApi()..offlineCompany = true;
    final app = await TestApp.create(seed: false, signedIn: false, api: api);
    addTearDown(app.dispose);
    await app.services.auth.signIn(email: 'owner@example.test', password: 'secret');
    await app.db.into(app.db.projects).insert(ProjectsCompanion.insert(id: const Value(-1), code: 'L1', name: 'Local site'));
    await app.services.projects.selectProject(-1);
    await open(tester, app);

    expect(find.text(en.accountDeleteLocalOnly(1)), findsOneWidget);
    expect(find.text(en.shellSignOutSendFirst), findsNothing);

    await tester.enterText(find.byKey(_password), 'secret');
    await confirm(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(await app.db.select(app.db.projects).get(), isEmpty);
    expect(app.services.offlinePlan.isLocalOnly, isFalse);
    expect(app.services.auth.currentUser, isNull);
  });
}
