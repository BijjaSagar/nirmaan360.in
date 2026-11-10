import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../data/notification_preferences.dart';

enum NotificationSettingsLoad { loading, ready, failed }

class NotificationSettingsState extends Equatable {
  const NotificationSettingsState({
    this.load = NotificationSettingsLoad.loading,
    this.preferences,
    this.failure,
    this.saving = false,
    this.saveFailures = 0,
  });

  final NotificationSettingsLoad load;
  final NotificationPreferences? preferences;

  /// Why the switches could not be read, or why the last change did not save.
  final ApiFailure? failure;

  /// A change is on its way. Switches wait, so two changes cannot cross.
  final bool saving;

  /// Goes up by one for each change the server did not take, so the screen
  /// can say so once.
  final int saveFailures;

  NotificationSettingsState copyWith({
    NotificationSettingsLoad? load,
    NotificationPreferences? preferences,
    ApiFailure? failure,
    bool clearFailure = false,
    bool? saving,
    int? saveFailures,
  }) => NotificationSettingsState(
    load: load ?? this.load,
    preferences: preferences ?? this.preferences,
    failure: clearFailure ? null : (failure ?? this.failure),
    saving: saving ?? this.saving,
    saveFailures: saveFailures ?? this.saveFailures,
  );

  @override
  List<Object?> get props => <Object?>[load, preferences, failure, saving, saveFailures];
}

/// The switches screen. A change shows at once and is undone if the server
/// does not take it.
class NotificationSettingsCubit extends Cubit<NotificationSettingsState> {
  NotificationSettingsCubit(this._repository) : super(const NotificationSettingsState());

  final NotificationPreferencesRepository _repository;

  Future<void> load() async {
    emit(state.copyWith(load: NotificationSettingsLoad.loading, clearFailure: true));
    try {
      final NotificationPreferences preferences = await _repository.load();
      if (isClosed) return;
      emit(NotificationSettingsState(load: NotificationSettingsLoad.ready, preferences: preferences, saveFailures: state.saveFailures));
    } on ApiFailure catch (failure) {
      if (isClosed) return;
      emit(state.copyWith(load: NotificationSettingsLoad.failed, failure: failure));
    }
  }

  /// The main push switch, for every phone of this person.
  Future<void> setPush(bool on) => _change((p) => p.copyWith(pushEnabled: on), <String, Object?>{'push': on});

  /// `email` or `whatsapp`.
  Future<void> setChannel(String channel, bool on) {
    return _change(
      (p) => switch (channel) {
        'email' => p.copyWith(email: p.email?.withEnabled(on)),
        'whatsapp' => p.copyWith(whatsapp: p.whatsapp?.withEnabled(on)),
        _ => p,
      },
      <String, Object?>{channel: on},
    );
  }

  Future<void> setCategory(String key, bool on) {
    return _change(
      (p) => p.copyWith(
        categories: <PushCategorySwitch>[
          for (final PushCategorySwitch category in p.categories) category.key == key ? category.withEnabled(on) : category,
        ],
      ),
      <String, Object?>{
        'categories': <String, bool>{key: on},
      },
    );
  }

  Future<void> setQuietHours(bool on) => _quiet((q) => q.copyWith(enabled: on), <String, Object?>{'enabled': on});

  /// [time] is `HH:MM`.
  Future<void> setQuietFrom(String time) => _quiet((q) => q.copyWith(from: time), <String, Object?>{'from': time});

  /// [time] is `HH:MM`.
  Future<void> setQuietUntil(String time) => _quiet((q) => q.copyWith(until: time), <String, Object?>{'until': time});

  Future<void> _quiet(QuietHoursSetting Function(QuietHoursSetting) edit, Map<String, Object?> changes) {
    return _change((p) => p.copyWith(quietHours: edit(p.quietHours)), <String, Object?>{'quiet_hours': changes});
  }

  Future<void> _change(NotificationPreferences Function(NotificationPreferences) edit, Map<String, Object?> changes) async {
    final NotificationPreferences? before = state.preferences;
    if (before == null || state.saving) return;
    emit(state.copyWith(preferences: edit(before), saving: true, clearFailure: true));
    try {
      final NotificationPreferences saved = await _repository.change(changes);
      if (isClosed) return;
      emit(state.copyWith(preferences: saved, saving: false));
    } on ApiFailure catch (failure) {
      if (isClosed) return;
      emit(state.copyWith(preferences: before, saving: false, failure: failure, saveFailures: state.saveFailures + 1));
    }
  }
}
