import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/app_services.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/sync_center/data/sync_repository.dart';

import '../../../support/test_app.dart';

void main() {
  group('sync center', () {
    late TestApp app;
    late SyncRepository repo;

    setUp(() async {
      app = await TestApp.create();
      repo = app.services.syncCenter;
    });

    tearDown(() => app.dispose());

    test('lists unfinished items with display info', () async {
      await app.services.attendance.mark(
        projectId: 1,
        workerId: 1,
        date: '2026-11-09',
        status: 'present',
      );

      final items = await repo.watchOutbox().first;

      expect(items.single.type, OutboxType.attendance);
      expect(items.single.title, 'Ramesh Pawar');
      expect(items.single.isBlocked, isFalse);
    });

    test('attention carries the reason and validation errors', () async {
      await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: 1,
          measuredOn: '2026-11-09',
          nos: Qty.parse('1.000'),
        ),
      );
      app.api.scriptPushStatuses(['error']);
      await app.sync();

      final item = (await repo.watchOutbox().first).single;

      expect(item.needsAttention, isTrue);
      expect(item.attentionCode, AttentionCode.validation);
      expect(item.validationErrors!['nos'], isNotEmpty);
    });

    test('a finished item moves to the log', () async {
      await app.services.issues.raise(projectId: 1, title: 'Crack');
      await app.sync();

      expect(await repo.watchOutbox().first, isEmpty);
      expect((await repo.watchLog().first).single.status, OutboxStatus.done);
    });

    test('status counts stay live', () async {
      final seen = <SyncStatus>[];
      final subscription = repo.status.listen(seen.add);
      addTearDown(subscription.cancel);
      await pumpEventQueue();

      await app.services.issues.raise(projectId: 1, title: 'Crack');
      await pumpEventQueue();

      expect(seen.last.pendingCount, 1);
      expect(repo.currentStatus.pendingCount, 1);
    });
  });

  group('test support', () {
    testWidgets('real repositories run in a widget test without leaks', (
      tester,
    ) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(
        MaterialApp(home: _ProjectList(services: app.services)),
      );
      await pumpDrift(tester);

      expect(find.text('Kalyani Tower B'), findsOneWidget);

      app.api.addProject(id: 2, code: 'NW', name: 'New Wing');
      await app.sync();
      await pumpDrift(tester);

      expect(find.text('New Wing'), findsOneWidget);
    }, timeout: const Timeout(Duration(seconds: 20)));

    testWidgets('writes reach the screen', (tester) async {
      final app = await TestApp.create();
      addTearDown(app.dispose);

      await tester.pumpWidget(
        MaterialApp(home: _ProjectList(services: app.services)),
      );
      await pumpDrift(tester);

      await app.services.issues.raise(projectId: 1, title: 'Crack');
      await pumpDrift(tester);

      expect(find.text('pending 1'), findsOneWidget);
    }, timeout: const Timeout(Duration(seconds: 20)));
  });
}

class _ProjectList extends StatelessWidget {
  const _ProjectList({required this.services});

  final AppServices services;

  @override
  Widget build(BuildContext context) {
    return Material(
      child: Column(
        children: [
          StreamBuilder<List<ProjectRow>>(
            stream: services.projects.watchProjects(),
            builder: (context, snapshot) => Column(
              children: [
                for (final project in snapshot.data ?? const <ProjectRow>[])
                  Text(project.name),
              ],
            ),
          ),
          StreamBuilder<SyncStatus>(
            stream: services.sync.status,
            builder: (context, snapshot) =>
                Text('pending ${snapshot.data?.pendingCount ?? 0}'),
          ),
        ],
      ),
    );
  }
}
