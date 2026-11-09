import 'dart:async';
import 'dart:math' show max;
import 'dart:typed_data';

import 'package:decimal/decimal.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';

typedef Row = Map<String, dynamic>;

/// An in-memory server implementing the sync semantics of docs/CONTRACT.md,
/// mirroring `app/Services/SyncService.php`.
///
/// Idempotent on `client_uuid`, follows the `client_updated_at` rules, and
/// lets a test script failures: offline, lost responses, hung requests, 401s,
/// 429s and per-item results.
class FakeApi implements NirmaanApi {
  FakeApi({DateTime? serverTime})
    : _now = serverTime ?? DateTime.utc(2026, 11, 9, 4, 30);

  // Scripted behaviour --------------------------------------------------------

  /// Every call fails with [OfflineFailure].
  bool offline = false;

  /// Every call fails with [AuthExpired], as a 401 would.
  bool unauthorized = false;

  final List<ApiFailure> _pushFailures = [];
  final List<ApiFailure> _pullFailures = [];
  final List<ApiFailure> _uploadFailures = [];
  final List<String> _scriptedStatuses = [];
  int _lostPushResponses = 0;
  int _lostUploadResponses = 0;
  int _lostSetupResponses = 0;
  Completer<void>? _pushGate;

  /// The next `push` throws [failure] without touching server state.
  void failNextPush([ApiFailure failure = const OfflineFailure()]) =>
      _pushFailures.add(failure);

  /// The next `push` is applied on the server, then the response is lost.
  void loseNextPushResponse() => _lostPushResponses++;

  /// The next `push` is applied, then never answers, like a killed process.
  /// Complete the returned completer to let it answer after all.
  Completer<void> hangNextPush() => _pushGate = Completer<void>();

  /// The next items pushed get these statuses instead of being applied.
  /// One of `conflict`, `error`, `forbidden` or `forbidden_feature` each.
  void scriptPushStatuses(List<String> statuses) =>
      _scriptedStatuses.addAll(statuses);

  void failNextPull([ApiFailure failure = const OfflineFailure()]) =>
      _pullFailures.add(failure);

  void failNextUpload([ApiFailure failure = const OfflineFailure()]) =>
      _uploadFailures.add(failure);

  /// The next upload is stored on the server, then the response is lost.
  void loseNextUploadResponse() => _lostUploadResponses++;

  /// The next setup create is stored on the server, then the reply is lost.
  void loseNextSetupResponse() => _lostSetupResponses++;

  /// Projects beyond this count are refused as a plan limit: a 422 with
  /// `errors.plan`. Null means no limit.
  int? planProjectLimit;

  /// Gives the signed-in user a permission, e.g. `project.manage`.
  void grant(String permission) => (user['can'] as Map)[permission] = true;

  /// When set, returned instead of the real `pull` body.
  Map<String, dynamic>? pullBodyOverride;

  /// Runs once at the start of the next `pull`, to simulate something
  /// happening on the phone while the request is in flight.
  Future<void> Function()? beforeNextPull;

  // Server state --------------------------------------------------------------

  DateTime _now;
  int _nextId = 100;

  Row user = {
    'id': 4,
    'name': 'Sachin More',
    'email': 'engineer@demo.test',
    'role': 'engineer',
    'role_label': 'Site Engineer',
    'locale': 'en',
    'organization': {
      'id': 1,
      'name': 'Sahyadri Infra Pvt Ltd',
      'features': ['site', 'measurement', 'labour', 'petty_cash'],
    },
    'can': {
      'project.view': true,
      'boq.view': true,
      'dpr.view': true,
      'dpr.create': true,
      'dpr.approve': false,
      'issue.view': true,
      'issue.manage': true,
      'attendance.view': true,
      'attendance.manage': true,
      'worker.view': true,
      'worker.manage': true,
      'petty_cash.view': true,
      'petty_cash.manage': true,
      'measurement.view': true,
      'measurement.create': true,
      'measurement.approve': false,
      'ra_bill.view': false,
      'ra_bill.approve': false,
      'project.manage': false,
      'boq.manage': false,
      'money.view': true,
    },
  };

  final List<Row> projects = [];
  final List<Row> sites = [];
  final List<Row> boqItems = [];
  final List<Row> workers = [];
  final List<Row> wallets = [];
  final List<Row> users = [];
  final Map<String, dynamic> meta = {
    'trades': ['Mason', 'Helper', 'Carpenter'],
    'units': ['cum', 'sqm', 'rmt', 'nos'],
    'expense_categories': ['Fuel', 'Food', 'Travel'],
    'issue_priorities': ['low', 'medium', 'high', 'critical'],
    'issue_statuses': ['open', 'in_progress', 'resolved', 'closed'],
    'attendance_statuses': ['present', 'half', 'absent', 'overtime'],
    'weather': ['Clear', 'Cloudy', 'Rain', 'Windy', 'Hot'],
    'measurement_tolerance_pct': '5',
  };

  final List<Row> measurements = [];
  final List<Row> dprs = [];
  final List<Row> issues = [];
  final List<Row> attendance = [];
  final List<Row> pettyEntries = [];
  final List<Row> uploads = [];
  final List<Row> approvalInbox = [];
  final Map<String, Row> _approvalActs = {};
  final Map<String, Row> _workersByUuid = {};

  /// Every `push` body received, in order, for assertions.
  final List<List<PushItem>> pushCalls = [];

  /// Every `pull` `since` value received, in order.
  final List<String?> pullCalls = [];

  /// Every upload received, in order, including retries.
  final List<UploadRequest> uploadCalls = [];

  /// Every `client_updated_at` that reached a successful write, per client_uuid.
  final Map<String, List<String>> appliedStamps = {};

  /// Kind => [id, deleted_at, and for measurements the BOQ line].
  final Map<String, List<Row>> _deletedLog = {
    'projects': [],
    'boq_items': [],
    'workers': [],
    'issues': [],
    'dprs': [],
    'measurements': [],
  };

  int get serverRowCount =>
      measurements.length + dprs.length + issues.length + attendance.length;

  // Seed helpers --------------------------------------------------------------

  /// One project, site, BOQ line, wallet and two workers.
  void seedDemo() {
    addProject(id: 1, code: 'KT-B', name: 'Kalyani Tower B');
    addSite(id: 1, projectId: 1, name: 'Tower B');
    addBoqItem(id: 1, projectId: 1, code: '2.1', qty: '620.000');
    addWorker2(id: 1, name: 'Ramesh Pawar', trade: 'Mason');
    addWorker2(id: 2, name: 'Suresh Jadhav', trade: 'Helper');
    addWallet(id: 1, projectId: 1, balance: '12500.00', userId: 4);
    users.add({'id': 4, 'name': 'Sachin More', 'role': 'engineer'});
  }

  void addProject({required int id, required String code, required String name}) {
    projects.add({
      'id': id,
      'code': code,
      'name': name,
      'location': 'Pune',
      'status': 'active',
      'start_date': '2026-01-01',
      'end_date': '2027-12-31',
      'measurement_tolerance_pct': '5.00',
      'updated_at': _stampNow(),
    });
  }

  void addSite({required int id, required int projectId, required String name}) {
    sites.add({
      'id': id,
      'project_id': projectId,
      'name': name,
      'address': '',
      'updated_at': _stampNow(),
    });
  }

  void _seedBoqItem({
    required int id,
    required int projectId,
    required String code,
    required String qty,
    required String rate,
    required String unit,
  }) {
    boqItems.add({
      'id': id,
      'project_id': projectId,
      'section': 'Superstructure',
      'code': code,
      'description': 'RCC work $code',
      'unit': unit,
      'qty': qty,
      'rate': rate,
      'sort': id,
      'updated_at': _stampNow(),
    });
  }

  void addWorker2({required int id, required String name, String trade = 'Mason'}) {
    workers.add({
      'id': id,
      'name': name,
      'phone': null,
      'trade': trade,
      'type': 'own',
      'is_active': true,
      'updated_at': _stampNow(),
    });
  }

  void addWallet({
    required int id,
    required int projectId,
    required String balance,
    int? userId,
  }) {
    wallets.add({
      'id': id,
      'project_id': projectId,
      'name': 'Site cash',
      'user_id': userId,
      'balance': balance,
      'updated_at': _stampNow(),
    });
  }

  /// Sets a row's status on the server, as an approver would. An approval
  /// keeps the stored `client_updated_at`.
  void setServerStatus(Row row, String status, {String? returnRemark}) {
    row['status'] = status;
    row['return_remark'] = returnRemark;
    row['updated_at'] = _stampNow();
  }

  /// A web or REST edit: applies [changes] and clears `client_updated_at`, so
  /// `updated_at` decides against later device writes.
  void editOnWeb(Row row, Row changes) {
    row.addAll(changes);
    row['client_updated_at'] = null;
    row['updated_at'] = _stampNow();
  }

  /// Soft-deletes an issue or DPR. Later pushes get `duplicate`.
  void softDeleteOnWeb(String kind, Row row) {
    final at = _stampNow();
    row['deleted_at'] = at;
    _deletedLog[kind]!.add({'id': row['id'], 'deleted_at': at});
  }

  /// Hard-deletes a measurement draft, as the web does.
  void deleteDraftOnWeb(Row row) {
    measurements.remove(row);
    _deletedLog['measurements']!.add({
      'id': row['id'],
      'deleted_at': _stampNow(),
      'boq_item_id': row['boq_item_id'],
    });
  }

  Row measurementByUuid(String uuid) =>
      measurements.firstWhere((m) => m['client_uuid'] == uuid);

  Row dprByUuid(String uuid) => dprs.firstWhere((d) => d['client_uuid'] == uuid);

  Row issueByUuid(String uuid) =>
      issues.firstWhere((i) => i['client_uuid'] == uuid);

  // NirmaanApi ----------------------------------------------------------------

  void _guard() {
    if (offline) throw const OfflineFailure();
    if (unauthorized) throw const AuthExpired();
  }

  @override
  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    if (offline) throw const OfflineFailure();
    if (password == 'wrong') {
      throw const ValidationFailure(
        'These details do not match our records.',
        errors: {
          'email': ['These details do not match our records.'],
        },
      );
    }
    return LoginResult(token: 'fake-token', user: user);
  }

  @override
  Future<void> logout() async {
    _guard();
  }

  @override
  Future<Map<String, dynamic>> me() async {
    _guard();
    return user;
  }

  @override
  Future<List<PushResult>> push(List<PushItem> items) async {
    _guard();
    if (_pushFailures.isNotEmpty) throw _pushFailures.removeAt(0);
    pushCalls.add(items);
    final results = items.map(_pushOne).toList();

    final gate = _pushGate;
    if (gate != null) {
      _pushGate = null;
      await gate.future;
    }
    if (_lostPushResponses > 0) {
      _lostPushResponses--;
      throw const OfflineFailure('Response lost');
    }
    return results;
  }

  static const _permissions = {
    'measurement': ('measurement.create', 'measurement'),
    'measurement_submit': ('measurement.create', 'measurement'),
    'dpr': ('dpr.create', 'site'),
    'dpr_submit': ('dpr.create', 'site'),
    'issue': ('issue.manage', 'site'),
    'issue_status': ('issue.manage', 'site'),
    'attendance': ('attendance.manage', 'labour'),
    'petty_cash': ('petty_cash.manage', 'petty_cash'),
  };

  PushResult _pushOne(PushItem item) {
    if (_scriptedStatuses.isNotEmpty) {
      return _scripted(item, _scriptedStatuses.removeAt(0));
    }

    final rule = _permissions[item.type];
    if (rule == null) return _error(item, {'type': ['Unknown type']});

    final can = (user['can'] as Map)[rule.$1] == true;
    if (!can) return _forbidden(item, 'permission');
    final features = (user['organization'] as Map)['features'] as List;
    if (!features.contains(rule.$2)) return _forbidden(item, 'feature');

    switch (item.type) {
      case 'measurement':
        return _measurementWrite(item);
      case 'dpr':
        return _dprWrite(item);
      case 'measurement_submit':
        return _submit(
          item,
          measurements,
          item.data['entry_client_uuid'] as String?,
          item.data['entry_id'] as int?,
        );
      case 'dpr_submit':
        return _submit(
          item,
          dprs,
          item.data['dpr_client_uuid'] as String?,
          item.data['dpr_id'] as int?,
        );
      case 'issue':
        return _issueWrite(item);
      case 'issue_status':
        return _issueStatus(item);
      case 'attendance':
        return _attendanceWrite(item);
      default:
        return _pettyWrite(item);
    }
  }

  PushResult _scripted(PushItem item, String status) {
    switch (status) {
      case 'conflict':
        return PushResult(
          clientUuid: item.clientUuid,
          status: 'conflict',
          id: 1,
          serverStatus: 'APPROVED',
          reason: 'approved',
        );
      case 'error':
        return _error(item, {
          'nos': ['Nos must be more than zero.'],
        });
      case 'error_project':
        return _error(item, {
          'project_id': ['Project not found.'],
        });
      case 'forbidden_feature':
        return _forbidden(item, 'feature');
      default:
        return _forbidden(item, 'permission');
    }
  }

  PushResult _error(PushItem item, Map<String, List<String>> errors) =>
      PushResult(clientUuid: item.clientUuid, status: 'error', errors: errors);

  PushResult _forbidden(PushItem item, String reason) =>
      PushResult(clientUuid: item.clientUuid, status: 'forbidden', reason: reason);

  PushResult _result(PushItem item, String status, Row row, {String? reason}) =>
      PushResult(
        clientUuid: item.clientUuid,
        status: status,
        id: row['id'] as int,
        serverStatus: row['status'] as String?,
        reason: reason,
      );

  /// Find by `server_id`, else by `client_uuid`. Includes soft-deleted rows.
  Row? _findRow(List<Row> table, PushItem item) {
    if (item.serverId != null) {
      for (final row in table) {
        if (row['id'] == item.serverId) return row;
      }
    }
    for (final row in table) {
      if (row['client_uuid'] == item.clientUuid) return row;
    }
    return null;
  }

  bool _sameStamp(Object? a, Object? b) {
    if (a == null || b == null) return false;
    return DateTime.parse(a as String).isAtSameMomentAs(DateTime.parse(b as String));
  }

  String _serverToday() {
    final local = _now.add(const Duration(hours: 5, minutes: 30));
    String two(int v) => v.toString().padLeft(2, '0');
    return '${local.year}-${two(local.month)}-${two(local.day)}';
  }

  bool _isFuture(Object? date) =>
      (date as String).compareTo(_serverToday()) > 0;

  PushResult _measurementWrite(PushItem item) {
    Map<String, List<String>>? validate() {
      if (_isFuture(item.data['measured_on'])) {
        return {
          'measured_on': ['The measured on must be today or earlier.'],
        };
      }
      final nos = item.data['nos'];
      if (nos == null || Decimal.parse(nos.toString()) <= Decimal.zero) {
        return {
          'nos': ['The nos must be greater than 0.'],
        };
      }
      return null;
    }

    return _documentWrite(item, measurements, validate, (row, data) {
      row.addAll({
        'project_id': item.projectId,
        'boq_item_id': data['boq_item_id'],
        'work_order_id': null,
        'measured_on': data['measured_on'],
        'description': data['description'],
        'location': data['location'],
        'nos': data['nos'],
        'length': data['length'],
        'breadth': data['breadth'],
        'depth': data['depth'],
        'is_deduction': data['is_deduction'] ?? false,
        'qty': _measurementQty(data),
        'remarks': data['remarks'],
        'deviation': false,
        'return_remark': null,
        'created_by': user['id'],
      });
    });
  }

  PushResult _dprWrite(PushItem item) {
    Map<String, List<String>>? validate() {
      if (_isFuture(item.data['report_date'])) {
        return {
          'report_date': ['The report date must be today or earlier.'],
        };
      }
      return null;
    }

    return _documentWrite(item, dprs, validate, (row, data) {
      row.addAll({
        'project_id': item.projectId,
        'site_id': data['site_id'],
        'report_date': data['report_date'],
        'weather': data['weather'],
        'summary': data['summary'],
        'hindrances': data['hindrances'],
        'manpower': data['manpower'] ?? const [],
        'machinery': data['machinery'] ?? const [],
        'work_items': data['work'] ?? const [],
        'photos': row['photos'] ?? <Row>[],
        'return_remark': null,
        'created_by': user['id'],
        'created_by_name': user['name'],
      });
    });
  }

  /// Rules 1 to 5 of the contract for measurements and DPRs.
  PushResult _documentWrite(
    PushItem item,
    List<Row> table,
    Map<String, List<String>>? Function() validate,
    void Function(Row row, Map<String, dynamic> data) fill,
  ) {
    final row = _findRow(table, item);

    if (row == null) {
      final errors = validate();
      if (errors != null) return _error(item, errors);
      final created = <String, dynamic>{
        'id': _nextId++,
        'client_uuid': item.clientUuid,
        'status': 'DRAFT',
        'client_updated_at': item.clientUpdatedAt,
        'updated_at': _stampNow(),
      };
      fill(created, item.data);
      table.add(created);
      _recordStamp(item);
      return _result(item, 'created', created);
    }

    if (row['project_id'] != item.projectId) {
      return _error(item, {
        'project_id': ['This record belongs to another project.'],
      });
    }

    // A retry of exactly the version already applied, settled before the
    // status check so a lost response is never reported as a conflict.
    if (_sameStamp(item.clientUpdatedAt, row['client_updated_at'])) {
      return _result(item, 'duplicate', row);
    }
    if (row['deleted_at'] != null) return _result(item, 'duplicate', row);

    final status = row['status'] as String;
    if (status != 'DRAFT' && status != 'RETURNED') {
      return _result(item, 'conflict', row, reason: status.toLowerCase());
    }

    final decision = _reconcile(item, row);
    if (decision != null) return decision;

    final errors = validate();
    if (errors != null) return _error(item, errors);
    fill(row, item.data);
    _applyStamp(item, row);
    return _result(item, 'updated', row);
  }

  /// Rules 3 and 4. Returns a result when the write should not apply.
  PushResult? _reconcile(PushItem item, Row row) {
    final stored = (row['client_updated_at'] ?? row['updated_at']) as String;
    final incoming = item.clientUpdatedAt;
    if (incoming == null || _sameStamp(incoming, stored)) {
      return _result(item, 'duplicate', row);
    }
    if (DateTime.parse(incoming).isBefore(DateTime.parse(stored))) {
      return _result(item, 'stale', row);
    }
    return null;
  }

  void _applyStamp(PushItem item, Row row) {
    row['client_updated_at'] = item.clientUpdatedAt;
    row['client_uuid'] ??= item.clientUuid;
    row['updated_at'] = _stampNow();
    _recordStamp(item);
  }

  /// A live row by reference: uuid when given, else id. Excludes soft-deleted.
  Row? _findByReference(List<Row> table, String? uuid, int? id) {
    for (final row in table) {
      if (row['deleted_at'] != null) continue;
      if (uuid != null ? row['client_uuid'] == uuid : row['id'] == id) {
        return row;
      }
    }
    return null;
  }

  PushResult _submit(
    PushItem item,
    List<Row> table,
    String? uuid,
    int? serverId,
  ) {
    final row = _findByReference(table, uuid, serverId);
    if (row == null) {
      return _error(item, {
        'id': ['Record not found on the server.'],
      });
    }
    final status = row['status'] as String;
    if (status == 'IN_REVIEW' || status == 'APPROVED') {
      return _result(item, 'duplicate', row);
    }
    if (status == 'REJECTED') {
      return _result(item, 'conflict', row, reason: 'rejected');
    }
    row['status'] = 'IN_REVIEW';
    row['updated_at'] = _stampNow();
    return _result(item, 'updated', row);
  }

  PushResult _issueWrite(PushItem item) {
    final row = _findRow(issues, item);
    if (row == null) {
      final created = <String, dynamic>{
        'id': _nextId++,
        'client_uuid': item.clientUuid,
        'project_id': item.projectId,
        'site_id': item.data['site_id'],
        'title': item.data['title'],
        'description': item.data['description'],
        'priority': item.data['priority'],
        'status': 'open',
        'location_note': item.data['location_note'],
        'assigned_to': item.data['assigned_to'],
        'raised_by': user['id'],
        'due_date': item.data['due_date'],
        'resolved_at': null,
        'client_updated_at': item.clientUpdatedAt,
        'updated_at': _stampNow(),
      };
      issues.add(created);
      _recordStamp(item);
      return _result(item, 'created', created);
    }
    if (row['deleted_at'] != null) return _result(item, 'duplicate', row);
    final decision = _reconcile(item, row);
    if (decision != null) return decision;
    row['title'] = item.data['title'];
    _applyStamp(item, row);
    return _result(item, 'updated', row);
  }

  /// Last writer wins against the stored stamp; an equal stamp is `duplicate`.
  PushResult _issueStatus(PushItem item) {
    final row = _findByReference(
      issues,
      item.data['issue_client_uuid'] as String?,
      item.data['issue_id'] as int?,
    );
    if (row == null) {
      return _error(item, {
        'issue_id': ['Record not found on the server.'],
      });
    }

    if (item.clientUpdatedAt == null && row['status'] != item.data['status']) {
      row['status'] = item.data['status'];
      row['updated_at'] = _stampNow();
      return _result(item, 'updated', row);
    }

    final decision = _reconcile(item, row);
    if (decision != null) return decision;
    row['status'] = item.data['status'];
    _applyStamp(item, row);
    return _result(item, 'updated', row);
  }

  PushResult _attendanceWrite(PushItem item) {
    final data = item.data;

    for (final candidate in attendance) {
      if (candidate['client_uuid'] == item.clientUuid &&
          _sameStamp(candidate['client_updated_at'], item.clientUpdatedAt)) {
        return _result(item, 'duplicate', candidate);
      }
    }

    if (_isFuture(data['attendance_date'])) {
      return _error(item, {
        'attendance_date': ['The date must be today or earlier.'],
      });
    }

    Row? row;
    for (final candidate in attendance) {
      if (candidate['project_id'] == item.projectId &&
          candidate['worker_id'] == data['worker_id'] &&
          candidate['attendance_date'] == data['attendance_date']) {
        row = candidate;
      }
    }

    if (row == null) {
      final created = <String, dynamic>{
        'id': _nextId++,
        'client_uuid': item.clientUuid,
        'project_id': item.projectId,
        'worker_id': data['worker_id'],
        'attendance_date': data['attendance_date'],
        'status': data['status'],
        'ot_hours': data['ot_hours'],
        'marked_by': user['id'],
        'client_updated_at': item.clientUpdatedAt,
        'updated_at': _stampNow(),
      };
      attendance.add(created);
      _recordStamp(item);
      return _result(item, 'created', created);
    }

    final decision = _reconcile(item, row);
    if (decision != null) return decision;
    row
      ..['status'] = data['status']
      ..['ot_hours'] = data['ot_hours'];
    _applyStamp(item, row);
    return _result(item, 'updated', row);
  }

  PushResult _pettyWrite(PushItem item) {
    final data = item.data;
    for (final row in pettyEntries) {
      if (row['client_uuid'] == item.clientUuid) {
        return _result(item, 'duplicate', row);
      }
    }
    final walletId = data['wallet_id'] as int;
    final wallet = wallets.where((w) => w['id'] == walletId).firstOrNull;
    if (wallet == null || wallet['project_id'] != item.projectId) {
      return _error(item, {
        'wallet_id': ['Wallet does not belong to this project.'],
      });
    }
    if (_isFuture(data['entry_date'])) {
      return _error(item, {
        'entry_date': ['The date must be today or earlier.'],
      });
    }
    if (data['type'] != 'expense' && data['type'] != 'topup') {
      return _error(item, {
        'type': ['The selected type is invalid.'],
      });
    }
    if (data['type'] == 'expense' && data['category'] == null) {
      return _error(item, {
        'category': ['The category field is required.'],
      });
    }
    final created = <String, dynamic>{
      'id': _nextId++,
      'client_uuid': item.clientUuid,
      'petty_cash_wallet_id': walletId,
      'type': data['type'],
      'amount': data['amount'],
      'category': data['category'],
      'description': data['description'],
      'entry_date': data['entry_date'],
      'created_by': user['id'],
      'has_bill': false,
      'updated_at': _stampNow(),
    };
    pettyEntries.add(created);
    final amount = Decimal.parse(data['amount'] as String);
    final balance = Decimal.parse(wallet['balance'] as String);
    wallet['balance'] =
        (data['type'] == 'expense' ? balance - amount : balance + amount)
            .toStringAsFixed(2);
    return _result(item, 'created', created);
  }

  void _recordStamp(PushItem item) {
    final stamp = item.clientUpdatedAt;
    if (stamp != null) {
      appliedStamps.putIfAbsent(item.clientUuid, () => []).add(stamp);
    }
  }

  /// Nos × L × B × D, rounded half-up to three places, like `computeQty`.
  String _measurementQty(Map<String, dynamic> data) {
    Decimal parseOr(Object? value, Decimal fallback) {
      if (value == null || value == '') return fallback;
      return Decimal.parse(value.toString());
    }

    var product = parseOr(data['nos'], Decimal.one);
    for (final dimension in ['length', 'breadth', 'depth']) {
      final raw = data[dimension];
      if (raw != null && raw != '') {
        product = product * Decimal.parse(raw.toString());
      }
    }
    final isDeduction = data['is_deduction'] == true;
    return (isDeduction ? -product : product).toStringAsFixed(3);
  }

  @override
  Future<Map<String, dynamic>> pull({String? since}) async {
    _guard();
    pullCalls.add(since);
    final hook = beforeNextPull;
    beforeNextPull = null;
    await hook?.call();
    if (_pullFailures.isNotEmpty) throw _pullFailures.removeAt(0);
    if (pullBodyOverride != null) return pullBodyOverride!;

    final serverTime = _stampNow();
    final from = since == null ? null : DateTime.parse(since);

    bool atOrAfter(Object? stamp) {
      if (from == null) return true;
      if (stamp == null) return true;
      return !DateTime.parse(stamp as String).isBefore(from);
    }

    List<Row> pick(List<Row> table) => table
        .where((row) => row['deleted_at'] == null && atOrAfter(row['updated_at']))
        .map((row) => Map<String, dynamic>.of(row))
        .toList();

    // `deleted` and `boq_balances` are deltas: empty with no `since`.
    List<int> deletedIds(String kind) => from == null
        ? const []
        : _deletedLog[kind]!
              .where((d) => atOrAfter(d['deleted_at']))
              .map((d) => d['id'] as int)
              .toList();

    final balances = from == null ? const <Row>[] : _movedBalances(atOrAfter);

    return {
      'server_time': serverTime,
      'user': user,
      'meta': meta,
      'users': users,
      'projects': pick(projects),
      'sites': pick(sites),
      'boq_items': pick(boqItems).map(_withLedger).toList(),
      'boq_balances': balances,
      'workers': pick(workers),
      'wallets': wallets.map((w) => Map<String, dynamic>.of(w)).toList(),
      'dprs': pick(dprs),
      'issues': pick(issues),
      'attendance': pick(attendance),
      'petty_cash_entries': pick(pettyEntries),
      'measurements': pick(measurements),
      'approvals': approvalInbox,
      'deleted': {
        for (final kind in _deletedLog.keys) kind: deletedIds(kind),
      },
    };
  }

  List<Row> _movedBalances(bool Function(Object?) atOrAfter) {
    final moved = <Object?>{
      for (final row in measurements)
        if (atOrAfter(row['updated_at'])) row['boq_item_id'],
      for (final row in _deletedLog['measurements']!)
        if (atOrAfter(row['deleted_at'])) row['boq_item_id'],
    };
    return boqItems
        .where((item) => moved.contains(item['id']))
        .map(_balance)
        .toList();
  }

  Row _balance(Row item) {
    final totals = _ledger(item['id'] as int);
    return {
      'id': item['id'],
      'measured_qty': totals.$1.toStringAsFixed(3),
      'approved_qty': totals.$2.toStringAsFixed(3),
      'balance_qty': (Decimal.parse(item['qty'] as String) - totals.$1)
          .toStringAsFixed(3),
    };
  }

  Row _withLedger(Row item) => {...item, ..._balance(item)};

  (Decimal, Decimal) _ledger(int boqItemId) {
    var measured = Decimal.zero;
    var approved = Decimal.zero;
    for (final row in measurements) {
      if (row['boq_item_id'] != boqItemId) continue;
      final qty = Decimal.parse(row['qty'] as String);
      if (row['status'] != 'REJECTED') measured += qty;
      if (row['status'] == 'APPROVED') approved += qty;
    }
    return (measured, approved);
  }

  @override
  Future<UploadResult> upload(UploadRequest request) async {
    _guard();
    if (_uploadFailures.isNotEmpty) throw _uploadFailures.removeAt(0);
    uploadCalls.add(request);

    final isPhoto = request.type == 'dpr_photo';
    final parent = _findByReference(
      isPhoto ? dprs : pettyEntries,
      request.parentClientUuid,
      request.parentId,
    );
    if (parent == null) {
      throw const ServerFailure('Parent not found.', statusCode: 404);
    }

    late final UploadResult result;
    if (!isPhoto) {
      // For a bill, `id` is the entry's and a second bill is a duplicate.
      final already = parent['has_bill'] == true;
      parent['has_bill'] = true;
      if (!already) {
        uploads.add({
          'id': parent['id'],
          'client_uuid': request.clientUuid,
          'type': request.type,
          'parent': parent['client_uuid'],
          'caption': request.caption,
        });
      }
      result = UploadResult(
        status: already ? 'duplicate' : 'created',
        id: parent['id'] as int,
      );
    } else {
      final existing = uploads
          .where((u) => u['client_uuid'] == request.clientUuid)
          .firstOrNull;
      if (existing != null) {
        // Already stored stays `duplicate`, even on an approved parent.
        result = UploadResult(status: 'duplicate', id: existing['id'] as int);
      } else {
        if (parent['status'] == 'APPROVED') {
          throw const ServerFailure('Parent approved.', statusCode: 409);
        }
        final id = _nextId++;
        uploads.add({
          'id': id,
          'client_uuid': request.clientUuid,
          'type': request.type,
          'parent': parent['client_uuid'],
          'caption': request.caption,
        });
        (parent['photos'] as List).add({'id': id, 'caption': request.caption});
        parent['updated_at'] = _stampNow();
        result = UploadResult(status: 'created', id: id);
      }
    }

    if (_lostUploadResponses > 0) {
      _lostUploadResponses--;
      throw const OfflineFailure('Response lost');
    }
    return result;
  }

  @override
  Future<ApprovalsResponse> approvals() async {
    _guard();
    return ApprovalsResponse(items: approvalInbox, waitingCount: 0);
  }

  @override
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
  }) async {
    _guard();
    final previous = _approvalActs[clientUuid];
    if (previous != null) return previous;
    final item = approvalInbox.firstWhere(
      (a) => a['id'] == approvalId,
      orElse: () => {'id': approvalId},
    );
    _approvalActs[clientUuid] = item;
    approvalInbox.removeWhere((a) => a['id'] == approvalId);
    return item;
  }

  // Setup from the app ------------------------------------------------------

  /// HTTP status of each setup create, in order: 201 new, 200 repeated.
  final List<int> setupStatuses = [];
  final Map<String, Row> _setupByUuid = {};

  static const planLimitMessage =
      'Your plan project limit has been reached. Upgrade to add more.';

  void _requireSetupPermission(String permission) {
    if ((user['can'] as Map)[permission] != true) {
      throw const ServerFailure('This action is forbidden.', statusCode: 403);
    }
  }

  void _requireProject(int projectId) {
    if (!projects.any((p) => p['id'] == projectId)) {
      throw const ServerFailure('Not found.', statusCode: 404);
    }
  }

  void _requireField(String field, String? value) {
    if (value == null || value.trim().isEmpty) {
      throw ValidationFailure(
        'The $field field is required.',
        errors: {
          field: ['The $field field is required.'],
        },
      );
    }
  }

  /// A repeated `client_uuid` gets the original row and creates nothing.
  Row _createOnce(
    String clientUuid,
    Row Function() create,
    Row Function(Row row) present,
  ) {
    final existing = _setupByUuid[clientUuid];
    final row = existing ?? create();
    _setupByUuid[clientUuid] = row;
    setupStatuses.add(existing == null ? 201 : 200);

    if (_lostSetupResponses > 0) {
      _lostSetupResponses--;
      throw const OfflineFailure('Response lost');
    }
    return present(row);
  }

  /// Up to 4 uppercase initials, with a number if needed to stay unique.
  String _generateProjectCode(String name) {
    final initials = name
        .trim()
        .split(RegExp(r'\s+'))
        .where((word) => word.isNotEmpty)
        .take(4)
        .map((word) => word[0].toUpperCase())
        .join();
    final taken = projects.map((p) => p['code']).toSet();
    var code = initials;
    for (var n = 2; taken.contains(code); n++) {
      code = '$initials$n';
    }
    return code;
  }

  @override
  Future<Map<String, dynamic>> createProject({
    required String clientUuid,
    required String name,
    String? location,
    String? startDate,
    String? endDate,
    String? contractValue,
    String? code,
  }) async {
    _guard();
    _requireSetupPermission('project.manage');
    return _createOnce(
      clientUuid,
      () {
        _requireField('name', name);
        final limit = planProjectLimit;
        if (limit != null && projects.length >= limit) {
          throw const ValidationFailure(
            planLimitMessage,
            errors: {
              'plan': [planLimitMessage],
            },
          );
        }
        final row = <String, dynamic>{
          'id': _nextId++,
          'client_uuid': clientUuid,
          'code': code == null || code.trim().isEmpty
              ? _generateProjectCode(name)
              : code,
          'name': name,
          'location': location,
          'status': 'active',
          'start_date': startDate,
          'end_date': endDate,
          'contract_value': contractValue,
          'measurement_tolerance_pct': '5.00',
          'updated_at': _stampNow(),
        };
        projects.add(row);
        return row;
      },
      (row) => Map<String, dynamic>.of(row),
    );
  }

  /// Two jobs under one name. `NirmaanApi` needs this name, and existing
  /// tests already seed BOQ lines with it. With [id] it seeds a line directly;
  /// without, it is `POST /projects/{project}/boq-items`.
  @override
  Future<Map<String, dynamic>> addBoqItem({
    required int projectId,
    int? id,
    String? clientUuid,
    String? description,
    String? unit,
    String? qty,
    String? rate,
    String? code,
    String? section,
  }) async {
    if (id != null) {
      _seedBoqItem(
        id: id,
        projectId: projectId,
        code: code!,
        qty: qty ?? '100.000',
        rate: rate ?? '500.00',
        unit: unit ?? 'cum',
      );
      return const {};
    }

    _guard();
    _requireSetupPermission('boq.manage');
    _requireProject(projectId);
    return _createOnce(
      clientUuid!,
      () {
        _requireField('description', description);
        _requireField('unit', unit);
        final inProject = boqItems.where((b) => b['project_id'] == projectId);
        final numbers = inProject
            .map((b) => int.tryParse(b['code'] as String))
            .nonNulls;
        final next = [inProject.length, ...numbers].reduce(max) + 1;
        final sort = inProject
            .map((b) => b['sort'] as int)
            .fold<int>(0, max);
        final row = <String, dynamic>{
          'id': _nextId++,
          'client_uuid': clientUuid,
          'project_id': projectId,
          'section': section,
          'code': code == null || code.trim().isEmpty ? '$next' : code,
          'description': description,
          'unit': unit,
          'qty': qty,
          'rate': rate,
          'sort': sort + 1,
          'updated_at': _stampNow(),
        };
        boqItems.add(row);
        return row;
      },
      _withLedger,
    );
  }

  @override
  Future<Map<String, dynamic>> createWallet({
    required int projectId,
    required String clientUuid,
    required String name,
    int? userId,
  }) async {
    _guard();
    _requireSetupPermission('petty_cash.manage');
    _requireProject(projectId);
    return _createOnce(
      clientUuid,
      () {
        _requireField('name', name);
        final row = <String, dynamic>{
          'id': _nextId++,
          'client_uuid': clientUuid,
          'project_id': projectId,
          'name': name,
          'user_id': userId,
          'balance': '0.00',
          'updated_at': _stampNow(),
        };
        wallets.add(row);
        return row;
      },
      (row) => Map<String, dynamic>.of(row),
    );
  }

  /// How many times the server applied an approval action, distinct by uuid.
  int get appliedApprovalActs => _approvalActs.length;

  @override
  Future<Map<String, dynamic>> addWorker({
    required String clientUuid,
    required String name,
    required String trade,
    String? phone,
    String type = 'own',
    String? dailyWage,
  }) async {
    _guard();
    final existing = _workersByUuid[clientUuid];
    if (existing != null) return existing;
    final worker = <String, dynamic>{
      'id': _nextId++,
      'name': name,
      'phone': phone,
      'trade': trade,
      'type': type,
      'daily_wage': dailyWage ?? '0.00',
      'is_active': true,
      'updated_at': _stampNow(),
    };
    workers.add(worker);
    _workersByUuid[clientUuid] = worker;
    return worker;
  }

  @override
  Future<Uint8List> dprPhotoBytes(int photoId) async {
    _guard();
    return Uint8List.fromList(const [1, 2, 3]);
  }

  @override
  Future<Uint8List> pettyBillBytes(int entryId) async {
    _guard();
    return Uint8List.fromList(const [4, 5, 6]);
  }

  // Site progress ------------------------------------------------------------

  Map<String, dynamic> progressSummaryBody = {'work_percent': '40.00', 'time_percent': '55.00', 'lag': '15.00', 'status': 'behind', 'open_pending': 1};
  List<Row> progressFloorRows = [
    {
      'id': 11,
      'name': 'First floor',
      'parent_id': null,
      'percent': '50.00',
      'targets': 2,
      'pending': 1,
      'mep_pending': 0,
      'children': [
        {'id': 21, 'name': 'Flat 101', 'parent_id': 11, 'percent': '25.00', 'targets': 1, 'pending': 0, 'mep_pending': 0, 'children': <Row>[]},
      ],
    },
  ];
  List<Row> progressItemRows = [
    {'id': 1, 'section': 'RCC', 'code': '2.1', 'description': 'RCC slab', 'unit': 'cum', 'qty': '100.000', 'done_qty': '40.000', 'balance_qty': '60.000', 'percent': '40.00'},
  ];
  List<Row> progressPendingRows = [
    {'id': 70, 'item_code': '2.1', 'item_description': 'RCC slab', 'floor_name': 'First floor', 'remark': 'Shuttering left', 'work_date': '2026-11-08', 'entered_by_name': 'Amit'},
  ];
  Map<String, dynamic> stageMatrixBody = {
    'trades': [
      {
        'key': 'plumbing',
        'label': 'Plumbing',
        'stages': [
          {'key': 'core_cutting', 'no': 1, 'label': 'Core cutting', 'hint': null, 'optional': false},
          {'key': 'drainage', 'no': 2, 'label': 'Drainage line', 'hint': null, 'optional': true},
        ],
      },
    ],
    'statuses': [
      {'key': 'not_started', 'label': 'Not started'},
      {'key': 'in_progress', 'label': 'In progress'},
      {'key': 'done', 'label': 'Done'},
      {'key': 'pending', 'label': 'Pending'},
      {'key': 'na', 'label': 'Not applicable'},
    ],
    'rows': [
      {
        'id': 11,
        'name': 'First floor',
        'label': 'First floor',
        'parent_id': null,
        'level': 0,
        'cells': {
          'plumbing': {
            'core_cutting': {'id': 5, 'status': 'done', 'remark': null},
            'drainage': {'id': null, 'status': 'not_started', 'remark': null},
          },
        },
      },
      {
        'id': 21,
        'name': 'Flat 101',
        'label': 'First floor › Flat 101',
        'parent_id': 11,
        'level': 1,
        'cells': {
          'plumbing': {
            'core_cutting': {'id': null, 'status': 'pending', 'remark': 'Sleeve missing'},
            'drainage': {'id': null, 'status': 'na', 'remark': null},
          },
        },
      },
    ],
  };
  Row floorProgressBody = {
    'id': 11,
    'name': 'First floor',
    'parent_id': null,
    'percent': '50.00',
    'targets': 2,
    'pending': 1,
    'mep_pending': 0,
    'children': <Row>[],
    'lines': [
      {'boq_item_id': 1, 'code': '2.1', 'description': 'RCC slab', 'unit': 'cum', 'planned_qty': '50.000', 'done_qty': '25.000', 'balance_qty': '25.000', 'percent': '50.00'},
    ],
    'pending_entries': <Row>[],
  };

  /// Everything posted, by `client_uuid`: a repeat must not add a second.
  final Map<String, Row> progressPosts = {};
  final Map<String, Row> stagePosts = {};
  final Map<String, Row> resolvePosts = {};
  final List<(String, String)> fieldPhotos = [];
  ApiFailure? failNextProgressPost;

  /// The next post is stored, then its reply is lost.
  bool loseNextProgressReply = false;
  var _progressSerial = 900;

  Map<String, dynamic> _storeProgress(Map<String, Row> store, Map<String, dynamic> body) {
    _guard();
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    final uuid = body['client_uuid'] as String;
    final row = store.putIfAbsent(uuid, () => {...body, 'id': _progressSerial++});
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return row;
  }

  @override
  Future<Map<String, dynamic>> progressSummary(int projectId) async {
    _guard();
    return progressSummaryBody;
  }

  @override
  Future<List<Map<String, dynamic>>> progressFloors(int projectId) async {
    _guard();
    return progressFloorRows;
  }

  @override
  Future<List<Map<String, dynamic>>> progressItems(int projectId) async {
    _guard();
    return progressItemRows;
  }

  @override
  Future<List<Map<String, dynamic>>> progressPending(int projectId) async {
    _guard();
    return progressPendingRows;
  }

  @override
  Future<Map<String, dynamic>> stageMatrix(int projectId) async {
    _guard();
    return stageMatrixBody;
  }

  @override
  Future<Map<String, dynamic>> floorProgress(int floorId) async {
    _guard();
    return floorProgressBody;
  }

  @override
  Future<Map<String, dynamic>> postProgressEntry(int projectId, Map<String, dynamic> body) async =>
      _storeProgress(progressPosts, body);

  @override
  Future<Map<String, dynamic>> resolveProgress(int entryId, Map<String, dynamic> body) async =>
      _storeProgress(resolvePosts, {...body, 'entry_id': entryId});

  @override
  Future<Map<String, dynamic>> postStageCell(int projectId, Map<String, dynamic> body) async =>
      _storeProgress(stagePosts, body);

  @override
  Future<Map<String, dynamic>> uploadFieldPhoto(
    String path, {
    required String filePath,
    required String clientUuid,
    String? caption,
    String? takenAt,
  }) async {
    _guard();
    fieldPhotos.add((path, clientUuid));
    return {'id': _progressSerial++, 'client_uuid': clientUuid};
  }

  /// Server time as a wire timestamp, advancing one second per call. Use it
  /// for rows a test adds by hand, so a delta pull picks them up.
  String stampNow() => _stampNow();

  /// Advances server time by one second per call, so `updated_at` is ordered.
  String _stampNow() {
    _now = _now.add(const Duration(seconds: 1));
    final local = _now.add(const Duration(hours: 5, minutes: 30));
    String two(int v) => v.toString().padLeft(2, '0');
    return '${local.year}-${two(local.month)}-${two(local.day)}T'
        '${two(local.hour)}:${two(local.minute)}:${two(local.second)}+05:30';
  }
}
