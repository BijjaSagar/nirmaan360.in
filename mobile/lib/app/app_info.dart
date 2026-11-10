/// The app's version, kept in step with `pubspec.yaml`.
///
/// There is no package for reading it at runtime, so a test compares this with
/// the pubspec and fails when one changes without the other.
abstract final class AppInfo {
  static const String version = '1.0.0';
  static const String buildNumber = '12';

  static String get label => '$version ($buildNumber)';
}
