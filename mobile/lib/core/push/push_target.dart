import 'package:flutter/foundation.dart';

import '../../app/routes.dart';
import 'push_message.dart';

/// What a notification can point at. The server's `type` names it; see
/// `app/Services/Notify/PushTarget.php` for what each one carries.
enum PushKind {
  approval,
  report,
  measurement,
  issue,
  alert,
  contractorPayment,
  materialFlag,
  deliveryChallan,
  portalPayment,
  portalBill,
  announcement,
}

/// What a push message points at: a kind of thing, which one, and the few
/// facts needed to open it.
///
/// The server's payload carries `type` and `id` (both as text), and may carry
/// `project_id` and more ids by type. A message with only `event`
/// (`approval.waiting`, `issue.assigned`, `alert.<type>`) still points at the
/// right kind, with no id. `test` and `link` point at nothing in the app, and
/// neither does anything else: [parse] answers null.
@immutable
class PushTarget {
  const PushTarget(
    this.kind, {
    this.id,
    this.projectId,
    this.status,
    this.approvalId,
    this.docType,
    this.docId,
    this.workOrderId,
  });

  final PushKind kind;

  /// The server's id of the record [kind] names.
  final int? id;

  /// The project it belongs to, when the message says.
  final int? projectId;

  /// An approval's or a payment's state: `waiting` means it needs this person.
  final String? status;

  /// The approval to act on, for a contractor payment.
  final int? approvalId;

  /// The document a decided approval was about: `dpr`, `measurement`, `ra_bill`...
  final String? docType;
  final int? docId;

  /// The contract a contractor payment belongs to.
  final int? workOrderId;

  /// It needs this person to act. An approval that says nothing is taken to.
  bool get waiting => status == null || status == 'waiting';

  /// Reads a message's data. Never throws.
  static PushTarget? parse(Map<String, String> data) {
    final String? type = data['type']?.trim().toLowerCase();
    if (type == 'test' || type == 'link') return null;
    final PushKind? kind = _kindOf(type) ?? _kindOf(_before(data['event'], '.'));
    if (kind == null) return null;
    final String? status = data['status']?.trim().toLowerCase();
    final String? docType = data['doc_type']?.trim().toLowerCase();
    return PushTarget(
      kind,
      id: _positiveId(data['id']),
      projectId: _positiveId(data['project_id']),
      status: status == null || status.isEmpty ? null : status,
      approvalId: _positiveId(data['approval_id']),
      docType: docType == null || docType.isEmpty ? null : docType,
      docId: _positiveId(data['doc_id']),
      workOrderId: _positiveId(data['work_order_id']),
    );
  }

  static PushTarget? fromMessage(PushMessage message) => parse(message.data);

  static String? _before(String? text, String separator) {
    if (text == null) return null;
    final int at = text.indexOf(separator);
    return at < 0 ? text : text.substring(0, at);
  }

  static PushKind? _kindOf(String? raw) {
    switch (raw?.trim().toLowerCase()) {
      case 'approval':
        return PushKind.approval;
      case 'dpr' || 'report' || 'daily_report':
        return PushKind.report;
      case 'measurement':
        return PushKind.measurement;
      case 'issue':
        return PushKind.issue;
      case 'alert' || 'site_alert':
        return PushKind.alert;
      case 'contractor_payment':
        return PushKind.contractorPayment;
      case 'material_flag':
        return PushKind.materialFlag;
      case 'delivery_challan':
        return PushKind.deliveryChallan;
      case 'portal_payment':
        return PushKind.portalPayment;
      case 'portal_bill':
        return PushKind.portalBill;
      case 'announcement':
        return PushKind.announcement;
      default:
        return null;
    }
  }

  /// A server id: whole, above zero, and small enough to be one.
  static int? _positiveId(String? raw) {
    if (raw == null) return null;
    final int? value = int.tryParse(raw.trim());
    return value != null && value > 0 && value < 0x7fffffff ? value : null;
  }

  @override
  bool operator ==(Object other) =>
      other is PushTarget &&
      other.kind == kind &&
      other.id == id &&
      other.projectId == projectId &&
      other.status == status &&
      other.approvalId == approvalId &&
      other.docType == docType &&
      other.docId == docId &&
      other.workOrderId == workOrderId;

  @override
  int get hashCode => Object.hash(kind, id, projectId, status, approvalId, docType, docId, workOrderId);

  @override
  String toString() => 'PushTarget($kind, id: $id, project: $projectId, status: $status)';
}

/// Finds the local record the server's id stands for, as its `client_uuid`.
/// Null when this phone has not pulled it yet.
typedef LocalUuidLookup = Future<String?> Function(PushKind kind, int serverId);

/// Turns a [PushTarget] into the path to open.
///
/// [allows] is the gate: it answers whether this person may open a location right
/// now. When they may not, they land on [home], as they would if they had typed
/// the address. A missing or unknown target, or one this phone cannot find yet,
/// opens the list it belongs to, or [home] when there is none.
class PushRouter {
  const PushRouter({required this.allows, required this.lookup, this.home = Routes.home});

  final bool Function(Uri location) allows;
  final LocalUuidLookup lookup;

  /// Where someone lands when there is nothing better: Home, or a contractor's portal.
  final String home;

  /// The path to open for [target]. Never throws.
  Future<String> pathFor(PushTarget? target) async {
    if (target == null) return home;
    String? path;
    try {
      path = await _path(target);
    } on Object {
      // A broken local read must never stop the person getting somewhere.
      path = null;
    }
    if (path == null) return home;
    return allows(Uri.parse(path)) ? path : home;
  }

  Future<String?> _path(PushTarget target) async {
    final int? id = target.id;
    switch (target.kind) {
      case PushKind.approval:
        if (target.waiting) return id == null ? Routes.approvals : Routes.approvalDetail(id);
        // Decided: the document it was about, when this phone shows that kind.
        return switch (target.docType) {
          'dpr' => _local(PushKind.report, target.docId, Routes.dprDetail, Routes.dprList),
          'measurement' => _local(PushKind.measurement, target.docId, Routes.measurementDetail, Routes.measurements),
          _ => null,
        };
      case PushKind.report:
        return _local(PushKind.report, id, Routes.dprDetail, Routes.dprList);
      case PushKind.measurement:
        return _local(PushKind.measurement, id, Routes.measurementDetail, Routes.measurements);
      case PushKind.issue:
        return _local(PushKind.issue, id, Routes.issueDetail, Routes.issues);
      case PushKind.alert:
        return '${Routes.siteDashboard}?tab=alerts';
      case PushKind.contractorPayment:
        final int? approvalId = target.approvalId;
        if (target.waiting && approvalId != null) return Routes.approvalDetail(approvalId);
        final int? workOrderId = target.workOrderId;
        return workOrderId == null ? Routes.contractors : Routes.contractorDetail(workOrderId);
      case PushKind.materialFlag || PushKind.deliveryChallan:
        return Routes.material;
      case PushKind.portalPayment:
        return Routes.portalPayments;
      case PushKind.portalBill:
        return id == null ? Routes.portalBills : Routes.portalBill(id);
      case PushKind.announcement:
        // Staff and contractor logins alike: the list is a Settings page, which the gate always opens.
        return id == null ? Routes.announcements : Routes.announcementsAt(id);
    }
  }

  Future<String> _local(PushKind kind, int? id, String Function(String uuid) detail, String list) async {
    final String? uuid = id == null ? null : await lookup(kind, id);
    return uuid == null ? list : detail(uuid);
  }
}
