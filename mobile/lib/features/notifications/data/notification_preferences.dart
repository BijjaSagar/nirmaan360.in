import 'package:equatable/equatable.dart';

import '../../../core/api/nirmaan_api.dart';

/// A channel the server can reach the person on, other than this app.
class ChannelSwitch extends Equatable {
  const ChannelSwitch({required this.enabled, required this.connected});

  factory ChannelSwitch.fromJson(Map<String, dynamic> json) =>
      ChannelSwitch(enabled: json['enabled'] == true, connected: json['connected'] == true);

  final bool enabled;

  /// The office has set the channel up. A switch for one it has not is not shown.
  final bool connected;

  ChannelSwitch withEnabled(bool on) => ChannelSwitch(enabled: on, connected: connected);

  @override
  List<Object?> get props => <Object?>[enabled, connected];
}

/// One kind of push the person can turn off: `alerts`, `approvals`, `payments`,
/// `material`, `issues`, or `contractor` for a contractor login.
class PushCategorySwitch extends Equatable {
  const PushCategorySwitch({required this.key, required this.label, required this.hint, required this.enabled});

  final String key;

  /// The server's words, used only for a kind this app does not know yet.
  final String label;
  final String hint;
  final bool enabled;

  PushCategorySwitch withEnabled(bool on) => PushCategorySwitch(key: key, label: label, hint: hint, enabled: on);

  @override
  List<Object?> get props => <Object?>[key, label, hint, enabled];
}

/// Hours in which a push that is not critical waits for the morning.
/// Times are `HH:MM` on the company's clock ([timezone]).
class QuietHoursSetting extends Equatable {
  const QuietHoursSetting({required this.enabled, required this.from, required this.until, required this.timezone});

  final bool enabled;
  final String from;
  final String until;
  final String timezone;

  QuietHoursSetting copyWith({bool? enabled, String? from, String? until}) => QuietHoursSetting(
    enabled: enabled ?? this.enabled,
    from: from ?? this.from,
    until: until ?? this.until,
    timezone: timezone,
  );

  @override
  List<Object?> get props => <Object?>[enabled, from, until, timezone];
}

/// The person's own notification switches, as `GET /notification-preferences`
/// answers them. Reading never throws: a missing part is read as off or empty.
class NotificationPreferences extends Equatable {
  const NotificationPreferences({
    required this.pushEnabled,
    required this.pushConnected,
    required this.categories,
    required this.quietHours,
    this.email,
    this.whatsapp,
  });

  factory NotificationPreferences.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> push = _map(json['push']);
    final Map<String, dynamic> quiet = _map(push['quiet_hours']);
    final Object? categories = push['categories'];
    return NotificationPreferences(
      pushEnabled: push['enabled'] == true,
      pushConnected: push['connected'] == true,
      categories: <PushCategorySwitch>[
        if (categories is List)
          for (final Object? row in categories)
            if (row is Map && row['key'] is String)
              PushCategorySwitch(
                key: row['key'] as String,
                label: row['label']?.toString() ?? row['key'] as String,
                hint: row['hint']?.toString() ?? '',
                enabled: row['enabled'] == true,
              ),
      ],
      quietHours: QuietHoursSetting(
        enabled: quiet['enabled'] == true,
        from: _time(quiet['from'], '22:00'),
        until: _time(quiet['until'], '07:00'),
        timezone: quiet['timezone']?.toString() ?? 'Asia/Kolkata',
      ),
      email: json['email'] is Map ? ChannelSwitch.fromJson(_map(json['email'])) : null,
      whatsapp: json['whatsapp'] is Map ? ChannelSwitch.fromJson(_map(json['whatsapp'])) : null,
    );
  }

  /// The main push switch, for every phone of this person.
  final bool pushEnabled;

  /// The office has connected Firebase, so push can be sent at all.
  final bool pushConnected;
  final List<PushCategorySwitch> categories;
  final QuietHoursSetting quietHours;

  /// Absent for a contractor login, which is reached by push alone.
  final ChannelSwitch? email;
  final ChannelSwitch? whatsapp;

  NotificationPreferences copyWith({
    bool? pushEnabled,
    List<PushCategorySwitch>? categories,
    QuietHoursSetting? quietHours,
    ChannelSwitch? email,
    ChannelSwitch? whatsapp,
  }) => NotificationPreferences(
    pushEnabled: pushEnabled ?? this.pushEnabled,
    pushConnected: pushConnected,
    categories: categories ?? this.categories,
    quietHours: quietHours ?? this.quietHours,
    email: email ?? this.email,
    whatsapp: whatsapp ?? this.whatsapp,
  );

  static Map<String, dynamic> _map(Object? value) =>
      value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

  static final RegExp _hhmm = RegExp(r'^([01]\d|2[0-3]):[0-5]\d$');

  static String _time(Object? value, String fallback) {
    final String? text = value?.toString();
    return text != null && _hhmm.hasMatch(text) ? text : fallback;
  }

  @override
  List<Object?> get props => <Object?>[pushEnabled, pushConnected, categories, quietHours, email, whatsapp];
}

/// Reads and changes the person's switches. Online only, by design: they live
/// on the server, which decides what to send. Throws `ApiFailure`.
class NotificationPreferencesRepository {
  /// [portal] answers whether the person signed in is a contractor login,
  /// which reaches the same routes under `/contractor-portal`.
  const NotificationPreferencesRepository(this._api, {required this._portal});

  final NirmaanApi _api;
  final bool Function() _portal;

  Future<NotificationPreferences> load() async =>
      NotificationPreferences.fromJson(await _api.notificationPreferences(portal: _portal()));

  /// Sends only what [changes] names, and answers with every switch as the server now has it.
  Future<NotificationPreferences> change(Map<String, Object?> changes) async =>
      NotificationPreferences.fromJson(await _api.updateNotificationPreferences(changes, portal: _portal()));
}
