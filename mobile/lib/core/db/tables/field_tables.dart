import 'package:drift/drift.dart';

import '../converters.dart';

/// Field tables use `client_uuid` as primary key with a nullable `server_id`.
///
/// A row that came from the server with no `client_uuid` gets a stable UUID v5
/// of `<type>:<server_id>` (see core/sync/stable_uuid.dart).

@DataClassName('DprRow')
@TableIndex(name: 'dprs_project_date', columns: {#projectId, #reportDate})
@TableIndex(name: 'dprs_server_id', columns: {#serverId})
@TableIndex(name: 'dprs_status', columns: {#status})
class Dprs extends Table {
  TextColumn get clientUuid => text()();
  IntColumn get serverId => integer().nullable()();
  IntColumn get projectId => integer()();
  IntColumn get siteId => integer().nullable()();
  TextColumn get reportDate => text()();
  TextColumn get weather => text().nullable()();
  TextColumn get summary => text().nullable()();
  TextColumn get hindrances => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('DRAFT'))();

  /// True while the user has edits that are saved here but not queued.
  /// A pull must treat this the same as an unsent outbox item.
  BoolColumn get localDraft => boolean().withDefault(const Constant(false))();
  IntColumn get createdBy => integer().nullable()();
  TextColumn get createdByName => text().nullable()();
  TextColumn get manpower => text().map(const ManpowerLinesConverter())();
  TextColumn get machinery => text().map(const MachineryLinesConverter())();
  TextColumn get workItems => text().map(const WorkLinesConverter())();
  TextColumn get returnRemark => text().nullable()();
  DateTimeColumn get clientUpdatedAt => dateTime().nullable()();
  DateTimeColumn get updatedAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {clientUuid};
}

@DataClassName('DprPhotoRow')
@TableIndex(name: 'dpr_photos_dpr', columns: {#dprClientUuid})
@TableIndex(name: 'dpr_photos_server_id', columns: {#serverId})
class DprPhotos extends Table {
  TextColumn get clientUuid => text()();
  IntColumn get serverId => integer().nullable()();
  TextColumn get dprClientUuid => text()();

  /// Relative to the app documents directory, because the iOS container
  /// path changes between launches. Null once the file exists only remotely.
  TextColumn get relativePath => text().nullable()();
  TextColumn get caption => text().nullable()();
  DateTimeColumn get takenAt => dateTime().nullable()();
  DateTimeColumn get createdAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {clientUuid};
}

@DataClassName('MeasurementRow')
@TableIndex(
  name: 'measurements_project_status_date',
  columns: {#projectId, #status, #measuredOn},
)
@TableIndex(name: 'measurements_boq', columns: {#boqItemId})
@TableIndex(name: 'measurements_server_id', columns: {#serverId})
class Measurements extends Table {
  TextColumn get clientUuid => text()();
  IntColumn get serverId => integer().nullable()();
  IntColumn get projectId => integer()();
  IntColumn get boqItemId => integer()();
  IntColumn get workOrderId => integer().nullable()();
  TextColumn get measuredOn => text()();
  TextColumn get description => text().nullable()();
  TextColumn get location => text().nullable()();
  TextColumn get nos => text().map(const QtyConverter()).nullable()();
  TextColumn get length => text().map(const QtyConverter()).nullable()();
  TextColumn get breadth => text().map(const QtyConverter()).nullable()();
  TextColumn get depth => text().map(const QtyConverter()).nullable()();
  BoolColumn get isDeduction => boolean().withDefault(const Constant(false))();

  /// Local preview until the server's figure replaces it on the next pull.
  TextColumn get qty => text().map(const QtyConverter())();

  /// What the server's ledger counted for this entry at the last pull.
  /// Null means the ledger has not seen it yet. Drives the balance preview.
  TextColumn get serverQty => text().map(const QtyConverter()).nullable()();
  TextColumn get status => text().withDefault(const Constant('DRAFT'))();
  BoolColumn get deviation => boolean().withDefault(const Constant(false))();
  TextColumn get remarks => text().nullable()();
  TextColumn get returnRemark => text().nullable()();
  IntColumn get createdBy => integer().nullable()();
  DateTimeColumn get clientUpdatedAt => dateTime().nullable()();
  DateTimeColumn get updatedAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {clientUuid};
}

@DataClassName('IssueRow')
@TableIndex(name: 'issues_project_status', columns: {#projectId, #status})
@TableIndex(name: 'issues_server_id', columns: {#serverId})
class Issues extends Table {
  TextColumn get clientUuid => text()();
  IntColumn get serverId => integer().nullable()();
  IntColumn get projectId => integer()();
  IntColumn get siteId => integer().nullable()();
  TextColumn get title => text()();
  TextColumn get description => text().nullable()();
  TextColumn get priority => text().withDefault(const Constant('medium'))();
  TextColumn get status => text().withDefault(const Constant('open'))();
  TextColumn get locationNote => text().nullable()();
  IntColumn get assignedTo => integer().nullable()();
  IntColumn get raisedBy => integer().nullable()();
  TextColumn get dueDate => text().nullable()();
  DateTimeColumn get resolvedAt => dateTime().nullable()();
  DateTimeColumn get clientUpdatedAt => dateTime().nullable()();
  DateTimeColumn get updatedAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {clientUuid};
}

@DataClassName('AttendanceMarkRow')
@TableIndex(
  name: 'attendance_project_date',
  columns: {#projectId, #attendanceDate},
)
@TableIndex(name: 'attendance_server_id', columns: {#serverId})
class AttendanceMarks extends Table {
  TextColumn get clientUuid => text()();
  IntColumn get serverId => integer().nullable()();
  IntColumn get projectId => integer()();
  IntColumn get workerId => integer()();
  TextColumn get attendanceDate => text()();
  TextColumn get status => text()();
  TextColumn get otHours => text().map(const HoursConverter())();
  IntColumn get markedBy => integer().nullable()();
  DateTimeColumn get clientUpdatedAt => dateTime().nullable()();
  DateTimeColumn get updatedAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {clientUuid};

  @override
  List<Set<Column>> get uniqueKeys => [
    {projectId, workerId, attendanceDate},
  ];
}

@DataClassName('PettyCashEntryRow')
@TableIndex(name: 'petty_wallet_date', columns: {#walletId, #entryDate})
@TableIndex(name: 'petty_server_id', columns: {#serverId})
class PettyCashEntries extends Table {
  TextColumn get clientUuid => text()();
  IntColumn get serverId => integer().nullable()();
  IntColumn get walletId => integer()();
  IntColumn get projectId => integer().nullable()();
  TextColumn get type => text().withDefault(const Constant('expense'))();
  TextColumn get amount => text().map(const MoneyConverter())();
  TextColumn get category => text().nullable()();
  TextColumn get description => text().nullable()();
  TextColumn get entryDate => text()();
  IntColumn get createdBy => integer().nullable()();
  BoolColumn get hasBill => boolean().withDefault(const Constant(false))();

  /// Relative path of the bill photo while it waits for upload.
  TextColumn get billRelativePath => text().nullable()();

  /// True once a pull has confirmed the wallet balance includes this entry.
  BoolColumn get countedInBalance =>
      boolean().withDefault(const Constant(false))();
  DateTimeColumn get updatedAt => dateTime().nullable()();

  @override
  Set<Column> get primaryKey => {clientUuid};
}

/// Items the user can act on. Replaced whole on every pull or refresh.
@DataClassName('ApprovalRow')
@TableIndex(name: 'approvals_submitted', columns: {#submittedAt})
class ApprovalsInbox extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get docType => text()();
  TextColumn get status => text()();
  TextColumn get title => text()();
  IntColumn get projectId => integer().nullable()();
  TextColumn get projectCode => text().nullable()();
  TextColumn get projectName => text().nullable()();
  IntColumn get submittedById => integer().nullable()();
  TextColumn get submittedByName => text().nullable()();
  DateTimeColumn get submittedAt => dateTime().nullable()();
  IntColumn get currentLevel => integer().nullable()();
  TextColumn get levels => text().map(const StringListConverter())();
  TextColumn get currentRole => text().nullable()();
  TextColumn get currentRoleLabel => text().nullable()();
  BoolColumn get canAct => boolean().withDefault(const Constant(false))();
  TextColumn get document => text().map(const JsonMapConverter())();
  TextColumn get steps => text().map(const JsonListConverter())();
}
