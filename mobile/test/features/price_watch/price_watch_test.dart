import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/app_gate.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/home/view/home_screen.dart';
import 'package:nirmaan360/features/material_control/view/material_screen.dart';
import 'package:nirmaan360/features/price_watch/data/price_watch_repository.dart';
import 'package:nirmaan360/features/price_watch/view/price_material_screen.dart';
import 'package:nirmaan360/features/price_watch/view/price_watch_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/test_app.dart';

const ServerFailure _refused = ServerFailure('This action is unauthorized.', statusCode: 403);

/// A phone signed in as someone who may or may not see recorded prices.
Future<TestApp> priceApp({
  bool money = true,
  bool overview = false,
  bool feature = true,
  void Function(FakeApi api)? setup,
}) async {
  final FakeApi server = FakeApi();
  (server.user['can'] as Map)['money.view'] = money;
  if (overview) server.grant('money.overview');
  if (feature) ((server.user['organization'] as Map)['features'] as List).add('price_watch');
  setup?.call(server);
  final TestApp app = await TestApp.create(api: server);
  await app.services.projects.selectProject(1);
  await app.sync();
  return app;
}

Key k(String value) => ValueKey<String>(value);

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  setUpAll(loadRealFonts);

  group('reading', () {
    test('a sync keeps the board and every material\'s prices on the phone', () async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);

      final PriceBoardCopy board = (await app.services.priceWatch.readBoard())!;
      expect(board.rows.map((WatchedMaterial m) => m.name), <String>['Cement', 'Steel', 'Sand', 'Bricks']);
      final WatchedMaterial cement = board.rows.first;
      expect(cement.current!.rate.toWire(), '410.00');
      expect(cement.current!.pricedOn, '2026-11-08');
      expect(cement.current!.source, 'Dealer quote');
      expect(cement.previous!.rate.toWire(), '392.00');
      expect(cement.changePct, '4.59');
      expect(cement.trend.map((PricePoint p) => p.rate.toWire()), <String>['385.00', '392.00', '410.00']);
      expect(board.rows.last.hasPrice, isFalse);
      expect(board.asOf, '2026-11-09');
      expect(board.staleAfterDays, 30);

      final WatchedMaterial opened = board.material(1)!;
      expect(opened.prices!.map((RecordedPrice p) => p.rate.toWire()), <String>['410.00', '392.00', '385.00']);
      expect(opened.prices!.first.notedBy, 'Anil Kulkarni');
      expect(board.material(4)!.prices, isEmpty);
    });

    test('without money.overview the phone never asks for the budget', () async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);

      expect(app.api.priceBoardCalls, greaterThan(0));
      expect(app.api.priceProjectCalls, 0);
      expect(await app.services.priceWatch.readProject(1), isNull);
      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNull);
    });

    test('with money.overview the project\'s budget effect is kept too', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      final ProjectImpactCopy impact = (await app.services.priceWatch.readProject(1))!;
      expect(impact.rows.map((MaterialImpact r) => r.impact?.toWire()), <String?>['36000.00', '-14000.00', null, null]);
      expect(impact.rows[0].boqRate!.toWire(), '380.00');
      expect(impact.rows[0].remaining!.toWire(), '1200.000');
      expect(impact.rows[0].needBasis, 'typed');
      expect(impact.rows[1].needBasis, 'norms');
      expect(impact.rows.map((MaterialImpact r) => r.gap), <ImpactGap?>[null, null, ImpactGap.noBoqRate, ImpactGap.noPrice]);
      expect(impact.summary.impact!.toWire(), '22000.00');
      expect(impact.summary.freshImpact!.toWire(), '36000.00');
      expect(impact.summary.basis, 'budget');
      expect(impact.summary.projected!.toWire(), '15276250.00');
      expect(impact.summary.beyondMargin, isFalse);
      expect(impact.forMaterial(2)!.material.name, 'Steel');
    });

    test('nobody without money.view or the plan is asked, and nothing is kept', () async {
      final TestApp noMoney = await priceApp(money: false, overview: true);
      addTearDown(noMoney.dispose);
      final TestApp noPlan = await priceApp(feature: false);
      addTearDown(noPlan.dispose);

      for (final TestApp app in <TestApp>[noMoney, noPlan]) {
        expect(app.api.priceBoardCalls, 0);
        expect(app.api.priceMaterialCalls, 0);
        expect(app.api.priceProjectCalls, 0);
        expect(await app.services.priceWatch.readBoard(), isNull);
        expect(await app.db.readMeta(MetaKey.priceWatch), isNull);
        expect(app.services.priceWatch.seesPrices, isFalse);
      }
    });

    test('a copy kept from before money.view went is not shown and is deleted', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.priceWatch), isNotNull);
      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNotNull);

      (app.api.user['can'] as Map)['money.view'] = false;
      (app.api.user['can'] as Map)['money.overview'] = false;
      // The copy is hidden at once, whatever the server says on the next sync.
      await app.services.session.updateUser(CurrentUser.fromJson(app.api.user));
      expect(await app.services.priceWatch.readBoard(), isNull);
      expect(await app.services.priceWatch.readProject(1), isNull);

      await app.sync();

      expect(await app.db.readMeta(MetaKey.priceWatch), isNull);
      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNull);
    });

    test('losing only money.overview takes the budget away and keeps the prices', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      (app.api.user['can'] as Map)['money.overview'] = false;
      await app.sync();

      expect(await app.services.priceWatch.readBoard(), isNotNull);
      expect(await app.services.priceWatch.readProject(1), isNull);
      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNull);
    });

    test('a contractor login is never shown prices', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      app.api.becomeContractor();
      // The same login turned into a contractor login keeps its id.
      app.api.user['id'] = app.services.session.user!.id;
      (app.api.user['can'] as Map)['money.view'] = true;
      await app.services.session.updateUser(CurrentUser.fromJson(app.api.user));

      expect(app.services.priceWatch.seesPrices, isFalse);
      expect(await app.services.priceWatch.readBoard(), isNull);
    });

    test('the last answer stays when the server cannot be reached', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      app.api.offline = true;
      await expectLater(app.services.priceWatch.refreshAll(projectId: 1), throwsA(isA<ApiFailure>()));

      expect((await app.services.priceWatch.readBoard())!.rows, hasLength(4));
      expect((await app.services.priceWatch.readProject(1))!.rows, hasLength(4));
    });

    test('a server error keeps the last answer too', () async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);

      app.api.priceBoardFailure = const ServerFailure('boom', statusCode: 500);
      await expectLater(app.services.priceWatch.refreshAll(), throwsA(isA<ServerFailure>()));

      expect((await app.services.priceWatch.readBoard())!.refused, isFalse);
    });

    test('a refusal replaces what was kept, and is not an error', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      app.api
        ..priceBoardFailure = _refused
        ..priceProjectFailure = _refused;
      await app.services.priceWatch.refreshAll(projectId: 1);

      expect((await app.services.priceWatch.readBoard())!.refused, isTrue);
      expect((await app.services.priceWatch.readBoard())!.rows, isEmpty);
      expect((await app.services.priceWatch.readProject(1))!.refused, isTrue);
    });

    test('a material\'s prices are read again only when its row says something changed', () async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      expect(app.api.priceMaterialCalls, 4);

      await app.services.priceWatch.refreshAll();
      expect(app.api.priceMaterialCalls, 4);

      app.api.priceLists[1]!.insert(0, <String, dynamic>{
        'id': 9,
        'rate': '415.00',
        'unit': 'bag',
        'priced_on': '2026-11-09',
        'source': 'Dealer quote',
        'source_kind': 'manual',
        'stale': false,
      });
      await app.services.priceWatch.refreshAll();

      expect(app.api.priceMaterialCalls, 5);
      expect((await app.services.priceWatch.readBoard())!.material(1)!.prices!.first.rate.toWire(), '415.00');
    });

    test('a read under ten minutes old is left alone unless it is forced', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);
      final int board = app.api.priceBoardCalls;
      final int project = app.api.priceProjectCalls;

      await app.services.priceWatch.refreshAll(projectId: 1, force: false);
      expect(app.api.priceBoardCalls, board);
      expect(app.api.priceProjectCalls, project);

      app.clock.advance(const Duration(minutes: 11));
      await app.services.priceWatch.refreshAll(projectId: 1, force: false);
      expect(app.api.priceBoardCalls, board + 1);
      expect(app.api.priceProjectCalls, project + 1);
    });

    test('out of date is counted on the phone, so an old copy does not claim to be fresh', () async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      final RecordedPrice cement = (await app.services.priceWatch.readBoard())!.rows.first.current!;

      expect(cement.isStale('2026-11-09', 30), isFalse);
      expect(cement.isStale('2026-12-08', 30), isFalse);
      expect(cement.isStale('2026-12-09', 30), isTrue);
    });

    test('a removed project takes its budget copy with it', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);
      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNotNull);

      app.api.pullBodyOverride = <String, dynamic>{
        'server_time': '2026-11-09T14:27:54+05:30',
        'deleted': <String, dynamic>{
          'projects': <int>[1],
        },
      };
      await app.sync();

      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNull);
      expect(await app.db.readMeta(MetaKey.priceWatch), isNotNull);
    });

    test('PHP\'s empty array where a map belongs does not stop it opening', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);
      await app.db.writeMeta(
        MetaKey.priceWatch,
        jsonEncode(<String, dynamic>{'data': <Object>[], 'meta': <Object>[], 'histories': <Object>[]}),
      );
      await app.db.writeMeta(
        MetaKey.priceWatchProject(1),
        jsonEncode(<String, dynamic>{'data': <Object>[], 'summary': <Object>[], 'meta': <Object>[]}),
      );

      expect((await app.services.priceWatch.readBoard())!.staleAfterDays, 30);
      expect((await app.services.priceWatch.readProject(1))!.summary.impact, isNull);
    });

    test('the copy on the phone round-trips as stored JSON', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      final Map<dynamic, dynamic> board = jsonDecode((await app.db.readMeta(MetaKey.priceWatch))!) as Map;
      expect(board.keys, containsAll(<String>['data', 'meta', 'histories', 'fetched_at']));
      final Map<dynamic, dynamic> project = jsonDecode((await app.db.readMeta(MetaKey.priceWatchProject(1)))!) as Map;
      expect(project.keys, containsAll(<String>['data', 'summary', 'meta', 'fetched_at']));
    });

    test('signing out clears it', () async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);

      await app.services.auth.signOut();

      expect(await app.db.readMeta(MetaKey.priceWatch), isNull);
      expect(await app.db.readMeta(MetaKey.priceWatchProject(1)), isNull);
    });
  });

  group('never called live or a market price', () {
    final RegExp banned = RegExp(r'live|market|current|बाजार|बाज़ार|लाइव|लाईव|सध्या|वर्तमान|चालू', caseSensitive: false);

    test('not in a translation of any language', () {
      final Map<String, dynamic> fragment = (jsonDecode(File('l10n/price_watch.json').readAsStringSync()) as Map).cast<String, dynamic>();
      expect(fragment.keys, isNotEmpty);
      for (final MapEntry<String, dynamic> entry in fragment.entries) {
        final Map<String, dynamic> words = (entry.value as Map).cast<String, dynamic>();
        for (final String locale in <String>['en', 'mr', 'hi']) {
          final String text = words[locale] as String? ?? '';
          expect(text, isNotEmpty, reason: '${entry.key} has no $locale');
          expect(banned.hasMatch(text), isFalse, reason: '${entry.key} ($locale): $text');
        }
      }
    });

    test('not in any text of the screens', () {
      final List<File> files = Directory('lib/features/price_watch').listSync(recursive: true).whereType<File>().where((File f) => f.path.endsWith('.dart')).toList();
      expect(files, isNotEmpty);
      final RegExp literal = RegExp("'([^'\\n]*)'|\"([^\"\\n]*)\"");
      for (final File file in files) {
        // File paths in imports are not text on a screen.
        final String code = file.readAsLinesSync().where((String line) => !line.startsWith('import ') && !line.startsWith('export ')).join('\n');
        for (final RegExpMatch match in literal.allMatches(code)) {
          final String text = match.group(1) ?? match.group(2) ?? '';
          // The server's own JSON key for the newest price is not a word anyone reads.
          if (text == 'current') continue;
          expect(banned.hasMatch(text), isFalse, reason: '${file.path}: $text');
        }
      }
    });

    test('and the server\'s own statement that it is not a feed is respected', () async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);

      final Map<String, dynamic> body = await app.api.priceBoard();
      expect((body['meta'] as Map)['live_feed'], isFalse);
    });
  });

  group('where each kind of login may go', () {
    Future<AppGate> gateFor(TestApp app) async {
      final AppGate gate = AppGate(app.services);
      addTearDown(gate.close);
      await Future<void>.delayed(const Duration(milliseconds: 50));
      return gate;
    }

    test('only money.view on a plan with price watch reaches the prices', () async {
      final TestApp allowed = await priceApp();
      addTearDown(allowed.dispose);
      final TestApp noMoney = await priceApp(money: false);
      addTearDown(noMoney.dispose);
      final TestApp noPlan = await priceApp(feature: false);
      addTearDown(noPlan.dispose);

      final AppGate yes = await gateFor(allowed);
      final AppGate noM = await gateFor(noMoney);
      final AppGate noP = await gateFor(noPlan);

      for (final String path in <String>[Routes.prices, Routes.priceMaterial(1)]) {
        expect(yes.redirect(Uri.parse(path)), isNull, reason: path);
        expect(noM.redirect(Uri.parse(path)), Routes.home, reason: path);
        expect(noP.redirect(Uri.parse(path)), Routes.home, reason: path);
      }
      expect(yes.state.canSeePrices, isTrue);
      expect(noM.state.canSeePrices, isFalse);
      expect(noP.state.canSeePrices, isFalse);
    });

    test('a contractor login is sent to the portal, whatever it holds', () async {
      final FakeApi server = FakeApi()..becomeContractor();
      (server.user['can'] as Map)['money.view'] = true;
      final TestApp app = await TestApp.create(seed: false, signedIn: false, api: server);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'c@d.e', password: 'x');
      final AppGate gate = await gateFor(app);

      expect(gate.state.canSeePrices, isFalse);
      expect(gate.redirect(Uri.parse(Routes.prices)), Routes.portal);
    });
  });

  group('the way in', () {
    Future<TestApp> materialApp({bool money = true, bool feature = true}) => priceApp(
      money: money,
      feature: feature,
      setup: (FakeApi api) {
        api.grant('material_control.view');
        ((api.user['organization'] as Map)['features'] as List).add('site_control');
      },
    );

    testWidgets('the Material page carries a labelled tile for money.view, and it opens the prices', (WidgetTester tester) async {
      final TestApp app = await materialApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.material);

      expect(find.byType(MaterialScreen), findsOneWidget);
      expect(find.byKey(k('material-prices')), findsOneWidget);
      expect(find.text(en.priceWatchTile), findsOneWidget);

      await tester.tap(find.byKey(k('material-prices')));
      await settle(tester);

      expect(find.byType(PriceWatchScreen), findsOneWidget);
      expect(find.text('Cement'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('without money.view the Material page has no tile and no word about prices', (WidgetTester tester) async {
      final TestApp app = await materialApp(money: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.material);

      expect(find.byType(MaterialScreen), findsOneWidget);
      expect(find.byKey(k('material-prices')), findsNothing);
      expect(find.text(en.priceWatchTile), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('on a plan without price watch the Material page has no tile either', (WidgetTester tester) async {
      final TestApp app = await materialApp(feature: false);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.material);

      expect(find.byKey(k('material-prices')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('the tile fits the bar at 360dp in $locale', (WidgetTester tester) async {
        final TestApp app = await materialApp();
        addTearDown(app.dispose);
        await pumpShellApp(tester, app, location: Routes.material, locale: locale);

        expect(find.byKey(k('material-prices')), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('screens', () {
    testWidgets('the board shows each price with its date, and who recorded it', (WidgetTester tester) async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);

      expect(find.byType(PriceWatchScreen), findsOneWidget);
      expect(find.text(en.priceWatchTitle), findsOneWidget);
      expect(find.textContaining(en.priceWatchNote), findsOneWidget);
      expect(find.text('Cement'), findsOneWidget);
      expect(find.text('₹410'), findsOneWidget);
      expect(find.text('₹5,400'), findsOneWidget);
      expect(find.text('${en.priceWatchRecordedOn('8 Nov')} · Dealer quote'), findsOneWidget);
      expect(find.text(en.priceWatchUp('4.59%', '₹392', '20 Oct')), findsOneWidget);
      expect(find.text(en.priceWatchOnlyOne), findsOneWidget);
      expect(find.text(en.priceWatchNone), findsOneWidget);
      // Steel was last recorded on 20 September: older than 30 days.
      expect(find.text(en.priceWatchStale(30)), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a price that fell says down, in words', (WidgetTester tester) async {
      final TestApp app = await priceApp(
        setup: (FakeApi api) => api.priceLists[1] = <Map<String, dynamic>>[
          <String, dynamic>{'id': 3, 'rate': '350.00', 'unit': 'bag', 'priced_on': '2026-11-08', 'source': 'Dealer quote', 'source_kind': 'manual', 'stale': false},
          <String, dynamic>{'id': 2, 'rate': '400.00', 'unit': 'bag', 'priced_on': '2026-10-20', 'source': 'Dealer quote', 'source_kind': 'manual', 'stale': false},
        ],
      );
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);

      expect(find.text(en.priceWatchDown('12.5%', '₹400', '20 Oct')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a material opens with every recorded price, newest first, offline too', (WidgetTester tester) async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      app.api.offline = true;
      app.connectivity.setOnline(false);
      await pumpShellApp(tester, app, location: Routes.prices);

      await tester.tap(find.byKey(k('price-1')));
      await settle(tester);

      expect(find.byType(PriceMaterialScreen), findsOneWidget);
      expect(find.byKey(k('price-header')), findsOneWidget);
      expect(find.byKey(k('price-row-3')), findsOneWidget);
      expect(find.byKey(k('price-row-2')), findsOneWidget);
      expect(find.byKey(k('price-row-1')), findsOneWidget);
      expect(find.text(en.priceWatchLatest), findsOneWidget);
      expect(find.textContaining(en.priceWatchNotedBy('Anil Kulkarni')), findsOneWidget);
      expect(find.textContaining('Dealer enquiry'), findsOneWidget);
      expect(find.text(en.priceWatchNone), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a material with no price says so', (WidgetTester tester) async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.priceMaterial(4));

      expect(find.text(en.priceWatchNone), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a material that is not on the board says so', (WidgetTester tester) async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.priceMaterial(99));

      expect(find.text(en.priceWatchMaterialMissing), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('with only money.view there is no budget anywhere', (WidgetTester tester) async {
      final TestApp app = await priceApp();
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);

      expect(find.byKey(k('price-tab-budget')), findsNothing);
      expect(find.text(en.priceWatchTabBudget), findsNothing);
      expect(find.byKey(k('price-summary')), findsNothing);

      await tester.pumpWidget(const SizedBox.shrink());
      await pumpShellApp(tester, app, location: Routes.priceMaterial(1));
      expect(find.byKey(k('price-budget')), findsNothing);
      expect(find.text(en.priceWatchBudgetTitle), findsNothing);
      expect(find.textContaining('36,000'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('with money.overview the budget tab shows the effect in words', (WidgetTester tester) async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);

      await tester.tap(find.byKey(k('price-tab-budget')));
      await settle(tester);

      expect(find.byKey(k('price-summary')), findsOneWidget);
      expect(find.text(en.priceWatchMore('₹22,000')), findsOneWidget);
      expect(find.text(en.priceWatchMore('₹36,000')), findsWidgets);
      expect(find.text(en.priceWatchLess('₹14,000')), findsOneWidget);
      expect(find.text(en.priceWatchWithin), findsOneWidget);
      expect(find.text(en.priceWatchAbove('₹30', '7.89%')), findsOneWidget);
      expect(find.text(en.priceWatchBelow('₹2', '3.08%')), findsOneWidget);
      // The list is lazy: the materials with no effect are below the fold.
      await tester.scrollUntilVisible(find.text(en.priceWatchGapNoBoqRate), 300, scrollable: find.byType(Scrollable).first);
      expect(find.text(en.priceWatchGapNoBoqRate), findsOneWidget);
      await tester.scrollUntilVisible(find.text(en.priceWatchNone), 300, scrollable: find.byType(Scrollable).first);
      expect(find.text(en.priceWatchNone), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a material shows its own effect on the budget for money.overview', (WidgetTester tester) async {
      final TestApp app = await priceApp(overview: true);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.priceMaterial(2));

      expect(find.byKey(k('price-budget')), findsOneWidget);
      expect(find.text(en.priceWatchLess('₹14,000')), findsOneWidget);
      expect(find.textContaining(en.priceWatchStillToBuy('7,000.000', 'kg')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a pill says when the effect is beyond the margin', (WidgetTester tester) async {
      final TestApp app = await priceApp(
        overview: true,
        setup: (FakeApi api) => api.priceSummary = <String, dynamic>{...api.priceSummary, 'beyond_margin': true},
      );
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);
      await tester.tap(find.byKey(k('price-tab-budget')));
      await settle(tester);

      expect(find.text(en.priceWatchBeyond), findsOneWidget);
      expect(find.text(en.priceWatchWithin), findsNothing);
    });

    testWidgets('a 403 is a calm page, not an error', (WidgetTester tester) async {
      final TestApp app = await priceApp(setup: (FakeApi api) => api.priceBoardFailure = _refused);
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);

      expect(find.text(en.priceWatchRefusedTitle), findsOneWidget);
      expect(find.text(en.priceWatchRefusedMessage), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('nothing on the phone and no network says to connect once', (WidgetTester tester) async {
      final TestApp app = await priceApp(setup: (FakeApi api) => api.priceBoardFailure = const ServerFailure('boom', statusCode: 500));
      addTearDown(app.dispose);
      await pumpShellApp(tester, app, location: Routes.prices);

      expect(find.text(en.priceWatchNoDataMessage), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('someone without money.view is sent Home and sees no word about prices', (WidgetTester tester) async {
      final TestApp app = await priceApp(money: false, overview: true);
      addTearDown(app.dispose);

      for (final String location in <String>[Routes.prices, Routes.priceMaterial(1)]) {
        await pumpShellApp(tester, app, location: location);
        expect(find.byType(HomeScreen), findsOneWidget, reason: location);
        expect(find.byType(PriceWatchScreen), findsNothing);
        expect(find.text(en.priceWatchTitle), findsNothing);
        expect(find.text(en.priceWatchTile), findsNothing);
        await tester.pumpWidget(const SizedBox.shrink());
      }
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('every price screen fits 360dp in $locale', (WidgetTester tester) async {
        final TestApp app = await priceApp(overview: true);
        addTearDown(app.dispose);

        await pumpShellApp(tester, app, location: Routes.prices, locale: locale);
        expect(tester.takeException(), isNull);

        await tester.tap(find.byKey(k('price-tab-budget')));
        await settle(tester);
        expect(tester.takeException(), isNull);

        for (final int id in <int>[1, 2, 3, 4]) {
          await tester.pumpWidget(const SizedBox.shrink());
          await pumpShellApp(tester, app, location: Routes.priceMaterial(id), locale: locale);
          expect(tester.takeException(), isNull, reason: 'material $id');
        }
      });

      testWidgets('the board fits 360dp in $locale at a large text size', (WidgetTester tester) async {
        final TestApp app = await priceApp(overview: true);
        addTearDown(app.dispose);
        await pumpShellApp(tester, app, location: Routes.prices, locale: locale, textScale: 1.3);
        expect(tester.takeException(), isNull);
        await tester.tap(find.byKey(k('price-tab-budget')));
        await settle(tester);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
