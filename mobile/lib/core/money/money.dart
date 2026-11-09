// Exact decimal arithmetic for quantities, money and hours.
//
// Mirrors App\Support\Dec on the server: values are held as Decimal, rounded
// half-up (away from zero on ties) to a fixed scale, and travel as strings.
// Binary floating point must never appear here or anywhere figures flow.
import 'package:decimal/decimal.dart';
import 'package:flutter/foundation.dart';

/// Groups digits the Indian way, matching `Dec::indian`: 12,34,567.00.
///
/// Rounds half-up to [scale] first. A negative value that rounds to zero
/// prints without a sign, exactly like the server.
String indianGroup(Decimal value, int scale) {
  var fixed = value.toStringAsFixed(scale);
  final negative = fixed.startsWith('-');
  if (negative) fixed = fixed.substring(1);

  final dot = fixed.indexOf('.');
  var whole = dot < 0 ? fixed : fixed.substring(0, dot);
  final fraction = dot < 0 ? null : fixed.substring(dot + 1);

  if (whole.length > 3) {
    final tail = whole.substring(whole.length - 3);
    final head = whole.substring(0, whole.length - 3);
    final groupedHead = head.replaceAllMapped(
      RegExp(r'\B(?=(\d{2})+(?!\d))'),
      (_) => ',',
    );
    whole = '$groupedHead,$tail';
  }

  return '${negative ? '-' : ''}$whole${fraction != null ? '.$fraction' : ''}';
}

/// Parses text the user typed: spaces and grouping commas are ignored and
/// Devanagari digits are accepted. Returns null for blank or invalid text.
Decimal? _parseUserText(String? input) {
  if (input == null) return null;
  final cleaned = _normaliseDigits(input).replaceAll(RegExp(r'[,\s]'), '');
  if (cleaned.isEmpty) return null;
  return Decimal.tryParse(cleaned);
}

String _normaliseDigits(String input) {
  const devanagariZero = 0x0966;
  final out = StringBuffer();
  for (final unit in input.runes) {
    if (unit >= devanagariZero && unit <= devanagariZero + 9) {
      out.writeCharCode(unit - devanagariZero + 0x30);
    } else {
      out.writeCharCode(unit);
    }
  }
  return out.toString();
}

/// `Dec::of` semantics: null or empty means zero. Anything else must parse.
Decimal _ofWire(String? raw) {
  if (raw == null || raw.isEmpty) return Decimal.zero;
  return Decimal.parse(raw);
}

/// Shared behaviour for the fixed-scale value types below.
@immutable
abstract class ScaledDecimal<T extends ScaledDecimal<T>>
    implements Comparable<T> {
  const ScaledDecimal(this.value);

  final Decimal value;

  /// Decimal places this type carries.
  int get scale;

  T _wrap(Decimal value);

  T operator +(T other) => _wrap(value + other.value);
  T operator -(T other) => _wrap(value - other.value);
  T operator -() => _wrap(-value);

  T abs() => _wrap(value.abs());

  bool operator <(T other) => value < other.value;
  bool operator <=(T other) => value <= other.value;
  bool operator >(T other) => value > other.value;
  bool operator >=(T other) => value >= other.value;

  bool get isZero => value == Decimal.zero;
  bool get isNegative => value < Decimal.zero;

  @override
  int compareTo(T other) => value.compareTo(other.value);

  /// The string the API expects, always with the full scale: `"12.500"`.
  String toWire() => value.toStringAsFixed(scale);

  /// Indian digit grouping, like `Dec::indian($value, $scale)`.
  String indian({int? scale}) => indianGroup(value, scale ?? this.scale);

  @override
  bool operator ==(Object other) =>
      other.runtimeType == runtimeType &&
      other is ScaledDecimal &&
      other.value == value;

  @override
  int get hashCode => Object.hash(runtimeType, value);

  @override
  String toString() => toWire();
}

/// A quantity at scale 3.
final class Qty extends ScaledDecimal<Qty> {
  const Qty._(super.value);

  /// Rounds [value] half-up to scale 3, like `Dec::qty`.
  factory Qty.of(Decimal value) => Qty._(value.round(scale: 3));

  /// Parses a wire string. Null or empty is zero, like `Dec::of`.
  factory Qty.fromWire(String? raw) => Qty.of(_ofWire(raw));

  /// Parses a string, throwing [FormatException] when it is not a number.
  factory Qty.parse(String raw) => Qty.of(Decimal.parse(raw));

  factory Qty.fromInt(int value) => Qty.of(Decimal.fromInt(value));

  /// Parses user-typed text; null when blank or not a number.
  static Qty? tryParse(String? input) {
    final parsed = _parseUserText(input);
    return parsed == null ? null : Qty.of(parsed);
  }

  static final Qty zero = Qty.of(Decimal.zero);
  static final Qty one = Qty.of(Decimal.one);

  @override
  int get scale => 3;

  @override
  Qty _wrap(Decimal value) => Qty.of(value);

  /// Display form, like `fmt_qty`: 12,34,567.500.
  String format() => indian();
}

/// A money amount at scale 2.
final class Money extends ScaledDecimal<Money> {
  const Money._(super.value);

  /// Rounds [value] half-up to scale 2, like `Dec::money`.
  factory Money.of(Decimal value) => Money._(value.round(scale: 2));

  /// Parses a wire string. Null or empty is zero, like `Dec::of`.
  factory Money.fromWire(String? raw) => Money.of(_ofWire(raw));

  /// Parses a string, throwing [FormatException] when it is not a number.
  factory Money.parse(String raw) => Money.of(Decimal.parse(raw));

  factory Money.fromInt(int value) => Money.of(Decimal.fromInt(value));

  /// Parses user-typed text; null when blank or not a number.
  static Money? tryParse(String? input) {
    final parsed = _parseUserText(input);
    return parsed == null ? null : Money.of(parsed);
  }

  /// True when [input] has more decimal places than money holds, so parsing it
  /// would round what someone typed.
  static bool hasExtraDecimals(String? input) => (_parseUserText(input)?.scale ?? 0) > 2;

  /// Quantity times rate, rounded to money precision like `BoqItem::amount`.
  factory Money.fromQtyRate(Qty qty, Money rate) =>
      Money.of(qty.value * rate.value);

  static final Money zero = Money.of(Decimal.zero);

  @override
  int get scale => 2;

  @override
  Money _wrap(Decimal value) => Money.of(value);

  /// Display form, like `inr()`: ₹12,34,567.00.
  String inr() => '₹${indian()}';

  String format() => inr();
}

/// Hours at scale 1, for overtime and machinery. Sent as `"2.5"`.
final class Hours extends ScaledDecimal<Hours> {
  const Hours._(super.value);

  factory Hours.of(Decimal value) => Hours._(value.round(scale: 1));

  factory Hours.fromWire(String? raw) => Hours.of(_ofWire(raw));

  factory Hours.parse(String raw) => Hours.of(Decimal.parse(raw));

  factory Hours.fromInt(int value) => Hours.of(Decimal.fromInt(value));

  static Hours? tryParse(String? input) {
    final parsed = _parseUserText(input);
    return parsed == null ? null : Hours.of(parsed);
  }

  static final Hours zero = Hours.of(Decimal.zero);

  @override
  int get scale => 1;

  @override
  Hours _wrap(Decimal value) => Hours.of(value);
}

/// Nos × L × B × D, matching `MeasurementEntry::computeQty` to the digit.
///
/// A null [nos] counts as one. Null dimensions are skipped; a dimension of
/// zero is not blank and gives zero. The product is exact and only the final
/// result is rounded. Deductions come out negative.
Qty computeMeasurementQty(
  Qty? nos,
  Qty? length,
  Qty? breadth,
  Qty? depth, [
  bool isDeduction = false,
]) {
  var product = (nos ?? Qty.one).value;
  for (final dimension in [length, breadth, depth]) {
    if (dimension != null) product = product * dimension.value;
  }
  return Qty.of(isDeduction ? -product : product);
}
