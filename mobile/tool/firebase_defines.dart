// Builds the dart-define JSON that gives the app its Firebase settings.
//
// The two files from the Firebase console are read, and only a JSON file is
// written. Nothing from either file is printed: the output says which parts
// were found and where the JSON went, and nothing else.
//
//   dart run tool/firebase_defines.dart \
//     --android ~/Downloads/google-services.json \
//     --ios ~/Downloads/GoogleService-Info.plist
//
// Then build with the result:
//
//   flutter build apk --dart-define-from-file=firebase.defines.json
//   flutter build ipa --dart-define-from-file=firebase.defines.json
//
// Without that flag the app builds and runs, with no push notifications. The
// JSON is git-ignored, and the script refuses to write it anywhere git would
// pick it up. Either file alone is fine: that platform's push stays off.
import 'dart:convert';
import 'dart:io';

/// The application id and bundle id both stores know the app by.
const String expectedAppId = 'in.nirmaan360.app';

/// Thrown for a file that cannot be used. The message never carries a value
/// read from the file.
class DefinesProblem implements Exception {
  const DefinesProblem(this.message);

  final String message;

  @override
  String toString() => message;
}

/// What `google-services.json` holds, as the dart-define keys.
///
/// The client for [appId] is the one used; a file for a different app is refused.
Map<String, String> fromGoogleServices(String text, {String appId = expectedAppId}) {
  final Object? decoded;
  try {
    decoded = jsonDecode(text);
  } on FormatException {
    throw const DefinesProblem('The Android file is not valid JSON.');
  }
  if (decoded is! Map) throw const DefinesProblem('The Android file is not a google-services.json.');
  final Object? info = decoded['project_info'];
  final Object? clients = decoded['client'];
  if (info is! Map || clients is! List) {
    throw const DefinesProblem('The Android file is not a google-services.json.');
  }

  Map<dynamic, dynamic>? client;
  for (final Object? candidate in clients) {
    if (candidate is! Map) continue;
    final Object? clientInfo = candidate['client_info'];
    final Object? android = clientInfo is Map ? clientInfo['android_client_info'] : null;
    if (android is Map && android['package_name'] == appId) client = candidate;
  }
  if (client == null) {
    throw const DefinesProblem('The Android file has no app with the id $expectedAppId.');
  }

  final String? appKey = _firstString((client['client_info'] as Map)['mobilesdk_app_id']);
  final Object? keys = client['api_key'];
  final String? apiKey = keys is List && keys.isNotEmpty && keys.first is Map
      ? _firstString((keys.first as Map)['current_key'])
      : null;
  final String? project = _firstString(info['project_id']);
  final String? sender = _firstString(info['project_number']);
  if (appKey == null || apiKey == null || project == null || sender == null) {
    throw const DefinesProblem('The Android file is missing its app id, key, project id or sender id.');
  }
  return <String, String>{
    'FIREBASE_PROJECT_ID': project,
    'FIREBASE_MESSAGING_SENDER_ID': sender,
    'FIREBASE_ANDROID_API_KEY': apiKey,
    'FIREBASE_ANDROID_APP_ID': appKey,
    if (_firstString(info['storage_bucket']) case final String bucket) 'FIREBASE_STORAGE_BUCKET': bucket,
  };
}

/// What `GoogleService-Info.plist` holds, as the dart-define keys. Only the XML
/// form that the console gives is read.
Map<String, String> fromPlist(String text, {String bundleId = expectedAppId}) {
  if (text.startsWith('bplist')) {
    throw const DefinesProblem('The iOS file is a binary plist. Use the XML file from the Firebase console.');
  }
  String? read(String key) {
    final RegExpMatch? match = RegExp(
      '<key>${RegExp.escape(key)}</key>\\s*<string>([^<]*)</string>',
    ).firstMatch(text);
    return match == null ? null : _firstString(_unescape(match.group(1)!));
  }

  final String? bundle = read('BUNDLE_ID');
  if (bundle != bundleId) throw const DefinesProblem('The iOS file is for a different app than $expectedAppId.');
  final String? appKey = read('GOOGLE_APP_ID');
  final String? apiKey = read('API_KEY');
  final String? project = read('PROJECT_ID');
  final String? sender = read('GCM_SENDER_ID');
  if (appKey == null || apiKey == null || project == null || sender == null) {
    throw const DefinesProblem('The iOS file is missing its app id, key, project id or sender id.');
  }
  return <String, String>{
    'FIREBASE_PROJECT_ID': project,
    'FIREBASE_MESSAGING_SENDER_ID': sender,
    'FIREBASE_IOS_API_KEY': apiKey,
    'FIREBASE_IOS_APP_ID': appKey,
    'FIREBASE_IOS_BUNDLE_ID': bundle!,
    if (read('STORAGE_BUCKET') case final String bucket) 'FIREBASE_STORAGE_BUCKET': bucket,
  };
}

/// Joins the two platforms. They must belong to one Firebase project.
Map<String, String> mergeDefines(Map<String, String>? android, Map<String, String>? ios) {
  if (android == null && ios == null) {
    throw const DefinesProblem('Give at least one of --android and --ios.');
  }
  if (android != null && ios != null) {
    for (final String key in const <String>['FIREBASE_PROJECT_ID', 'FIREBASE_MESSAGING_SENDER_ID']) {
      if (android[key] != ios[key]) {
        throw const DefinesProblem('The two files belong to different Firebase projects.');
      }
    }
  }
  return <String, String>{...?android, ...?ios};
}

/// The JSON text for [defines], stable and indented so a diff reads well.
String encodeDefines(Map<String, String> defines) {
  final List<String> keys = defines.keys.toList()..sort();
  return '${const JsonEncoder.withIndent('  ').convert(<String, String>{for (final String key in keys) key: defines[key]!})}\n';
}

String? _firstString(Object? value) {
  if (value is! String) return null;
  final String trimmed = value.trim();
  return trimmed.isEmpty ? null : trimmed;
}

String _unescape(String value) => value
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'")
    .replaceAll('&amp;', '&');

String _expandHome(String path) {
  final String? home = Platform.environment['HOME'];
  return home != null && path.startsWith('~/') ? '$home${path.substring(1)}' : path;
}

/// True when git would add [path] to a commit; false when it is ignored or when
/// it is not inside a repository at all.
bool _gitWouldTrack(String path) {
  try {
    final File file = File(path);
    final Directory parent = file.absolute.parent;
    final ProcessResult result = Process.runSync(
      'git',
      <String>['check-ignore', '-q', '--', file.absolute.path],
      workingDirectory: parent.existsSync() ? parent.path : null,
    );
    return result.exitCode == 1;
  } on ProcessException {
    return false;
  }
}

void main(List<String> args) {
  String? android;
  String? ios;
  String out = 'firebase.defines.json';
  for (int i = 0; i < args.length; i++) {
    switch (args[i]) {
      case '--android':
        android = i + 1 < args.length ? args[++i] : null;
      case '--ios':
        ios = i + 1 < args.length ? args[++i] : null;
      case '--out':
        out = i + 1 < args.length ? args[++i] : out;
      case '-h' || '--help':
        stdout.writeln('dart run tool/firebase_defines.dart [--android google-services.json] [--ios GoogleService-Info.plist] [--out firebase.defines.json]');
        return;
      default:
        stderr.writeln('Unknown argument ${args[i]}. Use --help.');
        exit(64);
    }
  }
  try {
    Map<String, String>? fromAndroid;
    Map<String, String>? fromIos;
    if (android != null) fromAndroid = fromGoogleServices(_read(android, 'Android'));
    if (ios != null) fromIos = fromPlist(_read(ios, 'iOS'));
    final Map<String, String> defines = mergeDefines(fromAndroid, fromIos);
    if (_gitWouldTrack(out)) {
      throw const DefinesProblem('Git does not ignore that output path, so it was not written. Add it to .gitignore first.');
    }
    final File file = File(out)..writeAsStringSync(encodeDefines(defines));
    if (!Platform.isWindows) Process.runSync('chmod', <String>['600', file.path]);
    stdout.writeln(
      'Wrote $out. Android: ${fromAndroid == null ? 'not included' : 'ok'}. '
      'iOS: ${fromIos == null ? 'not included' : 'ok'}. Keep it out of git.',
    );
  } on DefinesProblem catch (problem) {
    stderr.writeln(problem.message);
    exit(1);
  }
}

String _read(String path, String which) {
  final File file = File(_expandHome(path));
  if (!file.existsSync()) throw DefinesProblem('The $which file was not found at the path given.');
  return file.readAsStringSync();
}
