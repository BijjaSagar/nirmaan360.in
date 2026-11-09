import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/api/nirmaan_api.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/features/approvals/data/approval_repository.dart';

import '../../../support/test_app.dart';

Map<String, dynamic> approval(int id, {bool canAct = true}) => {
  'id': id,
  'doc_type': 'measurement',
  'status': 'IN_REVIEW',
  'title': 'Measurement · 2.1 · 15.000 cum',
  'project': {'id': 1, 'code': 'KT-B', 'name': 'Kalyani Tower B'},
  'submitted_by': {'id': 4, 'name': 'Sachin More'},
  'submitted_at': '2026-11-09T09:12:00+05:30',
  'current_level': 1,
  'levels': ['qs', 'pm'],
  'current_role': 'qs',
  'current_role_label': 'Estimator / QS',
  'can_act': canAct,
  'document': {'id': 3, 'qty': '15.000'},
  'steps': [
    {'level': 0, 'role': 'engineer', 'action': 'submit'},
  ],
};

void main() {
  late TestApp app;
  late ApprovalRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.approvals;
    app.api.approvalInbox.add(approval(7));
    await app.sync();
  });

  tearDown(() => app.dispose());

  test('inbox is cached from the pull and readable offline', () async {
    app.api.offline = true;

    final items = await repo.watchInbox().first;

    expect(items.single.docType, 'measurement');
    expect(items.single.projectCode, 'KT-B');
    expect(items.single.document['qty'], '15.000');
    expect(items.single.levels, ['qs', 'pm']);
  });

  test('refresh replaces the inbox', () async {
    app.api.approvalInbox
      ..clear()
      ..add(approval(8));

    await repo.refresh();

    expect((await repo.watchInbox().first).single.id, 8);
  });

  test('refresh raises OfflineFailure when offline', () async {
    app.api.offline = true;
    await expectLater(repo.refresh(), throwsA(isA<OfflineFailure>()));
  });

  test('approve removes the item', () async {
    await repo.approve(7, remark: 'OK');

    expect(await repo.watchInbox().first, isEmpty);
    expect(app.api.appliedApprovalActs, 1);
  });

  test('sendBack removes the item', () async {
    await repo.sendBack(7, remark: 'Check depth');
    expect(await repo.watchInbox().first, isEmpty);
  });

  test('offline raises OfflineFailure and sends nothing', () async {
    app.connectivity.setOnline(false);

    await expectLater(repo.approve(7), throwsA(isA<OfflineFailure>()));

    expect(app.api.appliedApprovalActs, 0);
    expect(await repo.watchInbox().first, hasLength(1));
  });

  test('a retry after a lost response is applied once', () async {
    final retrying = _LoseFirstActResponse(app.api);
    final services = AppServices.forTesting(
      db: app.db,
      api: retrying,
      connectivity: app.connectivity,
      clock: app.clock,
    );

    await expectLater(
      services.approvals.approve(7),
      throwsA(isA<OfflineFailure>()),
    );
    await services.approvals.approve(7);

    expect(retrying.uuids, hasLength(2));
    expect(retrying.uuids.toSet(), hasLength(1), reason: 'same client_uuid');
    expect(app.api.appliedApprovalActs, 1);
  });

  test('the action key is kept until the server has answered', () async {
    app.api.offline = true;
    await expectLater(repo.approve(7), throwsA(isA<OfflineFailure>()));
    final keys = await app.db.select(app.db.keyValues).get();
    final pending = keys.where((k) => k.key.startsWith('idempotency.approval'));
    expect(pending, hasLength(1));

    app.api.offline = false;
    await repo.approve(7);

    final after = await app.db.select(app.db.keyValues).get();
    expect(after.where((k) => k.key.startsWith('idempotency.approval')), isEmpty);
    expect(app.api.appliedApprovalActs, 1);
    expect(await app.db.readMeta(MetaKey.watermark), isNotNull);
  });
}

/// Lets the first approval through to the server but loses the response.
class _LoseFirstActResponse implements NirmaanApi {
  _LoseFirstActResponse(this._inner);

  final FakeApi _inner;
  final uuids = <String>[];

  @override
  Future<Map<String, dynamic>> actOnApproval(
    int approvalId, {
    required String action,
    required String clientUuid,
    String? remark,
  }) async {
    uuids.add(clientUuid);
    final result = await _inner.actOnApproval(
      approvalId,
      action: action,
      clientUuid: clientUuid,
      remark: remark,
    );
    if (uuids.length == 1) throw const OfflineFailure('Response lost');
    return result;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
