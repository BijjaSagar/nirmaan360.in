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
  Future<Map<String, dynamic>> me() async {
    final body = await _call(() => _dio.get('/me'));
    return (body['user'] as Map).cast<String, dynamic>();
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
    if (status == 429) {
      return RateLimited(retryAfter: _retryAfter(e.response));
    }
    if (status == 422) {
      return ValidationFailure(
        message,
        errors: data is Map
            ? parseValidationErrors(data['errors'])
            : const {},
      );
    }
    return ServerFailure(message, statusCode: status);
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
