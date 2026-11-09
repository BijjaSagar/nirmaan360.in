import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/data/project_repository.dart';

import '../../../support/test_app.dart';

MeasurementInput entry(String nos, {bool deduction = false}) =>
    MeasurementInput(
      projectId: 1,
      boqItemId: 1,
      measuredOn: '2026-11-09',
      nos: Qty.parse(nos),
      isDeduction: deduction,
    );

void main() {
  late TestApp app;
  late ProjectRepository repo;

  setUp(() async {
    app = await TestApp.create();
    repo = app.services.projects;
  });

  tearDown(() => app.dispose());

  test('watchProjects lists what was pulled', () async {
    expect((await repo.watchProjects().first).single.code, 'KT-B');
  });

  test('current project falls back to the first, then remembers', () async {
    expect((await repo.watchCurrentProject().first)!.id, 1);

    app.api.addProject(id: 2, code: 'ZZ', name: 'Zeta');
    await app.sync();
    await repo.selectProject(2);

    expect((await repo.watchCurrentProject().first)!.id, 2);
    expect(await repo.readSelectedProjectId(), 2);
  });

  test('sites by project', () async {
    expect((await repo.watchSites(1).first).single.name, 'Tower B');
    expect(await repo.watchSites(9).first, isEmpty);
  });

  test('BOQ search matches code and description', () async {
    app.api.addBoqItem(id: 2, projectId: 1, code: '3.4');
    await app.sync();

    expect(await repo.watchBoqItems(1).first, hasLength(2));
    expect(
      (await repo.watchBoqItems(1, search: '3.4').first).single.item.code,
      '3.4',
    );
    expect(await repo.watchBoqItems(1, search: 'nothing').first, isEmpty);
  });

  group('balance preview', () {
    test('subtracts unsent entries from the server balance', () async {
      await app.services.measurements.create(entry('5.000'));

      final line = (await repo.watchBoqLine(1).first)!;

      expect(line.serverBalance, Qty.parse('620.000'));
      expect(line.unsentQty, Qty.parse('5.000'));
      expect(line.previewBalance, Qty.parse('615.000'));
    });

    test('a deduction adds back', () async {
      await app.services.measurements.create(entry('5.000', deduction: true));

      final line = (await repo.watchBoqLine(1).first)!;

      expect(line.unsentQty, Qty.parse('-5.000'));
      expect(line.previewBalance, Qty.parse('625.000'));
    });

    test('is not counted twice after a pull', () async {
      await app.services.measurements.create(entry('5.000'));
      await app.sync();

      final line = (await repo.watchBoqLine(1).first)!;

      expect(line.serverBalance, Qty.parse('615.000'));
      expect(line.unsentQty, Qty.zero);
      expect(line.previewBalance, Qty.parse('615.000'));
    });

    test('stays right when a push landed but its pull failed', () async {
      await app.services.measurements.create(entry('5.000'));
      app.api.failNextPull();
      await app.services.sync.syncNow();

      final line = (await repo.watchBoqLine(1).first)!;

      expect(line.previewBalance, Qty.parse('615.000'));
    });

    test('counts only the difference for an edited entry', () async {
      final uuid = await app.services.measurements.create(entry('5.000'));
      await app.sync();
      await app.services.measurements.edit(uuid, entry('8.000'));

      final line = (await repo.watchBoqLine(1).first)!;

      expect(line.unsentQty, Qty.parse('3.000'));
      expect(line.previewBalance, Qty.parse('612.000'));
    });

    test('updates live', () async {
      final seen = <Qty>[];
      final subscription = repo
          .watchBoqLine(1)
          .listen((l) => seen.add(l!.previewBalance));
      addTearDown(subscription.cancel);
      await pumpEventQueue();

      await app.services.measurements.create(entry('5.000'));
      await pumpEventQueue();

      expect(seen.last, Qty.parse('615.000'));
    });

    test('sums decimals exactly', () async {
      for (final nos in ['0.100', '0.200', '0.300']) {
        await app.services.measurements.create(entry(nos));
      }
      final line = (await repo.watchBoqLine(1).first)!;
      expect(line.unsentQty.toWire(), '0.600');
    });
  });

  test('lookups come from the pull', () async {
    final lookups = await repo.watchLookups().first;
    expect(lookups.trades, contains('Mason'));
    expect(lookups.weather, contains('Rain'));
    expect(lookups.attendanceStatuses, contains('half'));
  });
}
