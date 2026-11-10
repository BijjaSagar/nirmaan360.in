import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../data/attendance_repository.dart';

/// One scan and how it ended. [number] tells scans apart on screen.
class ScanEntry extends Equatable {
  const ScanEntry(this.number, this.outcome);

  final int number;
  final ScanOutcome outcome;

  @override
  List<Object?> get props => <Object?>[number, outcome.result, outcome.workerName, outcome.status, outcome.message];
}

class ScanAttendanceState extends Equatable {
  const ScanAttendanceState({this.ready = false, this.projectCode, this.busy = false, this.entries = const <ScanEntry>[]});

  final bool ready;
  final String? projectCode;

  /// A scan is being answered. The camera keeps looking but the next code waits.
  final bool busy;

  /// Newest first.
  final List<ScanEntry> entries;

  ScanEntry? get last => entries.isEmpty ? null : entries.first;

  /// Workers newly marked, or kept to be marked, in this sitting.
  int get counted => entries.where((e) => e.outcome.result == ScanResult.marked || e.outcome.result == ScanResult.queued).length;

  ScanAttendanceState copyWith({bool? ready, String? projectCode, bool? busy, List<ScanEntry>? entries}) => ScanAttendanceState(
    ready: ready ?? this.ready,
    projectCode: projectCode ?? this.projectCode,
    busy: busy ?? this.busy,
    entries: entries ?? this.entries,
  );

  @override
  List<Object?> get props => <Object?>[ready, projectCode, busy, entries];
}

/// Marks workers present as their cards are held up to the camera.
class ScanAttendanceCubit extends Cubit<ScanAttendanceState> {
  ScanAttendanceCubit(this._services) : super(const ScanAttendanceState()) {
    unawaited(_load());
  }

  final AppServices _services;
  int? _projectId;
  var _number = 0;
  String? _lastCode;
  DateTime? _lastAt;

  /// The camera sees a card for as long as it is held up. The same code inside
  /// this window is the same holding, not a second scan.
  static const Duration sameCardWindow = Duration(seconds: 4);

  Future<void> _load() async {
    final int? projectId = await _services.projects.readSelectedProjectId();
    _projectId = projectId;
    final project = projectId == null ? null : await _services.projects.getProject(projectId);
    if (!isClosed) emit(state.copyWith(ready: true, projectCode: project?.code));
  }

  /// A code the camera read. Returns the entry it made, or null when it was ignored.
  Future<ScanEntry?> onCode(String raw) async {
    final int? projectId = _projectId;
    if (projectId == null || state.busy || raw.trim().isEmpty) return null;
    final DateTime now = _services.context.clock.now();
    if (_lastCode == raw && _lastAt != null && now.difference(_lastAt!) < sameCardWindow) return null;
    _lastCode = raw;
    _lastAt = now;

    emit(state.copyWith(busy: true));
    ScanOutcome outcome;
    try {
      outcome = await _services.attendance.scan(projectId, raw);
    } on Object {
      // The scan could not even be kept. Say so, and let the same card be read again.
      _lastCode = null;
      outcome = const ScanOutcome(ScanResult.refused);
    }
    if (isClosed) return null;
    final ScanEntry entry = ScanEntry(++_number, outcome);
    emit(state.copyWith(busy: false, entries: <ScanEntry>[entry, ...state.entries].take(30).toList()));
    return entry;
  }
}
