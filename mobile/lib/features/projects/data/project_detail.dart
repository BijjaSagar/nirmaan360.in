import 'package:decimal/decimal.dart';
import 'package:equatable/equatable.dart';

import '../../../core/money/money.dart';

/// One phase of a project's contract value: foundation, slab, finishing.
class ValuePhase extends Equatable {
  const ValuePhase({required this.name, required this.amount});

  factory ValuePhase.fromJson(Map<String, dynamic> json) =>
      ValuePhase(name: json['name']?.toString() ?? '', amount: Money.fromWire(json['amount']?.toString()));

  final String name;
  final Money amount;

  /// The `phases[]` item of `POST /projects` and `PATCH /projects/{project}`.
  Map<String, String> toWire() => <String, String>{'name': name, 'amount': amount.toWire()};

  @override
  List<Object?> get props => <Object?>[name, amount];
}

/// Each amount's share of their total, in percent with two decimals, as
/// "12.50". The shares add up to exactly 100.00: each is rounded down to the
/// hundredth, and the hundredths left over go one each to the largest
/// remainders, the earlier amount first on a tie. The server works it out the
/// same way (`App\Support\Shares`), so the phone and the website agree.
///
/// Null for every amount when the total is nothing. Exact: no doubles.
List<String?> phaseShares(List<Money> amounts) {
  const int whole = 10000;
  final BigInt hundred = BigInt.from(100);
  final List<BigInt> paise = <BigInt>[
    for (final Money amount in amounts) (amount.value * Decimal.fromBigInt(hundred)).toBigInt(),
  ];
  final BigInt total = paise.fold(BigInt.zero, (sum, value) => sum + value);
  if (total <= BigInt.zero) return List<String?>.filled(amounts.length, null);

  final List<int> units = <int>[];
  final List<BigInt> remainders = <BigInt>[];
  for (final BigInt value in paise) {
    final BigInt scaled = value * BigInt.from(whole);
    units.add((scaled ~/ total).toInt());
    remainders.add(scaled.remainder(total));
  }

  final List<int> order = List<int>.generate(amounts.length, (i) => i)
    ..sort((a, b) {
      final int byRemainder = remainders[b].compareTo(remainders[a]);
      return byRemainder != 0 ? byRemainder : a.compareTo(b);
    });
  final int spare = whole - units.fold(0, (sum, unit) => sum + unit);
  for (final int index in order.take(spare)) {
    units[index]++;
  }

  return <String?>[
    for (final int unit in units) '${unit ~/ 100}.${(unit % 100).toString().padLeft(2, '0')}',
  ];
}

/// A project as the edit screen needs it, from `GET /projects/{project}`.
class ProjectDetail extends Equatable {
  const ProjectDetail({
    required this.id,
    required this.code,
    required this.name,
    this.location,
    this.startDate,
    this.endDate,
    this.contractValue,
    this.phases,
  });

  factory ProjectDetail.fromJson(Map<String, dynamic> json) {
    final List<dynamic>? phases = json['phases'] as List<dynamic>?;
    final String? value = json['contract_value']?.toString();
    return ProjectDetail(
      id: (json['id'] as num).toInt(),
      code: json['code']?.toString() ?? '',
      name: json['name']?.toString() ?? '',
      location: json['location'] as String?,
      startDate: json['start_date'] as String?,
      endDate: json['end_date'] as String?,
      contractValue: value == null ? null : Money.fromWire(value),
      phases: phases?.map((phase) => ValuePhase.fromJson((phase as Map).cast<String, dynamic>())).toList(),
    );
  }

  final int id;
  final String code;
  final String name;
  final String? location;
  final String? startDate;
  final String? endDate;

  /// Null to someone who may not see money.
  final Money? contractValue;

  /// The contract value split into phases, in order. Empty when it is one
  /// figure, and null to someone who may not see money.
  final List<ValuePhase>? phases;

  /// The server sends money only to someone with `money.view`, and takes it
  /// only from them.
  bool get seesMoney => phases != null;

  @override
  List<Object?> get props => <Object?>[id, code, name, location, startDate, endDate, contractValue, phases];
}
