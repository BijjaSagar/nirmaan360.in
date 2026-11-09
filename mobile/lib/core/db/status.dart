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
  static const locale = 'locale';

  /// The last site progress this phone read for a project, as JSON.
  static String progressOverview(int projectId) => 'progress.overview.$projectId';

  /// The floor and item last used on the progress form.
  static String progressLastPick(int projectId) => 'progress.last_pick.$projectId';
}
