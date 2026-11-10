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

  LicenceStanding standing(DateTime now) {
    final DateTime? end = expiresAt;
    if (token == null || end == null) return LicenceStanding.none;
    if (issue == LicenceIssue.invalid || !end.isAfter(now)) return LicenceStanding.ended;
    if (end.difference(now) <= const Duration(days: 14)) return LicenceStanding.endingSoon;
    return LicenceStanding.active;
  }

  OfflinePlanState copyWith({
    OfflineMode? mode,
    String? token,
    String? publicKey,
    String? keyId,
    DateTime? expiresAt,
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

  final StreamController<OfflinePlanState?> _changes = StreamController<OfflinePlanState?>.broadcast();

  OfflinePlanState? _state;
  DateTime? _lastAsked;
  Future<void>? _asking;

  OfflinePlanState? get state => _state;

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
              issue: current.issue == LicenceIssue.invalid ? LicenceIssue.none : current.issue,
            );
    }
    await _seedLocal();
    await _write(current);
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
    try {
      final user = _session.user;
      // A contractor's login may call almost nothing, and has no plan of its own.
      if (user == null || user.isContractor || !_connectivity.isOnline) return;
      if (!force && !_isDue()) return;
      _lastAsked = _clock.now();

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
      debugPrint('Plan check failed: $e');
    } on Object catch (e) {
      debugPrint('Plan check failed: $e');
    }
  }

  bool _isDue() {
    final DateTime? last = _lastAsked;
    if (last == null) return true;
    return _clock.now().difference(last) >= (_state == null ? normalInterval : localInterval);
  }

  /// The sync engine was told `offline_plan`, or wrote it down in the background.
  Future<void> adoptRefusal({String? upgradeUrl}) async {
    await markLocal(_db, upgradeUrl: upgradeUrl);
    _state = await readStored(_db);
    await _seedLocal();
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
            _state!.copyWith(
              issue: LicenceIssue.deviceLimit,
              manageUrl: e.data?['manage_url'] as String? ?? _defaultManageUrl,
            ),
          );
        case 'licence_revoked':
          // A freed phone keeps its token until it runs out. Taking the slot
          // back by itself would undo what the owner just did on the web.
          await _write(_state!.copyWith(issue: LicenceIssue.revoked, manageUrl: _defaultManageUrl));
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
    await _write(
      _state!.copyWith(
        token: token,
        publicKey: publicKey,
        keyId: keyId,
        expiresAt: claims.expiresAt,
        issue: LicenceIssue.none,
        clearManageUrl: true,
      ),
    );
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
    _state = null;
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
    _emit();
  }

  void _emit() {
    if (!_changes.isClosed) _changes.add(_state);
  }

  /// Where the owner frees phones. The server names it with `device_limit`
  /// but not with `licence_revoked`.
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

  Future<void> dispose() => _changes.close();
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
