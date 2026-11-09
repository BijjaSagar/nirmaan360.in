import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/db/app_database.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/dpr/view/widgets/dpr_format.dart';
import 'package:nirmaan360/features/projects/data/project_repository.dart';

BoqLine line({required String qty, required String balance, String unsent = '0'}) {
  return BoqLine(
    unsentQty: Qty.parse(unsent),
    item: BoqItemRow(
      id: 1,
      projectId: 1,
      code: '2.1',
      description: 'Slab',
      unit: 'cum',
      qty: Qty.parse(qty),
      rate: Money.parse('100'),
      sort: 1,
      measuredQty: Qty.parse('0'),
      approvedQty: Qty.parse('0'),
      balanceQty: Qty.parse(balance),
    ),
  );
}

void main() {
  group('dprBoqDone', () {
    test('rounds the percent from exact decimals', () {
      final done = dprBoqDone(line(qty: '620.000', balance: '555.000'));
      expect(done.percent, 10);
      expect(done.fraction, closeTo(65 / 620, 0.0001));
    });

    test('counts unsent work on this phone', () {
      final done = dprBoqDone(line(qty: '100', balance: '40', unsent: '10'));
      expect(done.percent, 70);
    });

    test('a line with no quantity is zero, not a crash', () {
      expect(dprBoqDone(line(qty: '0', balance: '0')).percent, 0);
    });

    test('stays within the mark when the balance is overdrawn', () {
      final done = dprBoqDone(line(qty: '100', balance: '-5'));
      expect(done.percent, 100);
      expect(done.fraction, 1);
    });
  });
}
