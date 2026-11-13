import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';

import 'dio_nirmaan_api_test.dart' show FakeAdapter, Responder, json;

/// Floor set-up on the wire: the verbs, paths and bodies docs/CONTRACT.md names.
void main() {
  late FakeAdapter adapter;
  late DioNirmaanApi api;

  void answer(Responder responder) {
    adapter = FakeAdapter(responder);
    api = DioNirmaanApi(
      readToken: () async => 'secret-token',
      onUnauthorized: () {},
      baseUrl: 'https://example.test/api/v1',
      dio: Dio()..httpClientAdapter = adapter,
    );
  }

  const Map<String, dynamic> floor = <String, dynamic>{'id': 7, 'client_uuid': 'u-1', 'project_id': 3, 'name': 'Ground', 'parent_id': null, 'sort': 1};

  test('a floor is a POST with its name, parent and client_uuid', () async {
    answer((_) => json(<String, dynamic>{'data': floor}, status: 201));

    final Map<String, dynamic> reply = await api.addFloor(3, name: 'Flat 1', parentId: 7, clientUuid: 'u-1');

    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'POST');
    expect(request.uri.path, '/api/v1/projects/3/floors');
    expect(request.data, <String, dynamic>{'name': 'Flat 1', 'parent_id': 7, 'client_uuid': 'u-1'});
    expect(reply, floor);
  });

  test('a rename is a PATCH of the name alone, so the floor stays where it is', () async {
    answer((_) => json(<String, dynamic>{'data': floor}));

    await api.renameFloor(7, name: 'Ground floor');

    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'PATCH');
    expect(request.uri.path, '/api/v1/floors/7');
    expect(request.data, <String, dynamic>{'name': 'Ground floor'});
  });

  test('a planned quantity is a PUT with the quantity as a string', () async {
    answer((_) => json(<String, dynamic>{'data': <String, dynamic>{...floor, 'lines': <Object>[]}}));

    final Map<String, dynamic> reply = await api.setFloorTarget(7, boqItemId: 5, plannedQty: '12.500');

    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'PUT');
    expect(request.uri.path, '/api/v1/floors/7/target');
    expect(request.data, <String, dynamic>{'boq_item_id': 5, 'planned_qty': '12.500'});
    expect(reply['lines'], isEmpty);
  });

  test('sharing sends the floors with either all lines or the chosen ones', () async {
    answer((_) => json(<String, dynamic>{'data': <String, dynamic>{'count': 2, 'floors': <Object>[]}}));

    final Map<String, dynamic> reply = await api.splitFloors(3, floorIds: <int>[7, 8], boqItemIds: <int>[5, 6]);
    await api.splitFloors(3, floorIds: <int>[7], allItems: true);

    expect(adapter.requests.first.method, 'POST');
    expect(adapter.requests.first.uri.path, '/api/v1/projects/3/floors/split');
    expect(adapter.requests.first.data, <String, dynamic>{'floor_ids': <int>[7, 8], 'boq_item_ids': <int>[5, 6]});
    expect(adapter.requests.last.data, <String, dynamic>{'floor_ids': <int>[7], 'all_items': true});
    expect(reply['count'], 2);
  });

  test('removing is a DELETE, and a refusal keeps its status and words', () async {
    answer((_) => json(<String, dynamic>{'data': <String, dynamic>{'id': 7, 'deleted': true}}));
    await api.deleteFloor(7);
    expect(adapter.requests.single.method, 'DELETE');
    expect(adapter.requests.single.uri.path, '/api/v1/floors/7');

    answer((_) => json(<String, dynamic>{'message': 'Only the company owner can remove a floor.'}, status: 403));
    await expectLater(
      api.deleteFloor(7),
      throwsA(isA<ServerFailure>().having((f) => f.statusCode, 'status', 403).having((f) => f.message, 'message', contains('owner'))),
    );

    answer(
      (_) => json(<String, dynamic>{
        'message': 'Work has been recorded on this floor, so it cannot be removed.',
        'errors': <String, dynamic>{
          'floor': <String>['Work has been recorded on this floor, so it cannot be removed.'],
        },
      }, status: 422),
    );
    await expectLater(
      api.deleteFloor(7),
      throwsA(isA<ValidationFailure>().having((f) => f.errors['floor']?.single, 'floor', contains('Work has been recorded'))),
    );
  });

  test('the time frame sends all three, with null for one that is cleared', () async {
    answer((_) => json(<String, dynamic>{'data': <String, dynamic>{'start_date': '2026-12-01', 'planned_days': 90, 'deadline_date': null}}));

    final Map<String, dynamic> reply = await api.setProgressTimeFrame(3, startDate: '2026-12-01', plannedDays: 90);

    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'PATCH');
    expect(request.uri.path, '/api/v1/projects/3/progress/time-frame');
    expect(request.data, <String, dynamic>{'start_date': '2026-12-01', 'planned_days': 90, 'deadline_date': null});
    expect(reply['planned_days'], 90);
  });

  test('the week is a GET, with the day only when one is asked for', () async {
    answer((_) => json(<String, dynamic>{'data': <String, dynamic>{'start': '2026-11-09', 'end': '2026-11-15'}}));

    await api.progressWeek(3);
    final Map<String, dynamic> reply = await api.progressWeek(3, date: '2026-11-02');

    expect(adapter.requests.first.method, 'GET');
    expect(adapter.requests.first.uri.path, '/api/v1/projects/3/progress/week');
    expect(adapter.requests.first.uri.queryParameters, isEmpty);
    expect(adapter.requests.last.uri.queryParameters, <String, String>{'date': '2026-11-02'});
    expect(reply['start'], '2026-11-09');
  });
}
