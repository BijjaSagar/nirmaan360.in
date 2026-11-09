import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/projects/bloc/boq_cubit.dart';
import 'package:nirmaan360/features/projects/bloc/boq_form_cubit.dart';

import '../../attendance/view/field_test_support.dart';

BoqLine _line(String qty, String balance) => BoqLine(
  item: BoqItemRow(
    id: 1,
    projectId: 1,
    code: '1',
    description: 'Line',
    unit: 'cum',
    qty: Qty.parse(qty),
    rate: Money.parse('100'),
    sort: 1,
    measuredQty: Qty.zero,
    approvedQty: Qty.zero,
    balanceQty: Qty.parse(balance),
  ),
  unsentQty: Qty.zero,
);

/// The server rejects every new line with [errors].
class _RejectingApi extends FakeApi {
  _RejectingApi(this.errors);

  final Map<String, List<String>> errors;

  @override
  Future<Map<String, dynamic>> addBoqItem({
    required int projectId,
    int? id,
    String? clientUuid,
    String? description,
    String? unit,
    String? qty,
    String? rate,
    String? code,
    String? section,
  }) async {
    if (id != null) {
      return super.addBoqItem(projectId: projectId, id: id, code: code, qty: qty, rate: rate, unit: unit);
    }
    throw ValidationFailure('The given data was invalid.', errors: errors);
  }
}

Future<BoqFormCubit> _readyForm(TestApp app) async {
  final cubit = BoqFormCubit(app.services);
  await cubit.stream.firstWhere((s) => s.project != null);
  return cubit;
}

void _fill(BoqFormCubit cubit, {String description = 'M25 slab', String unit = 'cum'}) {
  cubit
    ..setDescription(description)
    ..setUnit(unit)
    ..next()
    ..setActiveText('12.345')
    ..nextFigure()
    ..setActiveText('678.90');
}

void main() {
  group('measured share', () {
    test('rounds down, so only a finished line shows 100%', () {
      expect(measuredShareOf(_line('3', '2')).percent, 33);
      expect(measuredShareOf(_line('1000', '1')).percent, 99);
      expect(measuredShareOf(_line('10', '0')).percent, 100);
    });

    test('is zero for a line with no quantity or nothing measured', () {
      expect(measuredShareOf(_line('0', '0')).percent, 0);
      expect(measuredShareOf(_line('10', '10')).fraction, 0);
    });

    test('over-measuring fills the mark but still reports the figure', () {
      final share = measuredShareOf(_line('10', '-3'));
      expect(share.fraction, 1);
      expect(share.percent, 130);
    });
  });

  test('search matches code, description or section, in memory', () async {
    final app = await TestApp.create();
    addTearDown(app.dispose);
    final cubit = BoqCubit(app.services);
    addTearDown(cubit.close);
    await cubit.stream.firstWhere((s) => s.load == BoqLoad.ready);

    expect(cubit.state.sections.single.name, 'Superstructure');

    cubit.search('nothing like this');
    expect(cubit.state.sections, isEmpty);

    cubit.search('superstr');
    expect(cubit.state.sections.single.lines, hasLength(1));
  });

  test('the live amount is exact decimal arithmetic', () async {
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);

    _fill(cubit);

    // 12.345 x 678.90 = 8381.0205, rounded half-up to money.
    expect(cubit.state.amount, Money.parse('8381.02'));
  });

  test('save and add another keeps the section and clears the rest', () async {
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);

    cubit.setSection('Substructure');
    cubit.setCode('A1');
    _fill(cubit);
    expect(await cubit.save(addAnother: true), isTrue);

    expect(cubit.state.addedCount, 1);
    expect(cubit.state.formVersion, 1);
    expect(cubit.state.step, 0);
    expect(cubit.state.unit, isNull);
    expect(cubit.state.qtyText, isEmpty);
    expect(cubit.state.rateText, isEmpty);
    expect(cubit.state.done, isFalse);

    // The second line keeps the section but not the code.
    _fill(cubit, description: 'Brickwork', unit: 'sqm');
    expect(await cubit.save(addAnother: false), isTrue);
    expect(cubit.state.done, isTrue);
    expect(cubit.state.addedCount, 2);

    final added = app.api.boqItems.where((b) => b['description'] != 'RCC work 2.1').toList();
    expect(added.map((b) => b['section']), ['Substructure', 'Substructure']);
    expect(added.first['code'], 'A1');
    expect(added.last['code'], isNot('A1'));
    expect(added.first['qty'], '12.345');
    expect(added.first['rate'], '678.90');
  });

  test('saving offline says so and sends nothing', () async {
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);
    final int before = app.api.boqItems.length;

    _fill(cubit);
    app.connectivity.setOnline(false);
    await Future<void>.delayed(Duration.zero);
    expect(cubit.state.online, isFalse);

    expect(await cubit.save(addAnother: true), isFalse);
    expect(cubit.state.problem, BoqFormProblem.offline);
    expect(app.api.boqItems, hasLength(before));
  });

  test('server validation errors land on their field and step', () async {
    final app = await TestApp.create(
      api: _RejectingApi(const {'code': ['This code is already used.']})..grant('boq.manage'),
    );
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);

    _fill(cubit);
    expect(await cubit.save(addAnother: true), isFalse);

    expect(cubit.state.serverErrors['code'], 'This code is already used.');
    expect(cubit.state.step, 0, reason: 'the code field is on the first step');
    expect(cubit.state.addedCount, 0);
  });

  test('a server message with no field is still shown', () async {
    final app = await TestApp.create(
      api: _RejectingApi(const {'plan': ['Your plan does not allow this.']})..grant('boq.manage'),
    );
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);

    _fill(cubit);
    expect(await cubit.save(addAnother: true), isFalse);

    expect(cubit.state.serverMessage, 'The given data was invalid.');
  });

  test('unit and description are required before the figures', () async {
    final app = await TestApp.create(api: FakeApi()..grant('boq.manage'));
    addTearDown(app.dispose);
    final cubit = await _readyForm(app);
    addTearDown(cubit.close);

    cubit.next();
    expect(cubit.state.problem, BoqFormProblem.descriptionRequired);

    cubit.setDescription('Plaster');
    cubit.next();
    expect(cubit.state.problem, BoqFormProblem.unitRequired);
    expect(cubit.state.step, 0);
  });
}
