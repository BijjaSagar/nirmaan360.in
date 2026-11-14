import 'dart:convert';
import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../api/nirmaan_api.dart';
import '../db/app_database.dart';
import '../db/outbox_dao.dart';
import 'attachment_store.dart';
import 'clock.dart';
import 'failures.dart';
import 'ids.dart';

/// What a photo is attached to. Each owner has its own routes on the server.
enum PhotoOwner {
  issue(
    outboxType: OutboxType.issuePhoto,
    collection: 'issues',
    listName: 'photos',
    filePath: 'issue-photos',
    parentKey: 'issue_id',
    fileField: 'photo',
  ),
  measurement(
    outboxType: OutboxType.measurementPhoto,
    collection: 'measurements',
    listName: 'attachments',
    filePath: 'measurement-attachments',
    parentKey: 'measurement_entry_id',
    fileField: 'file',
    alwaysBehindRecord: true,
  );

  const PhotoOwner({
    required this.outboxType,
    required this.collection,
    required this.listName,
    required this.filePath,
    required this.parentKey,
    required this.fileField,
    this.alwaysBehindRecord = false,
  });

  final OutboxType outboxType;
  final String collection;
  final String listName;
  final String filePath;

  /// The key in the server's reply that holds the id of the record.
  final String parentKey;

  /// The multipart name the server reads the file from.
  final String fileField;

  /// True when something else queued for the record must not overtake its
  /// photos. A measurement is sent for approval after its photos, because the
  /// server locks the photos the moment it is. An issue has no such order.
  final bool alwaysBehindRecord;

  /// `GET` lists and `POST` adds, e.g. `/issues/5/photos`.
  String listPath(int parentId) => '/$collection/$parentId/$listName';

  /// `GET` returns the file, e.g. `/issue-photos/9`.
  String photoPath(int photoId) => '/$filePath/$photoId';

  static PhotoOwner? forType(OutboxType type) {
    for (final PhotoOwner owner in values) {
      if (owner.outboxType == type) return owner;
    }
    return null;
  }
}

/// The two kinds of photo an issue takes. The server wants one of them.
abstract final class IssuePhotoKind {
  static const before = 'before';
  static const after = 'after';
  static const all = <String>[before, after];
}

/// The phone takes photos for a measurement. A sketch is a scan from the office.
abstract final class MeasurementPhotoKind {
  static const photo = 'photo';
  static const sketch = 'sketch';
}

/// A photo the server holds, as `GET /issues/{id}/photos` lists it.
class ServerPhoto {
  const ServerPhoto({required this.id, required this.kind, this.caption, this.isImage = true});

  factory ServerPhoto.fromJson(Map<String, dynamic> json) => ServerPhoto(
    id: (json['id'] as num).toInt(),
    kind: json['kind'] as String? ?? '',
    caption: json['caption'] as String?,
    isImage: json['is_image'] as bool? ?? true,
  );

  final int id;
  final String kind;
  final String? caption;

  /// False for a scanned PDF, which the phone lists but does not draw.
  final bool isImage;

  Map<String, dynamic> toJson() => <String, dynamic>{'id': id, 'kind': kind, 'caption': caption, 'is_image': isImage};
}

/// One photo on a screen: either on the server, or on this phone waiting to go.
class RecordPhoto {
  const RecordPhoto.stored(ServerPhoto this.server) : localPath = null, kind = '', caption = null, failed = false;

  const RecordPhoto.waiting({
    required String this.localPath,
    required this.kind,
    this.caption,
    this.failed = false,
  }) : server = null;

  final ServerPhoto? server;

  /// Relative path of the file on this phone, while it has not been sent.
  final String? localPath;
  final String kind;
  final String? caption;

  /// The server refused the file, so it is waiting for a person.
  final bool failed;

  bool get isWaiting => server == null;

  String get photoKind => server?.kind ?? kind;
  String? get photoCaption => server?.caption ?? caption;

  /// Stable across rebuilds, so a thumbnail keeps its picture while others change.
  String get key => server != null ? 'server-${server!.id}' : 'local-$localPath';
}

/// A file copied into app storage, ready to be queued behind its record.
class StagedPhoto {
  const StagedPhoto(this.uuid, this.relativePath);

  final String uuid;
  final String relativePath;
}

/// Photos on issues, from the server and from this phone.
///
/// The server's lists are kept as JSON per project, and each picture the phone
/// has fetched or sent is kept as a file, so a photo seen once can be seen
/// again with no network. A photo not yet sent is an outbox row, queued behind
/// the record it belongs to.
class RecordPhotos {
  RecordPhotos({
    required this._db,
    required this._api,
    required this._attachments,
    required this._clock,
  }) : _outbox = OutboxDao(_db);

  final AppDatabase _db;
  final NirmaanApi _api;
  final AttachmentStore _attachments;
  final AppClock _clock;
  final OutboxDao _outbox;

  static const String _stagingFolder = 'record_photos';
  static const String _cacheFolder = 'photo_cache';

  /// The most photos of one kind on one record, so a phone is not filled by one.
  static const int maxPerKind = 10;

  // Reading -------------------------------------------------------------------

  /// The photos of a record, the server's first and then those waiting to go.
  /// Changes whenever the record, its cached list or its queued photos change.
  Stream<List<RecordPhoto>> watch(PhotoOwner owner, String parentUuid) {
    final ResultSetImplementation<dynamic, dynamic> records = switch (owner) {
      PhotoOwner.issue => _db.issues,
      PhotoOwner.measurement => _db.measurements,
    };
    return _db.watchDerived(<ResultSetImplementation<dynamic, dynamic>>{_db.outbox, _db.keyValues, records}, () async {
      final _Parent? parent = await _parent(owner, parentUuid);
      if (parent == null) return const <RecordPhoto>[];

      final int? serverId = parent.serverId;
      final List<ServerPhoto> stored = serverId == null
          ? const <ServerPhoto>[]
          : await _cached(owner, parent.projectId, serverId);

      final List<OutboxRow> waiting =
          await (_db.select(_db.outbox)
                ..where(
                  (t) =>
                      t.type.equals(owner.outboxType.name) &
                      t.parentUuid.equals(parentUuid) &
                      t.status.equals(OutboxStatus.done.name).not(),
                )
                ..orderBy([(t) => OrderingTerm.asc(t.id)]))
              .get();

      return <RecordPhoto>[
        for (final ServerPhoto photo in stored) RecordPhoto.stored(photo),
        for (final OutboxRow row in waiting)
          if (row.filePath != null) _waitingPhoto(row),
      ];
    });
  }

  RecordPhoto _waitingPhoto(OutboxRow row) {
    final Map<String, dynamic> data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
    return RecordPhoto.waiting(
      localPath: row.filePath!,
      kind: data['kind'] as String? ?? '',
      caption: data['caption'] as String?,
      failed: row.status == OutboxStatus.attention,
    );
  }

  /// Asks the server for the record's photos and keeps the answer. Throws an
  /// `ApiFailure` when it cannot be reached, leaving the last list in place.
  /// Does nothing for a record the server has not seen yet.
  Future<void> refresh(PhotoOwner owner, String parentUuid) async {
    final _Parent? parent = await _parent(owner, parentUuid);
    final int? serverId = parent?.serverId;
    if (parent == null || serverId == null) return;
    final List<Map<String, dynamic>> rows = await _api.fieldPhotoList(owner.listPath(serverId));
    await _writeList(owner, parent.projectId, serverId, <ServerPhoto>[for (final Map<String, dynamic> row in rows) ServerPhoto.fromJson(row)]);
  }

  /// The file behind a photo: the copy on this phone, else from the server.
  Future<Uint8List> bytes(PhotoOwner owner, ServerPhoto photo) async {
    final String name = _cacheName(owner, photo.id);
    final Uint8List? kept = await _attachments.readFile(folder: _cacheFolder, fileName: name);
    if (kept != null) return kept;
    final Uint8List fetched = await _api.fieldPhotoBytes(owner.photoPath(photo.id));
    try {
      await _attachments.writeFile(fetched, folder: _cacheFolder, fileName: name);
    } on Object {
      // Keeping a copy is a convenience. Showing the picture is the job.
    }
    return fetched;
  }

  Future<String> absolutePath(String relativePath) => _attachments.absolutePath(relativePath);

  // Writing -------------------------------------------------------------------

  /// Copies the chosen files into app storage. Do this before the transaction
  /// that queues them, because file work must not hold the database.
  Future<List<StagedPhoto>> stage(List<String> sourcePaths) async {
    final List<StagedPhoto> staged = <StagedPhoto>[];
    for (final String source in sourcePaths) {
      final String uuid = newUuid();
      final String extension = p.extension(source);
      final String relative = await _attachments.importFile(
        source,
        folder: _stagingFolder,
        fileName: '$uuid${extension.isEmpty ? '.jpg' : extension}',
      );
      staged.add(StagedPhoto(uuid, relative));
    }
    return staged;
  }

  /// Queues staged photos behind a record. Call inside the transaction that
  /// writes the record, or any later one.
  ///
  /// A record the server has never seen is the head of its own queue, and its
  /// photos wait behind it. One the server already knows is posted on its own,
  /// so a stuck status change cannot hold a photo back, unless the owner needs
  /// its photos to go first: see [PhotoOwner.alwaysBehindRecord].
  Future<void> queue(
    PhotoOwner owner,
    List<StagedPhoto> staged, {
    required String parentUuid,
    required int projectId,
    required bool parentKnownToServer,
    required String kind,
    required String title,
  }) async {
    final DateTime now = _clock.now();
    final bool behindRecord = !parentKnownToServer || owner.alwaysBehindRecord;
    for (final StagedPhoto photo in staged) {
      await _outbox.enqueue(
        type: owner.outboxType,
        wireUuid: photo.uuid,
        recordUuid: photo.uuid,
        groupUuid: behindRecord ? parentUuid : photo.uuid,
        projectId: projectId,
        data: <String, dynamic>{'kind': kind, 'taken_at': toWireTimestamp(now)},
        filePath: photo.relativePath,
        parentUuid: parentUuid,
        createdAt: now,
        title: title,
        subtitle: kind,
      );
    }
  }

  /// Adds photos to a record already on the phone.
  Future<void> add(PhotoOwner owner, String parentUuid, List<String> sourcePaths, {required String kind}) async {
    final _Parent? parent = await _parent(owner, parentUuid);
    if (parent == null) throw RecordLocked('The record $parentUuid was not found.');
    if (owner == PhotoOwner.measurement) await _assertMeasurementOpen(parent, parentUuid);
    final List<StagedPhoto> staged = await stage(sourcePaths);
    await _db.transaction(() async {
      await queue(
        owner,
        staged,
        parentUuid: parentUuid,
        projectId: parent.projectId,
        parentKnownToServer: parent.serverId != null,
        kind: kind,
        title: parent.title,
      );
    });
  }

  /// For the sync engine, once the server has stored a photo: lists it and
  /// keeps the file the phone already has, so it never has to be fetched back.
  /// Call in the transaction that finishes the outbox row, so the photo is never
  /// missing from the screen and never there twice.
  Future<void> remember(PhotoOwner owner, int projectId, Map<String, dynamic> reply, {String? localRelativePath}) async {
    final int? parentId = (reply[owner.parentKey] as num?)?.toInt();
    if (parentId == null || reply['id'] is! num) return;
    final ServerPhoto photo = ServerPhoto.fromJson(reply);

    if (localRelativePath != null) {
      try {
        await _attachments.importFile(
          await _attachments.absolutePath(localRelativePath),
          folder: _cacheFolder,
          fileName: _cacheName(owner, photo.id),
        );
      } on Object {
        // The picture can still be fetched later.
      }
    }

    final List<ServerPhoto> list = await _cached(owner, projectId, parentId);
    if (list.any((ServerPhoto known) => known.id == photo.id)) return;
    await _writeList(owner, projectId, parentId, <ServerPhoto>[...list, photo]);
  }

  // Cache ---------------------------------------------------------------------

  String _cacheName(PhotoOwner owner, int photoId) => '${owner.name}_$photoId.jpg';

  String _listKey(PhotoOwner owner, int parentId) => '${owner.name}:$parentId';

  Future<Map<String, dynamic>> _readAll(int projectId) async {
    final String? raw = await _db.readMeta(MetaKey.recordPhotos(projectId));
    return raw == null ? <String, dynamic>{} : (jsonDecode(raw) as Map).cast<String, dynamic>();
  }

  Future<List<ServerPhoto>> _cached(PhotoOwner owner, int projectId, int parentId) async {
    final Object? list = (await _readAll(projectId))[_listKey(owner, parentId)];
    if (list is! List) return const <ServerPhoto>[];
    return <ServerPhoto>[for (final Object? row in list) ServerPhoto.fromJson((row as Map).cast<String, dynamic>())];
  }

  Future<void> _writeList(PhotoOwner owner, int projectId, int parentId, List<ServerPhoto> photos) {
    return _db.transaction(() async {
      final Map<String, dynamic> all = await _readAll(projectId);
      all[_listKey(owner, parentId)] = <Map<String, dynamic>>[for (final ServerPhoto photo in photos) photo.toJson()];
      await _db.writeMeta(MetaKey.recordPhotos(projectId), jsonEncode(all));
    });
  }

  Future<_Parent?> _parent(PhotoOwner owner, String parentUuid) async {
    switch (owner) {
      case PhotoOwner.issue:
        final IssueRow? row = await (_db.select(_db.issues)..where((t) => t.clientUuid.equals(parentUuid))).getSingleOrNull();
        return row == null ? null : _Parent(projectId: row.projectId, serverId: row.serverId, title: row.title);
      case PhotoOwner.measurement:
        final MeasurementRow? row =
            await (_db.select(_db.measurements)..where((t) => t.clientUuid.equals(parentUuid))).getSingleOrNull();
        return row == null
            ? null
            : _Parent(projectId: row.projectId, serverId: row.serverId, title: row.qty.format(), status: row.status);
    }
  }

  /// The server refuses a photo on an entry that is approved or with the
  /// approver, and so does the phone, before the file is even copied. An entry
  /// waiting to be sent for approval counts: its photos would arrive too late.
  Future<void> _assertMeasurementOpen(_Parent parent, String entryUuid) async {
    if (parent.status != DocStatus.draft && parent.status != DocStatus.returned) {
      throw RecordLocked('This entry is ${parent.status} and cannot take photos.');
    }
    final OutboxRow? submit =
        await (_db.select(_db.outbox)
              ..where(
                (t) =>
                    t.recordUuid.equals(entryUuid) &
                    t.type.equals(OutboxType.measurementSubmit.name) &
                    t.status.equals(OutboxStatus.done.name).not(),
              )
              ..limit(1))
            .getSingleOrNull();
    if (submit != null) throw const RecordLocked('This entry has already been sent for approval.');
  }
}

class _Parent {
  const _Parent({required this.projectId, required this.serverId, required this.title, this.status});

  final int projectId;
  final int? serverId;
  final String title;

  /// Where an entry is in approval. Null for a record that has none.
  final String? status;
}
