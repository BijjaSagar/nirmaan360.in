import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/record_photos.dart';
import '../../../core/sync/record_sync.dart';

export '../../../core/db/app_database.dart' show IssueRow;
export '../../../core/sync/record_photos.dart' show RecordPhoto, ServerPhoto, IssuePhotoKind, RecordPhotos;
export '../../../core/sync/record_sync.dart' show SyncedRecord, LocalSyncState;

class IssueRepository {
  IssueRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// Records a new issue and queues it, with its before photos behind it.
  /// Returns its uuid.
  Future<String> raise({
    required int projectId,
    required String title,
    String? description,
    String priority = 'medium',
    String? locationNote,
    int? assignedTo,
    String? dueDate,
    int? siteId,
    List<String> photoPaths = const <String>[],
  }) async {
    if (title.trim().isEmpty) {
      throw const ValidationFailure(
        'A title is required.',
        errors: {
          'title': ['A title is required.'],
        },
      );
    }
    final staged = await _ctx.photos.stage(photoPaths.take(RecordPhotos.maxPerKind).toList());
    return _db.transaction(() async {
      final uuid = newUuid();
      final stamp = _ctx.nextStamp(const []);
      await _db.into(_db.issues).insert(
        IssuesCompanion.insert(
          clientUuid: uuid,
          projectId: projectId,
          title: title,
          siteId: Value(siteId),
          description: Value(description),
          priority: Value(priority),
          locationNote: Value(locationNote),
          assignedTo: Value(assignedTo),
          raisedBy: Value(_ctx.currentUserId),
          dueDate: Value(dueDate),
          clientUpdatedAt: Value(stamp),
        ),
      );
      await _ctx.outbox.enqueue(
        type: OutboxType.issue,
        wireUuid: uuid,
        recordUuid: uuid,
        groupUuid: uuid,
        projectId: projectId,
        data: {
          'title': title,
          'description': description,
          'priority': priority,
          'location_note': locationNote,
          'assigned_to': assignedTo,
          'due_date': dueDate,
          'site_id': siteId,
        },
        clientUpdatedAt: stamp,
        createdAt: _ctx.clock.now(),
        title: title,
        coalesce: true,
      );
      await _ctx.photos.queue(
        PhotoOwner.issue,
        staged,
        parentUuid: uuid,
        projectId: projectId,
        parentKnownToServer: false,
        kind: IssuePhotoKind.before,
        title: title,
      );
      return uuid;
    });
  }

  /// The issue's photos: the server's, then those waiting to be sent.
  Stream<List<RecordPhoto>> watchPhotos(String clientUuid) => _ctx.photos.watch(PhotoOwner.issue, clientUuid);

  /// Reads the server's list of photos again. Throws when it cannot be reached.
  Future<void> refreshPhotos(String clientUuid) => _ctx.photos.refresh(PhotoOwner.issue, clientUuid);

  /// Queues photos taken for an issue that is already on the phone.
  Future<void> addPhotos(String clientUuid, List<String> paths, {required String kind}) =>
      _ctx.photos.add(PhotoOwner.issue, clientUuid, paths, kind: kind);

  Future<Uint8List> photoBytes(ServerPhoto photo) => _ctx.photos.bytes(PhotoOwner.issue, photo);

  /// Sets the issue's status and queues the change.
  Future<void> changeStatus(String clientUuid, String status) {
    return _db.transaction(() async {
      final issue =
          await (_db.select(_db.issues)
                ..where((t) => t.clientUuid.equals(clientUuid)))
              .getSingleOrNull();
      if (issue == null) {
        throw RecordLocked('Issue $clientUuid was not found.');
      }
      final stamp = _ctx.nextStamp([issue.clientUpdatedAt, issue.updatedAt]);
      final isResolved = status == 'resolved' || status == 'closed';
      await (_db.update(_db.issues)
            ..where((t) => t.clientUuid.equals(clientUuid)))
          .write(
            IssuesCompanion(
              status: Value(status),
              resolvedAt: Value(
                isResolved ? (issue.resolvedAt ?? _ctx.clock.now()) : null,
              ),
              clientUpdatedAt: Value(stamp),
            ),
          );
      await _ctx.outbox.enqueue(
        type: OutboxType.issueStatus,
        wireUuid: newUuid(),
        recordUuid: clientUuid,
        groupUuid: clientUuid,
        projectId: issue.projectId,
        data: {'issue_client_uuid': clientUuid, 'status': status},
        clientUpdatedAt: stamp,
        createdAt: _ctx.clock.now(),
        title: issue.title,
        subtitle: status,
        coalesce: true,
      );
    });
  }

  /// By project, optionally one [status], newest first.
  Stream<List<SyncedRecord<IssueRow>>> watchByProject(
    int projectId, {
    String? status,
  }) {
    return _db.watchDerived({_db.issues, _db.outbox}, () async {
      final rows =
          await (_db.select(_db.issues)
                ..where((t) {
                  var condition = t.projectId.equals(projectId);
                  if (status != null) {
                    condition = condition & t.status.equals(status);
                  }
                  return condition;
                })
                ..orderBy([
                  (t) => OrderingTerm.desc(t.clientUpdatedAt),
                  (t) => OrderingTerm.desc(t.updatedAt),
                ]))
              .get();
      final states = await SyncStates.load(_db);
      return [for (final row in rows) states.wrap(row, row.clientUuid)];
    });
  }

  Stream<SyncedRecord<IssueRow>?> watchOne(String clientUuid) {
    return _db.watchDerived({_db.issues, _db.outbox}, () async {
      final row =
          await (_db.select(_db.issues)
                ..where((t) => t.clientUuid.equals(clientUuid)))
              .getSingleOrNull();
      if (row == null) return null;
      final states = await SyncStates.load(_db);
      return states.wrap(row, row.clientUuid);
    });
  }
}
