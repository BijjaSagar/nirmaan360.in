import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;

import 'package:drift/drift.dart';
import 'package:equatable/equatable.dart';
import 'package:flutter/foundation.dart';

import '../api/api_failure.dart';
import '../api/nirmaan_api.dart';
import '../config/app_config.dart';
import '../db/app_database.dart';
import '../session/session_controller.dart';
import '../sync/clock.dart';
import '../sync/connectivity.dart';
import '../sync/ids.dart';
import 'licence_token.dart';

/// Names used to file a record created on this phone: see `createLocal`.
abstract final class LocalRecordKind {
  static const project = 'project';
  static const boqItem = 'boq_item';
  static const worker = 'worker';
  static const wallet = 'wallet';
}

/// How a phone on the offline plan is running.
enum OfflineMode {
  /// On the plan: everything stays on this phone.
  local,

  /// The server stopped saying offline. Nothing moves until the owner answers.
  asking,

  /// The owner said no. Still local, and not asked again.
  declined,

  /// The owner said yes. Survives a restart, so an interrupted upload resumes.
  uploading,
}

enum LicenceIssue { none, deviceLimit, revoked, invalid }

/// How the licence stands today.
enum LicenceStanding { active, endingSoon, ended, none }

/// From this long before the licence ends the app says so, calmly. A week,
/// because a site may be without a network for days.
const Duration kLicenceWarning = Duration(days: 7);

/// How the licence stands right now, as screens and the write guard see it.
@immutable
class LicenceStatus extends Equatable {
  const LicenceStatus({
    this.standing = LicenceStanding.none,
    this.endsAt,
    this.entriesBlocked = false,
    this.planSyncs = false,
    this.hasToken = false,
  });

  final LicenceStanding standing;

  /// From the verified token, never from anything the phone can edit.
  final DateTime? endsAt;

  /// New entries and edits are refused. Reading and the export still work.
  final bool entriesBlocked;

  /// The server no longer says offline for this company, and the phone still
  /// holds its data. A licence cannot be renewed then: only a fresh token (if
  /// the plan goes back to offline) or the upload to the server lifts a block.
  final bool planSyncs;

  /// The phone holds a token. One that failed its check is not kept.
  final bool hasToken;

  /// Blocked, and with no valid token at all, as against one that has run out.
  bool get hasNoLicence => entriesBlocked && !hasToken;

  /// Inside the last week, and not yet over.
  bool get isEndingSoon => standing == LicenceStanding.endingSoon;

  /// What a company on a normal plan has: nothing to say about a licence.
  static const LicenceStatus none = LicenceStatus();

  @override
  List<Object?> get props => <Object?>[standing, endsAt, entriesBlocked, planSyncs, hasToken];
}

/// What came of asking for a new licence at once.
enum LicenceRenewal {
  /// A fresh token is in and entries are open again.
  renewed,

  /// There is no network to ask on.
  offline,

  /// The server did not answer, or answered with an error.
  noAnswer,

  /// The server answered, but the licence it gave has already ended too.
  stillEnded,

  /// The server no longer accepts the sign-in, so renewing needs a fresh one.
  needsSignIn,

  /// The owner freed this phone on the web. It takes a person to take the slot back.
  freed,

  /// Another phone holds the only slot.
  deviceLimit,

  /// The company now syncs online, so there is no licence to renew.
  planChanged,

  /// Nothing to renew: not on the offline plan.
  notNeeded,
}

/// Everything this phone holds about the offline plan. Stored as one JSON
/// value. Its absence is what a company on any normal plan looks like.
@immutable
class OfflinePlanState extends Equatable {
  const OfflinePlanState({
    this.mode = OfflineMode.local,
    this.token,
    this.publicKey,
    this.keyId,
    this.expiresAt,
    this.issuedAt,
    this.issue = LicenceIssue.none,
    this.manageUrl,
    this.upgradeUrl,
    this.needsSignIn = false,
    this.signedOut = false,
  });

  factory OfflinePlanState.fromJson(Map<String, dynamic> json) {
    T named<T extends Enum>(List<T> values, Object? name, T fallback) {
      for (final T value in values) {
        if (value.name == name) return value;
      }
      return fallback;
    }

    return OfflinePlanState(
      mode: named(OfflineMode.values, json['mode'], OfflineMode.local),
      token: json['token'] as String?,
      publicKey: json['public_key'] as String?,
      keyId: json['key_id'] as String?,
      expiresAt: DateTime.tryParse(json['expires_at'] as String? ?? '')?.toUtc(),
      issuedAt: DateTime.tryParse(json['issued_at'] as String? ?? '')?.toUtc(),
      issue: named(LicenceIssue.values, json['issue'], LicenceIssue.none),
      manageUrl: json['manage_url'] as String?,
      upgradeUrl: json['upgrade_url'] as String?,
      needsSignIn: json['needs_sign_in'] == true,
      signedOut: json['signed_out'] == true,
    );
  }

  final OfflineMode mode;
  final String? token;
  final String? publicKey;
  final String? keyId;

  /// From the verified token, never from the server's reply beside it.
  final DateTime? expiresAt;

  /// When the server signed the token, also from the verified token.
  final DateTime? issuedAt;
  final LicenceIssue issue;

  /// Where the owner frees a phone.
  final String? manageUrl;
  final String? upgradeUrl;

  /// The server no longer accepts the sign-in. Work carries on; only renewing
  /// the licence needs a fresh sign-in.
  final bool needsSignIn;

  /// The person signed out on purpose, so a restart stays at the sign-in.
  final bool signedOut;

  bool get hasToken => token != null;

  /// [now] should come from [OfflinePlan.now], which a clock set back cannot fool.
  LicenceStanding standing(DateTime now) {
    final DateTime? end = expiresAt;
    // A token that failed its signature check is no licence, with or without one stored.
    if (issue == LicenceIssue.invalid) return LicenceStanding.ended;
    if (token == null || end == null) return LicenceStanding.none;
    if (!end.isAfter(now)) return LicenceStanding.ended;
    if (end.difference(now) <= kLicenceWarning) return LicenceStanding.endingSoon;
    return LicenceStanding.active;
  }

  /// A phone that keeps its data local, which this state is by existing, may
  /// add and change things only on a valid token. Once the token has run out, or
  /// when there never was a valid one, entries stop, whatever the server now
  /// says about the plan: a lapsed trial that falls back to a free plan, with the
  /// owner declining the upload, must not mean unlimited offline use. Only a
  /// fresh token, or the upload that moves the phone to normal sync, lifts it.
  ///
  /// The one exception is the upload itself. While it runs, nothing else can be
  /// reached (every screen leads to the upload page), and it has to queue the
  /// saved reports, so it is not held back by the thing it is about to end.
  LicenceStatus statusAt(DateTime now) {
    final LicenceStanding at = standing(now);
    return LicenceStatus(
      standing: at,
      endsAt: expiresAt,
      entriesBlocked: mode != OfflineMode.uploading && (at == LicenceStanding.ended || at == LicenceStanding.none),
      planSyncs: mode == OfflineMode.asking || mode == OfflineMode.declined,
      hasToken: hasToken,
    );
  }

  OfflinePlanState copyWith({
    OfflineMode? mode,
    String? token,
    String? publicKey,
    String? keyId,
    DateTime? expiresAt,
    DateTime? issuedAt,
    LicenceIssue? issue,
    String? manageUrl,
    bool clearManageUrl = false,
    String? upgradeUrl,
    bool? needsSignIn,
    bool? signedOut,
  }) {
    return OfflinePlanState(
      mode: mode ?? this.mode,
      token: token ?? this.token,
      publicKey: publicKey ?? this.publicKey,
      keyId: keyId ?? this.keyId,
      expiresAt: expiresAt ?? this.expiresAt,
      issuedAt: issuedAt ?? this.issuedAt,
      issue: issue ?? this.issue,
      manageUrl: clearManageUrl ? null : (manageUrl ?? this.manageUrl),
      upgradeUrl: upgradeUrl ?? this.upgradeUrl,
      needsSignIn: needsSignIn ?? this.needsSignIn,
      signedOut: signedOut ?? this.signedOut,
    );
  }

  Map<String, dynamic> toJson() => <String, dynamic>{
    'mode': mode.name,
    'token': token,
    'public_key': publicKey,
    'key_id': keyId,
    'expires_at': expiresAt?.toIso8601String(),
    'issued_at': issuedAt?.toIso8601String(),
    'issue': issue.name,
    'manage_url': manageUrl,
    'upgrade_url': upgradeUrl,
    'needs_sign_in': needsSignIn,
    'signed_out': signedOut,
  };

  @override
  List<Object?> get props => <Object?>[
    mode,
    token,
    publicKey,
    keyId,
    expiresAt,
    issuedAt,
    issue,
    manageUrl,
    upgradeUrl,
    needsSignIn,
    signedOut,
  ];
}

/// What exists on this phone and nowhere else.
@immutable
class OfflineWork {
  const OfflineWork({this.projects = 0, this.boqLines = 0, this.workers = 0, this.wallets = 0, this.entries = 0});

  final int projects;
  final int boqLines;
  final int workers;
  final int wallets;

  /// Queued entries and photos, and daily reports still in draft.
  final int entries;

  bool get isEmpty => projects + boqLines + workers + wallets + entries == 0;
}

/// Decides whether this company syncs or runs on the phone alone, and holds
/// the device licence.
///
/// A company on any normal plan writes nothing here, and every question below
/// answers "no". The server is asked after sign-in and now and then while
/// online, and no failure of that question can stop normal syncing.
class OfflinePlan {
  OfflinePlan({
    required this._db,
    required this._api,
    required this._clock,
    required this._connectivity,
    required this._session,
    this.appVersion,
    this.normalInterval = const Duration(hours: 6),
    this.localInterval = const Duration(hours: 1),
    this.nearEndInterval = const Duration(seconds: 30),
  });

  final AppDatabase _db;
  final NirmaanApi _api;
  final AppClock _clock;
  final ConnectivityMonitor _connectivity;
  final SessionController _session;

  /// Sent with the licence, so the devices page can show it.
  final String? appVersion;

  /// How often a normal company's plan is looked at again.
  final Duration normalInterval;

  /// How often a phone on the plan renews its licence while online.
  final Duration localInterval;

  /// How soon a phone that is in its last week, or past the end, asks again.
  /// Short, because every minute online is a chance it must not waste.
  final Duration nearEndInterval;

  final StreamController<OfflinePlanState?> _changes = StreamController<OfflinePlanState?>.broadcast();
  final StreamController<LicenceStatus> _licenceChanges = StreamController<LicenceStatus>.broadcast();
  final StreamController<LicenceEnded> _refusals = StreamController<LicenceEnded>.broadcast();

  OfflinePlanState? _state;
  DateTime? _lastAsked;
  Future<void>? _asking;
  bool _lastAskFailed = false;
  StreamSubscription<bool>? _connectivitySub;
  Timer? _clockTimer;

  /// The latest moment this app has seen, kept in memory and on disk.
  DateTime? _seen;
  DateTime? _seenSaved;
  LicenceStatus _lastStatus = LicenceStatus.none;

  OfflinePlanState? get state => _state;

  /// The time the licence is judged by: the later of the phone's clock, the
  /// latest moment this app has ever seen, and the moment the server signed the
  /// current token. Setting the clock back changes none of those, so it buys no
  /// extra days. Every screen and the write guard ask this, never the clock.
  DateTime now() {
    var latest = _clock.now().toUtc();
    final DateTime? seen = _seen;
    if (seen != null && seen.isAfter(latest)) latest = seen;
    final DateTime? signed = _state?.issuedAt;
    if (signed != null && signed.isAfter(latest)) latest = signed;
    _seen = latest;
    return latest;
  }

  /// How the licence stands now. [LicenceStatus.none] for a normal company.
  LicenceStatus get licence => _statusOf(_state);

  LicenceStatus _statusOf(OfflinePlanState? state) => state == null ? LicenceStatus.none : state.statusAt(now());

  /// Emits the current status on listen, then every change.
  Stream<LicenceStatus> get licenceChanges => Stream.multi((controller) {
    controller.add(licence);
    final StreamSubscription<LicenceStatus> subscription = _licenceChanges.stream.listen(controller.add);
    controller.onCancel = subscription.cancel;
  });

  /// Fires each time a write is turned away because the licence has ended, so
  /// the app can open the page that explains it, wherever the write came from.
  Stream<LicenceEnded> get refusals => _refusals.stream;

  /// Throws [LicenceEnded] when the licence has ended, and does nothing for a
  /// company on a normal plan. Every path that adds or changes a record on the
  /// phone calls this first, before it touches anything.
  void assertCanWrite() {
    if (_state == null) return;
    final LicenceStatus status = licence;
    if (!status.entriesBlocked) return;
    final refusal = LicenceEnded(endedAt: status.endsAt);
    // Screens that were drawn before the licence ran out catch up now.
    _emitLicence();
    if (!_refusals.isClosed) _refusals.add(refusal);
    throw refusal;
  }

  /// Keeps the latest moment seen, so a clock set back and a restart cannot
  /// take days back. Cheap: writes only when a minute has passed.
  Future<void> rememberTime() async {
    if (_state == null) return;
    final DateTime latest = now();
    final DateTime? saved = _seenSaved;
    if (saved != null && latest.difference(saved).abs() < const Duration(minutes: 1)) return;
    _seenSaved = latest;
    try {
      await _db.writeMeta(MetaKey.offlineSeen, latest.toIso8601String());
    } on Object catch (e) {
      // Closed under us at shutdown, or a full disk. The next call tries again.
      _seenSaved = saved;
      debugPrint('Could not remember the time: $e');
    }
  }

  /// Starts the one-minute watch that saves the time and flips the screens the
  /// moment the licence ends. Not started in tests, which move the clock by hand.
  void startWatching() {
    _clockTimer ??= Timer.periodic(const Duration(minutes: 1), (_) => unawaited(tick()));
  }

  /// One look at the time: saves it and tells anyone listening if the licence
  /// has crossed a line.
  Future<void> tick() async {
    await rememberTime();
    _emitLicence();
  }

  /// True for every mode: until the upload has finished, nothing syncs.
  bool get isLocalOnly => _state != null;

  /// Emits the current state on listen, then every change.
  Stream<OfflinePlanState?> get changes => Stream.multi((controller) {
    controller.add(_state);
    final StreamSubscription<OfflinePlanState?> subscription = _changes.stream.listen(controller.add);
    controller.onCancel = subscription.cancel;
  });

  /// Reads the stored state and checks the stored token, with no network.
  Future<void> load() async {
    final OfflinePlanState? stored = await readStored(_db);
    if (stored == null) return;
    await _readSeen();
    var current = stored;
    if (current.token != null && current.publicKey != null) {
      final LicenceClaims? claims = await verifyLicenceToken(
        current.token!,
        publicKeyBase64: current.publicKey!,
        deviceId: await _deviceId(),
      );
      current = claims == null
          ? current.copyWith(issue: LicenceIssue.invalid)
          : current.copyWith(
              expiresAt: claims.expiresAt,
              issuedAt: claims.issuedAt,
              issue: current.issue == LicenceIssue.invalid ? LicenceIssue.none : current.issue,
            );
    } else if (current.token != null || current.expiresAt != null) {
      // An end date with no token to back it, or a token with no key to check it
      // by, can only have been edited.
      current = current.copyWith(issue: LicenceIssue.invalid);
    }
    await _seedLocal();
    await _write(current);
    await rememberTime();
  }

  Future<void> _readSeen() async {
    final DateTime? saved = DateTime.tryParse(await _db.readMeta(MetaKey.offlineSeen) ?? '')?.toUtc();
    if (saved == null) return;
    _seenSaved = saved;
    if (_seen == null || saved.isAfter(_seen!)) _seen = saved;
  }

  static Future<OfflinePlanState?> readStored(AppDatabase db) async {
    final String? raw = await db.readMeta(MetaKey.offlinePlan);
    if (raw == null) return null;
    try {
      return OfflinePlanState.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
    } on Object {
      // Unreadable is still "on the plan": treating it as normal could send
      // local work to the server without asking.
      return const OfflinePlanState();
    }
  }

  /// Run by a sync that the server turned away with `offline_plan`, including
  /// from the background task, which has none of this object.
  static Future<void> markLocal(AppDatabase db, {String? upgradeUrl}) async {
    if (await db.readMeta(MetaKey.offlinePlan) != null) return;
    await db.writeMeta(
      MetaKey.offlinePlan,
      jsonEncode(OfflinePlanState(upgradeUrl: upgradeUrl).toJson()),
    );
  }

  /// Asks the server what this company is. Never throws.
  Future<void> check({bool force = false}) {
    return _asking ??= _ask(force).whenComplete(() => _asking = null);
  }

  Future<void> _ask(bool force) async {
    _lastAskFailed = false;
    try {
      final user = _session.user;
      // A contractor's login may call almost nothing, and has no plan of its own.
      if (user == null || user.isContractor || !_connectivity.isOnline) return;
      if (!force && !_isDue()) return;
      _lastAsked = now();

      final Map<String, dynamic> status = await _api.offlineStatus();
      if (status['offline'] == true) {
        await _enterOrStayLocal();
        await _renew();
      } else if (status['offline'] == false) {
        await _serverSyncs();
      }
    } on AuthExpired {
      if (_state != null) await _write(_state!.copyWith(needsSignIn: true));
    } on OfflinePlanRefused {
      await _enterOrStayLocal();
    } on ApiFailure catch (e) {
      // A renewal that met no answer should not wait out the whole interval.
      _lastAskFailed = true;
      if (_state != null) _lastAsked = null;
      debugPrint('Plan check failed: $e');
    } on Object catch (e) {
      _lastAskFailed = true;
      debugPrint('Plan check failed: $e');
    }
  }

  bool _isDue() {
    final DateTime? last = _lastAsked;
    if (last == null) return true;
    final OfflinePlanState? current = _state;
    final Duration waited = now().difference(last);
    if (current == null) return waited >= normalInterval;
    // In the last week, or past the end, every chance online is used.
    final LicenceStanding standing = current.standing(now());
    final bool nearEnd = standing != LicenceStanding.active;
    return waited >= (nearEnd ? nearEndInterval : localInterval);
  }

  /// Asks for a new licence right now and says what came of it. Never throws.
  /// A fresh token lifts the block as it arrives, with no restart.
  Future<LicenceRenewal> renewNow() async {
    if (_state == null) return LicenceRenewal.notNeeded;
    if (!_connectivity.isOnline) return LicenceRenewal.offline;
    // A check already in flight may have decided it was not due.
    final Future<void>? running = _asking;
    if (running != null) await running;
    await check(force: true);

    final OfflinePlanState? after = _state;
    if (after == null || after.mode != OfflineMode.local) return LicenceRenewal.planChanged;
    if (after.needsSignIn) return LicenceRenewal.needsSignIn;
    if (after.issue == LicenceIssue.revoked) return LicenceRenewal.freed;
    if (after.issue == LicenceIssue.deviceLimit) return LicenceRenewal.deviceLimit;
    if (_lastAskFailed) return LicenceRenewal.noAnswer;
    return licence.entriesBlocked ? LicenceRenewal.stillEnded : LicenceRenewal.renewed;
  }

  /// The sync engine was told `offline_plan`, or wrote it down in the background.
  Future<void> adoptRefusal({String? upgradeUrl}) async {
    await markLocal(_db, upgradeUrl: upgradeUrl);
    _state = await readStored(_db);
    await _seedLocal();
    _watchConnectivity();
    _emit();
    unawaited(check(force: true));
  }

  Future<void> _enterOrStayLocal() async {
    final OfflinePlanState? current = _state;
    if (current == null) {
      await _seedLocal();
      await _write(const OfflinePlanState());
    } else if (current.mode != OfflineMode.local) {
      // The plan is offline again, so there is nothing to ask about for now.
      await _write(current.copyWith(mode: OfflineMode.local));
    }
    if (_state!.needsSignIn) await _write(_state!.copyWith(needsSignIn: false));
  }

  /// The server no longer says offline. Nothing is sent from here: the most
  /// this ever does is ask, once.
  Future<void> _serverSyncs() async {
    final OfflinePlanState? current = _state;
    if (current == null) return;
    if (current.mode != OfflineMode.local) return;

    if ((await work()).isEmpty) {
      // Nothing to lose and nothing to ask about.
      await _db.writeMeta(MetaKey.fullRefresh, '1');
      await leave();
      return;
    }
    await _write(current.copyWith(mode: OfflineMode.asking));
  }

  Future<void> _renew({bool activateNow = false}) async {
    final OfflinePlanState current = _state!;
    if (current.issue == LicenceIssue.revoked && !activateNow) return;
    final bool activate = activateNow || current.token == null;
    final String deviceId = await _deviceId();
    try {
      final Map<String, dynamic> issued = activate
          ? await _api.activateDevice(
              deviceId: deviceId,
              deviceName: _deviceName,
              platform: _platform,
              appVersion: appVersion,
            )
          : await _api.refreshDevice(deviceId: deviceId, appVersion: appVersion);
      await _store(issued, deviceId);
    } on ServerFailure catch (e) {
      switch (e.code) {
        case 'device_limit':
          await _write(
            _state!.copyWith(issue: LicenceIssue.deviceLimit, manageUrl: _manageUrlIn(e) ?? _defaultManageUrl),
          );
        case 'licence_revoked':
          // A freed phone keeps its token until it runs out. Taking the slot
          // back by itself would undo what the owner just did on the web. The
          // server says where, in `manage_url`; the built address is only for a
          // server that does not.
          await _write(_state!.copyWith(issue: LicenceIssue.revoked, manageUrl: _manageUrlIn(e) ?? _defaultManageUrl));
        case 'licence_missing' when !activate:
          await _renew(activateNow: true);
        case 'not_offline_plan':
          await _serverSyncs();
        default:
          debugPrint('Licence renewal failed: $e');
      }
    }
  }

  Future<void> _store(Map<String, dynamic> issued, String deviceId) async {
    final String? token = issued['token'] as String?;
    final Object? key = issued['signing_key'];
    final String? publicKey = key is Map ? key['public_key'] as String? : null;
    final String? keyId = key is Map ? key['key_id'] as String? : null;
    if (token == null || publicKey == null) return;

    final LicenceClaims? claims = await verifyLicenceToken(token, publicKeyBase64: publicKey, deviceId: deviceId);
    if (claims == null) {
      // Keep whatever was working: a bad reply must not take a licence away.
      await _write(_state!.copyWith(issue: _state!.hasToken ? null : LicenceIssue.invalid));
      debugPrint('Licence token failed its signature check.');
      return;
    }
    // The server's own signed time is the one moment that can be believed, so a
    // phone whose clock once ran far ahead recovers as soon as it renews.
    final DateTime? signed = claims.issuedAt;
    if (signed != null) _seen = signed;
    await _write(
      _state!.copyWith(
        token: token,
        publicKey: publicKey,
        keyId: keyId,
        expiresAt: claims.expiresAt,
        issuedAt: signed,
        issue: LicenceIssue.none,
        clearManageUrl: true,
      ),
    );
    if (signed != null) {
      _seenSaved = null;
      await rememberTime();
    }
  }

  /// The owner asked to take a slot, after a phone was freed.
  Future<void> activateAgain() async {
    if (_state == null) return;
    try {
      await _renew(activateNow: true);
    } on ApiFailure catch (e) {
      debugPrint('Activation failed: $e');
    }
  }

  /// Gives this phone's slot back, for moving to a new phone. The token stays
  /// usable until it runs out, and nothing on the phone is touched.
  ///
  /// Throws [ApiFailure] so the screen can say what happened.
  Future<void> release() async {
    if (_state == null) return;
    await _api.releaseDevice(await _deviceId());
    await _write(_state!.copyWith(issue: LicenceIssue.revoked, manageUrl: _defaultManageUrl));
  }

  Future<void> decline() async {
    if (_state != null) await _write(_state!.copyWith(mode: OfflineMode.declined));
  }

  Future<void> beginUpload() async {
    if (_state != null) await _write(_state!.copyWith(mode: OfflineMode.uploading));
  }

  /// A person signed out on purpose, or asked to sign in again.
  Future<void> noteSignedOut() async {
    if (_state != null) await _write(_state!.copyWith(signedOut: true));
  }

  Future<void> noteSignedIn() async {
    final OfflinePlanState? current = _state;
    if (current != null && (current.signedOut || current.needsSignIn)) {
      await _write(current.copyWith(signedOut: false, needsSignIn: false));
    }
  }

  /// Back to being a normal company.
  Future<void> leave() async {
    await _db.deleteMeta(MetaKey.offlinePlan);
    await _db.deleteMeta(MetaKey.offlineSeen);
    _state = null;
    _seen = null;
    _seenSaved = null;
    unawaited(_connectivitySub?.cancel());
    _connectivitySub = null;
    _emit();
  }

  /// A request the server would refuse on this plan. The sync engine already
  /// stops itself; this is the backstop for anything else that tries.
  bool blocksPath(String path) {
    final OfflinePlanState? current = _state;
    if (current == null || current.mode == OfflineMode.uploading) return false;
    return !(path == '/me' || path.startsWith('/auth/') || path.startsWith('/offline/'));
  }

  /// Counts what would be lost with this phone. Local records have negative ids.
  Future<OfflineWork> work() async {
    final QueryRow row = await _db
        .customSelect(
          'SELECT '
          '(SELECT COUNT(*) FROM projects WHERE id < 0) AS projects, '
          '(SELECT COUNT(*) FROM boq_items WHERE id < 0) AS boq, '
          '(SELECT COUNT(*) FROM workers WHERE id < 0) AS workers, '
          '(SELECT COUNT(*) FROM wallets WHERE id < 0) AS wallets, '
          "(SELECT COUNT(*) FROM outbox WHERE status <> 'done') AS queued, "
          '(SELECT COUNT(*) FROM dprs WHERE local_draft = 1) AS drafts',
        )
        .getSingle();
    return OfflineWork(
      projects: row.read<int>('projects'),
      boqLines: row.read<int>('boq'),
      workers: row.read<int>('workers'),
      wallets: row.read<int>('wallets'),
      entries: row.read<int>('queued') + row.read<int>('drafts'),
    );
  }

  /// A stable id for this install. Kept through sign-outs on purpose: a new
  /// one would take a second licence slot.
  Future<String> _deviceId() async {
    final String? existing = await _db.readMeta(MetaKey.offlineDeviceId);
    if (existing != null) return existing;
    final String created = 'n360-${newUuid().replaceAll('-', '')}';
    await _db.writeMeta(MetaKey.offlineDeviceId, created);
    return created;
  }

  /// Forms need these lists, which normally come with the first pull. They
  /// match the server's `config/buildos.php`.
  Future<void> _seedLocal() async {
    if (await _db.readMeta(MetaKey.lookups) == null) {
      await _db.writeMeta(MetaKey.lookups, jsonEncode(_localLookups));
    }
    final user = _session.user;
    if (user != null) {
      await _db.into(_db.users).insertOnConflictUpdate(
        UsersCompanion(id: Value(user.id), name: Value(user.name), role: Value(user.role)),
      );
    }
  }

  Future<void> _write(OfflinePlanState next) async {
    if (next == _state && await _db.readMeta(MetaKey.offlinePlan) != null) return;
    await _db.writeMeta(MetaKey.offlinePlan, jsonEncode(next.toJson()));
    _state = next;
    _watchConnectivity();
    _emit();
  }

  void _emit() {
    if (!_changes.isClosed) _changes.add(_state);
    _emitLicence();
  }

  void _emitLicence() {
    final LicenceStatus status = licence;
    if (status == _lastStatus) return;
    _lastStatus = status;
    if (!_licenceChanges.isClosed) _licenceChanges.add(status);
  }

  /// The moment the phone is online, a phone on the plan asks for a new licence
  /// by itself. The scheduler does the same on its own beat; this one needs no
  /// timer, so the renewal never waits on a sync run.
  void _watchConnectivity() {
    if (_connectivitySub != null) return;
    _connectivitySub = _connectivity.changes.listen((online) {
      if (online && _state != null) unawaited(check());
    });
  }

  void _stopWatching() {
    unawaited(_connectivitySub?.cancel());
    _connectivitySub = null;
    _clockTimer?.cancel();
    _clockTimer = null;
  }

  /// Where the owner frees phones, as the server named it in its refusal
  /// (`device_limit` and `licence_revoked` both carry `manage_url`). Null when
  /// the reply has none, or has something that is not an http address.
  static String? _manageUrlIn(ServerFailure failure) {
    final Object? raw = failure.data?['manage_url'];
    if (raw is! String) return null;
    final String url = raw.trim();
    final Uri? parsed = Uri.tryParse(url);
    return parsed != null && (parsed.scheme == 'http' || parsed.scheme == 'https') && parsed.host.isNotEmpty ? url : null;
  }

  /// Where the owner frees phones when the server did not say. Built from the
  /// address the app talks to.
  static String get _defaultManageUrl {
    final String base = AppConfig.apiBaseUrl;
    final int cut = base.indexOf('/api/');
    return '${cut < 0 ? base : base.substring(0, cut)}/offline/devices';
  }

  String get _platform => Platform.isAndroid
      ? 'android'
      : Platform.isIOS
      ? 'ios'
      : 'other';

  String get _deviceName => switch (_platform) {
    'android' => '${AppConfig.deviceName} on Android',
    'ios' => '${AppConfig.deviceName} on iPhone',
    _ => AppConfig.deviceName,
  };

  Future<void> dispose() async {
    _stopWatching();
    await _changes.close();
    await _licenceChanges.close();
    await _refusals.close();
  }
}

const Map<String, Object> _localLookups = <String, Object>{
  'trades': <String>['Mason', 'Helper', 'Carpenter', 'Bar bender', 'Electrician', 'Plumber', 'Painter', 'Welder', 'Operator', 'Supervisor'],
  'units': <String>['cum', 'sqm', 'rmt', 'nos', 'kg', 'MT', 'ltr', 'bag', 'LS'],
  'expense_categories': <String>['Fuel', 'Food', 'Travel', 'Tools', 'Materials', 'Labour', 'Repairs', 'Other'],
  'issue_priorities': <String>['low', 'medium', 'high', 'critical'],
  'issue_statuses': <String>['open', 'in_progress', 'resolved', 'closed'],
  'attendance_statuses': <String>['present', 'half', 'absent', 'overtime'],
  'weather': <String>['Clear', 'Cloudy', 'Rain', 'Windy', 'Hot'],
  'measurement_tolerance_pct': '5',
};
