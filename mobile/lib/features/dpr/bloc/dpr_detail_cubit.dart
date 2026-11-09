import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../../projects/data/project_repository.dart' show BoqLine;
import '../data/dpr_repository.dart';
import 'dpr_streams.dart';

@immutable
class DprDetailState {
  const DprDetailState({
    this.loading = true,
    this.details,
    this.project,
    this.boq = const {},
    this.online = true,
  });

  final bool loading;
  final DprDetails? details;
  final ProjectRow? project;

  /// BOQ lines by id, for the code, description and unit of each work row.
  final Map<int, BoqLine> boq;
  final bool online;
}

/// The row just tapped in the list, held for one navigation.
///
/// A hero only flies if its destination exists on the first frame of the new
/// route, and the database answers a few milliseconds too late for that. So the
/// list hands over what it already has, and the detail starts from it.
SyncedRecord<DprRow>? _primed;

/// Call just before opening the detail screen for [record].
void primeDprDetail(SyncedRecord<DprRow> record) => _primed = record;

DprDetailState _firstState(String clientUuid) {
  final record = _primed;
  _primed = null;
  if (record == null || record.record.clientUuid != clientUuid) {
    return const DprDetailState();
  }
  return DprDetailState(
    loading: false,
    details: DprDetails(record: record, photos: const []),
  );
}

/// One report, live. Status and sync badge follow the record as it moves.
class DprDetailCubit extends Cubit<DprDetailState> {
  DprDetailCubit(this._services, String clientUuid)
    : _clientUuid = clientUuid,
      super(_firstState(clientUuid));

  final AppServices _services;
  final String _clientUuid;
  StreamSubscription<DprDetails?>? _details;
  StreamSubscription<bool>? _connection;

  void start() {
    _details = _services.dpr.watchOne(_clientUuid).listen(_onDetails);
    emit(_withConnection(state));
    _connection = _services.connectivity.changes.listen((_) {
      if (!isClosed) emit(_withConnection(state));
    });
  }

  DprDetailState _withConnection(DprDetailState s) => DprDetailState(
    loading: s.loading,
    details: s.details,
    project: s.project,
    boq: s.boq,
    online: _services.connectivity.isOnline,
  );

  Future<void> _onDetails(DprDetails? details) async {
    if (details == null) {
      if (!isClosed) {
        emit(DprDetailState(loading: false, online: state.online));
      }
      return;
    }
    final projectId = details.dpr.projectId;
    var project = state.project;
    var boq = state.boq;
    final missing = details.dpr.workItems.any(
      (line) => line.boqItemId != null && !boq.containsKey(line.boqItemId),
    );
    if (project?.id != projectId) {
      project = await _services.projects.getProject(projectId);
    }
    if (missing) {
      final lines = await dprFirst(_services.projects.watchBoqItems(projectId));
      boq = {for (final line in lines) line.item.id: line};
    }
    if (isClosed) return;
    emit(
      DprDetailState(
        loading: false,
        details: details,
        project: project,
        boq: boq,
        online: state.online,
      ),
    );
  }

  @override
  Future<void> close() async {
    await _details?.cancel();
    await _connection?.cancel();
    return super.close();
  }
}
