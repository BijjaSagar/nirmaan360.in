import '../api/api_failure.dart';
import '../api/nirmaan_api.dart';
import '../session/current_user.dart';
import '../session/session_controller.dart';
import '../sync/clock.dart';
import '../sync/connectivity.dart';
import 'read_watch.dart';

/// The document readers, as feature screens use them: whether to offer a
/// reader to this person, and watching a read while the server works on it.
/// The calls themselves are on [NirmaanApi], so the sign-in header and the
/// 401 handling apply to them as to every other request.
///
/// Readers are online only. Offline, signed out, as a contractor login, or
/// when the server cannot be asked, nothing is offered: this fails closed.
class Readers {
  Readers({
    required this._api,
    required this._session,
    required this._connectivity,
    this._clock = const SystemClock(),
    this.freshFor = const Duration(minutes: 5),
  });

  final NirmaanApi _api;
  final SessionController _session;
  final ConnectivityMonitor _connectivity;
  final AppClock _clock;

  /// How long an answer is reused before the server is asked again. An owner
  /// can switch the readers on or off on the website at any time.
  final Duration freshFor;

  ({int userId, DateTime at, ReaderAvailability value})? _last;

  /// What may be offered now. [refresh] asks the server even when a recent
  /// answer is kept, as after pull to refresh.
  Future<ReaderAvailability> availability({bool refresh = false}) async {
    final CurrentUser? user = _session.user;
    if (!_session.current.isSignedIn || user == null || user.isContractor) return ReaderAvailability.off;
    if (!_connectivity.isOnline) return ReaderAvailability.off;

    final DateTime now = _clock.now();
    final last = _last;
    if (!refresh && last != null && last.userId == user.id && now.difference(last.at) < freshFor) {
      return last.value;
    }
    try {
      final ReaderAvailability value = await _api.readerAvailability();
      _last = (userId: user.id, at: now, value: value);
      return value;
    } on ApiFailure {
      return ReaderAvailability.off;
    }
  }

  /// Drops the kept answer. Called whenever the session changes.
  void forget() => _last = null;

  /// Watches a read under the poll rule. See [ReadWatcher].
  Stream<ReadWatchEvent> watch(int readId) => ReadWatcher(_api, clock: _clock).watch(readId);
}
