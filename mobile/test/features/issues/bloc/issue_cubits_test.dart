import 'package:drift/drift.dart' show OrderingTerm;
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/features/issues/bloc/issue_detail_cubit.dart';
import 'package:nirmaan360/features/issues/bloc/issue_form_cubit.dart';
import 'package:nirmaan360/features/issues/bloc/issue_list_cubit.dart';

import '../../attendance/view/field_test_support.dart';

Future<List<OutboxRow>> _outbox(TestApp app) {
  return (app.db.select(app.db.outbox)..orderBy([(t) => OrderingTerm.asc(t.id)])).get();
}

void main() {
  late TestApp app;

  setUp(() async {
    app = await TestApp.create();
  });

  tearDown(() => app.dispose());

  test('status change works offline and queues', () async {
    final String uuid = await app.services.issues.raise(projectId: 1, title: 'Leak');
    await app.sync();
    app.connectivity.setOnline(false);

    final cubit = IssueDetailCubit(app.services, uuid);
    addTearDown(cubit.close);
    await cubit.stream.firstWhere((s) => s.issue != null);

    await cubit.changeStatus('in_progress');

    final issue = (await app.services.issues.watchOne(uuid).first)!;
    expect(issue.record.status, 'in_progress');
    expect(issue.isUnsent, isTrue);

    final rows = await _outbox(app);
    expect(rows.where((r) => r.type == OutboxType.issueStatus), hasLength(1));
    expect(cubit.state.savedWhileOffline, isTrue);
  });

  test('a raised issue needs a title and queues with its fields', () async {
    final cubit = IssueFormCubit(app.services);
    addTearDown(cubit.close);

    expect(await cubit.submit(title: '  ', location: ''), isFalse);
    expect(cubit.state.problem, IssueFormProblem.titleRequired);

    cubit.setPriority('critical');
    cubit.setDue(DueChoice.week);
    expect(await cubit.submit(title: 'Crack in C3', location: 'Grid C'), isTrue);

    final issue = (await app.services.issues.watchByProject(1).first).single;
    expect(issue.record.title, 'Crack in C3');
    expect(issue.record.priority, 'critical');
    expect(issue.record.locationNote, 'Grid C');
    expect(issue.record.dueDate, '2026-11-16');
    expect(await _outbox(app), hasLength(1));
  });

  test('open work sorts first, overdue before due', () async {
    await app.services.issues.raise(projectId: 1, title: 'Later', dueDate: '2027-01-01');
    await app.services.issues.raise(projectId: 1, title: 'Overdue', dueDate: '2026-11-01');
    final closed = await app.services.issues.raise(projectId: 1, title: 'Closed');
    await app.services.issues.changeStatus(closed, 'closed');

    final cubit = IssueListCubit(app.services);
    addTearDown(cubit.close);
    final state = await cubit.stream.firstWhere(
      (s) => s.load == IssueListLoad.ready && s.all.length == 3,
    );

    expect(state.visible.first.record.title, 'Overdue');
    expect(state.visible.last.record.status, 'closed');

    cubit.setFilter(IssueFilter.closed);
    expect(cubit.state.visible.single.record.title, 'Closed');
  });

  group('refresh', () {
    test('pulls what the office changed and says the server was reached', () async {
      final cubit = IssueListCubit(app.services);
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.load == IssueListLoad.ready);

      expect(await cubit.refresh(), isTrue);
    });

    test('says false when the phone has no network, and does not try', () async {
      final cubit = IssueListCubit(app.services);
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.load == IssueListLoad.ready);
      app.connectivity.setOnline(false);

      expect(await cubit.refresh(), isFalse);
    });

    test('says false when the server cannot be reached', () async {
      final cubit = IssueListCubit(app.services);
      addTearDown(cubit.close);
      await cubit.stream.firstWhere((s) => s.load == IssueListLoad.ready);
      app.api.offline = true;

      expect(await cubit.refresh(), isFalse);
    });
  });
}
