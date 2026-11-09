import 'package:equatable/equatable.dart';

List<Map<String, dynamic>> _rows(Object? value) => value is List
    ? <Map<String, dynamic>>[
        for (final Object? row in value)
          if (row is Map) row.cast<String, dynamic>(),
      ]
    : const <Map<String, dynamic>>[];

enum AlertSeverity {
  critical,
  warning,
  info;

  static AlertSeverity parse(Object? raw) => switch (raw) {
    'critical' => AlertSeverity.critical,
    'warning' => AlertSeverity.warning,
    _ => AlertSeverity.info,
  };
}

/// One cause that needs attention. The server wrote the words, in the
/// reader's language.
class SiteAlert extends Equatable {
  const SiteAlert({
    required this.id,
    required this.severity,
    required this.title,
    required this.message,
    this.firstSeen,
  });

  factory SiteAlert.fromJson(Map<String, dynamic> json) => SiteAlert(
    id: (json['id'] as num).toInt(),
    severity: AlertSeverity.parse(json['severity']),
    title: json['title'] as String? ?? '',
    message: json['message'] as String? ?? '',
    firstSeen: _date(json['first_seen_at']),
  );

  final int id;
  final AlertSeverity severity;
  final String title;
  final String message;

  /// `YYYY-MM-DD`, in India's time as the server sent it.
  final String? firstSeen;

  static String? _date(Object? raw) => raw is String && raw.length >= 10 ? raw.substring(0, 10) : null;

  @override
  List<Object?> get props => <Object?>[id, severity, title, message, firstSeen];
}

enum CardTone {
  ok,
  warn,
  bad,
  neutral;

  static CardTone parse(Object? raw) => switch (raw) {
    'ok' => CardTone.ok,
    'warn' => CardTone.warn,
    'bad' => CardTone.bad,
    _ => CardTone.neutral,
  };
}

class CardMeter extends Equatable {
  const CardMeter({required this.label, required this.percent, required this.tone});

  factory CardMeter.fromJson(Map<String, dynamic> json) => CardMeter(
    label: json['label'] as String? ?? '',
    percent: json['percent']?.toString(),
    tone: CardTone.parse(json['tone']),
  );

  final String label;
  final String? percent;
  final CardTone tone;

  @override
  List<Object?> get props => <Object?>[label, percent, tone];
}

class CardLine extends Equatable {
  const CardLine({required this.label, this.sub, this.value, required this.tone});

  factory CardLine.fromJson(Map<String, dynamic> json) => CardLine(
    label: json['label'] as String? ?? '',
    sub: json['sub'] as String?,
    value: json['value']?.toString(),
    tone: CardTone.parse(json['tone']),
  );

  final String label;
  final String? sub;
  final String? value;
  final CardTone tone;

  @override
  List<Object?> get props => <Object?>[label, sub, value, tone];
}

/// One card of the site dashboard. The server decides what is on it, so a
/// money figure a person may not see is simply not here.
class SiteCard extends Equatable {
  const SiteCard({
    required this.key,
    required this.title,
    required this.value,
    this.note,
    required this.tone,
    required this.meters,
    required this.lines,
    required this.money,
  });

  factory SiteCard.fromJson(Map<String, dynamic> json) => SiteCard(
    key: json['key'] as String? ?? '',
    title: json['title'] as String? ?? '',
    value: json['value']?.toString() ?? '',
    note: json['note'] as String?,
    tone: CardTone.parse(json['tone']),
    meters: _rows(json['meters']).map(CardMeter.fromJson).toList(growable: false),
    lines: _rows(json['rows']).map(CardLine.fromJson).toList(growable: false),
    money: json['money'] == true,
  );

  final String key;
  final String title;
  final String value;
  final String? note;
  final CardTone tone;
  final List<CardMeter> meters;
  final List<CardLine> lines;
  final bool money;

  @override
  List<Object?> get props => <Object?>[key, title, value, note, tone, meters, lines, money];
}

/// The last dashboard and open alerts this phone read for a project.
class SiteOverview extends Equatable {
  const SiteOverview({
    required this.cards,
    required this.alerts,
    required this.alertsTotal,
    required this.dashboardRefused,
    required this.alertsRefused,
    this.fetchedAt,
  });

  factory SiteOverview.fromJson(Map<String, dynamic> json) {
    final Object? dashboard = json['dashboard'];
    final Object? alerts = json['alerts'];
    final List<SiteAlert> rows = alerts is Map
        ? _rows(alerts['data']).map(SiteAlert.fromJson).toList(growable: false)
        : const <SiteAlert>[];
    return SiteOverview(
      cards: dashboard is Map ? _rows(dashboard['cards']).map(SiteCard.fromJson).toList(growable: false) : const <SiteCard>[],
      alerts: rows,
      alertsTotal: alerts is Map ? ((alerts['total'] as num?)?.toInt() ?? rows.length) : 0,
      dashboardRefused: dashboard is! Map,
      alertsRefused: alerts is! Map,
      fetchedAt: DateTime.tryParse(json['fetched_at'] as String? ?? ''),
    );
  }

  final List<SiteCard> cards;
  final List<SiteAlert> alerts;

  /// May be more than [alerts] holds: the phone reads the worst page only.
  final int alertsTotal;

  /// The server said no to the dashboard, so there is nothing to show for it.
  final bool dashboardRefused;
  final bool alertsRefused;
  final DateTime? fetchedAt;

  bool get refused => dashboardRefused && alertsRefused;

  /// The alerts have their own tab, so their card would only repeat it.
  List<SiteCard> get dashboardCards => cards.where((SiteCard card) => card.key != 'alerts').toList(growable: false);

  int get criticalCount => alerts.where((SiteAlert alert) => alert.severity == AlertSeverity.critical).length;

  /// The worst thing open. Alerts arrive worst first.
  AlertSeverity? get worst => alerts.isEmpty ? null : alerts.first.severity;

  /// A copy kept from before someone lost the money permission must not show
  /// them what it held. The next read replaces it.
  SiteOverview forViewer({required bool seesMoney}) {
    if (seesMoney) return this;
    return SiteOverview(
      cards: cards.where((SiteCard card) => !card.money).toList(growable: false),
      alerts: alerts,
      alertsTotal: alertsTotal,
      dashboardRefused: dashboardRefused,
      alertsRefused: alertsRefused,
      fetchedAt: fetchedAt,
    );
  }

  @override
  List<Object?> get props => <Object?>[cards, alerts, alertsTotal, dashboardRefused, alertsRefused, fetchedAt];
}
