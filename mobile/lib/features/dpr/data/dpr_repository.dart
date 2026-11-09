import 'dart:io';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/db/app_database.dart';
import '../../../core/sync/clock.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/record_sync.dart';

export '../../../core/db/app_database.dart'
    show DprRow, DprPhotoRow, ManpowerLine, MachineryLine, WorkLine, DocStatus;
export '../../../core/sync/record_sync.dart' show SyncedRecord, LocalSyncState;

/// The state of the DPR form. Autosave passes the whole form each time.
class DprInput {
  const DprInput({
    required this.projectId,
    required this.reportDate,
    this.siteId,
    this.weather,
    this.summary,
    this.hindrances,
    this.manpower = const [],
    this.machinery = const [],
    this.workItems = const [],
  });

  final int projectId;
  final String reportDate;
  final int? siteId;
  final String? weather;
  final String? summary;
  final String? hindrances;
  final List<ManpowerLine> manpower;
  final List<MachineryLine> machinery;
  final List<WorkLine> workItems;
}

/// One DPR with its photos and sync badge.
class DprDetails {
  const DprDetails({required this.record, required this.photos});

  final SyncedRecord<DprRow> record;
  final List<DprPhotoRow> photos;

  DprRow get dpr => record.record;
}

class DprRepository {
  DprRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// Saves the form locally. Survives an app kill, adds nothing to the
  /// outbox, and a pull will not overwrite it. Returns the draft's uuid.
  ///
  /// Without [clientUuid] the open draft for that project and date is reused
  /// or created. Pass [clientUuid] to edit one specific report.
  Future<String> saveDraft(DprInput input, {String? clientUuid}) {
    return _db.transaction(() async {
      final existing = clientUuid != null
          ? await _find(clientUuid)
          : await _openDraftFor(input.projectId, input.reportDate);

      if (existing != null) {
        await _assertEditable(existing);
        await (_db.update(_db.dprs)
              ..where((t) => t.clientUuid.equals(existing.clientUuid)))
            .write(_companionFrom(input, localDraft: true));
        return existing.clientUuid;
      }

      final uuid = clientUuid ?? newUuid();
      await _db.into(_db.dprs).insert(
        _companionFrom(input, localDraft: true).copyWith(
          clientUuid: Value(uuid),
          createdBy: Value(_ctx.currentUserId),
          createdByName: Value(_ctx.session.user?.name),
        ),
      );
      return uuid;
    });
  }

  /// Edits a RETURNED report. Call [send] when the engineer is done.
  Future<void> editReturned(String clientUuid, DprInput input) async {
    final dpr = await _require(clientUuid);
    if (dpr.status != DocStatus.returned) {
      throw RecordLocked('Only a returned report can be edited (${dpr.status}).');
    }
    await saveDraft(input, clientUuid: clientUuid);
  }

  /// Copies yesterday's manpower into this date's draft. Returns the copied
  /// lines, empty when yesterday has no report.
  Future<List<ManpowerLine>> copyManpowerFromYesterday(
    int projectId,
    String reportDate,
  ) {
    return _db.transaction(() async {
      final yesterday = _previousDate(reportDate);
      final previous =
          await (_db.select(_db.dprs)
                ..where(
                  (t) =>
                      t.projectId.equals(projectId) &
                      t.reportDate.equals(yesterday),
                )
                ..orderBy([(t) => OrderingTerm.desc(t.updatedAt)])
                ..limit(1))
              .getSingleOrNull();
      if (previous == null || previous.manpower.isEmpty) return const [];

      final draft = await _openDraftFor(projectId, reportDate);
      if (draft != null) {
        await _assertEditable(draft);
        await (_db.update(_db.dprs)
              ..where((t) => t.clientUuid.equals(draft.clientUuid)))
            .write(
              DprsCompanion(
                manpower: Value(previous.manpower),
                localDraft: const Value(true),
              ),
            );
      } else {
        await _db.into(_db.dprs).insert(
          _companionFrom(
            DprInput(
              projectId: projectId,
              reportDate: reportDate,
              manpower: previous.manpower,
            ),
            localDraft: true,
          ).copyWith(
            clientUuid: Value(newUuid()),
            createdBy: Value(_ctx.currentUserId),
            createdByName: Value(_ctx.session.user?.name),
          ),
        );
      }
      return previous.manpower;
    });
  }

  /// Copies the photo into app storage and attaches it to the report.
  /// The file is complete before any row refers to it.
  Future<String> addPhoto({
    required String dprClientUuid,
    required String sourcePath,
    String? caption,
    DateTime? takenAt,
  }) async {
    final dpr = await _require(dprClientUuid);
    await _assertEditable(dpr);

    final uuid = newUuid();
    final extension = p.extension(sourcePath);
    final relativePath = await _ctx.attachments.importFile(
      sourcePath,
      folder: 'dpr_photos',
      fileName: '$uuid${extension.isEmpty ? '.jpg' : extension}',
    );

    final now = _ctx.clock.now();
    await _db.into(_db.dprPhotos).insert(
      DprPhotosCompanion.insert(
        clientUuid: uuid,
        dprClientUuid: dprClientUuid,
        relativePath: Value(relativePath),
        caption: Value(caption),
        takenAt: Value(takenAt ?? now),
        createdAt: Value(now),
      ),
    );
    return uuid;
  }

  /// Removes a photo that has not been queued for upload.
  ///
  /// The stored file is deliberately left in place: a file is only ever
  /// deleted after its upload has succeeded.
  Future<void> removePhoto(String photoUuid) {
    return _db.transaction(() async {
      final photo =
          await (_db.select(_db.dprPhotos)
                ..where((t) => t.clientUuid.equals(photoUuid)))
              .getSingleOrNull();
      if (photo == null) return;
      if (photo.serverId != null || await _ctx.outbox.hasUnsent(photoUuid)) {
        throw const RecordLocked('This photo has already been sent.');
      }
      await (_db.delete(_db.dprPhotos)
            ..where((t) => t.clientUuid.equals(photoUuid)))
          .go();
    });
  }

  /// Queues the report, then each new photo, then the submission, in one
  /// transaction. A second call while it is waiting does nothing.
  Future<void> send(String clientUuid) {
    return _db.transaction(() async {
      final dpr = await _require(clientUuid);
      if (await _hasUnsentSubmit(clientUuid)) return;
      await _assertEditable(dpr);
      _ctx.assertNotFuture(dpr.reportDate, 'report_date');

      final now = _ctx.clock.now();
      final stamp = _ctx.nextStamp([dpr.clientUpdatedAt, dpr.updatedAt]);

      await (_db.update(_db.dprs)
            ..where((t) => t.clientUuid.equals(clientUuid)))
          .write(
            DprsCompanion(
              localDraft: const Value(false),
              clientUpdatedAt: Value(stamp),
            ),
          );

      await _ctx.outbox.enqueue(
        type: OutboxType.dpr,
        wireUuid: clientUuid,
        recordUuid: clientUuid,
        groupUuid: clientUuid,
        projectId: dpr.projectId,
        data: _wirePayload(dpr),
        clientUpdatedAt: stamp,
        createdAt: now,
        title: dpr.reportDate,
        coalesce: true,
      );

      final photos =
          await (_db.select(_db.dprPhotos)
                ..where(
                  (t) =>
                      t.dprClientUuid.equals(clientUuid) &
                      t.serverId.isNull() &
                      t.relativePath.isNotNull(),
                ))
              .get();
      for (final photo in photos) {
        if (await _ctx.outbox.hasUnsent(photo.clientUuid)) continue;
        await _ctx.outbox.enqueue(
          type: OutboxType.dprPhoto,
          wireUuid: photo.clientUuid,
          recordUuid: photo.clientUuid,
          groupUuid: clientUuid,
          projectId: dpr.projectId,
          data: {
            'caption': photo.caption,
            'taken_at': photo.takenAt == null
                ? null
                : toWireTimestamp(photo.takenAt!),
          },
          filePath: photo.relativePath,
          parentUuid: clientUuid,
          createdAt: now,
          title: dpr.reportDate,
          subtitle: photo.caption,
        );
      }

      await _ctx.outbox.enqueue(
        type: OutboxType.dprSubmit,
        wireUuid: newUuid(),
        recordUuid: clientUuid,
        groupUuid: clientUuid,
        projectId: dpr.projectId,
        data: {'dpr_client_uuid': clientUuid},
        clientUpdatedAt: stamp,
        createdAt: now,
        title: dpr.reportDate,
      );
    });
  }

  /// Newest first.
  Stream<List<SyncedRecord<DprRow>>> watchList(int projectId) {
    return _db.watchDerived({_db.dprs, _db.outbox}, () async {
      final rows =
          await (_db.select(_db.dprs)
                ..where((t) => t.projectId.equals(projectId))
                ..orderBy([
                  (t) => OrderingTerm.desc(t.reportDate),
                  (t) => OrderingTerm.desc(t.updatedAt),
                ]))
              .get();
      final states = await SyncStates.load(_db);
      return [for (final row in rows) states.wrap(row, row.clientUuid)];
    });
  }

  Stream<DprDetails?> watchOne(String clientUuid) {
    return _db.watchDerived({_db.dprs, _db.dprPhotos, _db.outbox}, () async {
      final dpr = await _find(clientUuid);
      if (dpr == null) return null;
      return _details(dpr);
    });
  }

  /// The report for a date, for the screen that opens today's DPR.
  Stream<DprDetails?> watchForDate(int projectId, String reportDate) {
    return _db.watchDerived({_db.dprs, _db.dprPhotos, _db.outbox}, () async {
      final dpr = await _openDraftFor(projectId, reportDate);
      if (dpr != null) return _details(dpr);
      final rows =
          await (_db.select(_db.dprs)
                ..where(
                  (t) =>
                      t.projectId.equals(projectId) &
                      t.reportDate.equals(reportDate),
                )
                ..orderBy([(t) => OrderingTerm.desc(t.updatedAt)])
                ..limit(1))
              .get();
      return rows.isEmpty ? null : _details(rows.first);
    });
  }

  /// Bytes of a photo: the local copy when it is still on this phone,
  /// otherwise from the server.
  Future<Uint8List> photoBytes(DprPhotoRow photo) async {
    final relative = photo.relativePath;
    if (relative != null && await _ctx.attachments.exists(relative)) {
      return File(await _ctx.attachments.absolutePath(relative)).readAsBytes();
    }
    final serverId = photo.serverId;
    if (serverId == null) {
      throw const RecordLocked('This photo is not available yet.');
    }
    return _ctx.api.dprPhotoBytes(serverId);
  }

  Future<DprDetails> _details(DprRow dpr) async {
    final photos =
        await (_db.select(_db.dprPhotos)
              ..where((t) => t.dprClientUuid.equals(dpr.clientUuid))
              ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
            .get();
    final states = await SyncStates.load(_db);
    return DprDetails(
      record: states.wrap(dpr, dpr.clientUuid),
      photos: photos,
    );
  }

  Future<DprRow?> _find(String clientUuid) {
    return (_db.select(_db.dprs)
          ..where((t) => t.clientUuid.equals(clientUuid)))
        .getSingleOrNull();
  }

  Future<DprRow> _require(String clientUuid) async {
    final dpr = await _find(clientUuid);
    if (dpr == null) throw RecordLocked('Report $clientUuid was not found.');
    return dpr;
  }

  /// A draft or returned report still open for editing on this date.
  Future<DprRow?> _openDraftFor(int projectId, String reportDate) async {
    final rows =
        await (_db.select(_db.dprs)
              ..where(
                (t) =>
                    t.projectId.equals(projectId) &
                    t.reportDate.equals(reportDate) &
                    t.status.isIn([DocStatus.draft, DocStatus.returned]),
              )
              ..orderBy([
                (t) => OrderingTerm.desc(t.localDraft),
                (t) => OrderingTerm.desc(t.updatedAt),
              ])
              ..limit(1))
            .get();
    return rows.isEmpty ? null : rows.first;
  }

  Future<bool> _hasUnsentSubmit(String clientUuid) async {
    final rows =
        await (_db.select(_db.outbox)
              ..where(
                (t) =>
                    t.recordUuid.equals(clientUuid) &
                    t.type.equals(OutboxType.dprSubmit.name) &
                    t.status.equals(OutboxStatus.done.name).not(),
              ))
            .get();
    return rows.isNotEmpty;
  }

  Future<void> _assertEditable(DprRow dpr) async {
    final locked =
        dpr.status == DocStatus.inReview ||
        dpr.status == DocStatus.approved ||
        dpr.status == DocStatus.rejected;
    if (locked) {
      throw RecordLocked('This report is ${dpr.status} and cannot be changed.');
    }
    if (await _hasUnsentSubmit(dpr.clientUuid)) {
      throw const RecordLocked('This report has already been sent.');
    }
  }

  DprsCompanion _companionFrom(DprInput input, {required bool localDraft}) {
    return DprsCompanion(
      projectId: Value(input.projectId),
      siteId: Value(input.siteId),
      reportDate: Value(input.reportDate),
      weather: Value(input.weather),
      summary: Value(input.summary),
      hindrances: Value(input.hindrances),
      manpower: Value(input.manpower),
      machinery: Value(input.machinery),
      workItems: Value(input.workItems),
      localDraft: Value(localDraft),
    );
  }

  Map<String, dynamic> _wirePayload(DprRow dpr) => {
    'site_id': dpr.siteId,
    'report_date': dpr.reportDate,
    'weather': dpr.weather,
    'summary': dpr.summary,
    'hindrances': dpr.hindrances,
    'manpower': dpr.manpower.map((e) => e.toJson()).toList(),
    'machinery': dpr.machinery.map((e) => e.toJson()).toList(),
    'work': dpr.workItems.map((e) => e.toJson()).toList(),
  };

  String _previousDate(String date) {
    final parts = date.split('-').map(int.parse).toList();
    return dateString(DateTime.utc(parts[0], parts[1], parts[2] - 1));
  }
}
