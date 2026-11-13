import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/config/app_config.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/reports/report_share.dart';
import 'package:share_plus/share_plus.dart';

import '../../support/fake_api.dart';

void main() {
  late FakeApi api;
  late Directory temp;
  late List<ShareParams> shared;

  setUp(() {
    api = FakeApi();
    temp = Directory.systemTemp.createTempSync('n360_reports_');
    shared = <ShareParams>[];
    ReportShare.tempDirectory = () async => temp.path;
    ReportShare.shareOut = (params) async => shared.add(params);
  });

  tearDown(() {
    ReportShare.resetForTesting();
    if (temp.existsSync()) temp.deleteSync(recursive: true);
  });

  CurrentUser userWith({List<String> features = const <String>['site', 'reports'], Map<String, bool>? can, String kind = 'staff'}) {
    return CurrentUser.fromJson(<String, dynamic>{
      ...api.user,
      'kind': kind,
      'organization': <String, dynamic>{...(api.user['organization'] as Map).cast<String, dynamic>(), 'features': features},
      'can': can ?? <String, bool>{'dpr.view': true, 'measurement.view': true},
    });
  }

  group('who is offered the buttons', () {
    test('a plan with reports and the permission', () {
      expect(ReportShare.allowed(userWith(), 'dpr.view'), isTrue);
      expect(ReportShare.allowed(userWith(), 'measurement.view'), isTrue);
    });

    test('not without reports on the plan, as on the website', () {
      expect(ReportShare.allowed(userWith(features: const <String>['site', 'measurement']), 'dpr.view'), isFalse);
    });

    test('not without the permission', () {
      expect(ReportShare.allowed(userWith(can: <String, bool>{'dpr.view': true}), 'measurement.view'), isFalse);
      expect(ReportShare.allowed(userWith(can: <String, bool>{'measurement.view': true}), 'dpr.view'), isFalse);
    });

    test('never to a contractor login, or nobody signed in', () {
      expect(ReportShare.allowed(userWith(kind: CurrentUser.contractorKind), 'dpr.view'), isFalse);
      expect(ReportShare.allowed(null, 'dpr.view'), isFalse);
    });
  });

  test('the DPR PDF is written to the reports folder and handed on as a PDF', () async {
    await ReportShare(api).shareDprPdf(dprId: 41, reportDate: '2026-11-09', subject: 'Daily report');

    expect(api.dprPdfReads, <int>[41]);
    final File file = File('${temp.path}/${AppConfig.reportFolder}/DPR-2026-11-09-41.pdf');
    expect(file.existsSync(), isTrue);
    expect(utf8.decode(file.readAsBytesSync()), startsWith('%PDF'));

    final ShareParams params = shared.single;
    expect(params.files!.single.path, file.path);
    expect(params.files!.single.mimeType, 'application/pdf');
    expect(params.fileNameOverrides, <String>['DPR-2026-11-09-41.pdf']);
    expect(params.subject, 'Daily report');
    expect(params.text, isNull);
  });

  test("the WhatsApp share is the server's message, signed link and all", () async {
    final DprShareLink link = await ReportShare(api).shareDprLink(dprId: 41, locale: 'mr');

    expect(api.dprShareLinkCalls.single, (dprId: 41, locale: 'mr'));
    expect(link.url, startsWith('https://nirmaan360.in/shared/dprs/41?expires='));
    expect(link.linkDays, 7);
    expect(shared.single.text, link.text);
    expect(shared.single.text, endsWith(link.url));
    expect(shared.single.files, isNull);
  });

  test('the measurement sheet asks for the chosen period', () async {
    await ReportShare(api).shareMeasurementSheet(projectId: 3, from: '2026-11-01', to: '2026-11-09');

    expect(api.measurementSheetReads.single, (projectId: 3, from: '2026-11-01', to: '2026-11-09'));
    final File file = File('${temp.path}/${AppConfig.reportFolder}/measurement-sheet-3-20261101-20261109.pdf');
    expect(file.existsSync(), isTrue);
    expect(shared.single.files!.single.path, file.path);
  });

  test('nothing is written or shared when the server refuses', () async {
    api.reportFailure = const ServerFailure('No.', statusCode: 403);

    await expectLater(
      ReportShare(api).shareDprPdf(dprId: 41, reportDate: '2026-11-09'),
      throwsA(isA<ServerFailure>()),
    );
    expect(shared, isEmpty);
    expect(Directory('${temp.path}/${AppConfig.reportFolder}').existsSync(), isFalse);
  });

  test('offline fails at once, before anything is shared', () async {
    api.offline = true;

    await expectLater(ReportShare(api).shareDprLink(dprId: 41), throwsA(isA<OfflineFailure>()));
    expect(shared, isEmpty);
  });

  test('signing out clears report files with the quotations, and nothing else', () async {
    await ReportShare(api).shareDprPdf(dprId: 41, reportDate: '2026-11-09');
    Directory('${temp.path}/${AppConfig.quotationFolder}').createSync();
    // Android's share plugin keeps its own copy of the last shared file here.
    File('${temp.path}/${AppConfig.sharePluginFolder}/DPR-2026-11-09-41.pdf')
      ..createSync(recursive: true)
      ..writeAsStringSync('%PDF copy');
    final File other = File('${temp.path}/unrelated.txt')..writeAsStringSync('keep');

    await clearCompanyTempFiles(() async => temp.path);

    expect(Directory('${temp.path}/${AppConfig.reportFolder}').existsSync(), isFalse);
    expect(Directory('${temp.path}/${AppConfig.quotationFolder}').existsSync(), isFalse);
    expect(Directory('${temp.path}/${AppConfig.sharePluginFolder}').existsSync(), isFalse);
    expect(other.existsSync(), isTrue);
  });
}
