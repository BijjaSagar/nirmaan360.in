import 'dart:async';
import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter/foundation.dart';

import '../api/api_failure.dart';
import '../api/nirmaan_api.dart';
import '../db/app_database.dart';
import '../db/outbox_dao.dart';
import '../session/current_user.dart';
import '../session/session_controller.dart';
import 'attachment_store.dart';
import 'clock.dart';
import 'connectivity.dart';
import 'floor_entries.dart';
import 'ids.dart';
import 'pull_applier.dart';
import 'record_photos.dart';
import 'sync_status.dart';

/// Pushes the outbox and pulls server changes.
///
/// Exactly-once delivery rests on three things working together: every write
/// carries a stable `client_uuid` and `client_updated_at`, so the server is
/// idempotent; a row only leaves `sending` when a result for it was applied;
/// and an outbox row and its local row always change in one transaction.
class SyncEngine {
  SyncEngine({
    required this._db,
    required this._api,
    required this._clock,
    required this._attachments,
    required ConnectivityMonitor connectivity,
    this._session,
    this.batchSize = 50,
    this.leaseTtl = const Duration(seconds: 60),
    this.leaseHeartbeat = const Duration(seconds: 20),
    this.parentMissingAttempts = 10,
    String? instanceId,
  }) : _outbox = OutboxDao(_db),
       _instanceId = instanceId ?? newUuid() {
    _online = connectivity.isOnline;
    _connectivitySub = connectivity.changes.listen((online) {
      _online = online;
      _notify();
    });
  }

  /// Non-file items per `POST /sync/push`.
  final int batchSize;
  final Duration leaseTtl;
  final Duration leaseHeartbeat;

  /// Uploads answered with 404 this many times stop retrying and ask a person.
  final int parentMissingAttempts;

  /// Runs after a successful pull. Failures here never fail the sync. The
  /// argument is true when this run sent something.
  Future<void> Function(bool sentSomething)? afterPull;

  /// Used when a 429 has no usable `Retry-After`: the limit's one-minute window.
  static const _defaultRateLimitWait = Duration(seconds: 60);

  final AppDatabase _db;
  final NirmaanApi _api;
  final AppClock _clock;
  final AttachmentStore _attachments;
  final SessionController? _session;
  final OutboxDao _outbox;
  late final RecordPhotos _photos = RecordPhotos(db: _db, api: _api, attachments: _attachments, clock: _clock);
  late final FloorEntries _floorEntries = FloorEntries(db: _db, api: _api);
  final String _instanceId;

  late final StreamSubscription<bool> _connectivitySub;
  final _engineChanges = StreamController<void>.broadcast();

  bool _online = true;
  SyncPhase _phase = SyncPhase.idle;
  SyncProgress? _progress;
  SyncIssue _issue = SyncIssue.none;
  SyncStatus _lastStatus = const SyncStatus();

  Future<SyncRunResult>? _running;
  bool _rerunRequested = false;
  bool _disposed = false;

  int _sentThisRun = 0;
  int _totalThisRun = 0;

  /// The most recent status without waiting for the stream.
  SyncStatus get currentStatus => _lastStatus;

  bool get isRunning => _running != null;

  /// Phase, online, counts, last sync time and progress. Emits on any change.
  Stream<SyncStatus> get status => Stream.multi((controller) {
    var counts = const OutboxCounts();
    DateTime? lastSyncedAt;

    void emit() {
      _lastStatus = SyncStatus(
        phase: _phase,
        online: _online,
        pendingCount: counts.pending,
        attentionCount: counts.attention,
        lastSyncedAt: lastSyncedAt,
        progress: _progress,
        lastIssue: _issue,
      );
      controller.add(_lastStatus);
    }

    final subscriptions = <StreamSubscription<dynamic>>[
      _outbox.watchCounts().listen((value) {
        counts = value;
        emit();
      }),
      _db.watchMeta(MetaKey.lastSyncedAt).listen((value) {
        lastSyncedAt = value == null ? null : DateTime.tryParse(value);
        emit();
      }),
      _engineChanges.stream.listen((_) => emit()),
    ];
    controller.onCancel = () =>
        Future.wait(subscriptions.map((s) => s.cancel()));
  });

  /// Runs one push and pull. Safe to call concurrently: a call during a run
  /// joins it and schedules one more pass, so its work is covered too.
  Future<SyncRunResult> syncNow() {
    if (_running != null) {
      _rerunRequested = true;
      return _running!;
    }
    final run = _runUntilSettled();
    _running = run.whenComplete(() {
      _running = null;
    });
    return _running!;
  }

  Future<SyncRunResult> _runUntilSettled() async {
    SyncRunResult result;
    do {
      _rerunRequested = false;
      result = await _runOnce();
    } while (_rerunRequested && result.isSuccess && !_disposed);
    return result;
  }

  Future<SyncRunResult> _runOnce() async {
    final wait = await _rateLimitRemaining();
    if (wait != null) {
      _issue = SyncIssue.rateLimited;
      _notify();
      return SyncRunResult(SyncOutcome.rateLimited, retryAfter: wait);
    }

    if (!await _acquireLease()) {
      return const SyncRunResult(SyncOutcome.busy);
    }

    final heartbeat = Timer.periodic(leaseHeartbeat, (_) => _renewLease());
    _sentThisRun = 0;
    _issue = SyncIssue.none;
    try {
      // Holding the lease means nobody else is mid-send, so any row still
      // marked sending was left behind by a process that died.
      await _outbox.recoverInterrupted();

      await _pushAll();

      await _pull();
      _online = true;

      try {
        await afterPull?.call(_sentThisRun > 0);
      } on Object catch (e) {
        debugPrint('After-pull step failed: $e');
      }

      final now = _clock.now();
      await _db.writeMeta(
        MetaKey.lastSyncedAt,
        now.toUtc().toIso8601String(),
      );
      await _outbox.pruneDone(now.subtract(const Duration(days: 30)));

      return SyncRunResult(SyncOutcome.success, sent: _sentThisRun);
    } on OfflineFailure catch (e) {
      _online = false;
      _issue = SyncIssue.offline;
      return SyncRunResult(SyncOutcome.offline, sent: _sentThisRun, error: e);
    } on RateLimited catch (e) {
      // A pause requested by the server, never a problem with the items.
      _issue = SyncIssue.rateLimited;
      final wait = e.retryAfter ?? _defaultRateLimitWait;
      await _db.writeMeta(
        MetaKey.rateLimitedUntil,
        _clock.now().add(wait).millisecondsSinceEpoch.toString(),
      );
      return SyncRunResult(
        SyncOutcome.rateLimited,
        sent: _sentThisRun,
        error: e,
        retryAfter: wait,
      );
    } on AuthExpired catch (e) {
      _issue = SyncIssue.authExpired;
      await _session?.markExpired();
      return SyncRunResult(
        SyncOutcome.authExpired,
        sent: _sentThisRun,
        error: e,
      );
    } catch (e, stack) {
      // Includes malformed pull bodies. The transaction rolled back, so the
      // watermark has not moved and nothing is lost by stopping here.
      debugPrint('Sync failed: $e\n$stack');
      _issue = SyncIssue.serverError;
      return SyncRunResult(
        SyncOutcome.serverError,
        sent: _sentThisRun,
        error: e,
      );
    } finally {
      heartbeat.cancel();
      await _releaseLease();
      _setPhase(SyncPhase.idle, null);
    }
  }

  /// Applies the changes since the watermark, then, if a row must be re-read,
  /// one full pull on top.
  ///
  /// The delta always comes first. A pull with no `since` has an empty
  /// `deleted` list, so going straight to a full pull would miss deletions,
  /// and the watermark must not move past them.
  Future<void> _pull() async {
    _setPhase(SyncPhase.pulling, null);
    final applier = PullApplier(_db);
    final needsRefresh = await _db.readMeta(MetaKey.fullRefresh) != null;
    final since = await _db.readMeta(MetaKey.watermark);

    final body = await _api.pull(since: since);
    await applier.apply(body);

    if (needsRefresh) {
      if (since != null) {
        await applier.apply(await _api.pull(), moveWatermark: false);
      }
      await _db.deleteMeta(MetaKey.fullRefresh);
    }

    final user = body['user'];
    if (user is Map) {
      await _session?.updateUser(
        CurrentUser.fromJson(user.cast<String, dynamic>()),
      );
    }
  }

  /// How long is left of a server-requested pause, or null if there is none.
  Future<Duration?> _rateLimitRemaining() async {
    final raw = await _db.readMeta(MetaKey.rateLimitedUntil);
    if (raw == null) return null;
    final remaining =
        int.parse(raw) - _clock.now().millisecondsSinceEpoch;
    if (remaining <= 0) {
      await _db.deleteMeta(MetaKey.rateLimitedUntil);
      return null;
    }
    return Duration(milliseconds: remaining);
  }

  /// Puts rows stuck in `sending` back to `pending`. Call at start-up.
  ///
  /// This runs without the lease on purpose. A background sync still running
  /// in this process would lose nothing: a row it sent is re-sent and comes
  /// back as `duplicate`.
  Future<int> recoverInterrupted() => _outbox.recoverInterrupted();

  // Push ----------------------------------------------------------------------

  Future<void> _pushAll() async {
    final unfinished = await _outbox.unfinished();
    final pending =
        unfinished.where((r) => r.status == OutboxStatus.pending).length;
    if (pending == 0) return;

    _totalThisRun = pending;
    _setPhase(SyncPhase.pushing, _currentProgress());

    // Groups that cannot go further in this run. Without this, a row that
    // keeps coming back to pending would be picked forever.
    final deferredGroups = <String>{};

    while (true) {
      final heads = await _outbox.eligibleHeads(skipGroups: deferredGroups);
      if (heads.isEmpty) return;

      final batch = heads
          .where((r) => !r.type.isFile && !r.type.isDirect)
          .take(batchSize)
          .toList();
      if (batch.isNotEmpty) {
        _setPhase(SyncPhase.pushing, _currentProgress());
        await _sendBatch(batch, deferredGroups);
      }

      for (final row in heads.where((r) => r.type.isDirect)) {
        _setPhase(SyncPhase.pushing, _currentProgress(label: row.title));
        await _sendDirect(row);
      }

      final files = heads.where((r) => r.type.isFile).toList();
      for (final file in files) {
        _setPhase(
          SyncPhase.uploading,
          _currentProgress(label: file.title),
        );
        await _uploadOne(file, deferredGroups);
      }
    }
  }

  Future<void> _sendBatch(
    List<OutboxRow> rows,
    Set<String> deferredGroups,
  ) async {
    final items = <PushItem>[];
    for (final row in rows) {
      items.add(await _buildPushItem(row));
    }

    final ids = rows.map((r) => r.id).toList();
    await _outbox.markSending(ids, _clock.now());

    final List<PushResult> results;
    try {
      results = await _api.push(items);
    } on ValidationFailure catch (e) {
      // The request as a whole was refused. Find the row responsible rather
      // than letting one bad row hold up everything behind it forever.
      if (rows.length > 1) {
        await _outbox.releaseSending(ids);
        for (final row in rows) {
          await _sendBatch([row], deferredGroups);
        }
        return;
      }
      await _db.transaction(() async {
        await _outbox.markAttention(
          rows.single.id,
          resultStatus: 'error',
          code: AttentionCode.validation,
          reason: _validationReason(e.errors, e.message),
          errors: e.errors,
        );
      });
      _sentThisRun++;
      return;
    } catch (_) {
      await _outbox.releaseSending(ids);
      rethrow;
    }

    _online = true;
    await _db.transaction(() async {
      final byUuid = {for (final r in results) r.clientUuid: r};
      for (final row in rows) {
        final result = byUuid[row.wireUuid];
        if (result == null) {
          // No answer for this row. Assume nothing; it goes round again on
          // the next run.
          await _outbox.releaseSending([row.id]);
          deferredGroups.add(row.groupUuid);
          continue;
        }
        await _applyResult(row, result);
      }
    });
  }

  Future<PushItem> _buildPushItem(OutboxRow row) async {
    final data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();

    // Read the server id now: it may have arrived after this row was queued.
    final serverId = await _serverIdFor(row.type, row.recordUuid);

    void preferId(String uuidKey, String idKey) {
      if (serverId != null) {
        data.remove(uuidKey);
        data[idKey] = serverId;
      }
    }

    switch (row.type) {
      case OutboxType.dprSubmit:
        preferId('dpr_client_uuid', 'dpr_id');
      case OutboxType.measurementSubmit:
        preferId('entry_client_uuid', 'entry_id');
      case OutboxType.issueStatus:
        preferId('issue_client_uuid', 'issue_id');
      default:
        break;
    }

    return PushItem(
      type: row.type.wire,
      clientUuid: row.wireUuid,
      projectId: row.projectId,
      serverId: serverId,
      clientUpdatedAt: row.clientUpdatedAt == null
          ? null
          : toWireTimestamp(row.clientUpdatedAt!),
      data: data,
    );
  }

  Future<int?> _serverIdFor(OutboxType type, String recordUuid) async {
    switch (type) {
      case OutboxType.dpr:
      case OutboxType.dprSubmit:
        final row =
            await (_db.select(_db.dprs)
                  ..where((t) => t.clientUuid.equals(recordUuid)))
                .getSingleOrNull();
        return row?.serverId;
      case OutboxType.measurement:
      case OutboxType.measurementSubmit:
        final row =
            await (_db.select(_db.measurements)
                  ..where((t) => t.clientUuid.equals(recordUuid)))
                .getSingleOrNull();
        return row?.serverId;
      case OutboxType.issue:
      case OutboxType.issueStatus:
        final row =
            await (_db.select(_db.issues)
                  ..where((t) => t.clientUuid.equals(recordUuid)))
                .getSingleOrNull();
        return row?.serverId;
      case OutboxType.attendance:
        final row =
            await (_db.select(_db.attendanceMarks)
                  ..where((t) => t.clientUuid.equals(recordUuid)))
                .getSingleOrNull();
        return row?.serverId;
      case OutboxType.pettyCash:
        final row =
            await (_db.select(_db.pettyCashEntries)
                  ..where((t) => t.clientUuid.equals(recordUuid)))
                .getSingleOrNull();
        return row?.serverId;
      case OutboxType.dprPhoto:
      case OutboxType.pettyBill:
      case OutboxType.progressEntry:
      case OutboxType.progressResolve:
      case OutboxType.progressPhoto:
      case OutboxType.stageCell:
      case OutboxType.stagePhoto:
      case OutboxType.materialChallan:
      case OutboxType.materialUse:
      case OutboxType.stockCount:
      case OutboxType.issuePhoto:
      case OutboxType.measurementPhoto:
      case OutboxType.progressCorrection:
        return null;
    }
  }

  /// Applies one wire result. Runs inside the batch transaction.
  Future<void> _applyResult(OutboxRow row, PushResult result) async {
    final now = _clock.now();

    switch (result.status) {
      case 'created':
      case 'updated':
      case 'duplicate':
        if (await _outbox.markDone(row.id, result.status, now)) {
          await _applyToRecord(row, result);
        }
      case 'stale':
        if (await _outbox.markDone(row.id, 'stale', now)) {
          // The server's row is newer but may be older than the watermark,
          // because local work was kept when it was pulled. Re-read it.
          await _db.writeMeta(MetaKey.fullRefresh, '1');
        }
      case 'conflict':
        final code = switch (result.reason) {
          'approved' => AttentionCode.approved,
          'in_review' => AttentionCode.inReview,
          'rejected' => AttentionCode.rejected,
          _ => AttentionCode.unexpected,
        };
        await _outbox.markAttention(
          row.id,
          resultStatus: 'conflict',
          code: code,
          reason: _conflictReason(result.reason),
        );
      case 'error':
        // The project itself was refused, so nothing in the entry can fix it.
        final projectGone = result.errors?.containsKey('project_id') ?? false;
        await _outbox.markAttention(
          row.id,
          resultStatus: 'error',
          code: projectGone ? AttentionCode.projectGone : AttentionCode.validation,
          reason: projectGone
              ? 'You no longer have access to this project.'
              : _validationReason(result.errors, result.reason),
          errors: result.errors,
        );
      case 'forbidden':
        final isPlan = result.reason == 'feature';
        await _outbox.markAttention(
          row.id,
          resultStatus: 'forbidden',
          code: isPlan ? AttentionCode.planFeature : AttentionCode.forbidden,
          reason: isPlan
              ? 'Your plan does not include this module.'
              : 'You do not have permission to do this.',
        );
      default:
        await _outbox.markAttention(
          row.id,
          resultStatus: result.status,
          code: AttentionCode.unexpected,
          reason: 'The server sent an unexpected reply: ${result.status}.',
        );
    }
    _sentThisRun++;
    _notifyProgress();
  }

  Future<void> _applyToRecord(OutboxRow row, PushResult result) async {
    final id = result.id;
    final status = result.serverStatus;

    switch (row.type) {
      case OutboxType.dpr:
      case OutboxType.dprSubmit:
        await (_db.update(_db.dprs)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .write(
              DprsCompanion(
                serverId: id != null ? Value(id) : const Value.absent(),
                status: status != null ? Value(status) : const Value.absent(),
              ),
            );
      case OutboxType.measurement:
      case OutboxType.measurementSubmit:
        await (_db.update(_db.measurements)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .write(
              MeasurementsCompanion(
                serverId: id != null ? Value(id) : const Value.absent(),
                status: status != null ? Value(status) : const Value.absent(),
              ),
            );
      case OutboxType.issue:
        await (_db.update(_db.issues)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .write(
              IssuesCompanion(
                serverId: id != null ? Value(id) : const Value.absent(),
              ),
            );
      case OutboxType.issueStatus:
        break;
      case OutboxType.attendance:
        await (_db.update(_db.attendanceMarks)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .write(
              AttendanceMarksCompanion(
                serverId: id != null ? Value(id) : const Value.absent(),
              ),
            );
      case OutboxType.pettyCash:
        await (_db.update(_db.pettyCashEntries)
              ..where((t) => t.clientUuid.equals(row.recordUuid)))
            .write(
              PettyCashEntriesCompanion(
                serverId: id != null ? Value(id) : const Value.absent(),
              ),
            );
      case OutboxType.dprPhoto:
      case OutboxType.pettyBill:
      case OutboxType.progressEntry:
      case OutboxType.progressResolve:
      case OutboxType.progressPhoto:
      case OutboxType.stageCell:
      case OutboxType.stagePhoto:
      case OutboxType.materialChallan:
      case OutboxType.materialUse:
      case OutboxType.stockCount:
      case OutboxType.issuePhoto:
      case OutboxType.measurementPhoto:
      case OutboxType.progressCorrection:
        break;
    }
  }

  // Direct posts --------------------------------------------------------------

  /// Sends one site progress or material row to its own endpoint.
  ///
  /// A row whose answer is lost is sent again with the same `client_uuid`, and
  /// the server returns the first copy.
  Future<void> _sendDirect(OutboxRow row) async {
    final relativePath = row.filePath;
    if (relativePath != null && !await _attachments.exists(relativePath)) {
      await _outbox.markSending([row.id], _clock.now());
      await _outbox.markAttention(
        row.id,
        resultStatus: 'error',
        code: AttentionCode.fileMissing,
        reason: 'The photo file is missing from this phone.',
      );
      _sentThisRun++;
      _notifyProgress();
      return;
    }

    await _outbox.markSending([row.id], _clock.now());

    final Map<String, dynamic> stored;
    try {
      stored = await _postDirect(row);
    } on ValidationFailure catch (e) {
      await _outbox.markAttention(
        row.id,
        resultStatus: 'error',
        code: AttentionCode.validation,
        reason: _validationReason(e.errors, e.message),
        errors: e.errors,
      );
      _sentThisRun++;
      _notifyProgress();
      return;
    } on ServerFailure catch (e) {
      if (e.statusCode == 403) {
        await _outbox.markAttention(
          row.id,
          resultStatus: 'forbidden',
          code: AttentionCode.forbidden,
          reason: 'You do not have permission to do this.',
        );
      } else if (e.statusCode == 404) {
        await _markNotFound(row);
      } else if (e.statusCode == 409) {
        // The record was approved or sent for review while this waited. The
        // server's words are all that tell the two apart.
        final inReview = e.message.toLowerCase().contains('review');
        await _outbox.markAttention(
          row.id,
          resultStatus: 'conflict',
          code: inReview ? AttentionCode.inReview : AttentionCode.approved,
          reason: e.message,
        );
      } else {
        await _outbox.releaseSending([row.id]);
        rethrow;
      }
      _sentThisRun++;
      _notifyProgress();
      return;
    } catch (_) {
      await _outbox.releaseSending([row.id]);
      rethrow;
    }

    _online = true;
    final serverId = (stored['id'] as num?)?.toInt();
    final PhotoOwner? owner = PhotoOwner.forType(row.type);
    await _db.transaction(() async {
      if (!await _outbox.markDone(row.id, 'created', _clock.now())) return;
      if (serverId != null) await _outbox.setParentId(row.recordUuid, serverId);
      // The photo joins the record's list in the same moment its row is
      // finished, so a screen never sees it twice or not at all.
      if (owner != null) await _photos.remember(owner, row.projectId, stored, localRelativePath: relativePath);
      // The same for an entry or a correction: the floor lists it at once.
      if (row.type == OutboxType.progressEntry || row.type == OutboxType.progressCorrection) {
        await _floorEntries.remember(row.projectId, stored);
      }
    });
    _sentThisRun++;
    _notifyProgress();

    // Only now, with the upload confirmed and recorded, is the file removed.
    if (relativePath != null) await _attachments.delete(relativePath);
  }

  Future<Map<String, dynamic>> _postDirect(OutboxRow row) async {
    final data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
    final body = <String, dynamic>{...data, 'client_uuid': row.wireUuid};

    Future<Map<String, dynamic>> photo(
      String path, {
      String fileField = 'photo',
      Map<String, String> fields = const <String, String>{},
    }) async => _api.uploadFieldPhoto(
      path,
      filePath: await _attachments.absolutePath(row.filePath!),
      clientUuid: row.wireUuid,
      caption: data['caption'] as String?,
      takenAt: data['taken_at'] as String?,
      fileField: fileField,
      fields: fields,
    );

    switch (row.type) {
      case OutboxType.progressEntry:
        return _api.postProgressEntry(row.projectId, body);
      case OutboxType.progressResolve:
        final entryId = (data['entry_id'] as num).toInt();
        return _api.resolveProgress(entryId, {'remark': data['remark'], 'client_uuid': row.wireUuid});
      case OutboxType.progressCorrection:
        final correctedId = (data['entry_id'] as num).toInt();
        return _api.correctProgress(correctedId, {
          'qty_done': data['qty_done'],
          'remark': data['remark'],
          'client_uuid': row.wireUuid,
        });
      case OutboxType.stageCell:
        return _api.postStageCell(row.projectId, body);
      case OutboxType.progressPhoto:
        return photo('/progress/entries/${_parentId(data)}/photos');
      case OutboxType.stagePhoto:
        return photo('/stage-cells/${_parentId(data)}/photos');
      case OutboxType.issuePhoto:
        return photo(
          PhotoOwner.issue.listPath(await _recordId(OutboxType.issue, row)),
          fileField: PhotoOwner.issue.fileField,
          fields: <String, String>{'kind': data['kind'] as String? ?? IssuePhotoKind.before},
        );
      case OutboxType.measurementPhoto:
        return photo(
          PhotoOwner.measurement.listPath(await _recordId(OutboxType.measurement, row)),
          fileField: PhotoOwner.measurement.fileField,
          fields: <String, String>{'kind': data['kind'] as String? ?? MeasurementPhotoKind.photo},
        );
      case OutboxType.materialChallan:
        final file = row.filePath;
        return _api.postMaterialChallan(
          row.projectId,
          body,
          filePath: file == null ? null : await _attachments.absolutePath(file),
        );
      case OutboxType.materialUse:
        return _api.postMaterialUse(row.projectId, body);
      case OutboxType.stockCount:
        return _api.postStockCount(row.projectId, body);
      default:
        throw StateError('${row.type} is not a direct row.');
    }
  }

  /// Set when the parent row was accepted, which the group order guarantees
  /// comes first. Without it the server has nothing to attach the file to.
  int _parentId(Map<String, dynamic> data) {
    final id = data['parent_id'];
    if (id is num) return id.toInt();
    throw const ServerFailure('The parent record was not found.', statusCode: 404);
  }

  /// The server id of the record a photo belongs to, read now: it may have
  /// arrived after the photo was queued, when the record's own row was sent.
  Future<int> _recordId(OutboxType recordType, OutboxRow row) async {
    final String? uuid = row.parentUuid;
    final int? id = uuid == null ? null : await _serverIdFor(recordType, uuid);
    if (id != null) return id;
    throw const ServerFailure('The parent record was not found.', statusCode: 404);
  }

  Future<void> _markNotFound(OutboxRow row) async {
    final project = await (_db.select(_db.projects)
          ..where((t) => t.id.equals(row.projectId)))
        .getSingleOrNull();
    await _outbox.markAttention(
      row.id,
      resultStatus: 'error',
      code: project == null ? AttentionCode.projectGone : AttentionCode.parentMissing,
      reason: project == null
          ? 'You no longer have access to this project.'
          : 'The record this belongs to was not found on the server.',
    );
  }

  // Uploads -------------------------------------------------------------------

  Future<void> _uploadOne(OutboxRow row, Set<String> deferredGroups) async {
    final relativePath = row.filePath!;

    if (!await _attachments.exists(relativePath)) {
      await _outbox.markSending([row.id], _clock.now());
      await _outbox.markAttention(
        row.id,
        resultStatus: 'error',
        code: AttentionCode.fileMissing,
        reason: 'The photo file is missing from this phone.',
      );
      _sentThisRun++;
      _notifyProgress();
      return;
    }

    final data = (jsonDecode(row.payload) as Map).cast<String, dynamic>();
    final parentId = await _parentServerId(row);
    final request = UploadRequest(
      type: row.type.wire,
      clientUuid: row.wireUuid,
      filePath: await _attachments.absolutePath(relativePath),
      parentClientUuid: parentId == null ? row.parentUuid : null,
      parentId: parentId,
      caption: data['caption'] as String?,
      takenAt: data['taken_at'] as String?,
    );

    await _outbox.markSending([row.id], _clock.now());

    final UploadResult result;
    try {
      result = await _api.upload(request);
    } on ServerFailure catch (e) {
      if (e.statusCode == 404) {
        await _parentNotReady(row, deferredGroups);
      } else if (e.statusCode == 409) {
        await _outbox.markAttention(
          row.id,
          resultStatus: 'conflict',
          code: AttentionCode.approved,
          reason: 'This record was already approved, so the file was not added.',
        );
        _sentThisRun++;
      } else if (e.statusCode == 403) {
        await _outbox.markAttention(
          row.id,
          resultStatus: 'forbidden',
          code: AttentionCode.forbidden,
          reason: 'You do not have permission to upload this file.',
        );
        _sentThisRun++;
      } else {
        await _outbox.releaseSending([row.id]);
        rethrow;
      }
      _notifyProgress();
      return;
    } on ValidationFailure catch (e) {
      await _outbox.markAttention(
        row.id,
        resultStatus: 'error',
        code: AttentionCode.validation,
        reason: _validationReason(e.errors, e.message),
        errors: e.errors,
      );
      _sentThisRun++;
      _notifyProgress();
      return;
    } catch (_) {
      await _outbox.releaseSending([row.id]);
      rethrow;
    }

    _online = true;
    await _db.transaction(() async {
      if (!await _outbox.markDone(row.id, result.status, _clock.now())) return;
      await _applyUpload(row, result.id);
    });
    _sentThisRun++;
    _notifyProgress();

    // Only now, with the upload confirmed and recorded, is the file removed.
    await _attachments.delete(relativePath);
  }

  /// A parent that is not found yet is retried on a later run, but not
  /// forever: a permanently missing parent would block its group silently.
  Future<void> _parentNotReady(
    OutboxRow row,
    Set<String> deferredGroups,
  ) async {
    if (row.notFoundCount + 1 >= parentMissingAttempts) {
      final project = await (_db.select(_db.projects)
            ..where((t) => t.id.equals(row.projectId)))
          .getSingleOrNull();
      final projectGone = project == null;
      await _outbox.markAttention(
        row.id,
        resultStatus: 'error',
        code: projectGone ? AttentionCode.projectGone : AttentionCode.parentMissing,
        reason: projectGone
            ? 'You no longer have access to this project.'
            : 'The record this file belongs to was not found on the server.',
      );
      _sentThisRun++;
    } else {
      await _outbox.releaseAfterNotFound(row.id);
      deferredGroups.add(row.groupUuid);
    }
  }

  Future<int?> _parentServerId(OutboxRow row) async {
    if (row.parentUuid == null) return null;
    if (row.type == OutboxType.dprPhoto) {
      final parent =
          await (_db.select(_db.dprs)
                ..where((t) => t.clientUuid.equals(row.parentUuid!)))
              .getSingleOrNull();
      return parent?.serverId;
    }
    final parent =
        await (_db.select(_db.pettyCashEntries)
              ..where((t) => t.clientUuid.equals(row.parentUuid!)))
            .getSingleOrNull();
    return parent?.serverId;
  }

  Future<void> _applyUpload(OutboxRow row, int serverId) async {
    if (row.type == OutboxType.dprPhoto) {
      // A pull during a lost response may have already added this photo
      // under its server id. Keep only the original local row.
      await (_db.delete(_db.dprPhotos)..where(
            (t) =>
                t.serverId.equals(serverId) &
                t.clientUuid.equals(row.recordUuid).not(),
          ))
          .go();
      await (_db.update(_db.dprPhotos)
            ..where((t) => t.clientUuid.equals(row.recordUuid)))
          .write(
            DprPhotosCompanion(
              serverId: Value(serverId),
              relativePath: const Value(null),
            ),
          );
    } else {
      await (_db.update(_db.pettyCashEntries)
            ..where((t) => t.clientUuid.equals(row.recordUuid)))
          .write(
            const PettyCashEntriesCompanion(
              hasBill: Value(true),
              billRelativePath: Value(null),
            ),
          );
    }
  }

  // Lease ---------------------------------------------------------------------

  /// One writer at a time across the UI engine and the background engine.
  Future<bool> _acquireLease() {
    return _db.transaction(() async {
      final raw = await _db.readMeta(MetaKey.lease);
      final now = _clock.now().millisecondsSinceEpoch;
      if (raw != null) {
        final lease = (jsonDecode(raw) as Map).cast<String, dynamic>();
        final owner = lease['owner'] as String;
        final expires = (lease['expires'] as num).toInt();
        if (owner != _instanceId && expires > now) return false;
      }
      await _writeLease(now);
      return true;
    });
  }

  Future<void> _renewLease() async {
    if (_disposed) return;
    final now = _clock.now().millisecondsSinceEpoch;
    await _db.transaction(() async {
      final raw = await _db.readMeta(MetaKey.lease);
      if (raw == null) return;
      final lease = (jsonDecode(raw) as Map).cast<String, dynamic>();
      if (lease['owner'] == _instanceId) await _writeLease(now);
    });
  }

  Future<void> _writeLease(int nowMillis) {
    return _db.writeMeta(
      MetaKey.lease,
      jsonEncode({
        'owner': _instanceId,
        'expires': nowMillis + leaseTtl.inMilliseconds,
      }),
    );
  }

  Future<void> _releaseLease() async {
    await _db.transaction(() async {
      final raw = await _db.readMeta(MetaKey.lease);
      if (raw == null) return;
      final lease = (jsonDecode(raw) as Map).cast<String, dynamic>();
      if (lease['owner'] == _instanceId) {
        await _db.deleteMeta(MetaKey.lease);
      }
    });
  }

  // Status --------------------------------------------------------------------

  void _setPhase(SyncPhase phase, SyncProgress? progress) {
    _phase = phase;
    _progress = progress;
    _notify();
  }

  SyncProgress _currentProgress({String? label}) =>
      SyncProgress(done: _sentThisRun, total: _totalThisRun, label: label);

  void _notifyProgress() {
    if (_phase == SyncPhase.idle) return;
    _progress = _currentProgress(label: _progress?.label);
    _notify();
  }

  void _notify() {
    if (!_engineChanges.isClosed) _engineChanges.add(null);
  }

  Future<void> dispose() async {
    _disposed = true;
    await _connectivitySub.cancel();
    await _engineChanges.close();
  }

  // Human reasons -------------------------------------------------------------

  String _conflictReason(String? reason) => switch (reason) {
    'approved' =>
      'This record was already approved. Your change was not applied.',
    'in_review' =>
      'This record is with the approver. Your change was not applied.',
    'rejected' => 'This record was rejected. Your change was not applied.',
    _ => 'The server would not accept this change.',
  };

  String _validationReason(
    Map<String, List<String>>? errors,
    String? fallback,
  ) {
    final messages = errors?.values.expand((e) => e).toList() ?? const [];
    if (messages.isNotEmpty) return messages.join(' ');
    return fallback ?? 'The server could not accept this entry.';
  }
}
