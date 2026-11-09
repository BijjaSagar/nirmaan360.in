import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../../core/design/pictograms.dart';
import '../../../core/sync/clock.dart';
import '../../../core/sync/failures.dart';
import '../data/dpr_repository.dart';
import 'dpr_streams.dart';

/// Where the screen is in its life.
enum DprFormPhase {
  loading,
  editing,

  /// The report for this date cannot be changed: show it read-only instead.
  locked,
  noProject,
}

/// The quiet indicator at the top of the sheet.
enum DprSaveStatus { clean, dirty, saved, failed }

/// A section that needs the engineer before the report can go.
enum DprFormProblem { weather, workers }

/// How a press of Send ended.
enum DprSendResult { sent, invalid, futureDate, locked, failed }

/// How adding a photo ended, for the one message the screen shows.
enum DprPhotoResult { added, cancelled, cameraFailed, failed, full, locked }

/// The most photos one report may hold, matching the web form.
const int dprMaxPhotos = 10;

/// The form, held as plain fields. [DprFormCubit] saves it on every change.
@immutable
class DprFormState {
  const DprFormState({
    this.phase = DprFormPhase.loading,
    this.project,
    this.reportDate = '',
    this.clientUuid,
    this.docStatus = DocStatus.draft,
    this.returnRemark,
    this.siteId,
    this.weather,
    this.weatherOptions = const [],
    this.trades = const [],
    this.counts = const {},
    this.visibleTradeCount = 0,
    this.yesterdayTotal,
    this.yesterdayWorkIds = const {},
    this.machinery = const [],
    this.work = const [],
    this.photos = const [],
    this.hindrances = '',
    this.notes = '',
    this.notesOpen = false,
    this.save = DprSaveStatus.clean,
    this.saveTick = 0,
    this.problems = const {},
    this.sending = false,
    this.capturing = false,
  });

  final DprFormPhase phase;
  final ProjectRow? project;

  /// `YYYY-MM-DD`.
  final String reportDate;

  /// Null until the first save creates the draft.
  final String? clientUuid;
  final String docStatus;
  final String? returnRemark;
  final int? siteId;
  final String? weather;
  final List<String> weatherOptions;

  /// Every trade, in the order the rows are drawn.
  final List<String> trades;
  final Map<String, int> counts;

  /// How many of [trades] are on the sheet. The rest wait behind a button.
  ///
  /// Decided on load and on copy, never while a stepper is held, so a row
  /// cannot move or vanish under a thumb.
  final int visibleTradeCount;

  /// Yesterday's headcount, null when there is no report to copy.
  final int? yesterdayTotal;
  final Set<int> yesterdayWorkIds;
  final List<MachineryLine> machinery;
  final List<WorkLine> work;
  final List<DprPhotoRow> photos;
  final String hindrances;
  final String notes;
  final bool notesOpen;
  final DprSaveStatus save;

  /// Goes up with every completed save, so the indicator can acknowledge it.
  final int saveTick;
  final Set<DprFormProblem> problems;
  final bool sending;
  final bool capturing;

  bool get isReturned => docStatus == DocStatus.returned;

  int get totalWorkers =>
      counts.values.fold(0, (sum, count) => sum + count);

  /// The trades currently on the sheet.
  List<String> get visibleTrades =>
      trades.take(visibleTradeCount).toList(growable: false);

  /// How many trades are still folded away.
  int get hiddenTradeCount => math.max(0, trades.length - visibleTradeCount);

  /// True when "Same as yesterday" should be offered: there is a yesterday
  /// and nothing has been counted today.
  ///
  /// Hidden once counts exist, because a stray tap would overwrite them.
  bool get canCopyYesterday =>
      yesterdayTotal != null && yesterdayTotal! > 0 && totalWorkers == 0;

  int count(String trade) => counts[trade] ?? 0;

  /// What the engineer still has to fill in.
  Set<DprFormProblem> get currentProblems => {
    if (weather == null) DprFormProblem.weather,
    if (totalWorkers == 0 && work.isEmpty && hindrances.trim().isEmpty)
      DprFormProblem.workers,
  };

  DprFormState copyWith({
    DprFormPhase? phase,
    ProjectRow? project,
    String? reportDate,
    String? clientUuid,
    String? docStatus,
    String? returnRemark,
    int? siteId,
    String? weather,
    List<String>? weatherOptions,
    List<String>? trades,
    Map<String, int>? counts,
    int? visibleTradeCount,
    int? yesterdayTotal,
    Set<int>? yesterdayWorkIds,
    List<MachineryLine>? machinery,
    List<WorkLine>? work,
    List<DprPhotoRow>? photos,
    String? hindrances,
    String? notes,
    bool? notesOpen,
    DprSaveStatus? save,
    int? saveTick,
    Set<DprFormProblem>? problems,
    bool? sending,
    bool? capturing,
  }) {
    return DprFormState(
      phase: phase ?? this.phase,
      project: project ?? this.project,
      reportDate: reportDate ?? this.reportDate,
      clientUuid: clientUuid ?? this.clientUuid,
      docStatus: docStatus ?? this.docStatus,
      returnRemark: returnRemark ?? this.returnRemark,
      siteId: siteId ?? this.siteId,
      weather: weather ?? this.weather,
      weatherOptions: weatherOptions ?? this.weatherOptions,
      trades: trades ?? this.trades,
      counts: counts ?? this.counts,
      visibleTradeCount: visibleTradeCount ?? this.visibleTradeCount,
      yesterdayTotal: yesterdayTotal ?? this.yesterdayTotal,
      yesterdayWorkIds: yesterdayWorkIds ?? this.yesterdayWorkIds,
      machinery: machinery ?? this.machinery,
      work: work ?? this.work,
      photos: photos ?? this.photos,
      hindrances: hindrances ?? this.hindrances,
      notes: notes ?? this.notes,
      notesOpen: notesOpen ?? this.notesOpen,
      save: save ?? this.save,
      saveTick: saveTick ?? this.saveTick,
      problems: problems ?? this.problems,
      sending: sending ?? this.sending,
      capturing: capturing ?? this.capturing,
    );
  }
}

/// Owns the DPR form: loads the record for the date, autosaves every change,
/// and sends. Quantities stay [Qty] from the keypad to the outbox.
class DprFormCubit extends Cubit<DprFormState> {
  DprFormCubit({
    required this._services,
    String? clientUuid,
    String? date,
    this.saveDelay = const Duration(milliseconds: 300),
  }) : _requestedUuid = clientUuid,
       _requestedDate = date,
       super(const DprFormState());

  final AppServices _services;
  final String? _requestedUuid;
  final String? _requestedDate;

  /// How long typing may pause before a save.
  final Duration saveDelay;

  DprRepository get _repo => _services.dpr;

  Timer? _debounce;
  StreamSubscription<DprDetails?>? _watcher;
  String? _watching;

  /// Saves run one at a time, in the order the changes were made.
  Future<void> _queue = Future<void>.value();
  bool _dirty = false;

  /// Set while [send] runs and for good after it, so the form never swaps to
  /// the read-only view under the stamp.
  bool _sendingNow = false;
  bool _sent = false;

  /// Trades first offered on a new form, before any count exists.
  static const int _foldedTrades = 4;

  Future<void> load() async {
    final project = await dprFirst(_services.projects.watchCurrentProject());
    if (project == null) {
      if (!isClosed) emit(state.copyWith(phase: DprFormPhase.noProject));
      return;
    }

    final requested = _requestedUuid;
    final today = _requestedDate ?? _services.context.today;
    final details = await dprFirst(
      requested != null
          ? _repo.watchOne(requested)
          : _repo.watchForDate(project.id, today),
    );

    final projectId = details?.dpr.projectId ?? project.id;
    final date = details?.dpr.reportDate ?? today;
    final recordProject = projectId == project.id
        ? project
        : (await _services.projects.getProject(projectId)) ?? project;

    if (details != null && !_isEditable(details)) {
      if (isClosed) return;
      emit(
        state.copyWith(
          phase: DprFormPhase.locked,
          project: recordProject,
          reportDate: date,
          clientUuid: details.dpr.clientUuid,
        ),
      );
      return;
    }

    final lookups = await dprFirst(_services.projects.watchLookups());
    final sites = await dprFirst(_services.projects.watchSites(projectId));
    final yesterday = await dprFirst(
      _repo.watchForDate(projectId, _previousDay(date)),
    );
    if (isClosed) return;

    final dpr = details?.dpr;
    final counts = <String, int>{
      for (final line in dpr?.manpower ?? const <ManpowerLine>[])
        if (line.count > 0) line.trade: line.count,
    };
    final known = lookups.trades.isNotEmpty
        ? lookups.trades
        : N360Pictograms.trades.map((p) => p.key).toList();
    final trades = _countsFirst(<String>{...known, ...counts.keys}, counts);

    final weatherOptions = lookups.weather.isNotEmpty
        ? lookups.weather
        : N360Pictograms.weatherKinds.map((p) => p.key).toList();
    final weather = dpr?.weather;

    final yesterdayRow = yesterday?.dpr;
    final yesterdayLines = yesterdayRow?.manpower ?? const <ManpowerLine>[];
    final yesterdayTotal = yesterdayLines.fold<int>(
      0,
      (sum, line) => sum + line.count,
    );

    final hindrances = dpr?.hindrances ?? '';
    final notes = dpr?.summary ?? '';

    emit(
      DprFormState(
        phase: DprFormPhase.editing,
        project: recordProject,
        reportDate: date,
        clientUuid: dpr?.clientUuid,
        docStatus: dpr?.status ?? DocStatus.draft,
        returnRemark: dpr?.returnRemark,
        siteId: dpr?.siteId ?? (sites.isEmpty ? null : sites.first.id),
        weather: weather,
        weatherOptions: <String>[
          ...weatherOptions,
          if (weather != null && !weatherOptions.contains(weather)) weather,
        ],
        trades: trades,
        counts: counts,
        visibleTradeCount: math.min(
          trades.length,
          math.max(counts.length, _foldedTrades),
        ),
        yesterdayTotal: yesterdayTotal > 0 ? yesterdayTotal : null,
        yesterdayWorkIds: {
          for (final line in yesterdayRow?.workItems ?? const <WorkLine>[])
            if (line.boqItemId != null) line.boqItemId!,
        },
        machinery: dpr?.machinery ?? const [],
        work: dpr?.workItems ?? const [],
        photos: details?.photos ?? const [],
        hindrances: hindrances,
        notes: notes,
        notesOpen: hindrances.isNotEmpty || notes.isNotEmpty,
        save: dpr == null ? DprSaveStatus.clean : DprSaveStatus.saved,
      ),
    );
    if (dpr != null) _watch(dpr.clientUuid);
  }

  // Edits ---------------------------------------------------------------------

  void setWeather(String value) {
    if (value == state.weather) return;
    _edit(state.copyWith(weather: value));
  }

  void setCount(String trade, int count) {
    final counts = Map<String, int>.of(state.counts);
    if (count <= 0) {
      counts.remove(trade);
    } else {
      counts[trade] = count;
    }
    _edit(state.copyWith(counts: counts));
  }

  /// Fills the counts from yesterday's report. Returns how many it copied.
  Future<int> copyYesterday() async {
    final s = state;
    final projectId = s.project?.id;
    if (projectId == null) return 0;
    final List<ManpowerLine> lines;
    try {
      lines = await _repo.copyManpowerFromYesterday(projectId, s.reportDate);
    } on RecordLocked {
      return 0;
    }
    if (lines.isEmpty || isClosed) return 0;
    final counts = <String, int>{
      for (final line in lines)
        if (line.count > 0) line.trade: line.count,
    };
    final trades = _countsFirst(<String>{...s.trades, ...counts.keys}, counts);
    _edit(
      state.copyWith(
        counts: counts,
        trades: trades,
        visibleTradeCount: counts.length,
      ),
    );
    return counts.values.fold<int>(0, (sum, count) => sum + count);
  }

  void showAllTrades() {
    emit(state.copyWith(visibleTradeCount: state.trades.length));
  }

  /// Adds [line], or replaces the row at [index].
  void putWork(WorkLine line, {int? index}) {
    final work = List<WorkLine>.of(state.work);
    if (index != null && index >= 0 && index < work.length) {
      work[index] = line;
    } else {
      work.add(line);
    }
    _edit(state.copyWith(work: work));
  }

  void removeWork(int index) {
    if (index < 0 || index >= state.work.length) return;
    _edit(state.copyWith(work: List<WorkLine>.of(state.work)..removeAt(index)));
  }

  /// Puts a removed row back where it was, for Undo.
  void restoreWork(WorkLine line, int index) {
    final work = List<WorkLine>.of(state.work);
    work.insert(index.clamp(0, work.length), line);
    _edit(state.copyWith(work: work));
  }

  void setHindrances(String value) {
    if (value == state.hindrances) return;
    _edit(state.copyWith(hindrances: value));
  }

  void setNotes(String value) {
    if (value == state.notes) return;
    _edit(state.copyWith(notes: value));
  }

  void openNotes() {
    if (!state.notesOpen) emit(state.copyWith(notesOpen: true));
  }

  // Photos --------------------------------------------------------------------

  Future<DprPhotoResult> addPhoto() async {
    if (state.capturing) return DprPhotoResult.cancelled;
    if (state.photos.length >= dprMaxPhotos) return DprPhotoResult.full;

    emit(state.copyWith(capturing: true));
    try {
      final String? path;
      try {
        path = await _services.photos.capture();
      } on Object {
        return DprPhotoResult.cameraFailed;
      }
      if (path == null) return DprPhotoResult.cancelled;

      // A photo hangs off the draft, so the draft has to exist first.
      final uuid = await _flush(force: true);
      if (uuid == null) return DprPhotoResult.failed;

      await _repo.addPhoto(dprClientUuid: uuid, sourcePath: path);
      return DprPhotoResult.added;
    } on RecordLocked {
      return DprPhotoResult.locked;
    } on Object {
      return DprPhotoResult.failed;
    } finally {
      if (!isClosed) emit(state.copyWith(capturing: false));
    }
  }

  Future<bool> removePhoto(String photoUuid) async {
    try {
      await _repo.removePhoto(photoUuid);
      return true;
    } on RecordLocked {
      return false;
    }
  }

  // Sending -------------------------------------------------------------------

  /// Marks the sections that are incomplete. True when the report can go.
  bool validate() {
    final problems = state.currentProblems;
    emit(state.copyWith(problems: problems));
    return problems.isEmpty;
  }

  /// Saves whatever is pending, then queues the report for the office.
  Future<DprSendResult> send() async {
    if (state.sending || _sent) return DprSendResult.sent;
    if (!validate()) return DprSendResult.invalid;

    _sendingNow = true;
    emit(state.copyWith(sending: true));
    try {
      final uuid = await _flush(force: true);
      if (uuid == null) return DprSendResult.failed;
      await _repo.send(uuid);
      _sent = true;
      return DprSendResult.sent;
    } on ValidationFailure {
      return DprSendResult.futureDate;
    } on RecordLocked {
      return DprSendResult.locked;
    } on Object {
      return DprSendResult.failed;
    } finally {
      _sendingNow = false;
      if (!isClosed) emit(state.copyWith(sending: false));
    }
  }

  /// Writes pending edits now, for when the app is about to go away.
  Future<void> saveNow() async {
    if (!_dirty) return;
    try {
      await _flush();
    } on Object {
      // The indicator already says it was not saved.
    }
  }

  // Internals -----------------------------------------------------------------

  /// Applies an edit and arms the debounced save. A section's message clears
  /// the moment it is satisfied, but a new one never appears before Send.
  void _edit(DprFormState next) {
    if (_sent) return;
    _dirty = true;
    final problems = next.problems.isEmpty
        ? next.problems
        : next.problems.intersection(next.currentProblems);
    emit(next.copyWith(save: DprSaveStatus.dirty, problems: problems));
    _debounce?.cancel();
    _debounce = Timer(saveDelay, () {
      unawaited(_flush().catchError((Object _) => null));
    });
  }

  /// Saves the form and returns the draft's uuid.
  ///
  /// With [force] a save happens even when nothing changed, so a photo or a
  /// send on an untouched form still has a draft to attach to.
  Future<String?> _flush({bool force = false}) {
    _debounce?.cancel();
    final result = _queue.then((_) => _write(force: force));
    _queue = result.then<void>((_) {}, onError: (Object _) {});
    return result;
  }

  Future<String?> _write({required bool force}) async {
    final existing = state.clientUuid;
    if (!_dirty && (!force || existing != null)) return existing;

    final snapshot = state;
    _dirty = false;
    final input = DprInput(
      projectId: snapshot.project!.id,
      reportDate: snapshot.reportDate,
      siteId: snapshot.siteId,
      weather: snapshot.weather,
      summary: _nullIfBlank(snapshot.notes),
      hindrances: _nullIfBlank(snapshot.hindrances),
      manpower: <ManpowerLine>[
        for (final trade in snapshot.trades)
          if (snapshot.count(trade) > 0)
            ManpowerLine(trade: trade, count: snapshot.count(trade)),
      ],
      machinery: snapshot.machinery,
      workItems: snapshot.work,
    );

    try {
      final String uuid;
      if (snapshot.isReturned && existing != null) {
        await _repo.editReturned(existing, input);
        uuid = existing;
      } else {
        uuid = await _repo.saveDraft(input, clientUuid: existing);
      }
      if (isClosed) return uuid;
      emit(
        state.copyWith(
          clientUuid: uuid,
          save: _dirty ? DprSaveStatus.dirty : DprSaveStatus.saved,
          saveTick: state.saveTick + 1,
        ),
      );
      _watch(uuid);
      return uuid;
    } on RecordLocked {
      _dirty = false;
      if (!isClosed) {
        emit(state.copyWith(save: DprSaveStatus.failed));
      }
      rethrow;
    } on Object {
      _dirty = true;
      if (!isClosed) {
        emit(state.copyWith(save: DprSaveStatus.failed));
      }
      rethrow;
    }
  }

  void _watch(String uuid) {
    if (_watching == uuid) return;
    _watching = uuid;
    unawaited(_watcher?.cancel());
    _watcher = _repo.watchOne(uuid).listen((details) {
      if (details == null || isClosed) return;
      if (_sendingNow || _sent) return;
      if (!_isEditable(details)) {
        emit(state.copyWith(phase: DprFormPhase.locked));
        return;
      }
      if (!listEquals(
        details.photos.map((p) => p.clientUuid).toList(),
        state.photos.map((p) => p.clientUuid).toList(),
      )) {
        emit(state.copyWith(photos: details.photos));
      }
    });
  }

  /// A report can be changed while it is a draft or was sent back, unless a
  /// send is still on its way out.
  static bool _isEditable(DprDetails details) {
    final dpr = details.dpr;
    final open =
        dpr.status == DocStatus.draft || dpr.status == DocStatus.returned;
    final leaving =
        !dpr.localDraft && details.record.syncState != LocalSyncState.synced;
    return open && !leaving;
  }

  static String? _nullIfBlank(String value) {
    final text = value.trim();
    return text.isEmpty ? null : text;
  }

  /// Trades with a count first, otherwise in the server's order.
  static List<String> _countsFirst(
    Iterable<String> trades,
    Map<String, int> counts,
  ) {
    return <String>[
      ...trades.where((t) => (counts[t] ?? 0) > 0),
      ...trades.where((t) => (counts[t] ?? 0) <= 0),
    ];
  }

  static String _previousDay(String date) {
    final parts = date.split('-').map(int.parse).toList();
    return dateString(DateTime.utc(parts[0], parts[1], parts[2] - 1));
  }

  @override
  Future<void> close() async {
    _debounce?.cancel();
    if (_dirty && !_sent) {
      try {
        await _flush();
      } on Object {
        // Nothing more can be done from here.
      }
    }
    await _watcher?.cancel();
    return super.close();
  }
}
