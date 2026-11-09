// What the phone knows about site progress. The server sends quantities and
// percentages only: nothing here can hold a rate, an amount or a budget.
import 'dart:convert';

import '../../../core/db/app_database.dart' show OutboxRow, OutboxType;
import '../../../core/money/money.dart';

Map<String, dynamic> _map(Object? value) => value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? value.whereType<Map>().map((row) => row.cast<String, dynamic>()).toList()
    : const <Map<String, dynamic>>[];

String? _text(Object? value) {
  if (value == null) return null;
  final String text = value.toString().trim();
  return text.isEmpty ? null : text;
}

int? _intOrNull(Object? value) => value is num ? value.toInt() : int.tryParse(value?.toString() ?? '');

int _int(Object? value) => _intOrNull(value) ?? 0;

Qty _qty(Object? value) => Qty.tryParse(_text(value)) ?? Qty.zero;

/// "42.50" as "42.5%", "40.00" as "40%". Null as a dash.
String percentText(String? raw) {
  if (raw == null) return '—';
  var text = raw.trim();
  if (text.contains('.')) {
    text = text.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
  }
  return '$text%';
}

class ProgressSummary {
  const ProgressSummary({
    required this.workPercent,
    this.timePercent,
    this.lag,
    this.status,
    this.daysToDeadline,
    this.openPending = 0,
  });

  factory ProgressSummary.fromJson(Map<String, dynamic> json) => ProgressSummary(
    workPercent: _text(json['work_percent']),
    timePercent: _text(json['time_percent']),
    lag: _text(json['lag']),
    status: _text(json['status']),
    daysToDeadline: _intOrNull(json['days_to_deadline']),
    openPending: _int(json['open_pending']),
  );

  final String? workPercent;
  final String? timePercent;

  /// Percentage points behind the calendar. Null when no dates are set.
  final String? lag;

  /// `behind`, `on_track`, or null when there is nothing to compare with.
  final String? status;
  final int? daysToDeadline;
  final int openPending;

  bool get isBehind => status == 'behind';
}

class ProgressFloor {
  const ProgressFloor({
    required this.id,
    required this.name,
    this.parentId,
    this.percent,
    this.targets = 0,
    this.pending = 0,
    this.mepPending = 0,
    this.children = const <ProgressFloor>[],
  });

  factory ProgressFloor.fromJson(Map<String, dynamic> json) => ProgressFloor(
    id: _int(json['id']),
    name: _text(json['name']) ?? '',
    parentId: _intOrNull(json['parent_id']),
    percent: _text(json['percent']),
    targets: _int(json['targets']),
    pending: _int(json['pending']),
    mepPending: _int(json['mep_pending']),
    children: _rows(json['children']).map(ProgressFloor.fromJson).toList(),
  );

  final int id;
  final String name;
  final int? parentId;
  final String? percent;
  final int targets;
  final int pending;
  final int mepPending;
  final List<ProgressFloor> children;
}

/// One choice in the floor picker.
class FloorChoice {
  const FloorChoice(this.floor, {this.parentName});

  final ProgressFloor floor;
  final String? parentName;

  bool get isFlat => parentName != null;

  String get label => parentName == null ? floor.name : '$parentName › ${floor.name}';
}

/// A contractor working on a BOQ line that more than one contractor shares.
class ProgressWorkOrder {
  const ProgressWorkOrder({required this.id, required this.label});

  factory ProgressWorkOrder.fromJson(Map<String, dynamic> json) =>
      ProgressWorkOrder(id: _int(json['id']), label: _text(json['label']) ?? '');

  final int id;
  final String label;
}

class ProgressItem {
  const ProgressItem({
    required this.id,
    this.section,
    required this.code,
    required this.description,
    required this.unit,
    required this.qty,
    required this.done,
    required this.balance,
    this.percent,
    this.workOrders = const <ProgressWorkOrder>[],
  });

  factory ProgressItem.fromJson(Map<String, dynamic> json) => ProgressItem(
    id: _int(json['id']),
    section: _text(json['section']),
    code: _text(json['code']) ?? '',
    description: _text(json['description']) ?? '',
    unit: _text(json['unit']) ?? '',
    qty: _qty(json['qty']),
    done: _qty(json['done_qty']),
    balance: _qty(json['balance_qty']),
    percent: _text(json['percent']),
    workOrders: _rows(json['work_orders']).map(ProgressWorkOrder.fromJson).toList(),
  );

  final int id;
  final String? section;
  final String code;
  final String description;
  final String unit;
  final Qty qty;
  final Qty done;
  final Qty balance;
  final String? percent;

  /// Empty unless several contractors share this line. When it is not empty
  /// the server refuses a quantity that does not say whose work it is.
  final List<ProgressWorkOrder> workOrders;

  ProgressItem plusUnsent(Qty added) => ProgressItem(
    id: id,
    section: section,
    code: code,
    description: description,
    unit: unit,
    qty: qty,
    done: done + added,
    balance: balance - added,
    percent: percent,
    workOrders: workOrders,
  );
}

class PendingEntry {
  const PendingEntry({
    this.id,
    this.itemCode,
    this.itemDescription,
    this.floorName,
    this.remark,
    this.workDate,
    this.enteredBy,
  });

  factory PendingEntry.fromJson(Map<String, dynamic> json) => PendingEntry(
    id: _intOrNull(json['id']),
    itemCode: _text(json['item_code']),
    itemDescription: _text(json['item_description']),
    floorName: _text(json['floor_name']),
    remark: _text(json['remark']),
    workDate: _text(json['work_date']),
    enteredBy: _text(json['entered_by_name']),
  );

  /// Null until the entry has reached the server.
  final int? id;
  final String? itemCode;
  final String? itemDescription;
  final String? floorName;
  final String? remark;
  final String? workDate;
  final String? enteredBy;

  bool get notSentYet => id == null;
}

/// A correction of an entry that is still on this phone, waiting to go.
class PendingCorrection {
  const PendingCorrection({required this.qty, required this.remark});

  final Qty qty;
  final String remark;
}

/// An entry that recorded work on a floor, with the corrections the server
/// holds for it added in.
class FloorEntry {
  const FloorEntry({
    required this.id,
    required this.boqItemId,
    required this.entered,
    required this.net,
    this.workDate,
    this.remark,
    this.enteredBy,
    this.corrections = 0,
    this.pending,
  });

  final int id;
  final int boqItemId;
  final String? workDate;

  /// What was first written.
  final Qty entered;

  /// What the server counts for it now.
  final Qty net;
  final String? remark;
  final String? enteredBy;

  /// How many corrections the server has already taken.
  final int corrections;
  final PendingCorrection? pending;

  /// What the screen shows. A correction waiting to send counts, so the list
  /// agrees with the totals, which count it too.
  Qty get shown => pending?.qty ?? net;

  bool get wasCorrected => corrections > 0;
}

/// The latest entries on a floor that can still be corrected, newest first.
class RecentEntries {
  const RecentEntries(this.entries);

  /// [rows] are the server's entries for the floor and [unsent] this phone's
  /// outbox rows. Corrections, resolutions and entries with no quantity are
  /// folded in or left out: the server corrects only an entry that recorded work.
  factory RecentEntries.build(List<Map<String, dynamic>> rows, List<OutboxRow> unsent, {int limit = 30}) {
    final Map<int, Qty> corrected = <int, Qty>{};
    final Map<int, int> counts = <int, int>{};
    for (final Map<String, dynamic> row in rows) {
      final int? target = _intOrNull(row['corrects_entry_id']);
      if (target == null) continue;
      corrected[target] = (corrected[target] ?? Qty.zero) + _qty(row['qty_done']);
      counts[target] = (counts[target] ?? 0) + 1;
    }

    final Map<int, PendingCorrection> waiting = <int, PendingCorrection>{};
    for (final OutboxRow row in unsent) {
      if (row.type != OutboxType.progressCorrection) continue;
      final Map<String, dynamic> data = _map(jsonDecode(row.payload));
      waiting[_int(data['entry_id'])] = PendingCorrection(qty: _qty(data['qty_done']), remark: _text(data['remark']) ?? '');
    }

    final List<Map<String, dynamic>> newestFirst = <Map<String, dynamic>>[...rows]
      ..sort((a, b) => _int(b['id']).compareTo(_int(a['id'])));
    final List<FloorEntry> entries = <FloorEntry>[];
    for (final Map<String, dynamic> row in newestFirst) {
      if (row['corrects_entry_id'] != null || row['resolves_entry_id'] != null) continue;
      final Qty entered = _qty(row['qty_done']);
      if (entered.isZero) continue;
      final int id = _int(row['id']);
      entries.add(
        FloorEntry(
          id: id,
          boqItemId: _int(row['boq_item_id']),
          workDate: _text(row['work_date']),
          entered: entered,
          net: entered + (corrected[id] ?? Qty.zero),
          remark: _text(row['remark']),
          enteredBy: _text(row['entered_by_name']),
          corrections: counts[id] ?? 0,
          pending: waiting[id],
        ),
      );
      if (entries.length >= limit) break;
    }
    return RecentEntries(entries);
  }

  final List<FloorEntry> entries;

  bool get isEmpty => entries.isEmpty;
}

class StageDef {
  const StageDef({required this.key, required this.no, required this.label, this.hint, this.optional = false});

  factory StageDef.fromJson(Map<String, dynamic> json) => StageDef(
    key: _text(json['key']) ?? '',
    no: _int(json['no']),
    label: _text(json['label']) ?? '',
    hint: _text(json['hint']),
    optional: json['optional'] == true,
  );

  final String key;
  final int no;
  final String label;
  final String? hint;

  /// Only an optional stage may be marked not applicable.
  final bool optional;
}

class StageTrade {
  const StageTrade({required this.key, required this.label, required this.stages});

  factory StageTrade.fromJson(Map<String, dynamic> json) => StageTrade(
    key: _text(json['key']) ?? '',
    label: _text(json['label']) ?? '',
    stages: _rows(json['stages']).map(StageDef.fromJson).toList(),
  );

  final String key;
  final String label;
  final List<StageDef> stages;
}

class StageStatusOption {
  const StageStatusOption(this.key, this.label);

  final String key;
  final String label;
}

class StageCell {
  const StageCell({this.status = 'not_started', this.remark, this.warning = false});

  factory StageCell.fromJson(Map<String, dynamic> json) =>
      StageCell(status: _text(json['status']) ?? 'not_started', remark: _text(json['remark']));

  final String status;
  final String? remark;

  /// Done, while something before it on the same row is still pending.
  final bool warning;
}

class StageRow {
  const StageRow({
    required this.id,
    required this.name,
    required this.label,
    this.parentId,
    this.level = 0,
    required this.cells,
    this.pending = 0,
  });

  factory StageRow.fromJson(Map<String, dynamic> json) {
    final Map<String, Map<String, StageCell>> cells = <String, Map<String, StageCell>>{};
    _map(json['cells']).forEach((trade, stages) {
      cells[trade] = <String, StageCell>{
        for (final MapEntry<String, dynamic> stage in _map(stages).entries)
          stage.key: StageCell.fromJson(_map(stage.value)),
      };
    });
    return StageRow(
      id: _int(json['id']),
      name: _text(json['name']) ?? '',
      label: _text(json['label']) ?? _text(json['name']) ?? '',
      parentId: _intOrNull(json['parent_id']),
      level: _int(json['level']),
      cells: cells,
    );
  }

  final int id;
  final String name;
  final String label;
  final int? parentId;
  final int level;

  /// Trade key to stage key to cell.
  final Map<String, Map<String, StageCell>> cells;
  final int pending;

  StageCell cell(String trade, String stage) => cells[trade]?[stage] ?? const StageCell();
}

/// One open line on the check to read before plaster, POP or paint.
class FinishingLine {
  const FinishingLine({
    required this.rowLabel,
    required this.floorId,
    required this.trade,
    required this.stage,
    required this.status,
    this.remark,
  });

  final String rowLabel;
  final int floorId;
  final StageTrade trade;
  final StageDef stage;
  final String status;
  final String? remark;
}

class BeforeFinishing {
  const BeforeFinishing({required this.floorName, required this.pending, required this.unfinished});

  final String floorName;
  final List<FinishingLine> pending;
  final List<FinishingLine> unfinished;

  bool get ready => pending.isEmpty && unfinished.isEmpty;
}

class StageMatrix {
  const StageMatrix({required this.trades, required this.statuses, required this.rows});

  factory StageMatrix.fromJson(Map<String, dynamic> json) => StageMatrix(
    trades: _rows(json['trades']).map(StageTrade.fromJson).toList(),
    statuses: <StageStatusOption>[
      for (final Map<String, dynamic> row in _rows(json['statuses']))
        StageStatusOption(_text(row['key']) ?? '', _text(row['label']) ?? ''),
    ],
    rows: _rows(json['rows']).map(StageRow.fromJson).toList(),
  ).recounted();

  final List<StageTrade> trades;
  final List<StageStatusOption> statuses;
  final List<StageRow> rows;

  String statusLabel(String key) {
    for (final StageStatusOption option in statuses) {
      if (option.key == key) return option.label;
    }
    return key;
  }

  StageTrade? trade(String key) {
    for (final StageTrade trade in trades) {
      if (trade.key == key) return trade;
    }
    return null;
  }

  StageRow? row(int floorId) {
    for (final StageRow row in rows) {
      if (row.id == floorId) return row;
    }
    return null;
  }

  /// How many stages of [trade] are done on [row], and how many count.
  ({int done, int total}) tally(StageRow row, StageTrade trade) {
    var done = 0;
    var total = 0;
    for (final StageDef stage in trade.stages) {
      final String status = row.cell(trade.key, stage.key).status;
      if (status != 'na') total++;
      if (status == 'done') done++;
    }
    return (done: done, total: total);
  }

  /// Works out the pending count and the out-of-order warnings the way the
  /// office screen does, so a change waiting to send shows correctly.
  StageMatrix recounted() {
    final List<StageRow> counted = <StageRow>[
      for (final StageRow row in rows)
        () {
          var pending = 0;
          final Map<String, Map<String, StageCell>> cells = <String, Map<String, StageCell>>{};
          for (final StageTrade trade in trades) {
            var pendingBefore = false;
            final Map<String, StageCell> stages = <String, StageCell>{};
            for (final StageDef stage in trade.stages) {
              final StageCell cell = row.cell(trade.key, stage.key);
              stages[stage.key] = StageCell(
                status: cell.status,
                remark: cell.remark,
                warning: cell.status == 'done' && pendingBefore,
              );
              if (cell.status == 'pending') {
                pendingBefore = true;
                pending++;
              }
            }
            cells[trade.key] = stages;
          }
          return StageRow(
            id: row.id,
            name: row.name,
            label: row.label,
            parentId: row.parentId,
            level: row.level,
            cells: cells,
            pending: pending,
          );
        }(),
    ];
    return StageMatrix(trades: trades, statuses: statuses, rows: counted);
  }

  /// A floor and its flats, read from this phone, so the check works with no
  /// network at all.
  BeforeFinishing beforeFinishing(int floorId) {
    final StageRow? floor = row(floorId);
    final List<StageRow> scope = <StageRow>[
      ?floor,
      for (final StageRow candidate in rows)
        if (candidate.parentId == floorId) candidate,
    ];
    final List<FinishingLine> pending = <FinishingLine>[];
    final List<FinishingLine> unfinished = <FinishingLine>[];
    for (final StageRow current in scope) {
      for (final StageTrade trade in trades) {
        for (final StageDef stage in trade.stages) {
          final StageCell cell = current.cell(trade.key, stage.key);
          if (cell.status == 'done' || cell.status == 'na') continue;
          final FinishingLine line = FinishingLine(
            rowLabel: current.label,
            floorId: current.id,
            trade: trade,
            stage: stage,
            status: cell.status,
            remark: cell.remark,
          );
          (cell.status == 'pending' ? pending : unfinished).add(line);
        }
      }
    }
    return BeforeFinishing(floorName: floor?.name ?? '', pending: pending, unfinished: unfinished);
  }
}

class FloorLine {
  const FloorLine({
    required this.boqItemId,
    required this.code,
    required this.description,
    required this.unit,
    required this.planned,
    required this.done,
    required this.balance,
    this.percent,
  });

  factory FloorLine.fromJson(Map<String, dynamic> json) => FloorLine(
    boqItemId: _int(json['boq_item_id']),
    code: _text(json['code']) ?? '',
    description: _text(json['description']) ?? '',
    unit: _text(json['unit']) ?? '',
    planned: _qty(json['planned_qty']),
    done: _qty(json['done_qty']),
    balance: _qty(json['balance_qty']),
    percent: _text(json['percent']),
  );

  final int boqItemId;
  final String code;
  final String description;
  final String unit;
  final Qty planned;
  final Qty done;
  final Qty balance;
  final String? percent;
}

class FloorDetail {
  const FloorDetail({required this.floor, required this.lines, required this.pending});

  factory FloorDetail.fromJson(Map<String, dynamic> json) => FloorDetail(
    floor: ProgressFloor.fromJson(json),
    lines: _rows(json['lines']).map(FloorLine.fromJson).toList(),
    pending: _rows(json['pending_entries']).map(PendingEntry.fromJson).toList(),
  );

  final ProgressFloor floor;
  final List<FloorLine> lines;
  final List<PendingEntry> pending;
}

/// Everything the progress screens read, as last fetched from the server,
/// with the work still waiting to send laid over it.
class ProgressOverview {
  const ProgressOverview({
    required this.summary,
    required this.floors,
    required this.items,
    required this.pending,
    required this.matrix,
    this.fetchedAt,
    this.unsent = 0,
  });

  factory ProgressOverview.fromJson(Map<String, dynamic> json) => ProgressOverview(
    summary: ProgressSummary.fromJson(_map(json['summary'])),
    floors: _rows(json['floors']).map(ProgressFloor.fromJson).toList(),
    items: _rows(json['items']).map(ProgressItem.fromJson).toList(),
    pending: _rows(json['pending']).map(PendingEntry.fromJson).toList(),
    matrix: StageMatrix.fromJson(_map(json['matrix'])),
    fetchedAt: DateTime.tryParse(_text(json['fetched_at']) ?? ''),
  );

  final ProgressSummary summary;
  final List<ProgressFloor> floors;
  final List<ProgressItem> items;
  final List<PendingEntry> pending;
  final StageMatrix matrix;
  final DateTime? fetchedAt;

  /// Entries, resolutions and stage changes not yet on the server.
  final int unsent;

  List<FloorChoice> get floorChoices => <FloorChoice>[
    for (final ProgressFloor floor in floors) ...<FloorChoice>[
      FloorChoice(floor),
      for (final ProgressFloor flat in floor.children) FloorChoice(flat, parentName: floor.name),
    ],
  ];

  ProgressItem? item(int id) {
    for (final ProgressItem item in items) {
      if (item.id == id) return item;
    }
    return null;
  }

  ProgressFloor? floor(int id) {
    for (final FloorChoice choice in floorChoices) {
      if (choice.floor.id == id) return choice.floor;
    }
    return null;
  }

  FloorChoice? floorChoice(int id) {
    for (final FloorChoice choice in floorChoices) {
      if (choice.floor.id == id) return choice;
    }
    return null;
  }

  /// [rows] are the outbox rows of this project not yet finished, oldest first.
  ProgressOverview withUnsent(List<OutboxRow> rows) {
    if (rows.isEmpty) return this;

    final Map<int, Qty> added = <int, Qty>{};
    final Set<int> resolved = <int>{};
    final List<PendingEntry> localPending = <PendingEntry>[];
    final Map<String, StageCell> changed = <String, StageCell>{};
    var unsentCount = 0;

    for (final OutboxRow row in rows) {
      final Map<String, dynamic> data = _map(jsonDecode(row.payload));
      switch (row.type) {
        case OutboxType.progressEntry:
          unsentCount++;
          final int itemId = _int(data['boq_item_id']);
          added[itemId] = (added[itemId] ?? Qty.zero) + _qty(data['qty_done']);
          if (data['is_pending'] == true) {
            final ProgressItem? line = item(itemId);
            final int? floorId = _intOrNull(data['project_floor_id']);
            localPending.add(
              PendingEntry(
                itemCode: line?.code,
                itemDescription: line?.description,
                floorName: floorId == null ? null : floorChoice(floorId)?.label,
                remark: _text(data['remark']),
                workDate: _text(data['work_date']),
              ),
            );
          }
        case OutboxType.progressResolve:
          unsentCount++;
          resolved.add(_int(data['entry_id']));
        case OutboxType.progressCorrection:
          unsentCount++;
          final int correctedItem = _int(data['boq_item_id']);
          // The difference from what the server counts, the way the server will work it out.
          added[correctedItem] = (added[correctedItem] ?? Qty.zero) + (_qty(data['qty_done']) - _qty(data['net_before']));
        case OutboxType.stageCell:
          unsentCount++;
          changed['${_int(data['project_floor_id'])}|${_text(data['trade'])}|${_text(data['stage'])}'] = StageCell(
            status: _text(data['status']) ?? 'not_started',
            remark: _text(data['remark']),
          );
        default:
          break;
      }
    }

    final StageMatrix laid = StageMatrix(
      trades: matrix.trades,
      statuses: matrix.statuses,
      rows: <StageRow>[
        for (final StageRow row in matrix.rows)
          StageRow(
            id: row.id,
            name: row.name,
            label: row.label,
            parentId: row.parentId,
            level: row.level,
            cells: <String, Map<String, StageCell>>{
              for (final StageTrade trade in matrix.trades)
                trade.key: <String, StageCell>{
                  for (final StageDef stage in trade.stages)
                    stage.key: changed['${row.id}|${trade.key}|${stage.key}'] ?? row.cell(trade.key, stage.key),
                },
            },
          ),
      ],
    ).recounted();

    return ProgressOverview(
      summary: summary,
      floors: floors,
      items: <ProgressItem>[
        for (final ProgressItem line in items)
          added.containsKey(line.id) ? line.plusUnsent(added[line.id]!) : line,
      ],
      pending: <PendingEntry>[
        ...localPending,
        for (final PendingEntry entry in pending)
          if (!resolved.contains(entry.id)) entry,
      ],
      matrix: laid,
      fetchedAt: fetchedAt,
      unsent: unsentCount,
    );
  }
}
