/// Build-time configuration. Nothing here is a secret.
abstract final class AppConfig {
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://nirmaan360.in/api/v1',
  );

  /// Name of the sqlite file in the app documents directory.
  static const String databaseName = 'nirmaan360';

  /// Sent to `POST /auth/login` so tokens are identifiable on the web.
  static const String deviceName = 'Nirmaan360 field app';

  /// Workmanager task name and unique id for background sync.
  static const String backgroundSyncTask = 'in.nirmaan360.sync';
}
