import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../data/approval_repository.dart';
import 'boq_index.dart';

enum ApprovalFailure { offline, raceLost, session, rejected, server }

enum ActResult { ignored, done, failed }

class ApprovalDetailState extends Equatable {
  const ApprovalDetailState({
    required this.online,
    this.loading = true,
    this.item,
    this.gone = false,
    this.acting = false,
    this.finished = false,
    this.refreshing = false,
    this.failure,
    this.failureDetail,
    this.boq = const {},
  });

  final bool online;
  final bool loading;

  /// The document. Kept after the server accepts an action, so the page stays
  /// whole while the stamp lands.
  final ApprovalRow? item;

  /// The item has left the inbox without this phone acting on it.
  final bool gone;
  final bool acting;
  final bool finished;
  final bool refreshing;
  final ApprovalFailure? failure;

  /// The server's own message, for a refusal the app has no words for.
  final String? failureDetail;

  /// The BOQ lines on this phone, for showing a measurement's share of BOQ.
  final BoqIndex boq;

  bool get canAct => item != null && item!.canAct && item!.reviewableHere && !gone && !finished;

  /// Approve is refused to anyone but the owner, so the button is not offered.
  bool get approveBlocked => item?.ownerOverride.blocksApprove ?? false;

  /// Buttons work only with a network: approvals are never queued.
  bool get actionsEnabled => canAct && online && !acting && failure != ApprovalFailure.raceLost;

  ApprovalDetailState copyWith({
    bool? online,
    bool? loading,
    ApprovalRow? item,
    bool? gone,
    bool? acting,
    bool? finished,
    bool? refreshing,
    Object? failure = _keep,
    Object? failureDetail = _keep,
    BoqIndex? boq,
  }) {
    return ApprovalDetailState(
      online: online ?? this.online,
      loading: loading ?? this.loading,
      item: item ?? this.item,
      gone: gone ?? this.gone,
      acting: acting ?? this.acting,
      finished: finished ?? this.finished,
      refreshing: refreshing ?? this.refreshing,
      failure: identical(failure, _keep) ? this.failure : failure as ApprovalFailure?,
      failureDetail: identical(failureDetail, _keep) ? this.failureDetail : failureDetail as String?,
      boq: boq ?? this.boq,
    );
  }

  static const Object _keep = Object();

  @override
  List<Object?> get props => [online, loading, item, gone, acting, finished, refreshing, failure, failureDetail, boq];
}

class ApprovalDetailCubit extends Cubit<ApprovalDetailState> {
  ApprovalDetailCubit({required AppServices services, required this._approvalId})
    : _services = services,
      super(ApprovalDetailState(online: services.connectivity.isOnline));

  final AppServices _services;
  final int _approvalId;
  StreamSubscription<List<ApprovalRow>>? _inbox;
  StreamSubscription<bool>? _connectivity;
  StreamSubscription<BoqIndex>? _boq;

  ApprovalRepository get _repo => _services.approvals;

  DateTime get now => _services.context.clock.now();

  void start() {
    _connectivity = _services.connectivity.changes.listen((online) {
      if (isClosed) return;
      emit(state.copyWith(online: online, failure: online ? state.failure : null));
    });
    _inbox = _repo.watchInbox().listen(_onInbox);
    _boq = watchBoqIndex(_services.db).listen((boq) {
      if (!isClosed) emit(state.copyWith(boq: boq));
    });
  }

  void _onInbox(List<ApprovalRow> rows) {
    if (isClosed) return;
    ApprovalRow? found;
    for (final ApprovalRow row in rows) {
      if (row.id == _approvalId) found = row;
    }
    if (found != null) {
      emit(state.copyWith(item: found, loading: false, gone: false));
    } else if (state.acting || state.finished) {
      // Our own action removed it. The document stays until the stamp is done.
      emit(state.copyWith(loading: false));
    } else {
      emit(state.copyWith(loading: false, gone: true));
    }
  }

  /// [overrideReason] is the owner's written reason, for a payment that
  /// only the owner may approve.
  Future<ActResult> approve({String? overrideReason}) {
    return _act(() => _repo.approve(_approvalId, overrideReason: overrideReason));
  }

  Future<ActResult> sendBack(String remark) {
    return _act(() => _repo.sendBack(_approvalId, remark: remark));
  }

  Future<ActResult> reject(String remark) {
    return _act(() => _repo.reject(_approvalId, remark: remark));
  }

  /// One action at a time. A second tap while the first is on its way, or
  /// after it has landed, does nothing at all.
  Future<ActResult> _act(Future<void> Function() call) async {
    if (state.acting || state.finished || state.item == null) return ActResult.ignored;
    if (!state.online) {
      emit(state.copyWith(failure: ApprovalFailure.offline, failureDetail: null));
      return ActResult.failed;
    }
    emit(state.copyWith(acting: true, failure: null, failureDetail: null));
    try {
      await call();
      if (!isClosed) emit(state.copyWith(acting: false, finished: true));
      return ActResult.done;
    } on OfflineFailure {
      _failed(ApprovalFailure.offline);
    } on AuthExpired {
      _failed(ApprovalFailure.session);
    } on ValidationFailure catch (failure) {
      _failed(ApprovalFailure.rejected, detail: failure.message);
    } on ServerFailure catch (failure) {
      final int? code = failure.statusCode;
      _failed(code == 403 || code == 404 || code == 409 ? ApprovalFailure.raceLost : ApprovalFailure.server);
    } on ApiFailure {
      _failed(ApprovalFailure.server);
    }
    return ActResult.failed;
  }

  void _failed(ApprovalFailure failure, {String? detail}) {
    if (!isClosed) {
      emit(state.copyWith(acting: false, failure: failure, failureDetail: detail));
    }
  }

  /// After losing a race, fetch what is actually waiting now.
  Future<void> refresh() async {
    if (state.refreshing || isClosed) return;
    emit(state.copyWith(refreshing: true));
    try {
      await _repo.refresh();
      if (!isClosed) emit(state.copyWith(refreshing: false, failure: null, failureDetail: null));
    } on OfflineFailure {
      if (!isClosed) emit(state.copyWith(refreshing: false, failure: ApprovalFailure.offline));
    } on ApiFailure {
      if (!isClosed) emit(state.copyWith(refreshing: false, failure: ApprovalFailure.server));
    }
  }

  @override
  Future<void> close() async {
    await _inbox?.cancel();
    await _connectivity?.cancel();
    await _boq?.cancel();
    return super.close();
  }
}
