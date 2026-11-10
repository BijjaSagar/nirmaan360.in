import 'dart:async';
import 'dart:io' show HttpDate;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:path/path.dart' as p;

import '../config/app_config.dart';
import 'api_failure.dart';
import 'nirmaan_api.dart';

typedef TokenReader = Future<String?> Function();

/// Talks to the Laravel API over Dio.
///
/// Timeouts assume a weak 2G/3G link. Nothing here retries: the sync engine
/// and the repositories own retry policy, because retrying blindly is how
/// entries get lost or duplicated.
class DioNirmaanApi implements NirmaanApi {
  DioNirmaanApi({
    required TokenReader readToken,
    this._onUnauthorized,
    String? baseUrl,
    Dio? dio,
  }) : _dio = dio ?? Dio() {
    _dio.options
      ..baseUrl = baseUrl ?? AppConfig.apiBaseUrl
      ..connectTimeout = const Duration(seconds: 20)
      ..sendTimeout = const Duration(seconds: 60)
      ..receiveTimeout = const Duration(seconds: 60)
      ..headers['Accept'] = 'application/json';
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          if (blocksPath?.call(options.path) ?? false) {
            handler.reject(DioException(requestOptions: options, type: DioExceptionType.connectionError));
            return;
          }
          final token = await readToken();
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
      ),
    );
  }

  final Dio _dio;
  final void Function()? _onUnauthorized;

  /// True for a request that must not leave the phone: the offline plan's
  /// backstop. A blocked request looks like having no network.
  bool Function(String path)? blocksPath;

  static const _pullTimeout = Duration(minutes: 3);
  static const _uploadSendTimeout = Duration(minutes: 5);
  static const _uploadReceiveTimeout = Duration(minutes: 2);

  @override
  Future<LoginResult> login({
    required String email,
    required String password,
    required String deviceName,
  }) async {
    final Map<String, dynamic> body;
    try {
      body = await _call(
        () => _dio.post(
          '/auth/login',
          data: {
            'email': email,
            'password': password,
            'device_name': deviceName,
          },
        ),
        treat401AsExpired: false,
      );
    } on ServerFailure catch (e) {
      if (e.statusCode == 401) {
        throw ValidationFailure(
          'These details do not match our records.',
          errors: {
            'email': ['These details do not match our records.'],
          },
        );
      }
      rethrow;
    }
    return LoginResult(
      token: body['token'] as String,
      user: (body['user'] as Map).cast<String, dynamic>(),
    );
  }

  @override
  Future<void> logout() async {
    await _call(() => _dio.post('/auth/logout'));
  }

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
    final body = await _call(
      () => _dio.post(
        _notify('/devices', portal),
        data: {
          'token': token,
          'platform': platform,
          'previous_token': ?previousToken,
          'app_version': ?appVersion,
          'locale': ?locale,
          'device_name': ?deviceName,
        },
      ),
    );
    return DeviceRegistration(pushConnected: body['push_connected'] == true);
  }

  @override
  Future<void> unregisterDevice(String token, {bool portal = false}) async {
    await _call(() => _dio.delete(_notify('/devices', portal), data: {'token': token}));
  }

  @override
  Future<Map<String, dynamic>> notificationPreferences({bool portal = false}) =>
      _getData(_notify('/notification-preferences', portal));

  @override
  Future<Map<String, dynamic>> updateNotificationPreferences(Map<String, Object?> changes, {bool portal = false}) async {
    final body = await _call(() => _dio.put(_notify('/notification-preferences', portal), data: changes));
    return (body['data'] as Map).cast<String, dynamic>();
  }

  /// A contractor login reaches the notification routes under its portal.
  static String _notify(String path, bool portal) => portal ? '/contractor-portal$path' : path;

  @override
  Future<Map<String, dynamic>> me() async {
    final body = await _call(() => _dio.get('/me'));
    return (body['user'] as Map).cast<String, dynamic>();
  }

  @override
  Future<Map<String, dynamic>> offlineStatus() => _call(() => _dio.get('/offline/status'));

  @override
  Future<Map<String, dynamic>> activateDevice({
    required String deviceId,
    required String deviceName,
    String? platform,
    String? appVersion,
  }) {
    return _call(
      () => _dio.post(
        '/offline/devices/activate',
        data: {
          'device_id': deviceId,
          'device_name': deviceName,
          'platform': ?platform,
          'app_version': ?appVersion,
        },
      ),
    );
  }

  @override
  Future<Map<String, dynamic>> refreshDevice({required String deviceId, String? appVersion}) {
    return _call(
      () => _dio.post(
        '/offline/devices/refresh',
        data: {'device_id': deviceId, 'app_version': ?appVersion},
      ),
    );
  }

  @override
  Future<void> releaseDevice(String deviceId) async {
    await _call(() => _dio.post('/offline/devices/release', data: {'device_id': deviceId}));
  }

  @override
  Future<List<PushResult>> push(List<PushItem> items) async {
    final body = await _call(
      () => _dio.post(
        '/sync/push',
        data: {'items': items.map((e) => e.toJson()).toList()},
        options: Options(receiveTimeout: const Duration(seconds: 90)),
      ),
    );
    return (body['results'] as List)
        .map((e) => PushResult.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  @override
  Future<Map<String, dynamic>> pull({String? since}) {
    return _call(
      () => _dio.get(
        '/sync/pull',
        queryParameters: since == null ? null : {'since': since},
        options: Options(receiveTimeout: _pullTimeout),
      ),
    );
  }

  @override
  Future<UploadResult> upload(UploadRequest request) async {
    final body = await _call(() async {
      // A FormData cannot be reused, so build it fresh inside the attempt.
      final form = FormData.fromMap({
        'type': request.type,
        'client_uuid': request.clientUuid,
        'parent_client_uuid': ?request.parentClientUuid,
        'parent_id': ?request.parentId,
        'caption': ?request.caption,
        'taken_at': ?request.takenAt,
        'file': await MultipartFile.fromFile(
          request.filePath,
          filename: p.basename(request.filePath),
        ),
      });
      return _dio.post(
        '/sync/upload',
        data: form,
        options: Options(
          sendTimeout: _uploadSendTimeout,
          receiveTimeout: _uploadReceiveTimeout,
        ),
      );
    });
    return UploadResult(
      status: body['status'] as String,
      id: (body['id'] as num).toInt(),
    );
  }

  @override
  Future<ApprovalsResponse> approvals() async {
    final body = await _call(() => _dio.get('/approvals'));
    return ApprovalsResponse(
      items: ((body['data'] as List?) ?? const [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList(),
      waitingCount: (body['waiting_count'] as num?)?.toInt() ?? 0,
    );
  }

  @override
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
  }) async {
    final body = await _call(
      () => _dio.post(
        '/approvals/$approvalId/act',
        data: {'action': action, 'remark': remark, 'client_uuid': clientUuid},
      ),
    );
    return (body['data'] as Map).cast<String, dynamic>();
  }

  @override
  Future<Map<String, dynamic>> addWorker({
    required String clientUuid,
    required String name,
    required String trade,
    String? phone,
    String type = 'own',
    String? dailyWage,
  }) async {
    final body = await _call(
      () => _dio.post(
        '/workers',
        data: {
          'client_uuid': clientUuid,
          'name': name,
          'trade': trade,
          'phone': phone,
          'type': type,
          'daily_wage': ?dailyWage,
        },
      ),
    );
    return (body['data'] as Map).cast<String, dynamic>();
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
  }) {
    return _createRow('/projects', {
      'client_uuid': clientUuid,
      'name': name,
      'location': ?location,
      'start_date': ?startDate,
      'end_date': ?endDate,
      'contract_value': ?contractValue,
      'code': ?code,
    });
  }

  @override
  Future<Map<String, dynamic>> addBoqItem({
    required int projectId,
    required String clientUuid,
    required String description,
    required String unit,
    required String qty,
    required String rate,
    String? code,
    String? section,
  }) {
    return _createRow('/projects/$projectId/boq-items', {
      'client_uuid': clientUuid,
      'description': description,
      'unit': unit,
      'qty': qty,
      'rate': rate,
      'code': ?code,
      'section': ?section,
    });
  }

  @override
  Future<Map<String, dynamic>> createWallet({
    required int projectId,
    required String clientUuid,
    required String name,
    int? userId,
  }) {
    return _createRow('/projects/$projectId/wallets', {
      'client_uuid': clientUuid,
      'name': name,
      'user_id': ?userId,
    });
  }

  Future<Map<String, dynamic>> _createRow(
    String path,
    Map<String, dynamic> data,
  ) async {
    final body = await _call(() => _dio.post(path, data: data));
    return (body['data'] as Map).cast<String, dynamic>();
  }

  @override
  Future<Uint8List> dprPhotoBytes(int photoId) =>
      _bytes('/dpr-photos/$photoId');

  @override
  Future<Uint8List> pettyBillBytes(int entryId) =>
      _bytes('/petty-entries/$entryId/bill');

  /// The token goes only to our own API: the path is taken from the address
  /// and asked of the configured server, wherever the address points.
  @override
  Future<Uint8List> projectImageBytes(String imageUrl) {
    final Uri uri = Uri.parse(imageUrl);
    final int at = uri.path.indexOf('/projects/');
    if (at < 0) throw const ServerFailure('Not a project photo.', statusCode: 404);
    return _bytes('${uri.path.substring(at)}${uri.hasQuery ? '?${uri.query}' : ''}');
  }

  Future<Map<String, dynamic>> _getData(String path) async {
    final body = await _call(() => _dio.get(path));
    return (body['data'] as Map).cast<String, dynamic>();
  }

  Future<List<Map<String, dynamic>>> _getRows(String path) async {
    final body = await _call(() => _dio.get(path));
    return (body['data'] as List).map((row) => (row as Map).cast<String, dynamic>()).toList();
  }

  Future<Map<String, dynamic>> _postData(String path, Map<String, dynamic> data) async {
    final body = await _call(() => _dio.post(path, data: data));
    return (body['data'] as Map).cast<String, dynamic>();
  }

  @override
  Future<Map<String, dynamic>> progressSummary(int projectId) =>
      _getData('/projects/$projectId/progress/summary');

  @override
  Future<List<Map<String, dynamic>>> progressFloors(int projectId) =>
      _getRows('/projects/$projectId/floors');

  @override
  Future<List<Map<String, dynamic>>> progressItems(int projectId) =>
      _getRows('/projects/$projectId/progress/items');

  @override
  Future<List<Map<String, dynamic>>> progressPending(int projectId) =>
      _getRows('/projects/$projectId/progress/pending');

  @override
  Future<Map<String, dynamic>> stageMatrix(int projectId) => _getData('/projects/$projectId/stages');

  @override
  Future<Map<String, dynamic>> floorProgress(int floorId) => _getData('/floors/$floorId/progress');

  @override
  Future<Map<String, dynamic>> postProgressEntry(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/progress/entries', body);

  @override
  Future<Map<String, dynamic>> resolveProgress(int entryId, Map<String, dynamic> body) =>
      _postData('/progress/entries/$entryId/resolve', body);

  @override
  Future<Map<String, dynamic>> correctProgress(int entryId, Map<String, dynamic> body) =>
      _postData('/progress/entries/$entryId/corrections', body);

  @override
  Future<Map<String, dynamic>> progressEntries(int projectId, {int? floorId, int page = 1}) => _call(
    () => _dio.get(
      '/projects/$projectId/progress/entries',
      queryParameters: <String, dynamic>{'project_floor_id': ?floorId, 'page': page},
    ),
  );

  @override
  Future<Map<String, dynamic>> postStageCell(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/stages/cells', body);

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
    final body = await _call(() async {
      // A FormData cannot be reused, so build it fresh inside the attempt.
      final form = FormData.fromMap({
        ...fields,
        'client_uuid': clientUuid,
        'caption': ?caption,
        'taken_at': ?takenAt,
        fileField: await MultipartFile.fromFile(filePath, filename: p.basename(filePath)),
      });
      return _dio.post(
        path,
        data: form,
        options: Options(sendTimeout: _uploadSendTimeout, receiveTimeout: _uploadReceiveTimeout),
      );
    });
    return (body['data'] as Map).cast<String, dynamic>();
  }

  @override
  Future<List<Map<String, dynamic>>> fieldPhotoList(String path) => _getRows(path);

  @override
  Future<Uint8List> fieldPhotoBytes(String path) => _bytes(path);

  @override
  Future<List<Map<String, dynamic>>> contractorBoard(int projectId) => _getRows('/projects/$projectId/contractor-control');

  @override
  Future<Map<String, dynamic>> contractorContract(int workOrderId) => _getData('/work-orders/$workOrderId/contractor-control');

  @override
  Future<Map<String, dynamic>> contractorPayments(int projectId) =>
      _call(() => _dio.get('/projects/$projectId/contractor-payments'));

  @override
  Future<Map<String, dynamic>> contractorPayment(int paymentId) => _getData('/contractor-payments/$paymentId');

  @override
  Future<Uint8List> contractorPaymentBill(int paymentId) => _bytes('/contractor-payments/$paymentId/bill');

  @override
  Future<Map<String, dynamic>> postContractorPayment(
    int workOrderId,
    Map<String, dynamic> body, {
    String? filePath,
  }) async {
    final path = '/work-orders/$workOrderId/contractor-payments';
    if (filePath == null) return _postData(path, body);
    final reply = await _call(() async {
      // A FormData cannot be reused, so build it fresh inside the attempt.
      final form = FormData.fromMap({
        for (final entry in body.entries)
          if (entry.value != null) entry.key: entry.value,
        'bill': await MultipartFile.fromFile(filePath, filename: p.basename(filePath)),
      });
      return _dio.post(
        path,
        data: form,
        options: Options(sendTimeout: _uploadSendTimeout, receiveTimeout: _uploadReceiveTimeout),
      );
    });
    return (reply['data'] as Map).cast<String, dynamic>();
  }

  @override
  Future<List<Map<String, dynamic>>> boqWithTenders(int projectId) => _getRows('/projects/$projectId/boq');

  @override
  Future<Map<String, dynamic>> boqLineTenders(int boqItemId) => _getData('/boq-items/$boqItemId/tenders');

  @override
  Future<Map<String, dynamic>> createLineTender(int boqItemId, Map<String, dynamic> body) =>
      _postData('/boq-items/$boqItemId/tenders', body);

  /// Ten pages of 100 is more subcontractors than any site has to choose from.
  static const int _contractorPages = 10;

  @override
  Future<List<Map<String, dynamic>>> subcontractors({String? query}) async {
    final rows = <Map<String, dynamic>>[];
    for (var page = 1; page <= _contractorPages; page++) {
      final body = await _call(
        () => _dio.get(
          '/contractors',
          queryParameters: <String, dynamic>{if (query != null && query.isNotEmpty) 'q': query, 'page': page},
        ),
      );
      rows.addAll((body['data'] as List).map((row) => (row as Map).cast<String, dynamic>()));
      final last = (body['last_page'] as num?)?.toInt() ?? page;
      if (page >= last) break;
    }
    return rows;
  }

  @override
  Future<Map<String, dynamic>> createSubcontractor(Map<String, dynamic> body) => _postData('/contractors', body);

  @override
  Future<Map<String, dynamic>> tenderComparison(int tenderId) => _getData('/tenders/$tenderId');

  @override
  Future<Map<String, dynamic>> postTenderQuote(int tenderId, Map<String, dynamic> body, {String? filePath}) async {
    final path = '/tenders/$tenderId/bids';
    final reply = await _call(() async {
      // Always multipart, as the server reads it, so `rates[12]` arrives as a map.
      // A FormData cannot be reused, so build it fresh inside the attempt.
      final form = FormData();
      for (final entry in body.entries) {
        final Object? value = entry.value;
        if (value == null) continue;
        if (value is Map) {
          for (final rate in value.entries) {
            if (rate.value != null) form.fields.add(MapEntry('${entry.key}[${rate.key}]', '${rate.value}'));
          }
        } else {
          form.fields.add(MapEntry(entry.key, '$value'));
        }
      }
      if (filePath != null) {
        form.files.add(MapEntry('quotation', await MultipartFile.fromFile(filePath, filename: p.basename(filePath))));
      }
      return _dio.post(
        path,
        data: form,
        options: Options(sendTimeout: _uploadSendTimeout, receiveTimeout: _uploadReceiveTimeout),
      );
    });
    return reply;
  }

  @override
  Future<Uint8List> quotationBytes(int bidId) => _bytes('/bids/$bidId/quotation');

  @override
  Future<Map<String, dynamic>> awardTender(int tenderId, Map<String, dynamic> body) =>
      _call(() => _dio.post('/tenders/$tenderId/award', data: body));

  @override
  Future<List<Map<String, dynamic>>> checklistTemplates() => _getRows('/checklist-templates');

  @override
  Future<Map<String, dynamic>> inspections(int projectId, {int page = 1}) => _call(
    () => _dio.get('/projects/$projectId/inspections', queryParameters: <String, dynamic>{'page': page}),
  );

  @override
  Future<Map<String, dynamic>> inspection(int inspectionId) => _getData('/inspections/$inspectionId');

  @override
  Future<Map<String, dynamic>> postInspection(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/inspections', body);

  @override
  Future<Map<String, dynamic>> machineAssets({int page = 1}) =>
      _call(() => _dio.get('/assets', queryParameters: <String, dynamic>{'page': page}));

  @override
  Future<Map<String, dynamic>> machineLogs(int projectId, {int page = 1}) => _call(
    () => _dio.get('/machine-logs', queryParameters: <String, dynamic>{'project_id': projectId, 'page': page}),
  );

  @override
  Future<Map<String, dynamic>> postMachineLog(Map<String, dynamic> body) => _postData('/machine-logs', body);

  @override
  Future<Map<String, dynamic>> siteDashboard(int projectId) => _getData('/projects/$projectId/site-dashboard');

  @override
  Future<Map<String, dynamic>> siteAlerts(int projectId) =>
      _call(() => _dio.get('/projects/$projectId/alerts', queryParameters: <String, String>{'status': 'open'}));

  @override
  Future<Map<String, dynamic>> scanSiteAlerts(int projectId) =>
      _postData('/projects/$projectId/alerts/scan', const <String, dynamic>{});

  @override
  Future<Map<String, dynamic>> priceBoard() => _call(() => _dio.get('/price-watch'));

  @override
  Future<Map<String, dynamic>> priceMaterial(int materialId) => _call(() => _dio.get('/price-watch/materials/$materialId'));

  @override
  Future<Map<String, dynamic>> priceProject(int projectId) => _call(() => _dio.get('/projects/$projectId/price-watch'));

  @override
  Future<Map<String, dynamic>> portalOverview() => _getData('/contractor-portal');

  @override
  Future<Map<String, dynamic>> portalContract(int workOrderId) => _getData('/contractor-portal/work-orders/$workOrderId');

  @override
  Future<List<Map<String, dynamic>>> portalPayments() => _getRows('/contractor-portal/payments');

  @override
  Future<List<Map<String, dynamic>>> portalBills() => _getRows('/contractor-portal/bills');

  @override
  Future<Map<String, dynamic>> portalBill(int billId) => _getData('/contractor-portal/bills/$billId');

  @override
  Future<Map<String, dynamic>> materialCheck(int projectId) =>
      _getData('/projects/$projectId/material-control/check');

  @override
  Future<List<Map<String, dynamic>>> materialChallans(int projectId) =>
      _getRows('/projects/$projectId/material-control/challans');

  @override
  Future<List<Map<String, dynamic>>> materialConsumption(int projectId) =>
      _getRows('/projects/$projectId/material-control/consumption');

  @override
  Future<List<Map<String, dynamic>>> materialStockChecks(int projectId) =>
      _getRows('/projects/$projectId/material-control/stock-checks');

  @override
  Future<List<Map<String, dynamic>>> materialSuppliers() => _getRows('/material-control/suppliers');

  @override
  Future<Map<String, dynamic>> procurementSnapshot(int projectId) async {
    final body = await _call(() => _dio.get('/projects/$projectId/procurement/snapshot'));
    return ((body['data'] ?? body) as Map).cast<String, dynamic>();
  }

  @override
  Future<Map<String, dynamic>> postMaterialChallan(
    int projectId,
    Map<String, dynamic> body, {
    String? filePath,
  }) async {
    final path = '/projects/$projectId/material-control/challans';
    if (filePath == null) return _postData(path, body);
    final reply = await _call(() async {
      // A FormData cannot be reused, so build it fresh inside the attempt.
      final form = FormData.fromMap({
        for (final entry in body.entries)
          if (entry.value != null) entry.key: entry.value,
        'photo': await MultipartFile.fromFile(filePath, filename: p.basename(filePath)),
      });
      return _dio.post(
        path,
        data: form,
        options: Options(sendTimeout: _uploadSendTimeout, receiveTimeout: _uploadReceiveTimeout),
      );
    });
    return (reply['data'] as Map).cast<String, dynamic>();
  }

  @override
  Future<Map<String, dynamic>> postMaterialUse(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/material-control/consumption', body);

  @override
  Future<Map<String, dynamic>> postStockCount(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/material-control/stock-checks', body);

  @override
  Future<Map<String, dynamic>> attendanceSelfieConsent() => _getData('/attendance/selfie-consent');

  @override
  Future<Map<String, dynamic>> postAttendanceScan(int projectId, Map<String, dynamic> body) =>
      _call(() => _dio.post('/projects/$projectId/attendance/scan', data: body));

  @override
  Future<List<Map<String, dynamic>>> procurementReceipts(int projectId) => _getRows('/projects/$projectId/grns');

  @override
  Future<Map<String, dynamic>> postGoodsReceipt(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/grns', body);

  @override
  Future<Map<String, dynamic>> postMaterialIssue(int projectId, Map<String, dynamic> body) =>
      _postData('/projects/$projectId/material-issues', body);

  Future<Uint8List> _bytes(String path) async {
    try {
      final response = await _dio.get<List<int>>(
        path,
        options: Options(responseType: ResponseType.bytes),
      );
      return Uint8List.fromList(response.data ?? const []);
    } on DioException catch (e) {
      throw _translate(e);
    }
  }

  Future<Map<String, dynamic>> _call(
    Future<Response<dynamic>> Function() request, {
    bool treat401AsExpired = true,
  }) async {
    try {
      final response = await request();
      final data = response.data;
      if (data is Map) return data.cast<String, dynamic>();
      return const <String, dynamic>{};
    } on DioException catch (e) {
      throw _translate(e, treat401AsExpired: treat401AsExpired);
    }
  }

  ApiFailure _translate(DioException e, {bool treat401AsExpired = true}) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
      case DioExceptionType.connectionError:
      case DioExceptionType.cancel:
        return const OfflineFailure();
      case DioExceptionType.badCertificate:
        return ServerFailure(e.message ?? 'Bad certificate', statusCode: null);
      case DioExceptionType.unknown:
        // Socket errors surface here on some platforms.
        return const OfflineFailure();
      case DioExceptionType.badResponse:
        return _fromResponse(e, treat401AsExpired: treat401AsExpired);
    }
  }

  ApiFailure _fromResponse(DioException e, {required bool treat401AsExpired}) {
    final status = e.response?.statusCode;
    final data = e.response?.data;
    final message = data is Map && data['message'] is String
        ? data['message'] as String
        : 'Request failed${status != null ? ' ($status)' : ''}.';

    if (status == 401 && treat401AsExpired) {
      _onUnauthorized?.call();
      return const AuthExpired();
    }
    final Object? code = data is Map ? data['code'] : null;
    if (status == 403 && code == 'offline_plan') {
      return OfflinePlanRefused(message, upgradeUrl: (data as Map)['upgrade_url'] as String?);
    }
    if (status == 429) {
      return RateLimited(
        retryAfter: _retryAfter(e.response),
        serverMessage: data is Map && data['message'] is String && (data['message'] as String).trim().isNotEmpty
            ? (data['message'] as String).trim()
            : null,
      );
    }
    if (status == 422) {
      return ValidationFailure(
        message,
        errors: data is Map
            ? parseValidationErrors(data['errors'])
            : const {},
      );
    }
    return ServerFailure(
      message,
      statusCode: status,
      code: code is String ? code : null,
      data: data is Map ? data.cast<String, dynamic>() : null,
    );
  }

  /// `Retry-After` is either a number of seconds or an HTTP date.
  Duration? _retryAfter(Response<dynamic>? response) {
    final raw = response?.headers.value('retry-after')?.trim();
    if (raw == null || raw.isEmpty) return null;

    final seconds = int.tryParse(raw);
    if (seconds != null) return Duration(seconds: seconds);

    try {
      final wait = HttpDate.parse(raw).difference(DateTime.now());
      return wait.isNegative ? Duration.zero : wait;
    } on Exception {
      return null;
    }
  }
}
