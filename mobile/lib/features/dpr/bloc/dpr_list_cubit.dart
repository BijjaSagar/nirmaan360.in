import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show ProjectRow;
import '../data/dpr_repository.dart';

@immutable
class DprListState {
  const DprListState({
    this.loading = true,
    this.project,
    this.today = '',
    this.rows = const [],
  });

  final bool loading;
  final ProjectRow? project;

  /// Today in India, `YYYY-MM-DD`.
  final String today;
  final List<SyncedRecord<DprRow>> rows;

  bool get hasToday => rows.any((row) => row.record.reportDate == today);

  /// Days of the current month so far, today included.
  int get daysThisMonth => today.length < 10 ? 0 : int.parse(today.substring(8, 10));

  /// Days of the current month that have a report on this phone.
  int get filedThisMonth {
    if (today.length < 7) return 0;
    final month = today.substring(0, 7);
    return rows
        .map((row) => row.record.reportDate)
        .where((date) => date.startsWith(month) && date.compareTo(today) <= 0)
        .toSet()
        .length;
  }
}

/// Watches the current project and its reports, newest first.
class DprListCubit extends Cubit<DprListState> {
  DprListCubit(this._services) : super(const DprListState());

  final AppServices _services;
  StreamSubscription<ProjectRow?>? _projects;
  StreamSubscription<List<SyncedRecord<DprRow>>>? _reports;
  int? _projectId;

  void start() {
    _projects = _services.projects.watchCurrentProject().listen(_onProject);
  }

  void _onProject(ProjectRow? project) {
    if (project == null) {
      unawaited(_reports?.cancel());
      _reports = null;
      _projectId = null;
      emit(const DprListState(loading: false));
      return;
    }
    if (project.id == _projectId) return;
    _projectId = project.id;
    unawaited(_reports?.cancel());
    _reports = _services.dpr.watchList(project.id).listen((rows) {
      if (isClosed) return;
      emit(
        DprListState(
          loading: false,
          project: project,
          today: _services.context.today,
          rows: rows,
        ),
      );
    });
  }

  @override
  Future<void> close() async {
    await _projects?.cancel();
    await _reports?.cancel();
    return super.close();
  }
}
