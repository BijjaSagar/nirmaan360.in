import 'dart:io';

import 'package:decimal/decimal.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/money/money.dart';

void main() {
  // Every row below was produced by the real App\Support\Dec on PHP 8.5:
  // [input, Dec::qty, Dec::money, indian(2), indian(3), indian(0)].
  const golden = [
    ['0', '0.000', '0.00', '0.00', '0.000', '0'],
    ['0.0005', '0.001', '0.00', '0.00', '0.001', '0'],
    ['0.0004', '0.000', '0.00', '0.00', '0.000', '0'],
    ['-0.0004', '0.000', '0.00', '0.00', '0.000', '0'],
    ['-0.0005', '-0.001', '0.00', '0.00', '-0.001', '0'],
    ['1.0005', '1.001', '1.00', '1.00', '1.001', '1'],
    ['-1.0005', '-1.001', '-1.00', '-1.00', '-1.001', '-1'],
    ['1.0015', '1.002', '1.00', '1.00', '1.002', '1'],
    ['2.5', '2.500', '2.50', '2.50', '2.500', '3'],
    ['-2.5', '-2.500', '-2.50', '-2.50', '-2.500', '-3'],
    ['0.005', '0.005', '0.01', '0.01', '0.005', '0'],
    ['-0.005', '-0.005', '-0.01', '-0.01', '-0.005', '0'],
    ['0.004', '0.004', '0.00', '0.00', '0.004', '0'],
    ['-0.004', '-0.004', '0.00', '0.00', '-0.004', '0'],
    ['1.005', '1.005', '1.01', '1.01', '1.005', '1'],
    ['-1.005', '-1.005', '-1.01', '-1.01', '-1.005', '-1'],
    ['1.015', '1.015', '1.02', '1.02', '1.015', '1'],
    ['999.9995', '1000.000', '1000.00', '1,000.00', '1,000.000', '1,000'],
    ['999.9994', '999.999', '1000.00', '1,000.00', '999.999', '1,000'],
    ['1000', '1000.000', '1000.00', '1,000.00', '1,000.000', '1,000'],
    ['1000.5', '1000.500', '1000.50', '1,000.50', '1,000.500', '1,001'],
    ['12345', '12345.000', '12345.00', '12,345.00', '12,345.000', '12,345'],
    ['123456', '123456.000', '123456.00', '1,23,456.00', '1,23,456.000', '1,23,456'],
    ['1234567', '1234567.000', '1234567.00', '12,34,567.00', '12,34,567.000', '12,34,567'],
    ['12345678', '12345678.000', '12345678.00', '1,23,45,678.00', '1,23,45,678.000', '1,23,45,678'],
    ['123456789', '123456789.000', '123456789.00', '12,34,56,789.00', '12,34,56,789.000', '12,34,56,789'],
    ['1234567890', '1234567890.000', '1234567890.00', '1,23,45,67,890.00', '1,23,45,67,890.000', '1,23,45,67,890'],
    ['123456789012.345', '123456789012.345', '123456789012.35', '1,23,45,67,89,012.35', '1,23,45,67,89,012.345', '1,23,45,67,89,012'],
    ['-1234567.895', '-1234567.895', '-1234567.90', '-12,34,567.90', '-12,34,567.895', '-12,34,568'],
    ['-1234567.894', '-1234567.894', '-1234567.89', '-12,34,567.89', '-12,34,567.894', '-12,34,568'],
    ['99999.995', '99999.995', '100000.00', '1,00,000.00', '99,999.995', '1,00,000'],
    ['100000', '100000.000', '100000.00', '1,00,000.00', '1,00,000.000', '1,00,000'],
    ['100000.004', '100000.004', '100000.00', '1,00,000.00', '1,00,000.004', '1,00,000'],
    ['0.1', '0.100', '0.10', '0.10', '0.100', '0'],
    ['0.2', '0.200', '0.20', '0.20', '0.200', '0'],
    ['0.3', '0.300', '0.30', '0.30', '0.300', '0'],
    ['1234567.8949', '1234567.895', '1234567.89', '12,34,567.89', '12,34,567.895', '12,34,568'],
    ['1234567.8950', '1234567.895', '1234567.90', '12,34,567.90', '12,34,567.895', '12,34,568'],
  ];

  group('matches App\\Support\\Dec for awkward values', () {
    for (final row in golden) {
      test('input ${row[0]}', () {
        final value = Decimal.parse(row[0]);
        expect(Qty.of(value).toWire(), row[1], reason: 'Dec::qty');
        expect(Money.of(value).toWire(), row[2], reason: 'Dec::money');
        expect(indianGroup(value, 2), row[3], reason: 'Dec::indian scale 2');
        expect(indianGroup(value, 3), row[4], reason: 'Dec::indian scale 3');
        expect(indianGroup(value, 0), row[5], reason: 'Dec::indian scale 0');
      });
    }
  });

  group('computeMeasurementQty matches MeasurementEntry::computeQty', () {
    // [nos, length, breadth, depth, isDeduction, expected from PHP computeQty].
    const cases = <List<Object?>>[
      ['2.000', '10.000', '5.000', '0.500', false, '50.000'],
      ['3.333', '3.333', '3.333', '3.333', false, '123.407'],
      ['1.005', '1.005', '1.005', null, false, '1.015'],
      [null, '1.234', '2.345', null, true, '-2.894'],
      [null, null, null, null, false, '1.000'],
      ['2.000', '0.000', '5.000', null, false, '0.000'],
      ['1.111', '1.111', '1.111', '1.111', true, '-1.524'],
      ['0.333', null, '0.333', '0.333', false, '0.037'],
      ['12.345', '67.891', '0.001', '9.999', false, '8.380'],
      ['7.777', '3.141', '2.718', '1.414', true, '-93.881'],
      ['0.500', '0.500', '0.500', '0.500', false, '0.063'],
    ];

    Qty? q(Object? raw) => raw == null ? null : Qty.parse(raw as String);

    for (final c in cases) {
      test('${c.take(5).toList()}', () {
        final result = computeMeasurementQty(
          q(c[0]),
          q(c[1]),
          q(c[2]),
          q(c[3]),
          c[4] as bool,
        );
        expect(result.toWire(), c[5]);
      });
    }

    test('rounds only the final product', () {
      // Rounding at each step would give 123.408; the exact product rounds
      // to 123.407, which is what the server computes.
      final result = computeMeasurementQty(
        Qty.parse('3.333'),
        Qty.parse('3.333'),
        Qty.parse('3.333'),
        Qty.parse('3.333'),
      );
      expect(result.toWire(), '123.407');
    });
  });

  group('wire parsing', () {
    test('null and empty are zero, like Dec::of', () {
      expect(Qty.fromWire(null).toWire(), '0.000');
      expect(Qty.fromWire('').toWire(), '0.000');
      expect(Money.fromWire(null).toWire(), '0.00');
    });

    test('rejects malformed wire values', () {
      expect(() => Qty.parse('abc'), throwsFormatException);
      expect(() => Money.parse('12.3.4'), throwsFormatException);
    });

    test('Hours keep one decimal place', () {
      expect(Hours.parse('2').toWire(), '2.0');
      expect(Hours.parse('2.25').toWire(), '2.3');
      expect(Hours.parse('2.24').toWire(), '2.2');
    });

    test('toWire is always full scale', () {
      expect(Qty.parse('12.5').toWire(), '12.500');
      expect(Money.parse('950').toWire(), '950.00');
    });
  });

  group('user input', () {
    test('accepts grouping commas and spaces', () {
      expect(Money.tryParse(' 12,34,567.50 ')!.toWire(), '1234567.50');
    });

    test('accepts Devanagari digits', () {
      expect(Qty.tryParse('१२.५')!.toWire(), '12.500');
    });

    test('blank or garbage is null', () {
      expect(Qty.tryParse(''), isNull);
      expect(Qty.tryParse('   '), isNull);
      expect(Qty.tryParse('abc'), isNull);
      expect(Qty.tryParse(null), isNull);
    });
  });

  group('arithmetic and display', () {
    test('adds and subtracts exactly', () {
      final total = [
        '0.1',
        '0.2',
      ].map(Money.parse).reduce((a, b) => a + b);
      expect(total, Money.parse('0.30'));
      expect((Qty.parse('5') - Qty.parse('7.25')).toWire(), '-2.250');
    });

    test('equality ignores written scale', () {
      expect(Qty.parse('12.5'), Qty.parse('12.500'));
      expect(Qty.parse('12.5').hashCode, Qty.parse('12.500').hashCode);
      expect(Money.parse('1.00') == Money.parse('1.0'), isTrue);
    });

    test('rupee format matches inr()', () {
      expect(Money.parse('1234567').inr(), '₹12,34,567.00');
      expect(Money.parse('-1234567.5').inr(), '₹-12,34,567.50');
      expect(Money.parse('0').inr(), '₹0.00');
    });

    test('quantity times rate rounds like BoqItem::amount', () {
      final amount = Money.fromQtyRate(Qty.parse('15.125'), Money.parse('7450.55'));
      expect(amount.toWire(), '112689.57');
    });
  });

  test('no floating point in the money, db or feature data folders', () {
    final problems = <String>[];
    final folders = [
      Directory('lib/core/money'),
      Directory('lib/core/db'),
      ...Directory('lib/features')
          .listSync()
          .whereType<Directory>()
          .map((feature) => Directory('${feature.path}/data')),
    ];

    for (final folder in folders) {
      if (!folder.existsSync()) continue;
      for (final entity in folder.listSync(recursive: true)) {
        if (entity is! File || !entity.path.endsWith('.dart')) continue;
        final text = entity.readAsStringSync();
        if (RegExp(r'\bdouble\b|\btoDouble\b').hasMatch(text)) {
          problems.add(entity.path);
        }
      }
    }
    expect(
      problems,
      isEmpty,
      reason: 'Money and quantity must never pass through double: $problems',
    );
  });
}
