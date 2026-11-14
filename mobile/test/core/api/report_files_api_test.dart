import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';

/// Answers each request with what [answer] gives, and keeps the requests.
class _Adapter implements HttpClientAdapter {
  _Adapter(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final List<RequestOptions> requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    await requestStream?.drain<void>();
    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Object body, int status) => ResponseBody.fromString(
  jsonEncode(body),
  status,
  headers: <String, List<String>>{
    Headers.contentTypeHeader: <String>['application/json'],
  },
);

void main() {
  late _Adapter adapter;
  late DioNirmaanApi api;

  void answer(ResponseBody Function(RequestOptions options) responder) {
    adapter = _Adapter(responder);
    api = DioNirmaanApi(
      readToken: () async => 'secret-token',
      onUnauthorized: () {},
      baseUrl: 'https://example.test/api/v1',
      dio: Dio()..httpClientAdapter = adapter,
    );
  }

  final List<int> pdf = utf8.encode('%PDF-1.4 x');

  test('the DPR PDF comes back as bytes, with the token', () async {
    answer((_) => ResponseBody.fromBytes(pdf, 200, headers: <String, List<String>>{Headers.contentTypeHeader: <String>['application/pdf']}));

    expect(await api.dprPdfBytes(41), pdf);
    expect(adapter.requests.single.uri.toString(), 'https://example.test/api/v1/dprs/41/pdf');
    expect(adapter.requests.single.method, 'GET');
    expect(adapter.requests.single.headers['Authorization'], 'Bearer secret-token');
  });

  test('the share link is a POST carrying the app language', () async {
    answer((_) => _json(<String, dynamic>{
      'data': <String, dynamic>{'url': 'https://example.test/shared/dprs/41?expires=1&signature=a', 'text': 'Report\nhttps://example.test/shared/dprs/41?expires=1&signature=a', 'link_days': 7},
    }, 200));

    final Map<String, dynamic> link = await api.dprShareLink(41, locale: 'hi');

    expect(link['link_days'], 7);
    final RequestOptions request = adapter.requests.single;
    expect(request.method, 'POST');
    expect(request.uri.toString(), 'https://example.test/api/v1/dprs/41/share-link');
    expect(request.data, <String, dynamic>{'locale': 'hi'});
  });

  test('no locale means none is sent', () async {
    answer((_) => _json(<String, dynamic>{'data': <String, dynamic>{'url': 'u', 'text': 't'}}, 200));

    await api.dprShareLink(41);

    expect(adapter.requests.single.data, <String, dynamic>{});
  });

  test('the measurement sheet sends the period', () async {
    answer((_) => ResponseBody.fromBytes(pdf, 200));

    expect(await api.measurementSheetBytes(3, from: '2026-11-01', to: '2026-11-09'), pdf);
    expect(
      adapter.requests.single.uri.toString(),
      'https://example.test/api/v1/projects/3/measurement-sheet.pdf?from=2026-11-01&to=2026-11-09',
    );
  });

  test('a 422 on the sheet is read as JSON even though bytes were asked for', () async {
    answer((_) => _json(<String, dynamic>{
      'message': 'This period has more than 600 measurements. Choose a shorter period.',
      'errors': <String, dynamic>{'to': <String>['This period has more than 600 measurements. Choose a shorter period.']},
    }, 422));

    await expectLater(
      api.measurementSheetBytes(3, from: '2020-01-01', to: '2026-11-09'),
      throwsA(isA<ValidationFailure>().having((e) => e.errors['to'], 'errors.to', isNotEmpty)),
    );
  });

  test('a refusal keeps its status', () async {
    answer((_) => _json(<String, dynamic>{'message': 'This module is not included in your plan.'}, 403));

    await expectLater(
      api.dprPdfBytes(41),
      throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 403).having((e) => e.message, 'message', contains('not included'))),
    );
  });

  test('a body that is not JSON still fails cleanly', () async {
    answer((_) => ResponseBody.fromBytes(utf8.encode('<html>bad gateway</html>'), 502));

    await expectLater(api.dprPdfBytes(41), throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 502)));
  });
}
