import 'dart:async';

import 'package:drift/drift.dart';
import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/failures.dart';
import '../../projects/data/project_repository.dart';
import '../data/measurement_repository.dart';

class MeasurementDetailState extends Equatable {
  const MeasurementDetailState({
    required this.online,
    this.loading = true,
    this.entry,
    this.line,
    this.submitWaiting = false,
    this.busy = false,
  });

  final bool online;
  final bool loading;
  final SyncedRecord<MeasurementRow>? entry;
  final BoqLine? line;

  /// A "send for approval" is queued and has not reached the server.
  final bool submitWaiting;
  final bool busy;

  bool get canChange {
    final String? status = entry?.record.status;
    return !submitWaiting && (status == DocStatus.draft || status == DocStatus.returned);
  }

  MeasurementDetailState copyWith({
    bool? online,
    bool? loading,
    SyncedRecord<MeasurementRow>? entry,
    BoqLine? line,
    bool? submitWaiting,
    bool? busy,
  }) {
    return MeasurementDetailState(
      online: online ?? this.online,
      loading: loading ?? this.loading,
      entry: entry ?? this.entry,
      line: line ?? this.line,
      submitWaiting: submitWaiting ?? this.submitWaiting,
      busy: busy ?? this.busy,
    );
  }

  @override
  List<Object?> get props => [online, loading, entry, line, submitWaiting, busy];
}

enum DetailSendResult { ignored, sent, locked, failed }

class MeasurementDetailCubit extends Cubit<MeasurementDetailState> {
  MeasurementDetailCubit({required AppServices services, required this._clientUuid})
    : _services = services,
      super(MeasurementDetailState(online: services.connectivity.isOnline));

  final AppServices _services;
  final String _clientUuid;
  StreamSubscription<SyncedRecord<MeasurementRow>?>? _entry;
  StreamSubscription<BoqLine?>? _line;
  StreamSubscription<List<OutboxRow>>? _submit;
  StreamSubscription<bool>? _connectivity;
  int? _boqItemId;

  void start() {
    _connectivity = _services.connectivity.changes.listen((online) {
      if (!isClosed) emit(state.copyWith(online: online));
    });
    _entry = _services.measurements.watchOne(_clientUuid).listen(_onEntry);
    _submit = _unsentSubmits().listen((rows) {
      if (!isClosed) emit(state.copyWith(submitWaiting: rows.isNotEmpty));
    });
  }

  /// The repository can say this only by refusing an edit, so the screen reads
  /// the outbox itself to explain the entry before anyone tries.
  Stream<List<OutboxRow>> _unsentSubmits() {
    final AppDatabase db = _services.db;
    return (db.select(db.outbox)..where(
          (t) =>
              t.recordUuid.equals(_clientUuid) &
              t.type.equalsValue(OutboxType.measurementSubmit) &
              t.status.equalsValue(OutboxStatus.done).not(),
        ))
        .watch();
  }

  void _onEntry(SyncedRecord<MeasurementRow>? entry) {
    if (isClosed) return;
    if (entry == null) {
      emit(state.copyWith(loading: false));
      return;
    }
    emit(state.copyWith(loading: false, entry: entry));
    final int boqItemId = entry.record.boqItemId;
    if (boqItemId == _boqItemId) return;
    _boqItemId = boqItemId;
    _line?.cancel();
    _line = _services.projects.watchBoqLine(boqItemId).listen((line) {
      if (!isClosed && line != null) emit(state.copyWith(line: line));
    });
  }

  Future<DetailSendResult> send() async {
    if (state.busy || !state.canChange) return DetailSendResult.ignored;
    emit(state.copyWith(busy: true));
    try {
      await _services.measurements.sendForApproval(_clientUuid);
      if (!isClosed) emit(state.copyWith(busy: false));
      return DetailSendResult.sent;
    } on RecordLocked {
      if (!isClosed) emit(state.copyWith(busy: false));
      return DetailSendResult.locked;
    } catch (_) {
      if (!isClosed) emit(state.copyWith(busy: false));
      return DetailSendResult.failed;
    }
  }

  @override
  Future<void> close() async {
    await _entry?.cancel();
    await _line?.cancel();
    await _submit?.cancel();
    await _connectivity?.cancel();
    return super.close();
  }
}
