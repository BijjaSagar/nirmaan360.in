// What POST /approvals/{id}/act carries: `override_reason` only when the
// owner gives one for a contractor payment ahead of the work done.
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';

import 'dio_nirmaan_api_test.dart' show FakeAdapter, json;

void main() {
  late FakeAdapter adapter;
  late DioNirmaanApi api;

  void answer(Object Function(RequestOptions options) responder) {
    adapter = FakeAdapter(responder);
    api = DioNirmaanApi(
      readToken: () async => 'secret-token',
      onUnauthorized: () {},
      baseUrl: 'https://example.test/api/v1',
      dio: Dio()..httpClientAdapter = adapter,
    );
  }

  Map<String, dynamic> body() => (adapter.requests.single.data as Map).cast<String, dynamic>();

  test('an ordinary decision has no override_reason key at all', () async {
    for (final (String action, String? remark) in <(String, String?)>[
      ('approve', null),
      ('return', 'Attach the bill'),
      ('reject', 'Already paid'),
    ]) {
      answer(
        (_) => json({
          'data': <String, dynamic>{'id': 7},
        }),
      );
      await api.actOnApproval(7, action: action, clientUuid: 'c1', remark: remark);

      expect(body(), <String, dynamic>{'action': action, 'remark': remark, 'client_uuid': 'c1'});
      expect(body().containsKey('override_reason'), isFalse, reason: action);
    }
  });

  test('the owner\'s reason goes as override_reason', () async {
    answer(
      (_) => json({
        'data': <String, dynamic>{'id': 7, 'status': 'APPROVED'},
      }),
    );
    await api.actOnApproval(
      7,
      action: 'approve',
      clientUuid: 'c1',
      overrideReason: 'Steel has to be bought before Monday.',
    );

    expect(adapter.requests.single.uri.path, '/api/v1/approvals/7/act');
    expect(body()['override_reason'], 'Steel has to be bought before Monday.');
    expect(jsonEncode(body()), contains('"action":"approve"'));
  });

  test('a 422 on override_reason is a ValidationFailure carrying the field and its message', () async {
    const String blocked = 'Only the owner can approve it, with a written reason.';
    answer(
      (_) => json({
        'message': blocked,
        'errors': {
          'override_reason': [blocked],
        },
      }, status: 422),
    );

    await expectLater(
      api.actOnApproval(7, action: 'approve', clientUuid: 'c1'),
      throwsA(
        isA<ValidationFailure>()
            .having((failure) => failure.errors['override_reason'], 'field errors', <String>[blocked])
            .having((failure) => failure.message, 'message', blocked),
      ),
    );
  });
}
