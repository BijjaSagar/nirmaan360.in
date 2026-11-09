import 'dart:convert';

import 'package:drift/drift.dart';

import '../money/money.dart';

/// Decimal columns are TEXT holding the wire string, so they round-trip
/// byte-for-byte. Never SUM them in SQL: sqlite would use floating point.
class QtyConverter extends TypeConverter<Qty, String> {
  const QtyConverter();

  @override
  Qty fromSql(String fromDb) => Qty.fromWire(fromDb);

  @override
  String toSql(Qty value) => value.toWire();
}

class MoneyConverter extends TypeConverter<Money, String> {
  const MoneyConverter();

  @override
  Money fromSql(String fromDb) => Money.fromWire(fromDb);

  @override
  String toSql(Money value) => value.toWire();
}

class HoursConverter extends TypeConverter<Hours, String> {
  const HoursConverter();

  @override
  Hours fromSql(String fromDb) => Hours.fromWire(fromDb);

  @override
  String toSql(Hours value) => value.toWire();
}

/// One manpower line on a DPR.
class ManpowerLine {
  const ManpowerLine({required this.trade, required this.count});

  factory ManpowerLine.fromJson(Map<String, dynamic> json) => ManpowerLine(
    trade: json['trade'] as String,
    count: (json['count'] as num).toInt(),
  );

  final String trade;
  final int count;

  Map<String, dynamic> toJson() => {'trade': trade, 'count': count};
}

/// One machinery line on a DPR.
class MachineryLine {
  const MachineryLine({required this.name, required this.hours});

  factory MachineryLine.fromJson(Map<String, dynamic> json) => MachineryLine(
    name: json['name'] as String,
    hours: Hours.fromWire(json['hours']?.toString()),
  );

  final String name;
  final Hours hours;

  Map<String, dynamic> toJson() => {'name': name, 'hours': hours.toWire()};
}

/// One work-done line on a DPR.
class WorkLine {
  const WorkLine({this.boqItemId, this.description, required this.qty});

  factory WorkLine.fromJson(Map<String, dynamic> json) => WorkLine(
    boqItemId: (json['boq_item_id'] as num?)?.toInt(),
    description: json['description'] as String?,
    qty: Qty.fromWire(json['qty']?.toString()),
  );

  final int? boqItemId;
  final String? description;
  final Qty qty;

  Map<String, dynamic> toJson() => {
    'boq_item_id': boqItemId,
    'description': description,
    'qty': qty.toWire(),
  };
}

class ManpowerLinesConverter extends TypeConverter<List<ManpowerLine>, String> {
  const ManpowerLinesConverter();

  @override
  List<ManpowerLine> fromSql(String fromDb) => (jsonDecode(fromDb) as List)
      .map((e) => ManpowerLine.fromJson(e as Map<String, dynamic>))
      .toList(growable: false);

  @override
  String toSql(List<ManpowerLine> value) =>
      jsonEncode(value.map((e) => e.toJson()).toList());
}

class MachineryLinesConverter
    extends TypeConverter<List<MachineryLine>, String> {
  const MachineryLinesConverter();

  @override
  List<MachineryLine> fromSql(String fromDb) => (jsonDecode(fromDb) as List)
      .map((e) => MachineryLine.fromJson(e as Map<String, dynamic>))
      .toList(growable: false);

  @override
  String toSql(List<MachineryLine> value) =>
      jsonEncode(value.map((e) => e.toJson()).toList());
}

class WorkLinesConverter extends TypeConverter<List<WorkLine>, String> {
  const WorkLinesConverter();

  @override
  List<WorkLine> fromSql(String fromDb) => (jsonDecode(fromDb) as List)
      .map((e) => WorkLine.fromJson(e as Map<String, dynamic>))
      .toList(growable: false);

  @override
  String toSql(List<WorkLine> value) =>
      jsonEncode(value.map((e) => e.toJson()).toList());
}

class StringListConverter extends TypeConverter<List<String>, String> {
  const StringListConverter();

  @override
  List<String> fromSql(String fromDb) =>
      (jsonDecode(fromDb) as List).map((e) => e.toString()).toList();

  @override
  String toSql(List<String> value) => jsonEncode(value);
}

class JsonMapConverter extends TypeConverter<Map<String, dynamic>, String> {
  const JsonMapConverter();

  @override
  Map<String, dynamic> fromSql(String fromDb) =>
      (jsonDecode(fromDb) as Map).cast<String, dynamic>();

  @override
  String toSql(Map<String, dynamic> value) => jsonEncode(value);
}

class JsonListConverter extends TypeConverter<List<dynamic>, String> {
  const JsonListConverter();

  @override
  List<dynamic> fromSql(String fromDb) => jsonDecode(fromDb) as List<dynamic>;

  @override
  String toSql(List<dynamic> value) => jsonEncode(value);
}
