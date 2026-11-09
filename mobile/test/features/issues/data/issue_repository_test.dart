import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/features/issues/data/issue_repository.dart';

import '../../../support/test_app.dart';

void main() {
  late TestApp app;
  late IssueRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.issues;
  });

  tearDown(() => app.dispose());

  test('raise then change status, then sync in order', () async {
    final uuid = await repo.raise(
      projectId: 1,
      title: 'Crack in beam',
      priority: 'high',
    );
    await repo.changeStatus(uuid, 'in_progress');
    expect((await repo.watchOne(uuid).first)!.record.status, 'in_progress');

    await app.sync();

    expect(app.api.issues, hasLength(1));
    expect(app.api.issueByUuid(uuid)['status'], 'in_progress');
    expect((await repo.watchOne(uuid).first)!.syncState, LocalSyncState.synced);
  });

  test('repeated status changes coalesce', () async {
    final uuid = await repo.raise(projectId: 1, title: 'Crack');
    await app.sync();

    await repo.changeStatus(uuid, 'in_progress');
    await repo.changeStatus(uuid, 'resolved');
    await repo.changeStatus(uuid, 'closed');

    final rows = await app.db.select(app.db.outbox).get();
    expect(rows.length, 2, reason: 'the raise and one status change');

    await app.sync();
    expect(app.api.issueByUuid(uuid)['status'], 'closed');
  });

  test('resolving sets resolved_at, reopening clears it', () async {
    final uuid = await repo.raise(projectId: 1, title: 'Crack');

    await repo.changeStatus(uuid, 'resolved');
    expect((await repo.watchOne(uuid).first)!.record.resolvedAt, isNotNull);

    await repo.changeStatus(uuid, 'open');
    expect((await repo.watchOne(uuid).first)!.record.resolvedAt, isNull);
  });

  test('watch by project filters by status', () async {
    await repo.raise(projectId: 1, title: 'Open one');
    final other = await repo.raise(projectId: 1, title: 'Closed one');
    await repo.changeStatus(other, 'closed');

    final open = await repo.watchByProject(1, status: 'open').first;
    final all = await repo.watchByProject(1).first;

    expect(open.single.record.title, 'Open one');
    expect(all, hasLength(2));
  });

  test('a blank title is refused', () {
    expect(
      () => repo.raise(projectId: 1, title: '  '),
      throwsA(isA<ValidationFailure>()),
    );
  });
}
