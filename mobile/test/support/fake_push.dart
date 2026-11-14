import 'dart:async';

import 'package:nirmaan360/core/push/push_message.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';

/// Firebase Cloud Messaging under test control. Every token here is made up.
class FakePushMessaging implements PushMessaging {
  FakePushMessaging({
    this.configured = true,
    this.permissionNow = PushPermission.notAsked,
    this.answerToRequest = PushPermission.granted,
    this.tokenNow = 'fake-fcm-token-1',
  });

  bool configured;

  /// Where the phone's permission stands.
  PushPermission permissionNow;

  /// What the phone's own question gets answered; set [permissionNow] to match.
  PushPermission answerToRequest;
  String? tokenNow;

  /// The next `initialise` throws.
  bool failToStart = false;

  /// The next `deleteToken` throws.
  bool failToDelete = false;

  int initialiseCalls = 0;
  int permissionReads = 0;
  int permissionRequests = 0;
  int tokenReads = 0;
  int deleteCalls = 0;
  final List<bool> enabledCalls = <bool>[];

  /// The notification whose tap started the app.
  PushMessage? launch;

  final StreamController<String> _refreshes = StreamController<String>.broadcast();
  final StreamController<PushMessage> _foreground = StreamController<PushMessage>.broadcast();
  final StreamController<PushMessage> _opened = StreamController<PushMessage>.broadcast();

  bool get enabled => enabledCalls.isNotEmpty && enabledCalls.last;

  void refreshToken(String token) {
    tokenNow = token;
    _refreshes.add(token);
  }

  void deliver(PushMessage message) => _foreground.add(message);

  void tap(PushMessage message) => _opened.add(message);

  @override
  bool get isConfigured => configured;

  @override
  Future<void> initialise() async {
    initialiseCalls++;
    if (failToStart) throw StateError('firebase would not start');
  }

  @override
  Future<PushPermission> permission() async {
    permissionReads++;
    return permissionNow;
  }

  @override
  Future<PushPermission> requestPermission() async {
    permissionRequests++;
    permissionNow = answerToRequest;
    return permissionNow;
  }

  @override
  Future<void> setEnabled(bool enabled) async => enabledCalls.add(enabled);

  @override
  Future<String?> token() async {
    tokenReads++;
    return tokenNow;
  }

  @override
  Future<void> deleteToken() async {
    deleteCalls++;
    if (failToDelete) throw StateError('no network');
    tokenNow = null;
  }

  @override
  Stream<String> get tokenRefreshes => _refreshes.stream;

  @override
  Stream<PushMessage> get foreground => _foreground.stream;

  @override
  Stream<PushMessage> get opened => _opened.stream;

  @override
  Future<PushMessage?> launchedBy() async => launch;
}
