import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/core/session/session_controller.dart';
import 'package:nirmaan360/core/session/token_store.dart';
import 'package:nirmaan360/core/sync/failures.dart';
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

  test('watermark and locale survive through the key-value table', () async {
    await app.services.auth.setLocale('hi');
    expect(await app.db.readMeta(MetaKey.locale), 'hi');
    expect(await app.db.readMeta(MetaKey.watermark), isNotNull);
  });
}
