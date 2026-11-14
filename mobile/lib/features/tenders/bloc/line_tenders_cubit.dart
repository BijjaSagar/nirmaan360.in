import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/api/api_failure.dart';
import '../../../core/app_services.dart';
import '../data/tender_repository.dart';

enum LineTendersLoad { loading, ready, offline, refused, failed }

class LineTendersState {
  const LineTendersState({this.load = LineTendersLoad.loading, this.line, this.refreshing = false});

  final LineTendersLoad load;

  /// The last copy read. Kept on screen while a refresh runs or fails.
  final LineTenders? line;
  final bool refreshing;

  LineTendersState copyWith({LineTendersLoad? load, LineTenders? line, bool? refreshing}) {
    return LineTendersState(
      load: load ?? this.load,
      line: line ?? this.line,
      refreshing: refreshing ?? this.refreshing,
    );
  }
}

/// One BOQ line's tenders and quotes, read live.
class LineTendersCubit extends Cubit<LineTendersState> {
  LineTendersCubit(AppServices services, {required this.boqItemId})
    : repository = TenderRepository(services.context),
      super(const LineTendersState()) {
    refresh();
  }

  final int boqItemId;
  final TenderRepository repository;

  /// Reads the line again. Returns whether the server answered.
  Future<bool> refresh() async {
    if (!repository.canView) {
      emit(state.copyWith(load: LineTendersLoad.refused));
      return false;
    }
    emit(state.copyWith(refreshing: true));
    try {
      final LineTenders line = await repository.line(boqItemId);
      if (isClosed) return true;
      emit(LineTendersState(load: LineTendersLoad.ready, line: line));
      return true;
    } on OfflineFailure {
      if (!isClosed) _keepOr(LineTendersLoad.offline);
    } on ServerFailure catch (e) {
      if (isClosed) return false;
      if (e.statusCode == 403 || e.statusCode == 404) {
        emit(const LineTendersState(load: LineTendersLoad.refused));
      } else {
        _keepOr(LineTendersLoad.failed);
      }
    } on ApiFailure {
      if (!isClosed) _keepOr(LineTendersLoad.failed);
    }
    return false;
  }

  /// A failed refresh leaves what was on screen, and says so only when nothing was.
  void _keepOr(LineTendersLoad load) {
    emit(state.line == null ? LineTendersState(load: load) : state.copyWith(refreshing: false));
  }
}
