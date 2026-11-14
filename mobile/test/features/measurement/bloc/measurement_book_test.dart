import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';
import 'package:nirmaan360/features/measurement/bloc/measurement_book.dart';

Qty q(String value) => Qty.parse(value);

void main() {
  group('the formula as written in the book', () {
    test('trims factors and keeps the result at three places', () {
      expect(
        bookFormula(
          nos: q('2'),
          length: q('10.000'),
          breadth: q('5'),
          depth: q('0.500'),
          deduction: false,
          result: q('50'),
          unit: 'cum',
        ),
        '2 × 10 × 5 × 0.5 = 50.000 cum',
      );
    });

    test('blank dimensions are skipped', () {
      expect(
        bookFormula(
          nos: q('3'),
          length: q('12.5'),
          breadth: null,
          depth: null,
          deduction: false,
          result: q('37.5'),
          unit: 'rmt',
        ),
        '3 × 12.5 = 37.500 rmt',
      );
    });

    test('a deduction carries its minus on the first factor and the result', () {
      expect(
        bookFormula(
          nos: q('1'),
          length: q('1.2'),
          breadth: q('0.9'),
          depth: null,
          deduction: true,
          result: q('-1.08'),
          unit: 'sqm',
        ),
        '−1 × 1.2 × 0.9 = −1.080 sqm',
      );
    });

    test('there is no formula without Nos', () {
      expect(
        bookFormula(nos: null, length: q('10'), breadth: null, depth: null, deduction: false, result: Qty.zero),
        isNull,
      );
    });

    test('a factor prints exactly, however small or large', () {
      expect(bookFigure(q('0.001')), '0.001');
      expect(bookFigure(q('99999999.000')), '99999999');
      expect(bookFigure(q('1000000.500')), '1000000.5');
    });
  });

  group('deviation follows MeasurementService::isDeviation', () {
    bool over(String boq, String approved, String entry, String pct) =>
        isDeviation(boqQty: q(boq), approvedQty: q(approved), entryQty: q(entry), tolerancePct: pct);

    test('exactly at BOQ plus tolerance is allowed; one thousandth more is not', () {
      expect(over('100', '0', '105.000', '5.00'), isFalse);
      expect(over('100', '0', '105.001', '5.00'), isTrue);
    });

    test('counts what is already approved', () {
      expect(over('100', '60', '45', '5'), isFalse);
      expect(over('100', '60', '45.001', '5'), isTrue);
    });

    test('rounds the allowance half-up to two places, like Dec::percentOf', () {
      // 5% of 33.333 is 1.66665, which the server rounds to 1.67.
      expect(over('33.333', '0', '35.003', '5'), isFalse);
      expect(over('33.333', '0', '35.004', '5'), isTrue);
    });

    test('a deduction is never a deviation', () {
      expect(over('100', '200', '-5', '5'), isFalse);
      expect(over('100', '0', '0', '5'), isFalse);
    });

    test('zero tolerance leaves no slack', () {
      expect(over('100', '0', '100', '0.00'), isFalse);
      expect(over('100', '0', '100.001', '0.00'), isTrue);
    });
  });

  group('share of BOQ', () {
    test('rounds to a whole percent', () {
      expect(shareLabel(q('50'), q('100')), '50%');
      expect(shareLabel(q('33.5'), q('100')), '34%');
      expect(shareLabel(q('625'), q('100')), '625%');
    });

    test('never rounds a real entry down to nothing', () {
      expect(shareLabel(q('0.001'), q('100000')), '<1%');
      expect(shareLabel(Qty.zero, q('100')), '0%');
    });

    test('a deduction reads negative but its mark is a size', () {
      expect(shareLabel(q('-12'), q('100')), '−12%');
      expect(shareFraction(q('-12'), q('100')), closeTo(0.12, 0.0001));
    });

    test('a BOQ of nothing has no share', () {
      expect(shareLabel(q('5'), Qty.zero), isNull);
      expect(shareFraction(q('5'), Qty.zero), isNull);
    });
  });

  test('figures read with a true minus and Indian grouping', () {
    expect(formatQty(q('-1234567.5')), '−12,34,567.500');
    expect(formatQty(q('50')), '50.000');
    expect(tolerancePercentLabel('5.00'), '5');
    expect(tolerancePercentLabel('2.50'), '2.5');
  });
}
