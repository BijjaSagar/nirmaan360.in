import 'dart:async';

import 'package:flutter_bloc/flutter_bloc.dart';

import '../../features/sync_center/data/sync_repository.dart';

/// One live copy of the sync status for the whole app, so the shell, the home
/// header and the outbox do not each run their own count queries.
class SyncStatusCubit extends Cubit<SyncStatus> {
  SyncStatusCubit(SyncRepository repository) : super(repository.currentStatus) {
    _subscription = repository.status.listen(emit);
  }

  late final StreamSubscription<SyncStatus> _subscription;

  @override
  Future<void> close() async {
    await _subscription.cancel();
    return super.close();
  }
}
