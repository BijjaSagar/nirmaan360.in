import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/sync/failures.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';

import '../../../support/test_app.dart';

MeasurementInput entry({
  String nos = '2.000',
  String? length = '10.000',
  bool deduction = false,
  String measuredOn = '2026-11-09',
}) => MeasurementInput(
  projectId: 1,
  boqItemId: 1,
  measuredOn: measuredOn,
  nos: Qty.parse(nos),
  length: length == null ? null : Qty.parse(length),
  isDeduction: deduction,
);

void main() {
  late TestApp app;
  late MeasurementRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.measurements;
  });

  tearDown(() => app.dispose());

  test('create writes the row and outbox together with a local qty', () async {
    final uuid = await repo.create(entry());

    final row = (await repo.watchOne(uuid).first)!;
    expect(row.record.qty, Qty.parse('20.000'));
    expect(row.syncState, LocalSyncState.queued);
    expect(await app.db.select(app.db.outbox).get(), hasLength(1));
  });

  test('the local preview is replaced by the server figure', () async {
    final uuid = await repo.create(entry());
    await app.sync();

    final serverRow = app.api.measurementByUuid(uuid);
    expect(serverRow['qty'], '20.000');
    app.api.setServerStatus(serverRow, 'DRAFT');
    serverRow['qty'] = '19.000';
    await app.sync();

    expect((await repo.watchOne(uuid).first)!.record.qty, Qty.parse('19.000'));
  });

  test('a deduction is negative', () async {
    final uuid = await repo.create(entry(deduction: true));
    expect((await repo.watchOne(uuid).first)!.record.qty.isNegative, isTrue);
  });

  test('refuses nos or dimensions the server would refuse', () async {
    expect(() => repo.create(entry(nos: '0')), throwsA(isA<ValidationFailure>()));
    expect(() => repo.create(entry(length: '0')), throwsA(isA<ValidationFailure>()));
    expect(await app.db.select(app.db.outbox).get(), isEmpty);
  });

  test('sendForApproval goes after the entry and reaches IN_REVIEW', () async {
    final uuid = await repo.create(entry());
    await repo.sendForApproval(uuid);

    final types = (await app.db.select(app.db.outbox).get()).map((r) => r.type);
    expect(types, [OutboxType.measurement, OutboxType.measurementSubmit]);

    await app.sync();

    expect(app.api.measurementByUuid(uuid)['status'], 'IN_REVIEW');
    expect((await repo.watchOne(uuid).first)!.record.status, DocStatus.inReview);
  });

  test('sendForApproval twice queues once', () async {
    final uuid = await repo.create(entry());
    await repo.sendForApproval(uuid);
    await repo.sendForApproval(uuid);
    expect(await app.db.select(app.db.outbox).get(), hasLength(2));
  });

  test('editing an unsent entry coalesces into its pending row', () async {
    final uuid = await repo.create(entry(nos: '1.000'));
    await repo.edit(uuid, entry(nos: '5.000'));

    expect(await app.db.select(app.db.outbox).get(), hasLength(1));

    await app.sync();
    expect(app.api.measurementByUuid(uuid)['nos'], '5.000');
  });

  test('a RETURNED entry can be edited and sent again', () async {
    final uuid = await repo.create(entry());
    await repo.sendForApproval(uuid);
    await app.sync();
    app.api.setServerStatus(app.api.measurementByUuid(uuid), 'RETURNED', returnRemark: 'Check');
    await app.sync();

    await repo.edit(uuid, entry(nos: '3.000'));
    await repo.sendForApproval(uuid);
    await app.sync();

    expect(app.api.measurements, hasLength(1));
    expect(app.api.measurementByUuid(uuid)['nos'], '3.000');
    expect(app.api.measurementByUuid(uuid)['status'], 'IN_REVIEW');
  });

  test('an IN_REVIEW entry is locked', () async {
    final uuid = await repo.create(entry());
    await repo.sendForApproval(uuid);
    await app.sync();

    await expectLater(repo.edit(uuid, entry()), throwsA(isA<RecordLocked>()));
  });

  test('watch by project filters by status', () async {
    final draft = await repo.create(entry());
    final sent = await repo.create(entry());
    await repo.sendForApproval(sent);
    await app.sync();

    final all = await repo.watchByProject(1).first;
    final inReview = await repo.watchByProject(1, status: DocStatus.inReview).first;

    expect(all, hasLength(2));
    expect(inReview.single.record.clientUuid, sent);
    expect(draft, isNot(sent));
  });
}
