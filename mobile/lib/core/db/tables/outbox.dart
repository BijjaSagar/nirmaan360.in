import 'package:drift/drift.dart';

/// Lifecycle of one outbox row.
enum OutboxStatus { pending, sending, done, attention }

/// Operation types, named after the wire `type`.
enum OutboxType {
  measurement('measurement'),
  measurementSubmit('measurement_submit'),
  dpr('dpr'),
  dprSubmit('dpr_submit'),
  dprPhoto('dpr_photo'),
  issue('issue'),
  issueStatus('issue_status'),
  attendance('attendance'),
  pettyCash('petty_cash'),
  pettyBill('petty_bill'),
  progressEntry('progress_entry'),
  progressResolve('progress_resolve'),
  progressPhoto('progress_photo'),
  stageCell('stage_cell'),
  stagePhoto('stage_photo'),
  materialChallan('material_challan'),
  materialUse('material_use'),
  stockCount('stock_count'),
  issuePhoto('issue_photo'),
  measurementPhoto('measurement_photo'),
  progressCorrection('progress_correction'),
  contractorPayment('contractor_payment');

  const OutboxType(this.wire);

  final String wire;

  /// File uploads go through `/sync/upload`, one at a time.
  bool get isFile => this == OutboxType.dprPhoto || this == OutboxType.pettyBill;

  /// Site progress, material, contractor payments and the photos on issues and measurements have their own endpoints. Each row is
  /// posted on its own, with the same `client_uuid` on every attempt.
  bool get isDirect => switch (this) {
    OutboxType.progressEntry ||
    OutboxType.progressResolve ||
    OutboxType.progressPhoto ||
    OutboxType.stageCell ||
    OutboxType.stagePhoto ||
    OutboxType.materialChallan ||
    OutboxType.materialUse ||
    OutboxType.stockCount ||
    OutboxType.issuePhoto ||
    OutboxType.measurementPhoto ||
    OutboxType.progressCorrection ||
    OutboxType.contractorPayment => true,
    _ => false,
  };
}

/// One row per user action waiting to reach the server.
///
/// FIFO order is the autoincrement [id]. Rows sharing a [groupUuid] depend on
/// each other: only the oldest unfinished row in a group may be sent.
@DataClassName('OutboxRow')
@TableIndex(name: 'outbox_status_id', columns: {#status, #id})
@TableIndex(name: 'outbox_record', columns: {#recordUuid})
@TableIndex(name: 'outbox_group', columns: {#groupUuid, #id})
class Outbox extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get type => textEnum<OutboxType>()();

  /// The `client_uuid` sent on the wire. Stable across retries.
  TextColumn get wireUuid => text()();

  /// The local record this operation acts on.
  TextColumn get recordUuid => text()();

  /// Dependency group: the top-level record (DPR, entry, issue, mark).
  TextColumn get groupUuid => text()();
  IntColumn get projectId => integer()();

  /// JSON of the wire `data`, or upload fields for file rows.
  TextColumn get payload => text()();
  DateTimeColumn get clientUpdatedAt => dateTime().nullable()();
  TextColumn get status =>
      textEnum<OutboxStatus>().withDefault(const Constant('pending'))();

  /// File rows: path relative to the app documents directory.
  TextColumn get filePath => text().nullable()();

  /// File rows: the `client_uuid` of the parent record.
  TextColumn get parentUuid => text().nullable()();

  /// Display data captured when queued, so the sync log works without joins.
  TextColumn get title => text().withDefault(const Constant(''))();
  TextColumn get subtitle => text().nullable()();

  /// Last wire result: created, updated, duplicate, stale, conflict, ...
  TextColumn get resultStatus => text().nullable()();

  /// Stable code for a screen to translate: see [AttentionCode].
  TextColumn get attentionCode => text().nullable()();

  /// English description for logs and as a fallback.
  TextColumn get attentionReason => text().nullable()();

  /// JSON of the server's validation `errors` map.
  TextColumn get attentionErrors => text().nullable()();
  IntColumn get attempts => integer().withDefault(const Constant(0))();

  /// File rows: how many times the server said the parent was not found.
  /// Counted apart from [attempts] so timeouts and 429s cannot use up the
  /// allowance and push a healthy upload to attention.
  IntColumn get notFoundCount => integer().withDefault(const Constant(0))();
  DateTimeColumn get lastAttemptAt => dateTime().nullable()();
  DateTimeColumn get createdAt => dateTime()();
  DateTimeColumn get doneAt => dateTime().nullable()();
}

/// Reason codes stored in `attention_code` and translated by the sync screen.
abstract final class AttentionCode {
  static const approved = 'approved';
  static const inReview = 'in_review';
  static const rejected = 'rejected';
  static const validation = 'validation';
  static const forbidden = 'forbidden';
  static const planFeature = 'plan_feature';
  static const fileMissing = 'file_missing';
  static const parentMissing = 'parent_missing';
  static const projectGone = 'project_gone';
  static const unexpected = 'unexpected';
}
