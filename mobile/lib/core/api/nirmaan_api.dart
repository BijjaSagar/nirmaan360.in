import 'dart:typed_data';

import 'account_models.dart';
import 'reader_models.dart';

export 'account_models.dart';
export 'reader_models.dart';

/// One item in `POST /sync/push`.
class PushItem {
  const PushItem({
    required this.type,
    required this.clientUuid,
    required this.projectId,
    required this.data,
    this.serverId,
    this.clientUpdatedAt,
  });

  final String type;
  final String clientUuid;
  final int projectId;
  final int? serverId;
  final String? clientUpdatedAt;
  final Map<String, dynamic> data;

  Map<String, dynamic> toJson() => {
    'type': type,
    'client_uuid': clientUuid,
    'project_id': projectId,
    'server_id': serverId,
    'client_updated_at': clientUpdatedAt,
    'data': data,
  };
}

/// One entry of the `results` array from `POST /sync/push`.
class PushResult {
  const PushResult({
    required this.clientUuid,
    required this.status,
    this.id,
    this.serverStatus,
    this.reason,
    this.errors,
  });

  factory PushResult.fromJson(Map<String, dynamic> json) => PushResult(
    // Null when the server could not read the item; it then matches no row.
    clientUuid: json['client_uuid'] as String? ?? '',
    status: json['status'] as String,
    id: (json['id'] as num?)?.toInt(),
    serverStatus: json['server_status'] as String?,
    reason: json['reason'] as String?,
    errors: _errorMap(json['errors']),
  );

  final String clientUuid;
  final String status;
  final int? id;
  final String? serverStatus;
  final String? reason;
  final Map<String, List<String>>? errors;
}

Map<String, List<String>>? _errorMap(Object? raw) {
  if (raw is! Map) return null;
  return raw.map(
    (key, value) => MapEntry(
      key.toString(),
      value is List ? value.map((e) => e.toString()).toList() : [value.toString()],
    ),
  );
}

/// Parses a Laravel validation `errors` map.
Map<String, List<String>> parseValidationErrors(Object? raw) =>
    _errorMap(raw) ?? const {};

class LoginResult {
  const LoginResult({required this.token, required this.user});

  final String token;
  final Map<String, dynamic> user;
}

/// A file for `POST /sync/upload`.
class UploadRequest {
  const UploadRequest({
    required this.type,
    required this.clientUuid,
    required this.filePath,
    this.parentClientUuid,
    this.parentId,
    this.caption,
    this.takenAt,
  });

  /// `dpr_photo` or `petty_bill`.
  final String type;
  final String clientUuid;

  /// Absolute path, resolved at send time.
  final String filePath;
  final String? parentClientUuid;
  final int? parentId;
  final String? caption;
  final String? takenAt;
}

class UploadResult {
  const UploadResult({required this.status, required this.id});

  final String status;
  final int id;
}

/// The reply to `POST /devices`.
class DeviceRegistration {
  const DeviceRegistration({required this.pushConnected});

  /// Whether the server can send push at all yet. False until the office has
  /// connected Firebase on the server; the phone is registered either way.
  final bool pushConnected;
}

class ApprovalsResponse {
  const ApprovalsResponse({required this.items, required this.waitingCount});

  final List<Map<String, dynamic>> items;
  final int waitingCount;
}

/// The server, as the app sees it. Every method throws [ApiFailure] subtypes.
abstract interface class NirmaanApi {
  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
  });

  Future<void> logout();

  /// `POST /auth/register`: starts a new company with this person as its
  /// owner and signs them in. The reply is the same `{token, user}` as login.
  /// No plan is sent: the server picks it.
  Future<LoginResult> register({
    required String company,
    required String name,
    required String email,
    String? phone,
    required String password,
    required String passwordConfirmation,
    required String deviceName,
    required String locale,
  });

  /// `GET /auth/account/deletion`: what deleting this account would do.
  Future<AccountDeletionPreview> accountDeletionPreview();

  /// `POST /auth/account/deletion`. On success the token is already revoked.
  /// A 409 with code `deletion_mode_changed` means [mode] is no longer right.
  Future<AccountDeletionResult> deleteAccount({
    required AccountDeletionMode mode,
    required String password,
    String? confirmName,
  });

  /// `DELETE /auth/account/deletion`: calls off a pending company deletion.
  /// True when one was cancelled, false when nothing was pending.
  Future<bool> cancelCompanyDeletion();

  /// `POST /devices`: this phone's FCM token, for the signed-in person.
  /// Registering the same token again updates the row, even for a new owner.
  /// [platform] is `android` or `ios`; [locale] is the language push text is
  /// written in; [previousToken] is the token this one replaces, which the
  /// server retires. A contractor login ([portal]) calls the same route under
  /// `/contractor-portal`, the only part of the API its gate lets through.
  Future<DeviceRegistration> registerDevice({
    required String token,
    required String platform,
    String? previousToken,
    String? appVersion,
    String? locale,
    String? deviceName,
    bool portal = false,
  });

  /// `DELETE /devices` with `{token}`: forgets this phone for the signed-in person.
  Future<void> unregisterDevice(String token, {bool portal = false});

  /// `GET /notification-preferences`: the signed-in person's own switches,
  /// `{in_app?, email?, whatsapp?, push: {enabled, connected, categories, quiet_hours}}`.
  Future<Map<String, dynamic>> notificationPreferences({bool portal = false});

  /// `PUT /notification-preferences`: changes only what [changes] names, and
  /// answers with every switch as it now stands.
  Future<Map<String, dynamic>> updateNotificationPreferences(Map<String, Object?> changes, {bool portal = false});

  /// `GET /announcements` (under `/contractor-portal` for a contractor login):
  /// what was sent to this person, newest first, as Laravel's page body
  /// (`data`, `current_page`, `last_page`). [locale] asks for the text in the
  /// language the app shows; the server falls back to English.
  Future<Map<String, dynamic>> announcements({int page = 1, String? locale, bool portal = false});

  /// The `user` object of `GET /me`.
  Future<Map<String, dynamic>> me();

  Future<List<PushResult>> push(List<PushItem> items);

  /// The whole `GET /sync/pull` body. [since] is passed back byte for byte.
  Future<Map<String, dynamic>> pull({String? since});

  Future<UploadResult> upload(UploadRequest request);

  Future<ApprovalsResponse> approvals();

  /// `action` is approve, return or reject.
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
    String? overrideReason,
  });

  /// Returns the created worker row. Idempotent on `clientUuid`.
  Future<Map<String, dynamic>> addWorker({
    required String clientUuid,
    required String name,
    required String trade,
    String? phone,
    String type,
    String? dailyWage,
  });

  /// `POST /projects`. Returns the row in the `projects` pull shape. A blank
  /// [code] is generated by the server. Idempotent on `clientUuid`. With
  /// [phases] (`{name, amount}`), the contract value is their total.
  Future<Map<String, dynamic>> createProject({
    required String clientUuid,
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    String? contractValue,
    String? code,
    List<Map<String, String>>? phases,
  });

  /// `GET /projects/{project}`: the `projects` pull row plus the website
  /// form's fields. `contract_value` and `phases` are null without `money.view`.
  Future<Map<String, dynamic>> projectDetail(int projectId);

  /// `PATCH /projects/{project}`: changes only the keys in [fields]. Answers
  /// as [projectDetail]. Needs `project.manage`; money keys are ignored
  /// without `money.view`.
  Future<Map<String, dynamic>> updateProject(int projectId, Map<String, dynamic> fields);

  /// `POST /projects/{project}/boq-items`. Returns the row in the `boq_items`
  /// pull shape. A blank [code] becomes the next number in that project.
  /// Idempotent on `clientUuid`.
  Future<Map<String, dynamic>> addBoqItem({
    required int projectId,
    required String clientUuid,
    required String description,
    required String unit,
    required String qty,
    required String rate,
    String? code,
    String? section,
  });

  /// `PATCH /boq-items/{item}`. Returns the row in the `boq_items` pull shape.
  /// [rate] is left out for someone who may not see money: the server keeps
  /// the rate. [updatedAt] is the line's `updated_at` when the form opened; a
  /// line changed since is a 409 [ServerFailure] with `code: changed` and the
  /// line as it is now in `data.data`. Sending the same values again changes
  /// nothing on the server.
  Future<Map<String, dynamic>> updateBoqItem({
    required int boqItemId,
    required String code,
    required String description,
    required String unit,
    required String qty,
    String? rate,
    required String reason,
    String? updatedAt,
  });

  /// `DELETE /boq-items/{item}` with a [reason]. For the company owner only:
  /// a 403 for anyone else. A line something is recorded against is a 422
  /// with one message per record under `boq`. A line already removed answers
  /// as removed, so a retry is safe.
  Future<void> deleteBoqItem({required int boqItemId, required String reason});

  /// `POST /projects/{project}/wallets`. Returns the row in the `wallets` pull
  /// shape. Idempotent on `clientUuid`.
  Future<Map<String, dynamic>> createWallet({
    required int projectId,
    required String clientUuid,
    required String name,
    int? userId,
  });

  Future<Uint8List> dprPhotoBytes(int photoId);

  Future<Uint8List> pettyBillBytes(int entryId);

  /// A project's photo. [imageUrl] is the address the server gave for it.
  Future<Uint8List> projectImageBytes(String imageUrl);

  // Contractor control. Reads fill a cache on the phone. The presenter on the
  // server leaves every money figure out for someone without `money.view`.

  /// `GET /projects/{project}/contractor-control`: one row per contract.
  Future<List<Map<String, dynamic>>> contractorBoard(int projectId);

  /// `GET /work-orders/{workOrder}/contractor-control`: the row with `lines` and `milestones`.
  Future<Map<String, dynamic>> contractorContract(int workOrderId);

  /// The first page of `GET /projects/{project}/contractor-payments`, `{data, meta}`.
  Future<Map<String, dynamic>> contractorPayments(int projectId);

  /// `GET /contractor-payments/{payment}`, with `impact` for `money.view`.
  Future<Map<String, dynamic>> contractorPayment(int paymentId);

  /// `GET /contractor-payments/{payment}/bill`. Needs `money.view`.
  Future<Uint8List> contractorPaymentBill(int paymentId);

  /// `POST /work-orders/{workOrder}/contractor-payments`, with the bill as
  /// multipart `bill` when there is one. Replayed from the outbox with the same
  /// `client_uuid` in [body].
  Future<Map<String, dynamic>> postContractorPayment(int workOrderId, Map<String, dynamic> body, {String? filePath});

  // Tenders on a BOQ line. Online only: a quote or an award is decided against
  // what the office holds now. Without `money.view` the server sends who quoted
  // and when, and no rate, amount, terms or file.

  /// `GET /projects/{project}/boq`: the lines, each with `tenders`
  /// `{tenders, quotes, lowest_rate?}` or null when it is in no tender.
  Future<List<Map<String, dynamic>>> boqWithTenders(int projectId);

  /// `GET /boq-items/{item}/tenders`: the line, its tenders and their quotes, and `can`.
  Future<Map<String, dynamic>> boqLineTenders(int boqItemId);

  /// `POST /boq-items/{item}/tenders` with `title`, `scope`, `due_on` and `client_uuid`.
  Future<Map<String, dynamic>> createLineTender(int boqItemId, Map<String, dynamic> body);

  /// `GET /contractors?q=`: the company's subcontractors, for the quote form.
  /// The server sends 100 a page; every page is read, up to a limit.
  Future<List<Map<String, dynamic>>> subcontractors({String? query});

  /// `POST /contractors`: a new subcontractor, or the one already there by that
  /// name. Returns `{id, name, phone}`.
  Future<Map<String, dynamic>> createSubcontractor(Map<String, dynamic> body);

  /// `GET /tenders/{tender}`: every quote side by side.
  Future<Map<String, dynamic>> tenderComparison(int tenderId);

  /// `POST /tenders/{tender}/bids`, multipart with the quotation as
  /// `quotation` when there is one. A repeated `client_uuid` returns the first
  /// quote. Returns the whole reply: `data` (the tender) and `bid_id`.
  Future<Map<String, dynamic>> postTenderQuote(int tenderId, Map<String, dynamic> body, {String? filePath});

  /// `GET /bids/{bid}/quotation`. Needs `money.view`.
  Future<Uint8List> quotationBytes(int bidId);

  /// `POST /tenders/{tender}/award` with `bid_id`, the contract terms and a
  /// `client_uuid`. Returns the whole reply: `data` (the tender, now `AWARDED`)
  /// and `work_order` `{id, number}`.
  Future<Map<String, dynamic>> awardTender(int tenderId, Map<String, dynamic> body);

  // Inspections. Reads fill a cache on the phone. A finished inspection is sent
  // whole, from the outbox, because that is the one call the server repeats safely.

  /// `GET /checklist-templates`: the company's active checklists with their items.
  Future<List<Map<String, dynamic>>> checklistTemplates();

  /// One page of `GET /projects/{project}/inspections`, newest first, in Laravel's page shape.
  Future<Map<String, dynamic>> inspections(int projectId, {int page = 1});

  /// `GET /inspections/{inspection}`: the inspection with its items and their results.
  Future<Map<String, dynamic>> inspection(int inspectionId);

  /// `POST /projects/{project}/inspections` with the results by item position
  /// and `complete: true`, so one call starts it, marks it and locks it. A
  /// repeated `client_uuid` returns the first inspection and applies nothing.
  /// Returns the inspection with its items.
  Future<Map<String, dynamic>> postInspection(int projectId, Map<String, dynamic> body);

  // Machine logs. Reads fill a cache on the phone. The presenter has no rate or
  // cost in it, and this app shows none.

  /// One page of `GET /assets`, the working machines by code, 100 to a page, in Laravel's page shape.
  Future<Map<String, dynamic>> machineAssets({int page = 1});

  /// One page of `GET /machine-logs` for a project, newest first, 50 to a page.
  Future<Map<String, dynamic>> machineLogs(int projectId, {int page = 1});

  /// `POST /machine-logs`. A repeated `client_uuid` in [body] returns the first
  /// log and stores nothing, so the outbox may send the same row again.
  Future<Map<String, dynamic>> postMachineLog(Map<String, dynamic> body);

  // Site dashboard and alerts. Read only.

  /// `GET /projects/{project}/site-dashboard`: the cards the server worked out
  /// for this person, already limited to what they may see.
  Future<Map<String, dynamic>> siteDashboard(int projectId);

  /// The first page of `GET /projects/{project}/alerts?status=open`, worst
  /// first, in Laravel's page shape.
  Future<Map<String, dynamic>> siteAlerts(int projectId);

  /// `POST /projects/{project}/alerts/scan`: runs every alert source over the
  /// project now and answers `{new, open, resolved}`. Needs `alerts.manage`, and
  /// the server allows ten a minute: past that it answers 429 in its own words.
  Future<Map<String, dynamic>> scanSiteAlerts(int projectId);

  // Price watch. Read only. These are prices a company recorded, each with its
  // date: `meta.live_feed` is always false. The board and a material need
  // `money.view`, a project's budget effect needs `money.overview`.

  /// `GET /price-watch`: `{data: [board rows], meta}`.
  Future<Map<String, dynamic>> priceBoard();

  /// `GET /price-watch/materials/{id}`: `{data: row with prices, meta}`.
  Future<Map<String, dynamic>> priceMaterial(int materialId);

  /// `GET /projects/{project}/price-watch`: `{data: rows, summary, meta}`.
  Future<Map<String, dynamic>> priceProject(int projectId);

  // Contractor portal. Read only, and the only calls a contractor login may
  // make besides `me` and sign-out: the server refuses everything else.

  /// `GET /contractor-portal`: `{contractor, summary, contracts}`.
  Future<Map<String, dynamic>> portalOverview();

  /// `GET /contractor-portal/work-orders/{workOrder}`: one contract with its
  /// `lines`, `parts`, `payments` and `bills`.
  Future<Map<String, dynamic>> portalContract(int workOrderId);

  /// `GET /contractor-portal/payments`: every payment counted as paid.
  Future<List<Map<String, dynamic>>> portalPayments();

  /// `GET /contractor-portal/bills`: every bill the company has approved.
  Future<List<Map<String, dynamic>>> portalBills();

  /// `GET /contractor-portal/bills/{bill}`: the bill with its `items` and `deductions`.
  Future<Map<String, dynamic>> portalBill(int billId);

  // Site progress. Reads fill a cache on the phone. Writes are replayed from
  // the outbox with their `client_uuid`, so a retry cannot record work twice.

  Future<Map<String, dynamic>> progressSummary(int projectId);

  Future<List<Map<String, dynamic>>> progressFloors(int projectId);

  Future<List<Map<String, dynamic>>> progressItems(int projectId);

  Future<List<Map<String, dynamic>>> progressPending(int projectId);

  Future<Map<String, dynamic>> stageMatrix(int projectId);

  Future<Map<String, dynamic>> floorProgress(int floorId);

  /// Returns the stored entry.
  Future<Map<String, dynamic>> postProgressEntry(int projectId, Map<String, dynamic> body);

  Future<Map<String, dynamic>> resolveProgress(int entryId, Map<String, dynamic> body);

  /// `POST /progress/entries/{id}/corrections`: [body] holds `qty_done`, what
  /// the entry should have been, a `remark` and the `client_uuid`. Returns the
  /// correction entry, which carries the difference.
  Future<Map<String, dynamic>> correctProgress(int entryId, Map<String, dynamic> body);

  /// One page of `GET /projects/{project}/progress/entries`, in Laravel's page
  /// shape, oldest entry first. [floorId] limits it to one floor.
  Future<Map<String, dynamic>> progressEntries(int projectId, {int? floorId, int page = 1});

  /// Returns the stored cell.
  Future<Map<String, dynamic>> postStageCell(int projectId, Map<String, dynamic> body);

  // Material control. Reads fill a cache; writes come from the outbox.

  Future<Map<String, dynamic>> materialCheck(int projectId);

  Future<List<Map<String, dynamic>>> materialChallans(int projectId);

  Future<List<Map<String, dynamic>>> materialConsumption(int projectId);

  Future<List<Map<String, dynamic>>> materialStockChecks(int projectId);

  Future<List<Map<String, dynamic>>> materialSuppliers();

  /// Materials, units and stores, from purchasing.
  Future<Map<String, dynamic>> procurementSnapshot(int projectId);

  /// [filePath] is the challan photo, sent with the fields in one request.
  Future<Map<String, dynamic>> postMaterialChallan(int projectId, Map<String, dynamic> body, {String? filePath});

  Future<Map<String, dynamic>> postMaterialUse(int projectId, Map<String, dynamic> body);

  Future<Map<String, dynamic>> postStockCount(int projectId, Map<String, dynamic> body);

  /// `POST /projects/{project}/attendance/scan` with `{code, client_uuid}`. The reply
  /// is not wrapped in `data`: `{already_marked, worker, attendance}`. The server
  /// marks the worker present for the day it receives the scan, and a repeated
  /// `client_uuid` returns the first mark with `already_marked` true.
  Future<Map<String, dynamic>> postAttendanceScan(int projectId, Map<String, dynamic> body);

  /// `GET /attendance/selfie-consent`: `{version, text}`, the note in the person's
  /// language. The photo is posted with [uploadFieldPhoto] to
  /// `/projects/{project}/attendance/selfies`, file field `selfie`.
  Future<Map<String, dynamic>> attendanceSelfieConsent();

  // Goods receipts against a purchase order, and issues from a store. They are
  // posted one at a time to their own endpoints, not through
  // `/procurement/push`: see docs/CONTRACT.md for why.

  /// `GET /projects/{project}/grns`: the latest 50 receipts, newest first.
  Future<List<Map<String, dynamic>>> procurementReceipts(int projectId);

  /// `POST /projects/{project}/grns`. A repeated `client_uuid` in [body] returns
  /// the first receipt and adds no stock, whatever the purchase order has become.
  Future<Map<String, dynamic>> postGoodsReceipt(int projectId, Map<String, dynamic> body);

  /// `POST /projects/{project}/material-issues`. A repeated `client_uuid` in
  /// [body] returns the first issue and takes no stock.
  Future<Map<String, dynamic>> postMaterialIssue(int projectId, Map<String, dynamic> body);

  /// [path] is the photos endpoint of the entry, the stage cell or the issue.
  /// [fileField] is the multipart name the endpoint reads the file from, and
  /// [fields] any further text fields it needs, such as the `kind` of a photo.
  Future<Map<String, dynamic>> uploadFieldPhoto(
    String path, {
    required String filePath,
    required String clientUuid,
    String? caption,
    String? takenAt,
    String fileField = 'photo',
    Map<String, String> fields = const <String, String>{},
  });

  /// The `data` rows of a photo list, such as `/issues/{id}/photos`.
  Future<List<Map<String, dynamic>>> fieldPhotoList(String path);

  /// The file behind a photo, such as `/issue-photos/{id}`.
  Future<Uint8List> fieldPhotoBytes(String path);

  // Document readers: the AI that reads a photographed page. Online only, and
  // never through the outbox: each call is made while the person waits. A read
  // is created by its feature's own route; these are the steps every kind of
  // read shares. Refusals keep the server's `code`: a 409 or 402 is a
  // [ServerFailure] and a refused picture is a 422 [ValidationFailure]. See
  // docs/CONTRACT.md, "Document readers".

  /// `GET /document-reading/status`: may any AI button be shown, and which.
  Future<ReaderAvailability> readerAvailability();

  /// `GET /document-reads/{read}`: where a read stands. Asking also moves the
  /// read along on the server.
  Future<DocumentReadStatus> documentRead(int readId);

  /// `POST /document-reads/{read}/pages`, multipart `page_no`, `part`, `image`.
  /// Until the read starts, sending a page number again replaces that page.
  Future<StoredReadPage> uploadReadPage(
    int readId, {
    required int pageNo,
    required String filePath,
    ReadPagePart part = ReadPagePart.full,
  });

  /// `POST /document-reads/{read}/quote`: the price of the pages, or of the
  /// range [pageFrom] to [pageTo].
  Future<ReadQuote> quoteRead(int readId, {int? pageFrom, int? pageTo});

  /// `POST /document-reads/{read}/start`. [cap] is the most the read may use,
  /// in credits, as the quote wrote it. Answers 202 with the status.
  Future<DocumentReadStatus> startRead(
    int readId, {
    String? cap,
    int? pageFrom,
    int? pageTo,
    List<int> skipPages = const <int>[],
  });

  /// `POST /document-reads/{read}/resume`, for a paused read.
  Future<DocumentReadStatus> resumeRead(int readId, {String? cap});

  /// `POST /document-reads/{read}/pages/{page}/retry`. [stronger] asks for the
  /// stronger model, when the failed page offers it.
  Future<DocumentReadStatus> retryReadPage(int readId, int pageId, {bool stronger = false, String? cap});

  /// `POST /document-reads/{read}/pages/{page}/split`: reads a cut-off page
  /// again as two halves.
  Future<DocumentReadStatus> splitReadPage(int readId, int pageId, {String? cap});

  /// `POST /document-reads/{read}/cancel`. Nothing more is sent or charged.
  Future<DocumentReadStatus> cancelRead(int readId);

  /// `GET /document-reads/{read}/pages/{page}/image`: the stored picture,
  /// exactly as it went to the reader. A 404 once the read's files are deleted.
  Future<Uint8List> readPageImage(int readId, int pageId);

  // Closing the material loop from site, online only: a reason on a flag, a
  // challan in full, its bill, a short found later, the bills list, and
  // wastage against the BOQ norms.

  /// `POST /projects/{project}/material-control/reasons` with `material_id`,
  /// `flag_type`, `reason` and `client_uuid`. Returns the reason; a repeated
  /// `client_uuid` returns the first one.
  Future<Map<String, dynamic>> postMaterialReason(int projectId, Map<String, dynamic> body);

  /// `GET /delivery-challans/{id}`: the challan with names, remarks and shorts.
  Future<Map<String, dynamic>> deliveryChallan(int challanId);

  /// `GET /delivery-challans/{id}/photo`.
  Future<Uint8List> deliveryChallanPhoto(int challanId);

  /// `POST /delivery-challans/{id}/bill`, multipart `bill` when [filePath] is
  /// given. Returns the challan in full.
  Future<Map<String, dynamic>> attachChallanBill(int challanId, Map<String, dynamic> body, {String? filePath});

  /// `GET /delivery-challans/{id}/bill`: the bill file. Needs `money.view`.
  Future<Uint8List> challanBillBytes(int challanId);

  /// `POST /delivery-challans/{id}/short` with `short_qty`, `found_on`, `note`
  /// and `client_uuid`. The whole reply: `{data: short, challan: challan}`.
  Future<Map<String, dynamic>> postChallanShort(int challanId, Map<String, dynamic> body);

  /// `GET /projects/{project}/material-control/bills`, the whole reply:
  /// `{data: rows, meta: {show, pending_count, alert_after_days, current_page, last_page, total}}`.
  Future<Map<String, dynamic>> challanBills(int projectId, {bool attached = false, int page = 1});

  /// `GET /projects/{project}/material-reconciliation?basis=`.
  Future<Map<String, dynamic>> materialReconciliation(int projectId, {String? basis});

  // Setting up floors and segments (online only). Each answer is what the
  // server now holds; nothing waits in the outbox. See docs/CONTRACT.md.

  /// `POST /projects/{project}/floors`: a floor, or a segment under the floor
  /// [parentId]. Returns `{id, client_uuid, project_id, name, parent_id, sort}`.
  /// A repeated [clientUuid] returns the floor it made. `progress.manage`.
  Future<Map<String, dynamic>> addFloor(int projectId, {required String name, int? parentId, required String clientUuid});

  /// `PATCH /floors/{floor}`: renames it. Returns the same shape as [addFloor].
  Future<Map<String, dynamic>> renameFloor(int floorId, {required String name});

  /// `PUT /floors/{floor}/target`: what is planned for one BOQ line on the
  /// floor; zero removes it. Returns the floor as `GET /floors/{floor}/progress`.
  Future<Map<String, dynamic>> setFloorTarget(int floorId, {required int boqItemId, required String plannedQty});

  /// `POST /projects/{project}/floors/split`: shares whole BOQ lines equally
  /// across [floorIds] ([allItems], or the lines in [boqItemIds]). Returns
  /// `{count, floors}`. Sending it again gives the same shares.
  Future<Map<String, dynamic>> splitFloors(int projectId, {required List<int> floorIds, List<int> boqItemIds, bool allItems});

  /// `DELETE /floors/{floor}`: the company owner only (403 for anyone else);
  /// a floor with work recorded or segments under it is a 422 under `floor`.
  Future<void> deleteFloor(int floorId);

  /// `PATCH /projects/{project}/progress/time-frame`: all three are sent, null
  /// clears one. Returns the progress summary.
  Future<Map<String, dynamic>> setProgressTimeFrame(int projectId, {String? startDate, int? plannedDays, String? deadlineDate});

  /// `GET /projects/{project}/progress/week`: the week (Monday to Sunday) that
  /// holds [date], today when null.
  Future<Map<String, dynamic>> progressWeek(int projectId, {String? date});

  // Report files (routes/api_modules/reports.php): the website's DPR PDF, its
  // WhatsApp link and the measurement sheet. Online only, on a plan with
  // `reports`. The app writes the files to its temporary folder, never to the
  // database.

  /// `GET /dprs/{dpr}/pdf` (`dpr.view`): the same PDF the website gives.
  Future<Uint8List> dprPdfBytes(int dprId);

  /// `POST /dprs/{dpr}/share-link` (`dpr.view`): `{url, text, whatsapp_url,
  /// expires_at, link_days, filename}`. `url` is the website's signed
  /// `/shared/dprs/{id}` link, `text` the website's WhatsApp message ending in
  /// it, written in [locale] (`en`, `mr` or `hi`) when given.
  Future<Map<String, dynamic>> dprShareLink(int dprId, {String? locale});

  /// `GET /projects/{project}/measurement-sheet.pdf?from=&to=` (`measurement.view`),
  /// dates as `YYYY-MM-DD`. Rates and amounts only for `money.view`, decided by
  /// the server.
  Future<Uint8List> measurementSheetBytes(int projectId, {required String from, required String to});
}
