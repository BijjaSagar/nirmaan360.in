/// Build-time configuration. Nothing here is a secret.
abstract final class AppConfig {
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://nirmaan360.in/api/v1',
  );

  /// The website the API belongs to, from [apiBaseUrl]: where the terms, the
  /// privacy policy and the web screens are.
  static String get siteUrl {
    const String base = apiBaseUrl;
    final int cut = base.indexOf('/api/');
    return cut < 0 ? base : base.substring(0, cut);
  }

  /// Folders in the temporary directory that hold company data. Signing out
  /// and deleting the account clear them.
  static const String offlineExportFolder = 'offline_exports';
  static const String quotationFolder = 'quotations';

  /// Name of the sqlite file in the app documents directory.
  static const String databaseName = 'nirmaan360';

  /// Sent to `POST /auth/login` so tokens are identifiable on the web.
  static const String deviceName = 'Nirmaan360 field app';

  /// Workmanager task name and unique id for background sync.
  static const String backgroundSyncTask = 'in.nirmaan360.sync';
}
