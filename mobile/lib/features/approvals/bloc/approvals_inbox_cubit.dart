import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../data/approval_repository.dart';

enum InboxFailure { offline, session, server }

/// What the detail screen did, so the inbox can show the item leaving.
enum ApprovalAction { approved, sentBack }

class ApprovalOutcome {
  const ApprovalOutcome(this.approvalId, this.action);

  final int approvalId;
  final ApprovalAction action;
}

/// A card that has been acted on, kept on the page just long enough to leave.
class LeavingCard extends Equatable {
  const LeavingCard(this.row, this.action, this.index);

  final ApprovalRow row;
  final ApprovalAction action;
  final int index;

  @override
  List<Object?> get props => [row.id, action, index];
}

class ApprovalsInboxState extends Equatable {
  const ApprovalsInboxState({
    required this.online,
    this.loading = true,
    this.items = const [],
    this.refreshing = false,
    this.failure,
    this.leaving,
  });

  final bool online;
  final bool loading;

  /// Yours to act on first, then the ones waiting on someone else.
  final List<ApprovalRow> items;
  final bool refreshing;
  final InboxFailure? failure;
  final LeavingCard? leaving;

  int get actionable => items.where((row) => row.canAct).length;

  ApprovalsInboxState copyWith({
    bool? online,
    bool? loading,
    List<ApprovalRow>? items,
    bool? refreshing,
    Object? failure = _keep,
    Object? leaving = _keep,
  }) {
    return ApprovalsInboxState(
      online: online ?? this.online,
      loading: loading ?? this.loading,
      items: items ?? this.items,
      refreshing: refreshing ?? this.refreshing,
      failure: identical(failure, _keep) ? this.failure : failure as InboxFailure?,
      leaving: identical(leaving, _keep) ? this.leaving : leaving as LeavingCard?,
    );
  }

  static const Object _keep = Object();

  @override
  List<Object?> get props => [online, loading, items, refreshing, failure, leaving];
}

class ApprovalsInboxCubit extends Cubit<ApprovalsInboxState> {
  ApprovalsInboxCubit({required AppServices services})
    : _services = services,
      super(ApprovalsInboxState(online: services.connectivity.isOnline));

  final AppServices _services;
  StreamSubscription<List<ApprovalRow>>? _inbox;
  StreamSubscription<bool>? _connectivity;

  /// The last place each card was seen, so a removed one can be shown leaving.
  final Map<int, (ApprovalRow, int)> _lastSeen = {};

  DateTime get now => _services.context.clock.now();

  void start() {
    _connectivity = _services.connectivity.changes.listen((online) {
      if (isClosed) return;
      emit(state.copyWith(online: online, failure: online ? null : state.failure));
      if (online) refresh();
    });
    _inbox = _services.approvals.watchInbox().listen(_onInbox);
    if (state.online) refresh();
  }

  void _onInbox(List<ApprovalRow> rows) {
    if (isClosed) return;
    final List<ApprovalRow> mine = [...rows.where((row) => row.canAct), ...rows.where((row) => !row.canAct)];
    for (final (index, row) in mine.indexed) {
      _lastSeen[row.id] = (row, index);
    }
    emit(state.copyWith(items: mine, loading: false));
  }

  /// Fetches the latest inbox. Cached items stay on screen when offline.
  Future<void> refresh() async {
    if (state.refreshing || isClosed) return;
    emit(state.copyWith(refreshing: true, failure: null));
    InboxFailure? failure;
    try {
      await _services.approvals.refresh();
    } on OfflineFailure {
      failure = InboxFailure.offline;
    } on AuthExpired {
      failure = InboxFailure.session;
    } on ApiFailure {
      failure = InboxFailure.server;
    }
    if (!isClosed) emit(state.copyWith(refreshing: false, failure: failure, loading: false));
  }

  /// The detail screen acted on an item and came back. Its card is already
  /// gone from the inbox; put it back for the moment it takes to leave.
  void showLeaving(ApprovalOutcome outcome) {
    if (isClosed) return;
    final (ApprovalRow, int)? seen = _lastSeen[outcome.approvalId];
    if (seen == null) return;
    final int index = seen.$2 > state.items.length ? state.items.length : seen.$2;
    emit(state.copyWith(leaving: LeavingCard(seen.$1, outcome.action, index)));
  }

  void leavingDone() {
    if (!isClosed) emit(state.copyWith(leaving: null));
  }

  @override
  Future<void> close() async {
    await _inbox?.cancel();
    await _connectivity?.cancel();
    return super.close();
  }
}
