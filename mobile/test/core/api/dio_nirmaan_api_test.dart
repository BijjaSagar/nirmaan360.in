import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/config/app_config.dart';

typedef Responder = Object Function(RequestOptions options);

/// Answers requests with canned bodies, or throws to simulate network faults.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.responder);

  final Responder responder;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    await requestStream?.drain<void>();
    final answer = responder(options);
    if (answer is ResponseBody) return answer;
    if (answer is DioException) throw answer;
    throw answer;
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody json(
  Object body, {
  int status = 200,
  Map<String, List<String>> headers = const {},
}) {
  return ResponseBody.fromString(
    jsonEncode(body),
    status,
    headers: {
      Headers.contentTypeHeader: ['application/json'],
      ...headers,
    },
  );
}

void main() {
  late FakeAdapter adapter;
  late DioNirmaanApi api;
  var unauthorizedCalls = 0;
  String? token = 'secret-token';

  void answer(Responder responder) {
    adapter = FakeAdapter(responder);
    unauthorizedCalls = 0;
    api = DioNirmaanApi(
      readToken: () async => token,
      onUnauthorized: () => unauthorizedCalls++,
      baseUrl: 'https://example.test/api/v1',
      dio: Dio()..httpClientAdapter = adapter,
    );
  }

  setUp(() {
    token = 'secret-token';
    answer((_) => json({}));
  });

  test('default base URL is production', () {
    expect(AppConfig.apiBaseUrl, 'https://nirmaan360.in/api/v1');
  });

  test('sends the Bearer token and asks for JSON', () async {
    answer((_) => json({'user': {'id': 1}}));
    await api.me();

    final request = adapter.requests.single;
    expect(request.uri.toString(), 'https://example.test/api/v1/me');
    expect(request.headers['Authorization'], 'Bearer secret-token');
    expect(request.headers['Accept'], 'application/json');
  });

  group('the offline plan', () {
    test('a 403 with code offline_plan is its own failure, not a ServerFailure', () async {
      answer((_) => json({'code': 'offline_plan', 'message': 'On the offline plan.', 'upgrade_url': 'https://example.test/billing'}, status: 403));
      await expectLater(
        api.pull(),
        throwsA(isA<OfflinePlanRefused>().having((e) => e.upgradeUrl, 'upgradeUrl', 'https://example.test/billing')),
      );
    });

    test('an ordinary 403 is still a ServerFailure', () async {
      answer((_) => json({'message': 'No.'}, status: 403));
      await expectLater(api.pull(), throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 403)));
    });

    test('a refused licence carries its code and details', () async {
      answer((_) => json({'code': 'device_limit', 'message': 'Full.', 'manage_url': 'https://example.test/d'}, status: 409));
      await expectLater(
        api.activateDevice(deviceId: 'phone-0001', deviceName: 'P'),
        throwsA(
          isA<ServerFailure>()
              .having((e) => e.code, 'code', 'device_limit')
              .having((e) => e.data?['manage_url'], 'manage_url', 'https://example.test/d'),
        ),
      );
    });

    test('a freed phone\'s refusal carries where to free one, in manage_url', () async {
      answer(
        (_) => json({
          'code': 'licence_revoked',
          'message': 'This phone was freed from the company.',
          'manage_url': 'https://example.test/offline/devices',
        }, status: 410),
      );
      await expectLater(
        api.refreshDevice(deviceId: 'phone-0001'),
        throwsA(
          isA<ServerFailure>()
              .having((e) => e.statusCode, 'status', 410)
              .having((e) => e.code, 'code', 'licence_revoked')
              .having((e) => e.data?['manage_url'], 'manage_url', 'https://example.test/offline/devices'),
        ),
      );
    });

    test('calls the licence routes with what they need', () async {
      answer((_) => json({'token': 't'}));
      await api.activateDevice(deviceId: 'phone-0001', deviceName: 'P', platform: 'android', appVersion: '1.0');
      await api.refreshDevice(deviceId: 'phone-0001');
      await api.releaseDevice('phone-0001');
      await api.offlineStatus();

      expect(adapter.requests.map((r) => r.path), <String>[
        '/offline/devices/activate',
        '/offline/devices/refresh',
        '/offline/devices/release',
        '/offline/status',
      ]);
      expect(adapter.requests.first.data, <String, Object>{
        'device_id': 'phone-0001',
        'device_name': 'P',
        'platform': 'android',
        'app_version': '1.0',
      });
    });

    test('a blocked path never reaches the network', () async {
      answer((_) => json({}));
      api.blocksPath = (path) => path != '/me';

      await expectLater(api.pull(), throwsA(isA<OfflineFailure>()));
      await expectLater(api.push(const []), throwsA(isA<OfflineFailure>()));
      expect(adapter.requests, isEmpty);

      answer((_) => json({'user': {'id': 1}}));
      api.blocksPath = (path) => path != '/me';
      await api.me();
      expect(adapter.requests, hasLength(1));
    });
  });

  test('a project photo is only ever asked of our own server', () async {
    answer((_) => ResponseBody.fromBytes(const <int>[1, 2, 3], 200));
    final bytes = await api.projectImageBytes('https://elsewhere.example/api/v1/projects/12/image?v=ab12cd34');

    final request = adapter.requests.single;
    expect(request.uri.toString(), 'https://example.test/api/v1/projects/12/image?v=ab12cd34');
    expect(request.headers['Authorization'], 'Bearer secret-token');
    expect(bytes, <int>[1, 2, 3]);
  });

  test('encodes the + in since', () async {
    answer((_) => json({'server_time': 'x'}));
    await api.pull(since: '2026-11-09T14:27:54+05:30');

    expect(
      adapter.requests.single.uri.toString(),
      contains('since=2026-11-09T14%3A27%3A54%2B05%3A30'),
    );
  });

  test('omits since on a first pull', () async {
    answer((_) => json({'server_time': 'x'}));
    await api.pull();
    expect(adapter.requests.single.uri.query, isEmpty);
  });

  group('failures', () {
    for (final type in [
      DioExceptionType.connectionTimeout,
      DioExceptionType.sendTimeout,
      DioExceptionType.receiveTimeout,
      DioExceptionType.connectionError,
    ]) {
      test('$type is OfflineFailure', () async {
        answer((o) => DioException(requestOptions: o, type: type));
        await expectLater(api.me(), throwsA(isA<OfflineFailure>()));
      });
    }

    test('a socket error is OfflineFailure', () async {
      answer(
        (o) => DioException(
          requestOptions: o,
          error: const SocketException('no route'),
        ),
      );
      await expectLater(api.me(), throwsA(isA<OfflineFailure>()));
    });

    test('401 is AuthExpired and reports it', () async {
      answer((_) => json({'message': 'Unauthenticated.'}, status: 401));
      await expectLater(api.me(), throwsA(isA<AuthExpired>()));
      expect(unauthorizedCalls, 1);
    });

    test('a 401 at login is a bad password, not an expired session', () async {
      answer((_) => json({'message': 'Nope'}, status: 401));
      await expectLater(
        api.login(email: 'a@b.c', password: 'x', deviceName: 'd'),
        throwsA(isA<ValidationFailure>()),
      );
      expect(unauthorizedCalls, 0);
    });

    test('422 is ValidationFailure with the errors map', () async {
      answer(
        (_) => json({
          'message': 'Invalid',
          'errors': {
            'email': ['Bad email'],
          },
        }, status: 422),
      );
      await expectLater(
        api.login(email: 'a', password: 'x', deviceName: 'd'),
        throwsA(
          isA<ValidationFailure>().having(
            (e) => e.errors['email'],
            'errors',
            ['Bad email'],
          ),
        ),
      );
    });

    test('500 is ServerFailure with the status', () async {
      answer((_) => json({'message': 'Boom'}, status: 500));
      await expectLater(
        api.me(),
        throwsA(
          isA<ServerFailure>().having((e) => e.statusCode, 'status', 500),
        ),
      );
    });

    test('404 and 409 stay ServerFailure', () async {
      answer((_) => json({'message': 'Gone'}, status: 404));
      await expectLater(api.me(), throwsA(isA<ServerFailure>()));
    });
  });

  group('429', () {
    test('Retry-After in seconds is honoured', () async {
      answer(
        (_) => json(
          {'message': 'Too Many Attempts.'},
          status: 429,
          headers: {
            'retry-after': ['42'],
          },
        ),
      );
      await expectLater(
        api.me(),
        throwsA(
          isA<RateLimited>().having(
            (e) => e.retryAfter,
            'retryAfter',
            const Duration(seconds: 42),
          ),
        ),
      );
    });

    test('keeps the server\'s own words, for a screen that has to explain', () async {
      answer((_) => json({'message': ' Too Many Attempts. '}, status: 429));
      await expectLater(api.me(), throwsA(isA<RateLimited>().having((e) => e.serverMessage, 'serverMessage', 'Too Many Attempts.')));

      answer((_) => json({}, status: 429));
      await expectLater(api.me(), throwsA(isA<RateLimited>().having((e) => e.serverMessage, 'serverMessage', isNull)));

      answer((_) => json({'message': 42}, status: 429));
      await expectLater(api.me(), throwsA(isA<RateLimited>().having((e) => e.serverMessage, 'serverMessage', isNull)));
    });

    test('Retry-After as an HTTP date is honoured', () async {
      final at = DateTime.now().toUtc().add(const Duration(minutes: 2));
      answer(
        (_) => json(
          {},
          status: 429,
          headers: {
            'retry-after': [HttpDate.format(at)],
          },
        ),
      );
      await expectLater(
        api.me(),
        throwsA(
          isA<RateLimited>().having(
            (e) => e.retryAfter!.inSeconds,
            'seconds',
            inInclusiveRange(100, 121),
          ),
        ),
      );
    });

    test('a missing header leaves retryAfter empty', () async {
      answer((_) => json({}, status: 429));
      await expectLater(
        api.me(),
        throwsA(
          isA<RateLimited>().having((e) => e.retryAfter, 'retryAfter', isNull),
        ),
      );
    });

    test('is not a ServerFailure, so it can never become attention', () async {
      answer((_) => json({}, status: 429));
      await expectLater(api.me(), throwsA(isNot(isA<ServerFailure>())));
    });
  });

  group('site alerts scan', () {
    test('posts to the project\'s scan route and reads the counts', () async {
      answer((_) => json({'data': {'new': 2, 'open': 5, 'resolved': 1}}));

      final Map<String, dynamic> reply = await api.scanSiteAlerts(8);

      expect(adapter.requests.single.method, 'POST');
      expect(adapter.requests.single.path, '/projects/8/alerts/scan');
      expect(reply, <String, dynamic>{'new': 2, 'open': 5, 'resolved': 1});
    });

    test('a 403 is the server\'s refusal in its words, a 429 is a rate limit with its words', () async {
      answer((_) => json({'message': 'This action is unauthorized.'}, status: 403));
      await expectLater(api.scanSiteAlerts(8), throwsA(isA<ServerFailure>().having((e) => e.message, 'message', 'This action is unauthorized.')));

      answer((_) => json({'message': 'Too Many Attempts.'}, status: 429, headers: {'retry-after': ['42']}));
      await expectLater(
        api.scanSiteAlerts(8),
        throwsA(
          isA<RateLimited>()
              .having((e) => e.serverMessage, 'serverMessage', 'Too Many Attempts.')
              .having((e) => e.retryAfter, 'retryAfter', const Duration(seconds: 42)),
        ),
      );
    });
  });

  group('endpoints', () {
    test('push sends the batch and parses results in order', () async {
      answer(
        (_) => json({
          'results': [
            {
              'client_uuid': 'u1',
              'status': 'created',
              'id': 12,
              'server_status': 'DRAFT',
              'reason': null,
              'errors': null,
            },
            {
              'client_uuid': 'u2',
              'status': 'error',
              'errors': {
                'nos': ['Required'],
              },
            },
          ],
        }),
      );

      final results = await api.push(const [
        PushItem(
          type: 'measurement',
          clientUuid: 'u1',
          projectId: 1,
          clientUpdatedAt: '2026-11-09T10:00:00+05:30',
          data: {'nos': '1.000'},
        ),
      ]);

      final sent = adapter.requests.single.data as Map;
      expect(((sent['items'] as List).first as Map)['client_uuid'], 'u1');
      expect(((sent['items'] as List).first as Map)['server_id'], isNull);
      expect(results.first.id, 12);
      expect(results.first.serverStatus, 'DRAFT');
      expect(results.last.errors!['nos'], ['Required']);
    });

    test('upload sends multipart and parses the result', () async {
      final temp = await Directory.systemTemp.createTemp('n360');
      addTearDown(() => temp.delete(recursive: true));
      final file = File('${temp.path}/photo.jpg')..writeAsBytesSync([1, 2]);

      answer((_) => json({'status': 'created', 'id': 3}, status: 201));

      final result = await api.upload(
        UploadRequest(
          type: 'dpr_photo',
          clientUuid: 'p1',
          filePath: file.path,
          parentClientUuid: 'd1',
          caption: 'North face',
        ),
      );

      final form = adapter.requests.single.data as FormData;
      expect(result.status, 'created');
      expect(result.id, 3);
      expect(form.fields.map((e) => e.key), containsAll(['type', 'client_uuid', 'parent_client_uuid', 'caption']));
      expect(form.fields.map((e) => e.key), isNot(contains('parent_id')));
      expect(form.files.single.key, 'file');
    });

    test('a field photo names its own file field and carries its extra fields', () async {
      final temp = await Directory.systemTemp.createTemp('n360');
      addTearDown(() => temp.delete(recursive: true));
      final file = File('${temp.path}/photo.jpg')..writeAsBytesSync([1, 2]);
      answer((_) => json({'data': {'id': 9, 'issue_id': 5, 'kind': 'before'}}, status: 201));

      final reply = await api.uploadFieldPhoto(
        '/issues/5/photos',
        filePath: file.path,
        clientUuid: 'u1',
        fields: {'kind': 'before'},
      );

      final request = adapter.requests.single;
      final form = request.data as FormData;
      expect(request.uri.path, '/api/v1/issues/5/photos');
      expect(reply['id'], 9);
      expect(form.fields.map((e) => '${e.key}=${e.value}'), containsAll(['kind=before', 'client_uuid=u1']));
      expect(form.files.single.key, 'photo');
    });

    test('an attachment is sent under the field the endpoint reads', () async {
      final temp = await Directory.systemTemp.createTemp('n360');
      addTearDown(() => temp.delete(recursive: true));
      final file = File('${temp.path}/photo.jpg')..writeAsBytesSync([1, 2]);
      answer((_) => json({'data': {'id': 9}}, status: 201));

      await api.uploadFieldPhoto(
        '/measurements/5/attachments',
        filePath: file.path,
        clientUuid: 'u1',
        fileField: 'file',
        fields: {'kind': 'photo'},
      );

      expect((adapter.requests.single.data as FormData).files.single.key, 'file');
    });

    test('a photo list unwraps data and a photo comes back as bytes', () async {
      answer((_) => json({'data': [{'id': 7, 'kind': 'after'}]}));
      final rows = await api.fieldPhotoList('/issues/5/photos');
      expect(rows.single['kind'], 'after');
      expect(adapter.requests.single.uri.path, '/api/v1/issues/5/photos');

      answer((_) => ResponseBody.fromBytes([1, 2, 3], 200));
      expect(await api.fieldPhotoBytes('/issue-photos/7'), [1, 2, 3]);
      expect(adapter.requests.single.uri.path, '/api/v1/issue-photos/7');
    });

    test('approvals reads data and waiting_count', () async {
      answer(
        (_) => json({
          'data': [
            {'id': 7},
          ],
          'waiting_count': 3,
        }),
      );

      final response = await api.approvals();

      expect(response.items.single['id'], 7);
      expect(response.waitingCount, 3);
    });

    test('act unwraps data', () async {
      answer((_) => json({'data': {'id': 7, 'status': 'APPROVED'}}));

      final item = await api.actOnApproval(
        7,
        action: 'approve',
        clientUuid: 'c1',
      );

      expect(item['status'], 'APPROVED');
      expect(
        adapter.requests.single.uri.path,
        '/api/v1/approvals/7/act',
      );
    });

    group('setup', () {
      Map<String, dynamic> created(Map<String, dynamic> row) => {'data': row};

      test('createProject posts the contract fields and unwraps data', () async {
        answer((_) => json(created({'id': 9, 'code': 'RP'}), status: 201));

        final row = await api.createProject(
          clientUuid: 'c1',
          name: 'Riverside',
          location: 'Nashik',
          startDate: '2026-11-01',
          endDate: '2027-11-01',
          contractValue: '12500000.50',
        );

        final request = adapter.requests.single;
        expect(request.method, 'POST');
        expect(request.uri.path, '/api/v1/projects');
        expect(request.data, {
          'client_uuid': 'c1',
          'name': 'Riverside',
          'location': 'Nashik',
          'start_date': '2026-11-01',
          'end_date': '2027-11-01',
          'contract_value': '12500000.50',
        });
        expect(row['id'], 9);
      });

      test('blank optional project fields are left out', () async {
        answer((_) => json(created({'id': 9}), status: 201));

        await api.createProject(clientUuid: 'c1', name: 'Riverside');

        expect(adapter.requests.single.data, {
          'client_uuid': 'c1',
          'name': 'Riverside',
        });
      });

      test('a repeated create answers 200 and parses the same', () async {
        answer((_) => json(created({'id': 9})));

        final row = await api.createProject(clientUuid: 'c1', name: 'R');

        expect(row['id'], 9);
      });

      test('addBoqItem posts to the project with decimals as strings', () async {
        answer((_) => json(created({'id': 3}), status: 201));

        await api.addBoqItem(
          projectId: 7,
          clientUuid: 'c2',
          description: 'Concrete',
          unit: 'cum',
          qty: '12.500',
          rate: '7450.00',
          section: 'Footings',
        );

        final request = adapter.requests.single;
        expect(request.uri.path, '/api/v1/projects/7/boq-items');
        expect(request.data, {
          'client_uuid': 'c2',
          'description': 'Concrete',
          'unit': 'cum',
          'qty': '12.500',
          'rate': '7450.00',
          'section': 'Footings',
        });
      });

      test('createWallet posts to the project', () async {
        answer((_) => json(created({'id': 4}), status: 201));

        await api.createWallet(
          projectId: 7,
          clientUuid: 'c3',
          name: 'Site cash',
          userId: 4,
        );

        final request = adapter.requests.single;
        expect(request.uri.path, '/api/v1/projects/7/wallets');
        expect(request.data, {
          'client_uuid': 'c3',
          'name': 'Site cash',
          'user_id': 4,
        });
      });

      test('the plan limit keeps errors.plan', () async {
        answer(
          (_) => json({
            'message': 'Your plan project limit has been reached.',
            'errors': {
              'plan': ['Your plan project limit has been reached.'],
            },
          }, status: 422),
        );

        await expectLater(
          api.createProject(clientUuid: 'c1', name: 'R'),
          throwsA(
            isA<ValidationFailure>().having(
              (e) => e.errors['plan'],
              'errors.plan',
              ['Your plan project limit has been reached.'],
            ),
          ),
        );
      });

      test('a timeout is OfflineFailure', () async {
        answer(
          (o) => DioException(
            requestOptions: o,
            type: DioExceptionType.receiveTimeout,
          ),
        );

        await expectLater(
          api.createWallet(projectId: 1, clientUuid: 'c', name: 'W'),
          throwsA(isA<OfflineFailure>()),
        );
      });

      test('403 is a ServerFailure', () async {
        answer((_) => json({'message': 'Forbidden'}, status: 403));

        await expectLater(
          api.addBoqItem(
            projectId: 1,
            clientUuid: 'c',
            description: 'D',
            unit: 'cum',
            qty: '1.000',
            rate: '1.00',
          ),
          throwsA(
            isA<ServerFailure>().having((e) => e.statusCode, 'status', 403),
          ),
        );
      });
    });

    test('addWorker unwraps data', () async {
      answer((_) => json({'data': {'id': 5, 'name': 'A'}}, status: 201));

      final worker = await api.addWorker(
        clientUuid: 'c1',
        name: 'A',
        trade: 'Mason',
      );

      expect(worker['id'], 5);
    });
  });
  group('tenders', () {
    test('subcontractors reads every page the server has', () async {
      answer((options) {
        final int page = options.queryParameters['page'] as int;
        return json({
          'current_page': page,
          'last_page': 2,
          'data': [
            {'id': page, 'name': 'Contractor $page', 'phone': null},
          ],
        });
      });

      final rows = await api.subcontractors(query: 'con');

      expect(rows.map((r) => r['id']), [1, 2]);
      expect(adapter.requests.map((r) => r.queryParameters['q']), ['con', 'con']);
      expect(adapter.requests.first.path, '/contractors');
    });

    test('a quote goes as multipart, with each rate keyed by the tender line', () async {
      answer((_) => json({'data': {'id': 31, 'status': 'OPEN'}, 'bid_id': 41}, status: 201));

      final reply = await api.postTenderQuote(31, {
        'party_id': 7,
        'rates': {'310': '475.50'},
        'includes_material': 1,
        'remarks': null,
        'client_uuid': 'u1',
      });

      final FormData form = adapter.requests.single.data as FormData;
      final fields = {for (final f in form.fields) f.key: f.value};
      expect(adapter.requests.single.path, '/tenders/31/bids');
      expect(fields['rates[310]'], '475.50');
      expect(fields['party_id'], '7');
      expect(fields['includes_material'], '1');
      expect(fields.containsKey('remarks'), isFalse);
      expect(reply['bid_id'], 41);
    });

    test('an award keeps the work order that comes beside the tender', () async {
      answer((_) => json({
        'data': {'id': 31, 'status': 'AWARDED', 'work_order_id': 12},
        'work_order': {'id': 12, 'number': 'WO-12'},
      }, status: 201));

      final reply = await api.awardTender(31, {'bid_id': 41});

      expect((reply['work_order'] as Map)['id'], 12);
      expect((reply['data'] as Map)['status'], 'AWARDED');
    });
  });

  group('signing up', () {
    Future<LoginResult> register({String? phone}) => api.register(
          company: 'Deccan Builders',
          name: 'Asha Patil',
          email: 'asha@deccan.test',
          phone: phone,
          password: 'site-office-8',
          passwordConfirmation: 'site-office-8',
          deviceName: AppConfig.deviceName,
          locale: 'mr',
        );

    test('posts the form to /auth/register and reads the reply as a login', () async {
      answer((_) => json({'token': 'new-token', 'user': {'id': 9, 'role': 'owner'}}, status: 201));

      final LoginResult result = await register(phone: '98200 11111');

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/auth/register');
      expect(request.data, <String, Object?>{
        'company': 'Deccan Builders',
        'name': 'Asha Patil',
        'email': 'asha@deccan.test',
        'phone': '98200 11111',
        'password': 'site-office-8',
        'password_confirmation': 'site-office-8',
        'device_name': AppConfig.deviceName,
        'locale': 'mr',
      });
      expect(result.token, 'new-token');
      expect(result.user['role'], 'owner');
    });

    test('sends no plan, and no phone when none was given', () async {
      answer((_) => json({'token': 't', 'user': {'id': 9}}, status: 201));
      await register();
      final Map<String, dynamic> body = adapter.requests.single.data as Map<String, dynamic>;
      expect(body.containsKey('phone'), isFalse);
      expect(body.containsKey('plan'), isFalse);
    });

    test('a taken email is a ValidationFailure on email', () async {
      answer((_) => json({
            'message': 'This email already has an account.',
            'errors': {
              'email': ['This email already has an account.'],
            },
          }, status: 422));
      await expectLater(
        register(),
        throwsA(isA<ValidationFailure>().having((e) => e.errors.keys, 'fields', ['email'])),
      );
    });

    test('a 401 is not taken as an expired session', () async {
      answer((_) => json({'message': 'Unauthenticated.'}, status: 401));
      await expectLater(register(), throwsA(isA<ServerFailure>()));
      expect(unauthorizedCalls, 0);
    });
  });

  group('deleting an account', () {
    test('reads what would happen', () async {
      answer((_) => json({
            'data': {
              'mode': 'company',
              'company_name': 'Deccan Builders',
              'other_people': 3,
              'requires_confirm_name': true,
              'grace_days': 30,
              'erase_after': '2026-12-12T15:04:05+05:30',
              'pending': {'erase_after': '2026-12-12T15:04:05+05:30', 'can_cancel': true},
            },
          }));

      final AccountDeletionPreview preview = await api.accountDeletionPreview();

      expect(adapter.requests.single.method, 'GET');
      expect(adapter.requests.single.path, '/auth/account/deletion');
      expect(preview.mode, AccountDeletionMode.company);
      expect(preview.companyName, 'Deccan Builders');
      expect(preview.otherPeople, 3);
      expect(preview.requiresConfirmName, isTrue);
      expect(preview.graceDays, 30);
      expect(preview.eraseAfter, DateTime.utc(2026, 12, 12, 9, 34, 5));
      expect(preview.pending!.canCancel, isTrue);
    });

    test('a member with nothing pending', () async {
      answer((_) => json({
            'data': {
              'mode': 'account',
              'company_name': 'Deccan Builders',
              'other_people': 0,
              'requires_confirm_name': false,
              'grace_days': 30,
              'erase_after': null,
              'pending': null,
            },
          }));
      final AccountDeletionPreview preview = await api.accountDeletionPreview();
      expect(preview.mode, AccountDeletionMode.account);
      expect(preview.eraseAfter, isNull);
      expect(preview.pending, isNull);
    });

    test('a member is deleted with the password alone', () async {
      answer((_) => json({'data': {'result': 'deleted'}}));
      final AccountDeletionResult result =
          await api.deleteAccount(mode: AccountDeletionMode.account, password: 'pw', confirmName: 'ignored');

      final request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/auth/account/deletion');
      expect(request.data, <String, Object?>{'mode': 'account', 'password': 'pw'});
      expect(result.scheduled, isFalse);
    });

    test('the only owner sends the company name and gets the date', () async {
      answer((_) => json({'data': {'result': 'scheduled', 'erase_after': '2026-12-12T15:04:05+05:30'}}));
      final AccountDeletionResult result =
          await api.deleteAccount(mode: AccountDeletionMode.company, password: 'pw', confirmName: 'Deccan Builders');

      expect(adapter.requests.single.data, <String, Object?>{
        'mode': 'company',
        'password': 'pw',
        'confirm_name': 'Deccan Builders',
      });
      expect(result.scheduled, isTrue);
      expect(result.eraseAfter, DateTime.utc(2026, 12, 12, 9, 34, 5));
    });

    test('a changed mode is a 409 with its code', () async {
      answer((_) => json({'message': 'Changed.', 'code': 'deletion_mode_changed'}, status: 409));
      await expectLater(
        api.deleteAccount(mode: AccountDeletionMode.account, password: 'pw'),
        throwsA(isA<ServerFailure>()
            .having((e) => e.statusCode, 'status', 409)
            .having((e) => e.code, 'code', 'deletion_mode_changed')),
      );
    });

    test('a wrong password is a ValidationFailure on password', () async {
      answer((_) => json({
            'message': 'That password is not right.',
            'errors': {
              'password': ['That password is not right.'],
            },
          }, status: 422));
      await expectLater(
        api.deleteAccount(mode: AccountDeletionMode.account, password: 'pw'),
        throwsA(isA<ValidationFailure>().having((e) => e.errors.keys, 'fields', ['password'])),
      );
    });

    test('cancelling uses DELETE and says whether anything was pending', () async {
      answer((_) => json({'data': {'result': 'cancelled'}}));
      expect(await api.cancelCompanyDeletion(), isTrue);
      expect(adapter.requests.single.method, 'DELETE');
      expect(adapter.requests.single.path, '/auth/account/deletion');

      answer((_) => json({'data': {'result': 'none'}}));
      expect(await api.cancelCompanyDeletion(), isFalse);
    });

    test('a non-owner cancelling is refused with a 403', () async {
      answer((_) => json({'message': 'Only a company owner can do this.'}, status: 403));
      await expectLater(api.cancelCompanyDeletion(), throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 403)));
    });
  });
}

