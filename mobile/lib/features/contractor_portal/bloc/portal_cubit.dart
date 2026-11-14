import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../data/portal_repository.dart';

enum PortalLoad { loading, ready }

class PortalState extends Equatable {
  const PortalState({this.load = PortalLoad.loading, this.snapshot, this.refreshing = false});

  final PortalLoad load;

  /// Null until this phone has read the portal once.
  final PortalSnapshot? snapshot;
  final bool refreshing;

  PortalState copyWith({PortalLoad? load, PortalSnapshot? Function()? snapshot, bool? refreshing}) {
    return PortalState(
      load: load ?? this.load,
      snapshot: snapshot == null ? this.snapshot : snapshot(),
      refreshing: refreshing ?? this.refreshing,
    );
  }

  @override
  List<Object?> get props => <Object?>[load, snapshot, refreshing];
}

/// The last copy of the portal on the phone, read again whenever the server
/// can be reached. One cubit serves the home page, the lists and the two
/// detail pages; the page it is made for decides what it reads on opening.
class PortalCubit extends Cubit<PortalState> {
  PortalCubit(this._services, {this.contractId, this.billId}) : super(const PortalState()) {
    _cacheSub = _services.portal.watchCached().listen((snapshot) {
      if (!isClosed) emit(state.copyWith(load: PortalLoad.ready, snapshot: () => snapshot));
    });
    unawaited(_open());
  }

  final AppServices _services;

  /// The contract this page is about, when it is a contract page.
  final int? contractId;

  /// The bill this page is about, when it is a bill page.
  final int? billId;

  StreamSubscription<PortalSnapshot?>? _cacheSub;

  PortalRepository get _repo => _services.portal;

  /// Home reads when the copy is old. A detail page reads its own record, or
  /// everything when the phone has never seen it.
  Future<void> _open() async {
    if (!_services.connectivity.isOnline) return;
    final PortalSnapshot? kept = await _repo.read();
    final int? contract = contractId;
    final int? bill = billId;
    await _guarded(() {
      if (kept == null || kept.refused) return _repo.refresh();
      if (contract != null) {
        return kept.contract(contract) == null ? _repo.refresh() : _repo.refreshContract(contract);
      }
      if (bill != null) return kept.bill(bill) == null ? _repo.refresh() : _repo.refreshBill(bill);
      return _repo.refreshIfStale();
    });
  }

  Future<bool> _guarded(Future<void> Function() read) async {
    if (isClosed) return false;
    emit(state.copyWith(refreshing: true));
    try {
      await read();
      return true;
    } on ApiFailure {
      return false;
    } finally {
      if (!isClosed) emit(state.copyWith(refreshing: false));
    }
  }

  /// Pull to refresh. False means the phone is showing what it already had.
  Future<bool> refresh() async {
    if (!_services.connectivity.isOnline) return false;
    return _guarded(_repo.refresh);
  }

  @override
  Future<void> close() async {
    await _cacheSub?.cancel();
    return super.close();
  }
}
