import 'package:equatable/equatable.dart';

import '../../../core/money/money.dart';

// What `GET /price-watch`, `/price-watch/materials/{id}` and
// `/projects/{id}/price-watch` return, read tolerantly. PHP sends an empty
// array where a map is expected, and a row with no id could not be opened, so
// it is left out rather than allowed to stop the screen.
//
// Every one of these is a price somebody wrote down with a date. The server
// says so itself (`meta.live_feed` is false), and nothing here or on a screen
// treats one as anything else.

Map<String, dynamic> _map(Object? value) => value is Map ? value.cast<String, dynamic>() : const <String, dynamic>{};

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? <Map<String, dynamic>>[
        for (final Object? row in value)
          if (row is Map) row.cast<String, dynamic>(),
      ]
    : const <Map<String, dynamic>>[];

Money? _money(Object? raw) {
  if (raw == null) return null;
  try {
    return Money.fromWire(raw.toString());
  } on FormatException {
    return null;
  }
}

Qty? _qty(Object? raw) {
  if (raw == null) return null;
  try {
    return Qty.fromWire(raw.toString());
  } on FormatException {
    return null;
  }
}

String? _text(Object? raw) {
  final String? text = raw?.toString().trim();
  return text == null || text.isEmpty ? null : text;
}

int? _int(Object? raw) => raw is num ? raw.toInt() : int.tryParse('${raw ?? ''}');

/// How long a price may stand before it is called out of date, when the
/// server has not said.
const int kDefaultStaleDays = 30;

/// One price somebody recorded, with the day it was recorded for.
class RecordedPrice extends Equatable {
  const RecordedPrice({
    required this.id,
    required this.rate,
    required this.pricedOn,
    this.unit,
    this.source,
    this.sourceKind,
    this.notedBy,
  });

  static RecordedPrice? tryFromJson(Object? raw) {
    final Map<String, dynamic> json = _map(raw);
    final int? id = _int(json['id']);
    final Money? rate = _money(json['rate']);
    final String? pricedOn = _text(json['priced_on']);
    if (id == null || rate == null || pricedOn == null) return null;
    return RecordedPrice(
      id: id,
      rate: rate,
      pricedOn: pricedOn,
      unit: _text(json['unit']),
      source: _text(json['source']),
      sourceKind: _text(json['source_kind']),
      notedBy: _text(json['noted_by']),
    );
  }

  final int id;
  final Money rate;

  /// `YYYY-MM-DD`: the day the price was recorded for.
  final String pricedOn;
  final String? unit;

  /// Where the price came from, in the company's own words: "Dealer quote".
  final String? source;
  final String? sourceKind;
  final String? notedBy;

  /// Out of date: older than [staleDays] whole days on [today]. Counted on
  /// the phone, so a copy kept for a month does not claim to be fresh.
  bool isStale(String today, int staleDays) {
    final DateTime? on = DateTime.tryParse(pricedOn);
    final DateTime? now = DateTime.tryParse(today);
    if (on == null || now == null) return false;
    final DateTime limit = DateTime.utc(on.year, on.month, on.day).add(Duration(days: staleDays));
    return limit.isBefore(DateTime.utc(now.year, now.month, now.day));
  }

  @override
  List<Object?> get props => <Object?>[id, rate, pricedOn, unit, source, sourceKind, notedBy];
}

/// One point of the short history on a board row.
class PricePoint extends Equatable {
  const PricePoint({required this.rate, required this.pricedOn});

  static PricePoint? tryFromJson(Object? raw) {
    final Map<String, dynamic> json = _map(raw);
    final Money? rate = _money(json['rate']);
    final String? pricedOn = _text(json['priced_on']);
    return rate == null || pricedOn == null ? null : PricePoint(rate: rate, pricedOn: pricedOn);
  }

  final Money rate;
  final String pricedOn;

  @override
  List<Object?> get props => <Object?>[rate, pricedOn];
}

/// A material the company watches, with its latest recorded price.
class WatchedMaterial extends Equatable {
  const WatchedMaterial({
    required this.id,
    required this.name,
    required this.unit,
    this.key,
    this.isActive = true,
    this.current,
    this.previous,
    this.changePct,
    this.trend = const <PricePoint>[],
    this.count = 0,
    this.prices,
  });

  static WatchedMaterial? tryFromJson(Object? raw) {
    final Map<String, dynamic> json = _map(raw);
    final int? id = _int(json['id']);
    if (id == null) return null;
    return WatchedMaterial(
      id: id,
      key: _text(json['key']),
      name: _text(json['name']) ?? '',
      unit: _text(json['unit']) ?? '',
      isActive: json['is_active'] != false,
      current: RecordedPrice.tryFromJson(json['current']),
      previous: RecordedPrice.tryFromJson(json['previous']),
      changePct: _text(json['change_pct']),
      trend: <PricePoint>[for (final Map<String, dynamic> p in _rows(json['trend'])) ?PricePoint.tryFromJson(p)],
      count: _int(json['count']) ?? 0,
      prices: json['prices'] is List
          ? <RecordedPrice>[for (final Map<String, dynamic> p in _rows(json['prices'])) ?RecordedPrice.tryFromJson(p)]
          : null,
    );
  }

  final int id;
  final String? key;
  final String name;

  /// What the price is per: "bag", "kg".
  final String unit;
  final bool isActive;

  /// The newest recorded price. Null when none has been recorded.
  final RecordedPrice? current;
  final RecordedPrice? previous;

  /// Percent change from [previous] to [current], two places, signed.
  final String? changePct;

  /// The last few prices, oldest first.
  final List<PricePoint> trend;
  final int count;

  /// Every recorded price, newest first. Null until the material has been opened.
  final List<RecordedPrice>? prices;

  bool get hasPrice => current != null;

  @override
  List<Object?> get props => <Object?>[id, key, name, unit, isActive, current, previous, changePct, trend, count, prices];
}

/// The company's board as the phone last read it.
class PriceBoardCopy extends Equatable {
  const PriceBoardCopy({
    this.refused = false,
    this.rows = const <WatchedMaterial>[],
    this.histories = const <int, WatchedMaterial>{},
    this.asOf,
    this.staleAfterDays = kDefaultStaleDays,
    this.fetchedAt,
  });

  factory PriceBoardCopy.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> meta = _map(json['meta']);
    final Map<String, dynamic> histories = _map(json['histories']);
    return PriceBoardCopy(
      refused: json['refused'] == true,
      rows: <WatchedMaterial>[for (final Map<String, dynamic> r in _rows(json['data'])) ?WatchedMaterial.tryFromJson(r)],
      histories: <int, WatchedMaterial>{
        for (final Object? value in histories.values)
          if (WatchedMaterial.tryFromJson(value) case final WatchedMaterial material) material.id: material,
      },
      asOf: _text(meta['as_of']),
      staleAfterDays: _int(meta['stale_after_days']) ?? kDefaultStaleDays,
      fetchedAt: DateTime.tryParse('${json['fetched_at'] ?? ''}'),
    );
  }

  final bool refused;
  final List<WatchedMaterial> rows;
  final Map<int, WatchedMaterial> histories;
  final String? asOf;
  final int staleAfterDays;
  final DateTime? fetchedAt;

  /// The material with its whole list of prices when they have been read,
  /// else its board row.
  WatchedMaterial? material(int id) {
    final WatchedMaterial? opened = histories[id];
    if (opened != null) return opened;
    for (final WatchedMaterial row in rows) {
      if (row.id == id) return row;
    }
    return null;
  }

  @override
  List<Object?> get props => <Object?>[refused, rows, histories, asOf, staleAfterDays, fetchedAt];
}

/// Why a material has no effect on the budget.
enum ImpactGap { noPrice, noBoqRate, noQty, unitMismatch }

ImpactGap? _gap(String? raw) => switch (raw) {
  'no_price' => ImpactGap.noPrice,
  'no_boq_rate' => ImpactGap.noBoqRate,
  'no_qty' => ImpactGap.noQty,
  'unit_mismatch' => ImpactGap.unitMismatch,
  _ => null,
};

/// What one material's latest recorded price does to a project's budget:
/// `(price - BOQ rate) x quantity still to buy`.
class MaterialImpact extends Equatable {
  const MaterialImpact({
    required this.material,
    this.boqRate,
    this.boqQty,
    this.difference,
    this.abovePct,
    this.need,
    this.needBasis,
    this.received,
    this.remaining,
    this.impact,
    this.gap,
  });

  static MaterialImpact? tryFromJson(Object? raw) {
    final Map<String, dynamic> json = _map(raw);
    final WatchedMaterial? material = WatchedMaterial.tryFromJson(json);
    if (material == null) return null;
    return MaterialImpact(
      material: material,
      boqRate: _money(json['boq_rate']),
      boqQty: _qty(json['boq_qty']),
      difference: _money(json['difference']),
      abovePct: _text(json['above_boq_pct']),
      need: _qty(json['need']),
      needBasis: _text(json['need_basis']),
      received: _qty(json['received']),
      remaining: _qty(json['remaining']),
      impact: _money(json['impact']),
      gap: _gap(_text(json['why'])),
    );
  }

  final WatchedMaterial material;
  final Money? boqRate;
  final Qty? boqQty;

  /// Latest recorded price less the BOQ rate. Negative when the price is below it.
  final Money? difference;
  final String? abovePct;
  final Qty? need;

  /// `norms` when the BOQ's material norms gave the need, `typed` when a
  /// person typed it in for this project.
  final String? needBasis;
  final Qty? received;
  final Qty? remaining;

  /// The effect on the budget. Negative when the price is below the BOQ rate.
  final Money? impact;
  final ImpactGap? gap;

  @override
  List<Object?> get props => <Object?>[
    material,
    boqRate,
    boqQty,
    difference,
    abovePct,
    need,
    needBasis,
    received,
    remaining,
    impact,
    gap,
  ];
}

class ImpactSummary extends Equatable {
  const ImpactSummary({
    this.impact,
    this.freshImpact,
    this.impactPct,
    this.basis,
    this.budget,
    this.ceiling,
    this.boq,
    this.projected,
    this.beyondMargin = false,
    this.materials = 0,
    this.withImpact = 0,
  });

  factory ImpactSummary.fromJson(Map<String, dynamic> json) => ImpactSummary(
    impact: _money(json['impact']),
    freshImpact: _money(json['fresh_impact']),
    impactPct: _text(json['impact_pct']),
    basis: _text(json['basis']),
    budget: _money(json['budget']),
    ceiling: _money(json['ceiling']),
    boq: _money(json['boq']),
    projected: _money(json['projected']),
    beyondMargin: json['beyond_margin'] == true,
    materials: _int(json['materials']) ?? 0,
    withImpact: _int(json['with_impact']) ?? 0,
  );

  final Money? impact;

  /// The same, from prices that are not out of date.
  final Money? freshImpact;
  final String? impactPct;

  /// What [impactPct] is a share of: `budget`, or `boq` when no budget is set.
  final String? basis;
  final Money? budget;
  final Money? ceiling;
  final Money? boq;

  /// The BOQ with the rest of the material bought at the recorded prices.
  final Money? projected;
  final bool beyondMargin;
  final int materials;
  final int withImpact;

  @override
  List<Object?> get props => <Object?>[
    impact,
    freshImpact,
    impactPct,
    basis,
    budget,
    ceiling,
    boq,
    projected,
    beyondMargin,
    materials,
    withImpact,
  ];
}

/// One project's budget effect as the phone last read it.
class ProjectImpactCopy extends Equatable {
  const ProjectImpactCopy({
    this.refused = false,
    this.rows = const <MaterialImpact>[],
    this.summary = const ImpactSummary(),
    this.asOf,
    this.staleAfterDays = kDefaultStaleDays,
    this.fetchedAt,
  });

  factory ProjectImpactCopy.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> meta = _map(json['meta']);
    return ProjectImpactCopy(
      refused: json['refused'] == true,
      rows: <MaterialImpact>[for (final Map<String, dynamic> r in _rows(json['data'])) ?MaterialImpact.tryFromJson(r)],
      summary: ImpactSummary.fromJson(_map(json['summary'])),
      asOf: _text(meta['as_of']),
      staleAfterDays: _int(meta['stale_after_days']) ?? kDefaultStaleDays,
      fetchedAt: DateTime.tryParse('${json['fetched_at'] ?? ''}'),
    );
  }

  final bool refused;
  final List<MaterialImpact> rows;
  final ImpactSummary summary;
  final String? asOf;
  final int staleAfterDays;
  final DateTime? fetchedAt;

  MaterialImpact? forMaterial(int id) {
    for (final MaterialImpact row in rows) {
      if (row.material.id == id) return row;
    }
    return null;
  }

  @override
  List<Object?> get props => <Object?>[refused, rows, summary, asOf, staleAfterDays, fetchedAt];
}
