import 'dart:convert';
import 'dart:io';

import 'package:archive/archive_io.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/attachment_store.dart';
import 'package:nirmaan360/features/dpr/data/dpr_repository.dart';
import 'package:nirmaan360/features/offline_plan/data/offline_export.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../app/shell_test_helpers.dart';
import '../core/design/test_helpers.dart' show loadRealFonts;
import '../support/test_app.dart';

/// A phone on the offline plan with files on disk and a project with work.
class _Setup {
  _Setup(this.app, this.root, this.photo);
  final TestApp app;
  final Directory root;
  final File photo;
}

Future<_Setup> _setup() async {
  final root = await Directory.systemTemp.createTemp('n360-export');
  final store = DirectoryAttachmentStore(() async => root.path);
  final api = FakeApi()..offlineCompany = true;
  final app = await TestApp.create(seed: false, signedIn: false, api: api, attachments: store);
  await app.services.auth.signIn(email: 'owner@example.test', password: 'x');
  final photo = File('${root.path}/source.jpg')..writeAsBytesSync(List<int>.generate(2048, (i) => i % 256));
  return _Setup(app, root, photo);
}

void main() {
  setUpAll(loadRealFonts);

  group('the export', () {
    test('holds the data and every photo and bill', () async {
      final s = await _setup();
      addTearDown(() async {
        await s.app.dispose();
        await s.root.delete(recursive: true);
      });
      final services = s.app.services;

      final ProjectRow project = await services.projects.createProject(name: 'Kalyani');
      final wallet = await services.pettyCash.createWallet(projectId: project.id, name: 'Site cash');
      await services.pettyCash.addExpense(
        walletId: wallet.id,
        amount: Money.parse('200'),
        entryDate: '2026-11-08',
        category: 'Food',
        billSourcePath: s.photo.path,
      );
      final String dpr = await services.dpr.saveDraft(DprInput(projectId: project.id, reportDate: '2026-11-08', summary: 'Slab, "cast", today'));
      await services.dpr.addPhoto(dprClientUuid: dpr, sourcePath: s.photo.path);

      final OfflineExport export = await services.exportBackup();

      expect(export.photos, 2);
      expect(export.missingPhotos, 0);
      final Archive archive = ZipDecoder().decodeBytes(File(export.path).readAsBytesSync());
      final names = archive.files.map((f) => f.name).toSet();
      expect(names, containsAll(<String>['data.json', 'README.txt', 'csv/projects.csv', 'csv/measurements.csv']));
      final photos = names.where((n) => n.startsWith('photos/')).toList();
      expect(photos, hasLength(2));
      expect(archive.findFile(photos.first)!.content, s.photo.readAsBytesSync());

      final Map<String, dynamic> data = jsonDecode(utf8.decode(archive.findFile('data.json')!.content)) as Map<String, dynamic>;
      final tables = data['tables'] as Map<String, dynamic>;
      expect((tables['projects'] as List).single['name'], 'Kalyani');
      expect((tables['petty_cash_entries'] as List).single['category'], 'Food');
      expect((tables['dpr_photos'] as List), hasLength(1));
      expect(data['local_details'], isNotEmpty);

      final String csv = utf8.decode(archive.findFile('csv/dprs.csv')!.content);
      expect(csv, contains('"Slab, ""cast"", today"'));
    });

    test('carries no secret', () async {
      final s = await _setup();
      addTearDown(() async {
        await s.app.dispose();
        await s.root.delete(recursive: true);
      });
      final OfflineExport export = await s.app.services.exportBackup();
      final Archive archive = ZipDecoder().decodeBytes(File(export.path).readAsBytesSync());
      final String data = utf8.decode(archive.findFile('data.json')!.content);
      expect(data, isNot(contains('fake-token')));
      expect(data, isNot(contains('"token"')));
      expect(data, isNot(contains(s.app.services.offlinePlan.state!.token!)));
    });

    test('says when a photo is missing and still makes the file', () async {
      final s = await _setup();
      addTearDown(() async {
        await s.app.dispose();
        await s.root.delete(recursive: true);
      });
      final services = s.app.services;
      final ProjectRow project = await services.projects.createProject(name: 'A');
      final String dpr = await services.dpr.saveDraft(DprInput(projectId: project.id, reportDate: '2026-11-08'));
      await services.dpr.addPhoto(dprClientUuid: dpr, sourcePath: s.photo.path);
      final row = await s.app.db.select(s.app.db.dprPhotos).getSingle();
      await File('${s.root.path}/${row.relativePath}').delete();

      final OfflineExport export = await services.exportBackup();

      expect(export.photos, 0);
      expect(export.missingPhotos, 1);
      expect(File(export.path).existsSync(), isTrue);
    });

    test('still works after the licence has ended', () async {
      final s = await _setup();
      addTearDown(() async {
        await s.app.dispose();
        await s.root.delete(recursive: true);
      });
      s.app.clock.advance(const Duration(days: 200));
      await s.app.services.offlinePlan.load();

      final OfflineExport export = await s.app.services.exportBackup();

      expect(File(export.path).existsSync(), isTrue);
    });

    test('an old backup does not pile up', () async {
      final s = await _setup();
      addTearDown(() async {
        await s.app.dispose();
        await s.root.delete(recursive: true);
      });
      final first = await s.app.services.exportBackup();
      s.app.clock.advance(const Duration(minutes: 1));
      final second = await s.app.services.exportBackup();
      expect(File(second.path).existsSync(), isTrue);
      expect(File(first.path).existsSync(), isFalse);
    });
  });

  group('on screen', () {
    testWidgets('offers the share sheet from the plan screen', (tester) async {
      final app = await TestApp.create(seed: false, signedIn: false, api: FakeApi()..offlineCompany = true);
      addTearDown(app.dispose);
      await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

      await pumpShellApp(tester, app, location: Routes.offlinePlan);

      expect(find.text(lookupAppLocalizations(const Locale('en')).offlineExportButton), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    for (final locale in <String>['mr', 'hi']) {
      testWidgets('the export card fits 360dp in $locale', (tester) async {
        final app = await TestApp.create(seed: false, signedIn: false, api: FakeApi()..offlineCompany = true);
        addTearDown(app.dispose);
        await app.services.auth.signIn(email: 'owner@example.test', password: 'x');

        await pumpShellApp(tester, app, location: Routes.offlinePlan, locale: locale);

        final en = lookupAppLocalizations(Locale(locale));
        expect(find.text(en.offlineExportHint), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  });
}
