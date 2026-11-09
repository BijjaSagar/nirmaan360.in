import 'dart:convert';
import 'dart:typed_data';

import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/design/design.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/features/projects/view/widgets/project_photo.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../../support/test_app.dart';

const String firstUrl = 'https://nirmaan360.in/api/v1/projects/1/image?v=aa11';
const String secondUrl = 'https://nirmaan360.in/api/v1/projects/1/image?v=bb22';

/// A one-pixel PNG.
final Uint8List pixel = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
);

Future<TestApp> photoApp({String? imageUrl = firstUrl}) async {
  final InMemoryAttachmentStore files = InMemoryAttachmentStore();
  final FakeApi api = FakeApi();
  final TestApp app = await TestApp.create(api: api, attachments: files);
  await app.services.projects.selectProject(1);
  if (imageUrl != null) await setPhoto(app, imageUrl);
  return app;
}

Future<void> setPhoto(TestApp app, String? imageUrl) async {
  final Map<String, dynamic> project = app.api.projects.firstWhere((p) => p['id'] == 1);
  project['image_url'] = imageUrl;
  project['updated_at'] = app.api.stampNow();
  if (imageUrl != null) app.api.projectImages[imageUrl] = pixel;
  await app.sync();
}

Future<ProjectRow> project(TestApp app) =>
    (app.db.select(app.db.projects)..where((t) => t.id.equals(1))).getSingle();

void main() {
  group('keeping the photo', () {
    late TestApp app;

    setUp(() async => app = await photoApp());
    tearDown(() => app.dispose());

    test('a pull keeps the address with the project', () async {
      expect((await project(app)).imageUrl, firstUrl);
    });

    test('it is fetched once and then read from the phone', () async {
      expect(await app.services.projectImages.bytesFor(1, firstUrl), pixel);
      expect(await app.services.projectImages.bytesFor(1, firstUrl), pixel);
      expect(app.api.projectImageCalls, <String>[firstUrl]);
    });

    test('with no network and nothing kept there is no photo', () async {
      (app.services.connectivity as FakeConnectivity).setOnline(false);
      expect(await app.services.projectImages.bytesFor(1, firstUrl), isNull);
      expect(app.api.projectImageCalls, isEmpty);
    });

    test('a photo kept earlier still shows with no network', () async {
      await app.services.projectImages.bytesFor(1, firstUrl);
      (app.services.connectivity as FakeConnectivity).setOnline(false);
      expect(await app.services.projectImages.bytesFor(1, firstUrl), pixel);
    });

    test('a new photo is fetched again', () async {
      await app.services.projectImages.bytesFor(1, firstUrl);
      await setPhoto(app, secondUrl);
      await app.services.projectImages.bytesFor(1, secondUrl);
      expect(app.api.projectImageCalls, <String>[firstUrl, secondUrl]);
    });

    test('until a new photo arrives the old one stays', () async {
      await app.services.projectImages.bytesFor(1, firstUrl);
      (app.services.connectivity as FakeConnectivity).setOnline(false);
      expect(await app.services.projectImages.bytesFor(1, secondUrl), pixel);
    });

    test('a refused fetch means no photo, not a crash', () async {
      app.api.projectImages.clear();
      expect(await app.services.projectImages.bytesFor(1, firstUrl), isNull);
    });

    test('a photo removed on the website leaves the phone', () async {
      await app.services.projectImages.bytesFor(1, firstUrl);
      await setPhoto(app, null);

      expect((await project(app)).imageUrl, isNull);
      expect(await app.db.readMeta(MetaKey.projectImage(1)), isNull);
      expect((app.services.context.attachments as InMemoryAttachmentStore).contents, isEmpty);
    });

    test('a project taken away takes its photo with it', () async {
      await app.services.projectImages.bytesFor(1, firstUrl);
      final Map<String, dynamic> row = app.api.projects.firstWhere((p) => p['id'] == 1);
      app.api.softDeleteOnWeb('projects', row);
      app.api.projects.remove(row);
      await app.sync();

      expect((app.services.context.attachments as InMemoryAttachmentStore).contents, isEmpty);
    });
  });

  test('a phone on the old database gains the column and reads projects again', () async {
    final AppDatabase db = AppDatabase(
      NativeDatabase.memory(
        setup: (raw) {
          raw.execute(
            "CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, name TEXT NOT NULL, "
            "location TEXT, status TEXT NOT NULL DEFAULT 'active', start_date TEXT, end_date TEXT, "
            "measurement_tolerance_pct TEXT NOT NULL DEFAULT '5.00', updated_at INTEGER)",
          );
          raw.execute('CREATE TABLE key_values (key TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL)');
          raw.execute("INSERT INTO projects (id, code, name) VALUES (1, 'KT-B', 'Kalyani Tower B')");
          raw.execute('PRAGMA user_version = 1');
        },
      ),
    );
    addTearDown(db.close);

    final ProjectRow row = await db.select(db.projects).getSingle();
    expect(row.name, 'Kalyani Tower B');
    expect(row.imageUrl, isNull);
    expect(await db.readMeta(MetaKey.fullRefresh), '1');
  });

  group('showing the photo', () {
    Future<void> pump(WidgetTester tester, TestApp app, ProjectRow row) async {
      await tester.pumpWidget(
        RepositoryProvider<AppServices>.value(
          value: app.services,
          child: MaterialApp(
            theme: buildN360Theme(),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
            home: Scaffold(body: Center(child: ProjectPhoto(project: row))),
          ),
        ),
      );
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 100));
      await pumpDrift(tester, 10);
    }

    testWidgets('no photo shows the first letter of the name', (tester) async {
      final TestApp app = await photoApp(imageUrl: null);
      addTearDown(app.dispose);
      await pump(tester, app, await project(app));

      expect(find.text('K'), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('project-photo')), findsNothing);
    });

    testWidgets('a photo that cannot be fetched falls back to the letter', (tester) async {
      final TestApp app = await photoApp();
      addTearDown(app.dispose);
      app.api.projectImages.clear();
      await pump(tester, app, await project(app));

      expect(find.text('K'), findsOneWidget);
    });

    testWidgets('a photo replaces the letter', (tester) async {
      final TestApp app = await photoApp();
      addTearDown(app.dispose);
      await pump(tester, app, await project(app));

      expect(find.byKey(const ValueKey<String>('project-photo')), findsOneWidget);
      expect(find.text('K'), findsNothing);
    });

    testWidgets('a screen reader skips it, because the name is beside it', (tester) async {
      final TestApp app = await photoApp(imageUrl: null);
      addTearDown(app.dispose);
      final SemanticsHandle handle = tester.ensureSemantics();
      await pump(tester, app, await project(app));

      expect(find.bySemanticsLabel('K'), findsNothing);
      handle.dispose();
    });
  });
}
