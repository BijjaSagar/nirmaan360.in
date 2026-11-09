import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';

/// Whether the device believes it has a network. A hint only: a connected
/// radio can still reach nothing, so real requests decide in the end.
abstract interface class ConnectivityMonitor {
  bool get isOnline;

  /// Emits on every change of [isOnline].
  Stream<bool> get changes;
}

class ConnectivityPlusMonitor implements ConnectivityMonitor {
  ConnectivityPlusMonitor([Connectivity? connectivity])
    : _connectivity = connectivity ?? Connectivity();

  final Connectivity _connectivity;
  final _changes = StreamController<bool>.broadcast();
  StreamSubscription<List<ConnectivityResult>>? _subscription;

  // Assume online until the first check, so a slow platform channel can
  // never make the app claim to be offline at start-up.
  bool _isOnline = true;

  @override
  bool get isOnline => _isOnline;

  @override
  Stream<bool> get changes => _changes.stream;

  /// Starts listening. Safe to call once at start-up.
  Future<void> start() async {
    _subscription ??= _connectivity.onConnectivityChanged.listen(_update);
    _update(await _connectivity.checkConnectivity());
  }

  void _update(List<ConnectivityResult> results) {
    final online = results.any((r) => r != ConnectivityResult.none);
    if (online != _isOnline) {
      _isOnline = online;
      _changes.add(online);
    }
  }

  Future<void> dispose() async {
    await _subscription?.cancel();
    await _changes.close();
  }
}
