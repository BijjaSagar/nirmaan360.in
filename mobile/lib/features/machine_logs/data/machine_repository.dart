import 'dart:convert';

import 'package:drift/drift.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/data_context.dart';
import 'machine_models.dart';

export 'machine_models.dart';

/// The daily machine log. What the server last said is kept per project, and a
/// log typed on the phone waits in the outbox until it can be sent.
///
/// A log is safe to write offline. The server repeats `POST /machine-logs` by
/// `client_uuid` and stores nothing the second time. What it can still refuse
/// when the log arrives (more than 24 hours for one machine on one day, a day a
/// hire bill already covers, a machine retired meanwhile) comes back as a 422
/// and waits in the Sync Center in the server's own words.
class MachineRepository {
  MachineRepository(this._ctx);

  final DataContext _ctx;

  static const Duration _freshFor = Duration(minutes: 10);

  /// The most pages of machines read: 100 to a page.
  static const int _assetPages = 5;

  /// The server's own limits, from `PlantLogService::logRules`.
  static final Hours maxHoursPerDay = Hours.fromInt(24);
  static final Qty maxKm = Qty.fromInt(99999);
  static final Qty maxReading = Qty.fromInt(99999999);
  static const int operatorMax = 80;
  static const int remarksMax = 255;

  AppDatabase get _db => _ctx.db;

  /// Open to this person on this plan. Only `asset.log` reaches the app.
  bool get isOpenToUser {
    final user = _ctx.session.user;
    return user != null && !user.isContractor && user.can('asset.log') && user.features.contains('assets');
  }

  // Reading --------------------------------------------------------------------

  Stream<MachineOverview?> watchCached(int projectId) => _db.watchMeta(MetaKey.machines(projectId)).map(_decode);

  SimpleSelectStatement<$OutboxTable, OutboxRow> _unsentQuery(int projectId) {
    return _db.select(_db.outbox)
      ..where(
        (t) =>
            t.projectId.equals(projectId) &
            t.type.equals(OutboxType.machineLog.name) &
            t.status.equals(OutboxStatus.done.name).not(),
      )
      ..orderBy([(t) => OrderingTerm.asc(t.id)]);
  }

  Stream<List<OutboxRow>> watchUnsent(int projectId) => _unsentQuery(projectId).watch();

  /// The last copy on this phone with logs still waiting laid over it, read once.
  Future<MachineOverview?> readOverview(int projectId) async {
    final MachineOverview? cached = _decode(await _db.readMeta(MetaKey.machines(projectId)));
    return cached?.withUnsent(await _unsentQuery(projectId).get());
  }

  MachineOverview? _decode(String? raw) {
    if (raw == null) return null;
    try {
      return MachineOverview.fromJson((jsonDecode(raw) as Map).cast<String, dynamic>());
    } on Object {
      // A copy that cannot be read is the same as no copy: the next read replaces it.
      return null;
    }
  }

  bool _isRefusal(ServerFailure e) => e.statusCode == 403 || e.statusCode == 404;

  List<Map<String, dynamic>> _rows(Object? value) => value is List
      ? <Map<String, dynamic>>[
          for (final Object? row in value)
            if (row is Map) row.cast<String, dynamic>(),
        ]
      : const <Map<String, dynamic>>[];

  /// Throws an [ApiFailure] when the server cannot be reached, leaving the last
  /// copy in place. A refusal replaces it, because it may no longer be seen.
  Future<void> refresh(int projectId) async {
    final String key = MetaKey.machines(projectId);
    final List<Map<String, dynamic>> machines = <Map<String, dynamic>>[];
    final Map<String, dynamic> logs;
    try {
      var page = 1;
      var last = 1;
      do {
        final Map<String, dynamic> body = await _ctx.api.machineAssets(page: page);
        machines.addAll(_rows(body['data']));
        last = (body['last_page'] as num?)?.toInt() ?? 1;
        page++;
      } while (page <= last && page <= _assetPages);
      logs = await _ctx.api.machineLogs(projectId);
    } on ServerFailure catch (e) {
      if (!_isRefusal(e)) rethrow;
      await _db.writeMeta(key, jsonEncode(<String, dynamic>{'refused': true, 'fetched_at': _now}));
      return;
    }
    await _db.writeMeta(
      key,
      jsonEncode(<String, dynamic>{
        'machines': machines,
        'logs': _rows(logs['data']),
        'more': ((logs['last_page'] as num?)?.toInt() ?? 1) > 1,
        'fetched_at': _now,
      }),
    );
  }

  /// For the sync engine: keeps the selected project ready to use offline.
  Future<void> refreshCurrent({bool force = false}) async {
    final int? projectId = int.tryParse(await _db.readMeta(MetaKey.selectedProject) ?? '');
    if (projectId == null) return;
    if (!isOpenToUser) {
      // Someone who no longer has this should not keep a copy to open.
      if (_ctx.session.user != null) await _db.deleteMeta(MetaKey.machines(projectId));
      return;
    }
    // A project taken away is not read again. It would only be refused.
    final known = await (_db.select(_db.projects)..where((t) => t.id.equals(projectId))).getSingleOrNull();
    if (known == null) return;
    if (!force) {
      final DateTime? last = _decode(await _db.readMeta(MetaKey.machines(projectId)))?.fetchedAt;
      if (last != null && _ctx.clock.now().difference(last) < _freshFor) return;
    }
    await refresh(projectId);
  }

  // Writing --------------------------------------------------------------------

  /// Queues a day's log for [machine]. At least one of [hours], [km],
  /// [breakdownHours] or both readings is needed, as the server asks, and the
  /// readings give the worked figure only for a machine whose meter counts it.
  ///
  /// Hours are [Hours], one decimal, like overtime: what is typed is never
  /// rounded, so a figure with more places is refused.
  ///
  /// Throws a [ValidationFailure] naming the field the server would refuse.
  Future<String> log({
    required String uuid,
    required int projectId,
    required Machine machine,
    required String date,
    Hours? hours,
    Qty? km,
    Hours? breakdownHours,
    Qty? openingReading,
    Qty? closingReading,
    String? operatorName,
    String? remarks,
  }) async {
    _ctx.assertCanWrite();
    _ctx.assertNotFuture(date, 'log_date');
    void refuse(String field, String message) =>
        throw ValidationFailure(message, errors: {field: [message]});

    if (hours != null && (hours.isNegative || hours > maxHoursPerDay)) refuse('hours', 'Hours must be from 0 to 24.');
    if (breakdownHours != null && (breakdownHours.isNegative || breakdownHours > maxHoursPerDay)) {
      refuse('breakdown_hours', 'Hours lost must be from 0 to 24.');
    }
    if (km != null && (km.isNegative || km > maxKm)) refuse('km', 'Km must be from 0 to 99,999.');
    for (final (String field, Qty? reading) in <(String, Qty?)>[
      ('opening_reading', openingReading),
      ('closing_reading', closingReading),
    ]) {
      if (reading != null && (reading.isNegative || reading > maxReading)) refuse(field, 'Readings must be from 0 to 99,999,999.');
    }
    final bool bothReadings = openingReading != null && closingReading != null;
    if (bothReadings && closingReading < openingReading) {
      refuse('closing_reading', 'The closing reading cannot be lower than the opening reading.');
    }
    final bool fromMeter = bothReadings && (machine.meterIsHours || machine.meterIsKm);
    if (hours == null && km == null && breakdownHours == null && !fromMeter) {
      refuse('hours', 'Enter the hours or km worked, or the hours lost to breakdown.');
    }
    _ctx.assertWithin(<(String, String?, int)>[('operator', operatorName, operatorMax), ('remarks', remarks, remarksMax)]);

    final String? worked = hours != null
        ? '${hours.toWire()} h'
        : km != null
        ? '${km.toWire()} km'
        : null;
    await _ctx.db.transaction(() async {
      await _ctx.outbox.enqueue(
        type: OutboxType.machineLog,
        wireUuid: uuid,
        recordUuid: uuid,
        groupUuid: uuid,
        projectId: projectId,
        data: <String, dynamic>{
          'asset_id': machine.id,
          'project_id': projectId,
          'log_date': date,
          'hours': ?hours?.toWire(),
          'km': ?km?.toWire(),
          'breakdown_hours': ?breakdownHours?.toWire(),
          'opening_reading': ?openingReading?.toWire(),
          'closing_reading': ?closingReading?.toWire(),
          'operator': ?_clean(operatorName),
          'remarks': ?_clean(remarks),
        },
        createdAt: _ctx.clock.now(),
        title: machine.displayName,
        subtitle: worked,
      );
    });
    return uuid;
  }

  static String? _clean(String? text) {
    final String? trimmed = text?.trim();
    return trimmed == null || trimmed.isEmpty ? null : trimmed;
  }

  String get _now => _ctx.clock.now().toUtc().toIso8601String();
}
