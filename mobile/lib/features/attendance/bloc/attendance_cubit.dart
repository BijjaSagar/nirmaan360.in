import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../../core/design/design.dart' show HajiriValue, N360Pictograms;
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart' show dateString;
import '../data/attendance_repository.dart';

/// The default overtime when OT is tapped, and how the hours control steps.
final Hours defaultOvertimeHours = Hours.fromInt(2);
final Hours overtimeStep = Hours.parse('0.5');
final Hours overtimeMin = Hours.parse('0.5');
final Hours overtimeMax = Hours.fromInt(16);

/// What is written for a worker on one day.
class DayMark extends Equatable {
  const DayMark(this.value, this.otHours);

  final HajiriValue value;
  final Hours otHours;

  @override
  List<Object?> get props => [value, otHours];
}

HajiriValue? hajiriOf(String status) => switch (status) {
  AttendanceStatus.present => HajiriValue.present,
  AttendanceStatus.half => HajiriValue.half,
  AttendanceStatus.overtime => HajiriValue.overtime,
  AttendanceStatus.absent => HajiriValue.absent,
  _ => null,
};

String _statusOf(HajiriValue value) => switch (value) {
  HajiriValue.present => AttendanceStatus.present,
  HajiriValue.half => AttendanceStatus.half,
  HajiriValue.overtime => AttendanceStatus.overtime,
  HajiriValue.absent => AttendanceStatus.absent,
};

/// One entry of the flat muster list: a trade header or a worker.
sealed class MusterItem extends Equatable {
  const MusterItem();
}

final class MusterGroup extends MusterItem {
  const MusterGroup({required this.trade, required this.workerIds});

  final String trade;
  final List<int> workerIds;

  @override
  List<Object?> get props => [trade, workerIds];
}

final class MusterWorker extends MusterItem {
  const MusterWorker({
    required this.id,
    required this.name,
    required this.trade,
  });

  final int id;
  final String name;
  final String trade;

  @override
  List<Object?> get props => [id, name, trade];
}

/// The figures in the sticky header.
class MusterTotals extends Equatable {
  const MusterTotals({
    this.present = 0,
    this.half = 0,
    this.overtime = 0,
    this.absent = 0,
    this.left = 0,
  });

  final int present;
  final int half;
  final int overtime;
  final int absent;
  final int left;

  int get marked => present + half + overtime + absent;
  int get total => marked + left;

  @override
  List<Object?> get props => [present, half, overtime, absent, left];
}

enum AttendanceLoad { loading, ready, noProject, failed }

/// A one-shot message for the view, raised with a fresh [AttendanceState.noticeId].
sealed class AttendanceNotice {
  const AttendanceNotice();
}

final class MarkFailed extends AttendanceNotice {
  const MarkFailed();
}

final class AllPresentDone extends AttendanceNotice {
  const AllPresentDone(this.count);

  final int count;
}

class AttendanceState {
  const AttendanceState({
    required this.load,
    required this.today,
    required this.date,
    required this.recentDates,
    this.project,
    this.items = const [],
    this.marks = const {},
    this.notice,
    this.noticeId = 0,
  });

  final AttendanceLoad load;
  final String today;
  final String date;

  /// Newest first, starting at today. Never holds a future date.
  final List<String> recentDates;
  final ProjectRow? project;

  /// Keeps its identity until the roster itself changes, so the list can skip
  /// rebuilding when only marks move.
  final List<MusterItem> items;

  /// Marks on the screen, by worker id. Includes taps not yet confirmed.
  final Map<int, DayMark> marks;
  final AttendanceNotice? notice;
  final int noticeId;

  bool get isToday => date == today;

  int get workerCount => items.whereType<MusterWorker>().length;

  MusterTotals get totals {
    var present = 0, half = 0, overtime = 0, absent = 0;
    for (final mark in marks.values) {
      switch (mark.value) {
        case HajiriValue.present:
          present++;
        case HajiriValue.half:
          half++;
        case HajiriValue.overtime:
          overtime++;
        case HajiriValue.absent:
          absent++;
      }
    }
    final int marked = present + half + overtime + absent;
    return MusterTotals(
      present: present,
      half: half,
      overtime: overtime,
      absent: absent,
      left: (workerCount - marked).clamp(0, workerCount),
    );
  }

  AttendanceState copyWith({
    AttendanceLoad? load,
    String? date,
    ProjectRow? project,
    bool clearProject = false,
    List<MusterItem>? items,
    Map<int, DayMark>? marks,
    AttendanceNotice? notice,
    int? noticeId,
  }) {
    return AttendanceState(
      load: load ?? this.load,
      today: today,
      date: date ?? this.date,
      recentDates: recentDates,
      project: clearProject ? null : (project ?? this.project),
      items: items ?? this.items,
      marks: marks ?? this.marks,
      notice: notice,
      noticeId: noticeId ?? this.noticeId,
    );
  }
}

/// Runs the muster: optimistic marks, the rolling totals, and "All present".
///
/// [marks] on the state are confirmed rows plus any taps still being written,
/// so a tap never waits for the database and a stale stream event cannot undo
/// a newer tap.
class AttendanceCubit extends Cubit<AttendanceState> {
  AttendanceCubit(this._services)
    : super(
        AttendanceState(
          load: AttendanceLoad.loading,
          today: _services.context.today,
          date: _services.context.today,
          recentDates: _recent(_services.context.today),
        ),
      ) {
    _projectSub = _services.projects.watchCurrentProject().listen(
      _onProject,
      onError: _onStreamError,
    );
  }

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projectSub;
  StreamSubscription<List<AttendanceLine>>? _daySub;

  /// Marks as the database last reported them.
  Map<int, DayMark> _confirmed = const {};

  /// Marks the user made that the database has not reported back yet.
  final Map<int, DayMark> _optimistic = {};

  /// Written by "All present" but not yet shown, so the rows fill in a wave.
  final Set<int> _held = {};
  Timer? _waveTimer;

  /// Rough number of waves "All present" fills the screen in.
  static const int _waveSteps = 8;
  static const Duration _waveTick = Duration(milliseconds: 36);

  static List<String> _recent(String today, {int days = 7}) {
    final List<String> parts = today.split('-');
    final base = DateTime.utc(
      int.parse(parts[0]),
      int.parse(parts[1]),
      int.parse(parts[2]),
    );
    return <String>[
      for (var i = 0; i < days; i++)
        dateString(base.subtract(Duration(days: i))),
    ];
  }

  /// Re-subscribes after a load failure.
  void retry() {
    emit(state.copyWith(load: AttendanceLoad.loading));
    final project = state.project;
    if (project == null) {
      _projectSub?.cancel();
      _projectSub = _services.projects.watchCurrentProject().listen(
        _onProject,
        onError: _onStreamError,
      );
    } else {
      _subscribeDay(project.id);
    }
  }

  void _onProject(ProjectRow? project) {
    if (project == null) {
      _daySub?.cancel();
      _daySub = null;
      emit(state.copyWith(clearProject: true, load: AttendanceLoad.noProject));
      return;
    }
    if (state.project?.id == project.id && _daySub != null) {
      emit(state.copyWith(project: project));
      return;
    }
    _clearLocal();
    emit(state.copyWith(project: project, items: const [], marks: const {}));
    _subscribeDay(project.id);
  }

  void _clearLocal() {
    _waveTimer?.cancel();
    _waveTimer = null;
    _held.clear();
    _optimistic.clear();
    _confirmed = const {};
  }

  void _subscribeDay(int projectId) {
    _daySub?.cancel();
    final date = state.date;
    _daySub = _services.attendance
        .watchDay(projectId, date)
        .listen((lines) => _onDay(lines, date), onError: _onStreamError);
  }

  void _onStreamError(Object error, StackTrace stack) {
    emit(state.copyWith(load: AttendanceLoad.failed));
  }

  void _onDay(List<AttendanceLine> lines, String date) {
    if (date != state.date) return;

    final confirmed = <int, DayMark>{};
    for (final line in lines) {
      final mark = line.mark;
      if (mark == null) continue;
      final value = hajiriOf(mark.status);
      if (value != null) confirmed[line.worker.id] = DayMark(value, mark.otHours);
    }
    _confirmed = confirmed;

    // A tap is settled once the database reports the same value back.
    _optimistic.removeWhere((id, mark) => confirmed[id] == mark);

    final items = _groupItems(lines);
    emit(
      state.copyWith(
        load: AttendanceLoad.ready,
        items: _sameItems(state.items, items) ? state.items : items,
        marks: _merged(),
      ),
    );
  }

  bool _sameItems(List<MusterItem> a, List<MusterItem> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) return false;
    }
    return true;
  }

  /// Groups workers by trade, in the design system's trade order, with any
  /// trade the app has not heard of after the known ones.
  List<MusterItem> _groupItems(List<AttendanceLine> lines) {
    final byTrade = <String, List<AttendanceLine>>{};
    for (final line in lines) {
      byTrade.putIfAbsent(line.worker.trade, () => []).add(line);
    }
    final known = <String>[for (final p in N360Pictograms.trades) p.key];
    int rank(String trade) {
      final index = known.indexWhere(
        (k) => k.toLowerCase() == trade.trim().toLowerCase(),
      );
      return index == -1 ? known.length : index;
    }

    final trades = byTrade.keys.toList()
      ..sort((a, b) {
        final byRank = rank(a).compareTo(rank(b));
        return byRank != 0 ? byRank : a.compareTo(b);
      });

    return <MusterItem>[
      for (final trade in trades) ...[
        MusterGroup(
          trade: trade,
          workerIds: <int>[for (final l in byTrade[trade]!) l.worker.id],
        ),
        for (final line in byTrade[trade]!)
          MusterWorker(
            id: line.worker.id,
            name: line.worker.name,
            trade: line.worker.trade,
          ),
      ],
    ];
  }

  Map<int, DayMark> _merged() {
    final merged = Map<int, DayMark>.of(_confirmed);
    _optimistic.forEach((id, mark) => merged[id] = mark);
    _held.forEach(merged.remove);
    return merged;
  }

  void _show() => emit(state.copyWith(marks: _merged()));

  /// Moves to another recent day. Today and the six days before it only.
  void selectDate(String date) {
    if (date == state.date || !state.recentDates.contains(date)) return;
    _clearLocal();
    emit(state.copyWith(date: date, marks: const {}));
    final project = state.project;
    if (project != null) _subscribeDay(project.id);
  }

  /// Sets one worker's status. Returns once written. The screen has already
  /// moved on: the row paints the tap at once and the write follows.
  Future<void> mark(int workerId, HajiriValue value) async {
    final DayMark? current = _markOnScreen(workerId);
    final Hours hours;
    if (value == HajiriValue.overtime) {
      // Tapping OT again keeps hours the user already adjusted.
      hours = current?.value == HajiriValue.overtime
          ? current!.otHours
          : defaultOvertimeHours;
    } else {
      hours = Hours.zero;
    }
    final next = DayMark(value, hours);
    if (next == current) return;
    await _write(workerId, next);
  }

  /// Changes the hours on a worker already marked as overtime.
  Future<void> setOvertimeHours(int workerId, Hours hours) async {
    final current = _markOnScreen(workerId);
    if (current?.value != HajiriValue.overtime) return;
    if (hours < overtimeMin || hours > overtimeMax) return;
    final next = DayMark(HajiriValue.overtime, hours);
    if (next == current) return;
    await _write(workerId, next);
  }

  DayMark? _markOnScreen(int workerId) =>
      _optimistic[workerId] ?? _confirmed[workerId];

  Future<void> _write(int workerId, DayMark mark) async {
    final project = state.project;
    if (project == null) return;
    final date = state.date;
    _held.remove(workerId);
    _optimistic[workerId] = mark;
    _show();
    try {
      await _services.attendance.mark(
        projectId: project.id,
        workerId: workerId,
        date: date,
        status: _statusOf(mark.value),
        otHours: mark.value == HajiriValue.overtime ? mark.otHours : null,
      );
    } on Exception {
      if (isClosed || date != state.date) return;
      // Only undo this tap if a newer one has not replaced it.
      if (_optimistic[workerId] == mark) _optimistic.remove(workerId);
      emit(
        state.copyWith(
          marks: _merged(),
          notice: const MarkFailed(),
          noticeId: state.noticeId + 1,
        ),
      );
    }
  }

  /// Fills every worker with no mark yet as present. It never touches a worker
  /// who already has a mark, so an absent mark stays absent.
  ///
  /// With [animate] the rows fill top to bottom in under a third of a second.
  Future<void> markAllPresent({required bool animate}) async {
    final project = state.project;
    if (project == null) return;
    final date = state.date;

    final targets = <int>[
      for (final item in state.items)
        if (item is MusterWorker && _markOnScreen(item.id) == null) item.id,
    ];
    if (targets.isEmpty) return;

    final present = DayMark(HajiriValue.present, Hours.zero);
    for (final id in targets) {
      _optimistic[id] = present;
    }
    if (animate && targets.length > 1) {
      _startWave(targets);
    } else {
      _show();
    }

    try {
      await _services.attendance.markAllPresent(project.id, date);
      if (isClosed || date != state.date) return;
      emit(
        state.copyWith(
          notice: AllPresentDone(targets.length),
          noticeId: state.noticeId + 1,
        ),
      );
    } on Exception {
      if (isClosed || date != state.date) return;
      _waveTimer?.cancel();
      _waveTimer = null;
      _held.clear();
      targets.forEach(_optimistic.remove);
      emit(
        state.copyWith(
          marks: _merged(),
          notice: const MarkFailed(),
          noticeId: state.noticeId + 1,
        ),
      );
    }
  }

  void _startWave(List<int> targets) {
    _waveTimer?.cancel();
    _held
      ..clear()
      ..addAll(targets);
    final int perTick = (targets.length / _waveSteps).ceil();
    var next = 0;
    _waveTimer = Timer.periodic(_waveTick, (timer) {
      final end = (next + perTick).clamp(0, targets.length);
      for (var i = next; i < end; i++) {
        _held.remove(targets[i]);
      }
      next = end;
      if (next >= targets.length) {
        _held.clear();
        timer.cancel();
        _waveTimer = null;
      }
      _show();
    });
    _show();
  }

  @override
  Future<void> close() async {
    _waveTimer?.cancel();
    await _projectSub?.cancel();
    await _daySub?.cancel();
    return super.close();
  }
}
