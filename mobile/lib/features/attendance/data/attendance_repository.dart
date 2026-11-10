import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/offline/offline_plan.dart' show LocalRecordKind;
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';

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

class AttendanceRepository {
  AttendanceRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

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
}
