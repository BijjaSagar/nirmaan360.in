// Renders the DPR screens to PNG with the real fonts so a person can judge them.
//
// flutter test test/features/dpr/view/dpr_render_test.dart \
//   --dart-define=N360_DPR_DIR=/absolute/output/dir
//
// Without the define nothing is written and the file has no tests.
import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/dpr/view/dpr_detail_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_form_screen.dart';
import 'package:nirmaan360/features/dpr/view/dpr_list_screen.dart';

import '../../../core/design/test_helpers.dart';
import '../../../support/test_app.dart';
import 'dpr_test_support.dart';

const String _dir = String.fromEnvironment('N360_DPR_DIR');

const Locale _en = Locale('en');
const Locale _mr = Locale('mr');

/// Part of each BOQ line is already measured, so progress marks show a real figure.
Future<void> _measureSome(TestApp app) async {
  for (final (int id, String balance) in const <(int, String)>[
    (1, '402.500'),
    (2, '31.250'),
    (3, '612.500'),
  ]) {
    await (app.db.update(app.db.boqItems)..where((t) => t.id.equals(id))).write(
      BoqItemsCompanion(balanceQty: Value(Qty.parse(balance))),
    );
  }
}

Future<void> _insertYesterday(TestApp app) async {
  await app.db.into(app.db.dprs).insert(
    DprsCompanion.insert(
      clientUuid: 'yesterday',
      projectId: 1,
      reportDate: yesterday,
      manpower: const [
        ManpowerLine(trade: 'Mason', count: 12),
        ManpowerLine(trade: 'Helper', count: 18),
        ManpowerLine(trade: 'Bar bender', count: 6),
        ManpowerLine(trade: 'Carpenter', count: 4),
      ],
      machinery: const [],
      workItems: [WorkLine(boqItemId: 2, qty: Qty.parse('1.200'))],
      status: const Value(DocStatus.approved),
      serverId: const Value(90),
    ),
  );
}

Future<String> _filledDraft(TestApp app, {String? hindrances}) async {
  final uuid = await app.services.dpr.saveDraft(
    DprInput(
      projectId: 1,
      reportDate: today,
      siteId: 1,
      weather: 'Rain',
      manpower: const [
        ManpowerLine(trade: 'Mason', count: 12),
        ManpowerLine(trade: 'Helper', count: 21),
        ManpowerLine(trade: 'Bar bender', count: 6),
      ],
      workItems: [
        WorkLine(boqItemId: 1, qty: Qty.parse('15.5')),
        WorkLine(boqItemId: 3, qty: Qty.parse('42')),
      ],
      hindrances: hindrances,
    ),
  );
  return uuid;
}

Future<void> _addPhotos(TestApp app, String uuid, int count) async {
  for (var i = 0; i < count; i++) {
    await app.db.into(app.db.dprPhotos).insert(
      DprPhotosCompanion.insert(
        clientUuid: 'photo-$i',
        dprClientUuid: uuid,
        serverId: Value(700 + i),
      ),
    );
  }
}

void main() {
  if (_dir.isEmpty) return;

  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await loadRealFonts();
    await Directory(_dir).create(recursive: true);
  });

  /// Runs [scene] with its own in-memory app, then captures [name].
  void shot(
    String name,
    Future<Widget> Function(TestApp app) scene, {
    Locale locale = _en,
    double height = 800,
    Future<void> Function(WidgetTester tester)? then,
  }) {
    testWidgets(name, (tester) async {
      usePhone(tester, height: height);
      final photo = await tester.runAsync(fakeSitePhoto);
      final app = await TestApp.create(seed: false, api: demoApi(photoBytes: photo));
      await app.sync();
      await _measureSome(app);
      addTearDown(app.dispose);

      final Widget screen = await scene(app);
      await tester.pumpWidget(hostApp(app.services, screen, locale: locale));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 900));
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 250)));
      await tester.pump(const Duration(milliseconds: 100));
      if (then != null) await then(tester);
      await tester.runAsync(() => capturePng(tester, _dir, name));
      await tester.pump(const Duration(seconds: 1));
    });
  }

  Future<Widget> formEmpty(TestApp app) async {
    await _insertYesterday(app);
    return const DprFormScreen(date: today);
  }

  Future<Widget> formFilled(TestApp app) async {
    final uuid = await _filledDraft(app);
    await _addPhotos(app, uuid, 3);
    return const DprFormScreen(date: today);
  }

  shot('form_new_en', formEmpty);
  shot('form_new_mr', formEmpty, locale: _mr);
  shot('form_new_tall_en', formEmpty, height: 1300);
  shot('form_filled_tall_en', formFilled, height: 1500);
  shot('form_filled_tall_mr', formFilled, locale: _mr, height: 1500);

  shot('form_returned_en', (app) async {
    final uuid = await _filledDraft(app, hindrances: 'Cement delivery late by two days.');
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(
      app.api.dprByUuid(uuid),
      'RETURNED',
      returnRemark: 'Helper count looks high against muster. Please recheck and add slab photos.',
    );
    await app.sync();
    return DprFormScreen(clientUuid: uuid);
  });

  Future<void> tapText(WidgetTester tester, String text) async {
    await tester.tap(find.text(text).first);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    await pumpDrift(tester, 10);
    await tester.pump(const Duration(milliseconds: 300));
  }

  shot(
    'review_en',
    formFilled,
    then: (tester) => tapText(tester, 'Send report'),
  );
  shot(
    'review_mr',
    formFilled,
    locale: _mr,
    then: (tester) => tapText(tester, 'अहवाल पाठवा'),
  );
  shot(
    'add_work_list_en',
    formFilled,
    height: 800,
    then: (tester) async {
      await tester.scrollUntilVisible(find.text('Add work'), 300, scrollable: find.byType(Scrollable).first);
      await tester.pump(const Duration(milliseconds: 200));
      await tapText(tester, 'Add work');
    },
  );
  shot(
    'add_work_keypad_en',
    formFilled,
    then: (tester) async {
      await tester.scrollUntilVisible(find.text('Add work'), 300, scrollable: find.byType(Scrollable).first);
      await tester.pump(const Duration(milliseconds: 200));
      await tapText(tester, 'Add work');
      await tapText(tester, 'Fe-500 reinforcement steel, cut and bent');
      for (final digit in const <String>['1', '.', '2', '5']) {
        await tester.tap(find.text(digit).last);
        await tester.pump(const Duration(milliseconds: 80));
      }
      await tester.pump(const Duration(milliseconds: 400));
    },
  );
  shot(
    'add_work_keypad_mr',
    formFilled,
    locale: _mr,
    then: (tester) async {
      await tester.scrollUntilVisible(find.text('काम जोडा'), 300, scrollable: find.byType(Scrollable).first);
      await tester.pump(const Duration(milliseconds: 200));
      await tapText(tester, 'काम जोडा');
      await tapText(tester, 'Fe-500 reinforcement steel, cut and bent');
      for (final digit in const <String>['3', '2', '.', '5']) {
        await tester.tap(find.text(digit).last);
        await tester.pump(const Duration(milliseconds: 80));
      }
      await tester.pump(const Duration(milliseconds: 400));
    },
  );

  shot('list_empty_en', (app) async => const DprListScreen());

  Future<Widget> listScene(TestApp app) async {
    final repo = app.services.dpr;
    await _insertYesterday(app);
    final a = await repo.saveDraft(
      const DprInput(
        projectId: 1,
        reportDate: '2026-11-07',
        manpower: [ManpowerLine(trade: 'Mason', count: 8)],
      ),
    );
    await repo.send(a);
    await app.sync();
    final b = await repo.saveDraft(
      DprInput(
        projectId: 1,
        reportDate: '2026-11-06',
        manpower: const [ManpowerLine(trade: 'Helper', count: 22)],
        workItems: [WorkLine(boqItemId: 1, qty: Qty.parse('3'))],
      ),
    );
    await repo.send(b);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(b), 'RETURNED', returnRemark: 'Recheck');
    await app.sync();
    final c = await repo.saveDraft(
      const DprInput(
        projectId: 1,
        reportDate: '2026-11-05',
        manpower: [ManpowerLine(trade: 'Helper', count: 15)],
      ),
    );
    await repo.send(c);
    await _filledDraft(app);
    return const DprListScreen();
  }

  shot('list_missed_en', (app) async {
    await app.services.dpr.saveDraft(
      const DprInput(projectId: 1, reportDate: today, manpower: [ManpowerLine(trade: 'Mason', count: 6)]),
    );
    await app.services.dpr.saveDraft(
      const DprInput(projectId: 1, reportDate: '2026-11-07', manpower: [ManpowerLine(trade: 'Mason', count: 8)]),
    );
    return const DprListScreen();
  });
  shot('list_en', listScene);
  shot('list_mr', listScene, locale: _mr);

  Future<Widget> detailScene(TestApp app) async {
    final uuid = await _filledDraft(app, hindrances: 'Cement delivery late by two days.');
    await app.services.dpr.send(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.dprByUuid(uuid), 'IN_REVIEW');
    await app.sync();
    await _addPhotos(app, uuid, 2);
    return DprDetailScreen(clientUuid: uuid);
  }

  shot('detail_tall_en', detailScene, height: 1400);
  shot('detail_tall_mr', detailScene, locale: _mr, height: 1400);
  shot('detail_en', detailScene);

  shot(
    'validation_en',
    formEmpty,
    then: (tester) async {
      await tapText(tester, 'Send report');
      await tester.pump(const Duration(milliseconds: 200));
    },
  );
}
