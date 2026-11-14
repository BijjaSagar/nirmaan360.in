import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/db/app_database.dart' show BoqItemRow;
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/data/project_repository.dart';

import '../../../support/test_app.dart';

void main() {
  late TestApp app;
  late ProjectRepository repo;

  setUp(() async {
    final api = FakeApi()
      ..grant('project.manage')
      ..grant('boq.manage');
    app = await TestApp.create(api: api);
    repo = app.services.projects;
  });

  tearDown(() => app.dispose());

  Future<BoqItemRow> addLine({String description = 'Footing concrete'}) =>
      repo.addBoqItem(
        projectId: 1,
        description: description,
        unit: 'cum',
        qty: Qty.parse('120.500'),
        rate: Money.parse('7450.00'),
      );

  Future<int> setupKeys() async {
    final rows = await app.db.select(app.db.keyValues).get();
    return rows.where((r) => r.key.startsWith('idempotency.setup')).length;
  }

  group('createProject', () {
    test('creates on the server and returns the local row', () async {
      final project = await repo.createProject(
        name: 'Riverside Phase 2',
        location: 'Nashik',
        startDate: '2026-11-01',
        endDate: '2027-11-01',
        contractValue: Money.parse('12500000.50'),
      );

      expect(project.name, 'Riverside Phase 2');
      expect(project.location, 'Nashik');
      expect(project.startDate, '2026-11-01');

      final server = app.api.projects.last;
      expect(server['id'], project.id);
      expect(server['contract_value'], '12500000.50');
      expect(app.api.setupStatuses, [201]);
    });

    test('shows at once in watchProjects, with no pull', () async {
      final pullsBefore = app.api.pullCalls.length;

      await repo.createProject(name: 'Riverside Phase 2');

      final projects = await repo.watchProjects().first;
      expect(projects.map((p) => p.name), contains('Riverside Phase 2'));
      expect(app.api.pullCalls.length, pullsBefore);
    });

    test('updates a live watcher', () async {
      final seen = <int>[];
      final subscription = repo.watchProjects().listen(
        (projects) => seen.add(projects.length),
      );
      addTearDown(subscription.cancel);
      await pumpEventQueue();

      await repo.createProject(name: 'Riverside Phase 2');
      await pumpEventQueue();

      expect(seen.last, 2);
    });

    test('can be selected afterwards', () async {
      final project = await repo.createProject(name: 'Riverside Phase 2');

      await repo.selectProject(project.id);

      expect((await repo.watchCurrentProject().first)!.id, project.id);
    });

    test('generates a code from the initials', () async {
      final project = await repo.createProject(name: 'Riverside Phase Two');
      expect(project.code, 'RPT');
    });

    test('takes at most four initials', () async {
      final project = await repo.createProject(
        name: 'Shivneri Riverside Phase Two Wing',
      );
      expect(project.code, 'SRPT');
    });

    test('adds a number to keep the code unique', () async {
      await repo.createProject(name: 'Riverside Phase Two');
      final second = await repo.createProject(name: 'Rail Pump Tower');
      expect(second.code, 'RPT2');
    });

    test('offline raises OfflineFailure and sends nothing', () async {
      app.connectivity.setOnline(false);
      final before = app.api.projects.length;

      await expectLater(
        repo.createProject(name: 'Riverside'),
        throwsA(isA<OfflineFailure>()),
      );
      expect(app.api.projects.length, before);
    });

    test('a dropped connection raises OfflineFailure', () async {
      app.api.offline = true;

      await expectLater(
        repo.createProject(name: 'Riverside'),
        throwsA(isA<OfflineFailure>()),
      );
    });

    test('a lost reply retried makes one server row', () async {
      final before = app.api.projects.length;
      app.api.loseNextSetupResponse();

      await expectLater(
        repo.createProject(name: 'Riverside'),
        throwsA(isA<OfflineFailure>()),
      );
      expect(app.api.projects.length, before + 1);

      final project = await repo.createProject(name: 'Riverside');

      expect(app.api.projects.length, before + 1);
      expect(app.api.setupStatuses, [201, 200]);
      expect(project.id, app.api.projects.last['id']);
      expect(
        (await repo.watchProjects().first).where((p) => p.name == 'Riverside'),
        hasLength(1),
      );
    });

    test('correcting the location before retrying cannot make a twin',
        () async {
      final before = app.api.projects.length;
      app.api.loseNextSetupResponse();
      await expectLater(
        repo.createProject(name: 'Riverside', location: 'Nasik'),
        throwsA(isA<OfflineFailure>()),
      );

      await repo.createProject(name: ' riverside ', location: 'Nashik');

      expect(app.api.projects.length, before + 1);
    });

    test('a later project of the same name is a new one', () async {
      final before = app.api.projects.length;

      await repo.createProject(name: 'Riverside');
      await repo.createProject(name: 'Riverside');

      expect(app.api.projects.length, before + 2);
      expect(await setupKeys(), 0, reason: 'keys go once a reply is stored');
    });

    test('the plan limit is a ValidationFailure with errors.plan', () async {
      app.api.planProjectLimit = 1;

      await expectLater(
        repo.createProject(name: 'Second'),
        throwsA(
          isA<ValidationFailure>().having(
            (e) => e.errors['plan'],
            'errors.plan',
            [FakeApi.planLimitMessage],
          ),
        ),
      );
      expect(app.api.projects, hasLength(1));
    });

    test('a retry at the plan limit still gets its row', () async {
      app.api.planProjectLimit = 2;
      app.api.loseNextSetupResponse();
      await expectLater(
        repo.createProject(name: 'Second'),
        throwsA(isA<OfflineFailure>()),
      );

      final project = await repo.createProject(name: 'Second');

      expect(project.name, 'Second');
      expect(app.api.projects, hasLength(2));
    });

    test('server validation surfaces its errors', () async {
      await expectLater(
        repo.createProject(name: '   '),
        throwsA(
          isA<ValidationFailure>().having(
            (e) => e.errors.keys,
            'fields',
            contains('name'),
          ),
        ),
      );
    });

    test('without the permission the server refuses', () async {
      (app.api.user['can'] as Map)['project.manage'] = false;

      await expectLater(
        repo.createProject(name: 'Riverside'),
        throwsA(
          isA<ServerFailure>().having((e) => e.statusCode, 'status', 403),
        ),
      );
    });

    test('a pull after creating does not duplicate it', () async {
      final project = await repo.createProject(name: 'Riverside');

      await app.sync();

      expect(
        (await repo.watchProjects().first).where((p) => p.id == project.id),
        hasLength(1),
      );
    });
  });

  group('addBoqItem', () {
    test('creates a line whose balance equals its quantity', () async {
      final line = await addLine();

      expect(line.description, 'Footing concrete');
      expect(line.qty, Qty.parse('120.500'));
      expect(line.rate, Money.parse('7450.00'));
      expect(line.balanceQty, Qty.parse('120.500'));
      expect(line.measuredQty, Qty.zero);
    });

    test('sends quantity and rate as wire strings', () async {
      await addLine();
      expect(app.api.boqItems.last['qty'], '120.500');
      expect(app.api.boqItems.last['rate'], '7450.00');
    });

    test('shows at once in the BOQ list with its balance', () async {
      final line = await addLine();

      final lines = await repo.watchBoqItems(1).first;
      final shown = lines.firstWhere((l) => l.item.id == line.id);

      expect(shown.previewBalance, Qty.parse('120.500'));
    });

    test('a blank code becomes the next number', () async {
      final first = await addLine(description: 'One');
      final second = await addLine(description: 'Two');

      expect(int.parse(second.code), int.parse(first.code) + 1);
    });

    test('keeps a given code and section', () async {
      final line = await repo.addBoqItem(
        projectId: 1,
        description: 'Brickwork',
        unit: 'sqm',
        qty: Qty.parse('10'),
        rate: Money.parse('1'),
        code: 'B-7',
        section: 'Masonry',
      );

      expect(line.code, 'B-7');
      expect(line.section, 'Masonry');
    });

    test('sorts after the existing lines', () async {
      final existing = app.api.boqItems.single['sort'] as int;
      final line = await addLine();
      expect(line.sort, existing + 1);
    });

    test('offline raises OfflineFailure', () async {
      app.connectivity.setOnline(false);

      await expectLater(addLine(), throwsA(isA<OfflineFailure>()));
      expect(app.api.boqItems, hasLength(1));
    });

    test('a lost reply retried makes one server row', () async {
      app.api.loseNextSetupResponse();
      await expectLater(addLine(), throwsA(isA<OfflineFailure>()));
      expect(app.api.boqItems, hasLength(2));

      await addLine();

      expect(app.api.boqItems, hasLength(2));
      expect(await app.db.select(app.db.boqItems).get(), hasLength(2));
    });

    test('correcting the quantity before retrying cannot make a twin',
        () async {
      app.api.loseNextSetupResponse();
      await expectLater(addLine(), throwsA(isA<OfflineFailure>()));

      await repo.addBoqItem(
        projectId: 1,
        description: 'Footing concrete',
        unit: 'cum',
        qty: Qty.parse('99.000'),
        rate: Money.parse('7450.00'),
      );

      expect(app.api.boqItems, hasLength(2));
    });

    test('a line in another section is a different line', () async {
      await repo.addBoqItem(
        projectId: 1,
        description: 'Concrete',
        unit: 'cum',
        qty: Qty.parse('1'),
        rate: Money.parse('1'),
        section: 'A',
      );
      await repo.addBoqItem(
        projectId: 1,
        description: 'Concrete',
        unit: 'cum',
        qty: Qty.parse('1'),
        rate: Money.parse('1'),
        section: 'B',
      );

      expect(app.api.boqItems, hasLength(3));
    });

    test('an unknown project is a 404', () async {
      await expectLater(
        repo.addBoqItem(
          projectId: 999,
          description: 'X',
          unit: 'cum',
          qty: Qty.parse('1'),
          rate: Money.parse('1'),
        ),
        throwsA(
          isA<ServerFailure>().having((e) => e.statusCode, 'status', 404),
        ),
      );
    });

    test('server validation surfaces its errors', () async {
      await expectLater(
        addLine(description: ' '),
        throwsA(
          isA<ValidationFailure>().having(
            (e) => e.errors.keys,
            'fields',
            contains('description'),
          ),
        ),
      );
    });

    test('a later measurement moves the balance as usual', () async {
      final line = await addLine();

      await app.services.measurements.create(
        MeasurementInput(
          projectId: 1,
          boqItemId: line.id,
          measuredOn: '2026-11-09',
          nos: Qty.parse('20.000'),
        ),
      );

      final preview = (await repo.watchBoqLine(line.id).first)!;
      expect(preview.previewBalance, Qty.parse('100.500'));
    });
  });

  group('watchSetup', () {
    test('counts what the project has', () async {
      final setup = await repo.watchSetup(1).first;

      expect(setup.boqCount, 1);
      expect(setup.workerCount, 2);
      expect(setup.walletCount, 1);
    });

    test('a project with nothing yet is all zero except workers', () async {
      final project = await repo.createProject(name: 'Empty');

      final setup = await repo.watchSetup(project.id).first;

      expect(setup.boqCount, 0);
      expect(setup.walletCount, 0);
      expect(setup.workerCount, 2, reason: 'workers are organisation-wide');
    });

    test('updates live as a site is set up', () async {
      final project = await repo.createProject(name: 'New site');
      final seen = <ProjectSetup>[];
      final subscription = repo.watchSetup(project.id).listen(seen.add);
      addTearDown(subscription.cancel);
      await pumpEventQueue();

      await repo.addBoqItem(
        projectId: project.id,
        description: 'Concrete',
        unit: 'cum',
        qty: Qty.parse('5'),
        rate: Money.parse('100'),
      );
      await pumpEventQueue();
      expect(seen.last.boqCount, 1);

      await app.services.pettyCash.createWallet(
        projectId: project.id,
        name: 'Site cash',
      );
      await pumpEventQueue();
      expect(seen.last.walletCount, 1);

      await app.services.attendance.addWorker(name: 'New', trade: 'Mason');
      await pumpEventQueue();
      expect(seen.last.workerCount, 3);
    });

    test('other projects do not count', () async {
      await addLine();
      final setup = await repo.watchSetup(99).first;
      expect(setup.boqCount, 0);
      expect(setup.walletCount, 0);
    });
  });

  group('permissions', () {
    test('project.manage and boq.manage reach can()', () async {
      final user = CurrentUser.fromJson(app.api.user);

      expect(user.can('project.manage'), isTrue);
      expect(user.can('boq.manage'), isTrue);
    });

    test('nothing filters keys through the session cache', () {
      final user = CurrentUser.fromJson(app.api.user);
      final restored = CurrentUser.fromJson(user.toJson());

      expect(restored.can('project.manage'), isTrue);
      expect(restored.can('boq.manage'), isTrue);
    });

    test('the signed-in user sees them through the repository', () {
      expect(app.services.auth.can('project.manage'), isTrue);
    });

    test('a role without them is refused by can()', () async {
      final plain = await TestApp.create();
      addTearDown(plain.dispose);

      expect(plain.services.auth.can('project.manage'), isFalse);
      expect(plain.services.auth.can('boq.manage'), isFalse);
    });

    test('a permission granted later arrives with the next pull', () async {
      final plain = await TestApp.create();
      addTearDown(plain.dispose);

      plain.api.grant('project.manage');
      await plain.sync();

      expect(plain.services.auth.can('project.manage'), isTrue);
    });
  });
}
