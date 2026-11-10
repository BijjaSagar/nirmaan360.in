import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/failures.dart';
import '../../../core/sync/ids.dart';
import '../../../core/sync/record_photos.dart';
import '../../../core/sync/record_sync.dart';

export '../../../core/db/app_database.dart' show MeasurementRow, DocStatus;
export '../../../core/sync/record_photos.dart' show RecordPhoto, ServerPhoto, MeasurementPhotoKind, RecordPhotos;
export '../../../core/sync/record_sync.dart' show SyncedRecord, LocalSyncState;

/// The fields of a measurement entry form.
class MeasurementInput {
  const MeasurementInput({
    required this.projectId,
    required this.boqItemId,
    required this.measuredOn,
    required this.nos,
    this.description,
    this.location,
    this.length,
    this.breadth,
    this.depth,
    this.isDeduction = false,
    this.remarks,
    this.photoPaths = const <String>[],
  });

  final int projectId;
  final int boqItemId;
  final String measuredOn;
  final String? description;
  final String? location;
  final Qty nos;
  final Qty? length;
  final Qty? breadth;
  final Qty? depth;
  final bool isDeduction;
  final String? remarks;

  /// Photos taken for this entry, copied in and queued behind it when it is saved.
  final List<String> photoPaths;

  /// Local preview, computed exactly as the server will.
  Qty get qty => computeMeasurementQty(nos, length, breadth, depth, isDeduction);
}

class MeasurementRepository {
  MeasurementRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// Saves an entry and queues it. Returns its uuid.
  ///
  /// Throws [ValidationFailure] for values the server would refuse, so the
  /// engineer can fix them now instead of seeing them rejected a week later.
  Future<String> create(MeasurementInput input) async {
    _ctx.assertCanWrite();
    _validate(input);
    final List<StagedPhoto> staged = await _stagePhotos(input);
    return _db.transaction(() async {
      final uuid = newUuid();
      final stamp = _ctx.nextStamp(const []);
      await _db
          .into(_db.measurements)
          .insert(
            MeasurementsCompanion.insert(
              clientUuid: uuid,
              projectId: input.projectId,
              boqItemId: input.boqItemId,
              measuredOn: input.measuredOn,
              description: Value(input.description),
              location: Value(input.location),
              nos: Value(input.nos),
              length: Value(input.length),
              breadth: Value(input.breadth),
              depth: Value(input.depth),
              isDeduction: Value(input.isDeduction),
              qty: input.qty,
              remarks: Value(input.remarks),
              createdBy: Value(_ctx.currentUserId),
              clientUpdatedAt: Value(stamp),
            ),
          );
      await _enqueueEntry(uuid, input, stamp);
      await _queuePhotos(staged, uuid, input, serverKnows: false);
      return uuid;
    });
  }

  /// Edits a DRAFT or RETURNED entry and queues the change.
  Future<void> edit(String clientUuid, MeasurementInput input) async {
    _ctx.assertCanWrite();
    _validate(input);
    final List<StagedPhoto> staged = await _stagePhotos(input);
    return _db.transaction(() async {
      final entry = await _require(clientUuid);
      await _assertEditable(entry);
      final stamp = _ctx.nextStamp([entry.clientUpdatedAt, entry.updatedAt]);
      await (_db.update(_db.measurements)..where((t) => t.clientUuid.equals(clientUuid))).write(
        MeasurementsCompanion(
          boqItemId: Value(input.boqItemId),
          measuredOn: Value(input.measuredOn),
          description: Value(input.description),
          location: Value(input.location),
          nos: Value(input.nos),
          length: Value(input.length),
          breadth: Value(input.breadth),
          depth: Value(input.depth),
          isDeduction: Value(input.isDeduction),
          qty: Value(input.qty),
          remarks: Value(input.remarks),
          clientUpdatedAt: Value(stamp),
        ),
      );
      await _enqueueEntry(clientUuid, input, stamp);
      await _queuePhotos(staged, clientUuid, input, serverKnows: entry.serverId != null);
    });
  }

  /// The entry's photos: the server's, then those waiting to be sent.
  Stream<List<RecordPhoto>> watchPhotos(String clientUuid) => _ctx.photos.watch(PhotoOwner.measurement, clientUuid);

  /// Reads the server's list again. Throws when it cannot be reached.
  Future<void> refreshPhotos(String clientUuid) => _ctx.photos.refresh(PhotoOwner.measurement, clientUuid);

  /// Queues photos for an entry already on the phone. Throws [RecordLocked]
  /// once the entry is with the approver, approved, or waiting to be sent.
  Future<void> addPhotos(String clientUuid, List<String> paths) {
    _ctx.assertCanWrite();
    return _ctx.photos.add(PhotoOwner.measurement, clientUuid, paths, kind: MeasurementPhotoKind.photo);
  }

  Future<Uint8List> photoBytes(ServerPhoto photo) => _ctx.photos.bytes(PhotoOwner.measurement, photo);

  Future<List<StagedPhoto>> _stagePhotos(MeasurementInput input) =>
      _ctx.photos.stage(input.photoPaths.take(RecordPhotos.maxPerKind).toList());

  Future<void> _queuePhotos(
    List<StagedPhoto> staged,
    String uuid,
    MeasurementInput input, {
    required bool serverKnows,
  }) {
    return _ctx.photos.queue(
      PhotoOwner.measurement,
      staged,
      parentUuid: uuid,
      projectId: input.projectId,
      parentKnownToServer: serverKnows,
      kind: MeasurementPhotoKind.photo,
      title: input.qty.format(),
    );
  }

  /// Queues the submission. A second call while one is waiting does nothing.
  Future<void> sendForApproval(String clientUuid) {
    _ctx.assertCanWrite();
    return _db.transaction(() async {
      final entry = await _require(clientUuid);
      if (await _hasUnsentSubmit(clientUuid)) return;
      await _assertEditable(entry);
      await _ctx.outbox.enqueue(
        type: OutboxType.measurementSubmit,
        wireUuid: newUuid(),
        recordUuid: clientUuid,
        groupUuid: clientUuid,
        projectId: entry.projectId,
        data: {'entry_client_uuid': clientUuid},
        clientUpdatedAt: entry.clientUpdatedAt,
        createdAt: _ctx.clock.now(),
        title: entry.qty.format(),
      );
    });
  }

  /// By project, optionally one [status], newest first.
  Stream<List<SyncedRecord<MeasurementRow>>> watchByProject(int projectId, {String? status}) {
    return _db.watchDerived({_db.measurements, _db.outbox}, () async {
      final rows =
          await (_db.select(_db.measurements)
                ..where((t) {
                  var condition = t.projectId.equals(projectId);
                  if (status != null) {
                    condition = condition & t.status.equals(status);
                  }
                  return condition;
                })
                ..orderBy([(t) => OrderingTerm.desc(t.measuredOn), (t) => OrderingTerm.desc(t.clientUpdatedAt)]))
              .get();
      final states = await SyncStates.load(_db);
      return [for (final row in rows) states.wrap(row, row.clientUuid)];
    });
  }

  Stream<SyncedRecord<MeasurementRow>?> watchOne(String clientUuid) {
    return _db.watchDerived({_db.measurements, _db.outbox}, () async {
      final row = await _find(clientUuid);
      if (row == null) return null;
      final states = await SyncStates.load(_db);
      return states.wrap(row, row.clientUuid);
    });
  }

  void _validate(MeasurementInput input) {
    _ctx.assertNotFuture(input.measuredOn, 'measured_on');
    final dimensions = {'nos': input.nos, 'length': input.length, 'breadth': input.breadth, 'depth': input.depth};
    dimensions.forEach((field, value) {
      if (value != null && value <= Qty.zero) {
        throw ValidationFailure(
          'Must be more than zero.',
          errors: {
            field: ['Must be more than zero.'],
          },
        );
      }
    });
  }

  Future<void> _enqueueEntry(String uuid, MeasurementInput input, DateTime stamp) {
    return _ctx.outbox.enqueue(
      type: OutboxType.measurement,
      wireUuid: uuid,
      recordUuid: uuid,
      groupUuid: uuid,
      projectId: input.projectId,
      data: {
        'boq_item_id': input.boqItemId,
        'measured_on': input.measuredOn,
        'description': input.description,
        'location': input.location,
        'nos': input.nos.toWire(),
        'length': input.length?.toWire(),
        'breadth': input.breadth?.toWire(),
        'depth': input.depth?.toWire(),
        'is_deduction': input.isDeduction,
        'remarks': input.remarks,
      },
      clientUpdatedAt: stamp,
      createdAt: _ctx.clock.now(),
      title: input.qty.format(),
      subtitle: input.location,
      coalesce: true,
    );
  }

  Future<MeasurementRow?> _find(String clientUuid) {
    return (_db.select(_db.measurements)..where((t) => t.clientUuid.equals(clientUuid))).getSingleOrNull();
  }

  Future<MeasurementRow> _require(String clientUuid) async {
    final row = await _find(clientUuid);
    if (row == null) throw RecordLocked('Entry $clientUuid was not found.');
    return row;
  }

  Future<bool> _hasUnsentSubmit(String clientUuid) async {
    final rows =
        await (_db.select(_db.outbox)..where(
              (t) =>
                  t.recordUuid.equals(clientUuid) &
                  t.type.equals(OutboxType.measurementSubmit.name) &
                  t.status.equals(OutboxStatus.done.name).not(),
            ))
            .get();
    return rows.isNotEmpty;
  }

  Future<void> _assertEditable(MeasurementRow entry) async {
    if (entry.status != DocStatus.draft && entry.status != DocStatus.returned) {
      throw RecordLocked('This entry is ${entry.status} and cannot be changed.');
    }
    if (await _hasUnsentSubmit(entry.clientUuid)) {
      throw const RecordLocked('This entry has already been sent for approval.');
    }
  }
}
