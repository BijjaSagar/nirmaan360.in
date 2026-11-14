import 'push_message.dart';

/// Where the phone's permission to show notifications stands.
enum PushPermission {
  /// The phone has never asked.
  notAsked,

  /// Notifications may be shown.
  granted,

  /// Refused, but the phone may ask once more (Android 13 allows a second ask).
  denied,

  /// Refused, and the phone will not ask again: only its Settings can change it.
  blocked,
}

/// Everything the app needs from Firebase Cloud Messaging, and nothing more.
///
/// The one real implementation is `FirebasePushMessaging`, the only code that
/// imports the Firebase packages. A build with no Firebase settings uses
/// [NoPushMessaging], and tests use a fake.
abstract interface class PushMessaging {
  /// False when this build has no Firebase settings: push is off, and nothing
  /// below is ever called.
  bool get isConfigured;

  /// Starts Firebase. Safe to call again. Throws when Firebase cannot start.
  Future<void> initialise();

  Future<PushPermission> permission();

  /// Shows the phone's own question, when it still will. Always answers with
  /// where the permission stands afterwards.
  Future<PushPermission> requestPermission();

  /// Lets the phone register with Firebase (true), or keeps it unregistered
  /// (false). Off is the state before anyone has signed in and agreed: until
  /// then Firebase learns nothing about this phone.
  Future<void> setEnabled(bool enabled);

  /// This phone's FCM token. Null when it has none yet; never throws.
  Future<String?> token();

  /// Forgets the token, so the phone stops getting this person's messages.
  Future<void> deleteToken();

  /// A new token, after the first one: the phone's registration was renewed.
  Stream<String> get tokenRefreshes;

  /// A message that arrived while the app was open.
  Stream<PushMessage> get foreground;

  /// A notification the person tapped while the app was running in the background.
  Stream<PushMessage> get opened;

  /// The notification whose tap started the app, once. Null when it started otherwise.
  Future<PushMessage?> launchedBy();
}

/// Push for a build with no Firebase settings.
class NoPushMessaging implements PushMessaging {
  const NoPushMessaging();

  @override
  bool get isConfigured => false;

  @override
  Future<void> initialise() async {}

  @override
  Future<PushPermission> permission() async => PushPermission.notAsked;

  @override
  Future<PushPermission> requestPermission() async => PushPermission.notAsked;

  @override
  Future<void> setEnabled(bool enabled) async {}

  @override
  Future<String?> token() async => null;

  @override
  Future<void> deleteToken() async {}

  @override
  Stream<String> get tokenRefreshes => const Stream<String>.empty();

  @override
  Stream<PushMessage> get foreground => const Stream<PushMessage>.empty();

  @override
  Stream<PushMessage> get opened => const Stream<PushMessage>.empty();

  @override
  Future<PushMessage?> launchedBy() async => null;
}
