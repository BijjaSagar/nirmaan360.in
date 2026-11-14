import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';

import 'dio_nirmaan_api_test.dart' show FakeAdapter, Responder, json;

/// `PATCH /boq-items/{item}` and `DELETE /boq-items/{item}` on the wire.
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

  const Map<String, dynamic> row = <String, dynamic>{'id': 5, 'code': '2.1', 'qty': '650.000'};

  Future<Map<String, dynamic>> edit({String? rate = '7600.00', String? updatedAt = '2026-11-09T10:00:00.000Z'}) {
    return api.updateBoqItem(
      boqItemId: 5,
      code: '2.1',
      description: 'RCC M30 in slab',
      unit: 'cum',
      qty: '650.000',
      rate: rate,
      reason: 'Revised drawing',
      updatedAt: updatedAt,
    );
  }

  test('an edit is a PATCH of the line with decimals as strings, and returns the row', () async {
    answer((_) => json(<String, dynamic>{'data': row}));

    final Map<String, dynamic> reply = await edit();

    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'PATCH');
    expect(request.uri.path, '/api/v1/boq-items/5');
    expect(request.data, <String, dynamic>{
      'code': '2.1',
      'description': 'RCC M30 in slab',
      'unit': 'cum',
      'qty': '650.000',
      'rate': '7600.00',
      'reason': 'Revised drawing',
      'updated_at': '2026-11-09T10:00:00.000Z',
    });
    expect(reply, row);
  });

  test('no rate and no opening time leave those keys out', () async {
    answer((_) => json(<String, dynamic>{'data': row}));

    await edit(rate: null, updatedAt: null);

    final Map<dynamic, dynamic> sent = adapter.requests.single.data as Map<dynamic, dynamic>;
    expect(sent.containsKey('rate'), isFalse);
    expect(sent.containsKey('updated_at'), isFalse);
  });

  test('a line changed since it was opened is a 409 carrying the line as it is now', () async {
    answer(
      (_) => json(<String, dynamic>{'message': 'Someone changed this BOQ item.', 'code': 'changed', 'data': row}, status: 409),
    );

    await expectLater(
      edit(),
      throwsA(
        isA<ServerFailure>()
            .having((e) => e.statusCode, 'status', 409)
            .having((e) => e.code, 'code', 'changed')
            .having((e) => e.data?['data'], 'data.data', row),
      ),
    );
  });

  test('a refused quantity is a ValidationFailure on qty', () async {
    answer(
      (_) => json(<String, dynamic>{
        'message': 'Quantity cannot be lower than the quantity already measured (50.000).',
        'errors': <String, dynamic>{
          'qty': <String>['Quantity cannot be lower than the quantity already measured (50.000).'],
        },
      }, status: 422),
    );

    await expectLater(
      edit(),
      throwsA(isA<ValidationFailure>().having((e) => e.errors['qty'], 'qty', hasLength(1))),
    );
  });

  test('a delete is a DELETE of the line with the reason', () async {
    answer((_) => json(<String, dynamic>{'data': <String, dynamic>{'id': 5, 'deleted': true}}));

    await api.deleteBoqItem(boqItemId: 5, reason: 'Added twice');

    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'DELETE');
    expect(request.uri.path, '/api/v1/boq-items/5');
    expect(request.data, <String, dynamic>{'reason': 'Added twice'});
  });

  test('a delete by anyone but the owner is a 403 with the server words', () async {
    answer((_) => json(<String, dynamic>{'message': 'Only the company owner can delete a BOQ item.'}, status: 403));

    await expectLater(
      api.deleteBoqItem(boqItemId: 5, reason: 'Added twice'),
      throwsA(
        isA<ServerFailure>()
            .having((e) => e.statusCode, 'status', 403)
            .having((e) => e.message, 'message', 'Only the company owner can delete a BOQ item.'),
      ),
    );
  });

  test('a line with records against it is a 422 naming each under boq', () async {
    answer(
      (_) => json(<String, dynamic>{
        'message': 'This item has measurements and cannot be removed. (and 1 more error)',
        'errors': <String, dynamic>{
          'boq': <String>[
            'This item has measurements and cannot be removed.',
            'This item is in a tender and cannot be removed.',
          ],
        },
      }, status: 422),
    );

    await expectLater(
      api.deleteBoqItem(boqItemId: 5, reason: 'Added twice'),
      throwsA(isA<ValidationFailure>().having((e) => e.errors['boq'], 'boq', hasLength(2))),
    );
  });

  test('no network is an OfflineFailure', () async {
    answer((o) => DioException(requestOptions: o, type: DioExceptionType.connectionError));

    await expectLater(api.deleteBoqItem(boqItemId: 5, reason: 'Added twice'), throwsA(isA<OfflineFailure>()));
    await expectLater(edit(), throwsA(isA<OfflineFailure>()));
  });
}
