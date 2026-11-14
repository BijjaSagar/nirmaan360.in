import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../data/sync_repository.dart';

class SyncCenterState extends Equatable {
  const SyncCenterState({
    this.loaded = false,
    this.waiting = const <OutboxItem>[],
    this.attention = const <OutboxItem>[],
    this.stale = const <OutboxItem>[],
  });

  /// False until the first read from the database.
  final bool loaded;

  /// In the tray: pending, or being sent.
  final List<OutboxItem> waiting;

  /// The server said no, or a file is missing. Needs a person.
  final List<OutboxItem> attention;

  /// Newest first: writes the server did not apply because it had newer data.
  final List<OutboxItem> stale;

  @override
  List<Object?> get props => <Object?>[
        loaded,
        <int>[for (final i in waiting) i.id],
        <String>[for (final i in waiting) i.status.name],
        <int>[for (final i in attention) i.id],
        <int>[for (final i in stale) i.id],
      ];
}

class SyncCenterCubit extends Cubit<SyncCenterState> {
  SyncCenterCubit({required AppServices services})
      : _repository = services.syncCenter,
        super(const SyncCenterState()) {
    _subscriptions.addAll(<StreamSubscription<Object?>>[
      _repository.watchOutbox().listen(_onOutbox),
      _repository.watchLog(limit: 40).listen(_onLog),
    ]);
  }

  static const int _logLimit = 5;

  final SyncRepository _repository;
  final List<StreamSubscription<Object?>> _subscriptions = <StreamSubscription<Object?>>[];

  void _onOutbox(List<OutboxItem> items) {
    if (isClosed) return;
    emit(
      SyncCenterState(
        loaded: true,
        waiting: <OutboxItem>[for (final i in items) if (!i.needsAttention) i],
        attention: <OutboxItem>[for (final i in items) if (i.needsAttention) i],
        stale: state.stale,
      ),
    );
  }

  void _onLog(List<OutboxItem> items) {
    if (isClosed) return;
    emit(
      SyncCenterState(
        loaded: state.loaded,
        waiting: state.waiting,
        attention: state.attention,
        stale: <OutboxItem>[
          for (final i in items) if (i.resultStatus == 'stale') i,
        ].take(_logLimit).toList(),
      ),
    );
  }

  Future<SyncRunResult> syncNow() => _repository.syncNow();

  Future<void> retry(OutboxItem item) => _repository.retry(item.id);

  Future<void> discard(OutboxItem item) => _repository.discard(item.id);

  @override
  Future<void> close() async {
    for (final subscription in _subscriptions) {
      await subscription.cancel();
    }
    return super.close();
  }
}
