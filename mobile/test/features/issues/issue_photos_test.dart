import 'package:drift/drift.dart' show OrderingTerm, Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/widgets/record_photos.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/features/issues/bloc/issue_detail_cubit.dart';
import 'package:nirmaan360/features/issues/bloc/issue_form_cubit.dart';
import 'package:nirmaan360/features/issues/data/issue_repository.dart';
import 'package:nirmaan360/features/issues/view/issue_detail_screen.dart';
import 'package:nirmaan360/features/issues/view/issue_form_screen.dart';
import 'package:nirmaan360/features/sync_center/view/sync_labels.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import '../attendance/view/field_test_support.dart';

typedef Json = Map<String, dynamic>;

Future<TestApp> issueApp({bool canManage = true}) async {
  final FakeApi server = FakeApi();
  (server.user['can'] as Map)['issue.manage'] = canManage;
  return TestApp.create(api: server);
}

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<List<RecordPhoto>> photosOf(TestApp app, String uuid) => app.services.issues.watchPhotos(uuid).first;

/// An issue that has reached the server, and the id it was given.
Future<(String, int)> sentIssue(TestApp app) async {
  final String uuid = await app.services.issues.raise(projectId: 1, title: 'Leak at C3');
  await app.sync();
  return (uuid, app.api.issueByUuid(uuid)['id'] as int);
}

void main() {
  group('reading', () {
    late TestApp app;

    setUp(() async => app = await issueApp());
    tearDown(() => app.dispose());

    test('the server photos are listed and kept for when there is no network', () async {
      final (String uuid, int id) = await sentIssue(app);
      app.api.fieldPhotoRows['/issues/$id/photos'] = <Json>[
        <String, dynamic>{'id': 7, 'issue_id': id, 'kind': 'before', 'caption': 'Honeycombing', 'taken_at': null},
        <String, dynamic>{'id': 8, 'issue_id': id, 'kind': 'after', 'caption': null, 'taken_at': null},
      ];

      await app.services.issues.refreshPhotos(uuid);
      final List<RecordPhoto> seen = await photosOf(app, uuid);
      expect(seen.map((p) => p.server!.id), <int>[7, 8]);
      expect(seen.first.photoCaption, 'Honeycombing');
      expect(seen.last.photoKind, 'after');

      app.api.offline = true;
      await expectLater(app.services.issues.refreshPhotos(uuid), throwsA(isA<OfflineFailure>()));
      expect((await photosOf(app, uuid)).map((p) => p.server!.id), <int>[7, 8]);
    });

    test('a picture is fetched once and then read from the phone', () async {
      final (String uuid, int id) = await sentIssue(app);
      app.api.fieldPhotoRows['/issues/$id/photos'] = <Json>[
        <String, dynamic>{'id': 7, 'issue_id': id, 'kind': 'before', 'caption': null},
      ];
      await app.services.issues.refreshPhotos(uuid);
      final ServerPhoto photo = (await photosOf(app, uuid)).single.server!;

      await app.services.issues.photoBytes(photo);
      app.api.offline = true;
      final bytes = await app.services.issues.photoBytes(photo);

      expect(bytes, FakeApi.tinyPng);
      expect(app.api.fieldPhotoByteReads, <String>['/issue-photos/7']);
    });

    test('an issue the server has not seen has no list to ask for', () async {
      final String uuid = await app.services.issues.raise(projectId: 1, title: 'Not sent yet');
      await app.services.issues.refreshPhotos(uuid);
      expect(await photosOf(app, uuid), isEmpty);
    });

    test('a removed project takes its photo lists off the phone', () async {
      final (String uuid, int id) = await sentIssue(app);
      app.api.fieldPhotoRows['/issues/$id/photos'] = <Json>[
        <String, dynamic>{'id': 7, 'issue_id': id, 'kind': 'before', 'caption': null},
      ];
      await app.services.issues.refreshPhotos(uuid);
      expect(await app.db.readMeta(MetaKey.recordPhotos(1)), isNotNull);

      final Json project = app.api.projects.firstWhere((p) => p['id'] == 1);
      app.api.softDeleteOnWeb('projects', project);
      app.api.projects.remove(project);
      await app.sync();

      expect(await app.db.readMeta(MetaKey.recordPhotos(1)), isNull);
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await issueApp());
    tearDown(() => app.dispose());

    test('photos taken with a new issue wait behind it and use the id the server gives it', () async {
      final String uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Leak at C3',
        photoPaths: <String>['/tmp/a.jpg', '/tmp/b.jpg'],
      );

      final List<OutboxRow> queued = await outbox(app);
      expect(queued.map((r) => r.type), <OutboxType>[OutboxType.issue, OutboxType.issuePhoto, OutboxType.issuePhoto]);
      expect(queued.skip(1).every((r) => r.groupUuid == uuid && r.parentUuid == uuid), isTrue);
      expect(queued[1].payload, contains('"kind":"before"'));

      await app.sync();

      final int id = app.api.issueByUuid(uuid)['id'] as int;
      expect(app.api.fieldPhotos.map((p) => p.$1), <String>['/issues/$id/photos', '/issues/$id/photos']);
      expect(app.api.fieldPhotoForms.first.$1, 'photo');
      expect(app.api.fieldPhotoForms.first.$2, <String, String>{'kind': 'before'});
      expect((await outbox(app)).every((r) => r.status == OutboxStatus.done), isTrue);
    });

    test('a photo on a sent issue is posted on its own and keeps its kind', () async {
      final (String uuid, int id) = await sentIssue(app);

      await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg'], kind: IssuePhotoKind.after);
      final OutboxRow row = (await outbox(app)).last;
      expect(row.groupUuid, row.recordUuid, reason: 'a stuck status change must not hold it back');

      await app.sync();

      expect(app.api.fieldPhotos.single.$1, '/issues/$id/photos');
      expect(app.api.photoPosts.values.single['kind'], 'after');
    });

    test('a photo added with no network is sent once, when the network returns', () async {
      app.api.offline = true;
      final String uuid = await app.services.issues.raise(
        projectId: 1,
        title: 'Leak at C3',
        photoPaths: <String>['/tmp/a.jpg'],
      );
      await app.sync();
      expect(app.api.photoPosts, isEmpty);

      app.api.offline = false;
      await app.sync();

      expect(app.api.photoPosts, hasLength(1));
      expect(app.api.photoPosts.values.single['issue_id'], app.api.issueByUuid(uuid)['id']);
    });

    test('a photo whose reply is lost reaches the server exactly once', () async {
      final (String uuid, _) = await sentIssue(app);
      await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg'], kind: IssuePhotoKind.before);

      app.api.loseNextProgressReply = true;
      await app.sync();
      expect((await outbox(app)).last.status, isNot(OutboxStatus.done));

      await app.sync();

      expect(app.api.photoPosts, hasLength(1));
      expect(app.api.fieldPhotos, hasLength(2), reason: 'it was asked twice, and stored once');
      expect((await outbox(app)).last.status, OutboxStatus.done);
    });

    test('a photo just sent is listed once, with no wait for a refresh', () async {
      final (String uuid, int id) = await sentIssue(app);
      await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg'], kind: IssuePhotoKind.before);
      expect((await photosOf(app, uuid)).single.isWaiting, isTrue);

      await app.sync();

      final List<RecordPhoto> seen = await photosOf(app, uuid);
      expect(seen, hasLength(1));
      expect(seen.single.isWaiting, isFalse);
      expect(seen.single.photoKind, 'before');
      expect(seen.single.server!.id, app.api.photoPosts.values.single['id']);
      expect(app.api.fieldPhotoByteReads, isEmpty);
      expect(id, isPositive);
    });

    test('a file the server refuses waits for a person and holds nothing else back', () async {
      final (String uuid, _) = await sentIssue(app);
      await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg'], kind: IssuePhotoKind.before);
      await app.services.issues.changeStatus(uuid, 'in_progress');
      app.api.failNextProgressPost = const ValidationFailure(
        'The photo must be an image.',
        errors: <String, List<String>>{
          'photo': <String>['The photo must be an image.'],
        },
      );

      await app.sync();

      final OutboxRow photo = (await outbox(app)).firstWhere((r) => r.type == OutboxType.issuePhoto);
      expect(photo.status, OutboxStatus.attention);
      expect(photo.attentionCode, AttentionCode.validation);
      expect(photo.attentionReason, contains('must be an image'));
      expect(app.api.issueByUuid(uuid)['status'], 'in_progress');
      expect((await photosOf(app, uuid)).single.failed, isTrue);
    });

    test('no permission and a vanished issue both go to the sync screen', () async {
      final (String uuid, _) = await sentIssue(app);
      await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg'], kind: IssuePhotoKind.before);
      app.api.failNextProgressPost = const ServerFailure('Forbidden.', statusCode: 403);
      await app.sync();
      expect((await outbox(app)).last.attentionCode, AttentionCode.forbidden);

      await app.services.issues.addPhotos(uuid, <String>['/tmp/b.jpg'], kind: IssuePhotoKind.after);
      app.api.failNextProgressPost = const ServerFailure('Not found.', statusCode: 404);
      await app.sync();
      expect((await outbox(app)).last.attentionCode, AttentionCode.parentMissing);
    });

    test('discarding an issue that never went takes its photos with it', () async {
      app.api.offline = true;
      await app.services.issues.raise(projectId: 1, title: 'Leak', photoPaths: <String>['/tmp/a.jpg']);
      final OutboxRow issue = (await outbox(app)).first;
      await (app.db.update(app.db.outbox)..where((t) => t.id.equals(issue.id))).write(
        const OutboxCompanion(status: Value(OutboxStatus.attention)),
      );

      await app.services.syncCenter.discard(issue.id);

      expect(await outbox(app), isEmpty);
    });

    test('the sync log names an issue photo', () async {
      expect(syncTypeIcon(OutboxType.issuePhoto), Icons.photo_camera_outlined);
    });
  });

  group('the form and the detail cubit', () {
    late TestApp app;

    setUp(() async => app = await issueApp());
    tearDown(() => app.dispose());

    test('photos chosen on the form are kept when the issue is saved', () async {
      final IssueFormCubit cubit = IssueFormCubit(app.services);
      addTearDown(cubit.close);

      cubit
        ..addPhoto('/tmp/a.jpg')
        ..addPhoto('/tmp/a.jpg')
        ..addPhoto('/tmp/b.jpg')
        ..removePhoto('/tmp/b.jpg');
      expect(cubit.state.photos, <String>['/tmp/a.jpg']);

      expect(await cubit.submit(title: 'Crack', location: ''), isTrue);
      expect((await outbox(app)).map((r) => r.type), <OutboxType>[OutboxType.issue, OutboxType.issuePhoto]);
    });

    test('the form takes no more photos than the phone should hold', () {
      final IssueFormCubit cubit = IssueFormCubit(app.services);
      addTearDown(cubit.close);
      for (var i = 0; i < 14; i++) {
        cubit.addPhoto('/tmp/$i.jpg');
      }
      expect(cubit.state.photos, hasLength(RecordPhotos.maxPerKind));
    });

    test('the detail screen queues a photo and says whether it will wait', () async {
      final (String uuid, _) = await sentIssue(app);
      app.connectivity.setOnline(false);
      final IssueDetailCubit cubit = IssueDetailCubit(app.services, uuid);
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.issue != null);

      await cubit.addPhoto(IssuePhotoKind.before, '/tmp/a.jpg');

      expect(cubit.state.photoSavedId, 1);
      expect(cubit.state.photoSavedWhileOffline, isTrue);
      expect((await outbox(app)).last.type, OutboxType.issuePhoto);
    });

    test('someone who cannot manage issues is not offered photos', () async {
      final TestApp viewer = await issueApp(canManage: false);
      addTearDown(viewer.dispose);
      final String uuid = await viewer.services.issues.raise(projectId: 1, title: 'Leak');

      final IssueDetailCubit cubit = IssueDetailCubit(viewer.services, uuid);
      addTearDown(cubit.close);

      expect(cubit.state.canAddPhotos, isFalse);
    });
  });

  group('screens', () {
    testWidgets('the detail shows each kind with its button, and the ones waiting to send', (tester) async {
      setPhone(tester);
      final TestApp app = await issueApp();
      addTearDown(app.dispose);
      final (String uuid, int id) = await sentIssue(app);
      app.api.fieldPhotoRows['/issues/$id/photos'] = <Json>[
        <String, dynamic>{'id': 7, 'issue_id': id, 'kind': 'before', 'caption': 'Honeycombing'},
      ];
      await app.services.issues.refreshPhotos(uuid);
      await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg'], kind: IssuePhotoKind.after);

      await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Photos'), findsOneWidget);
      expect(find.text('Before'), findsOneWidget);
      expect(find.text('After'), findsOneWidget);
      expect(find.text('Honeycombing'), findsOneWidget);
      expect(find.text('Waiting to send'), findsOneWidget);
      expect(find.text('Add before photo'), findsOneWidget);
      expect(find.text('Add after photo'), findsOneWidget);
      expect(find.byType(RecordPhotoTile), findsNWidgets(2));
      expect(tester.takeException(), isNull);
    });

    testWidgets('a photo opens larger and closes again', (tester) async {
      setPhone(tester);
      final TestApp app = await issueApp();
      addTearDown(app.dispose);
      final (String uuid, int id) = await sentIssue(app);
      app.api.fieldPhotoRows['/issues/$id/photos'] = <Json>[
        <String, dynamic>{'id': 7, 'issue_id': id, 'kind': 'before', 'caption': 'Honeycombing'},
      ];
      await app.services.issues.refreshPhotos(uuid);

      await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 600));

      await tester.tap(find.byType(RecordPhotoTile));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 600));
      expect(find.text('Close'), findsOneWidget);

      await tester.tap(find.text('Close'));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 600));
      expect(find.text('Close'), findsNothing);
    });

    testWidgets('someone who cannot manage issues sees the photos but no way to add', (tester) async {
      setPhone(tester);
      final TestApp app = await issueApp(canManage: false);
      addTearDown(app.dispose);
      final String uuid = await app.services.issues.raise(projectId: 1, title: 'Leak');

      await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid)));
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Add before photo'), findsNothing);
      expect(find.text('Add after photo'), findsNothing);
      expect(find.text('Photos'), findsNothing, reason: 'nothing to show and nothing to do');
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('the form and the detail fit a small phone in $locale', (tester) async {
        setPhone(tester);
        final TestApp app = await issueApp();
        addTearDown(app.dispose);
        final (String uuid, int id) = await sentIssue(app);
        app.api.fieldPhotoRows['/issues/$id/photos'] = <Json>[
          for (var n = 1; n <= 4; n++)
            <String, dynamic>{'id': n, 'issue_id': id, 'kind': n.isEven ? 'after' : 'before', 'caption': 'Honeycombing near the column'},
        ];
        await app.services.issues.refreshPhotos(uuid);
        await app.services.issues.addPhotos(uuid, <String>['/tmp/a.jpg', '/tmp/b.jpg'], kind: IssuePhotoKind.before);

        await tester.pumpWidget(fieldApp(app.services, IssueDetailScreen(clientUuid: uuid), locale: Locale(locale)));
        await pumpDrift(tester, 10);
        await tester.pump(const Duration(milliseconds: 600));
        expect(tester.takeException(), isNull, reason: 'detail');
        final AppLocalizations l = await AppLocalizations.delegate.load(Locale(locale));
        expect(find.text(l.issuesPhotoAddBefore), findsOneWidget);
        expect(find.text(l.photosWaiting), findsNWidgets(2));

        await tester.pumpWidget(fieldApp(app.services, const IssueFormScreen(), locale: Locale(locale)));
        await pumpDrift(tester, 10);
        await tester.pump(const Duration(milliseconds: 600));
        expect(tester.takeException(), isNull, reason: 'form');
      });
    }
  });
}
