/// Approval status of a measurement or DPR, as the server reports it.
abstract final class DocStatus {
  static const draft = 'DRAFT';
  static const inReview = 'IN_REVIEW';
  static const approved = 'APPROVED';
  static const returned = 'RETURNED';
  static const rejected = 'REJECTED';
}

/// Attendance values from `config('buildos.attendance_statuses')`.
abstract final class AttendanceStatus {
  static const present = 'present';
  static const half = 'half';
  static const absent = 'absent';
  static const overtime = 'overtime';
}

/// Keys in the key-value table.
abstract final class MetaKey {
  static const watermark = 'sync.watermark';
  static const lastSyncedAt = 'sync.last_synced_at';
  static const lease = 'sync.lease';

  /// Set when a row must be re-read from the server: see the sync engine.
  static const fullRefresh = 'sync.full_refresh';

  /// Epoch milliseconds before which the server asked not to be contacted.
  static const rateLimitedUntil = 'sync.rate_limited_until';

  /// JSON map of record uuid to kind, for rows soft-deleted on the server
  /// while they still had unsent work.
  static const tombstones = 'sync.tombstones';
  static const lookups = 'sync.lookups';
  static const sessionUser = 'session.user';
  static const selectedProject = 'selected_project';

  /// The selfie consent note the server last sent: `{version, text}`. Kept so it can be
  /// shown with no network once it has been loaded.
  static const selfieConsent = 'selfie.consent';

  /// Who accepted which version of the note on this phone: `<user id>|<version>`.
  static const selfieConsentAccepted = 'selfie.consent.accepted';
  static const locale = 'locale';

  /// The offline plan's state, as JSON. Absent for a company on any normal plan.
  static const offlinePlan = 'offline.state';

  /// The stable id this install holds its licence under. Kept through a sign-out.
  static const offlineDeviceId = 'offline.device_id';

  /// The latest moment this phone has seen, as ISO 8601. The licence is judged
  /// against the later of this and the clock, so setting the clock back gains nothing.
  static const offlineSeen = 'offline.seen';

  /// The upload's `client_uuid` and details that have no column, for one local record.
  static String offlineRecord(String kind, int localId) => 'offline.record.$kind.$localId';

  /// The last site progress this phone read for a project, as JSON.
  static String progressOverview(int projectId) => 'progress.overview.$projectId';

  /// The last material check this phone read for a project, as JSON.
  static String materialOverview(int projectId) => 'material.overview.$projectId';

  /// The last site dashboard and open alerts this phone read for a project, as JSON.
  static String siteOverview(int projectId) => 'site.overview.$projectId';

  /// The last contract board and payments this phone read for a project, as JSON.
  static String contractorOverview(int projectId) => 'contractor.overview.$projectId';

  /// The last contractor portal this phone read, as JSON. One per phone: a
  /// contractor login has no projects, and signing out clears it.
  static const portal = 'portal.snapshot';

  /// The company's price board and each material's recorded prices, as JSON.
  /// One per phone. It is dropped when the money permission is lost.
  static const priceWatch = 'price_watch.board';

  /// Prefix of every price watch key, so they can be cleared together.
  static const priceWatchPrefix = 'price_watch.';

  /// One project's budget effect from the recorded prices, as JSON. Needs `money.overview`.
  static String priceWatchProject(int projectId) => 'price_watch.project.$projectId';

  /// The checklists and recent inspections this phone read for a project, as JSON.
  static String inspections(int projectId) => 'inspections.$projectId';

  /// The machines and the latest machine logs this phone read for a project, as JSON.
  static String machines(int projectId) => 'machines.$projectId';

  /// Inspections being filled in on this phone and not finished, by their uuid, as JSON.
  /// They are work the server has never seen, so a sign-out counts them.
  static String inspectionDrafts(int projectId) => 'inspection.drafts.$projectId';

  /// Prefix of every draft key, so they can be counted together.
  static const inspectionDraftsPrefix = 'inspection.drafts.';

  /// The recent progress entries the server listed for each floor of a project, as JSON.
  static String floorEntries(int projectId) => 'floor_entries.$projectId';

  /// The lists of photos the server holds for a project's issues, as JSON.
  static String recordPhotos(int projectId) => 'record_photos.$projectId';

  /// The address of the photo kept on this phone for a project.
  static const projectImagePrefix = 'project.image.';
  static String projectImage(int projectId) => '$projectImagePrefix$projectId';

  /// The floor and item last used on the progress form.
  static String progressLastPick(int projectId) => 'progress.last_pick.$projectId';
}
