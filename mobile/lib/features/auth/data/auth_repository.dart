import 'package:drift/drift.dart';

import '../../../core/config/app_config.dart';
import '../../../core/db/app_database.dart';
import '../../../core/session/current_user.dart';
import '../../../core/session/session_controller.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/sync_status.dart';

class SignInResult {
  const SignInResult({required this.user, required this.firstSync});

  final CurrentUser user;

  /// The first pull after sign-in. Not successful only when the network
  /// failed right after login; the app still opens and retries later.
  final SyncRunResult firstSync;
}

class AuthRepository {
  AuthRepository(this._ctx);

  final DataContext _ctx;

  CurrentUser? get currentUser => _ctx.session.user;

  bool can(String permission) => currentUser?.can(permission) ?? false;

  Stream<SessionState> watchSession() => _ctx.session.changes;

  /// Local only. Never touches the network, so the app opens anywhere.
  Future<SessionState> restoreSession() => _ctx.session.restore();

  /// Signs in and runs the first pull.
  ///
  /// Throws [DifferentUserFailure] when another user's unsent work is still
  /// on this phone: it must never be pushed under the wrong account.
  Future<SignInResult> signIn({
    required String email,
    required String password,
  }) async {
    final login = await _ctx.api.login(
      email: email,
      password: password,
      deviceName: AppConfig.deviceName,
    );
    final user = CurrentUser.fromJson(login.user);

    final previous = _ctx.session.user;
    if (previous != null && previous.id != user.id) {
      final unsent = await unsentWorkCount();
      if (unsent > 0) throw DifferentUserFailure(unsent);
      await _wipeLocalData();
    }

    await _ctx.session.startSession(login.token, user);
    final firstSync = await _ctx.sync.syncNow();
    return SignInResult(user: user, firstSync: firstSync);
  }

  /// Signs out and clears local data.
  ///
  /// Refuses with [UnsentWorkFailure] when work has not reached the server,
  /// unless [discardUnsent] is true, which a screen must only pass after the
  /// user has confirmed losing that work.
  Future<void> signOut({bool discardUnsent = false}) async {
    final unsent = await unsentWorkCount();
    if (unsent > 0 && !discardUnsent) throw UnsentWorkFailure(unsent);

    try {
      await _ctx.api.logout();
    } on Exception {
      // Signing out must work offline. The token is dropped locally anyway.
    }
    await _wipeLocalData();
    await _ctx.session.clear();
  }

  /// Outbox items not finished, plus DPR drafts not yet queued.
  Future<int> unsentWorkCount() async {
    final queued = await _ctx.outbox.unsentCount();
    final count = _ctx.db.dprs.clientUuid.count();
    final query = _ctx.db.selectOnly(_ctx.db.dprs)
      ..addColumns([count])
      ..where(_ctx.db.dprs.localDraft.equals(true));
    final drafts = (await query.getSingle()).read(count) ?? 0;
    return queued + drafts;
  }

  Stream<String?> watchLocale() => _ctx.db.watchMeta(MetaKey.locale);

  Future<String?> readLocale() => _ctx.db.readMeta(MetaKey.locale);

  Future<void> setLocale(String locale) =>
      _ctx.db.writeMeta(MetaKey.locale, locale);

  Future<void> _wipeLocalData() async {
    final db = _ctx.db;
    await db.transaction(() async {
      for (final table in db.allTables) {
        if (table == db.keyValues) continue;
        await db.delete(table).go();
      }
      await (db.delete(db.keyValues)
            ..where((t) => t.key.equals(MetaKey.locale).not()))
          .go();
    });
    await _ctx.attachments.deleteAll();
  }
}
