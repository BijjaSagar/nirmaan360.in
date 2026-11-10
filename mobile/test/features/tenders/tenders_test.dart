import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/router.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/features/tenders/data/tender_repository.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const int _line = 11;

Map<String, dynamic> _quote({
  required int bidId,
  required String contractor,
  String rate = '450.00',
  String amount = '45000.00',
  bool current = true,
  bool lowest = false,
  bool hasFile = false,
}) {
  return <String, dynamic>{
    'bid_id': bidId,
    'contractor': contractor,
    'received_on': '2026-11-08',
    'current': current,
    'selected': false,
    'valid_until': '2026-12-31',
    'includes_material': true,
    'completion_days': 45,
    'entered_by': 'Rahul Patil',
    'rate': rate,
    'amount': amount,
    'diff': '-50.00',
    'diff_amount': '-5000.00',
    'diff_pct': '-10.0',
    'lowest': lowest,
    'payment_terms': '30 days after RA bill',
    'remarks': 'Shuttering material ours',
    'has_quotation': hasFile,
    'quotation_name': hasFile ? 'quote.pdf' : null,
  };
}

Future<TestApp> tenderApp({
  bool money = true,
  bool view = true,
  bool tender = true,
  bool feature = true,
  bool quotes = true,
}) async {
  final FakeApi server = FakeApi();
  if (view) server.grant('contractor.view');
  if (tender) server.grant('contractor.tender');
  server.grant('boq.view');
  (server.user['can'] as Map)['money.view'] = money;
  if (feature) ((server.user['organization'] as Map)['features'] as List).add('site_control');
  await server.addBoqItem(projectId: 1, id: _line, code: '2.1', qty: '100.000', rate: '500.00', unit: 'cum');
  await server.addBoqItem(projectId: 1, id: 12, code: '2.2', qty: '50.000', rate: '80.00', unit: 'sqm');
  if (quotes) {
    server.seedTender(
      boqItemId: _line,
      id: 31,
      quotes: <Map<String, dynamic>>[
        _quote(bidId: 41, contractor: 'Shree Formwork', lowest: true, hasFile: true),
        _quote(bidId: 42, contractor: 'Kalyan Builders', rate: '520.00', amount: '52000.00'),
      ],
    );
  }
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Future<void> settle(WidgetTester tester) async {
  await pumpDrift(tester, 10);
  await tester.pump(const Duration(milliseconds: 500));
  await pumpDrift(tester, 10);
}

Future<void> pumpScreen(WidgetTester tester, TestApp app, {required String start, String locale = 'en'}) async {
  tester.view.devicePixelRatio = 3;
  tester.view.physicalSize = const Size(360, 800) * 3;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  final AppGate gate = AppGate(app.services);
  addTearDown(gate.close);
  await pumpDrift(tester);
  final GoRouter router = buildRouter(gate, initialLocation: start);
  addTearDown(router.dispose);

  await tester.pumpWidget(
    RepositoryProvider<AppServices>.value(
      value: app.services,
      child: BlocProvider<AppGate>.value(
        value: gate,
        child: MaterialApp.router(
          routerConfig: router,
          theme: buildN360Theme(),
          locale: Locale(locale),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          builder: (context, navigator) => MediaQuery(
            data: MediaQuery.of(context).copyWith(disableAnimations: true),
            child: LedgerPaper.root(child: navigator),
          ),
        ),
      ),
    ),
  );
  await settle(tester);
}

Key k(String value) => ValueKey<String>(value);

Future<void> tap(WidgetTester tester, Key key) async {
  await tester.ensureVisible(find.byKey(key));
  await tester.pump();
  await tester.tap(find.byKey(key));
  await settle(tester);
}

Future<void> type(WidgetTester tester, Key field, String text) async {
  await tester.ensureVisible(find.byKey(field));
  await tester.enterText(find.descendant(of: find.byKey(field), matching: find.byType(TextField)), text);
  await settle(tester);
}

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('reading', () {
    test('a line comes with every figure for someone who may see money', () async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      final TenderRepository repo = TenderRepository(app.services.context);

      final LineTenders line = await repo.line(_line);

      expect(line.code, '2.1');
      expect(line.qty.toWire(), '100.000');
      expect(line.boqRate?.toWire(), '500.00');
      expect(line.lowestRate?.toWire(), '450.00');
      expect(line.lowestContractor, 'Shree Formwork');
      final TenderQuote first = line.tenders.single.quotes.first;
      expect(first.rate?.toWire(), '450.00');
      expect(first.amount?.toWire(), '45000.00');
      expect(first.includesMaterial, isTrue);
      expect(first.completionDays, 45);
      expect(line.canQuote, isTrue);
      expect(line.canAward, isTrue);
    });

    test('without money.view no rate, amount, terms or file arrive', () async {
      final TestApp app = await tenderApp(money: false);
      addTearDown(app.dispose);

      final LineTenders line = await TenderRepository(app.services.context).line(_line);

      final TenderQuote first = line.tenders.single.quotes.first;
      expect(first.contractor, 'Shree Formwork');
      expect(first.rate, isNull);
      expect(first.amount, isNull);
      expect(first.paymentTerms, isNull);
      expect(first.hasQuotation, isFalse);
      expect(line.boqRate, isNull);
      expect(line.lowestRate, isNull);
    });

    test('markers count tenders and quotes by line', () async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);

      final Map<int, TenderMarker> markers = await TenderRepository(app.services.context).markers(1);

      expect(markers.keys, <int>[_line]);
      expect(markers[_line]!.tenders, 1);
      expect(markers[_line]!.quotes, 2);
      expect(markers[_line]!.lowestRate?.toWire(), '450.00');
    });

    test('a store keeper reads nothing, and the server is not asked', () async {
      final TestApp app = await tenderApp(view: false);
      addTearDown(app.dispose);

      expect(await TenderRepository(app.services.context).markers(1), isEmpty);
      expect(app.api.boqWithTendersReads, 0);
    });
  });

  group('the BOQ', () {
    testWidgets('every line offers tenders, and a line in one says how it stands', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boq);

      expect(find.text('1 tender · 2 quotes · Lowest ₹450.00 per cum'), findsOneWidget);
      // Line 2.2 has none yet.
      expect(find.byKey(k('boq-tenders-12')), findsOneWidget);
      expect(
        find.descendant(of: find.byKey(k('boq-tenders-12')), matching: find.text(en.tenderLinkEmpty)),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    });

    testWidgets('without money.view the marker has no rupee', (WidgetTester tester) async {
      final TestApp app = await tenderApp(money: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boq);

      expect(find.text('1 tender · 2 quotes'), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a store keeper sees the BOQ with no tenders at all', (WidgetTester tester) async {
      final TestApp app = await tenderApp(view: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boq);

      expect(find.textContaining('tender'), findsNothing);
      expect(find.byKey(k('boq-tenders-$_line')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('tapping a line opens its tenders', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boq);

      await tap(tester, k('boq-tenders-$_line'));

      expect(find.text('Slab shuttering'), findsOneWidget);
      expect(find.text('Shree Formwork'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('the line page', () {
    testWidgets('shows each quote with its figures, and what may be done', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      expect(find.text('₹450.00 per cum'), findsOneWidget);
      expect(find.text('₹45,000.00'), findsOneWidget);
      expect(find.text('-10.0% vs BOQ'), findsWidgets);
      expect(find.text(en.tenderTagLowest), findsOneWidget);
      expect(find.byKey(k('tender-31-add-quote')), findsOneWidget);
      expect(find.byKey(k('quote-41-select')), findsOneWidget);
      expect(find.byKey(k('quote-41-file')), findsOneWidget);
      expect(find.byKey(k('tender-new')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('without money.view: who quoted and when, no figures, no file, no new tender', (WidgetTester tester) async {
      final TestApp app = await tenderApp(money: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      expect(find.text('Shree Formwork'), findsOneWidget);
      expect(find.text('Kalyan Builders'), findsOneWidget);
      expect(find.textContaining('₹'), findsNothing);
      expect(find.byKey(k('quote-41-file')), findsNothing);
      expect(find.byKey(k('tender-new')), findsNothing);
      expect(find.text(en.tenderMoneyHidden), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the server decides who may quote and who may choose', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      app.api.tenderCanQuote = false;
      app.api.tenderCanAward = false;
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      expect(find.byKey(k('tender-31-add-quote')), findsNothing);
      expect(find.byKey(k('quote-41-select')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('offline it says tenders need the internet', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      app.connectivity.setOnline(false);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      expect(find.text(en.tenderOfflineTitle), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a PDF quotation offers another app', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      await tap(tester, k('quote-41-file'));

      expect(find.text(en.tenderQuotationNotPicture), findsOneWidget);
      expect(find.byKey(k('quotation-open-elsewhere')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('a new tender', () {
    testWidgets('starts with the line as its title, and appears on the page', (WidgetTester tester) async {
      final TestApp app = await tenderApp(quotes: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      expect(find.text(en.tenderNoneTitle), findsOneWidget);
      await tap(tester, k('tender-new'));
      await tap(tester, k('tender-new-send'));

      final (int item, Map<String, dynamic> body) = app.api.tenderStarts.single;
      expect(item, _line);
      expect(body['title'], '2.1 · RCC work 2.1');
      expect(body['client_uuid'], isA<String>());
      expect(find.text('2.1 · RCC work 2.1'), findsOneWidget);
      expect(find.text(en.tenderQuotesNone), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('needs a title', (WidgetTester tester) async {
      final TestApp app = await tenderApp(quotes: false);
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      await tap(tester, k('tender-new'));
      await type(tester, k('tender-new-title'), '  ');
      await tap(tester, k('tender-new-send'));

      expect(find.text(en.tenderTitleRequired), findsOneWidget);
      expect(app.api.tenderStarts, isEmpty);
      expect(tester.takeException(), isNull);
    });
  });

  group('adding a quote', () {
    Future<void> openForm(WidgetTester tester, TestApp app) =>
        pumpScreen(tester, app, start: Routes.tenderQuote(_line, 31));

    Future<void> chooseContractor(WidgetTester tester, int id) async {
      await tap(tester, k('quote-form-contractor'));
      await tester.tap(find.byKey(k('field-pick-$id')));
      await settle(tester);
    }

    testWidgets('sends who quoted, the rate for the line and the terms', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      await chooseContractor(tester, 8);
      await type(tester, k('quote-form-rate-310'), '475.50');
      expect(find.text('100.000 cum × rate = ₹47,550.00'), findsOneWidget);
      await tap(tester, k('quote-form-material'));
      await type(tester, k('quote-form-days'), '40');
      await type(tester, k('quote-form-terms'), '15 days after bill');
      await tap(tester, k('quote-form-save'));

      final (int tender, Map<String, dynamic> body, String? file) = app.api.tenderQuotePosts.single;
      expect(tender, 31);
      expect(body['party_id'], 8);
      expect(body['rates'], <String, String>{'310': '475.50'});
      expect(body.containsKey('quoted_amount'), isFalse);
      expect(body['includes_material'], 1);
      expect(body['completion_days'], 40);
      expect(body['payment_terms'], '15 days after bill');
      expect(body['received_on'], app.services.context.today);
      expect(file, isNull);
      expect(tester.takeException(), isNull);
    });

    testWidgets('asks who quoted', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      await type(tester, k('quote-form-rate-310'), '475');
      await tap(tester, k('quote-form-save'));

      expect(find.text(en.tenderContractorRequired), findsOneWidget);
      expect(app.api.tenderQuotePosts, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a rate must be above zero, with two decimals at most', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await openForm(tester, app);
      await chooseContractor(tester, 7);

      await tap(tester, k('quote-form-save'));
      expect(find.text(en.tenderRateError), findsOneWidget);

      await type(tester, k('quote-form-rate-310'), '10.125');
      await tap(tester, k('quote-form-save'));
      expect(find.text(en.tenderRateError), findsOneWidget);

      await type(tester, k('quote-form-rate-310'), '0');
      await tap(tester, k('quote-form-save'));
      expect(find.text(en.tenderRateError), findsOneWidget);
      expect(app.api.tenderQuotePosts, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a contractor added from the form is the one chosen', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await openForm(tester, app);

      await tap(tester, k('quote-form-add-contractor'));
      await type(tester, k('contractor-new-name'), 'Om Sai Formwork');
      await type(tester, k('contractor-new-phone'), '9822000000');
      await tap(tester, k('contractor-new-send'));

      expect(app.api.subcontractorAdds.single['name'], 'Om Sai Formwork');
      expect(find.text('Om Sai Formwork'), findsOneWidget);
      await type(tester, k('quote-form-rate-310'), '430');
      await tap(tester, k('quote-form-save'));
      expect(app.api.tenderQuotePosts.single.$2['party_id'], 102);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a failed save keeps its id, so the retry is the same quote', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await openForm(tester, app);
      await chooseContractor(tester, 7);
      await type(tester, k('quote-form-rate-310'), '440');

      app.api.tenderFailure = const ServerFailure('', statusCode: 500);
      await tap(tester, k('quote-form-save'));
      expect(find.text(en.tenderSaveFailed), findsOneWidget);
      final String? kept = await app.db.readMeta('idempotency.quote.31');
      expect(kept, isNotNull);

      app.api.tenderFailure = null;
      await tap(tester, k('quote-form-save'));
      expect(app.api.tenderQuotePosts.single.$2['client_uuid'], kept);
      expect(await app.db.readMeta('idempotency.quote.31'), isNull);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a closed tender takes no quote', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      app.api.lineTenderRows[_line]!.single['status'] = 'AWARDED';
      await openForm(tester, app);

      expect(find.text(en.tenderClosed), findsOneWidget);
      expect(find.byKey(k('quote-form-save')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });

  group('selecting a contractor', () {
    testWidgets('sends the website\'s terms and shows the work order', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.boqTenders(_line));

      await tap(tester, k('quote-41-select'));
      expect(find.text(en.tenderAwardExplain), findsOneWidget);
      await tap(tester, k('award-form-send'));

      final (int tender, Map<String, dynamic> body) = app.api.tenderAwards.single;
      expect(tender, 31);
      expect(body['bid_id'], 41);
      expect(body['retention_pct'], '5');
      expect(body['tds_pct'], '1');
      expect(body['gst_pct'], '18');
      expect(body['advance_amount'], '0.00');
      expect(body['advance_recovery_pct'], '0');
      expect(body['deduction_basis'], 'gross');
      expect(body['deduction_order'], 'retention,advance,material,tds');
      expect(find.text(en.tenderStatusAwarded), findsOneWidget);
      expect(find.text(en.tenderWorkOrder('WO-99')), findsOneWidget);
      expect(find.text(en.tenderTagSelected), findsOneWidget);
      expect(find.byKey(k('tender-31-add-quote')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a percentage over 100 is refused before sending', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      await pumpScreen(tester, app, start: Routes.tenderAward(_line, 31, 41));

      await type(tester, k('award-form-gst'), '118');
      await tap(tester, k('award-form-send'));

      expect(find.text(en.tenderPctError), findsOneWidget);
      expect(app.api.tenderAwards, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a replaced quote cannot be chosen', (WidgetTester tester) async {
      final TestApp app = await tenderApp();
      addTearDown(app.dispose);
      ((app.api.lineTenderRows[_line]!.single['quotes'] as List).first as Map)['current'] = false;
      await pumpScreen(tester, app, start: Routes.tenderAward(_line, 31, 41));

      expect(find.text(en.tenderQuoteGone), findsOneWidget);
      expect(find.byKey(k('award-form-send')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });

  group('in Marathi and Hindi', () {
    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('the line page and the quote form fit at 360dp in $locale', (WidgetTester tester) async {
        final TestApp app = await tenderApp();
        addTearDown(app.dispose);
        await pumpScreen(tester, app, start: Routes.boqTenders(_line), locale: locale);
        expect(tester.takeException(), isNull);

        await tap(tester, k('tender-31-add-quote'));
        expect(tester.takeException(), isNull);
      });
    }
  });
}
