import '../api/api_failure.dart';
import '../api/nirmaan_api.dart';
import '../db/app_database.dart';
import '../db/outbox_dao.dart';
import '../session/session_controller.dart';
import 'attachment_store.dart';
import 'clock.dart';
import 'connectivity.dart';
import 'sync_engine.dart';

/// What every repository needs, gathered in one place.
class DataContext {
  DataContext({
    required this.db,
    required this.api,
    required this.clock,
    required this.attachments,
    required this.connectivity,
    required this.session,
    required this.sync,
  }) : outbox = OutboxDao(db);

  final AppDatabase db;
  final OutboxDao outbox;
  final NirmaanApi api;
  final AppClock clock;
  final AttachmentStore attachments;
  final ConnectivityMonitor connectivity;
  final SessionController session;
  final SyncEngine sync;

  int? get currentUserId => session.user?.id;

  /// Today's calendar date in India, `YYYY-MM-DD`.
  String get today => istDate(clock.now());

  /// The next `client_updated_at` for a record whose newest known stamps are
  /// [previous] (local stamp and server `updated_at`).
  DateTime nextStamp(Iterable<DateTime?> previous) =>
      nextClientStamp(clock.now(), previous);

  /// The server refuses future dates with `before_or_equal:today`. Saying so
  /// now is kinder than a rejection a week later in the sync screen.
  void assertNotFuture(String date, String field) {
    if (date.compareTo(today) > 0) {
      throw ValidationFailure(
        'The date cannot be in the future.',
        errors: {
          field: ['The date cannot be in the future.'],
        },
      );
    }
  }
}
