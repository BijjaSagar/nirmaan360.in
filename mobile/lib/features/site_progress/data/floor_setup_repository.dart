import '../../../core/api/api_failure.dart';
import '../../../core/api/nirmaan_api.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/ids.dart';
import 'progress_repository.dart';

export 'progress_models.dart';

// Setting up floors and segments, planning what is meant to be done on each,
// the project's time frame and the weekly view. Online only, by design: a
// floor is named and planned against what the office holds now, so nothing
// waits in the outbox. Quantities and percentages only, never a rate.

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

Qty _qty(Object? value) => Qty.tryParse(_text(value)) ?? Qty.zero;

/// The project's time frame, as the progress summary carries it.
class TimeFrame {
  const TimeFrame({this.startDate, this.plannedDays, this.deadlineDate});

  factory TimeFrame.fromSummary(Map<String, dynamic> json) => TimeFrame(
    startDate: _text(json['start_date']),
    plannedDays: _intOrNull(json['planned_days']),
    deadlineDate: _text(json['deadline_date']),
  );

  /// `yyyy-mm-dd`, or null when not set.
  final String? startDate;
  final int? plannedDays;
  final String? deadlineDate;

  bool get isEmpty => startDate == null && plannedDays == null && deadlineDate == null;
}

/// Everything the set-up screen shows: the floors with their segments, the BOQ
/// lines that can be planned on them, and the time frame.
class FloorSetup {
  const FloorSetup({required this.floors, required this.items, required this.timeFrame});

  final List<ProgressFloor> floors;
  final List<ProgressItem> items;
  final TimeFrame timeFrame;

  /// Floors and segments in the order they are shown, parents first.
  List<FloorChoice> get choices => <FloorChoice>[
    for (final ProgressFloor floor in floors) ...<FloorChoice>[
      FloorChoice(floor),
      for (final ProgressFloor child in floor.children) FloorChoice(child, parentName: floor.name),
    ],
  ];
}

/// One floor's plan: what is planned and done for each BOQ line on it. A line
/// with no target has no entry in [planned].
class FloorPlan {
  const FloorPlan({required this.floor, required this.planned, required this.done});

  factory FloorPlan.fromJson(Map<String, dynamic> json) {
    final Map<int, Qty> planned = <int, Qty>{};
    final Map<int, Qty> done = <int, Qty>{};
    for (final Map<String, dynamic> line in _rows(json['lines'])) {
      final int? id = _intOrNull(line['boq_item_id']);
      if (id == null) continue;
      final Qty? target = Qty.tryParse(_text(line['planned_qty']));
      if (target != null) planned[id] = target;
      done[id] = _qty(line['done_qty']);
    }
    return FloorPlan(floor: ProgressFloor.fromJson(json), planned: planned, done: done);
  }

  final ProgressFloor floor;
  final Map<int, Qty> planned;
  final Map<int, Qty> done;
}

/// A BOQ line worked on during the week, and where.
class WeekLine {
  const WeekLine({required this.item, required this.weekQty, required this.floors});

  factory WeekLine.fromJson(Map<String, dynamic> json) => WeekLine(
    item: ProgressItem.fromJson(json),
    weekQty: _qty(json['week_qty']),
    floors: <({String name, Qty qty})>[
      for (final Map<String, dynamic> floor in _rows(json['floors'])) (name: _text(floor['name']) ?? '—', qty: _qty(floor['qty'])),
    ],
  );

  final ProgressItem item;
  final Qty weekQty;
  final List<({String name, Qty qty})> floors;
}

/// One week, Monday to Sunday: what was done, what is pending, what is under way.
class ProgressWeek {
  const ProgressWeek({
    required this.start,
    required this.end,
    required this.isCurrent,
    required this.entryCount,
    required this.done,
    required this.pending,
    required this.next,
    required this.nextMore,
    required this.notStarted,
  });

  factory ProgressWeek.fromJson(Map<String, dynamic> json) => ProgressWeek(
    start: _text(json['start']) ?? '',
    end: _text(json['end']) ?? '',
    isCurrent: json['is_current'] == true,
    entryCount: _intOrNull(json['entry_count']) ?? 0,
    done: _rows(json['done']).map(WeekLine.fromJson).toList(),
    pending: _rows(json['pending']).map(PendingEntry.fromJson).toList(),
    next: _rows(json['next']).map(ProgressItem.fromJson).toList(),
    nextMore: _intOrNull(json['next_more']) ?? 0,
    notStarted: _intOrNull(json['not_started']) ?? 0,
  );

  /// `yyyy-mm-dd` of the Monday and the Sunday.
  final String start;
  final String end;
  final bool isCurrent;
  final int entryCount;
  final List<WeekLine> done;
  final List<PendingEntry> pending;

  /// Work under way, furthest along first.
  final List<ProgressItem> next;
  final int nextMore;
  final int notStarted;
}

class FloorSetupRepository {
  FloorSetupRepository(this._api, this._progress);

  final NirmaanApi _api;
  final ProgressRepository _progress;

  /// The client_uuid of an add the server has not answered yet, by what was
  /// asked, so a retry after a lost answer returns the first floor.
  final Map<String, String> _unanswered = <String, String>{};

  Future<FloorSetup> load(int projectId) async {
    final List<Object> results = await Future.wait<Object>(<Future<Object>>[
      _api.progressFloors(projectId),
      _api.progressItems(projectId),
      _api.progressSummary(projectId),
    ]);
    return FloorSetup(
      floors: (results[0] as List<Map<String, dynamic>>).map(ProgressFloor.fromJson).toList(),
      items: (results[1] as List<Map<String, dynamic>>).map(ProgressItem.fromJson).toList(),
      timeFrame: TimeFrame.fromSummary(results[2] as Map<String, dynamic>),
    );
  }

  /// Adds a floor, or a segment under [parentId]. Returns the new floor's id.
  Future<int> addFloor(int projectId, String name, {int? parentId}) async {
    final String key = '$projectId|${parentId ?? ''}|${name.trim().toLowerCase()}';
    final String uuid = _unanswered.putIfAbsent(key, newUuid);
    try {
      final Map<String, dynamic> row = await _api.addFloor(projectId, name: name.trim(), parentId: parentId, clientUuid: uuid);
      _unanswered.remove(key);
      return _intOrNull(row['id']) ?? 0;
    } on ApiFailure catch (failure) {
      // Only a request that may not have arrived keeps its uuid for the retry.
      if (failure is! OfflineFailure) _unanswered.remove(key);
      rethrow;
    }
  }

  Future<void> rename(int floorId, String name) => _api.renameFloor(floorId, name: name.trim());

  Future<FloorPlan> plan(int floorId) async => FloorPlan.fromJson(await _api.floorProgress(floorId));

  /// Zero removes the target. Returns the floor's plan as the server now holds it.
  Future<FloorPlan> setTarget(int floorId, int boqItemId, Qty planned) async =>
      FloorPlan.fromJson(await _api.setFloorTarget(floorId, boqItemId: boqItemId, plannedQty: planned.toWire()));

  /// Shares whole BOQ lines equally across [floorIds]. Returns how many lines were shared.
  Future<int> split(int projectId, {required List<int> floorIds, List<int> boqItemIds = const <int>[], bool allItems = false}) async {
    final Map<String, dynamic> body = await _api.splitFloors(projectId, floorIds: floorIds, boqItemIds: boqItemIds, allItems: allItems);
    return _intOrNull(body['count']) ?? 0;
  }

  /// For the company owner. The server refuses anyone else, and any floor with
  /// work on it. A floor already gone (a retry after a lost answer, or removed
  /// by someone else meanwhile) is what was asked for, so it is not a failure.
  Future<void> delete(int floorId) async {
    try {
      await _api.deleteFloor(floorId);
    } on ServerFailure catch (failure) {
      if (failure.statusCode != 404) rethrow;
    }
  }

  Future<TimeFrame> setTimeFrame(int projectId, TimeFrame frame) async => TimeFrame.fromSummary(
    await _api.setProgressTimeFrame(
      projectId,
      startDate: frame.startDate,
      plannedDays: frame.plannedDays,
      deadlineDate: frame.deadlineDate,
    ),
  );

  Future<ProgressWeek> week(int projectId, {String? date}) async => ProgressWeek.fromJson(_map(await _api.progressWeek(projectId, date: date)));

  /// Brings the progress screens' copy up to date after a change. Never throws:
  /// the change is on the server either way, and the next refresh catches up.
  Future<void> refreshProgress(int projectId) async {
    try {
      await _progress.refresh(projectId);
    } on ApiFailure {
      // Shown as it was until the next refresh.
    }
  }
}
