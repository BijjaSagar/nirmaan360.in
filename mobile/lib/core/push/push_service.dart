import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_failure.dart';
import '../config/app_config.dart';
import '../db/app_database.dart';
import '../sync/connectivity.dart';
import 'device_registry.dart';
import 'push_message.dart';
import 'push_messaging.dart';

/// Where push stands for the person using this phone.
enum PushState {
  /// Nothing to offer: this build has no Firebase settings, nobody who may get
  /// push is signed in (signed out, a contractor login, the offline plan), or
  /// Firebase could not start.
  unavailable,

  /// The person has not been asked yet. Home explains, then asks once.
  askFirst,

  /// Not on, and the person can turn it on from Settings.
  off,

  /// The phone refuses notifications for this app. Only its Settings can change that.
  blocked,

  /// The phone may show notifications and this phone is registered.
  on,
}

/// Push notifications for the signed-in person: asking once, keeping this
/// phone's token on the server, and handing back what arrives or is tapped.
///
/// Until someone who may get push has signed in and said yes, the phone is not
/// registered with Firebase at all. Signing out takes the token off the server
/// and off the phone.
class PushService {
  PushService({
    required this._messaging,
    required this._devices,
    required this._db,
    required this._platform,
    this._deviceName = AppConfig.deviceName,
    ConnectivityMonitor? connectivity,
    this._patience = const Duration(seconds: 4),
  }) {
    _connectivity = connectivity?.changes.listen((online) {
      if (online) unawaited(retryPending());
    });
  }

  final PushMessaging _messaging;
  final DeviceRegistry _devices;
  final AppDatabase _db;
  final String _platform;
  final String _deviceName;
  final Duration _patience;

  StreamSubscription<bool>? _connectivity;
  final List<StreamSubscription<Object?>> _subscriptions = <StreamSubscription<Object?>>[];
  final StreamController<PushState> _states = StreamController<PushState>.broadcast();
  final StreamController<PushMessage> _foreground = StreamController<PushMessage>.broadcast();

  // Taps that came before anyone listened, such as the one that started the app.
  // They are handed over when the first listener arrives.
  late final StreamController<PushMessage> _taps = StreamController<PushMessage>.broadcast(onListen: _handOverEarlyTaps);
  final List<PushMessage> _earlyTaps = <PushMessage>[];

  PushState _state = PushState.unavailable;
  bool _started = false;
  bool _ready = false;
  bool _eligible = false;
  Future<void> _queue = Future<void>.value();

  /// A token the server has not accepted yet, to send again when it can.
  String? _pending;

  /// The token the server took in this run, so the same one is not sent twice.
  String? _registered;

  /// This build has Firebase settings and Firebase started.
  bool get available => _ready;

  PushState get state => _state;

  Stream<PushState> get states => _states.stream;

  /// A message that arrived while the app was open.
  Stream<PushMessage> get foreground => _foreground.stream;

  /// A notification the person tapped, from any state of the app. One that
  /// came before there was a listener is delivered to the first one.
  Stream<PushMessage> get taps => _taps.stream;

  /// Starts Firebase and listens. A build with no settings does nothing at all.
  /// Never throws.
  Future<void> start() async {
    if (_started) return;
    _started = true;
    if (!_messaging.isConfigured) return;
    try {
      await _messaging.initialise();
    } on Object catch (error) {
      debugPrint('Push could not start: ${error.runtimeType}');
      return;
    }
    _ready = true;
    _subscriptions
      ..add(_messaging.tokenRefreshes.listen(_onTokenRefresh, onError: _ignore))
      ..add(_messaging.foreground.listen(_foreground.add, onError: _ignore))
      ..add(_messaging.opened.listen(_emitTap, onError: _ignore));
    try {
      final PushMessage? launch = await _messaging.launchedBy();
      if (launch != null) _emitTap(launch);
    } on Object catch (error) {
      debugPrint('Push launch message unreadable: ${error.runtimeType}');
    }
    await _evaluate();
  }

  /// Says whether the person signed in may get push: a signed-in member of staff
  /// on a company that is not on the offline plan. Never a contractor login.
  Future<void> setEligible(bool eligible) async {
    if (eligible == _eligible) return;
    _eligible = eligible;
    await _evaluate();
  }

  /// "Turn on" in the explanation, or in Settings: asks the phone, once the
  /// person has agreed in the app's own words. Answers where push stands now.
  Future<PushState> turnOn() async {
    if (!_ready || !_eligible) return _state;
    await _db.writeMeta(MetaKey.pushExplained, '1');
    PushPermission permission;
    try {
      permission = await _messaging.requestPermission();
    } on Object catch (error) {
      debugPrint('Push permission request failed: ${error.runtimeType}');
      _set(PushState.off);
      return _state;
    }
    switch (permission) {
      case PushPermission.granted:
        await _activate();
      case PushPermission.denied || PushPermission.blocked:
        _set(PushState.blocked);
      case PushPermission.notAsked:
        _set(PushState.off);
    }
    return _state;
  }

  /// "Not now" in the explanation. It is not asked again; Settings still can.
  Future<void> declineForNow() async {
    await _db.writeMeta(MetaKey.pushExplained, '1');
    if (_state == PushState.askFirst) _set(PushState.off);
  }

  /// Takes this phone off the server and off Firebase. Call it while the person
  /// is still signed in, just before the sign-out reaches the server. It waits
  /// a few seconds at most and never throws, so signing out works offline.
  Future<void> forgetThisPhone() async {
    _eligible = false;
    _pending = null;
    _registered = null;
    if (!_ready) return;
    final String? token = await _db.readMeta(MetaKey.pushToken);
    if (token != null) {
      await _quietly(() => _devices.unregister(token));
      await _db.deleteMeta(MetaKey.pushToken);
    }
    await _quietly(_messaging.deleteToken);
    await _quietly(() => _messaging.setEnabled(false));
    _set(PushState.unavailable);
  }

  /// Reads the phone's permission again, for when the person comes back from its Settings.
  Future<void> refresh() => _evaluate();

  /// Sends a token the server did not take before, if there is one.
  Future<void> retryPending() async {
    final String? token = _pending;
    if (token == null) return;
    await _register(token);
  }

  Future<void> _evaluate() async {
    if (!_ready) return;
    if (!_eligible) {
      _set(PushState.unavailable);
      return;
    }
    final PushPermission permission;
    try {
      permission = await _messaging.permission();
    } on Object catch (error) {
      debugPrint('Push permission unreadable: ${error.runtimeType}');
      _set(PushState.unavailable);
      return;
    }
    if (!_eligible) return;
    switch (permission) {
      case PushPermission.granted:
        // Already on and registered: coming back to the app changes nothing.
        if (_state == PushState.on && _pending == null) return;
        await _activate();
      case PushPermission.notAsked:
        final bool explained = await _db.readMeta(MetaKey.pushExplained) != null;
        _set(explained ? PushState.off : PushState.askFirst);
      case PushPermission.denied:
        _set(PushState.off);
      case PushPermission.blocked:
        _set(PushState.blocked);
    }
  }

  Future<void> _activate() async {
    if (!_eligible) return;
    try {
      await _messaging.setEnabled(true);
    } on Object catch (error) {
      debugPrint('Push could not be switched on: ${error.runtimeType}');
    }
    _set(PushState.on);
    final String? token = await _messaging.token();
    if (token != null) await _register(token);
  }

  void _onTokenRefresh(String token) {
    if (_state == PushState.on) unawaited(_register(token));
  }

  Future<void> _register(String token) {
    // One at a time, so a refresh landing during the first send cannot overtake it.
    return _queue = _queue.then((_) => _send(token));
  }

  Future<void> _send(String token) async {
    if (!_eligible || _state != PushState.on || token == _registered) return;
    try {
      await _devices.register(token: token, platform: _platform, deviceName: _deviceName);
    } on ApiFailure catch (failure) {
      switch (failure) {
        case OfflineFailure() || ServerFailure() || RateLimited():
          _pending = token;
        case AuthExpired() || ValidationFailure() || OfflinePlanRefused() || LicenceEnded():
          // Sending the same token again would be refused the same way.
          _pending = null;
      }
      return;
    }
    _pending = null;
    _registered = token;
    final String? before = await _db.readMeta(MetaKey.pushToken);
    await _db.writeMeta(MetaKey.pushToken, token);
    // A renewed token replaces the old one, which would only pile up on the server.
    if (before != null && before != token) await _quietly(() => _devices.unregister(before));
  }

  Future<void> _quietly(Future<void> Function() action) async {
    try {
      await action().timeout(_patience);
    } on Object {
      // Best effort: the server drops a token Firebase no longer knows.
    }
  }

  void _emitTap(PushMessage message) {
    if (_taps.isClosed) return;
    if (_taps.hasListener) {
      _taps.add(message);
      return;
    }
    // Only the latest few matter: the person tapped one, and a stale pile helps nobody.
    _earlyTaps.add(message);
    if (_earlyTaps.length > 3) _earlyTaps.removeAt(0);
  }

  void _handOverEarlyTaps() {
    final List<PushMessage> waiting = List<PushMessage>.of(_earlyTaps);
    _earlyTaps.clear();
    for (final PushMessage message in waiting) {
      scheduleMicrotask(() {
        if (!_taps.isClosed) _taps.add(message);
      });
    }
  }

  void _set(PushState state) {
    if (state == _state) return;
    _state = state;
    if (!_states.isClosed) _states.add(state);
  }

  void _ignore(Object error) {
    debugPrint('Push stream error: ${error.runtimeType}');
  }

  Future<void> dispose() async {
    await _connectivity?.cancel();
    for (final StreamSubscription<Object?> subscription in _subscriptions) {
      await subscription.cancel();
    }
    await _states.close();
    await _foreground.close();
    await _taps.close();
  }
}
