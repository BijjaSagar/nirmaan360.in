import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/app/routes.dart';
import 'package:nirmaan360/app/widgets/record_photos.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/failures.dart';
import 'package:nirmaan360/features/measurement/bloc/measurement_detail_cubit.dart';
import 'package:nirmaan360/features/measurement/bloc/measurement_form_cubit.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/data/project_repository.dart';
import 'package:nirmaan360/features/sync_center/view/sync_labels.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

import 'view/book_test_kit.dart';

typedef Json = Map<String, dynamic>;

MeasurementInput entry({List<String> photos = const <String>[]}) => MeasurementInput(
  projectId: 1,
  boqItemId: 1,
  measuredOn: '2026-11-09',
  nos: Qty.parse('2.000'),
  length: Qty.parse('10.000'),
  photoPaths: photos,
);

Future<List<OutboxRow>> outbox(TestApp app) =>
    (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();

Future<List<RecordPhoto>> photosOf(TestApp app, String uuid) => app.services.measurements.watchPhotos(uuid).first;

Future<TestApp> photoApp({bool canCreate = true}) {
  final FakeApi server = FakeApi();
  (server.user['can'] as Map)['measurement.create'] = canCreate;
  return bookApp(api: server);
}

/// An entry that has reached the server, and the id it was given.
Future<(String, int)> sentEntry(TestApp app, {List<String> photos = const <String>[]}) async {
  final String uuid = await app.services.measurements.create(entry(photos: photos));
  await app.sync();
  return (uuid, app.api.measurementByUuid(uuid)['id'] as int);
}

void main() {
  group('reading', () {
    late TestApp app;

    setUp(() async => app = await photoApp());
    tearDown(() => app.dispose());

    test('the server lists photos and scanned sketches, and keeps them for offline', () async {
      final (String uuid, int id) = await sentEntry(app);
      app.api.fieldPhotoRows['/measurements/$id/attachments'] = <Json>[
        <String, dynamic>{'id': 4, 'measurement_entry_id': id, 'kind': 'photo', 'caption': 'Slab edge', 'is_image': true},
        <String, dynamic>{'id': 5, 'measurement_entry_id': id, 'kind': 'sketch', 'caption': null, 'is_image': false},
      ];

      await app.services.measurements.refreshPhotos(uuid);
      app.api.offline = true;
      final List<RecordPhoto> seen = await photosOf(app, uuid);

      expect(seen.map((p) => p.server!.id), <int>[4, 5]);
      expect(seen.map((p) => p.server!.isImage), <bool>[true, false]);
      expect(seen.first.photoCaption, 'Slab edge');
    });

    test('an entry the server has not seen has nothing to read', () async {
      final String uuid = await app.services.measurements.create(entry());
      await app.services.measurements.refreshPhotos(uuid);
      expect(await photosOf(app, uuid), isEmpty);
    });

    test('a picture is fetched from its own route, once', () async {
      final (String uuid, int id) = await sentEntry(app);
      app.api.fieldPhotoRows['/measurements/$id/attachments'] = <Json>[
        <String, dynamic>{'id': 4, 'measurement_entry_id': id, 'kind': 'photo', 'is_image': true},
      ];
      await app.services.measurements.refreshPhotos(uuid);
      final ServerPhoto photo = (await photosOf(app, uuid)).single.server!;

      await app.services.measurements.photoBytes(photo);
      await app.services.measurements.photoBytes(photo);

      expect(app.api.fieldPhotoByteReads, <String>['/measurement-attachments/4']);
    });
  });

  group('sending', () {
    late TestApp app;

    setUp(() async => app = await photoApp());
    tearDown(() => app.dispose());

    test('photos taken with a new entry wait behind it and go to the id the server gives it', () async {
      final String uuid = await app.services.measurements.create(entry(photos: <String>['/tmp/a.jpg', '/tmp/b.jpg']));

      final List<OutboxRow> queued = await outbox(app);
      expect(queued.map((r) => r.type), <OutboxType>[
        OutboxType.measurement,
        OutboxType.measurementPhoto,
        OutboxType.measurementPhoto,
      ]);
      expect(queued.skip(1).every((r) => r.groupUuid == uuid && r.parentUuid == uuid), isTrue);

      await app.sync();

      final int id = app.api.measurementByUuid(uuid)['id'] as int;
      expect(app.api.fieldPhotos.map((p) => p.$1), <String>['/measurements/$id/attachments', '/measurements/$id/attachments']);
      expect(app.api.fieldPhotoForms.first.$1, 'file', reason: 'the server reads an attachment from `file`');
      expect(app.api.fieldPhotoForms.first.$2, <String, String>{'kind': 'photo'});
      expect((await outbox(app)).every((r) => r.status == OutboxStatus.done), isTrue);
    });

    test('a photo added to a sent draft goes before the submission queued after it', () async {
      final (String uuid, int id) = await sentEntry(app);

      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      await app.services.measurements.sendForApproval(uuid);
      final List<OutboxRow> queued = (await outbox(app)).where((r) => r.status != OutboxStatus.done).toList();
      expect(queued.map((r) => r.type), <OutboxType>[OutboxType.measurementPhoto, OutboxType.measurementSubmit]);
      expect(queued.every((r) => r.groupUuid == uuid), isTrue, reason: 'the submission may not overtake the photo');

      await app.sync();

      expect(app.api.photoPosts, hasLength(1));
      expect(app.api.photoPosts.values.single['measurement_entry_id'], id);
      expect(app.api.measurementByUuid(uuid)['status'], 'IN_REVIEW');
      expect((await outbox(app)).every((r) => r.status == OutboxStatus.done), isTrue);
    });

    test('a photo added with no network is sent once, when the network returns', () async {
      final (String uuid, _) = await sentEntry(app);
      app.api.offline = true;
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      await app.sync();
      expect(app.api.photoPosts, isEmpty);

      app.api.offline = false;
      await app.sync();

      expect(app.api.photoPosts, hasLength(1));
    });

    test('a photo whose reply is lost reaches the server exactly once', () async {
      final (String uuid, _) = await sentEntry(app);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);

      app.api.loseNextProgressReply = true;
      await app.sync();
      expect((await outbox(app)).last.status, isNot(OutboxStatus.done));
      await app.sync();

      expect(app.api.photoPosts, hasLength(1));
      expect(app.api.fieldPhotos, hasLength(2));
      expect((await outbox(app)).last.status, OutboxStatus.done);
    });

    test('a sent photo is listed once, with no wait for a refresh', () async {
      final (String uuid, _) = await sentEntry(app);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      expect((await photosOf(app, uuid)).single.isWaiting, isTrue);

      await app.sync();

      final List<RecordPhoto> seen = await photosOf(app, uuid);
      expect(seen, hasLength(1));
      expect(seen.single.isWaiting, isFalse);
      expect(seen.single.server!.id, app.api.photoPosts.values.single['id']);
    });

    test('an entry approved while its photo waited sends the photo to the sync screen, unchanged', () async {
      final (String uuid, _) = await sentEntry(app);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'APPROVED');

      await app.sync();

      final OutboxRow photo = (await outbox(app)).last;
      expect(photo.status, OutboxStatus.attention);
      expect(photo.attentionCode, AttentionCode.approved);
      expect(app.api.photoPosts, isEmpty);
    });

    test('an entry now with the approver says so', () async {
      final (String uuid, _) = await sentEntry(app);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'IN_REVIEW');

      await app.sync();

      expect((await outbox(app)).last.attentionCode, AttentionCode.inReview);
    });

    test('a file the server refuses waits for a person', () async {
      final (String uuid, _) = await sentEntry(app);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      app.api.failNextProgressPost = const ValidationFailure(
        'The file must be an image.',
        errors: <String, List<String>>{
          'file': <String>['The file must be an image.'],
        },
      );

      await app.sync();

      final OutboxRow photo = (await outbox(app)).last;
      expect(photo.attentionCode, AttentionCode.validation);
      expect(photo.attentionReason, contains('must be an image'));
    });

    test('a photo that failed holds the submission behind it until a person decides', () async {
      final (String uuid, _) = await sentEntry(app);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      await app.services.measurements.sendForApproval(uuid);
      app.api.failNextProgressPost = const ServerFailure('Forbidden.', statusCode: 403);

      await app.sync();

      final List<OutboxRow> rows = await outbox(app);
      expect(rows.where((r) => r.status == OutboxStatus.attention).single.type, OutboxType.measurementPhoto);
      expect(app.api.measurementByUuid(uuid)['status'], 'DRAFT');

      await app.services.syncCenter.discard(rows.firstWhere((r) => r.status == OutboxStatus.attention).id);
      await app.sync();
      expect(app.api.measurementByUuid(uuid)['status'], 'IN_REVIEW');
    });

    test('the sync log names a measurement photo', () {
      expect(syncTypeIcon(OutboxType.measurementPhoto), Icons.photo_camera_outlined);
    });
  });

  group('who may add, and when', () {
    late TestApp app;

    setUp(() async => app = await photoApp());
    tearDown(() => app.dispose());

    test('an entry waiting to be sent for approval takes no more photos', () async {
      final String uuid = await app.services.measurements.create(entry());
      await app.services.measurements.sendForApproval(uuid);

      await expectLater(app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']), throwsA(isA<RecordLocked>()));
      expect(await outbox(app), hasLength(2));
    });

    test('approved and in-review entries take none, a returned one does', () async {
      final (String uuid, _) = await sentEntry(app);

      for (final String status in <String>['APPROVED', 'IN_REVIEW', 'REJECTED']) {
        app.api.setServerStatus(app.api.measurementByUuid(uuid), status);
        await app.sync();
        await expectLater(
          app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']),
          throwsA(isA<RecordLocked>()),
          reason: status,
        );
      }

      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'RETURNED', returnRemark: 'Add a photo');
      await app.sync();
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);
      expect((await outbox(app)).last.type, OutboxType.measurementPhoto);
    });

    test('the detail cubit says whether a photo will wait, and offers it only while the entry can change', () async {
      final (String uuid, _) = await sentEntry(app);
      app.connectivity.setOnline(false);
      final MeasurementDetailCubit cubit = MeasurementDetailCubit(services: app.services, clientUuid: uuid)..start();
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.entry != null);
      expect(cubit.state.canAddPhotos, isTrue);

      expect(await cubit.addPhoto('/tmp/a.jpg'), DetailPhotoResult.savedOffline);

      await app.services.measurements.sendForApproval(uuid);
      await cubit.stream.firstWhere((s) => s.submitWaiting);
      expect(cubit.state.canAddPhotos, isFalse);
      expect(await cubit.addPhoto('/tmp/b.jpg'), DetailPhotoResult.locked);
    });

    test('someone who cannot create measurements is not offered photos', () async {
      final TestApp viewer = await photoApp(canCreate: false);
      addTearDown(viewer.dispose);
      final String uuid = await viewer.services.measurements.create(entry());

      final MeasurementDetailCubit cubit = MeasurementDetailCubit(services: viewer.services, clientUuid: uuid)..start();
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.entry != null);

      expect(cubit.state.canAddPhotos, isFalse);
      expect(await cubit.addPhoto('/tmp/a.jpg'), DetailPhotoResult.locked);
    });
  });

  group('the entry form', () {
    late TestApp app;

    setUp(() async => app = await photoApp());
    tearDown(() => app.dispose());

    Future<MeasurementFormCubit> readyForm({String? uuid}) async {
      final MeasurementFormCubit cubit = MeasurementFormCubit(services: app.services, clientUuid: uuid)..start();
      addTearDown(cubit.close);
      if (uuid == null) await cubit.stream.firstWhere((s) => s.projectId != null);
      return cubit;
    }

    test('photos chosen with the details are kept with the row, and the next row starts with none', () async {
      final MeasurementFormCubit cubit = await readyForm();
      final BoqLine line = (await app.services.projects.watchBoqLine(1).first)!;
      cubit
        ..selectLine(line)
        ..typed('2')
        ..setDetails(location: 'Grid C', description: '', measuredOn: '2026-11-09', photos: <String>['/tmp/a.jpg']);
      expect(cubit.state.isDirty, isTrue);

      expect(await cubit.save(), FormResult.saved);

      expect((await outbox(app)).map((r) => r.type), <OutboxType>[OutboxType.measurement, OutboxType.measurementPhoto]);
      expect(cubit.state.photos, isEmpty);
    });

    test('a photo alone is not thrown away without asking', () async {
      final MeasurementFormCubit cubit = await readyForm();
      cubit.setDetails(location: '', description: '', measuredOn: '2026-11-09', photos: <String>['/tmp/a.jpg']);
      expect(cubit.state.isDirty, isTrue);
    });

    test('no more than the phone should hold', () async {
      final MeasurementFormCubit cubit = await readyForm();
      cubit.setDetails(
        location: '',
        description: '',
        measuredOn: '2026-11-09',
        photos: <String>[for (var i = 0; i < 14; i++) '/tmp/$i.jpg'],
      );
      expect(cubit.state.photos, hasLength(RecordPhotos.maxPerKind));
    });

    test('correcting a saved row can add a photo too', () async {
      final (String uuid, _) = await sentEntry(app);
      final MeasurementFormCubit cubit = await readyForm(uuid: uuid);
      if (cubit.state.loading || cubit.state.line == null) {
        await cubit.stream.firstWhere((s) => !s.loading && s.line != null);
      }
      cubit.setDetails(location: 'Grid D', description: '', measuredOn: '2026-11-09', photos: <String>['/tmp/a.jpg']);

      expect(await cubit.save(), FormResult.saved);

      final List<OutboxRow> open = (await outbox(app)).where((r) => r.status != OutboxStatus.done).toList();
      expect(open.map((r) => r.type), <OutboxType>[OutboxType.measurement, OutboxType.measurementPhoto]);
    });
  });

  group('screens', () {
    Future<void> settleSheet(WidgetTester tester) async {
      await pumpDrift(tester, 10);
      await tester.pump(const Duration(milliseconds: 600));
      await pumpDrift(tester, 10);
    }

    testWidgets('a draft shows its photos and the way to add one', (tester) async {
      final TestApp app = await photoApp();
      addTearDown(app.dispose);
      final (String uuid, int id) = await sentEntry(app);
      app.api.fieldPhotoRows['/measurements/$id/attachments'] = <Json>[
        <String, dynamic>{'id': 4, 'measurement_entry_id': id, 'kind': 'photo', 'caption': 'Slab edge', 'is_image': true},
        <String, dynamic>{'id': 5, 'measurement_entry_id': id, 'kind': 'sketch', 'caption': null, 'is_image': false},
      ];
      await app.services.measurements.refreshPhotos(uuid);
      await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg']);

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settleSheet(tester);

      await tester.ensureVisible(find.text('Add photo'));
      expect(find.text('Photos'), findsOneWidget);
      expect(find.text('Slab edge'), findsOneWidget);
      expect(find.text('Waiting to send'), findsOneWidget);
      expect(find.text('PDF, on the website'), findsOneWidget);
      expect(find.text('Add photo'), findsOneWidget);
      expect(find.byType(RecordPhotoTile), findsNWidgets(3));
      expect(tester.takeException(), isNull);
    });

    testWidgets('an approved entry keeps its photos in view but cannot take more, and says why', (tester) async {
      final TestApp app = await photoApp();
      addTearDown(app.dispose);
      final (String uuid, int id) = await sentEntry(app);
      app.api.fieldPhotoRows['/measurements/$id/attachments'] = <Json>[
        <String, dynamic>{'id': 4, 'measurement_entry_id': id, 'kind': 'photo', 'caption': 'Slab edge', 'is_image': true},
      ];
      app.api.setServerStatus(app.api.measurementByUuid(uuid), 'APPROVED');
      await app.sync();
      await app.services.measurements.refreshPhotos(uuid);

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settleSheet(tester);

      expect(find.text('Slab edge'), findsOneWidget);
      expect(find.text('Add photo'), findsNothing);
      expect(find.text('Photos cannot be added once the entry is with the approver.'), findsOneWidget);
    });

    testWidgets('someone who cannot create measurements sees no photos card on an empty entry', (tester) async {
      final TestApp app = await photoApp(canCreate: false);
      addTearDown(app.dispose);
      final String uuid = await app.services.measurements.create(entry());

      await pumpBook(tester, app, start: Routes.measurementDetail(uuid));
      await settleSheet(tester);

      expect(find.text('Photos'), findsNothing);
      expect(find.text('Add photo'), findsNothing);
    });

    testWidgets('the details sheet on the form has a photos row', (tester) async {
      final TestApp app = await photoApp();
      addTearDown(app.dispose);
      await pumpBook(tester, app, start: Routes.measurementForm);
      await settleSheet(tester);

      await tester.tap(find.text('Location, date'));
      await settleSheet(tester);

      expect(find.text('Photos'), findsOneWidget);
      expect(find.text('Add photo'), findsOneWidget);
      expect(find.byKey(const ValueKey<String>('measurement-form-photos')), findsOneWidget);
    });

    for (final String locale in <String>['mr', 'hi']) {
      testWidgets('the detail and the details sheet fit a small phone in $locale', (tester) async {
        final TestApp app = await photoApp();
        addTearDown(app.dispose);
        final (String uuid, int id) = await sentEntry(app);
        app.api.fieldPhotoRows['/measurements/$id/attachments'] = <Json>[
          for (var n = 1; n <= 4; n++)
            <String, dynamic>{'id': n, 'measurement_entry_id': id, 'kind': 'photo', 'caption': 'Slab edge near the column', 'is_image': true},
        ];
        await app.services.measurements.refreshPhotos(uuid);
        await app.services.measurements.addPhotos(uuid, <String>['/tmp/a.jpg', '/tmp/b.jpg']);
        final AppLocalizations l = await AppLocalizations.delegate.load(Locale(locale));

        await pumpBook(tester, app, start: Routes.measurementDetail(uuid), locale: locale);
        await settleSheet(tester);
        await tester.ensureVisible(find.text(l.measurementPhotoAdd));
        expect(tester.takeException(), isNull, reason: 'detail');
        expect(find.text(l.measurementPhotoAdd), findsOneWidget);
        expect(find.text(l.photosWaiting), findsNWidgets(2));

        await pumpBook(tester, app, start: Routes.measurementForm, locale: locale);
        await settleSheet(tester);
        await tester.tap(find.text(l.measurementDetailsShort));
        await settleSheet(tester);
        expect(tester.takeException(), isNull, reason: 'sheet');
        expect(find.text(l.measurementPhotoAdd), findsOneWidget);
      });
    }
  });
}
