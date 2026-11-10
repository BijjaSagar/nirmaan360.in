import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/config/app_config.dart';
import '../../../core/db/app_database.dart';
import '../../../core/session/current_user.dart';
import '../../../core/session/session_controller.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/inspection_cache.dart';
import '../../../core/sync/sync_status.dart';
import '../../contractor_portal/data/portal_repository.dart';

class SignInResult {
  const SignInResult({required this.user, required this.firstSync});

  final CurrentUser user;

  /// The first pull after sign-in. Not successful only when the network
  /// failed right after login; the app still opens and retries later.
  final SyncRunResult firstSync;
}

class AuthRepository {
  AuthRepository(this._ctx, this._portal);

  final DataContext _ctx;
  final PortalRepository _portal;

  /// Runs once a sign-out is certain, while the token still works. It takes this
  /// phone off the server's push list. Never throws, and waits a few seconds at most.
  Future<void> Function()? beforeSignOut;

  /// Runs after the language changes on this phone, so push text follows it.
  /// Never throws.
  Future<void> Function()? afterLocaleChange;

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
      // On the offline plan everything here exists nowhere else, sent or not.
      if (_ctx.offlinePlan.isLocalOnly) throw DifferentUserFailure(await _localRecordCount());
      final unsent = await unsentWorkCount();
      if (unsent > 0) throw DifferentUserFailure(unsent);
      await _wipeLocalData();
    }

    await _ctx.session.startSession(login.token, user);
    // Before the first pull: a company on the offline plan must not make one.
    // A failure here changes nothing, and the first pull goes ahead as ever.
    if (!user.isContractor) await _ctx.offlinePlan.check(force: true);
    await _ctx.offlinePlan.noteSignedIn();
    if (_ctx.offlinePlan.isLocalOnly) {
      return SignInResult(user: user, firstSync: const SyncRunResult(SyncOutcome.success));
    }
    // A contractor login has no sites to pull and nothing to send: the portal
    // is its first read. The site team's first pull is exactly as it was.
    final firstSync = user.isContractor ? await _readPortal() : await _ctx.sync.syncNow();
    return SignInResult(user: user, firstSync: firstSync);
  }

  /// The contractor's first read, reported the way a sync run is. Failing
  /// leaves the portal to try again when it opens.
  Future<SyncRunResult> _readPortal() async {
    try {
      await _portal.refresh();
      return const SyncRunResult(SyncOutcome.success);
    } on OfflineFailure catch (e) {
      return SyncRunResult(SyncOutcome.offline, error: e);
    } on AuthExpired catch (e) {
      return SyncRunResult(SyncOutcome.authExpired, error: e);
    } on ApiFailure catch (e) {
      return SyncRunResult(SyncOutcome.serverError, error: e);
    }
  }

  /// Signs out and clears local data.
  ///
  /// Refuses with [UnsentWorkFailure] when work has not reached the server,
  /// unless [discardUnsent] is true, which a screen must only pass after the
  /// user has confirmed losing that work.
  ///
  /// On the offline plan nothing is ever cleared: this phone holds the only
  /// copy. The sign-in goes, and the same person can sign back in to it.
  Future<void> signOut({bool discardUnsent = false}) async {
    if (_ctx.offlinePlan.isLocalOnly) {
      try {
        await _ctx.api.logout();
      } on Exception {
        // Signing out must work offline.
      }
      await _ctx.offlinePlan.noteSignedOut();
      await _ctx.session.endKeepingUser();
      return;
    }
    final unsent = await unsentWorkCount();
    if (unsent > 0 && !discardUnsent) throw UnsentWorkFailure(unsent);

    await beforeSignOut?.call();
    try {
      await _ctx.api.logout();
    } on Exception {
      // Signing out must work offline. The token is dropped locally anyway.
    }
    await _wipeLocalData();
    await _ctx.session.clear();
  }

  /// Outbox items not finished, plus DPR drafts not yet queued and inspections
  /// still being filled in.
  Future<int> unsentWorkCount() async {
    final queued = await _ctx.outbox.unsentCount() + await InspectionCache.draftCount(_ctx.db);
    final count = _ctx.db.dprs.clientUuid.count();
    final query = _ctx.db.selectOnly(_ctx.db.dprs)
      ..addColumns([count])
      ..where(_ctx.db.dprs.localDraft.equals(true));
    final drafts = (await query.getSingle()).read(count) ?? 0;
    return queued + drafts;
  }

  Future<int> _localRecordCount() async {
    final work = await _ctx.offlinePlan.work();
    return work.projects + work.boqLines + work.workers + work.wallets + work.entries;
  }

  Stream<String?> watchLocale() => _ctx.db.watchMeta(MetaKey.locale);

  Future<String?> readLocale() => _ctx.db.readMeta(MetaKey.locale);

  Future<void> setLocale(String locale) async {
    final String? before = await readLocale();
    await _ctx.db.writeMeta(MetaKey.locale, locale);
    if (before != locale) await afterLocaleChange?.call();
  }

  Future<void> _wipeLocalData() async {
    final db = _ctx.db;
    await db.transaction(() async {
      for (final table in db.allTables) {
        if (table == db.keyValues) continue;
        await db.delete(table).go();
      }
      await (db.delete(db.keyValues)
            ..where((t) => t.key.isIn(<String>[MetaKey.locale, MetaKey.offlineDeviceId, MetaKey.pushExplained]).not()))
          .go();
    });
    await _ctx.attachments.deleteAll();
  }
}
