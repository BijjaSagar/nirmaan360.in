import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/config/app_config.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/core/session/token_store.dart';
import 'package:nirmaan360/core/sync/failures.dart';
import 'package:nirmaan360/features/auth/data/auth_repository.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';

import '../../support/test_app.dart';

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  group('restore', () {
    test('needs no network', () async {
      app.api.offline = true;
      final state = await app.services.auth.restoreSession();
      expect(state.status, SessionStatus.signedIn);
      expect(state.user!.name, 'Sachin More');
    });

    test('survives a restart', () async {
      final restored = SessionController(tokens: app.tokens, db: app.db);
      addTearDown(restored.dispose);

      final state = await restored.restore();

      expect(state.isSignedIn, isTrue);
      expect(state.user!.can('dpr.create'), isTrue);
      expect(state.user!.can('dpr.approve'), isFalse);
    });

    test('signed out when nothing is stored', () async {
      final other = await TestApp.create(signedIn: false, seed: false);
      addTearDown(other.dispose);
      final state = await other.services.auth.restoreSession();
      expect(state.status, SessionStatus.signedOut);
    });

    test('a cached user with no token is expired, not signed out', () async {
      await app.tokens.clear();
      final state = await app.services.auth.restoreSession();
      expect(state.status, SessionStatus.expired);
      expect(state.user, isNotNull);
    });
  });

  test('InMemoryTokenStore round-trips', () async {
    final store = InMemoryTokenStore();
    expect(await store.read(), isNull);
    await store.write('abc');
    expect(await store.read(), 'abc');
    await store.clear();
    expect(await store.read(), isNull);
  });

  group('CurrentUser', () {
    test('parses the /me shape and round-trips', () {
      final user = CurrentUser.fromJson(app.api.user);
      expect(user.roleLabel, 'Site Engineer');
      expect(user.features, contains('measurement'));

      final again = CurrentUser.fromJson(user.toJson());
      expect(again.id, user.id);
      expect(again.can('issue.manage'), isTrue);
    });

    test('an unknown permission is false', () {
      expect(CurrentUser.fromJson(app.api.user).can('nope'), isFalse);
    });
  });

  group('sign in', () {
    test('runs the first full pull', () async {
      final fresh = FakeApi()..seedDemo();
      final other = await TestApp.create(
        api: fresh,
        signedIn: false,
        seed: false,
      );
      addTearDown(other.dispose);

      final result = await other.services.auth.signIn(
        email: 'engineer@demo.test',
        password: 'secret',
      );

      expect(result.firstSync.isSuccess, isTrue);
      expect(fresh.pullCalls.single, isNull);
      expect(await other.db.select(other.db.projects).get(), hasLength(1));
      expect(await other.tokens.read(), 'fake-token');
    });

    test('a wrong password raises ValidationFailure and stores nothing',
        () async {
      final other = await TestApp.create(signedIn: false, seed: false);
      addTearDown(other.dispose);

      await expectLater(
        other.services.auth.signIn(email: 'a@b.c', password: 'wrong'),
        throwsA(isA<ValidationFailure>()),
      );
      expect(await other.tokens.read(), isNull);
    });

    test('offline at sign-in raises OfflineFailure', () async {
      final other = await TestApp.create(signedIn: false, seed: false);
      addTearDown(other.dispose);
      other.api.offline = true;

      await expectLater(
        other.services.auth.signIn(email: 'a@b.c', password: 'x'),
        throwsA(isA<OfflineFailure>()),
      );
    });

    test('the same user signing back in after a 401 keeps unsent work',
        () async {
      await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      app.api.unauthorized = true;
      await app.sync();
      expect(app.services.session.current.status, SessionStatus.expired);

      app.api.unauthorized = false;
      await app.services.auth.signIn(email: 'a@b.c', password: 'x');

      expect(await app.db.select(app.db.dprs).get(), hasLength(1));
      expect(app.api.issues, hasLength(1));
    });

    test('another user is refused while unsent work exists', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      app.api.user = {...app.api.user, 'id': 99};

      await expectLater(
        app.services.auth.signIn(email: 'b@b.c', password: 'x'),
        throwsA(isA<DifferentUserFailure>()),
      );
      expect((await app.db.select(app.db.outbox).get()), hasLength(1));
      expect(app.services.session.user!.id, 4);
    });

    test('another user with nothing unsent replaces the local data', () async {
      await app.services.projects.selectProject(1);
      await app.services.auth.setLocale('mr');
      app.api.user = {...app.api.user, 'id': 99};

      await app.services.auth.signIn(email: 'b@b.c', password: 'x');

      expect(app.services.session.user!.id, 99);
      expect(await app.services.auth.readLocale(), 'mr');
      expect(await app.services.projects.readSelectedProjectId(), isNull);
    });
  });

  group('sign out', () {
    test('refuses while work is unsent and keeps everything', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');

      await expectLater(
        app.services.auth.signOut(),
        throwsA(
          isA<UnsentWorkFailure>().having((e) => e.count, 'count', 1),
        ),
      );
      expect(await app.db.select(app.db.issues).get(), hasLength(1));
      expect(app.services.session.current.isSignedIn, isTrue);
    });

    test('an unqueued DPR draft counts as unsent', () async {
      await app.services.dpr.saveDraft(
        const DprInput(projectId: 1, reportDate: '2026-11-09'),
      );
      expect(await app.services.auth.unsentWorkCount(), 1);
      await expectLater(
        app.services.auth.signOut(),
        throwsA(isA<UnsentWorkFailure>()),
      );
    });

    test('clears local data once everything is sent', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      await app.sync();

      await app.services.auth.signOut();

      expect(app.services.session.current.status, SessionStatus.signedOut);
      expect(await app.tokens.read(), isNull);
      expect(await app.db.select(app.db.issues).get(), isEmpty);
    });

    test('works offline', () async {
      app.api.offline = true;
      await app.services.auth.signOut();
      expect(app.services.session.current.status, SessionStatus.signedOut);
    });

    test('discarding is an explicit choice', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      await app.services.auth.signOut(discardUnsent: true);
      expect(await app.db.select(app.db.outbox).get(), isEmpty);
    });
  });

  test('signing in with nobody signed in clears what a late pull left behind', () async {
    await app.sync();
    await app.services.auth.signOut();
    // A pull still on its way when the sign-out finished.
    await app.db.writeMeta(MetaKey.watermark, '2026-11-09T04:30:00Z');
    await app.db.into(app.db.issues).insert(IssuesCompanion.insert(
          clientUuid: 'late-1',
          projectId: 1,
          title: 'From the last person',
        ));
    app.api.projects.clear();
    app.api.issues.clear();

    await app.services.auth.signIn(email: 'engineer@demo.test', password: 'secret');

    expect(await app.db.select(app.db.issues).get(), isEmpty);
  });

  test('signing out clears the temporary backup and quotation files', () async {
    final quotes = Directory('${app.tempDirectory.path}/quotations')..createSync();
    File('${quotes.path}/quote.pdf').writeAsStringSync('pdf');

    await app.services.auth.signOut(discardUnsent: true);

    expect(quotes.existsSync(), isFalse);
  });

  group('sign up', () {
    Future<SignInResult> signUp(TestApp on) => on.services.auth.signUp(
          company: 'Deccan Builders',
          name: 'Asha Patil',
          email: 'asha@deccan.test',
          password: 'site-office-8',
          passwordConfirmation: 'site-office-8',
          locale: 'hi',
        );

    test('starts the session as the new owner and runs the first pull', () async {
      final fresh = await TestApp.create(seed: false, signedIn: false);
      addTearDown(fresh.dispose);

      final SignInResult result = await signUp(fresh);

      expect(result.user.role, 'owner');
      expect(result.user.organizationName, 'Deccan Builders');
      expect(result.firstSync.isSuccess, isTrue);
      expect(fresh.services.session.current.isSignedIn, isTrue);
      expect(await fresh.tokens.read(), 'fake-signup-token');
      expect(fresh.api.pullCalls, isNotEmpty);
      expect(fresh.api.registerCalls.single['device_name'], AppConfig.deviceName);
    });

    test("clears the last person's data when nothing of theirs is unsent", () async {
      await app.sync();
      expect(await app.db.select(app.db.projects).get(), isNotEmpty);
      app.services.session.markExpired();
      // The new company has no project on the server yet.
      app.api.projects.clear();

      await signUp(app);

      expect(app.services.auth.currentUser!.email, 'asha@deccan.test');
      // The demo company's projects belonged to the last person, not this new company.
      expect(await app.db.select(app.db.projects).get(), isEmpty);
    });

    test("refuses, before asking the server, while another person's work is unsent", () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      app.services.session.markExpired();

      await expectLater(signUp(app), throwsA(isA<DifferentUserFailure>().having((e) => e.unsentCount, 'count', 1)));

      expect(app.api.registerCalls, isEmpty);
      expect(await app.db.select(app.db.issues).get(), hasLength(1));
    });

    test('a refusal from the server changes nothing here', () async {
      final fresh = await TestApp.create(seed: false, signedIn: false);
      addTearDown(fresh.dispose);
      fresh.api.takenEmails.add('asha@deccan.test');

      await expectLater(signUp(fresh), throwsA(isA<ValidationFailure>()));
      expect(fresh.services.session.current.isSignedIn, isFalse);
      expect(await fresh.tokens.read(), isNull);
    });
  });

  group('delete account', () {
    test('clears every table, the files, the token and the user, and keeps the language', () async {
      await app.services.auth.setLocale('mr');
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      await app.sync();
      final attachments = app.services.context.attachments;

      final result = await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');

      expect(result.scheduled, isFalse);
      expect(app.services.session.current.status, SessionStatus.signedOut);
      expect(app.services.session.current.user, isNull);
      expect(await app.tokens.read(), isNull);
      expect(await app.db.readMeta(MetaKey.sessionUser), isNull);
      for (final table in app.db.allTables) {
        if (table == app.db.keyValues) continue;
        expect(await app.db.select(table).get(), isEmpty, reason: table.actualTableName);
      }
      expect(await app.db.readMeta(MetaKey.locale), 'mr');
      expect(attachments, isNotNull);
      expect(app.api.logoutCalls, 0);
    });

    test('refuses while work is unsent, unless losing it was confirmed', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');

      await expectLater(
        app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret'),
        throwsA(isA<UnsentWorkFailure>().having((e) => e.count, 'count', 1)),
      );
      expect(app.api.deleteAccountCalls, isEmpty);
      expect(app.services.session.current.isSignedIn, isTrue);

      await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret', discardUnsent: true);
      expect(await app.db.select(app.db.issues).get(), isEmpty);
      expect(app.services.session.current.status, SessionStatus.signedOut);
    });

    test('a refusal from the server leaves everything as it was', () async {
      await expectLater(
        app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'wrong'),
        throwsA(isA<ValidationFailure>()),
      );
      app.api.offline = true;
      await expectLater(
        app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret'),
        throwsA(isA<OfflineFailure>()),
      );

      expect(app.services.session.current.isSignedIn, isTrue);
      expect(await app.tokens.read(), 'fake-token');
      expect(await app.db.select(app.db.projects).get(), isNotEmpty);
    });

    test('the only owner gets the date the company goes', () async {
      app.api.scheduleCompanyDeletion();
      app.api.deletionPreview = {...app.api.deletionPreview, 'pending': null};

      final result = await app.services.auth.deleteAccount(
        mode: AccountDeletionMode.company,
        password: 'secret',
        confirmName: 'Sahyadri Infra Pvt Ltd',
      );

      expect(result.scheduled, isTrue);
      expect(result.eraseAfter, DateTime.parse('2026-12-09T10:00:00+05:30'));
      expect(app.services.session.current.status, SessionStatus.signedOut);
    });

    test('a background 401 in between still ends signed out, not expired', () async {
      await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');
      // Anything the phone asks with the old token is refused now.
      await expectLater(app.api.me(), throwsA(isA<AuthExpired>()));
      expect(app.services.session.current.status, SessionStatus.signedOut);
    });

    test('a sync under way finishes before the wipe, so nothing it pulls lands afterwards', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      final gate = app.api.hangNextPush();
      final Future<void> running = app.sync();
      await pumpEventQueue();
      expect(app.services.sync.isRunning, isTrue);

      var done = false;
      final Future<void> deleting = app.services.auth
          .deleteAccount(mode: AccountDeletionMode.account, password: 'secret', discardUnsent: true)
          .then((_) => done = true);
      await pumpEventQueue();
      expect(done, isFalse, reason: 'it waits for the sync');

      gate.complete();
      await running;
      await deleting;

      expect(app.services.sync.isRunning, isFalse);
      expect(await app.db.select(app.db.projects).get(), isEmpty);
      expect(await app.db.readMeta(MetaKey.watermark), isNull);
      expect(app.services.session.current.status, SessionStatus.signedOut);
    });

    test('a pull for someone no longer signed in does not bring their user back', () async {
      final user = app.services.session.user!;
      await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');

      await app.services.session.updateUser(user);

      expect(await app.db.readMeta(MetaKey.sessionUser), isNull);
      expect(app.services.session.current.user, isNull);
    });

    test('a reply lost on the way back still clears the phone when the server did delete', () async {
      app.api.loseNextDeleteAccountResponse = true;

      final result = await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');

      expect(result.scheduled, isFalse);
      expect(app.api.deleteAccountCalls, hasLength(1));
      expect(app.services.session.current.status, SessionStatus.signedOut);
      expect(await app.db.select(app.db.projects).get(), isEmpty);
      expect(app.services.auth.takeDeletedNotice(), isNotNull);
    });

    test('a lost reply with no way to check is retried, and the retry finishes it', () async {
      app.api.loseNextDeleteAccountResponse = true;
      // The check after the lost reply cannot reach the server either.
      app.api.offlineAfterLostDelete = true;

      await expectLater(
        app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret'),
        throwsA(isA<OfflineFailure>()),
      );
      expect(app.services.session.current.isSignedIn, isTrue);
      expect(await app.db.select(app.db.projects).get(), isNotEmpty);

      // Back online: the token is gone, which shows the first request went through.
      app.api.offline = false;
      await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');

      expect(app.api.deleteAccountCalls, hasLength(1));
      expect(app.services.session.current.status, SessionStatus.signedOut);
      expect(await app.db.select(app.db.projects).get(), isEmpty);
    });

    test('a 401 with no lost reply before it is not taken as a deletion', () async {
      app.api.unauthorized = true;

      await expectLater(
        app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret'),
        throwsA(isA<AuthExpired>()),
      );
      expect(await app.db.select(app.db.projects).get(), isNotEmpty);
    });

    test('the temporary quotation files go too', () async {
      final quotes = Directory('${app.tempDirectory.path}/quotations')..createSync();
      File('${quotes.path}/quote.pdf').writeAsStringSync('pdf');
      final other = File('${app.tempDirectory.path}/unrelated.txt')..writeAsStringSync('keep');

      await app.services.auth.deleteAccount(mode: AccountDeletionMode.account, password: 'secret');

      expect(quotes.existsSync(), isFalse);
      expect(other.existsSync(), isTrue);
    });
  });

  test('watermark and locale survive through the key-value table', () async {
    await app.services.auth.setLocale('hi');
    expect(await app.db.readMeta(MetaKey.locale), 'hi');
    expect(await app.db.readMeta(MetaKey.watermark), isNotNull);
  });
}
