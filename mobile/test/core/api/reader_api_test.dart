import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/dio_nirmaan_api.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';

typedef Responder = Object Function(RequestOptions options);

/// Answers requests with canned bodies, the way the server on feat/ai-features does.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.responder);

  final Responder responder;
  final List<RequestOptions> requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    await requestStream?.drain<void>();
    final Object answer = responder(options);
    if (answer is ResponseBody) return answer;
    throw answer;
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody reply(Object body, {int status = 200}) => ResponseBody.fromString(
  jsonEncode(body),
  status,
  headers: {
    Headers.contentTypeHeader: ['application/json'],
  },
);

/// The example status body from the spec (A5), with the fields the server adds.
Map<String, dynamic> runningBody() => <String, dynamic>{
  'id': 41,
  'kind': 'boq',
  'status': 'running',
  'pause_reason': null,
  'error': null,
  'message': null,
  'pages_total': 12,
  'pages_read': 7,
  'pages_failed': 1,
  'pages_left': 4,
  'stored_pages': <int>[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
  'credits_used': '38.2000',
  'credits_burned': '6.6000',
  'credits_cap': '96.0000',
  'eta_minutes': 6,
  'stalled': false,
  'can_cancel': true,
  'can_resume': false,
  'failed_pages': <Map<String, dynamic>>[
    <String, dynamic>{
      'id': 77,
      'page_no': 5,
      'page_to': null,
      'pass': 'rows',
      'part': 'full',
      'reason': 'cut_off',
      'can_retry': false,
      'can_split': true,
      'can_stronger': false,
      'needs_picture': false,
    },
  ],
  'left_out_pages': <Map<String, dynamic>>[],
};

void main() {
  late FakeAdapter adapter;
  late DioNirmaanApi api;
  late int unauthorizedCalls;

  void answer(Responder responder) {
    adapter = FakeAdapter(responder);
    unauthorizedCalls = 0;
    api = DioNirmaanApi(
      readToken: () async => 'secret-token',
      onUnauthorized: () => unauthorizedCalls++,
      baseUrl: 'https://example.test/api/v1',
      dio: Dio()..httpClientAdapter = adapter,
    );
  }

  setUp(() => answer((_) => reply(<String, dynamic>{})));

  group('reader availability', () {
    test('reads what may be offered to this person', () async {
      answer(
        (_) => reply(<String, dynamic>{
          'released': true,
          'connected': true,
          'plan': true,
          'consent': {'enabled': true, 'version': 'ai-docs-v1', 'current': true},
          'can': {'boq_scan': true, 'slip_challan': true, 'voice_dpr': false},
          'credits_free': null,
          'voice': {'on_device_only': true},
        }),
      );

      final ReaderAvailability status = await api.readerAvailability();

      expect(adapter.requests.single.path, '/document-reading/status');
      expect(adapter.requests.single.method, 'GET');
      expect(adapter.requests.single.headers['Authorization'], 'Bearer secret-token');
      expect(status.offers(ReaderFeature.slipChallan), isTrue);
      expect(status.offers(ReaderFeature.boqScan), isTrue);
      expect(status.offers(ReaderFeature.voiceDpr), isFalse);
      expect(status.needsConsent(ReaderFeature.slipChallan), isFalse);
      expect(status.consent.version, 'ai-docs-v1');
      expect(status.creditsFree, isNull);
      expect(status.voiceOnDeviceOnly, isTrue);
    });

    test('offers nothing until the readers are released and connected', () async {
      answer(
        (_) => reply(<String, dynamic>{
          'released': false,
          'connected': true,
          'plan': true,
          'consent': {'enabled': false, 'version': null, 'current': false},
          'can': {'boq_scan': true, 'slip_challan': true, 'voice_dpr': true},
          'credits_free': '412.5000',
          'voice': {'on_device_only': true},
        }),
      );

      final ReaderAvailability status = await api.readerAvailability();

      expect(status.offers(ReaderFeature.slipChallan), isFalse);
      expect(status.creditsFree, '412.5000');
    });

    test('shows the button but asks for the notice when the company has not agreed', () {
      final ReaderAvailability status = ReaderAvailability.fromJson(<String, dynamic>{
        'released': true,
        'connected': true,
        'plan': true,
        'consent': {'enabled': false, 'version': 'ai-docs-v1', 'current': false},
        'can': {'slip_challan': true},
      });

      expect(status.offers(ReaderFeature.slipChallan), isTrue);
      expect(status.needsConsent(ReaderFeature.slipChallan), isTrue);
      // An answer without the voice block never offers voice.
      expect(status.voiceOnDeviceOnly, isFalse);
    });
  });

  group('a read', () {
    test('reads the status body', () async {
      answer((_) => reply(runningBody()));

      final DocumentReadStatus read = await api.documentRead(41);

      expect(adapter.requests.single.path, '/document-reads/41');
      expect(read.isWorking, isTrue);
      expect(read.isFinished, isFalse);
      expect(read.pagesLeft, 4);
      expect(read.creditsUsed, '38.2000');
      expect(read.etaMinutes, 6);
      expect(read.canCancel, isTrue);
      expect(read.failedPages.single.id, 77);
      expect(read.failedPages.single.canSplit, isTrue);
      expect(read.failedPages.single.reason, 'cut_off');
      expect(read.missingPages(12), isEmpty);
    });

    test('lists the pages still to send after an interrupted upload', () {
      final DocumentReadStatus read = DocumentReadStatus.fromJson(<String, dynamic>{
        'id': 3,
        'kind': 'slip',
        'status': 'draft',
        'stored_pages': <int>[1, 3],
      });

      expect(read.missingPages(4), <int>[2, 4]);
      expect(read.isWorking, isFalse);
      expect(read.failedPages, isEmpty);
    });

    test('sends a page as multipart with its number and part', () async {
      final Directory dir = await Directory.systemTemp.createTemp('reader_api_test');
      addTearDown(() => dir.delete(recursive: true));
      final File photo = File('${dir.path}/page-2.jpg')..writeAsBytesSync(<int>[0xFF, 0xD8, 0xFF, 0xD9]);
      answer(
        (_) => reply(<String, dynamic>{
          'id': 9,
          'page_no': 2,
          'part': 'top',
          'width': 1622,
          'height': 2296,
          'tokens': 4756,
        }, status: 201),
      );

      final StoredReadPage page = await api.uploadReadPage(41, pageNo: 2, filePath: photo.path, part: ReadPagePart.top);

      final RequestOptions request = adapter.requests.single;
      expect(request.method, 'POST');
      expect(request.path, '/document-reads/41/pages');
      final FormData form = request.data as FormData;
      expect(Map<String, String>.fromEntries(form.fields), {'page_no': '2', 'part': 'top'});
      expect(form.files.single.key, 'image');
      expect(form.files.single.value.filename, 'page-2.jpg');
      expect(page.tokens, 4756);
      expect(page.width, 1622);
    });

    test('a refused picture keeps its code and numbers', () async {
      final Directory dir = await Directory.systemTemp.createTemp('reader_api_test');
      addTearDown(() => dir.delete(recursive: true));
      final File photo = File('${dir.path}/page-1.jpg')..writeAsBytesSync(<int>[0xFF, 0xD8, 0xFF, 0xD9]);
      answer(
        (_) => reply(<String, dynamic>{
          'code': 'image_too_big',
          'fits': {'width': 1568, 'height': 1568},
          'message': 'This picture is too large to read.',
        }, status: 422),
      );

      await expectLater(
        api.uploadReadPage(41, pageNo: 1, filePath: photo.path),
        throwsA(
          isA<ValidationFailure>()
              .having((f) => f.code, 'code', 'image_too_big')
              .having((f) => (f.data!['fits'] as Map)['width'], 'fits.width', 1568)
              .having((f) => f.errors, 'errors', isEmpty),
        ),
      );
    });

    test('a field error is still a plain validation failure', () async {
      answer(
        (_) => reply(<String, dynamic>{
          'message': 'The page no field is required.',
          'errors': {
            'page_no': ['The page no field is required.'],
          },
        }, status: 422),
      );

      await expectLater(
        api.quoteRead(41),
        throwsA(
          isA<ValidationFailure>().having((f) => f.code, 'code', isNull).having((f) => f.errors.keys, 'keys', ['page_no']),
        ),
      );
    });

    test('asks the price for a range of pages', () async {
      answer(
        (_) => reply(<String, dynamic>{
          'pages': 12,
          'estimate': '81.6000',
          'cap': '131.0000',
          'cap_floor': '98.0000',
          'company_max': '650.0000',
          'rupees': '81.60',
          'minutes': 19,
          'free': '412.5000',
          'enough': true,
          'pages_that_fit': 12,
          'basis': 'default',
        }),
      );

      final ReadQuote quote = await api.quoteRead(41, pageFrom: 1, pageTo: 12);

      expect(adapter.requests.single.path, '/document-reads/41/quote');
      expect(adapter.requests.single.data, {'page_from': 1, 'page_to': 12});
      expect(quote.cap, '131.0000');
      expect(quote.rupees, '81.60');
      expect(quote.minutes, 19);
      expect(quote.enough, isTrue);
    });

    test('a quote for every page sends no range', () async {
      answer((_) => reply(<String, dynamic>{'pages': 1, 'estimate': '6.0000', 'cap': '10.0000', 'enough': false}));

      final ReadQuote quote = await api.quoteRead(41);

      expect(adapter.requests.single.data, <String, dynamic>{});
      expect(quote.rupees, isNull);
      expect(quote.free, isNull);
      expect(quote.enough, isFalse);
    });

    test('starts with the cap from the quote and answers with the status', () async {
      answer((_) => reply(runningBody(), status: 202));

      final DocumentReadStatus read = await api.startRead(41, cap: '131.0000', pageFrom: 1, pageTo: 12, skipPages: <int>[4]);

      expect(adapter.requests.single.path, '/document-reads/41/start');
      expect(adapter.requests.single.data, {
        'cap': '131.0000',
        'page_from': 1,
        'page_to': 12,
        'skip_pages': [4],
      });
      expect(read.status, ReadState.running);
    });

    test('resume, retry, split and cancel post to their own paths', () async {
      answer((_) => reply(runningBody(), status: 202));

      await api.resumeRead(41, cap: '50.0000');
      await api.retryReadPage(41, 77, stronger: true);
      await api.splitReadPage(41, 77);
      await api.cancelRead(41);

      expect(adapter.requests.map((r) => '${r.method} ${r.path}'), [
        'POST /document-reads/41/resume',
        'POST /document-reads/41/pages/77/retry',
        'POST /document-reads/41/pages/77/split',
        'POST /document-reads/41/cancel',
      ]);
      expect(adapter.requests[0].data, {'cap': '50.0000'});
      expect(adapter.requests[1].data, {'stronger': true});
      expect(adapter.requests[2].data, <String, dynamic>{});
    });

    test('fetches a stored page picture', () async {
      answer(
        (_) => ResponseBody.fromBytes(<int>[0xFF, 0xD8, 0xFF, 0xD9], 200, headers: {
          Headers.contentTypeHeader: ['image/jpeg'],
        }),
      );

      final Uint8List bytes = await api.readPageImage(41, 77);

      expect(adapter.requests.single.path, '/document-reads/41/pages/77/image');
      expect(bytes, [0xFF, 0xD8, 0xFF, 0xD9]);
    });
  });

  group('refusals keep the server code', () {
    test('a company that has not agreed to the notice', () async {
      answer(
        (_) => reply(<String, dynamic>{'code': 'consent_required', 'message': 'Your company has not agreed.'}, status: 409),
      );

      await expectLater(
        api.startRead(41, cap: '10.0000'),
        throwsA(isA<ServerFailure>().having((f) => f.statusCode, 'status', 409).having((f) => f.code, 'code', 'consent_required')),
      );
    });

    test('no credits is a 402 with its code', () async {
      answer((_) => reply(<String, dynamic>{'code': 'no_credits', 'message': 'No credits left.'}, status: 402));

      await expectLater(
        api.startRead(41),
        throwsA(isA<ServerFailure>().having((f) => f.statusCode, 'status', 402).having((f) => f.code, 'code', 'no_credits')),
      );
    });

    test('pages missing lists the pages', () async {
      answer(
        (_) => reply(<String, dynamic>{
          'code': 'pages_missing',
          'missing': [4, 9],
          'message': 'Some pages are not uploaded yet.',
        }, status: 409),
      );

      await expectLater(
        api.startRead(41),
        throwsA(isA<ServerFailure>().having((f) => f.data!['missing'], 'missing', [4, 9])),
      );
    });

    test('a 401 ends the session like any other request', () async {
      answer((_) => reply(<String, dynamic>{'message': 'Unauthenticated.'}, status: 401));

      await expectLater(api.documentRead(41), throwsA(isA<AuthExpired>()));
      expect(unauthorizedCalls, 1);
    });
  });
}
