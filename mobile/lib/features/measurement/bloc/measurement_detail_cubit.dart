import 'dart:async';

import 'package:drift/drift.dart';
import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
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
    this.photos = const [],
    this.mayAddPhotos = false,
  });

  final bool online;
  final bool loading;
  final SyncedRecord<MeasurementRow>? entry;
  final BoqLine? line;

  /// A "send for approval" is queued and has not reached the server.
  final bool submitWaiting;
  final bool busy;

  /// The server's photos, then those waiting to be sent.
  final List<RecordPhoto> photos;

  /// This person may add photos to entries, whatever state the entry is in.
  final bool mayAddPhotos;

  /// Photos can be added only while the entry itself can still change.
  bool get canAddPhotos => mayAddPhotos && canChange;

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
    List<RecordPhoto>? photos,
  }) {
    return MeasurementDetailState(
      online: online ?? this.online,
      loading: loading ?? this.loading,
      entry: entry ?? this.entry,
      line: line ?? this.line,
      submitWaiting: submitWaiting ?? this.submitWaiting,
      busy: busy ?? this.busy,
      photos: photos ?? this.photos,
      mayAddPhotos: mayAddPhotos,
    );
  }

  @override
  List<Object?> get props => [online, loading, entry, line, submitWaiting, busy, photos, mayAddPhotos];
}

enum DetailSendResult { ignored, sent, locked, failed }

enum DetailPhotoResult { saved, savedOffline, locked, failed }

class MeasurementDetailCubit extends Cubit<MeasurementDetailState> {
  MeasurementDetailCubit({required AppServices services, required this._clientUuid})
    : _services = services,
      super(
        MeasurementDetailState(
          online: services.connectivity.isOnline,
          mayAddPhotos: services.auth.can('measurement.create'),
        ),
      );

  final AppServices _services;
  final String _clientUuid;
  StreamSubscription<SyncedRecord<MeasurementRow>?>? _entry;
  StreamSubscription<BoqLine?>? _line;
  StreamSubscription<List<OutboxRow>>? _submit;
  StreamSubscription<bool>? _connectivity;
  StreamSubscription<List<RecordPhoto>>? _photos;
  int? _boqItemId;

  /// The server's id for this entry the last time its photos were read.
  int? _photosReadFor;
  int _waitingPhotos = 0;

  void start() {
    _connectivity = _services.connectivity.changes.listen((online) {
      if (!isClosed) emit(state.copyWith(online: online));
    });
    _entry = _services.measurements.watchOne(_clientUuid).listen(_onEntry);
    _photos = _services.measurements.watchPhotos(_clientUuid).listen(_onPhotos);
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
    // The server's photos can be read once it knows the entry.
    final int? serverId = entry.record.serverId;
    if (serverId != null && serverId != _photosReadFor) {
      _photosReadFor = serverId;
      unawaited(refreshPhotos());
    }
    final int boqItemId = entry.record.boqItemId;
    if (boqItemId == _boqItemId) return;
    _boqItemId = boqItemId;
    _line?.cancel();
    _line = _services.projects.watchBoqLine(boqItemId).listen((line) {
      if (!isClosed && line != null) emit(state.copyWith(line: line));
    });
  }

  void _onPhotos(List<RecordPhoto> photos) {
    if (isClosed) return;
    final int waiting = photos.where((photo) => photo.isWaiting).length;
    final bool someWereSent = waiting < _waitingPhotos;
    _waitingPhotos = waiting;
    emit(state.copyWith(photos: photos));
    if (someWereSent) unawaited(refreshPhotos());
  }

  /// Reads the server's photos again. Quiet when there is no network: what was
  /// read last stays on screen.
  Future<void> refreshPhotos() async {
    try {
      await _services.measurements.refreshPhotos(_clientUuid);
    } on ApiFailure {
      // Offline, or not allowed to see them. The last list stands.
    }
  }

  /// Keeps a photo on the phone and queues it. Works offline.
  Future<DetailPhotoResult> addPhoto(String path) async {
    if (!state.canAddPhotos) return DetailPhotoResult.locked;
    try {
      await _services.measurements.addPhotos(_clientUuid, [path]);
      return _services.connectivity.isOnline ? DetailPhotoResult.saved : DetailPhotoResult.savedOffline;
    } on RecordLocked {
      return DetailPhotoResult.locked;
    } on Object {
      return DetailPhotoResult.failed;
    }
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
    await _photos?.cancel();
    await _connectivity?.cancel();
    return super.close();
  }
}
