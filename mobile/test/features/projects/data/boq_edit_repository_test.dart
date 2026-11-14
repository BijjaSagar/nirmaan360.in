import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart' show BoqItemRow;
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/core/session/current_user.dart';
import 'package:nirmaan360/features/measurement/data/measurement_repository.dart';
import 'package:nirmaan360/features/projects/data/project_repository.dart';

import '../../../support/test_app.dart';

/// Editing a BOQ line and removing it, through the repository, against the
/// fake server. Line 1 (code 2.1, 620 cum) comes from the demo seed.
void main() {
  late TestApp app;
  late ProjectRepository repo;

  Future<void> start({bool owner = false}) async {
    final FakeApi api = FakeApi()..grant('boq.manage');
    api.user['is_owner'] = owner;
    app = await TestApp.create(api: api);
    repo = app.services.projects;
  }

  tearDown(() => app.dispose());

  Future<BoqItemRow> line() async => (await repo.getBoqItem(1))!;

  Future<BoqItemRow> save(BoqItemRow opened, {String? description, String qty = '650', String? rate = '7600'}) {
    return repo.updateBoqItem(
      boqItemId: opened.id,
      code: opened.code,
      description: description ?? opened.description,
      unit: opened.unit,
      qty: Qty.parse(qty),
      rate: rate == null ? null : Money.parse(rate),
      reason: 'Revised drawing R3',
      openedAt: opened.updatedAt,
    );
  }

  Future<void> measureLocally(int boqItemId, String nos) => app.services.measurements.create(
    MeasurementInput(projectId: 1, boqItemId: boqItemId, measuredOn: '2026-11-09', nos: Qty.parse(nos)),
  );

  group('updateBoqItem', () {
    setUp(() => start());

    test('saves on the server and keeps the reply on the phone', () async {
      final BoqItemRow opened = await line();

      final BoqItemRow saved = await save(opened, description: 'RCC M30 in slab');

      expect(saved.description, 'RCC M30 in slab');
      expect(saved.qty, Qty.parse('650'));
      expect(saved.rate, Money.parse('7600'));
      expect(saved.updatedAt, isNot(opened.updatedAt));
      expect((await line()).description, 'RCC M30 in slab');

      final Map<String, Object?> sent = app.api.boqEdits.single;
      expect(sent['qty'], '650.000');
      expect(sent['rate'], '7600.00');
      expect(sent['reason'], 'Revised drawing R3');
      expect(DateTime.parse(sent['updated_at']! as String).isAtSameMomentAs(opened.updatedAt!), isTrue);
    });

    test('sends no rate for someone who may not see money, and the rate stays', () async {
      final BoqItemRow opened = await line();
      final Object? rateBefore = app.api.boqItems.first['rate'];

      await save(opened, rate: null);

      expect(app.api.boqEdits.single.containsKey('rate'), isTrue);
      expect(app.api.boqEdits.single['rate'], isNull);
      expect(app.api.boqItems.first['rate'], rateBefore);
    });

    test('offline it is refused before anything is sent', () async {
      final BoqItemRow opened = await line();
      app.connectivity.setOnline(false);

      await expectLater(save(opened), throwsA(isA<OfflineFailure>()));

      expect(app.api.boqEdits, isEmpty);
      expect((await line()).qty, opened.qty);
    });

    test('a line changed on the website since it was opened is a 409, and the phone takes the office copy', () async {
      final BoqItemRow opened = await line();
      app.api.editOnWeb(app.api.boqItems.first, {'description': 'Changed in the office'});

      await expectLater(
        save(opened, description: 'Changed on site'),
        throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 409).having((e) => e.code, 'code', 'changed')),
      );

      expect(app.api.boqItems.first['description'], 'Changed in the office');
      final BoqItemRow now = await line();
      expect(now.description, 'Changed in the office');
      expect(now.updatedAt, isNot(opened.updatedAt));

      // Saved again from the office copy, it goes through.
      expect((await save(now, description: 'Changed on site')).description, 'Changed on site');
    });

    test('the server refusing a quantity below what is measured comes back on the qty field', () async {
      await measureLocally(1, '30');
      await app.sync();
      final BoqItemRow opened = await line();

      await expectLater(
        save(opened, qty: '29.999'),
        throwsA(isA<ValidationFailure>().having((e) => e.errors['qty']?.single, 'qty', contains('30.000'))),
      );
      expect((await line()).qty, Qty.parse('620'));
    });

    test('a line the server no longer has leaves the phone', () async {
      final BoqItemRow opened = await line();
      app.api.softDeleteOnWeb('boq_items', app.api.boqItems.first);

      await expectLater(save(opened), throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 404)));

      expect(await repo.getBoqItem(1), isNull);
    });
  });

  group('deleteBoqItem', () {
    test('the owner removes the line on the server and on the phone, and a later pull keeps it gone', () async {
      await start(owner: true);

      await repo.deleteBoqItem(boqItemId: 1, reason: 'Added twice');

      expect(app.api.boqDeletes.single, (id: 1, reason: 'Added twice'));
      expect(app.api.boqItems.first['deleted_at'], isNotNull);
      expect(await repo.getBoqItem(1), isNull);

      await app.sync();
      expect(await repo.getBoqItem(1), isNull);
    });

    test('anyone else gets the server 403 and the line stays', () async {
      await start();

      await expectLater(
        repo.deleteBoqItem(boqItemId: 1, reason: 'Added twice'),
        throwsA(isA<ServerFailure>().having((e) => e.statusCode, 'status', 403)),
      );

      expect(app.api.boqItems.first['deleted_at'], isNull);
      expect(await repo.getBoqItem(1), isNotNull);
    });

    test('a line the server holds records against is refused with every reason, and stays', () async {
      await start(owner: true);
      app.api.boqItemUses[1] = <String>['This item is in a tender and cannot be removed.'];
      await measureLocally(1, '5');
      await app.sync();

      await expectLater(
        repo.deleteBoqItem(boqItemId: 1, reason: 'Added twice'),
        throwsA(
          isA<ValidationFailure>().having((e) => e.errors['boq'], 'boq', <String>[
            'This item has measurements and cannot be removed.',
            'This item is in a tender and cannot be removed.',
          ]),
        ),
      );

      expect(await repo.getBoqItem(1), isNotNull);
    });

    test('work on this phone the server has not had yet stops it before anything is sent', () async {
      await start(owner: true);
      await measureLocally(1, '5');

      expect(await repo.hasUnsentWorkOn(1), isTrue);
      await expectLater(repo.deleteBoqItem(boqItemId: 1, reason: 'Added twice'), throwsA(isA<UnsentWorkOnLine>()));
      expect(app.api.boqDeletes, isEmpty);

      await app.sync();
      expect(await repo.hasUnsentWorkOn(1), isFalse);
    });

    test('unsent work on another line does not hold this one', () async {
      await start(owner: true);
      await app.api.addBoqItem(id: 2, projectId: 1, code: '2.2');
      await app.sync();
      await measureLocally(1, '5');

      expect(await repo.hasUnsentWorkOn(2), isFalse);
      await repo.deleteBoqItem(boqItemId: 2, reason: 'Not in scope');
      expect(await repo.getBoqItem(2), isNull);
    });

    test('a line already gone on the server counts as removed', () async {
      await start(owner: true);
      app.api.softDeleteOnWeb('boq_items', app.api.boqItems.first);

      await repo.deleteBoqItem(boqItemId: 1, reason: 'Added twice');

      expect(await repo.getBoqItem(1), isNull);
    });

    test('offline it is refused before anything is sent', () async {
      await start(owner: true);
      app.connectivity.setOnline(false);

      await expectLater(repo.deleteBoqItem(boqItemId: 1, reason: 'Added twice'), throwsA(isA<OfflineFailure>()));

      expect(app.api.boqDeletes, isEmpty);
      expect(await repo.getBoqItem(1), isNotNull);
    });
  });

  group('CurrentUser.isOwner', () {
    Map<String, dynamic> me(Object? isOwner) => <String, dynamic>{
      'id': 1,
      'name': 'Asha',
      'role': 'owner',
      'is_owner': ?isOwner,
      'can': <String, bool>{},
    };

    test('is read from is_owner, and missing means not the owner', () {
      expect(CurrentUser.fromJson(me(true)).isOwner, isTrue);
      expect(CurrentUser.fromJson(me(false)).isOwner, isFalse);
      expect(CurrentUser.fromJson(me(null)).isOwner, isFalse, reason: 'an older server says nothing: delete stays hidden');
    });

    test('survives the cached copy', () {
      final CurrentUser user = CurrentUser.fromJson(me(true));
      expect(CurrentUser.fromJson(user.toJson()).isOwner, isTrue);
    });
  });
}
