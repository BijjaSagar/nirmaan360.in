import 'package:flutter/foundation.dart';

import '../../app/routes.dart';
import 'push_message.dart';

/// What a notification can point at.
enum PushKind { approval, report, measurement, issue, alert }

/// What a push message points at: a kind of thing, and which one when it says.
///
/// The server's payload carries `type` and `id` (both as text), and may carry
/// `project_id`. A message with only `event` (`approval.waiting`, `issue.assigned`,
/// `alert.<type>`) still points at the right kind, with no id. Anything else is
/// not a target at all, and [parse] answers null.
@immutable
class PushTarget {
  const PushTarget(this.kind, {this.id, this.projectId});

  final PushKind kind;

  /// The server's id of the approval, report, measurement or issue.
  final int? id;

  /// The project it belongs to, when the message says.
  final int? projectId;

  /// Reads a message's data. Never throws.
  static PushTarget? parse(Map<String, String> data) {
    final PushKind? kind = _kindOf(data['type']) ?? _kindOf(_before(data['event'], '.'));
    if (kind == null) return null;
    return PushTarget(kind, id: _positiveId(data['id']), projectId: _positiveId(data['project_id']));
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
      other is PushTarget && other.kind == kind && other.id == id && other.projectId == projectId;

  @override
  int get hashCode => Object.hash(kind, id, projectId);

  @override
  String toString() => 'PushTarget($kind, id: $id, project: $projectId)';
}

/// Finds the local record the server's id stands for, as its `client_uuid`.
/// Null when this phone has not pulled it yet.
typedef LocalUuidLookup = Future<String?> Function(PushKind kind, int serverId);

/// Turns a [PushTarget] into the path to open.
///
/// [allows] is the gate: it answers whether this person may open a location right
/// now. When they may not, they land on Home, as they would if they had typed
/// the address. A missing or unknown target, or one this phone cannot find yet,
/// opens the list it belongs to, or Home when there is none.
class PushRouter {
  const PushRouter({required this.allows, required this.lookup});

  final bool Function(Uri location) allows;
  final LocalUuidLookup lookup;

  /// The path to open for [target]. Never throws.
  Future<String> pathFor(PushTarget? target) async {
    if (target == null) return Routes.home;
    String? path;
    try {
      path = await _path(target);
    } on Object {
      // A broken local read must never stop the person getting somewhere.
      path = null;
    }
    if (path == null) return Routes.home;
    return allows(Uri.parse(path)) ? path : Routes.home;
  }

  Future<String?> _path(PushTarget target) async {
    final int? id = target.id;
    switch (target.kind) {
      case PushKind.approval:
        return id == null ? Routes.approvals : Routes.approvalDetail(id);
      case PushKind.report:
        final String? uuid = id == null ? null : await lookup(PushKind.report, id);
        return uuid == null ? Routes.dprList : Routes.dprDetail(uuid);
      case PushKind.measurement:
        final String? uuid = id == null ? null : await lookup(PushKind.measurement, id);
        return uuid == null ? Routes.measurements : Routes.measurementDetail(uuid);
      case PushKind.issue:
        final String? uuid = id == null ? null : await lookup(PushKind.issue, id);
        return uuid == null ? Routes.issues : Routes.issueDetail(uuid);
      case PushKind.alert:
        return '${Routes.siteDashboard}?tab=alerts';
    }
  }
}
