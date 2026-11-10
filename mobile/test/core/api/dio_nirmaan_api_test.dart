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
}
