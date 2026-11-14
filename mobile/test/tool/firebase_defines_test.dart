import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import '../../tool/firebase_defines.dart';

// Made-up contents, shaped like the console files. Nothing here is a real key.
String _googleServices({String package = 'in.nirmaan360.app', String project = 'test-project', String number = '111111111111'}) {
  return jsonEncode(<String, Object?>{
    'project_info': <String, Object?>{'project_number': number, 'project_id': project, 'storage_bucket': '$project.example'},
    'client': <Object?>[
      <String, Object?>{
        'client_info': <String, Object?>{
          'mobilesdk_app_id': '1:$number:android:other',
          'android_client_info': <String, Object?>{'package_name': 'com.example.other'},
        },
        'api_key': <Object?>[<String, Object?>{'current_key': 'other-key'}],
      },
      <String, Object?>{
        'client_info': <String, Object?>{
          'mobilesdk_app_id': '1:$number:android:aaaa',
          'android_client_info': <String, Object?>{'package_name': package},
        },
        'api_key': <Object?>[<String, Object?>{'current_key': 'android-key-for-tests'}],
      },
    ],
  });
}

String _plist({String bundle = 'in.nirmaan360.app', String project = 'test-project', String number = '111111111111'}) => '''
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict>
	<key>API_KEY</key>
	<string>ios-key-for-tests</string>
	<key>GCM_SENDER_ID</key>
	<string>$number</string>
	<key>BUNDLE_ID</key>
	<string>$bundle</string>
	<key>PROJECT_ID</key>
	<string>$project</string>
	<key>STORAGE_BUCKET</key>
	<string>$project.example</string>
	<key>GOOGLE_APP_ID</key>
	<string>1:$number:ios:bbbb</string>
</dict></plist>
''';

void main() {
  test('the Android file gives the app\'s own client, not another one in the file', () {
    final Map<String, String> defines = fromGoogleServices(_googleServices());
    expect(defines['FIREBASE_ANDROID_APP_ID'], '1:111111111111:android:aaaa');
    expect(defines['FIREBASE_ANDROID_API_KEY'], 'android-key-for-tests');
    expect(defines['FIREBASE_PROJECT_ID'], 'test-project');
    expect(defines['FIREBASE_MESSAGING_SENDER_ID'], '111111111111');
  });

  test('the iOS file gives its own key, app id and bundle id', () {
    final Map<String, String> defines = fromPlist(_plist());
    expect(defines['FIREBASE_IOS_APP_ID'], '1:111111111111:ios:bbbb');
    expect(defines['FIREBASE_IOS_API_KEY'], 'ios-key-for-tests');
    expect(defines['FIREBASE_IOS_BUNDLE_ID'], 'in.nirmaan360.app');
  });

  test('both together are one set of defines, and either alone is enough', () {
    final Map<String, String> merged = mergeDefines(fromGoogleServices(_googleServices()), fromPlist(_plist()));
    expect(merged.keys, containsAll(<String>['FIREBASE_ANDROID_APP_ID', 'FIREBASE_IOS_APP_ID', 'FIREBASE_PROJECT_ID']));
    expect(mergeDefines(null, fromPlist(_plist())).containsKey('FIREBASE_ANDROID_APP_ID'), isFalse);
    expect(() => mergeDefines(null, null), throwsA(isA<DefinesProblem>()));
  });

  test('files for another app or another project are refused, and the message shows no value', () {
    expect(() => fromGoogleServices(_googleServices(package: 'com.example.wrong')), throwsA(isA<DefinesProblem>()));
    expect(() => fromPlist(_plist(bundle: 'com.example.wrong')), throwsA(isA<DefinesProblem>()));
    try {
      mergeDefines(fromGoogleServices(_googleServices()), fromPlist(_plist(project: 'someone-elses-project', number: '222222222222')));
      fail('should have been refused');
    } on DefinesProblem catch (problem) {
      expect(problem.message, isNot(contains('someone-elses-project')));
      expect(problem.message, isNot(contains('111111111111')));
    }
  });

  test('a file that is not the right kind is refused without echoing it', () {
    for (final void Function() read in <void Function()>[
      () => fromGoogleServices('not json at all {{'),
      () => fromGoogleServices('[]'),
      () => fromGoogleServices('{"project_info": {}, "client": []}'),
      () => fromPlist('bplist00binary'),
      () => fromPlist('<plist><dict></dict></plist>'),
    ]) {
      expect(read, throwsA(isA<DefinesProblem>()));
    }
  });

  test('the JSON is stable, sorted and holds only text', () {
    final String text = encodeDefines(mergeDefines(fromGoogleServices(_googleServices()), fromPlist(_plist())));
    final Map<String, Object?> decoded = (jsonDecode(text) as Map).cast<String, Object?>();
    expect(decoded.keys.toList(), (decoded.keys.toList()..sort()));
    expect(decoded.values.every((value) => value is String), isTrue);
  });
}
