import 'dart:async';
import 'dart:convert' show base64, base64Decode, base64Url, jsonDecode, jsonEncode, utf8;
import 'dart:math' show atan2, cos, max, min, pi, sin, sqrt;
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart' show KeyPairType, SimpleKeyPair, SimplePublicKey;
import 'package:cryptography/dart.dart' show DartEd25519;
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
/// One `POST /devices`, as the fake server saw it.
typedef DeviceCall = ({
  String token,
  String platform,
  String? deviceName,
  String? previousToken,
  String? appVersion,
  String? locale,
  bool portal,
});

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

  // The offline plan ----------------------------------------------------------

  /// The company is on the offline plan: only `auth`, `me` and `offline/*` work.
  bool offlineCompany = false;

  /// What `GET /offline/status` answers for a normal company.
  int offlineStatusCalls = 0;
  int activateCalls = 0;
  int refreshCalls = 0;
  int releaseCalls = 0;

  /// Requests the real server would have turned away with `offline_plan`.
  int refusedCalls = 0;

  /// The next status call throws this. Every later one answers normally.
  ApiFailure? failNextOfflineStatus;

  /// When set, activating answers this failure.
  ApiFailure? activateFailure;

  /// When set, refreshing answers this failure.
  ApiFailure? refreshFailure;

  /// Seconds a new token stays good.
  int licenceSeconds = 90 * 24 * 3600;
  String? lastDeviceId;

  /// Corrupts the signature on tokens from now on.
  bool forgeLicence = false;

  final DartEd25519 _ed25519 = DartEd25519();
  SimpleKeyPair? _licenceKeys;

  void _refuseIfOffline() {
    if (!offlineCompany) return;
    refusedCalls++;
    throw const OfflinePlanRefused('Your company is on the offline plan.', upgradeUrl: 'http://localhost/billing');
  }

  @override
  Future<Map<String, dynamic>> offlineStatus() async {
    offlineStatusCalls++;
    if (offline) throw const OfflineFailure();
    if (unauthorized) throw const AuthExpired();
    final ApiFailure? failure = failNextOfflineStatus;
    if (failure != null) {
      failNextOfflineStatus = null;
      throw failure;
    }
    return <String, dynamic>{
      'plan': offlineCompany ? 'offline' : 'pro',
      'offline': offlineCompany,
      'syncs': !offlineCompany,
      'max_devices': 1,
      'active_devices': 0,
      'licence_days': 90,
    };
  }

  Future<Map<String, dynamic>> _issueLicence(String deviceId) async {
    final SimpleKeyPair keys = _licenceKeys ??= await _ed25519.newKeyPair();
    final SimplePublicKey publicKey = await keys.extractPublicKey();
    assert(publicKey.type == KeyPairType.ed25519);
    final int exp = _now.millisecondsSinceEpoch ~/ 1000 + licenceSeconds;
    final List<int> payload = utf8.encode(
      jsonEncode(<String, Object>{
        'v': 1,
        'kid': 'test-key',
        'lic': 7,
        'org': 1,
        'usr': 4,
        'dev': deviceId,
        'plan': 'offline',
        'features': <String>['site', 'measurement', 'labour', 'petty_cash'],
        'max_devices': 1,
        'iat': _now.millisecondsSinceEpoch ~/ 1000,
        'exp': exp,
      }),
    );
    final signature = await _ed25519.sign(payload, keyPair: keys);
    final List<int> sig = forgeLicence ? List<int>.filled(64, 7) : signature.bytes;
    String encode(List<int> bytes) => base64Url.encode(bytes).replaceAll('=', '');
    return <String, dynamic>{
      'licence': <String, Object>{'id': 7, 'device_id': deviceId, 'status': 'active'},
      'token': 'v1.${encode(payload)}.${encode(sig)}',
      'signing_key': <String, String>{
        'algorithm': 'Ed25519',
        'key_id': 'test-key',
        'public_key': base64.encode(publicKey.bytes),
      },
      'max_devices': 1,
      'active_devices': 1,
    };
  }

  @override
  Future<Map<String, dynamic>> activateDevice({
    required String deviceId,
    required String deviceName,
    String? platform,
    String? appVersion,
  }) async {
    activateCalls++;
    lastDeviceId = deviceId;
    if (offline) throw const OfflineFailure();
    final ApiFailure? failure = activateFailure;
    if (failure != null) throw failure;
    return _issueLicence(deviceId);
  }

  @override
  Future<Map<String, dynamic>> refreshDevice({required String deviceId, String? appVersion}) async {
    refreshCalls++;
    lastDeviceId = deviceId;
    if (offline) throw const OfflineFailure();
    final ApiFailure? failure = refreshFailure;
    if (failure != null) throw failure;
    return _issueLicence(deviceId);
  }

  // Push devices -------------------------------------------------------------

  /// Tokens the server holds, in the order they were first registered.
  final List<String> pushTokens = <String>[];

  /// Every `POST /devices` that reached the server, newest last.
  final List<DeviceCall> deviceRegistrations = <DeviceCall>[];

  /// Every `DELETE /devices` that reached the server.
  final List<String> deviceRemovals = <String>[];

  /// What `push_connected` says in the reply.
  bool pushConnected = false;

  /// What `GET /notification-preferences` answers. Each PUT merges into it.
  Map<String, dynamic> notificationSwitches = <String, dynamic>{
    'push': <String, dynamic>{
      'enabled': true,
      'connected': true,
      'categories': <Map<String, dynamic>>[
        <String, dynamic>{'key': 'alerts', 'label': 'Site alerts', 'hint': '', 'enabled': true},
        <String, dynamic>{'key': 'approvals', 'label': 'Approvals', 'hint': '', 'enabled': true},
      ],
      'quiet_hours': <String, dynamic>{'enabled': false, 'from': '22:00', 'until': '07:00', 'timezone': 'Asia/Kolkata'},
    },
  };

  /// Every `PUT /notification-preferences` body, and whether it went to the portal.
  final List<({Map<String, Object?> changes, bool portal})> preferenceChanges = <({Map<String, Object?> changes, bool portal})>[];

  /// The next `PUT /notification-preferences` throws this.
  ApiFailure? failNextPreferenceChange;

  @override
  Future<Map<String, dynamic>> notificationPreferences({bool portal = false}) async {
    if (offline) throw const OfflineFailure();
    return _copy(notificationSwitches);
  }

  @override
  Future<Map<String, dynamic>> updateNotificationPreferences(Map<String, Object?> changes, {bool portal = false}) async {
    if (offline) throw const OfflineFailure();
    final ApiFailure? failure = failNextPreferenceChange;
    if (failure != null) {
      failNextPreferenceChange = null;
      throw failure;
    }
    preferenceChanges.add((changes: changes, portal: portal));
    final Map<String, dynamic> push = notificationSwitches['push'] as Map<String, dynamic>;
    for (final MapEntry<String, Object?> change in changes.entries) {
      switch (change.key) {
        case 'categories':
          for (final MapEntry<Object?, Object?> category in (change.value! as Map).entries) {
            for (final Map<String, dynamic> row in (push['categories'] as List).cast<Map<String, dynamic>>()) {
              if (row['key'] == category.key) row['enabled'] = category.value;
            }
          }
        case 'quiet_hours':
          (push['quiet_hours'] as Map<String, dynamic>).addAll((change.value! as Map).cast<String, dynamic>());
        default:
          final Object? channel = notificationSwitches[change.key];
          if (channel is Map<String, dynamic>) channel['enabled'] = change.value;
      }
    }
    return _copy(notificationSwitches);
  }

  static Map<String, dynamic> _copy(Map<String, dynamic> value) =>
      (jsonDecode(jsonEncode(value)) as Map).cast<String, dynamic>();

  /// Sign-outs that reached the server. After one, the token is no longer good.
  int logoutCalls = 0;

  /// The next `POST /devices` throws this.
  ApiFailure? failNextDeviceRegistration;

  @override
  Future<DeviceRegistration> registerDevice({
    required String token,
    required String platform,
    String? previousToken,
    String? appVersion,
    String? locale,
    String? deviceName,
    bool portal = false,
  }) async {
    if (offline) throw const OfflineFailure();
    final ApiFailure? failure = failNextDeviceRegistration;
    if (failure != null) {
      failNextDeviceRegistration = null;
      throw failure;
    }
    deviceRegistrations.add((
      token: token,
      platform: platform,
      deviceName: deviceName,
      previousToken: previousToken,
      appVersion: appVersion,
      locale: locale,
      portal: portal,
    ));
    // As the server does: the token it replaces is retired.
    if (previousToken != null && previousToken != token) pushTokens.remove(previousToken);
    if (!pushTokens.contains(token)) pushTokens.add(token);
    return DeviceRegistration(pushConnected: pushConnected);
  }

  @override
  Future<void> unregisterDevice(String token, {bool portal = false}) async {
    if (offline) throw const OfflineFailure();
    // The real route needs the person's token, which a sign-out has already ended.
    if (logoutCalls > 0) throw const AuthExpired();
    deviceRemovals.add(token);
    pushTokens.remove(token);
  }

  @override
  Future<void> releaseDevice(String deviceId) async {
    releaseCalls++;
    if (offline) throw const OfflineFailure();
  }

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

  /// How many times `GET /me` was asked.
  int meReads = 0;

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
    logoutCalls++;
  }

  @override
  Future<Map<String, dynamic>> me() async {
    _guard();
    meReads++;
    return user;
  }

  @override
  Future<List<PushResult>> push(List<PushItem> items) async {
    _refuseIfOffline();
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
    _refuseIfOffline();
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
    _refuseIfOffline();
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
    _refuseIfOffline();
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
    _refuseIfOffline();
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
    _refuseIfOffline();
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
    _refuseIfOffline();
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

  /// Photos by address. An address not here answers 404.
  final Map<String, Uint8List> projectImages = {};
  final List<String> projectImageCalls = [];

  @override
  Future<Uint8List> projectImageBytes(String imageUrl) async {
    _guard();
    projectImageCalls.add(imageUrl);
    final bytes = projectImages[imageUrl];
    if (bytes == null) throw const ServerFailure('Not found.', statusCode: 404);
    return bytes;
  }

  // Contractor control ------------------------------------------------------

  /// What `ContractorControlPresenter` sends only with `money.view`.
  static const List<String> _contractorMoneyKeys = <String>[
    'contract_value', 'paid', 'advance', 'paid_pct', 'gap_pct', 'ratio_status', 'over_limit', 'limit_pct', 'label',
    'balance_work_value', 'balance_money', 'short_by', 'amount', 'balance', 'state', 'mode', 'paid_to', 'reference',
    'notes', 'is_advance', 'has_bill', 'is_reversal', 'approval_id', 'impact',
  ];

  bool get _seesContractorMoney => (user['can'] as Map)['money.view'] == true;

  Row _presentContractor(Row row) {
    if (_seesContractorMoney) return row;
    return {
      for (final entry in row.entries)
        if (!_contractorMoneyKeys.contains(entry.key))
          entry.key: entry.value is List
              ? [for (final item in entry.value as List) item is Row ? _presentContractor(item) : item]
              : entry.value,
    };
  }

  List<Row> contractorBoardRows = [
    {
      'work_order_id': 1,
      'number': 'WO-1',
      'title': 'Slab casting',
      'contractor': 'Shree Formwork',
      'contract_status': 'ACTIVE',
      'work_pct': '70.00',
      'balance_work_pct': '30.00',
      'contract_value': '100000.00',
      'paid': '90000.00',
      'advance': '0.00',
      'paid_pct': '90.00',
      'gap_pct': '20.00',
      'ratio_status': 'red',
      'over_limit': true,
      'limit_pct': '5.00',
      'label': 'Paid 90%, Work 70%',
      'balance_work_value': '30000.00',
      'balance_money': '10000.00',
      'short_by': '20000.00',
    },
    {
      'work_order_id': 2,
      'number': 'WO-2',
      'title': 'Plaster',
      'contractor': 'Patil Constructions',
      'contract_status': 'ACTIVE',
      'work_pct': '16.00',
      'balance_work_pct': '84.00',
      'contract_value': '15000.00',
      'paid': '0.00',
      'advance': '0.00',
      'paid_pct': '0.00',
      'gap_pct': '-16.00',
      'ratio_status': 'green',
      'over_limit': false,
      'limit_pct': '5.00',
      'label': 'Paid 0%, Work 16%',
      'balance_work_value': '12600.00',
      'balance_money': '15000.00',
      'short_by': '0.00',
    },
    {
      'work_order_id': 3,
      'number': 'WO-3',
      'title': 'Old work',
      'contractor': 'Closed Co',
      'contract_status': 'CLOSED',
      'work_pct': '100.00',
      'balance_work_pct': '0.00',
      'contract_value': '5000.00',
      'paid': '5000.00',
      'advance': '0.00',
      'paid_pct': '100.00',
      'gap_pct': '0.00',
      'ratio_status': 'green',
      'over_limit': false,
      'limit_pct': '5.00',
      'label': 'Paid 100%, Work 100%',
      'balance_work_value': '0.00',
      'balance_money': '0.00',
      'short_by': '0.00',
    },
  ];
  Map<int, List<Row>> contractorLineRows = {
    1: [
      {
        'boq_item_id': 3,
        'code': '2.1',
        'description': 'RCC M25 in slabs',
        'unit': 'cum',
        'work_pct': '70.00',
        'balance_work_pct': '30.00',
        'contract_value': '100000.00',
        'paid': '90000.00',
        'advance': null,
        'paid_pct': '90.00',
        'gap_pct': '20.00',
        'ratio_status': 'red',
        'over_limit': true,
        'limit_pct': '5.00',
        'label': 'Paid 90%, Work 70%',
        'balance_work_value': '30000.00',
        'balance_money': '10000.00',
        'short_by': '20000.00',
      },
    ],
  };
  Map<int, List<Row>> contractorPartRows = {
    1: [
      {'id': 11, 'title': 'Plinth', 'done': true, 'done_on': '2026-11-01', 'amount': '25000.00', 'paid': '25000.00', 'balance': '0.00', 'state': 'paid'},
      {'id': 12, 'title': 'Roof', 'done': false, 'done_on': null, 'amount': '75000.00', 'paid': '0.00', 'balance': '75000.00', 'state': 'not_paid'},
    ],
  };
  List<Row> contractorPaymentRows = [
    {
      'id': 21,
      'number': 'CP-21',
      'status': 'IN_REVIEW',
      'work_order_id': 1,
      'work_order_number': 'WO-1',
      'contractor': 'Shree Formwork',
      'boq_item_id': null,
      'milestone_id': null,
      'paid_on': '2026-11-05',
      'entered_by': 'Sachin More',
      'client_uuid': null,
      'amount': '30000.00',
      'mode': 'bank',
      'paid_to': 'Shree Formwork',
      'reference': 'NEFT-9',
      'is_advance': false,
      'notes': null,
      'has_bill': true,
      'is_reversal': false,
      'approval_id': 3,
      'impact': null,
    },
  ];
  bool contractorMorePayments = false;
  ApiFailure? contractorBoardFailure;
  int contractorContractReads = 0;
  final Map<String, Row> contractorPaymentPosts = {};
  final List<String?> contractorPaymentFiles = [];
  int? contractorPaymentWorkOrder;

  @override
  Future<List<Map<String, dynamic>>> contractorBoard(int projectId) async {
    _guard();
    final failure = contractorBoardFailure;
    if (failure != null) throw failure;
    return contractorBoardRows.map(_presentContractor).toList();
  }

  @override
  Future<Map<String, dynamic>> contractorContract(int workOrderId) async {
    _guard();
    contractorContractReads++;
    final row = contractorBoardRows.firstWhere((r) => r['work_order_id'] == workOrderId);
    return _presentContractor({
      ...row,
      'lines': contractorLineRows[workOrderId] ?? <Row>[],
      'milestones': contractorPartRows[workOrderId] ?? <Row>[],
    });
  }

  @override
  Future<Map<String, dynamic>> contractorPayments(int projectId) async {
    _guard();
    final posted = contractorPaymentPosts.values.map(_presentContractor).toList();
    return {
      'data': [...posted, ...contractorPaymentRows.map(_presentContractor)],
      'meta': {'current_page': 1, 'last_page': contractorMorePayments ? 2 : 1},
    };
  }

  @override
  Future<Map<String, dynamic>> contractorPayment(int paymentId) async {
    _guard();
    final row = contractorPaymentRows.firstWhere((r) => r['id'] == paymentId);
    return _presentContractor({
      ...row,
      'impact': {
        'now': {'label': 'Paid 60%, Work 70%'},
        'after': {'label': 'Paid 90%, Work 70%'},
        'item': null,
      },
    });
  }

  @override
  Future<Uint8List> contractorPaymentBill(int paymentId) async {
    _guard();
    return Uint8List.fromList(const [37, 80, 68, 70, 45, 49]);
  }

  @override
  Future<Map<String, dynamic>> postContractorPayment(
    int workOrderId,
    Map<String, dynamic> body, {
    String? filePath,
  }) async {
    contractorPaymentWorkOrder = workOrderId;
    final stored = _storeProgress(contractorPaymentPosts, {
      ...body,
      'number': 'CP-new',
      'status': 'IN_REVIEW',
      'work_order_id': workOrderId,
      'work_order_number': 'WO-$workOrderId',
      'contractor': 'Shree Formwork',
      'entered_by': 'Sachin More',
    });
    contractorPaymentFiles.add(filePath);
    return _presentContractor(stored);
  }

  // Tenders on BOQ lines -------------------------------------------------------

  /// Tenders by BOQ line, in the presenter's shape with every figure. Money is
  /// taken out on the way for someone without money.view, as the server does.
  final Map<int, List<Row>> lineTenderRows = {};
  bool tenderCanQuote = true;
  bool tenderCanAward = true;
  ApiFailure? tenderFailure;
  final List<Row> subcontractorRows = [
    {'id': 7, 'name': 'Shree Formwork', 'phone': '9800000007', 'contact_person': 'Ramesh'},
    {'id': 8, 'name': 'Kalyan Builders', 'phone': null, 'contact_person': null},
  ];
  final List<(int, Row)> tenderStarts = [];
  final List<(int, Row, String?)> tenderQuotePosts = [];
  final List<(int, Row)> tenderAwards = [];
  final List<Row> subcontractorAdds = [];
  int boqWithTendersReads = 0;
  final Map<String, Row> _tenderOnce = {};
  int _nextTenderId = 500;

  static const Set<String> _tenderMoneyKeys = {
    'boq_rate',
    'boq_amount',
    'lowest_rate',
    'lowest_contractor',
    'rate',
    'amount',
    'diff',
    'diff_amount',
    'diff_pct',
    'lowest',
    'payment_terms',
    'remarks',
    'has_quotation',
    'quotation_name',
  };

  Object? _hideTenderMoney(Object? value) {
    if (_seesContractorMoney) return value;
    if (value is List) return value.map(_hideTenderMoney).toList();
    if (value is Map) {
      return <String, dynamic>{
        for (final entry in value.cast<String, dynamic>().entries)
          if (!_tenderMoneyKeys.contains(entry.key)) entry.key: _hideTenderMoney(entry.value),
      };
    }
    return value;
  }

  void _tenderGuard() {
    _guard();
    final failure = tenderFailure;
    if (failure != null) throw failure;
  }

  /// Adds a tender to a line, quotes included, as a test sets it up.
  Row seedTender({
    required int boqItemId,
    int? id,
    String title = 'Slab shuttering',
    String status = 'OPEN',
    String qty = '100.000',
    List<Row> quotes = const [],
    List<Row>? lines,
  }) {
    final int tenderId = id ?? _nextTenderId++;
    final Row tender = {
      'id': tenderId,
      'number': 'CT-$tenderId',
      'title': title,
      'status': status,
      'due_on': null,
      'work_order_id': null,
      'work_order_number': null,
      'qty': qty,
      'lines': lines ?? [
        {'id': tenderId * 10, 'boq_item_id': boqItemId, 'code': '2.1', 'description': 'RCC work 2.1', 'unit': 'cum', 'qty': qty},
      ],
      'quote_count': quotes.where((q) => q['current'] != false).length,
      'quotes': [...quotes],
      'boq_rate': '500.00',
      'boq_amount': '50000.00',
      'lowest_rate': null,
    };
    lineTenderRows.putIfAbsent(boqItemId, () => []).add(tender);
    return tender;
  }

  Row? _tenderById(int tenderId) {
    for (final rows in lineTenderRows.values) {
      for (final row in rows) {
        if (row['id'] == tenderId) return row;
      }
    }
    return null;
  }

  ({int tenders, int quotes, String? lowest, String? by}) _lineSummary(int boqItemId) {
    final rows = lineTenderRows[boqItemId] ?? const <Row>[];
    var quotes = 0;
    Decimal? lowest;
    String? by;
    for (final tender in rows) {
      for (final Row quote in (tender['quotes'] as List).cast<Row>()) {
        if (quote['current'] == false) continue;
        quotes++;
        final rate = quote['rate'] == null ? null : Decimal.parse('${quote['rate']}');
        if (rate != null && (lowest == null || rate < lowest)) {
          lowest = rate;
          by = quote['contractor'] as String?;
        }
      }
    }
    return (tenders: rows.length, quotes: quotes, lowest: lowest?.toStringAsFixed(2), by: by);
  }

  @override
  Future<List<Map<String, dynamic>>> boqWithTenders(int projectId) async {
    _tenderGuard();
    boqWithTendersReads++;
    return [
      for (final item in boqItems.where((b) => b['project_id'] == projectId))
        {
          ...item,
          'tenders': lineTenderRows[item['id']] == null
              ? null
              : _hideTenderMoney({
                  'tenders': _lineSummary(item['id'] as int).tenders,
                  'quotes': _lineSummary(item['id'] as int).quotes,
                  'lowest_rate': _lineSummary(item['id'] as int).lowest,
                }),
        },
    ];
  }

  @override
  Future<Map<String, dynamic>> boqLineTenders(int boqItemId) async {
    _tenderGuard();
    final item = boqItems.firstWhere(
      (b) => b['id'] == boqItemId,
      orElse: () => throw const ServerFailure('Not found.', statusCode: 404),
    );
    final rows = lineTenderRows[boqItemId] ?? <Row>[];
    final summary = _lineSummary(boqItemId);
    final qty = Decimal.parse('${item['qty']}');
    final rate = Decimal.parse('${item['rate']}');
    return _hideTenderMoney({
          'boq_item_id': boqItemId,
          'code': item['code'],
          'description': item['description'],
          'unit': item['unit'],
          'qty': item['qty'],
          'tender_count': rows.length,
          'open_tender_count': rows.where((r) => r['status'] == 'OPEN').length,
          'quote_count': summary.quotes,
          'tenders': rows,
          'can': {'quote': tenderCanQuote, 'award': tenderCanAward},
          'no_quantity': qty == Decimal.zero,
          'boq_rate': item['rate'],
          'boq_amount': (qty * rate).toStringAsFixed(2),
          'lowest_rate': summary.lowest,
          'lowest_contractor': summary.by,
        })
        as Row;
  }

  @override
  Future<Map<String, dynamic>> createLineTender(int boqItemId, Map<String, dynamic> body) async {
    _tenderGuard();
    final uuid = body['client_uuid'] as String;
    final kept = _tenderOnce[uuid];
    if (kept != null) return kept;
    if ('${body['title'] ?? ''}'.trim().isEmpty) {
      throw const ValidationFailure('The title field is required.', errors: {
        'title': ['The title field is required.'],
      });
    }
    final item = boqItems.firstWhere((b) => b['id'] == boqItemId);
    tenderStarts.add((boqItemId, {...body}));
    final tender = seedTender(boqItemId: boqItemId, title: body['title'] as String, qty: '${item['qty']}');
    tender['due_on'] = body['due_on'];
    (tender['lines'] as List).first['code'] = item['code'];
    return _tenderOnce[uuid] = {'id': tender['id'], 'number': tender['number']};
  }

  @override
  Future<List<Map<String, dynamic>>> subcontractors({String? query}) async {
    _tenderGuard();
    final needle = (query ?? '').toLowerCase();
    return subcontractorRows.where((r) => '${r['name']}'.toLowerCase().contains(needle)).map((r) => {...r}).toList();
  }

  @override
  Future<Map<String, dynamic>> createSubcontractor(Map<String, dynamic> body) async {
    _tenderGuard();
    if ('${body['name'] ?? ''}'.trim().isEmpty) {
      throw const ValidationFailure('The name field is required.', errors: {
        'name': ['The name field is required.'],
      });
    }
    subcontractorAdds.add({...body});
    final row = <String, dynamic>{
      'id': 100 + subcontractorRows.length,
      'name': body['name'],
      'phone': body['phone'],
      'contact_person': body['contact_person'],
    };
    subcontractorRows.add(row);
    return {...row};
  }

  @override
  Future<Map<String, dynamic>> tenderComparison(int tenderId) async {
    _tenderGuard();
    return _tenderById(tenderId) ?? (throw const ServerFailure('Not found.', statusCode: 404));
  }

  @override
  Future<Map<String, dynamic>> postTenderQuote(int tenderId, Map<String, dynamic> body, {String? filePath}) async {
    _tenderGuard();
    final uuid = body['client_uuid'] as String;
    final kept = _tenderOnce[uuid];
    if (kept != null) return kept;
    final tender = _tenderById(tenderId)!;
    if (tender['status'] != 'OPEN') throw const ServerFailure('This tender is closed.', statusCode: 409);
    tenderQuotePosts.add((tenderId, {...body}, filePath));
    final party = subcontractorRows.firstWhere((r) => r['id'] == body['party_id']);
    final quotes = (tender['quotes'] as List).cast<Row>();
    for (final quote in quotes) {
      if (quote['contractor'] == party['name']) quote['current'] = false;
    }
    final lines = (tender['lines'] as List).cast<Row>();
    final rates = (body['rates'] as Map?)?.cast<String, dynamic>() ?? const <String, dynamic>{};
    Decimal amount = Decimal.zero;
    for (final line in lines) {
      final rate = rates['${line['id']}'];
      if (rate != null) amount += Decimal.parse('$rate') * Decimal.parse('${line['qty']}');
    }
    if (rates.isEmpty && body['quoted_amount'] != null) amount = Decimal.parse('${body['quoted_amount']}');
    final bidId = 900 + tenderQuotePosts.length;
    quotes.add({
      'bid_id': bidId,
      'contractor': party['name'],
      'received_on': body['received_on'],
      'current': true,
      'selected': false,
      'valid_until': body['valid_until'],
      'includes_material': body['includes_material'] == 1,
      'completion_days': body['completion_days'],
      'entered_by': 'Rahul Patil',
      'rate': rates.isEmpty ? null : rates.values.first,
      'amount': amount.toStringAsFixed(2),
      'diff': null,
      'diff_amount': null,
      'diff_pct': null,
      'lowest': false,
      'payment_terms': body['payment_terms'],
      'remarks': body['remarks'],
      'has_quotation': filePath != null,
      'quotation_name': filePath == null ? null : 'quotation.jpg',
    });
    tender['quote_count'] = quotes.where((q) => q['current'] != false).length;
    return _tenderOnce[uuid] = {'data': _hideTenderMoney(tender), 'bid_id': bidId};
  }

  @override
  Future<Uint8List> quotationBytes(int bidId) async {
    _tenderGuard();
    // A PDF's first bytes: not a picture.
    return Uint8List.fromList(const [0x25, 0x50, 0x44, 0x46, 0x2D]);
  }

  @override
  Future<Map<String, dynamic>> awardTender(int tenderId, Map<String, dynamic> body) async {
    _tenderGuard();
    final tender = _tenderById(tenderId)!;
    if (tender['status'] != 'OPEN') throw const ServerFailure('This tender is closed.', statusCode: 409);
    tenderAwards.add((tenderId, {...body}));
    tender['status'] = 'AWARDED';
    tender['work_order_id'] = 99;
    tender['work_order_number'] = 'WO-99';
    for (final Row quote in (tender['quotes'] as List).cast<Row>()) {
      quote['selected'] = quote['bid_id'] == body['bid_id'];
    }
    return {
      'data': _hideTenderMoney(tender),
      'work_order': {'id': 99, 'number': 'WO-99'},
    };
  }

  // Inspections ---------------------------------------------------------------

  /// Gives the signed-in user the plan feature and the permission to fill in
  /// a checklist, as an engineer on a plan with `qhs` has them.
  void grantInspections() {
    final features = (user['organization'] as Map)['features'] as List;
    if (!features.contains('qhs')) features.add('qhs');
    grant('checklist.fill');
  }

  /// The company's active checklists, as `InspectionPresenter::template` sends them.
  List<Row> checklistTemplateRows = [
    {
      'id': 1,
      'name': 'Pour card',
      'category': 'pour_card',
      'description': null,
      'items': ['Level checked', 'Cover blocks fixed', 'Cubes cast'],
      'updated_at': '2026-11-01T09:00:00+05:30',
    },
    {
      'id': 2,
      'name': 'PPE checklist',
      'category': 'ppe',
      'description': null,
      'items': ['Helmets worn', 'Safety shoes worn', 'Harness used for work at height', 'Gloves used'],
      'updated_at': '2026-11-01T09:00:00+05:30',
    },
  ];

  Row _inspectionItem(int id, int position, String text, String result, [String? remark]) =>
      {'id': id, 'position': position, 'text': text, 'result': result, 'remark': remark};

  /// Inspections made on the website, as `InspectionPresenter::detail` sends them.
  late final Map<int, Row> inspectionStore = {
    11: {
      'id': 11,
      'client_uuid': null,
      'project_id': 1,
      'site_id': null,
      'checklist_template_id': 1,
      'title': 'Pour card',
      'category': 'pour_card',
      'location': 'Slab L2',
      'inspected_on': '2026-11-07',
      'status': 'passed',
      'completed_at': '2026-11-07T11:00:00+05:30',
      'updated_at': '2026-11-07T11:00:00+05:30',
      'items': [
        _inspectionItem(101, 1, 'Level checked', 'pass'),
        _inspectionItem(102, 2, 'Cover blocks fixed', 'pass'),
        _inspectionItem(103, 3, 'Cubes cast', 'na'),
      ],
    },
    12: {
      'id': 12,
      'client_uuid': null,
      'project_id': 1,
      'site_id': null,
      'checklist_template_id': 2,
      'title': 'PPE checklist',
      'category': 'ppe',
      'location': 'Gate 2',
      'inspected_on': '2026-11-08',
      'status': 'failed',
      'completed_at': '2026-11-08T10:00:00+05:30',
      'updated_at': '2026-11-08T10:00:00+05:30',
      'items': [
        _inspectionItem(111, 1, 'Helmets worn', 'pass'),
        _inspectionItem(112, 2, 'Safety shoes worn', 'pass'),
        _inspectionItem(113, 3, 'Harness used for work at height', 'fail', 'No harness on the 3rd floor edge'),
        _inspectionItem(114, 4, 'Gloves used', 'pass'),
      ],
    },
    13: {
      'id': 13,
      'client_uuid': null,
      'project_id': 1,
      'site_id': null,
      'checklist_template_id': 1,
      'title': 'Pour card',
      'category': 'pour_card',
      'location': null,
      'inspected_on': '2026-11-09',
      'status': 'in_progress',
      'completed_at': null,
      'updated_at': '2026-11-09T08:00:00+05:30',
      'items': [
        _inspectionItem(121, 1, 'Level checked', 'pass'),
        _inspectionItem(122, 2, 'Cover blocks fixed', 'pending'),
        _inspectionItem(123, 3, 'Cubes cast', 'pending'),
      ],
    },
  };

  /// Whole inspections posted from the phone, by `client_uuid`.
  final Map<String, Row> inspectionPosts = {};
  int inspectionPostCalls = 0;

  /// Sees the body of every post, as the server would.
  void Function(Map<String, dynamic> body)? onInspectionPost;
  int checklistTemplateReads = 0;
  int inspectionDetailReads = 0;

  /// Every read of the checklists or the list throws this.
  ApiFailure? inspectionReadFailure;

  /// Every read of the list alone throws this, so the checklists still come.
  ApiFailure? inspectionListFailure;
  var _inspectionSerial = 500;

  Row _inspectionSummary(Row detail) => {
    for (final entry in detail.entries)
      if (entry.key != 'items') entry.key: entry.value,
  };

  @override
  Future<List<Map<String, dynamic>>> checklistTemplates() async {
    _guard();
    checklistTemplateReads++;
    final failure = inspectionReadFailure;
    if (failure != null) throw failure;
    return checklistTemplateRows;
  }

  @override
  Future<Map<String, dynamic>> inspections(int projectId, {int page = 1}) async {
    _guard();
    final failure = inspectionReadFailure ?? inspectionListFailure;
    if (failure != null) throw failure;
    final rows = [
      ...inspectionStore.values,
      ...inspectionPosts.values,
    ].where((row) => row['project_id'] == projectId).toList()
      ..sort((a, b) {
        final byDay = (b['inspected_on'] as String).compareTo(a['inspected_on'] as String);
        return byDay != 0 ? byDay : (b['id'] as int).compareTo(a['id'] as int);
      });
    return {
      'data': [for (final row in rows.take(25)) _inspectionSummary(row)],
      'current_page': 1,
      'last_page': rows.length > 25 ? 2 : 1,
      'total': rows.length,
    };
  }

  @override
  Future<Map<String, dynamic>> inspection(int inspectionId) async {
    _guard();
    inspectionDetailReads++;
    final row = inspectionStore[inspectionId] ?? inspectionPosts.values.firstWhere((r) => r['id'] == inspectionId, orElse: () => {});
    if (row.isEmpty) throw const ServerFailure('Not found.', statusCode: 404);
    return row;
  }

  @override
  Future<Map<String, dynamic>> postInspection(int projectId, Map<String, dynamic> body) async {
    _guard();
    inspectionPostCalls++;
    onInspectionPost?.call(body);
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    final uuid = body['client_uuid'] as String;
    var stored = inspectionPosts[uuid];
    if (stored == null) {
      // ChecklistService::start. The whole call is one transaction: a refusal stores nothing.
      Row? template;
      for (final row in checklistTemplateRows) {
        if (row['id'] == body['checklist_template_id']) template = row;
      }
      if (template == null) {
        throw const ValidationFailure('The selected checklist template id is invalid.', errors: {
          'checklist_template_id': ['The selected checklist template id is invalid.'],
        });
      }
      final texts = [for (final text in template['items'] as List) text as String];
      final items = [for (final (i, text) in texts.indexed) _inspectionItem(_inspectionSerial * 10 + i, i + 1, text, 'pending')];
      for (final raw in (body['results'] as List?) ?? const []) {
        final result = raw as Map;
        final position = result['position'] as int;
        if (position < 1 || position > items.length) {
          throw ValidationFailure('Item $position is not on this checklist.', errors: {
            'results': ['Item $position is not on this checklist.'],
          });
        }
        final remark = result['remark'] as String?;
        if (result['result'] == 'fail' && (remark == null || remark.trim().isEmpty)) {
          throw const ValidationFailure('Say what is wrong with a failed item.', errors: {
            'results': ['Say what is wrong with a failed item.'],
          });
        }
        items[position - 1]['result'] = result['result'];
        items[position - 1]['remark'] = remark;
      }
      var status = 'in_progress';
      if (body['complete'] == true) {
        final pending = items.where((item) => item['result'] == 'pending').length;
        if (pending > 0) {
          throw ValidationFailure('$pending item(s) still have no result.', errors: {
            'items': ['$pending item(s) still have no result.'],
          });
        }
        status = items.any((item) => item['result'] == 'fail') ? 'failed' : 'passed';
      }
      final id = _inspectionSerial++;
      stored = {
        'id': id,
        'client_uuid': uuid,
        'project_id': projectId,
        'site_id': null,
        'checklist_template_id': template['id'],
        'title': (body['title'] as String?) ?? template['name'],
        'category': template['category'],
        'location': body['location'],
        'inspected_on': (body['inspected_on'] as String?) ?? '2026-11-09',
        'status': status,
        'completed_at': status == 'in_progress' ? null : '2026-11-09T10:00:00+05:30',
        'updated_at': '2026-11-09T10:00:00+05:30',
        'items': items,
      };
      inspectionPosts[uuid] = stored;
    }
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return stored;
  }

  // Machine logs ---------------------------------------------------------------

  /// Gives the signed-in user the plan feature and the permission to log a
  /// machine, as an engineer on a plan with `assets` has them.
  void grantMachineLogs() {
    final features = (user['organization'] as Map)['features'] as List;
    if (!features.contains('assets')) features.add('assets');
    grant('asset.log');
  }

  Row _machine(int id, String name, String category, String? meter, int? projectId, {String? registration, String status = 'active'}) => {
    'id': id,
    'code': 'AST-${id.toString().padLeft(4, '0')}',
    'name': name,
    'category': category,
    'ownership': 'owned',
    'registration_no': registration,
    'meter_unit': meter,
    'project_id': projectId,
    'site_id': null,
    'status': status,
    'updated_at': '2026-11-01T09:00:00+05:30',
  };

  /// What `MachineLogPresenter::asset` sends: no rate, no hire term, no cost.
  late List<Row> machineAssetRows = [
    _machine(1, 'JCB 3DX', 'backhoe', 'hours', 1, registration: 'MH12 AB 1234'),
    _machine(2, 'Tipper', 'tipper', 'km', null),
    _machine(3, 'Hired Crane', 'crane', null, 2, status: 'under_repair'),
  ];

  Row _machineLogRow(int id, int assetId, String date, {String hours = '0.000', String km = '0.000', String breakdown = '0.000', String? operator, String? uuid}) => {
    'id': id,
    'client_uuid': uuid,
    'asset_id': assetId,
    'project_id': 1,
    'log_date': date,
    'opening_reading': null,
    'closing_reading': null,
    'hours': hours,
    'km': km,
    'breakdown_hours': breakdown,
    'operator': operator,
    'remarks': null,
    'billed': false,
    'created_at': '2026-11-08T18:00:00+05:30',
  };

  late List<Row> machineLogRows = [
    _machineLogRow(21, 1, '2026-11-08', hours: '8.500', operator: 'Ramesh'),
    _machineLogRow(22, 2, '2026-11-08', km: '42.500'),
    _machineLogRow(23, 3, '2026-11-07', breakdown: '4.000'),
  ];

  /// Logs posted from the phone, by `client_uuid`.
  final Map<String, Row> machineLogPosts = {};
  int machineLogPostCalls = 0;
  int machineAssetReads = 0;

  /// Machines per page, so a test can make the list run to a second page.
  int machineAssetsPerPage = 100;

  /// Every read of the machines or the logs throws this.
  ApiFailure? machineReadFailure;

  /// Only the list of logs throws this, so the machines still come.
  ApiFailure? machineLogListFailure;

  /// `asset id:date` pairs a hire bill covers, which the server refuses.
  final Set<String> machineHireBilled = {};
  void Function(Map<String, dynamic> body)? onMachineLogPost;
  var _machineLogSerial = 700;

  @override
  Future<Map<String, dynamic>> machineAssets({int page = 1}) async {
    _guard();
    machineAssetReads++;
    final failure = machineReadFailure;
    if (failure != null) throw failure;
    final from = (page - 1) * machineAssetsPerPage;
    return {
      'data': machineAssetRows.skip(from).take(machineAssetsPerPage).toList(),
      'current_page': page,
      'last_page': (machineAssetRows.length / machineAssetsPerPage).ceil().clamp(1, 1000),
      'total': machineAssetRows.length,
    };
  }

  @override
  Future<Map<String, dynamic>> machineLogs(int projectId, {int page = 1}) async {
    _guard();
    final failure = machineReadFailure ?? machineLogListFailure;
    if (failure != null) throw failure;
    final rows = [...machineLogRows, ...machineLogPosts.values].where((row) => row['project_id'] == projectId).toList()
      ..sort((a, b) {
        final byDay = (b['log_date'] as String).compareTo(a['log_date'] as String);
        return byDay != 0 ? byDay : (b['id'] as int).compareTo(a['id'] as int);
      });
    return {
      'data': rows.take(50).toList(),
      'current_page': 1,
      'last_page': rows.length > 50 ? 2 : 1,
      'total': rows.length,
    };
  }

  @override
  Future<Map<String, dynamic>> postMachineLog(Map<String, dynamic> body) async {
    _guard();
    machineLogPostCalls++;
    onMachineLogPost?.call(body);
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    final uuid = body['client_uuid'] as String?;
    var stored = uuid == null ? null : machineLogPosts[uuid];
    if (stored == null) {
      // PlantLogService::record, in the order the server checks.
      ValidationFailure refuse(String field, String message) => ValidationFailure(message, errors: {field: [message]});
      final asset = machineAssetRows.where((row) => row['id'] == body['asset_id']).firstOrNull;
      if (asset == null) throw refuse('asset_id', 'The selected asset id is invalid.');
      final date = body['log_date'] as String;
      if (date.compareTo('2026-11-09') > 0) throw refuse('log_date', 'The log date field must be a date before or equal to today.');
      if (machineHireBilled.contains('${asset['id']}:$date')) {
        throw refuse('log_date', 'A hire bill already covers this date. Cancel the bill to add entries.');
      }
      Decimal? number(Object? raw) => raw == null || '$raw'.trim().isEmpty ? null : Decimal.parse('$raw');
      final opening = number(body['opening_reading']);
      final closing = number(body['closing_reading']);
      final both = opening != null && closing != null;
      if (both && closing < opening) throw refuse('closing_reading', 'The closing reading cannot be lower than the opening reading.');
      final fromMeter = both ? closing - opening : null;
      final hours = number(body['hours']) ?? (asset['meter_unit'] == 'hours' ? fromMeter : null);
      final km = number(body['km']) ?? (asset['meter_unit'] == 'km' ? fromMeter : null);
      final lost = number(body['breakdown_hours']);
      if (hours == null && km == null && lost == null) {
        throw refuse('hours', 'Enter the hours or km worked, or the hours lost to breakdown.');
      }
      var already = Decimal.zero;
      for (final row in [...machineLogRows, ...machineLogPosts.values]) {
        if (row['asset_id'] == asset['id'] && row['log_date'] == date) already += Decimal.parse(row['hours'] as String);
      }
      if (already + (hours ?? Decimal.zero) > Decimal.fromInt(24)) {
        throw refuse('hours', 'That would be more than 24 hours for this machine on one day.');
      }
      String three(Decimal? value) => (value ?? Decimal.zero).toStringAsFixed(3);
      final id = _machineLogSerial++;
      stored = {
        'id': id,
        'client_uuid': uuid,
        'asset_id': asset['id'],
        'project_id': body['project_id'] ?? asset['project_id'],
        'log_date': date,
        'opening_reading': opening == null ? null : three(opening),
        'closing_reading': closing == null ? null : three(closing),
        'hours': three(hours),
        'km': three(km),
        'breakdown_hours': three(lost),
        'operator': body['operator'],
        'remarks': body['remarks'],
        'billed': false,
        'created_at': '2026-11-09T10:00:00+05:30',
      };
      machineLogPosts[uuid ?? 'no-uuid-$id'] = stored;
    }
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return stored;
  }

  // Price watch ---------------------------------------------------------------

  /// Gives the signed-in user the plan feature and the money permissions of an
  /// owner, so the recorded prices are open to them.
  void grantPriceWatch({bool overview = true}) {
    final features = (user['organization'] as Map)['features'] as List;
    if (!features.contains('price_watch')) features.add('price_watch');
    grant('money.view');
    if (overview) grant('money.overview');
  }

  Row _recorded(int id, String rate, String on, String unit, {String? source = 'Dealer quote', String? by}) => {
    'id': id,
    'rate': rate,
    'unit': unit,
    'priced_on': on,
    'source': source,
    'source_kind': 'manual',
    'stale': false,
    'noted_by': ?by,
  };

  /// Every recorded price, newest first, by watched material id.
  late Map<int, List<Row>> priceLists = {
    1: [
      _recorded(3, '410.00', '2026-11-08', 'bag', by: 'Anil Kulkarni'),
      _recorded(2, '392.00', '2026-10-20', 'bag', source: 'Dealer enquiry'),
      _recorded(1, '385.00', '2026-10-01', 'bag'),
    ],
    2: [
      _recorded(5, '63.00', '2026-09-20', 'kg', source: 'Mill list'),
      _recorded(4, '61.50', '2026-08-15', 'kg', source: 'Mill list'),
    ],
    3: [_recorded(6, '5400.00', '2026-11-05', 'brass', source: 'Local supplier')],
    4: [],
  };

  static const Map<int, (String, String, String)> _watched = {
    1: ('cement', 'Cement', 'bag'),
    2: ('steel', 'Steel', 'kg'),
    3: ('sand', 'Sand', 'brass'),
    4: ('bricks', 'Bricks', 'nos'),
  };

  /// What the server says is today, for the prices' own `stale` flags.
  String priceToday = '2026-11-09';
  int priceStaleDays = 30;

  bool _stale(String on) {
    final limit = DateTime.parse(on).add(Duration(days: priceStaleDays));
    return limit.isBefore(DateTime.parse(priceToday));
  }

  Row _price(Row? price) => price == null ? {} : {...price, 'stale': _stale(price['priced_on'] as String)};

  Row _boardRow(int id) {
    final (key, name, unit) = _watched[id]!;
    final prices = priceLists[id] ?? [];
    final current = prices.isEmpty ? null : prices.first;
    final previous = prices.length < 2 ? null : prices[1];
    String? change;
    if (current != null && previous != null) {
      final now = Decimal.parse(current['rate'] as String);
      final before = Decimal.parse(previous['rate'] as String);
      change = ((now - before) * Decimal.fromInt(100) / before).toDecimal(scaleOnInfinitePrecision: 6).round(scale: 2).toStringAsFixed(2);
    }
    return {
      'id': id,
      'key': key,
      'name': name,
      'unit': unit,
      'material_id': null,
      'is_active': true,
      'current': current == null ? null : _price(current),
      'previous': previous == null ? null : _price(previous),
      'change_pct': change,
      'trend': [
        for (final p in prices.take(8).toList().reversed) {'rate': p['rate'], 'priced_on': p['priced_on']},
      ],
      'count': prices.length,
    };
  }

  Map<String, dynamic> get _priceMeta => {'as_of': priceToday, 'stale_after_days': priceStaleDays, 'live_feed': false};

  /// Scripted failures for the three reads, e.g. a 403.
  ApiFailure? priceBoardFailure;
  ApiFailure? priceMaterialFailure;
  ApiFailure? priceProjectFailure;

  /// How many times each read was asked, including the ones refused.
  int priceBoardCalls = 0;
  int priceMaterialCalls = 0;
  int priceProjectCalls = 0;

  void _priceGuard(String permission) {
    _guard();
    final can = (user['can'] as Map)[permission] == true;
    final features = (user['organization'] as Map)['features'] as List;
    if (!can || !features.contains('price_watch')) {
      throw const ServerFailure('This action is unauthorized.', statusCode: 403);
    }
  }

  @override
  Future<Map<String, dynamic>> priceBoard() async {
    priceBoardCalls++;
    _priceGuard('money.view');
    final failure = priceBoardFailure;
    if (failure != null) throw failure;
    return {'data': [for (final id in _watched.keys) _boardRow(id)], 'meta': _priceMeta};
  }

  @override
  Future<Map<String, dynamic>> priceMaterial(int materialId) async {
    priceMaterialCalls++;
    _priceGuard('money.view');
    final failure = priceMaterialFailure;
    if (failure != null) throw failure;
    if (!_watched.containsKey(materialId)) throw const ServerFailure('Not found', statusCode: 404);
    return {
      'data': {..._boardRow(materialId), 'prices': [for (final p in priceLists[materialId] ?? []) _price(p)]},
      'meta': _priceMeta,
    };
  }

  /// The project rows are cement and steel with a BOQ rate; sand has none; bricks have no price.
  List<Row> priceProjectRows() => [
    {
      ..._boardRow(1),
      'boq_rate': '380.00',
      'boq_qty': '1200.000',
      'difference': '30.00',
      'above_boq_pct': '7.89',
      'need': '1200.000',
      'need_basis': 'typed',
      'received': '0.000',
      'remaining': '1200.000',
      'impact': '36000.00',
      'stale': false,
      'why': null,
    },
    {
      ..._boardRow(2),
      'boq_rate': '65.00',
      'boq_qty': '8000.000',
      'difference': '-2.00',
      'above_boq_pct': '-3.08',
      'need': '8000.000',
      'need_basis': 'norms',
      'received': '1000.000',
      'remaining': '7000.000',
      'impact': '-14000.00',
      'stale': true,
      'why': null,
    },
    {
      ..._boardRow(3),
      'boq_rate': null,
      'boq_qty': null,
      'difference': null,
      'above_boq_pct': null,
      'need': null,
      'need_basis': null,
      'received': '0.000',
      'remaining': null,
      'impact': null,
      'stale': false,
      'why': 'no_boq_rate',
    },
    {
      ..._boardRow(4),
      'boq_rate': null,
      'boq_qty': null,
      'difference': null,
      'above_boq_pct': null,
      'need': null,
      'need_basis': null,
      'received': '0.000',
      'remaining': null,
      'impact': null,
      'stale': false,
      'why': 'no_price',
    },
  ];

  Row priceSummary = {
    'impact': '22000.00',
    'fresh_impact': '36000.00',
    'impact_pct': '0.14',
    'fresh_impact_pct': '0.24',
    'basis': 'budget',
    'budget': '16000000.00',
    'ceiling': '16800000.00',
    'margin_pct': '5.00',
    'boq': '15254250.00',
    'projected': '15276250.00',
    'beyond_margin': false,
    'materials': 4,
    'priced': 3,
    'with_boq_rate': 2,
    'with_impact': 2,
    'stale': 1,
    'rising': 1,
    'rise_pct': '5.00',
    'impact_alert_pct': '1.00',
    'stale_days': 30,
  };

  @override
  Future<Map<String, dynamic>> priceProject(int projectId) async {
    priceProjectCalls++;
    _priceGuard('money.overview');
    final failure = priceProjectFailure;
    if (failure != null) throw failure;
    return {'data': priceProjectRows(), 'summary': priceSummary, 'meta': _priceMeta};
  }

  // Contractor portal --------------------------------------------------------

  /// Turns the signed-in user into a contractor login, as `GET /me` describes
  /// one: no site permission at all, and the portal alone.
  void becomeContractor({int partyId = 2, String name = 'Shree Formwork Works'}) {
    user = {
      'id': 7,
      'name': 'Ramesh Pawar',
      'email': 'contractor@demo.test',
      'role': 'contractor',
      'role_label': 'Contractor',
      'kind': 'contractor',
      'contractor': {'party_id': partyId, 'name': name},
      'locale': 'en',
      'organization': {
        'id': 1,
        'name': 'Sahyadri Infra Pvt Ltd',
        'features': ['site', 'measurement', 'contractor_portal', 'price_watch'],
      },
      'can': {
        for (final key in (user['can'] as Map).keys) key: false,
        'contractor_portal.view': true,
      },
    };
  }

  Row portalOverviewBody = {
    'contractor': {'party_id': 2, 'name': 'Shree Formwork Works'},
    'summary': {
      'contract_value': '2133000.00',
      'earned': '1000000.00',
      'paid': '1200000.00',
      'advance': '100000.00',
      'balance_money': '933000.00',
      'work_pct': '46.88',
      'paid_pct': '56.26',
      'gap_pct': '9.38',
      'status': 'red',
      'label': 'Paid 56.26%, Work 46.88%',
      'balance_work_pct': '53.12',
      'balance_work_value': '1133000.00',
      'contracts': 2,
      'contracts_ahead': 1,
    },
    'contracts': [
      {
        'id': 1,
        'number': 'WO-KT-B-001',
        'title': 'Slab casting and shuttering',
        'contract_status': 'ACTIVE',
        'project_name': 'Kalyani Tower B',
        'project_code': 'KT-B',
        'start_date': '2026-09-01',
        'contract_value': '1333000.00',
        'earned': '1000000.00',
        'paid': '1200000.00',
        'advance': '100000.00',
        'balance_money': '133000.00',
        'work_pct': '75.02',
        'paid_pct': '90.02',
        'gap_pct': '15.00',
        'status': 'red',
        'label': 'Paid 90.02%, Work 75.02%',
        'balance_work_pct': '24.98',
        'balance_work_value': '333000.00',
        'deadline': '2026-11-19',
        'deadline_label': 'Before Lakshmi Puja',
        'days_left': 10,
        'time_state': 'on_track',
      },
      {
        'id': 5,
        'number': 'WO-MH-07-004',
        'title': 'Brick masonry',
        'contract_status': 'ACTIVE',
        'project_name': 'Mahalaxmi Heights',
        'project_code': 'MH-07',
        'start_date': null,
        'contract_value': '800000.00',
        'earned': '0.00',
        'paid': '0.00',
        'advance': '0.00',
        'balance_money': '800000.00',
        'work_pct': '0.00',
        'paid_pct': '0.00',
        'gap_pct': '0.00',
        'status': 'green',
        'label': 'Paid 0%, Work 0%',
        'balance_work_pct': '100.00',
        'balance_work_value': '800000.00',
        'deadline': '2026-11-01',
        'deadline_label': null,
        'days_left': -8,
        'time_state': 'overdue',
      },
    ],
  };

  Row _portalPayment(int id, String number, String paidOn, String amount, {bool reversal = false, bool advance = false}) => {
    'id': id,
    'number': number,
    'paid_on': paidOn,
    'amount': amount,
    'mode': 'bank',
    'mode_label': 'Bank',
    'paid_to': 'Shree Formwork Works',
    'reference': 'UTR-$id',
    'is_advance': advance,
    'is_reversal': reversal,
    'work_order_id': 1,
    'work_order_number': 'WO-KT-B-001',
    'work_order_title': 'Slab casting and shuttering',
    'project': 'Kalyani Tower B',
    'against': null,
  };

  late List<Row> portalPaymentRows = [
    _portalPayment(3, 'CP-2026-27-0003', '2026-11-05', '600000.00'),
    _portalPayment(2, 'CP-2026-27-0002', '2026-10-20', '500000.00'),
    _portalPayment(1, 'CP-2026-27-0001', '2026-09-10', '100000.00', advance: true),
  ];

  Row _portalBill(int id, String number, String status, {String? paidAt}) => {
    'id': id,
    'number': number,
    'is_final': false,
    'status': status,
    'work_order_id': 1,
    'work_order_number': 'WO-KT-B-001',
    'project': 'Kalyani Tower B',
    'period_from': '2026-10-01',
    'period_to': '2026-10-31',
    'gross_amount': '200000.00',
    'deduction_total': '10000.00',
    'gst_amount': '0.00',
    'retention_released': '0.00',
    'net_payable': '190000.00',
    'paid_amount': status == 'PAID' ? '190000.00' : '0.00',
    'paid_at': paidAt,
  };

  late List<Row> portalBillRows = [
    _portalBill(2, 'WO-KT-B-001/RA/2', 'APPROVED'),
    _portalBill(1, 'WO-KT-B-001/RA/1', 'PAID', paidAt: '2026-10-15'),
  ];

  /// The contract details, keyed by work order. Built from the overview rows
  /// when a test has not set its own.
  Map<int, Row> portalContractOverride = {};
  bool portalContractsMore = false;

  /// A scripted failure for the next portal reads, e.g. a 403.
  ApiFailure? portalFailure;
  int portalReads = 0;
  int portalContractReads = 0;
  int portalBillReads = 0;

  void _portalGuard() {
    _guard();
    final failure = portalFailure;
    if (failure != null) throw failure;
    portalReads++;
  }

  @override
  Future<Map<String, dynamic>> portalOverview() async {
    _portalGuard();
    return portalOverviewBody;
  }

  @override
  Future<Map<String, dynamic>> portalContract(int workOrderId) async {
    _portalGuard();
    portalContractReads++;
    final custom = portalContractOverride[workOrderId];
    if (custom != null) return custom;
    final rows = (portalOverviewBody['contracts'] as List).cast<Row>();
    final row = rows.where((r) => r['id'] == workOrderId).firstOrNull;
    if (row == null) throw const ServerFailure('Not found', statusCode: 404);
    return {
      ...row,
      'lines': [
        {
          'id': 11,
          'boq_item_id': 3,
          'code': '2.1',
          'description': 'RCC M25 in slabs and beams including shuttering',
          'unit': 'cum',
          'qty': '620.000',
          'rate': '2150.00',
          'value': '1333000.00',
          'done_qty': '465.250',
          'done_value': '1000287.50',
          'balance_qty': '154.750',
          'work_pct': '75.04',
        },
      ],
      'parts': [
        {
          'id': 1,
          'title': 'Plinth complete',
          'amount': '500000.00',
          'paid': '500000.00',
          'balance': '0.00',
          'state': 'paid',
          'done': true,
          'done_on': '2026-10-02',
        },
        {
          'id': 2,
          'title': 'First slab',
          'amount': '400000.00',
          'paid': '100000.00',
          'balance': '300000.00',
          'state': 'part_paid',
          'done': false,
          'done_on': null,
        },
      ],
      'payments': portalPaymentRows.where((p) => p['work_order_id'] == workOrderId).toList(),
      'bills': portalBillRows.where((b) => b['work_order_id'] == workOrderId).toList(),
    };
  }

  @override
  Future<List<Map<String, dynamic>>> portalPayments() async {
    _portalGuard();
    return portalPaymentRows;
  }

  @override
  Future<List<Map<String, dynamic>>> portalBills() async {
    _portalGuard();
    return portalBillRows;
  }

  @override
  Future<Map<String, dynamic>> portalBill(int billId) async {
    _portalGuard();
    portalBillReads++;
    final row = portalBillRows.where((b) => b['id'] == billId).firstOrNull;
    if (row == null) throw const ServerFailure('Not found', statusCode: 404);
    return {
      ...row,
      'items': [
        {
          'code': '2.1',
          'description': 'RCC M25 in slabs and beams including shuttering',
          'unit': 'cum',
          'rate': '4000.00',
          'prev_qty': '0.000',
          'current_qty': '50.000',
          'cumulative_qty': '50.000',
          'current_amount': '200000.00',
          'cumulative_amount': '200000.00',
        },
      ],
      'deductions': [
        {'label': 'Retention', 'pct': '5.00', 'amount': '10000.00'},
      ],
    };
  }

  // Site dashboard and alerts ----------------------------------------------

  Row siteDashboardBody = {
    'project': {'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B', 'status': 'active'},
    'today': '2026-11-09',
    'cards': [
      {
        'key': 'work',
        'title': 'Work done',
        'value': '35%',
        'note': '2 of 5 BOQ lines complete, 3 started',
        'tone': 'ok',
        'meters': [
          {'label': 'Work done', 'percent': '35.00', 'tone': 'ok'},
        ],
        'rows': <Row>[],
        'url': 'http://example.test/projects/1/boq',
        'money': false,
      },
      {
        'key': 'pending',
        'title': 'Pending items',
        'value': '9',
        'note': '0 pending work remarks, 9 open issues',
        'tone': 'warn',
        'meters': <Row>[],
        'rows': [
          {'label': 'Open issues', 'value': '9', 'tone': 'warn'},
        ],
        'url': null,
        'money': false,
      },
      {
        'key': 'alerts',
        'title': 'Open alerts',
        'value': '2',
        'note': '1 critical',
        'tone': 'bad',
        'meters': <Row>[],
        'rows': <Row>[],
        'url': null,
        'money': false,
      },
    ],
  };
  List<Row> siteAlertRows = [
    {
      'id': 7,
      'project_id': 1,
      'project_name': null,
      'type': 'schedule.behind',
      'reference': null,
      'severity': 'critical',
      'status': 'open',
      'title': 'Behind schedule',
      'message': '50% of the time is used but 35% of the work is done, 15% behind.',
      'data': {'lag_pct': '15.00'},
      'url': 'http://example.test/projects/1/alerts',
      'first_seen_at': '2026-11-02T09:00:00+05:30',
      'last_seen_at': '2026-11-09T09:00:00+05:30',
      'resolved_at': null,
    },
    {
      'id': 8,
      'project_id': 1,
      'project_name': null,
      'type': 'schedule.deadline',
      'reference': null,
      'severity': 'info',
      'status': 'open',
      'title': 'Deadline ahead',
      'message': 'The finish date is 30 days away.',
      'data': <String, dynamic>{},
      'url': null,
      'first_seen_at': '2026-11-08T09:00:00+05:30',
      'last_seen_at': '2026-11-09T09:00:00+05:30',
      'resolved_at': null,
    },
  ];
  int? siteAlertsTotal;
  ApiFailure? siteDashboardFailure;
  ApiFailure? siteAlertsFailure;
  int siteDashboardReads = 0;
  int siteAlertsReads = 0;

  @override
  Future<Map<String, dynamic>> siteDashboard(int projectId) async {
    _guard();
    siteDashboardReads++;
    final failure = siteDashboardFailure;
    if (failure != null) throw failure;
    return siteDashboardBody;
  }

  @override
  Future<Map<String, dynamic>> siteAlerts(int projectId) async {
    _guard();
    siteAlertsReads++;
    final failure = siteAlertsFailure;
    if (failure != null) throw failure;
    return {
      'data': siteAlertRows,
      'current_page': 1,
      'last_page': 1,
      'per_page': 30,
      'total': siteAlertsTotal ?? siteAlertRows.length,
    };
  }

  /// What a scan answers, and what it does to the alerts on the server.
  Row siteScanBody = {'new': 1, 'open': 3, 'resolved': 0};
  ApiFailure? siteScanFailure;
  int siteScanCalls = 0;

  @override
  Future<Map<String, dynamic>> scanSiteAlerts(int projectId) async {
    _guard();
    siteScanCalls++;
    final failure = siteScanFailure;
    if (failure != null) throw failure;
    return siteScanBody;
  }

  // Material control -------------------------------------------------------

  Row materialCheckBody = {
    'rows': [
      {
        'material_id': 5,
        'material': 'Cement',
        'unit': 'bag',
        'estimate': '100.000',
        'received': '200.000',
        'used': '120.000',
        'should_be_in_stock': '80.000',
        'stock_on_site': '75.000',
        'unaccounted': '5.000',
        'short_delivered': '0.000',
        'last_counted': '2026-11-01',
        'wastage_pct': '2.00',
        'flags': [
          {'type': 'unaccounted', 'message': '5 bag unaccounted: received, but neither used nor in stock. Where did it go?', 'open': true},
        ],
      },
    ],
    'open_flags': 1,
  };
  List<Row> materialChallanRows = [
    {'id': 1, 'material_id': 5, 'qty': '200.000', 'unit_id': null, 'supplier': 'Gupta Traders', 'challan_no': 'C-1', 'challan_date': '2026-11-01'},
  ];
  List<Row> materialUseRows = [
    {
      'id': 2,
      'issue_date': '2026-11-02',
      'items': [
        {'material_id': 5, 'qty': '120.000', 'unit_id': null, 'activity': 'Slab 1'},
      ],
    },
  ];
  List<Row> materialCountRows = [
    {'id': 3, 'material_id': 5, 'physical_qty': '75.000', 'variance_qty': '-5.000', 'unit_id': null, 'check_date': '2026-11-03'},
  ];
  List<Row> materialSupplierRows = [
    {'party_id': 8, 'name': 'Gupta Traders', 'warn': true, 'warning': 'Delivered short 3 times.'},
    {'party_id': 9, 'name': 'Shree Traders', 'warn': false, 'warning': null},
  ];
  Row procurementSnapshotBody = {
    'stores': [
      {'id': 1, 'name': 'Site store'},
    ],
    'units': [
      {'id': 10, 'code': 'bag'},
      {'id': 11, 'code': 'MT'},
    ],
    'materials': [
      {
        'id': 5,
        'code': 'CEM',
        'name': 'Cement',
        'base_unit_id': 10,
        'conversions': [
          {'unit_id': 11, 'factor': '20.000000'},
        ],
      },
      {'id': 6, 'code': 'SND', 'name': 'Sand', 'base_unit_id': 11, 'conversions': <Row>[]},
    ],
    // What `ProcurementSyncService::snapshot` adds. The rows carry a rate and tax figures
    // here on purpose: the real server's order rows can too, and the app must drop them.
    'stock': <Row>[
      {'store_id': 1, 'material_id': 5, 'balance': '75.000'},
      {'store_id': 1, 'material_id': 6, 'balance': '3.000'},
    ],
    'purchase_orders': <Row>[
      {
        'id': 70,
        'number': 'PO-101',
        'status': 'ISSUED',
        'vendor': 'Gupta Traders',
        'store_id': 1,
        'subtotal': '40000.00',
        'total': '47200.00',
        'items': <Row>[
          {'id': 701, 'material_id': 5, 'qty': '100.000', 'unit_id': 10, 'base_qty': '100.000', 'received_base_qty': '0.000', 'remaining_base_qty': '100.000', 'rate': '400.00', 'amount': '40000.00', 'gst_pct': '18.00'},
          {'id': 702, 'material_id': 6, 'qty': '10.000', 'unit_id': 11, 'base_qty': '10.000', 'received_base_qty': '0.000', 'remaining_base_qty': '10.000', 'rate': '1500.00', 'amount': '15000.00'},
        ],
      },
    ],
  };
  ApiFailure? snapshotFailure;
  final Map<String, Row> challanPosts = {};
  final Map<String, Row> materialUsePosts = {};
  final Map<String, Row> stockCountPosts = {};
  final List<String?> challanFiles = [];

  @override
  Future<Map<String, dynamic>> materialCheck(int projectId) async {
    _guard();
    return materialCheckBody;
  }

  @override
  Future<List<Map<String, dynamic>>> materialChallans(int projectId) async {
    _guard();
    return materialChallanRows;
  }

  @override
  Future<List<Map<String, dynamic>>> materialConsumption(int projectId) async {
    _guard();
    return materialUseRows;
  }

  @override
  Future<List<Map<String, dynamic>>> materialStockChecks(int projectId) async {
    _guard();
    return materialCountRows;
  }

  @override
  Future<List<Map<String, dynamic>>> materialSuppliers() async {
    _guard();
    return materialSupplierRows;
  }

  @override
  Future<Map<String, dynamic>> procurementSnapshot(int projectId) async {
    _guard();
    final failure = snapshotFailure;
    if (failure != null) throw failure;
    return procurementSnapshotBody;
  }

  @override
  Future<Map<String, dynamic>> postMaterialChallan(
    int projectId,
    Map<String, dynamic> body, {
    String? filePath,
  }) async {
    final row = _storeProgress(challanPosts, body);
    challanFiles.add(filePath);
    return row;
  }

  @override
  Future<Map<String, dynamic>> postMaterialUse(int projectId, Map<String, dynamic> body) async =>
      _storeProgress(materialUsePosts, body);

  @override
  Future<Map<String, dynamic>> postStockCount(int projectId, Map<String, dynamic> body) async =>
      _storeProgress(stockCountPosts, body);

  // Attendance by photo --------------------------------------------------------------

  /// What `GET /attendance/selfie-consent` answers.
  Row selfieConsentBody = {'version': 'selfie-v1', 'text': 'The worker in this photo agrees to it being taken, with this location, only to record attendance at this site.'};
  int selfieConsentReads = 0;

  /// The sites' map position and fence, which the server holds and does not send: id to [lat, lng, radius in metres].
  final Map<int, List<num>> siteFences = {1: [18.5204, 73.8567, 200]};

  /// Every selfie stored, by `client_uuid`, and every request that reached the server.
  final Map<String, Row> selfiePosts = {};
  final List<Map<String, String>> selfieForms = [];
  final List<String> selfieFiles = [];

  int _metres(double fromLat, double fromLng, double toLat, double toLng) {
    double rad(double d) => d * pi / 180;
    final dLat = rad(toLat - fromLat);
    final dLng = rad(toLng - fromLng);
    final a = sin(dLat / 2) * sin(dLat / 2) + cos(rad(fromLat)) * cos(rad(toLat)) * sin(dLng / 2) * sin(dLng / 2);
    return (6371000 * 2 * atan2(sqrt(a), sqrt(1 - a))).round();
  }

  @override
  Future<Map<String, dynamic>> attendanceSelfieConsent() async {
    _guard();
    selfieConsentReads++;
    return selfieConsentBody;
  }

  /// `SelfieAttendanceService::record`, in the order the server checks.
  Future<Map<String, dynamic>> _postSelfie(int projectId, String uuid, String filePath, Map<String, String> fields) async {
    selfieForms.add(fields);
    selfieFiles.add(filePath);
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    ValidationFailure refuse(String field, String message) => ValidationFailure(message, errors: {field: [message]});
    var stored = selfiePosts[uuid];
    if (stored == null) {
      if (fields['consent'] != '1') throw refuse('consent', 'The consent must be accepted.');
      final siteId = int.parse(fields['site_id'] ?? '0');
      final fence = siteFences[siteId];
      final site = sites.where((s) => s['id'] == siteId && s['project_id'] == projectId).firstOrNull;
      if (site == null) throw refuse('site_id', 'The selected site id is invalid.');
      final workerId = int.parse(fields['worker_id'] ?? '0');
      final worker = workers.where((w) => w['id'] == workerId).firstOrNull;
      if (worker == null) throw refuse('worker_id', 'The selected worker id is invalid.');
      final lat = double.parse(fields['lat']!);
      final lng = double.parse(fields['lng']!);
      final accuracy = fields['accuracy_m'] == null ? 0.0 : double.parse(fields['accuracy_m']!);
      final at = DateTime.parse(fields['captured_at']!);
      if (at.isAfter(_now.add(const Duration(minutes: 10)))) throw refuse('captured_at', 'The captured at field must be a date before or equal to +10 minutes.');
      if (at.isBefore(_now.subtract(const Duration(days: 7)))) throw refuse('captured_at', 'The captured at field must be a date after or equal to -7 days.');
      if (fence == null) throw refuse('site_id', 'This site has no map location yet, so a selfie cannot be checked against it.');
      final radius = fence[2].toInt();
      if (accuracy > radius) throw refuse('lat', 'The location is too rough to check (${accuracy.round()} m). Move to open sky and try again.');
      final distance = _metres(lat, lng, fence[0].toDouble(), fence[1].toDouble());
      if (distance > radius) {
        throw refuse('lat', 'This location is $distance m from ${site['name']}. Attendance needs to be within $radius m.');
      }
      final local = at.toUtc().add(const Duration(hours: 5, minutes: 30));
      String two(int v) => v.toString().padLeft(2, '0');
      final day = '${local.year}-${two(local.month)}-${two(local.day)}';
      if (selfiePosts.values.any((s) => s['worker_id'] == workerId && s['attendance_date'] == day)) {
        throw refuse('worker_id', 'This worker already has a selfie for that day. Delete it first to take another.');
      }
      Row? mark = attendance.where((a) => a['project_id'] == projectId && a['worker_id'] == workerId && a['attendance_date'] == day).firstOrNull;
      if (mark == null) {
        mark = {
          'id': _nextId++,
          'client_uuid': uuid,
          'project_id': projectId,
          'worker_id': workerId,
          'attendance_date': day,
          'status': 'present',
          'ot_hours': '0.0',
          'marked_by': user['id'],
          'client_updated_at': null,
          'updated_at': _stampNow(),
        };
        attendance.add(mark);
      } else if (mark['status'] == 'absent') {
        mark['status'] = 'present';
      }
      stored = {
        'id': _nextId++,
        'client_uuid': uuid,
        'worker_id': workerId,
        'site_id': siteId,
        'attendance_date': day,
        'distance_m': distance,
        'radius_m': radius,
        'consent_version': selfieConsentBody['version'],
      };
      selfiePosts[uuid] = stored;
    }
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return stored;
  }

  // Attendance by QR card ---------------------------------------------------------

  /// Moves the server's clock, and so the day it dates a scan by.
  void advanceServerTime(Duration by) => _now = _now.add(by);

  /// Every body that reached `POST /attendance/scan`, replays included.
  final List<Row> scanBodies = [];
  final Map<String, Row> scanReplies = {};

  /// Codes of cards a newer card has replaced.
  final Set<String> replacedCards = {};

  /// Workers the server says are on another project today: id to that project's name.
  final Map<int, String> presentElsewhere = {};

  /// A card the way `WorkerCardService::code` prints it. The fake knows the shape only.
  String cardFor(int workerId, {int version = 1}) =>
      'N360W-$workerId-$version-${workerId.toString().padLeft(2, '0')}${'a' * 18}';

  /// `QrAttendanceService::scan`, in the order the server checks, dated by the server's day.
  @override
  Future<Map<String, dynamic>> postAttendanceScan(int projectId, Map<String, dynamic> body) async {
    _guard();
    scanBodies.add(body);
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    ValidationFailure refuse(String message) => ValidationFailure(message, errors: {'code': [message]});
    final code = (body['code'] as String? ?? '').trim();
    final match = RegExp(r'^N360W-(\d+)-(\d+)-([a-f0-9]{20})$').firstMatch(code);
    final worker = match == null ? null : workers.where((w) => w['id'] == int.parse(match.group(1)!)).firstOrNull;
    if (worker == null) throw refuse('This is not a worker card from your company.');
    if (replacedCards.contains(code)) throw refuse('This card was replaced by a newer one. Use the new card.');
    if (worker['is_active'] != true) throw refuse('${worker['name']} is not an active worker.');

    final uuid = body['client_uuid'] as String?;
    final replay = uuid == null ? null : scanReplies[uuid];
    if (replay != null) {
      if (loseNextProgressReply) {
        loseNextProgressReply = false;
        throw const OfflineFailure();
      }
      return {...replay, 'already_marked': true};
    }
    final today = _serverToday();
    final elsewhere = presentElsewhere[worker['id']];
    if (elsewhere != null) throw refuse('${worker['name']} is already marked present today at $elsewhere.');

    Row? mark;
    for (final candidate in attendance) {
      if (candidate['project_id'] == projectId && candidate['worker_id'] == worker['id'] && candidate['attendance_date'] == today) {
        mark = candidate;
      }
    }
    var already = true;
    if (mark == null) {
      mark = {
        'id': _nextId++,
        'client_uuid': uuid,
        'project_id': projectId,
        'worker_id': worker['id'],
        'attendance_date': today,
        'status': 'present',
        'ot_hours': '0.0',
        'marked_by': user['id'],
        'client_updated_at': null,
        'updated_at': _stampNow(),
      };
      attendance.add(mark);
      already = false;
    } else if (mark['status'] == 'absent') {
      mark
        ..['status'] = 'present'
        ..['ot_hours'] = '0.0'
        ..['updated_at'] = _stampNow();
      already = false;
    }
    final reply = {'already_marked': already, 'worker': {'id': worker['id'], 'name': worker['name']}, 'attendance': mark};
    if (uuid != null) scanReplies[uuid] = reply;
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return reply;
  }

  // Goods receipts and store issues -------------------------------------------

  /// Receipts already on the server, newest first, as `GET /grns` lists them.
  List<Row> grnRows = [];
  final Map<String, Row> grnPosts = {};
  final Map<String, Row> issuePosts = {};
  int grnPostCalls = 0;
  int issuePostCalls = 0;
  int receiptReads = 0;

  /// Every body that reached the server, replays included.
  final List<Row> grnBodies = [];
  final List<Row> issueBodies = [];

  /// Only the list of receipts throws this, so the rest of the page still comes.
  ApiFailure? receiptListFailure;
  var _grnSerial = 300;
  var _issueSerial = 400;

  /// Gives the signed-in user what a store keeper has on a plan with `procurement`.
  void grantStock() {
    final features = (user['organization'] as Map)['features'] as List;
    if (!features.contains('procurement')) features.add('procurement');
    grant('procurement.stock');
  }

  List<Row> get _orderRows => (procurementSnapshotBody['purchase_orders'] as List).cast<Row>();

  List<Row> get _stockRows => (procurementSnapshotBody['stock'] as List).cast<Row>();

  Decimal _baseOf(Object? materialId, Object? qty, Object? unitId) {
    final material = (procurementSnapshotBody['materials'] as List).cast<Row>().firstWhere((m) => m['id'] == materialId);
    final value = Decimal.parse('$qty');
    if (unitId == null || unitId == material['base_unit_id']) return value;
    final conversion = (material['conversions'] as List).cast<Row>().where((c) => c['unit_id'] == unitId).firstOrNull;
    if (conversion == null) throw ValidationFailure('No conversion.', errors: {'items': ['No conversion.']});
    return value * Decimal.parse('${conversion['factor']}');
  }

  String _q3(Decimal value) => value.toStringAsFixed(3);

  @override
  Future<List<Map<String, dynamic>>> procurementReceipts(int projectId) async {
    _guard();
    receiptReads++;
    final failure = receiptListFailure;
    if (failure != null) throw failure;
    return [...grnPosts.values.toList().reversed, ...grnRows];
  }

  /// `GrnService` and `ProcurementApiController::storeGrn`, in the order the server checks.
  @override
  Future<Map<String, dynamic>> postGoodsReceipt(int projectId, Map<String, dynamic> body) async {
    _guard();
    grnPostCalls++;
    grnBodies.add(body);
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    final uuid = body['client_uuid'] as String;
    // A replay comes back first, whatever the order has become since.
    var stored = grnPosts[uuid];
    if (stored == null) {
      ValidationFailure refuse(String field, String message) => ValidationFailure(message, errors: {field: [message]});
      final order = _orderRows.where((o) => o['id'] == body['purchase_order_id']).firstOrNull;
      if (order == null) throw refuse('purchase_order_id', 'The selected purchase order id is invalid.');
      final date = body['grn_date'] as String;
      if (date.compareTo('2026-11-09') > 0) throw refuse('grn_date', 'The grn date field must be a date before or equal to today.');
      final items = (body['items'] as List?)?.cast<Row>() ?? const <Row>[];
      if (items.isEmpty) throw refuse('items', 'The items field is required.');
      final lines = (order['items'] as List).cast<Row>();
      final running = {for (final line in lines) line['id'] as int: Decimal.parse('${line['received_base_qty']}')};
      final prepared = <Row>[];
      for (final (index, input) in items.indexed) {
        final qty = Decimal.parse('${input['qty']}');
        if (qty <= Decimal.zero) throw refuse('items.$index.qty', 'The items.$index.qty field must be greater than 0.');
        if (qty.scale > 3) throw refuse('items.$index.qty', 'The items.$index.qty field must have 0-3 decimal places.');
        final line = lines.where((l) => l['id'] == input['purchase_order_item_id']).firstOrNull;
        if (line == null) throw refuse('items', 'A line on this receipt is not on the purchase order.');
        final unitId = input['unit_id'] ?? line['unit_id'];
        final base = _baseOf(line['material_id'], input['qty'], unitId);
        final total = running[line['id']]! + base;
        if (total > Decimal.parse('${line['base_qty']}')) {
          final due = Decimal.parse('${line['base_qty']}') - running[line['id']]!;
          throw refuse('over_receipt', 'Only ${_q3(due)} of Material ${line['material_id']} is still due on this purchase order.');
        }
        running[line['id'] as int] = total;
        prepared.add({
          'id': _grnSerial * 10 + index,
          'purchase_order_item_id': line['id'],
          'material_id': line['material_id'],
          'qty': _q3(qty),
          'unit_id': unitId,
          'base_qty': _q3(base),
          'rejected_qty': _q3(Decimal.parse('${input['rejected_qty'] ?? 0}')),
        });
      }
      // Posted: the order's lines and the store move with it, as the ledger does.
      for (final line in lines) {
        final total = running[line['id']]!;
        line['received_base_qty'] = _q3(total);
        line['remaining_base_qty'] = _q3(Decimal.parse('${line['base_qty']}') - total);
      }
      final storeId = body['store_id'] ?? order['store_id'];
      for (final item in prepared) {
        final stock = _stockRows.where((s) => s['store_id'] == storeId && s['material_id'] == item['material_id']).firstOrNull;
        if (stock == null) {
          _stockRows.add({'store_id': storeId, 'material_id': item['material_id'], 'balance': item['base_qty']});
        } else {
          stock['balance'] = _q3(Decimal.parse('${stock['balance']}') + Decimal.parse('${item['base_qty']}'));
        }
      }
      if (lines.every((l) => Decimal.parse('${l['remaining_base_qty']}') <= Decimal.zero)) {
        order['status'] = 'RECEIVED';
        _orderRows.remove(order);
      } else {
        order['status'] = 'PARTIAL';
      }
      final id = _grnSerial++;
      stored = {
        'id': id,
        'number': 'GRN-${id.toString().padLeft(4, '0')}',
        'client_uuid': uuid,
        'purchase_order_id': order['id'],
        'store_id': storeId,
        'grn_date': date,
        'challan_no': body['challan_no'],
        'status': 'POSTED',
        'items': prepared,
      };
      grnPosts[uuid] = stored;
    }
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return stored;
  }

  /// `MaterialIssueService` and `ProcurementApiController::storeIssue`.
  @override
  Future<Map<String, dynamic>> postMaterialIssue(int projectId, Map<String, dynamic> body) async {
    _guard();
    issuePostCalls++;
    issueBodies.add(body);
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    final uuid = body['client_uuid'] as String;
    var stored = issuePosts[uuid];
    if (stored == null) {
      ValidationFailure refuse(String field, String message) => ValidationFailure(message, errors: {field: [message]});
      final stores = (procurementSnapshotBody['stores'] as List).cast<Row>();
      final store = stores.where((s) => s['id'] == body['store_id']).firstOrNull;
      if (store == null) throw refuse('store_id', 'The selected store id is invalid.');
      final date = body['issue_date'] as String;
      if (date.compareTo('2026-11-09') > 0) throw refuse('issue_date', 'The issue date field must be a date before or equal to today.');
      final items = (body['items'] as List?)?.cast<Row>() ?? const <Row>[];
      if (items.isEmpty) throw refuse('items', 'The items field is required.');
      final moves = <Row>[];
      final left = {for (final s in _stockRows) '${s['store_id']}:${s['material_id']}': Decimal.parse('${s['balance']}')};
      for (final (index, input) in items.indexed) {
        final qty = Decimal.parse('${input['qty']}');
        if (qty <= Decimal.zero) throw refuse('items.$index.qty', 'The items.$index.qty field must be greater than 0.');
        if (qty.scale > 3) throw refuse('items.$index.qty', 'The items.$index.qty field must have 0-3 decimal places.');
        if (input['boq_item_id'] == null && (input['activity'] as String?)?.trim().isNotEmpty != true) {
          throw refuse('items.$index.boq_item_id', 'The items.$index.boq_item_id field is required when items.$index.activity is not present.');
        }
        final base = _baseOf(input['material_id'], input['qty'], input['unit_id']);
        final key = '${store['id']}:${input['material_id']}';
        final after = (left[key] ?? Decimal.zero) - base;
        if (after < Decimal.zero) {
          throw refuse('stock', 'Only ${_q3(left[key] ?? Decimal.zero)} of Material ${input['material_id']} is available in ${store['name']}.');
        }
        left[key] = after;
        moves.add({
          'id': _issueSerial * 10 + index,
          'material_id': input['material_id'],
          'qty': _q3(qty),
          'unit_id': input['unit_id'],
          'base_qty': _q3(base),
          'boq_item_id': input['boq_item_id'],
          'activity': input['activity'],
        });
      }
      for (final entry in left.entries) {
        final parts = entry.key.split(':');
        final stock = _stockRows.where((s) => '${s['store_id']}' == parts[0] && '${s['material_id']}' == parts[1]).firstOrNull;
        if (stock != null) stock['balance'] = _q3(entry.value);
      }
      final id = _issueSerial++;
      stored = {
        'id': id,
        'number': 'MI-${id.toString().padLeft(4, '0')}',
        'client_uuid': uuid,
        'store_id': store['id'],
        'issue_date': date,
        'issued_to': body['issued_to'],
        'status': 'POSTED',
        'items': moves,
      };
      issuePosts[uuid] = stored;
      materialUseRows = [stored, ...materialUseRows];
    }
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return stored;
  }

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

  /// The entries the server holds, as `GET .../progress/entries` lists them.
  final List<Row> progressEntryRows = [];

  /// Corrections by `client_uuid`: a repeat must not add a second.
  final Map<String, Row> correctionPosts = {};

  /// How many entries a page of the list holds.
  int progressPageSize = 100;

  /// The floor and page of every read of the entry list.
  final List<(int?, int)> progressEntryReads = [];

  /// Adds an entry the server already holds, and returns it.
  Row addProgressEntry({
    required int boqItemId,
    int? floorId,
    String qty = '10.000',
    String workDate = '2026-11-09',
    String? remark,
    int projectId = 1,
  }) {
    final row = <String, dynamic>{
      'id': _progressSerial++,
      'client_uuid': null,
      'project_id': projectId,
      'boq_item_id': boqItemId,
      'project_floor_id': floorId,
      'work_date': workDate,
      'qty_done': qty,
      'remark': remark,
      'is_pending': false,
      'corrects_entry_id': null,
      'resolves_entry_id': null,
      'entered_by_name': 'Sachin More',
      'photos': <Row>[],
      'created_at': _stampNow(),
    };
    progressEntryRows.add(row);
    return row;
  }

  @override
  Future<Map<String, dynamic>> progressEntries(int projectId, {int? floorId, int page = 1}) async {
    _guard();
    progressEntryReads.add((floorId, page));
    final all = progressEntryRows
        .where((r) => r['project_id'] == projectId && (floorId == null || r['project_floor_id'] == floorId))
        .toList()
      ..sort((a, b) => (a['id'] as int).compareTo(b['id'] as int));
    final last = max(1, (all.length / progressPageSize).ceil());
    final from = (page - 1) * progressPageSize;
    return {
      'data': from >= all.length ? <Row>[] : all.sublist(from, min(all.length, from + progressPageSize)),
      'current_page': page,
      'last_page': last,
      'per_page': progressPageSize,
      'total': all.length,
    };
  }

  /// Mirrors `ProgressEntryService::correct`: only an original entry that
  /// recorded work, a reason, and a quantity that differs from what it counts.
  @override
  Future<Map<String, dynamic>> correctProgress(int entryId, Map<String, dynamic> body) async {
    _guard();
    final failure = failNextProgressPost;
    if (failure != null) {
      failNextProgressPost = null;
      throw failure;
    }
    final uuid = body['client_uuid'] as String;
    var row = correctionPosts[uuid];
    if (row == null) {
      final entry = progressEntryRows.where((r) => r['id'] == entryId).firstOrNull;
      if (entry == null) throw const ServerFailure('Not found.', statusCode: 404);
      Never refuse(String field, String message) =>
          throw ValidationFailure(message, errors: {field: [message]});
      final entered = Decimal.parse(entry['qty_done'] as String);
      if (entry['corrects_entry_id'] != null || entry['resolves_entry_id'] != null || entered == Decimal.zero) {
        refuse('qty_done', 'Only an entry that recorded work can be corrected. Correct the original entry.');
      }
      final reason = ((body['remark'] as String?) ?? '').trim();
      if (reason.isEmpty) refuse('remark', 'Say why the entry is being corrected.');
      var net = entered;
      for (final other in progressEntryRows.where((r) => r['corrects_entry_id'] == entryId)) {
        net += Decimal.parse(other['qty_done'] as String);
      }
      final difference = Decimal.parse(body['qty_done'] as String) - net;
      if (difference == Decimal.zero) refuse('qty_done', 'That is already the quantity on this entry.');
      row = {
        ...entry,
        'id': _progressSerial++,
        'client_uuid': uuid,
        'qty_done': difference.toStringAsFixed(3),
        'remark': reason,
        'is_pending': false,
        'corrects_entry_id': entryId,
        'created_at': _stampNow(),
      };
      correctionPosts[uuid] = row;
      progressEntryRows.add(row);
    }
    if (loseNextProgressReply) {
      loseNextProgressReply = false;
      throw const OfflineFailure();
    }
    return row;
  }

  /// Photos and attachments stored by `client_uuid`: a repeat must not add a second.
  final Map<String, Row> photoPosts = {};

  /// Rows the server already holds for a list path such as `/issues/5/photos`,
  /// before anything is uploaded from the phone.
  final Map<String, List<Row>> fieldPhotoRows = {};

  /// The multipart field and extra fields of each upload, in request order.
  final List<(String, Map<String, String>)> fieldPhotoForms = [];

  /// Paths whose bytes were asked for.
  final List<String> fieldPhotoByteReads = [];

  /// The next photo list read fails with this.
  ApiFailure? failNextPhotoList;

  /// What a photo's bytes are: a one pixel PNG, so an image widget can decode it.
  static final Uint8List tinyPng = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  );

  @override
  Future<Map<String, dynamic>> uploadFieldPhoto(
    String path, {
    required String filePath,
    required String clientUuid,
    String? caption,
    String? takenAt,
    String fileField = 'photo',
    Map<String, String> fields = const <String, String>{},
  }) async {
    _guard();
    final selfie = RegExp(r'^/projects/(\d+)/attendance/selfies$').firstMatch(path);
    if (selfie != null) return _postSelfie(int.parse(selfie.group(1)!), clientUuid, filePath, fields);
    fieldPhotos.add((path, clientUuid));
    fieldPhotoForms.add((fileField, fields));
    final match = RegExp(r'^/(issues|measurements)/(\d+)/').firstMatch(path);
    final parentKey = match == null ? null : (match.group(1) == 'issues' ? 'issue_id' : 'measurement_entry_id');
    // A measurement locks its attachments with the entry, but a repeat of one
    // already stored gets the original back.
    if (match != null && match.group(1) == 'measurements' && !photoPosts.containsKey(clientUuid)) {
      final entry = measurements.where((m) => m['id'] == int.parse(match.group(2)!)).firstOrNull;
      final status = entry?['status'];
      if (status == 'APPROVED') {
        throw const ServerFailure(
          'This record is approved and cannot be changed. Create a reversal entry instead.',
          statusCode: 409,
        );
      }
      if (status != null && status != 'DRAFT' && status != 'RETURNED') {
        throw const ServerFailure('This entry is under review and its attachments cannot be changed.', statusCode: 409);
      }
    }
    return _storeProgress(photoPosts, {
      'client_uuid': clientUuid,
      'kind': fields['kind'],
      'caption': caption,
      'taken_at': takenAt,
      'parent_path': path,
      'file_field': fileField,
      if (match != null) parentKey!: int.parse(match.group(2)!),
      'is_image': true,
    });
  }

  @override
  Future<List<Map<String, dynamic>>> fieldPhotoList(String path) async {
    _guard();
    final failure = failNextPhotoList;
    if (failure != null) {
      failNextPhotoList = null;
      throw failure;
    }
    return [
      ...?fieldPhotoRows[path],
      for (final row in photoPosts.values)
        if (row['parent_path'] == path) row,
    ];
  }

  @override
  Future<Uint8List> fieldPhotoBytes(String path) async {
    _guard();
    fieldPhotoByteReads.add(path);
    return tinyPng;
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
