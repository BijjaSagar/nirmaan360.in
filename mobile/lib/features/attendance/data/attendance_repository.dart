import 'dart:async';
import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/offline/offline_plan.dart' show LocalRecordKind;
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import 'worker_card.dart';

export '../../../core/db/app_database.dart'
    show WorkerRow, AttendanceMarkRow, AttendanceStatus;

/// One worker with their mark for the day, if any.
class AttendanceLine {
  const AttendanceLine({required this.worker, this.mark});

  final WorkerRow worker;
  final AttendanceMarkRow? mark;
}

class AttendanceTotals {
  const AttendanceTotals({
    this.present = 0,
    this.half = 0,
    this.absent = 0,
    this.overtime = 0,
    this.unmarked = 0,
    required this.otHours,
  });

  final int present;
  final int half;
  final int absent;
  final int overtime;
  final int unmarked;
  final Hours otHours;

  int get marked => present + half + absent + overtime;
  int get total => marked + unmarked;
}

/// How a scan of a worker's card ended.
enum ScanResult {
  /// The server marked the worker present.
  marked,

  /// The worker already had a mark for today, which a scan never changes.
  alreadyMarked,

  /// Kept on this phone, to be sent when there is a network. The server checks the card then.
  queued,

  /// The server refused it, in its own words: a replaced card, a worker on another site today.
  refused,

  /// Not a worker card at all.
  notACard,
}

class ScanOutcome {
  const ScanOutcome(this.result, {this.workerName, this.status, this.message});

  final ScanResult result;

  /// From the server's reply, else from this phone's list of workers. Null when neither knows.
  final String? workerName;

  /// For [ScanResult.alreadyMarked]: what the worker is marked as today.
  final String? status;

  /// For [ScanResult.refused]: the server's words, in the person's language.
  final String? message;
}

class AttendanceRepository {
  AttendanceRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// Marking by card or by selfie needs `attendance.manage` on a plan with `labour`.
  /// A phone that keeps its data local, and a contractor login, never have it.
  bool get canCapture {
    final user = _ctx.session.user;
    return user != null &&
        !user.isContractor &&
        !_ctx.offlinePlan.isLocalOnly &&
        user.can('attendance.manage') &&
        user.features.contains('labour');
  }

  /// Workers joined to their marks for [date]. A worker who has since been
  /// deactivated still appears on days they were marked.
  Stream<List<AttendanceLine>> watchDay(int projectId, String date) {
    final query = _db.select(_db.workers).join([
      leftOuterJoin(
        _db.attendanceMarks,
        _db.attendanceMarks.workerId.equalsExp(_db.workers.id) &
            _db.attendanceMarks.projectId.equals(projectId) &
            _db.attendanceMarks.attendanceDate.equals(date),
      ),
    ])
      ..where(
        _db.workers.isActive.equals(true) |
            _db.attendanceMarks.clientUuid.isNotNull(),
      )
      ..orderBy([OrderingTerm.asc(_db.workers.name)]);

    return query.watch().map((rows) {
      return [
        for (final row in rows)
          AttendanceLine(
            worker: row.readTable(_db.workers),
            mark: row.readTableOrNull(_db.attendanceMarks),
          ),
      ];
    });
  }

  Stream<AttendanceTotals> watchDayTotals(int projectId, String date) {
    return watchDay(projectId, date).map((lines) {
      var present = 0, half = 0, absent = 0, overtime = 0, unmarked = 0;
      var otHours = Hours.zero;
      for (final line in lines) {
        final mark = line.mark;
        if (mark == null) {
          unmarked++;
          continue;
        }
        otHours = otHours + mark.otHours;
        switch (mark.status) {
          case AttendanceStatus.present:
            present++;
          case AttendanceStatus.half:
            half++;
          case AttendanceStatus.absent:
            absent++;
          case AttendanceStatus.overtime:
            overtime++;
        }
      }
      return AttendanceTotals(
        present: present,
        half: half,
        absent: absent,
        overtime: overtime,
        unmarked: unmarked,
        otHours: otHours,
      );
    });
  }

  /// Marks one worker. Safe to call on every tap: repeated marks for one
  /// worker and date collapse into a single queued item holding the last value.
  Future<void> mark({
    required int projectId,
    required int workerId,
    required String date,
    required String status,
    Hours? otHours,
  }) {
    _ctx.assertCanWrite();
    _ctx.assertNotFuture(date, 'attendance_date');
    if (otHours != null && otHours > Hours.fromInt(16)) {
      throw const ValidationFailure(
        'Overtime cannot be more than 16 hours.',
        errors: {
          'ot_hours': ['Overtime cannot be more than 16 hours.'],
        },
      );
    }
    return _db.transaction(
      () => _markInTransaction(
        projectId: projectId,
        workerId: workerId,
        date: date,
        status: status,
        otHours: otHours ?? Hours.zero,
      ),
    );
  }

  /// Marks every active worker who has no mark yet as present.
  ///
  /// Marks already on the day are kept, so a tap here never turns someone
  /// recorded absent into present. Returns how many workers were marked.
  Future<int> markAllPresent(int projectId, String date) {
    _ctx.assertCanWrite();
    _ctx.assertNotFuture(date, 'attendance_date');
    return _db.transaction(() async {
      final workers = await (_db.select(_db.workers)
            ..where((t) => t.isActive.equals(true)))
          .get();
      final existing = await (_db.select(_db.attendanceMarks)
            ..where(
              (t) =>
                  t.projectId.equals(projectId) &
                  t.attendanceDate.equals(date),
            ))
          .get();
      final alreadyMarked = existing.map((m) => m.workerId).toSet();

      var added = 0;
      for (final worker in workers) {
        if (alreadyMarked.contains(worker.id)) continue;
        await _markInTransaction(
          projectId: projectId,
          workerId: worker.id,
          date: date,
          status: AttendanceStatus.present,
          otHours: Hours.zero,
        );
        added++;
      }
      return added;
    });
  }

  Future<void> _markInTransaction({
    required int projectId,
    required int workerId,
    required String date,
    required String status,
    required Hours otHours,
  }) async {
    final existing = await (_db.select(_db.attendanceMarks)
          ..where(
            (t) =>
                t.projectId.equals(projectId) &
                t.workerId.equals(workerId) &
                t.attendanceDate.equals(date),
          ))
        .getSingleOrNull();

    final stamp = _ctx.nextStamp([existing?.clientUpdatedAt, existing?.updatedAt]);
    final uuid = existing?.clientUuid ?? newUuid();

    if (existing == null) {
      await _db.into(_db.attendanceMarks).insert(
        AttendanceMarksCompanion.insert(
          clientUuid: uuid,
          projectId: projectId,
          workerId: workerId,
          attendanceDate: date,
          status: status,
          otHours: otHours,
          markedBy: Value(_ctx.currentUserId),
          clientUpdatedAt: Value(stamp),
        ),
      );
    } else {
      await (_db.update(_db.attendanceMarks)
            ..where((t) => t.clientUuid.equals(uuid)))
          .write(
            AttendanceMarksCompanion(
              status: Value(status),
              otHours: Value(otHours),
              markedBy: Value(_ctx.currentUserId),
              clientUpdatedAt: Value(stamp),
            ),
          );
    }

    await _ctx.outbox.enqueue(
      type: OutboxType.attendance,
      wireUuid: uuid,
      recordUuid: uuid,
      groupUuid: uuid,
      projectId: projectId,
      data: {
        'worker_id': workerId,
        'attendance_date': date,
        'status': status,
        'ot_hours': otHours.toWire(),
      },
      clientUpdatedAt: stamp,
      createdAt: _ctx.clock.now(),
      title: date,
      subtitle: date,
      coalesce: true,
    );
  }

  /// Adds a worker on the server. Online only, by design.
  ///
  /// Throws [OfflineFailure] when offline. The action key survives a lost
  /// response, so retrying the same form cannot create the worker twice.
  Future<WorkerRow> addWorker({
    required String name,
    required String trade,
    String? phone,
    String type = 'own',
    Money? dailyWage,
  }) async {
    _ctx.assertCanWrite();
    if (_ctx.offlinePlan.isLocalOnly) {
      return _addLocalWorker(name: name, trade: trade, phone: phone, type: type, dailyWage: dailyWage);
    }
    if (!_ctx.connectivity.isOnline) throw const OfflineFailure();

    final actionKey = 'idempotency.worker.${base64Url.encode(utf8.encode('$name|$trade|$phone|$type'))}';
    var uuid = await _db.readMeta(actionKey);
    if (uuid == null) {
      uuid = newUuid();
      await _db.writeMeta(actionKey, uuid);
    }

    final json = await _ctx.api.addWorker(
      clientUuid: uuid,
      name: name,
      trade: trade,
      phone: phone,
      type: type,
      dailyWage: dailyWage?.toWire(),
    );

    await _db.deleteMeta(actionKey);

    final companion = WorkersCompanion(
      id: Value((json['id'] as num).toInt()),
      name: Value(json['name'] as String? ?? name),
      phone: Value(json['phone'] as String? ?? phone),
      trade: Value(json['trade'] as String? ?? trade),
      type: Value(json['type'] as String? ?? type),
      isActive: Value(json['is_active'] as bool? ?? true),
    );
    await _db.into(_db.workers).insertOnConflictUpdate(companion);
    return (_db.select(_db.workers)
          ..where((t) => t.id.equals(companion.id.value)))
        .getSingle();
  }

  Future<WorkerRow> _addLocalWorker({
    required String name,
    required String trade,
    String? phone,
    required String type,
    Money? dailyWage,
  }) {
    _ctx.assertWithin(<(String, String?, int)>[('name', name, 120), ('trade', trade, 60), ('phone', phone, 20)]);
    return _ctx.createLocal(
      kind: LocalRecordKind.worker,
      table: 'workers',
      extras: <String, Object?>{'daily_wage': dailyWage?.toWire()},
      insert: (id) async {
        await _db.into(_db.workers).insert(
          WorkersCompanion.insert(
            id: Value(id),
            name: name,
            phone: Value(phone),
            trade: trade,
            type: Value(type),
            updatedAt: Value(_ctx.clock.now()),
          ),
        );
        return (_db.select(_db.workers)..where((t) => t.id.equals(id))).getSingle();
      },
    );
  }

  // Cards ------------------------------------------------------------------------

  SimpleSelectStatement<$OutboxTable, OutboxRow> _unsentScans(int projectId) => _db.select(_db.outbox)
    ..where(
      (t) =>
          t.projectId.equals(projectId) &
          t.type.isIn(<String>[OutboxType.attendanceScan.name, OutboxType.attendanceSelfie.name]) &
          t.status.equals(OutboxStatus.done.name).not(),
    )
    ..orderBy([(t) => OrderingTerm.asc(t.id)]);

  /// The workers whose card was scanned, or whose photo was taken, on [date] and not yet sent, so the muster
  /// can show them present before the server has said so.
  Stream<Set<int>> watchUnsentScans(int projectId, String date) =>
      _unsentScans(projectId).watch().map((rows) => _scannedOn(rows, date));

  /// The same, read once.
  Future<Set<int>> readUnsentScans(int projectId, String date) async =>
      _scannedOn(await _unsentScans(projectId).get(), date);

  static Set<int> _scannedOn(List<OutboxRow> rows, String date) => <int>{
    for (final OutboxRow row in rows)
      if ((jsonDecode(row.payload) as Map)['scan_date'] == date) ?_scanWorker(row),
  };

  static int? _scanWorker(OutboxRow row) {
    final Object? local = (jsonDecode(row.payload) as Map)['local'];
    final Object? id = local is Map ? local['worker_id'] : null;
    return id is num ? id.toInt() : null;
  }

  /// Marks the worker whose card was scanned present for today.
  ///
  /// With a network the server answers at once, and that answer is what the
  /// person sees. Without one, or when the answer does not come, the scan is kept
  /// and sent when it can be, under the same `client_uuid`, so a reply lost after
  /// the server marked the worker comes back as "already marked" and makes no
  /// second mark. A refusal is never queued: the person is standing there.
  ///
  /// The server dates a scan by the day it receives it. So a kept scan is only
  /// sent on the day it was made: see `SyncEngine._checkScanIsToday`.
  Future<ScanOutcome> scan(int projectId, String code) async {
    _ctx.assertCanWrite();
    final String trimmed = code.trim();
    final int? workerId = WorkerCard.workerIdOf(trimmed);
    if (workerId == null || trimmed.length > WorkerCard.longest) return const ScanOutcome(ScanResult.notACard);

    final WorkerRow? known = await (_db.select(_db.workers)..where((t) => t.id.equals(workerId))).getSingleOrNull();
    final String date = _ctx.today;

    // A mark someone made today is never changed by a scan, except an absent one.
    final AttendanceMarkRow? mark = await (_db.select(_db.attendanceMarks)
          ..where((t) => t.projectId.equals(projectId) & t.workerId.equals(workerId) & t.attendanceDate.equals(date)))
        .getSingleOrNull();
    if (mark != null && mark.status != AttendanceStatus.absent) {
      return ScanOutcome(ScanResult.alreadyMarked, workerName: known?.name, status: mark.status);
    }
    final Set<int> waiting = await readUnsentScans(projectId, date);
    if (waiting.contains(workerId)) {
      return ScanOutcome(ScanResult.alreadyMarked, workerName: known?.name, status: AttendanceStatus.present);
    }

    final String uuid = newUuid();
    if (_ctx.connectivity.isOnline) {
      try {
        final Map<String, dynamic> reply = await _ctx.api.postAttendanceScan(projectId, <String, dynamic>{
          'code': trimmed,
          'client_uuid': uuid,
        });
        // The mark reaches the muster with the next pull, which this asks for now.
        unawaited(_ctx.sync.syncNow());
        final Object? worker = reply['worker'];
        final Object? attendance = reply['attendance'];
        return ScanOutcome(
          reply['already_marked'] == true ? ScanResult.alreadyMarked : ScanResult.marked,
          workerName: (worker is Map ? worker['name'] as String? : null) ?? known?.name,
          status: attendance is Map ? attendance['status'] as String? : null,
        );
      } on ValidationFailure catch (e) {
        final List<String>? words = e.errors['code'];
        return ScanOutcome(
          ScanResult.refused,
          workerName: known?.name,
          message: words != null && words.isNotEmpty ? words.first : e.message,
        );
      } on ServerFailure catch (e) {
        // Forbidden or gone is an answer. Anything else is a fault to try again later.
        if (e.statusCode == 403 || e.statusCode == 404) {
          return ScanOutcome(ScanResult.refused, workerName: known?.name, message: e.message);
        }
      } on ApiFailure {
        // No network after all, a busy server: keep it below.
      }
    }

    await _db.transaction(
      () => _ctx.outbox.enqueue(
        type: OutboxType.attendanceScan,
        wireUuid: uuid,
        recordUuid: uuid,
        groupUuid: uuid,
        projectId: projectId,
        data: <String, dynamic>{
          'code': trimmed,
          'scan_date': date,
          // For the phone alone, to show who it was. Not sent.
          'local': <String, dynamic>{'worker_id': workerId, 'worker_name': known?.name},
        },
        createdAt: _ctx.clock.now(),
        title: known?.name ?? '',
        subtitle: date,
      ),
    );
    return ScanOutcome(ScanResult.queued, workerName: known?.name);
  }
}
