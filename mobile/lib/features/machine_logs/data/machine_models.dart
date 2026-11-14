import 'dart:convert';

import 'package:equatable/equatable.dart';

import '../../../core/db/app_database.dart';
import '../../../core/money/money.dart';

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? <Map<String, dynamic>>[
        for (final Object? row in value)
          if (row is Map) row.cast<String, dynamic>(),
      ]
    : const <Map<String, dynamic>>[];

String? _text(Object? raw) {
  final String? text = raw?.toString();
  return text == null || text.trim().isEmpty ? null : text;
}

/// A figure the server sent as a string such as `"8.500"`. Null when it is
/// missing or not a number, never a crash: this is read on every sync.
Hours? _hours(Object? raw) => raw == null ? null : Hours.tryParse(raw.toString());

Qty? _qty(Object? raw) => raw == null ? null : Qty.tryParse(raw.toString());

/// What `MachineLogPresenter::asset` sends. There is no rate, hire term or cost
/// in it, and this app shows none.
class Machine extends Equatable {
  const Machine({
    required this.id,
    required this.code,
    required this.name,
    this.category,
    this.ownership,
    this.registrationNo,
    this.meterUnit,
    this.projectId,
    this.status,
  });

  /// Null when the row has no id, which nothing could be logged against.
  static Machine? tryFromJson(Map<String, dynamic> json) {
    final int? id = (json['id'] as num?)?.toInt();
    if (id == null) return null;
    return Machine(
      id: id,
      code: _text(json['code']) ?? '',
      name: _text(json['name']) ?? '',
      category: _text(json['category']),
      ownership: _text(json['ownership']),
      registrationNo: _text(json['registration_no']),
      meterUnit: _text(json['meter_unit']),
      projectId: (json['project_id'] as num?)?.toInt(),
      status: _text(json['status']),
    );
  }

  final int id;
  final String code;
  final String name;
  final String? category;
  final String? ownership;
  final String? registrationNo;

  /// `hours`, `km` or null: what the machine's meter counts. Readings give the
  /// worked figure only when this is set.
  final String? meterUnit;

  /// The project it works on, or null for one shared between projects.
  final int? projectId;
  final String? status;

  bool get meterIsHours => meterUnit == 'hours';
  bool get meterIsKm => meterUnit == 'km';
  bool get underRepair => status == 'under_repair';

  /// `AST-0001 · JCB 3DX`.
  String get displayName => <String>[code, name].where((String part) => part.isNotEmpty).join(' · ');

  @override
  List<Object?> get props => <Object?>[id, code, name, category, ownership, registrationNo, meterUnit, projectId, status];
}

/// One day's work of a machine: as the server holds it, or as typed on this
/// phone and still waiting to be sent ([unsent]).
class MachineLog extends Equatable {
  const MachineLog({
    required this.assetId,
    required this.logDate,
    this.id,
    this.clientUuid,
    this.projectId,
    this.openingReading,
    this.closingReading,
    this.hours,
    this.km,
    this.breakdownHours,
    this.operatorName,
    this.remarks,
    this.unsent,
  });

  factory MachineLog.fromJson(Map<String, dynamic> json) => MachineLog(
    id: (json['id'] as num?)?.toInt(),
    clientUuid: _text(json['client_uuid']),
    assetId: (json['asset_id'] as num?)?.toInt() ?? 0,
    projectId: (json['project_id'] as num?)?.toInt(),
    logDate: _text(json['log_date']) ?? '',
    openingReading: _qty(json['opening_reading']),
    closingReading: _qty(json['closing_reading']),
    hours: _hours(json['hours']),
    km: _qty(json['km']),
    breakdownHours: _hours(json['breakdown_hours']),
    operatorName: _text(json['operator']),
    remarks: _text(json['remarks']),
  );

  /// A log typed on this phone that has not reached the server, worked out from
  /// the payload in the outbox. Never throws: a damaged payload still shows as a row.
  factory MachineLog.fromOutbox(OutboxRow row) {
    Map<String, dynamic> data = const <String, dynamic>{};
    try {
      final Object? decoded = jsonDecode(row.payload);
      if (decoded is Map) data = decoded.cast<String, dynamic>();
    } on FormatException {
      // Shown by what is known.
    }
    return MachineLog(
      clientUuid: row.wireUuid,
      assetId: (data['asset_id'] as num?)?.toInt() ?? 0,
      projectId: row.projectId,
      logDate: _text(data['log_date']) ?? '',
      openingReading: _qty(data['opening_reading']),
      closingReading: _qty(data['closing_reading']),
      hours: _hours(data['hours']),
      km: _qty(data['km']),
      breakdownHours: _hours(data['breakdown_hours']),
      operatorName: _text(data['operator']),
      remarks: _text(data['remarks']),
      unsent: row,
    );
  }

  final int? id;
  final String? clientUuid;
  final int assetId;
  final int? projectId;

  /// `YYYY-MM-DD`.
  final String logDate;
  final Qty? openingReading;
  final Qty? closingReading;

  /// What was worked. Null on a waiting log that left it to the meter readings.
  final Hours? hours;
  final Qty? km;
  final Hours? breakdownHours;
  final String? operatorName;
  final String? remarks;

  /// The outbox row of a log that has not been sent.
  final OutboxRow? unsent;

  bool get notSent => unsent != null;
  bool get needsAttention => unsent?.status == OutboxStatus.attention;

  /// The worked hours count only when above zero.
  bool get hasHours => hours != null && !hours!.isZero;
  bool get hasKm => km != null && !km!.isZero;
  bool get hasBreakdown => breakdownHours != null && !breakdownHours!.isZero;

  String get key => id != null ? '$id' : (clientUuid ?? '');

  @override
  List<Object?> get props => <Object?>[
    id,
    clientUuid,
    assetId,
    projectId,
    logDate,
    openingReading,
    closingReading,
    hours,
    km,
    breakdownHours,
    operatorName,
    remarks,
    unsent,
  ];
}

/// What this phone last read for a project: the machines and the latest logs.
class MachineOverview extends Equatable {
  const MachineOverview({
    this.machines = const <Machine>[],
    this.logs = const <MachineLog>[],
    this.more = false,
    this.refused = false,
    this.fetchedAt,
  });

  factory MachineOverview.fromJson(Map<String, dynamic> json) => MachineOverview(
    refused: json['refused'] == true,
    machines: <Machine>[
      for (final Map<String, dynamic> row in _rows(json['machines'])) ?Machine.tryFromJson(row),
    ],
    logs: <MachineLog>[for (final Map<String, dynamic> row in _rows(json['logs'])) MachineLog.fromJson(row)],
    more: json['more'] == true,
    fetchedAt: DateTime.tryParse(json['fetched_at']?.toString() ?? ''),
  );

  final List<Machine> machines;
  final List<MachineLog> logs;

  /// The list holds the latest page only.
  final bool more;

  /// The server said this role or plan does not have machine logs.
  final bool refused;
  final DateTime? fetchedAt;

  Machine? machine(int id) {
    for (final Machine row in machines) {
      if (row.id == id) return row;
    }
    return null;
  }

  int get unsent => logs.where((MachineLog row) => row.notSent).length;

  /// Hours already logged for [assetId] on [date], from what the phone knows,
  /// waiting logs included. The server caps one machine at 24 hours a day.
  Hours hoursOn(int assetId, String date) {
    var total = Hours.zero;
    for (final MachineLog row in logs) {
      if (row.assetId == assetId && row.logDate == date && row.hours != null) total = total + row.hours!;
    }
    return total;
  }

  /// Logs typed on this phone go on top, newest first. One the server has
  /// already stored is not shown twice while its reply is being resent.
  MachineOverview withUnsent(List<OutboxRow> rows) {
    final Set<String?> known = <String?>{for (final MachineLog row in logs) row.clientUuid};
    final List<MachineLog> waiting = <MachineLog>[
      for (final OutboxRow row in rows.reversed)
        if (row.type == OutboxType.machineLog && !known.contains(row.wireUuid)) MachineLog.fromOutbox(row),
    ];
    if (waiting.isEmpty) return this;
    return MachineOverview(
      machines: machines,
      logs: <MachineLog>[...waiting, ...logs],
      more: more,
      refused: refused,
      fetchedAt: fetchedAt,
    );
  }

  @override
  List<Object?> get props => <Object?>[machines, logs, more, refused, fetchedAt];
}
