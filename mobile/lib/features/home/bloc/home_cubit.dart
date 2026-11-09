import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/record_sync.dart';
import '../../projects/data/project_repository.dart';
import '../../sync_center/data/sync_repository.dart';
import '../data/home_repository.dart';

enum ReturnedKind { dpr, measurement }

/// A document sent back to this user, ready for the home screen.
class ReturnedItem extends Equatable {
  const ReturnedItem({
    required this.kind,
    required this.uuid,
    required this.date,
    this.remark,
    this.code,
    this.qty,
    this.unit,
  });

  final ReturnedKind kind;
  final String uuid;

  /// Report date or measured date, `YYYY-MM-DD`.
  final String date;
  final String? remark;

  /// For a measurement: BOQ code, quantity and unit.
  final String? code;
  final String? qty;
  final String? unit;

  @override
  List<Object?> get props => <Object?>[kind, uuid, date, remark, code, qty, unit];
}

class HomeState extends Equatable {
  const HomeState({
    this.summary,
    this.returned = const <ReturnedItem>[],
    this.approvalsCount = 0,
  });

  /// Null until the first read of the day's figures.
  final HomeSummary? summary;
  final List<ReturnedItem> returned;
  final int approvalsCount;

  @override
  List<Object?> get props => <Object?>[summary, returned, approvalsCount];
}

/// Today's picture for one project, live from the local database.
class HomeCubit extends Cubit<HomeState> {
  HomeCubit({required this._services, required this.projectId}) : super(const HomeState()) {
    final home = _services.home;
    _subscriptions.addAll(<StreamSubscription<Object?>>[
      home.watchHomeSummary(projectId).listen(_onSummary),
      _services.dpr.watchList(projectId).listen((rows) {
        _dprs = rows;
        _publishReturned();
      }),
      _services.measurements
          .watchByProject(projectId, status: DocStatus.returned)
          .listen((rows) {
        _measurements = rows;
        _publishReturned();
      }),
      _services.projects.watchBoqItems(projectId).listen((lines) {
        _boq = <int, BoqLine>{for (final line in lines) line.item.id: line};
        _publishReturned();
      }),
      _services.syncCenter.watchOutbox().listen((items) {
        _queued = items;
        _publishReturned();
      }),
      _services.approvals.watchCount().listen(_onApprovals),
    ]);
  }

  final AppServices _services;
  final int projectId;
  final List<StreamSubscription<Object?>> _subscriptions = <StreamSubscription<Object?>>[];

  List<SyncedRecord<DprRow>> _dprs = const <SyncedRecord<DprRow>>[];
  List<SyncedRecord<MeasurementRow>> _measurements = const <SyncedRecord<MeasurementRow>>[];
  Map<int, BoqLine> _boq = const <int, BoqLine>{};
  List<OutboxItem> _queued = const <OutboxItem>[];

  void _onSummary(HomeSummary summary) {
    if (!isClosed) {
      emit(HomeState(summary: summary, returned: state.returned, approvalsCount: state.approvalsCount));
    }
  }

  void _onApprovals(int count) {
    if (!isClosed) {
      emit(HomeState(summary: state.summary, returned: state.returned, approvalsCount: count));
    }
  }

  void _publishReturned() {
    if (isClosed) return;
    final int? me = _services.auth.currentUser?.id;

    // A record whose correction is already on its way needs nothing more from
    // the user, even though the server still says RETURNED until the next pull.
    final Set<String> resubmitting = <String>{
      for (final item in _queued)
        if (!item.needsAttention &&
            (item.type == OutboxType.dprSubmit || item.type == OutboxType.measurementSubmit))
          item.row.recordUuid,
    };

    final List<ReturnedItem> returned = <ReturnedItem>[
      for (final record in _dprs)
        if (record.record.status == DocStatus.returned &&
            record.record.createdBy == me &&
            !resubmitting.contains(record.record.clientUuid))
          ReturnedItem(
            kind: ReturnedKind.dpr,
            uuid: record.record.clientUuid,
            date: record.record.reportDate,
            remark: record.record.returnRemark,
          ),
      for (final record in _measurements)
        if (record.record.createdBy == me && !resubmitting.contains(record.record.clientUuid))
          ReturnedItem(
            kind: ReturnedKind.measurement,
            uuid: record.record.clientUuid,
            date: record.record.measuredOn,
            remark: record.record.returnRemark,
            code: _boq[record.record.boqItemId]?.item.code,
            unit: _boq[record.record.boqItemId]?.item.unit,
            qty: record.record.qty.format(),
          ),
    ]..sort((a, b) => b.date.compareTo(a.date));

    emit(HomeState(summary: state.summary, returned: returned, approvalsCount: state.approvalsCount));
  }

  @override
  Future<void> close() async {
    for (final subscription in _subscriptions) {
      await subscription.cancel();
    }
    return super.close();
  }
}
