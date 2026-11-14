import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/config/app_config.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/reports/report_share.dart';
import 'package:share_plus/share_plus.dart';

import '../../../support/test_app.dart';
import 'dpr_test_support.dart';

void main() {
  late TestApp app;
  late List<ShareParams> shared;

  Future<void> start({bool reports = true}) async {
    final FakeApi api = demoApi();
    if (reports) api.grantReports();
    app = await TestApp.create(seed: false, api: api);
    await app.sync();
    ReportShare.tempDirectory = () async => app.tempDirectory.path;
    ReportShare.shareOut = (params) async => shared.add(params);
  }

  setUp(() => shared = <ShareParams>[]);

  tearDown(() async {
    ReportShare.resetForTesting();
    await app.dispose();
  });

  Future<String> report({bool send = true}) async {
    final String uuid = await app.services.dpr.saveDraft(
      const DprInput(projectId: 1, reportDate: today, weather: 'Clear', manpower: <ManpowerLine>[ManpowerLine(trade: 'Mason', count: 4)]),
    );
    if (send) {
      await app.services.dpr.send(uuid);
      await app.sync();
    }
    return uuid;
  }

  Future<void> show(WidgetTester tester, String uuid, {Locale locale = const Locale('en')}) async {
    usePhone(tester, height: 2000);
    await tester.pumpWidget(hostApp(app.services, DprDetailScreen(clientUuid: uuid), locale: locale));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 500));
  }

  GloveButton button(WidgetTester tester, String key) => tester.widget<GloveButton>(find.byKey(ValueKey<String>(key)));

  /// The file is written with real I/O, which only moves while the real clock runs.
  Future<void> settleIo(WidgetTester tester) async {
    for (var i = 0; i < 8; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 20)));
      await tester.pump();
    }
  }

  Future<void> tap(WidgetTester tester, String key) async {
    await tester.ensureVisible(find.byKey(ValueKey<String>(key)));
    await tester.tap(find.byKey(ValueKey<String>(key)));
    await settleIo(tester);
  }

  int serverIdOf(String uuid) => app.api.dprByUuid(uuid)['id'] as int;

  testWidgets('a report on the server offers its PDF and the WhatsApp link', (tester) async {
    await start();
    final String uuid = await report();
    await show(tester, uuid);

    expect(find.text('Share this report'), findsOneWidget);
    expect(button(tester, 'dpr-share-pdf').onPressed, isNotNull);
    expect(button(tester, 'dpr-share-whatsapp').onPressed, isNotNull);
    expect(find.text('The WhatsApp link opens the PDF for 7 days. Anyone who has the link can open it.'), findsOneWidget);
  });

  testWidgets('Share PDF downloads the server copy into the reports folder and hands it on', (tester) async {
    await start();
    final String uuid = await report();
    await show(tester, uuid);

    await tap(tester, 'dpr-share-pdf');

    final int id = serverIdOf(uuid);
    expect(app.api.dprPdfReads, <int>[id]);
    final String path = '${app.tempDirectory.path}/${AppConfig.reportFolder}/DPR-$today-$id.pdf';
    expect(File(path).existsSync(), isTrue);
    expect(shared.single.files!.single.path, path);
    expect(shared.single.files!.single.mimeType, 'application/pdf');
    expect(app.api.dprShareLinkCalls, isEmpty);
  });

  testWidgets("Share on WhatsApp sends the server's message, in the app's language", (tester) async {
    await start();
    final String uuid = await report();
    await show(tester, uuid, locale: const Locale('mr'));

    expect(find.text('WhatsApp वर पाठवा'), findsOneWidget);
    await tap(tester, 'dpr-share-whatsapp');

    expect(app.api.dprShareLinkCalls.single, (dprId: serverIdOf(uuid), locale: 'mr'));
    expect(shared.single.text, contains('/shared/dprs/${serverIdOf(uuid)}?expires='));
    expect(shared.single.files, isNull);
    expect(app.api.dprPdfReads, isEmpty);
  });

  testWidgets('a plan without reports shows no share buttons, as on the website', (tester) async {
    await start(reports: false);
    final String uuid = await report();
    await show(tester, uuid);

    expect(find.text('Share this report'), findsNothing);
    expect(find.byKey(const ValueKey<String>('dpr-share-pdf')), findsNothing);
  });

  testWidgets('a report still on the phone waits until it reaches the office', (tester) async {
    await start();
    final String uuid = await report(send: false);
    await show(tester, uuid);

    expect(find.text('You can share this report once it has reached the office.'), findsOneWidget);
    expect(button(tester, 'dpr-share-pdf').onPressed, isNull);
    expect(button(tester, 'dpr-share-whatsapp').onPressed, isNull);
  });

  testWidgets('offline, the buttons wait for internet', (tester) async {
    await start();
    final String uuid = await report();
    app.connectivity.setOnline(false);
    await show(tester, uuid);

    expect(find.text('Sharing needs internet.'), findsOneWidget);
    expect(button(tester, 'dpr-share-pdf').onPressed, isNull);
  });

  testWidgets('a refusal says so and shares nothing', (tester) async {
    await start();
    final String uuid = await report();
    await show(tester, uuid);
    app.api.reportFailure = const ServerFailure('No.', statusCode: 403);

    await tap(tester, 'dpr-share-whatsapp');
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('This report cannot be shared from your account.'), findsOneWidget);
    expect(shared, isEmpty);
    await tester.pump(const Duration(seconds: 3));
  });

  testWidgets('one tap at a time: the button waits while the PDF comes', (tester) async {
    await start();
    final String uuid = await report();
    await show(tester, uuid);
    final Completer<void> gate = app.api.reportGate = Completer<void>();

    await tap(tester, 'dpr-share-pdf');
    expect(button(tester, 'dpr-share-pdf').loading, isTrue);
    expect(button(tester, 'dpr-share-whatsapp').onPressed, isNull);

    gate.complete();
    app.api.reportGate = null;
    await settleIo(tester);

    expect(button(tester, 'dpr-share-pdf').loading, isFalse);
    expect(shared, hasLength(1));
  });
}
