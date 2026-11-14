import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/money/money.dart';
import '../data/floor_setup_repository.dart';

/// How far a screen that reads from the server has got.
enum SetupLoad { loading, ready, offline, failed }

SetupLoad loadFailure(ApiFailure failure) => failure is OfflineFailure ? SetupLoad.offline : SetupLoad.failed;

/// What a write left to say: nothing when it went through, otherwise why not.
/// [message] is the server's own words for a refusal it explained (already in
/// the person's language), null for the screen to say it in its own words.
class SetupProblem {
  const SetupProblem(this.kind, {this.message, this.field});

  factory SetupProblem.of(ApiFailure failure) => switch (failure) {
    OfflineFailure() => const SetupProblem(SetupProblemKind.offline),
    ValidationFailure(:final String message, :final Map<String, List<String>> errors) => SetupProblem(
      SetupProblemKind.refused,
      message: errors.values.expand((e) => e).firstOrNull ?? message,
      field: errors.keys.firstOrNull,
    ),
    ServerFailure(statusCode: 403) => const SetupProblem(SetupProblemKind.forbidden),
    _ => const SetupProblem(SetupProblemKind.failed),
  };

  final SetupProblemKind kind;
  final String? message;

  /// The field the server named, such as `name` or `deadline_date`.
  final String? field;
}

enum SetupProblemKind { offline, refused, forbidden, failed }

/// The outcome of adding several segments: how many were added, and the names
/// refused with why.
class SegmentsOutcome {
  const SegmentsOutcome({required this.added, required this.refused});

  /// The names that are now segments.
  final List<String> added;
  final Map<String, SetupProblem> refused;
}

class FloorSetupState extends Equatable {
  const FloorSetupState({this.load = SetupLoad.loading, this.setup, this.busy = false});

  final SetupLoad load;
  final FloorSetup? setup;

  /// A write is on its way; the buttons wait.
  final bool busy;

  FloorSetupState copyWith({SetupLoad? load, FloorSetup? setup, bool? busy}) =>
      FloorSetupState(load: load ?? this.load, setup: setup ?? this.setup, busy: busy ?? this.busy);

  @override
  List<Object?> get props => <Object?>[load, setup, busy];
}

/// The floors and segments of one project, set up from the phone. Online only:
/// every change goes to the server at once and the list is read back after it.
class FloorSetupCubit extends Cubit<FloorSetupState> {
  FloorSetupCubit(this._repository, this.projectId) : super(const FloorSetupState());

  final FloorSetupRepository _repository;
  final int projectId;

  Future<void> load() async {
    if (state.setup == null) emit(state.copyWith(load: SetupLoad.loading));
    try {
      final FloorSetup setup = await _repository.load(projectId);
      if (!isClosed) emit(state.copyWith(load: SetupLoad.ready, setup: setup));
    } on ApiFailure catch (failure) {
      // A list already shown stays; only a first load shows the failure.
      if (!isClosed && state.setup == null) emit(state.copyWith(load: loadFailure(failure)));
    }
  }

  Future<SetupProblem?> _write(Future<void> Function() change) async {
    if (state.busy) return null;
    emit(state.copyWith(busy: true));
    try {
      await change();
      await _repository.refreshProgress(projectId);
      await load();
      return null;
    } on ApiFailure catch (failure) {
      return SetupProblem.of(failure);
    } finally {
      if (!isClosed) emit(state.copyWith(busy: false));
    }
  }

  Future<SetupProblem?> addFloor(String name, {int? parentId}) =>
      _write(() => _repository.addFloor(projectId, name, parentId: parentId));

  /// Splits a floor into segments, one request per name. A refused name does
  /// not stop the others; a lost connection does.
  Future<SegmentsOutcome> addSegments(int parentId, List<String> names) async {
    final Map<String, SetupProblem> refused = <String, SetupProblem>{};
    final List<String> added = <String>[];
    if (state.busy) return SegmentsOutcome(added: added, refused: refused);
    emit(state.copyWith(busy: true));
    try {
      for (final String name in names) {
        try {
          await _repository.addFloor(projectId, name, parentId: parentId);
          added.add(name);
        } on ApiFailure catch (failure) {
          refused[name] = SetupProblem.of(failure);
          if (failure is OfflineFailure) break;
        }
      }
      if (added.isNotEmpty) {
        await _repository.refreshProgress(projectId);
        await load();
      }
      return SegmentsOutcome(added: added, refused: refused);
    } finally {
      if (!isClosed) emit(state.copyWith(busy: false));
    }
  }

  Future<SetupProblem?> rename(int floorId, String name) => _write(() => _repository.rename(floorId, name));

  Future<SetupProblem?> delete(int floorId) => _write(() => _repository.delete(floorId));

  Future<SetupProblem?> saveTimeFrame(TimeFrame frame) => _write(() => _repository.setTimeFrame(projectId, frame));
}

class FloorPlanState extends Equatable {
  const FloorPlanState({this.load = SetupLoad.loading, this.plan, this.items = const <ProgressItem>[], this.query = '', this.saving});

  final SetupLoad load;
  final FloorPlan? plan;
  final List<ProgressItem> items;
  final String query;

  /// The BOQ line being saved, if any.
  final int? saving;

  List<ProgressItem> get shown {
    final String q = query.trim().toLowerCase();
    if (q.isEmpty) return items;
    return items.where((i) => i.code.toLowerCase().contains(q) || i.description.toLowerCase().contains(q)).toList();
  }

  FloorPlanState copyWith({SetupLoad? load, FloorPlan? plan, List<ProgressItem>? items, String? query, int? Function()? saving}) =>
      FloorPlanState(
        load: load ?? this.load,
        plan: plan ?? this.plan,
        items: items ?? this.items,
        query: query ?? this.query,
        saving: saving == null ? this.saving : saving(),
      );

  @override
  List<Object?> get props => <Object?>[load, plan, items, query, saving];
}

/// What is planned on one floor or segment, line by line.
class FloorPlanCubit extends Cubit<FloorPlanState> {
  FloorPlanCubit(this._repository, this.projectId, this.floorId) : super(const FloorPlanState());

  final FloorSetupRepository _repository;
  final int projectId;
  final int floorId;

  Future<void> load() async {
    if (state.plan == null) emit(state.copyWith(load: SetupLoad.loading));
    try {
      final FloorPlan plan = await _repository.plan(floorId);
      final FloorSetup setup = await _repository.load(projectId);
      if (!isClosed) emit(state.copyWith(load: SetupLoad.ready, plan: plan, items: setup.items));
    } on ApiFailure catch (failure) {
      if (!isClosed && state.plan == null) emit(state.copyWith(load: loadFailure(failure)));
    }
  }

  void search(String query) => emit(state.copyWith(query: query));

  Future<SetupProblem?> setPlanned(int boqItemId, Qty planned) async {
    if (state.saving != null) return null;
    emit(state.copyWith(saving: () => boqItemId));
    try {
      final FloorPlan plan = await _repository.setTarget(floorId, boqItemId, planned);
      if (!isClosed) emit(state.copyWith(plan: plan));
      await _repository.refreshProgress(projectId);
      return null;
    } on ApiFailure catch (failure) {
      return SetupProblem.of(failure);
    } finally {
      if (!isClosed) emit(state.copyWith(saving: () => null));
    }
  }
}

class FloorSplitState extends Equatable {
  const FloorSplitState({
    this.load = SetupLoad.loading,
    this.setup,
    this.floorIds = const <int>{},
    this.itemIds = const <int>{},
    this.allItems = false,
    this.busy = false,
  });

  final SetupLoad load;
  final FloorSetup? setup;
  final Set<int> floorIds;
  final Set<int> itemIds;
  final bool allItems;
  final bool busy;

  FloorSplitState copyWith({SetupLoad? load, FloorSetup? setup, Set<int>? floorIds, Set<int>? itemIds, bool? allItems, bool? busy}) =>
      FloorSplitState(
        load: load ?? this.load,
        setup: setup ?? this.setup,
        floorIds: floorIds ?? this.floorIds,
        itemIds: itemIds ?? this.itemIds,
        allItems: allItems ?? this.allItems,
        busy: busy ?? this.busy,
      );

  @override
  List<Object?> get props => <Object?>[load, setup, floorIds, itemIds, allItems, busy];
}

/// Why sharing cannot be sent yet.
enum SplitMissing { floor, line }

/// Shares whole BOQ lines equally across chosen floors and segments.
class FloorSplitCubit extends Cubit<FloorSplitState> {
  FloorSplitCubit(this._repository, this.projectId) : super(const FloorSplitState());

  final FloorSetupRepository _repository;
  final int projectId;

  Future<void> load() async {
    emit(state.copyWith(load: SetupLoad.loading));
    try {
      final FloorSetup setup = await _repository.load(projectId);
      if (!isClosed) emit(state.copyWith(load: SetupLoad.ready, setup: setup));
    } on ApiFailure catch (failure) {
      if (!isClosed) emit(state.copyWith(load: loadFailure(failure)));
    }
  }

  void toggleFloor(int id) => emit(state.copyWith(floorIds: _toggle(state.floorIds, id)));

  void toggleItem(int id) => emit(state.copyWith(itemIds: _toggle(state.itemIds, id)));

  void setAllItems(bool all) => emit(state.copyWith(allItems: all));

  Set<int> _toggle(Set<int> set, int id) => set.contains(id) ? (Set<int>.of(set)..remove(id)) : <int>{...set, id};

  SplitMissing? get missing {
    if (state.floorIds.isEmpty) return SplitMissing.floor;
    if (!state.allItems && state.itemIds.isEmpty) return SplitMissing.line;
    return null;
  }

  /// The number of lines shared, or the problem. Floors keep the order they are shown in.
  Future<(int?, SetupProblem?)> submit() async {
    if (state.busy || missing != null) return (null, null);
    emit(state.copyWith(busy: true));
    try {
      final List<int> floors = <int>[
        for (final FloorChoice choice in state.setup?.choices ?? const <FloorChoice>[])
          if (state.floorIds.contains(choice.floor.id)) choice.floor.id,
      ];
      final int count = await _repository.split(
        projectId,
        floorIds: floors,
        boqItemIds: state.allItems ? const <int>[] : state.itemIds.toList(),
        allItems: state.allItems,
      );
      await _repository.refreshProgress(projectId);
      return (count, null);
    } on ApiFailure catch (failure) {
      return (null, SetupProblem.of(failure));
    } finally {
      if (!isClosed) emit(state.copyWith(busy: false));
    }
  }
}

class ProgressWeekState extends Equatable {
  const ProgressWeekState({this.load = SetupLoad.loading, this.week, this.date});

  final SetupLoad load;
  final ProgressWeek? week;

  /// The day asked for, null for this week.
  final String? date;

  @override
  List<Object?> get props => <Object?>[load, week, date];
}

/// One week of the project, Monday to Sunday, read from the server.
class ProgressWeekCubit extends Cubit<ProgressWeekState> {
  ProgressWeekCubit(this._repository, this.projectId) : super(const ProgressWeekState());

  final FloorSetupRepository _repository;
  final int projectId;

  Future<void> load({String? date}) async {
    emit(ProgressWeekState(load: SetupLoad.loading, week: state.week, date: date));
    try {
      final ProgressWeek week = await _repository.week(projectId, date: date);
      if (!isClosed) emit(ProgressWeekState(load: SetupLoad.ready, week: week, date: date));
    } on ApiFailure catch (failure) {
      if (!isClosed) emit(ProgressWeekState(load: loadFailure(failure), week: state.week, date: date));
    }
  }

  Future<void> retry() => load(date: state.date);

  Future<void> previous() => _move(-7);

  Future<void> next() => _move(7);

  Future<void> _move(int days) async {
    final DateTime? start = DateTime.tryParse(state.week?.start ?? '');
    if (start == null) return;
    final DateTime day = start.add(Duration(days: days));
    await load(date: '${day.year}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}');
  }
}
