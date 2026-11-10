import 'dart:convert';

import '../api/api_failure.dart';
import '../api/nirmaan_api.dart';
import '../db/app_database.dart';
import '../db/outbox_dao.dart';
import '../offline/offline_plan.dart';
import '../session/session_controller.dart';
import 'attachment_store.dart';
import 'clock.dart';
import 'connectivity.dart';
import 'ids.dart';
import 'record_photos.dart';
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
    required this.offlinePlan,
  }) : outbox = OutboxDao(db);

  final AppDatabase db;
  final OutboxDao outbox;
  final NirmaanApi api;
  final AppClock clock;
  final AttachmentStore attachments;
  final ConnectivityMonitor connectivity;
  final SessionController session;
  final SyncEngine sync;
  final OfflinePlan offlinePlan;

  /// Photos on issues: the server's lists, the files and the queue.
  late final RecordPhotos photos = RecordPhotos(db: db, api: api, attachments: attachments, clock: clock);

  int? get currentUserId => session.user?.id;

  /// Throws [LicenceEnded] once an offline licence has ended; does nothing for a
  /// company on a normal plan. Every path that adds or changes a record on the
  /// phone calls this first, before it touches anything.
  void assertCanWrite() => offlinePlan.assertCanWrite();

  /// Today's calendar date in India, `YYYY-MM-DD`.
  String get today => istDate(clock.now());

  /// The next `client_updated_at` for a record whose newest known stamps are
  /// [previous] (local stamp and server `updated_at`).
  DateTime nextStamp(Iterable<DateTime?> previous) =>
      nextClientStamp(clock.now(), previous);

  /// Creates a master on the server. Online only, by design.
  ///
  /// Throws [OfflineFailure] when offline. The `client_uuid` is kept in the
  /// meta table under [identity] until the reply has been stored, so a retry
  /// after a lost reply gets the original row back instead of a second one.
  ///
  /// [identity] holds only what makes this the same thing: amounts and notes
  /// stay out, so correcting one before retrying still cannot create a twin.
  Future<T> createOnline<T>({
    required List<Object?> identity,
    required Future<Map<String, dynamic>> Function(String clientUuid) send,
    required Future<T> Function(Map<String, dynamic> row) store,
  }) async {
    assertCanWrite();
    if (!connectivity.isOnline) throw const OfflineFailure();

    final normalised = identity
        .map((part) => part?.toString().trim().toLowerCase() ?? '')
        .join('|');
    final key = 'idempotency.setup.${base64Url.encode(utf8.encode(normalised))}';

    var clientUuid = await db.readMeta(key);
    if (clientUuid == null) {
      clientUuid = newUuid();
      await db.writeMeta(key, clientUuid);
    }

    final row = await send(clientUuid);

    return db.transaction(() async {
      final result = await store(row);
      await db.deleteMeta(key);
      return result;
    });
  }

  /// Creates a master that exists only on this phone, for the offline plan.
  ///
  /// Its id is negative, so it can never clash with a server id. Beside it is
  /// kept the `client_uuid` the upload will use, so a retry cannot duplicate
  /// it, and anything [extras] holds that the local table has no column for.
  Future<T> createLocal<T>({
    required String kind,
    required String table,
    Map<String, Object?> extras = const <String, Object?>{},
    required Future<T> Function(int localId) insert,
  }) async {
    assertCanWrite();
    return db.transaction(() async {
      final row = await db.customSelect('SELECT MIN(COALESCE(MIN(id), 0), 0) - 1 AS next FROM $table').getSingle();
      final int localId = row.read<int>('next');
      await db.writeMeta(
        MetaKey.offlineRecord(kind, localId),
        jsonEncode(<String, Object?>{'uuid': newUuid(), ...extras}),
      );
      return insert(localId);
    });
  }

  /// The same limits the server applies, because a record kept here has to be
  /// accepted if the owner uploads it one day. [fields] is field, text, longest.
  void assertWithin(Iterable<(String, String?, int)> fields) {
    for (final (String field, String? value, int longest) in fields) {
      if (value != null && value.runes.length > longest) {
        final String message = 'This must not be longer than $longest characters.';
        throw ValidationFailure(message, errors: {field: [message]});
      }
    }
  }

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
