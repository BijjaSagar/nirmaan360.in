import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/dpr/view/widgets/dpr_ui.dart';

import '../../../support/test_app.dart';
import 'dpr_test_support.dart';

/// A stamp's label is painted, so it is read from the widget.
List<String> stampLabels(WidgetTester tester) =>
    tester.widgetList<RubberStamp>(find.byType(RubberStamp)).map((s) => s.label).toList();

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create(seed: false, api: demoApi());
    await app.sync();
  });

  tearDown(() => app.dispose());

  Future<void> show(WidgetTester tester, String uuid) async {
    usePhone(tester, height: 1400);
    await tester.pumpWidget(hostApp(app.services, DprDetailScreen(clientUuid: uuid)));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 500));
  }

  Future<String> filled() {
    return app.services.dpr.saveDraft(
      DprInput(
        projectId: 1,
        reportDate: today,
        weather: 'Rain',
        hindrances: 'Cement late.',
        summary: 'Slab shuttering done.',
        manpower: const [
          ManpowerLine(trade: 'Mason', count: 12),
          ManpowerLine(trade: 'Helper', count: 21),
        ],
        workItems: [WorkLine(boqItemId: 1, qty: Qty.parse('15.5'))],
      ),
    );
  }

  testWidgets('shows the whole report, read-only', (tester) async {
    final uuid = await filled();
    await show(tester, uuid);

    expect(find.text('Rain'), findsOneWidget);
    expect(find.text('33'), findsNWidgets(2), reason: 'section total and ledger total');
    expect(find.text('15.500 cum'), findsOneWidget);
    expect(find.text('M25 RCC in slab and beams, sixth floor'), findsOneWidget);
    expect(find.text('Cement late.'), findsOneWidget);
    expect(find.text('Slab shuttering done.'), findsOneWidget);
    expect(find.byType(GloveStepper), findsNothing);
    expect(find.text('Send report'), findsNothing);
  });

  testWidgets('queued work says it is still on the phone', (tester) async {
    final uuid = await filled();
    await app.services.dpr.send(uuid);
    app.connectivity.setOnline(false);
    await show(tester, uuid);

    expect(stampLabels(tester), ['On phone']);
    expect(find.text('Waiting to send'), findsOneWidget);
    expect(find.text('Saved on phone, will send when network returns'), findsOneWidget);
    expect(find.text('Sent'), findsNothing);
  });

  testWidgets('a RETURNED report shows the remark and offers correction', (tester) async {
    final uuid = await filled();
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(uuid), 'RETURNED', returnRemark: 'Recheck helpers');
    await app.sync();

    await show(tester, uuid);

    expect(find.text('Recheck helpers'), findsOneWidget);
    expect(stampLabels(tester), ['Returned']);
    expect(find.text('Correct and send again'), findsOneWidget);
  });

  testWidgets('photos after upload are shown through photoBytes', (tester) async {
    final uuid = await filled();
    await app.db.into(app.db.dprPhotos).insert(
      DprPhotosCompanion.insert(
        clientUuid: 'uploaded',
        dprClientUuid: uuid,
        serverId: const Value(700),
      ),
    );

    await show(tester, uuid);

    expect(find.byType(DprPhotoThumb), findsOneWidget);
    expect(find.text('1 photo'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('renders in Marathi with no overflow', (tester) async {
    final uuid = await filled();
    usePhone(tester, height: 1400);
    await tester.pumpWidget(
      hostApp(
        app.services,
        DprDetailScreen(clientUuid: uuid),
        locale: const Locale('mr'),
        textScale: 1.3,
      ),
    );
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text('हवामान'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
