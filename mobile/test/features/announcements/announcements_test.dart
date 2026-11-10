import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/push/push_messaging.dart';
import 'package:nirmaan360/core/push/push_target.dart';
import 'package:nirmaan360/features/announcements/bloc/announcements_cubit.dart';
import 'package:nirmaan360/features/announcements/data/announcement.dart';
import 'package:nirmaan360/features/announcements/view/announcements_screen.dart';
import 'package:nirmaan360/features/notifications/view/notification_settings_screen.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../app/shell_test_helpers.dart';
import '../../core/design/test_helpers.dart' show loadRealFonts;
import '../../support/fake_push.dart';
import '../../support/test_app.dart';

/// Holds back an answer until [release], to put a refresh between a request and its answer.
class _SlowApi extends FakeApi {
  bool hold = false;
  final List<Completer<void>> _held = <Completer<void>>[];

  void release() {
    for (final Completer<void> gate in _held) {
      gate.complete();
    }
  }

  @override
  Future<Map<String, dynamic>> announcements({int page = 1, String? locale, bool portal = false}) async {
    if (hold) {
      final Completer<void> gate = Completer<void>();
      _held.add(gate);
      final Map<String, dynamic> answer = await super.announcements(page: page, locale: locale, portal: portal);
      await gate.future;
      return answer;
    }
    return super.announcements(page: page, locale: locale, portal: portal);
  }
}

/// A row as `GET /announcements` sends it.
Map<String, dynamic> _row(int id, {String? title, String? image, bool repeats = false, String from = 'Acme Infra'}) => <String, dynamic>{
  'id': id,
  'title': title ?? 'Notice $id',
  'body': 'Wear helmets on every site from Monday. This line is long enough to wrap on a narrow phone.',
  'image_url': image,
  'from': from,
  'repeats': repeats,
  'sent_at': '2026-11-10T18:30:00+05:30',
  'first_sent_at': '2026-11-10T18:30:00+05:30',
};

Future<TestApp> _open(WidgetTester tester, FakeApi api, {String location = Routes.announcements, String? locale, double textScale = 1}) async {
  final TestApp app = await TestApp.create(api: api);
  addTearDown(app.dispose);
  await pumpShellApp(tester, app, location: location, locale: locale, textScale: textScale);
  await settle(tester);
  return app;
}

void main() {
  setUpAll(loadRealFonts);
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));

  group('reading the list', () {
    test('rows the app cannot use are dropped, and nothing throws', () {
      final AnnouncementPage page = AnnouncementPage.fromJson(<String, dynamic>{
        'data': <Object?>[
          _row(1, image: 'https://nirmaan360.in/announcement-images/abc'),
          <String, dynamic>{'id': 0, 'title': 'No id'},
          <String, dynamic>{'id': 3, 'title': '  '},
          'not a row',
          null,
          <String, dynamic>{'id': '4', 'title': 'Id as text', 'image_url': 'javascript:alert(1)', 'sent_at': 'yesterday'},
        ],
        'current_page': 1,
        'last_page': 2,
      });
      expect(page.items.map((item) => item.id), <int>[1, 4]);
      expect(page.items.first.imageUrl, 'https://nirmaan360.in/announcement-images/abc');
      expect(page.items.last.imageUrl, isNull, reason: 'only a web address is fetched');
      expect(page.items.last.sentAt, isNull);
      expect(page.hasMore, isTrue);

      expect(AnnouncementPage.fromJson(const <String, dynamic>{}).items, isEmpty);
      expect(AnnouncementPage.fromJson(const <String, dynamic>{}).hasMore, isFalse);
    });
  });

  group('a tapped notification', () {
    test('of type announcement opens the list at that one', () async {
      final PushRouter router = PushRouter(allows: (_) => true, lookup: (_, _) async => null);
      expect(PushTarget.parse(<String, String>{'type': 'announcement', 'id': '12', 'repeats': '1'}), const PushTarget(PushKind.announcement, id: 12));
      expect(await router.pathFor(PushTarget.parse(<String, String>{'type': 'announcement', 'id': '12'})), Routes.announcementsAt(12));
      expect(await router.pathFor(PushTarget.parse(<String, String>{'type': 'announcement'})), Routes.announcements);
    });

    test('opens it for a contractor login too, whose gate lets Settings pages through', () async {
      final PushRouter router = PushRouter(
        home: Routes.portal,
        allows: (uri) => Routes.isPortal(uri.path) || Routes.isSettings(uri.path),
        lookup: (_, _) async => null,
      );
      expect(await router.pathFor(PushTarget.parse(<String, String>{'type': 'announcement', 'id': '5'})), Routes.announcementsAt(5));
    });
  });

  testWidgets('Settings opens the list, which shows each one in full with who sent it', (tester) async {
    final FakeApi api = FakeApi()
      ..announcementRows = <Map<String, dynamic>>[
        _row(2, title: 'Safety week', image: 'https://nirmaan360.in/announcement-images/abc'),
        _row(1, title: 'Monthly review', repeats: true, from: 'Nirmaan360'),
      ];
    await _open(tester, api, location: Routes.settings);

    await tester.tap(find.byKey(const ValueKey<String>('settings-announcements')));
    await settle(tester);

    expect(find.byType(AnnouncementsScreen), findsOneWidget);
    expect(find.text('Safety week'), findsOneWidget);
    expect(find.text('Monthly review'), findsOneWidget);
    expect(find.textContaining('Acme Infra · '), findsOneWidget);
    expect(find.textContaining('Nirmaan360 · '), findsOneWidget);
    expect(find.text(en.announcementsReminder), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('announcement-image-2')), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('announcement-image-1')), findsNothing);
    expect(api.announcementCalls.single, (page: 1, locale: 'en', portal: false));
  });

  testWidgets('asks for the text in the language the app shows', (tester) async {
    final FakeApi api = FakeApi()..announcementRows = <Map<String, dynamic>>[_row(1)];
    await _open(tester, api, locale: 'mr');

    expect(api.announcementCalls.single.locale, 'mr');
    expect(find.text(lookupAppLocalizations(const Locale('mr')).announcementsTitle), findsOneWidget);
  });

  testWidgets('marks the one the notification was about', (tester) async {
    final FakeApi api = FakeApi()..announcementRows = <Map<String, dynamic>>[_row(2), _row(1)];
    await _open(tester, api, location: Routes.announcementsAt(1));

    final AnnouncementCard marked = tester.widget<AnnouncementCard>(find.byKey(const ValueKey<String>('announcement-1')));
    final AnnouncementCard other = tester.widget<AnnouncementCard>(find.byKey(const ValueKey<String>('announcement-2')));
    expect(marked.highlighted, isTrue);
    expect(other.highlighted, isFalse);
  });

  testWidgets('says when there is nothing yet', (tester) async {
    await _open(tester, FakeApi());

    expect(find.text(en.announcementsEmptyTitle), findsOneWidget);
  });

  testWidgets('offline, it says the list needs the internet and tries again on request', (tester) async {
    final FakeApi api = FakeApi()
      ..offline = true
      ..announcementRows = <Map<String, dynamic>>[_row(1, title: 'Back online')];
    await _open(tester, api);

    expect(find.text(en.announcementsOfflineTitle), findsOneWidget);

    api.offline = false;
    await tester.tap(find.byKey(const ValueKey<String>('announcements-retry')));
    await settle(tester);

    expect(find.text('Back online'), findsOneWidget);
  });

  testWidgets('older ones come a page at a time', (tester) async {
    final FakeApi api = FakeApi()
      ..announcementsPerPage = 2
      ..announcementRows = <Map<String, dynamic>>[_row(3), _row(2), _row(1, title: 'The oldest')];
    await _open(tester, api);

    expect(find.text('The oldest'), findsNothing);
    await tester.scrollUntilVisible(find.byKey(const ValueKey<String>('announcements-more')), 200);
    await tester.tap(find.byKey(const ValueKey<String>('announcements-more')));
    await settle(tester);

    await tester.scrollUntilVisible(find.text('The oldest'), 200);
    expect(find.text('The oldest'), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('announcements-more')), findsNothing);
    expect(api.announcementCalls.map((call) => call.page), <int>[1, 2]);
  });

  testWidgets('a failed "Show older" says so and keeps the list', (tester) async {
    final FakeApi api = FakeApi()
      ..announcementsPerPage = 1
      ..announcementRows = <Map<String, dynamic>>[_row(2, title: 'Newest'), _row(1)];
    await _open(tester, api);

    api.offline = true;
    await tester.tap(find.byKey(const ValueKey<String>('announcements-more')));
    await settle(tester);

    expect(find.text(en.announcementsOfflineMessage), findsOneWidget);
    expect(find.text('Newest'), findsOneWidget);
    expect(find.byKey(const ValueKey<String>('announcements-more')), findsOneWidget, reason: 'it can be tried again');
    await tester.pump(const Duration(seconds: 5));
    await tester.pump(const Duration(milliseconds: 400));
  });

  test('an older page that arrives after a refresh is dropped', () async {
    final _SlowApi api = _SlowApi()
      ..announcementsPerPage = 1
      ..announcementRows = <Map<String, dynamic>>[_row(3), _row(2), _row(1)];
    final AnnouncementsCubit cubit = AnnouncementsCubit(AnnouncementsRepository(api, portal: () => false), locale: 'en');
    addTearDown(cubit.close);

    await cubit.load();
    api.hold = true;
    final Future<void> older = cubit.loadMore();
    api.announcementRows = <Map<String, dynamic>>[_row(4), _row(3), _row(2), _row(1)];
    api.hold = false;
    await cubit.load();
    api.release();
    await older;

    expect(cubit.state.items.map((item) => item.id), <int>[4]);
    expect(cubit.state.page, 1);
  });

  testWidgets('a contractor login reads its list under the portal', (tester) async {
    final FakeApi api = FakeApi()
      ..becomeContractor()
      ..announcementRows = <Map<String, dynamic>>[_row(1, title: 'Portal notice')];
    final TestApp app = await TestApp.create(api: api, seed: false, signedIn: false);
    addTearDown(app.dispose);
    await app.services.auth.signIn(email: 'contractor@demo.test', password: 'password');
    await pumpShellApp(tester, app, location: Routes.announcements);
    await settle(tester);

    expect(find.byType(AnnouncementsScreen), findsOneWidget, reason: 'the gate lets a contractor in');
    expect(find.text('Portal notice'), findsOneWidget);
    expect(api.announcementCalls.single.portal, isTrue);
  });

  testWidgets('the switches page names the announcements kind in the app\'s own words', (tester) async {
    final FakeApi api = FakeApi()
      ..notificationSwitches = <String, dynamic>{
        'push': <String, dynamic>{
          'enabled': true,
          'connected': true,
          'categories': <Map<String, dynamic>>[
            <String, dynamic>{'key': 'announcements', 'label': 'Server words', 'hint': 'Server hint', 'enabled': true},
          ],
          'quiet_hours': <String, dynamic>{'enabled': false, 'from': '22:00', 'until': '07:00', 'timezone': 'Asia/Kolkata'},
        },
      };
    final TestApp app = await TestApp.create(api: api, pushMessaging: FakePushMessaging(permissionNow: PushPermission.granted));
    addTearDown(app.dispose);
    await app.services.startPush();
    await pumpShellApp(tester, app, location: Routes.notificationSettings);
    await settle(tester);

    expect(find.byType(NotificationSettingsScreen), findsOneWidget);
    expect(find.text(en.pushPrefsAnnouncements), findsOneWidget);
    expect(find.text(en.pushPrefsAnnouncementsHint), findsOneWidget);
    expect(find.text('Server words'), findsNothing);

    await tester.tap(find.byKey(const ValueKey<String>('push-prefs-announcements')));
    await settle(tester);
    expect(api.preferenceChanges.single.changes, <String, Object?>{
      'categories': <String, bool>{'announcements': false},
    });
  });

  for (final String locale in <String>['mr', 'hi']) {
    testWidgets('fits 360dp in $locale at a large text size', (tester) async {
      tester.view.physicalSize = const Size(360, 780);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final FakeApi api = FakeApi()..announcementRows = <Map<String, dynamic>>[_row(1, repeats: true), _row(2)];
      await _open(tester, api, locale: locale, textScale: 1.3);

      expect(tester.takeException(), isNull);
      expect(find.byType(AnnouncementCard), findsWidgets);
    });
  }
}
