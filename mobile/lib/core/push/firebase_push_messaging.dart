// The token calls are marked deprecated in firebase_messaging 17 in favour of
// `register()` and a Firebase installation id. The server (`FcmGateway`) sends to
// a registration token, which these calls still give, so they stay until the
// server's push API says otherwise.
// ignore_for_file: deprecated_member_use

import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import 'firebase_settings.dart';
import 'push_message.dart';
import 'push_messaging.dart';

FirebaseOptions _optionsFor(FirebasePlatformSettings settings) => FirebaseOptions(
  apiKey: settings.apiKey,
  appId: settings.appId,
  messagingSenderId: settings.senderId,
  projectId: settings.projectId,
  storageBucket: settings.storageBucket,
  iosBundleId: settings.iosBundleId,
);

/// Where the phone's permission stands, from Firebase's words for it.
@visibleForTesting
PushPermission pushPermissionFrom(AuthorizationStatus status, TargetPlatform platform) {
  switch (status) {
    case AuthorizationStatus.authorized || AuthorizationStatus.provisional:
      return PushPermission.granted;
    case AuthorizationStatus.notDetermined:
      return PushPermission.notAsked;
    case AuthorizationStatus.deniedPermanently:
      return PushPermission.blocked;
    case AuthorizationStatus.denied:
      // On Apple phones a refusal is final; on Android a first refusal may be asked again.
      return platform == TargetPlatform.iOS ? PushPermission.blocked : PushPermission.denied;
  }
}

PushMessage _fromRemote(RemoteMessage message) =>
    PushMessage.fromParts(title: message.notification?.title, body: message.notification?.body, data: message.data);

/// Runs when a message arrives while the app is in the background or closed.
///
/// It lives in its own isolate and must not open the local database, which the
/// app holds. The phone shows the notification by itself; the tap is handled
/// when the app opens. So this only makes sure Firebase is up and returns.
@pragma('vm:entry-point')
Future<void> firebaseBackgroundHandler(RemoteMessage message) async {
  final FirebasePlatformSettings? settings = FirebaseSettings.fromBuild.forPlatform(defaultTargetPlatform);
  if (settings == null) return;
  try {
    if (Firebase.apps.isEmpty) await Firebase.initializeApp(options: _optionsFor(settings));
  } on Object {
    // Nothing here is worth a crash in a background isolate.
  }
}

/// Firebase Cloud Messaging on Android and iOS, started from Dart with the
/// settings the app was built with. No `google-services.json` or
/// `GoogleService-Info.plist` is read by the native build.
class FirebasePushMessaging implements PushMessaging {
  FirebasePushMessaging({FirebaseSettings settings = FirebaseSettings.fromBuild, TargetPlatform? platform})
    : _platform = platform ?? defaultTargetPlatform,
      _settings = settings.forPlatform(platform ?? defaultTargetPlatform);

  final TargetPlatform _platform;
  final FirebasePlatformSettings? _settings;
  Future<void>? _starting;

  FirebaseMessaging get _messaging => FirebaseMessaging.instance;

  @override
  bool get isConfigured => _settings != null;

  @override
  Future<void> initialise() {
    final FirebasePlatformSettings? settings = _settings;
    if (settings == null) return Future<void>.value();
    return _starting ??= _start(settings).catchError((Object error, StackTrace stack) {
      // Let a later call try again rather than caching the failure.
      _starting = null;
      Error.throwWithStackTrace(error, stack);
    });
  }

  Future<void> _start(FirebasePlatformSettings settings) async {
    if (Firebase.apps.isEmpty) await Firebase.initializeApp(options: _optionsFor(settings));
    FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
  }

  @override
  Future<PushPermission> permission() async =>
      pushPermissionFrom((await _messaging.getNotificationSettings()).authorizationStatus, _platform);

  @override
  Future<PushPermission> requestPermission() async =>
      pushPermissionFrom((await _messaging.requestPermission()).authorizationStatus, _platform);

  @override
  Future<void> setEnabled(bool enabled) => _messaging.setAutoInitEnabled(enabled);

  @override
  Future<String?> token() async {
    try {
      return await _messaging.getToken();
    } on Object {
      // No APNs token yet, or no network. A refresh brings it when it exists.
      return null;
    }
  }

  @override
  Future<void> deleteToken() => _messaging.deleteToken();

  @override
  Stream<String> get tokenRefreshes => _messaging.onTokenRefresh;

  @override
  Stream<PushMessage> get foreground => FirebaseMessaging.onMessage.map(_fromRemote);

  @override
  Stream<PushMessage> get opened => FirebaseMessaging.onMessageOpenedApp.map(_fromRemote);

  @override
  Future<PushMessage?> launchedBy() async {
    final RemoteMessage? message = await _messaging.getInitialMessage();
    return message == null ? null : _fromRemote(message);
  }
}
