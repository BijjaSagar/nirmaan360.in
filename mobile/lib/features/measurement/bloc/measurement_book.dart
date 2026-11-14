// How a measurement is written in the book. Pure helpers: no widgets, no I/O,
// and no `double` anywhere. Every figure stays a `Qty` or a decimal string.
import 'package:decimal/decimal.dart';

import '../../../core/money/money.dart';

/// A true minus sign, for figures a person reads.
const String figureMinus = '−';

/// The multiplication sign used between factors in the book.
const String _times = '×';

/// `Qty.format()` with a true minus: `−1,23,456.500`.
String formatQty(Qty qty) {
  final String text = qty.format();
  return text.startsWith('-') ? '$figureMinus${text.substring(1)}' : text;
}

/// A factor as written by hand: `10.000` is `10`, `0.500` is `0.5`.
String bookFigure(Qty qty) => qty.value.abs().toString();

/// The factors of an entry in book order, blanks skipped: `2 × 10 × 5 × 0.5`.
/// A deduction carries the minus on its first factor. Null with no Nos.
String? bookFactors({
  required Qty? nos,
  required Qty? length,
  required Qty? breadth,
  required Qty? depth,
  required bool deduction,
}) {
  if (nos == null) return null;
  final factors = <Qty>[nos, ?length, ?breadth, ?depth].map(bookFigure).join(' $_times ');
  return deduction ? '$figureMinus$factors' : factors;
}

/// The whole line: `2 × 10 × 5 × 0.5 = 50.000 cum`. Null with no Nos.
String? bookFormula({
  required Qty? nos,
  required Qty? length,
  required Qty? breadth,
  required Qty? depth,
  required bool deduction,
  required Qty result,
  String? unit,
}) {
  final String? factors = bookFactors(nos: nos, length: length, breadth: breadth, depth: depth, deduction: deduction);
  if (factors == null) return null;
  final String tail = unit == null || unit.isEmpty ? '' : ' $unit';
  return '$factors = ${formatQty(result)}$tail';
}

/// The server's `MeasurementService::isDeviation`, to the digit.
///
/// Approved so far plus this entry, against the BOQ quantity plus its
/// tolerance. Like `Dec::percentOf`, the tolerance is rounded half-up to two
/// places before it is added. An entry at or below zero is never a deviation.
bool isDeviation({required Qty boqQty, required Qty approvedQty, required Qty entryQty, required String tolerancePct}) {
  if (entryQty <= Qty.zero) return false;
  final Decimal percent = Decimal.tryParse(tolerancePct) ?? Decimal.fromInt(5);
  final Money allowance = Money.of(boqQty.value * percent.shift(-2));
  final Decimal limit = boqQty.value + allowance.value;
  return (approvedQty.value + entryQty.value) > limit;
}

/// `5.00` reads as `5` in a sentence, `2.50` as `2.5`.
String tolerancePercentLabel(String tolerancePct) {
  final Decimal? percent = Decimal.tryParse(tolerancePct);
  return percent?.toString() ?? tolerancePct;
}

/// How much of [whole] the [part] is, for drawing a progress mark. A layout
/// value only: it is never shown as a figure or used in a sum. Null when the
/// whole is zero.
double? shareFraction(Qty part, Qty whole) {
  if (whole <= Qty.zero) return null;
  return (part.value.abs() / whole.value).toDouble();
}

/// The same share in words: `50%`, `<1%`, `−12%`. Null when the whole is zero.
String? shareLabel(Qty part, Qty whole) {
  if (whole <= Qty.zero) return null;
  final BigInt percent = ((part.value.abs() * Decimal.fromInt(100)) / whole.value).round();
  if (percent == BigInt.zero) return part.isZero ? '0%' : '<1%';
  return '${part.isNegative ? figureMinus : ''}$percent%';
}
