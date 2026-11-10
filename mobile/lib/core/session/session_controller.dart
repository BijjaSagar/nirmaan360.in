import 'dart:async';
import 'dart:convert';

import '../db/app_database.dart';
import 'current_user.dart';
import 'token_store.dart';

enum SessionStatus {
  /// `restore` has not run yet.
  restoring,

  /// Nobody has signed in on this device.
  signedOut,

  /// A token and a cached user exist.
  signedIn,

  /// The server answered 401. Local work is intact; sign in again to resume.
  expired,
}

class SessionState {
  const SessionState(this.status, this.user);

  final SessionStatus status;
  final CurrentUser? user;

  bool get isSignedIn => status == SessionStatus.signedIn;
}

/// Holds who is signed in. Restoring reads only local storage, so the app
/// opens with no network and stays usable for as long as the phone is offline.
class SessionController {
  SessionController({required this._tokens, required this._db});

  final TokenStore _tokens;
  final AppDatabase _db;

  final _changes = StreamController<SessionState>.broadcast();
  SessionState _state = const SessionState(SessionStatus.restoring, null);

  /// Set by the offline plan. While it answers true, a 401 from the server
  /// does not end the session: the person's data is only on this phone, and
  /// a sign-in screen they cannot pass with no internet would lock them out.
  bool Function()? keepSessionOn401;

  SessionState get current => _state;
  CurrentUser? get user => _state.user;

  /// Emits the current state on listen, then every change.
  Stream<SessionState> get changes => Stream.multi((controller) {
    controller.add(_state);
    final subscription = _changes.stream.listen(controller.add);
    controller.onCancel = subscription.cancel;
  });

  Future<String?> readToken() => _tokens.read();

  /// Rebuilds the session from local storage. Never touches the network.
  Future<SessionState> restore() async {
    final token = await _tokens.read();
    final user = await _readCachedUser();
    if (token != null && token.isNotEmpty && user != null) {
      return _set(SessionState(SessionStatus.signedIn, user));
    }
    if (user != null) {
      return _set(SessionState(SessionStatus.expired, user));
    }
    return _set(const SessionState(SessionStatus.signedOut, null));
  }

  Future<void> startSession(String token, CurrentUser user) async {
    await _tokens.write(token);
    await _writeCachedUser(user);
    _set(SessionState(SessionStatus.signedIn, user));
  }

  /// Applies a fresh user object from a pull, e.g. changed permissions.
  Future<void> updateUser(CurrentUser user) async {
    await _writeCachedUser(user);
    if (_state.status == SessionStatus.signedIn) {
      _set(SessionState(SessionStatus.signedIn, user));
    }
  }

  /// A 401 was received. Drops the token only; nothing local is touched.
  Future<void> markExpired() async {
    if (keepSessionOn401?.call() ?? false) return;
    if (_state.status == SessionStatus.signedOut ||
        _state.status == SessionStatus.expired) {
      return;
    }
    await _tokens.clear();
    _set(SessionState(SessionStatus.expired, _state.user));
  }

  /// The offline plan's sign-out: the token goes, and the user stays known so
  /// the same person can come back to their data and nobody else can.
  Future<void> endKeepingUser() async {
    await _tokens.clear();
    _set(SessionState(SessionStatus.expired, _state.user));
  }

  /// The offline plan's restart: no usable token, but the data is here and
  /// nobody signed out, so the app stays open.
  void resumeLocally() {
    if (_state.status == SessionStatus.expired && _state.user != null) {
      _set(SessionState(SessionStatus.signedIn, _state.user));
    }
  }

  /// Ends the session completely. The caller has dealt with unsent work.
  Future<void> clear() async {
    await _tokens.clear();
    await _db.deleteMeta(MetaKey.sessionUser);
    _set(const SessionState(SessionStatus.signedOut, null));
  }

  Future<CurrentUser?> _readCachedUser() async {
    final raw = await _db.readMeta(MetaKey.sessionUser);
    if (raw == null) return null;
    return CurrentUser.fromJson(
      (jsonDecode(raw) as Map).cast<String, dynamic>(),
    );
  }

  Future<void> _writeCachedUser(CurrentUser user) {
    return _db.writeMeta(MetaKey.sessionUser, jsonEncode(user.toJson()));
  }

  SessionState _set(SessionState state) {
    _state = state;
    _changes.add(state);
    return state;
  }

  Future<void> dispose() => _changes.close();
}
