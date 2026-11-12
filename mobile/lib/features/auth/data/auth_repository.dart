import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/api/nirmaan_api.dart';
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

  /// Runs once the server has deleted the account, before anything local is
  /// cleared. It drops this phone's push token without asking the server, which
  /// has already dropped it and would refuse the revoked token. Never throws.
  Future<void> Function()? afterAccountDeleted;

  AccountDeletionResult? _deletedNotice;

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
      await _refuseOtherUsersWork();
      await _wipeLocalData();
    }
    return _finishSignIn(login, user);
  }

  /// Starts a new company with this person as its only owner, signs them in
  /// and runs the first pull, exactly as [signIn] does.
  ///
  /// A new account is always someone else to whatever this phone still holds,
  /// so another person's unsent work, or anything on the offline plan, refuses
  /// it with [DifferentUserFailure] before the server is asked.
  Future<SignInResult> signUp({
    required String company,
    required String name,
    required String email,
    String? phone,
    required String password,
    required String passwordConfirmation,
    required String locale,
  }) async {
    final previous = _ctx.session.user;
    if (previous != null) await _refuseOtherUsersWork();
    final login = await _ctx.api.register(
      company: company,
      name: name,
      email: email,
      phone: phone,
      password: password,
      passwordConfirmation: passwordConfirmation,
      deviceName: AppConfig.deviceName,
      locale: locale,
    );
    final user = CurrentUser.fromJson(login.user);
    if (previous != null) await _wipeLocalData();
    return _finishSignIn(login, user);
  }

  /// Throws [DifferentUserFailure] when this phone holds work that must never
  /// be sent under, or wiped by, a different account.
  Future<void> _refuseOtherUsersWork() async {
    // On the offline plan everything here exists nowhere else, sent or not.
    if (_ctx.offlinePlan.isLocalOnly) throw DifferentUserFailure(await _localRecordCount());
    final unsent = await unsentWorkCount();
    if (unsent > 0) throw DifferentUserFailure(unsent);
  }

  Future<SignInResult> _finishSignIn(LoginResult login, CurrentUser user) async {
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

  /// What deleting this account would do. Online only; writes nothing.
  Future<AccountDeletionPreview> deletionPreview() => _ctx.api.accountDeletionPreview();

  /// Calls off a pending company deletion. True when one was cancelled.
  Future<bool> cancelCompanyDeletion() => _ctx.api.cancelCompanyDeletion();

  /// Deletes this person's account on the server, then everything of theirs on
  /// this phone, and signs out.
  ///
  /// Refuses with [UnsentWorkFailure] while work has not reached the server,
  /// or while the offline plan holds records that exist only here, unless
  /// [discardUnsent] is true: a screen passes it only after showing that count.
  ///
  /// If the server refuses, nothing here changes and push stays on. Once it
  /// agrees the token is already revoked, so the server is never asked to
  /// sign out. Unlike [signOut], the offline plan's data goes too: the account
  /// it belonged to is gone.
  Future<AccountDeletionResult> deleteAccount({
    required AccountDeletionMode mode,
    required String password,
    String? confirmName,
    bool discardUnsent = false,
  }) async {
    if (!discardUnsent) {
      final int count = _ctx.offlinePlan.isLocalOnly ? await _localRecordCount() : await unsentWorkCount();
      if (count > 0) throw UnsentWorkFailure(count);
    }
    final AccountDeletionResult result = await _ctx.api.deleteAccount(
      mode: mode,
      password: password,
      confirmName: confirmName,
    );
    _deletedNotice = result;
    await afterAccountDeleted?.call();
    if (_ctx.offlinePlan.isLocalOnly) await _ctx.offlinePlan.leave();
    await _wipeLocalData();
    await _ctx.session.clear();
    return result;
  }

  /// The deletion that just signed this phone out, once: the sign-in screen
  /// says what happened, and a later visit says nothing.
  AccountDeletionResult? takeDeletedNotice() {
    final AccountDeletionResult? notice = _deletedNotice;
    _deletedNotice = null;
    return notice;
  }

  /// Records on the offline plan, which exist on this phone and nowhere else.
  Future<int> localRecordCount() => _localRecordCount();

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
