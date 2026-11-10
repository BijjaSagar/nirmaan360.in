import 'dart:async';

import 'package:equatable/equatable.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/offline/offline_plan.dart';
import '../data/offline_upload.dart';

class OfflineUploadState extends Equatable {
  const OfflineUploadState({this.work, this.running = false, this.done = 0, this.total = 0, this.stop, this.detail, this.finished = false});

  final OfflineWork? work;
  final bool running;
  final int done;
  final int total;
  final UploadStop? stop;
  final String? detail;

  /// Every record has moved. The app goes back to normal now.
  final bool finished;

  OfflineUploadState copyWith({OfflineWork? work, bool? running, int? done, int? total, UploadStop? stop, String? detail, bool clearStop = false, bool? finished}) {
    return OfflineUploadState(
      work: work ?? this.work,
      running: running ?? this.running,
      done: done ?? this.done,
      total: total ?? this.total,
      stop: clearStop ? null : (stop ?? this.stop),
      detail: clearStop ? null : (detail ?? this.detail),
      finished: finished ?? this.finished,
    );
  }

  @override
  List<Object?> get props => <Object?>[work, running, done, total, stop, detail, finished];
}

/// The owner's answer, and the upload that follows a yes.
class OfflineUploadCubit extends Cubit<OfflineUploadState> {
  OfflineUploadCubit(this._services) : super(const OfflineUploadState()) {
    _uploader = _services.offlineUploader;
    unawaited(_loadWork());
  }

  final AppServices _services;
  late final OfflineUploader _uploader;

  Future<void> _loadWork() async {
    final OfflineWork work = await _services.offlinePlan.work();
    if (!isClosed) emit(state.copyWith(work: work));
  }

  /// No upload starts anywhere but here, and only a person's tap gets here.
  Future<void> accept() async {
    if (state.running) return;
    emit(state.copyWith(running: true, clearStop: true));
    _uploader.onProgress = ((int, int) progress) {
      if (!isClosed) emit(state.copyWith(done: progress.$1, total: progress.$2));
    };
    final UploadReport report = await _uploader.run();
    _uploader.onProgress = null;
    if (report.isReady) {
      if (!isClosed) emit(state.copyWith(running: false, finished: true));
      // Not tied to this screen: the app moves on while the entries go up.
      unawaited(_uploader.finish(_services.sync));
      return;
    }
    await _loadWork();
    if (!isClosed) emit(state.copyWith(running: false, stop: report.stop, detail: report.detail));
  }

  Future<void> decline() => _services.offlinePlan.decline();
}
