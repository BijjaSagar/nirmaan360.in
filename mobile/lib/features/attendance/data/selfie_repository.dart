import 'dart:async';
import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:path/path.dart' as p;

import '../../../core/api/api_failure.dart';
import '../../../core/db/app_database.dart';
import '../../../core/sync/clock.dart';
import '../../../core/sync/data_context.dart';
import '../../../core/sync/ids.dart';
import 'selfie_models.dart';

export 'selfie_models.dart';

/// Attendance by a photo of the worker taken at the site.
///
/// The server marks the worker present when the photo's place is inside the site's
/// fence, and keeps the photo, the place and the consent note it showed. The phone
/// cannot check the fence itself: the site's position and radius are not in what
/// the server sends, so the answer, with the distance, always comes from the server.
///
/// **A photo can be queued offline.** The server dates the mark by `captured_at`,
/// not by when the photo arrives, and accepts one up to seven days old, which is
/// what it means by "a phone can be offline for up to a week". The place was read
/// when the photo was taken. What the server can still refuse when it arrives (too far
/// from the site, a rough reading, a photo for that worker and day already there,
/// older than seven days) goes to the Sync Center in its own words.
class SelfieRepository {
  SelfieRepository(this._ctx);

  final DataContext _ctx;

  AppDatabase get _db => _ctx.db;

  /// The server's limit on how old a photo may be when it arrives.
  static const Duration oldestAllowed = Duration(days: 7);

  /// `attendance.manage` on a plan with `labour`, never for a contractor login or a
  /// phone that keeps its data local.
  bool get isOpenToUser {
    final user = _ctx.session.user;
    return user != null &&
        !user.isContractor &&
        !_ctx.offlinePlan.isLocalOnly &&
        user.can('attendance.manage') &&
        user.features.contains('labour');
  }

  // Consent ----------------------------------------------------------------------

  Future<SelfieConsent?> readConsent() async {
    final String? raw = await _db.readMeta(MetaKey.selfieConsent);
    if (raw == null) return null;
    try {
      return SelfieConsent.tryFromJson(jsonDecode(raw));
    } on FormatException {
      return null;
    }
  }

  /// The note from the server when it can be reached, else the last copy kept.
  Future<SelfieConsent?> loadConsent() async {
    if (_ctx.connectivity.isOnline) {
      try {
        final SelfieConsent? fresh = SelfieConsent.tryFromJson(await _ctx.api.attendanceSelfieConsent());
        if (fresh != null) {
          await _db.writeMeta(MetaKey.selfieConsent, jsonEncode(fresh.toJson()));
          return fresh;
        }
      } on ApiFailure {
        // The kept copy below is what there is.
      }
    }
    return readConsent();
  }

  String? get _acceptedKey {
    final int? user = _ctx.currentUserId;
    return user == null ? null : '$user|';
  }

  /// Whether this person has accepted exactly this version of the note on this phone.
  Future<bool> hasAccepted(SelfieConsent consent) async {
    final String? prefix = _acceptedKey;
    return prefix != null && await _db.readMeta(MetaKey.selfieConsentAccepted) == '$prefix${consent.version}';
  }

  /// Written when the note has been shown and the worker has agreed. A new version asks again.
  Future<void> accept(SelfieConsent consent) async {
    _ctx.assertCanWrite();
    final String? prefix = _acceptedKey;
    if (prefix != null) await _db.writeMeta(MetaKey.selfieConsentAccepted, '$prefix${consent.version}');
  }

  // Reading ----------------------------------------------------------------------

  Future<List<SiteRow>> readSites(int projectId) =>
      (_db.select(_db.sites)..where((t) => t.projectId.equals(projectId))..orderBy([(t) => OrderingTerm.asc(t.name)])).get();

  Future<List<WorkerRow>> readWorkers() =>
      (_db.select(_db.workers)..where((t) => t.isActive.equals(true))..orderBy([(t) => OrderingTerm.asc(t.name)])).get();

  // Sending ----------------------------------------------------------------------

  /// Marks [worker] present from the photo at [photoPath], taken at [fix] at [capturedAt].
  ///
  /// With a network the server answers at once, and a refusal is shown rather than
  /// queued: the person is still at the site and can move and take it again. When the
  /// network is missing, or the reply does not come, the photo is copied into app storage
  /// and kept under the same `client_uuid`, so a reply lost after the server stored it
  /// comes back as the first row and makes no second photo or mark.
  Future<SelfieOutcome> submit({
    required int projectId,
    required int siteId,
    required WorkerRow worker,
    required String photoPath,
    required SelfieFix fix,
    required DateTime capturedAt,
    required SelfieConsent consent,
  }) async {
    _ctx.assertCanWrite();
    final String uuid = newUuid();
    final Map<String, String> fields = <String, String>{
      'site_id': '$siteId',
      'worker_id': '${worker.id}',
      'lat': fix.lat,
      'lng': fix.lng,
      'accuracy_m': fix.accuracy,
      'captured_at': toWireTimestamp(capturedAt),
      'consent': '1',
      'consent_version': consent.version,
    };

    if (_ctx.connectivity.isOnline) {
      try {
        final Map<String, dynamic> reply = await _ctx.api.uploadFieldPhoto(
          '/projects/$projectId/attendance/selfies',
          filePath: photoPath,
          clientUuid: uuid,
          fileField: 'selfie',
          fields: fields,
        );
        // The mark reaches the muster with the next pull, which this asks for now.
        unawaited(_ctx.sync.syncNow());
        return SelfieOutcome(
          SelfieResult.marked,
          workerName: worker.name,
          distance: (reply['distance_m'] as num?)?.toInt(),
          radius: (reply['radius_m'] as num?)?.toInt(),
        );
      } on ValidationFailure catch (e) {
        final List<String> words = <String>[for (final List<String> list in e.errors.values) ...list];
        return SelfieOutcome(SelfieResult.refused, workerName: worker.name, message: words.isEmpty ? e.message : words.first);
      } on ServerFailure catch (e) {
        if (e.statusCode == 403 || e.statusCode == 404) {
          return SelfieOutcome(SelfieResult.refused, workerName: worker.name, message: e.message);
        }
      } on ApiFailure {
        // No network after all, or a busy server: keep it below.
      }
    }

    final String extension = p.extension(photoPath);
    final String kept = await _ctx.attachments.importFile(
      photoPath,
      folder: 'attendance_selfies',
      fileName: '$uuid${extension.isEmpty ? '.jpg' : extension}',
    );
    try {
      await _db.transaction(
        () => _ctx.outbox.enqueue(
          type: OutboxType.attendanceSelfie,
          wireUuid: uuid,
          recordUuid: uuid,
          groupUuid: uuid,
          projectId: projectId,
          filePath: kept,
          data: <String, dynamic>{
            ...fields,
            // The day it was taken, India time, to show the worker as present on that day.
            'scan_date': istDate(capturedAt),
            'local': <String, dynamic>{'worker_id': worker.id, 'worker_name': worker.name},
          },
          createdAt: _ctx.clock.now(),
          title: worker.name,
          subtitle: istDate(capturedAt),
        ),
      );
    } on Object {
      await _ctx.attachments.delete(kept);
      rethrow;
    }
    return SelfieOutcome(SelfieResult.queued, workerName: worker.name);
  }
}
