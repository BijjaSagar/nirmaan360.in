import 'package:flutter/foundation.dart';

/// What one platform needs to start Firebase.
@immutable
class FirebasePlatformSettings {
  const FirebasePlatformSettings({
    required this.apiKey,
    required this.appId,
    required this.senderId,
    required this.projectId,
    this.storageBucket,
    this.iosBundleId,
  });

  final String apiKey;
  final String appId;
  final String senderId;
  final String projectId;
  final String? storageBucket;
  final String? iosBundleId;

  // Never printed: a stray `print` of this object must not leak the key.
  @override
  String toString() => 'FirebasePlatformSettings(<hidden>)';
}

/// The app's Firebase settings, filled in when it is built.
///
/// They come from `--dart-define-from-file`, which `tool/firebase_defines.dart`
/// writes from the two files in the Firebase console. Nothing is in the repo.
/// A build without them has no push: [forPlatform] answers null, and nothing
/// else in the app touches Firebase.
@immutable
class FirebaseSettings {
  const FirebaseSettings({
    this.projectId = '',
    this.senderId = '',
    this.storageBucket = '',
    this.androidApiKey = '',
    this.androidAppId = '',
    this.iosApiKey = '',
    this.iosAppId = '',
    this.iosBundleId = '',
  });

  /// The settings this build was given. Empty unless it was built with the JSON.
  static const FirebaseSettings fromBuild = FirebaseSettings(
    projectId: String.fromEnvironment('FIREBASE_PROJECT_ID'),
    senderId: String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID'),
    storageBucket: String.fromEnvironment('FIREBASE_STORAGE_BUCKET'),
    androidApiKey: String.fromEnvironment('FIREBASE_ANDROID_API_KEY'),
    androidAppId: String.fromEnvironment('FIREBASE_ANDROID_APP_ID'),
    iosApiKey: String.fromEnvironment('FIREBASE_IOS_API_KEY'),
    iosAppId: String.fromEnvironment('FIREBASE_IOS_APP_ID'),
    iosBundleId: String.fromEnvironment('FIREBASE_IOS_BUNDLE_ID'),
  );

  final String projectId;
  final String senderId;
  final String storageBucket;
  final String androidApiKey;
  final String androidAppId;
  final String iosApiKey;
  final String iosAppId;
  final String iosBundleId;

  /// The settings for [platform], or null when this build has none for it.
  /// Only Android and iOS are ever answered.
  FirebasePlatformSettings? forPlatform(TargetPlatform platform) {
    if (projectId.isEmpty || senderId.isEmpty) return null;
    final String key;
    final String app;
    switch (platform) {
      case TargetPlatform.android:
        key = androidApiKey;
        app = androidAppId;
      case TargetPlatform.iOS:
        key = iosApiKey;
        app = iosAppId;
      case TargetPlatform.fuchsia || TargetPlatform.linux || TargetPlatform.macOS || TargetPlatform.windows:
        return null;
    }
    if (key.isEmpty || app.isEmpty) return null;
    return FirebasePlatformSettings(
      apiKey: key,
      appId: app,
      senderId: senderId,
      projectId: projectId,
      storageBucket: storageBucket.isEmpty ? null : storageBucket,
      iosBundleId: platform == TargetPlatform.iOS && iosBundleId.isNotEmpty ? iosBundleId : null,
    );
  }

  @override
  String toString() => 'FirebaseSettings(<hidden>)';
}
