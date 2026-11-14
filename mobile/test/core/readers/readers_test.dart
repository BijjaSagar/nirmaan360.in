import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/readers/read_watch.dart';
import 'package:nirmaan360/core/session/current_user.dart';

import '../../support/test_app.dart';

ReaderAvailability slipsOn() => ReaderAvailability.fromJson(<String, dynamic>{
  'released': true,
  'connected': true,
  'plan': true,
  'consent': {'enabled': true, 'version': 'ai-docs-v1', 'current': true},
  'can': {'slip_challan': true},
  'voice': {'on_device_only': true},
});

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create(seed: false);
    addTearDown(app.dispose);
    app.api.readerAvailabilityBody = slipsOn();
  });

  test('asks the server once and keeps the answer for five minutes', () async {
    final ReaderAvailability first = await app.services.readers.availability();
    app.clock.advance(const Duration(minutes: 4));
    await app.services.readers.availability();

    expect(first.offers(ReaderFeature.slipChallan), isTrue);
    expect(app.api.readerAvailabilityReads, 1);

    app.clock.advance(const Duration(minutes: 2));
    await app.services.readers.availability();
    expect(app.api.readerAvailabilityReads, 2);
  });

  test('pull to refresh asks again', () async {
    await app.services.readers.availability();
    await app.services.readers.availability(refresh: true);

    expect(app.api.readerAvailabilityReads, 2);
  });

  test('offline, nothing is offered and the server is not asked', () async {
    app.connectivity.setOnline(false);

    final ReaderAvailability status = await app.services.readers.availability();

    expect(status.offers(ReaderFeature.slipChallan), isFalse);
    expect(app.api.readerAvailabilityReads, 0);
  });

  test('when the server cannot be asked, nothing is offered', () async {
    app.api.failNextReaderCall(const ServerFailure('Server error.', statusCode: 500));

    final ReaderAvailability status = await app.services.readers.availability();

    expect(status.offers(ReaderFeature.slipChallan), isFalse);
  });

  test('a contractor login is never offered a reader', () async {
    await app.services.session.startSession(
      'contractor-token',
      CurrentUser.fromJson(<String, dynamic>{...app.api.user, 'kind': CurrentUser.contractorKind}),
    );

    final ReaderAvailability status = await app.services.readers.availability();

    expect(status.offers(ReaderFeature.slipChallan), isFalse);
    expect(app.api.readerAvailabilityReads, 0);
  });

  test('a sign-out drops the kept answer', () async {
    await app.services.readers.availability();
    await app.services.auth.signOut();
    await pumpEventQueue();

    final ReaderAvailability signedOut = await app.services.readers.availability();
    expect(signedOut.offers(ReaderFeature.slipChallan), isFalse);

    await app.services.session.startSession('fake-token', CurrentUser.fromJson(app.api.user));
    await pumpEventQueue();
    await app.services.readers.availability();
    expect(app.api.readerAvailabilityReads, 2);
  });

  test('watch reports the read until it is ready', () async {
    app.api.readStatusScript[5] = <Map<String, dynamic>>[
      <String, dynamic>{'kind': 'slip', 'status': 'ready', 'pages_total': 1, 'pages_read': 1},
    ];

    final List<ReadWatchEvent> events = await app.services.readers.watch(5).toList();

    expect((events.last as ReadWatchEnded).reason, ReadWatchStop.settled);
    expect(app.api.readerCalls, ['status 5']);
  });
}
