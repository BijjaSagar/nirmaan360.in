import 'package:flutter/material.dart';

/// An icon that stands for a server value, with the English key used to look
/// up its translation. Every picture on the site is paired with a label.
@immutable
class Pictogram {
  const Pictogram(this.icon, this.key);

  final IconData icon;

  /// The server's English value, untouched. For an unknown value this is
  /// whatever the server sent.
  final String key;
}

/// Icons for the server's fixed vocabularies.
///
/// Lookup ignores case and treats underscores as spaces. A value the app has
/// never heard of gets [fallback] rather than a crash, so a new trade added on
/// the web still shows up with its own text.
abstract final class N360Pictograms {
  static const IconData fallback = Icons.label_outline;

  static const List<Pictogram> trades = <Pictogram>[
    Pictogram(Icons.foundation, 'Mason'),
    Pictogram(Icons.back_hand, 'Helper'),
    Pictogram(Icons.carpenter, 'Carpenter'),
    Pictogram(Icons.grid_4x4, 'Bar bender'),
    Pictogram(Icons.electrical_services, 'Electrician'),
    Pictogram(Icons.plumbing, 'Plumber'),
    Pictogram(Icons.format_paint, 'Painter'),
    Pictogram(Icons.local_fire_department, 'Welder'),
    Pictogram(Icons.agriculture, 'Operator'),
    Pictogram(Icons.engineering, 'Supervisor'),
  ];

  static const List<Pictogram> expenseCategories = <Pictogram>[
    Pictogram(Icons.local_gas_station, 'Fuel'),
    Pictogram(Icons.restaurant, 'Food'),
    Pictogram(Icons.directions_bus, 'Travel'),
    Pictogram(Icons.handyman, 'Tools'),
    Pictogram(Icons.inventory_2, 'Materials'),
    Pictogram(Icons.groups, 'Labour'),
    Pictogram(Icons.home_repair_service, 'Repairs'),
    Pictogram(Icons.more_horiz, 'Other'),
  ];

  static const List<Pictogram> weatherKinds = <Pictogram>[
    Pictogram(Icons.wb_sunny, 'Clear'),
    Pictogram(Icons.cloud, 'Cloudy'),
    Pictogram(Icons.umbrella, 'Rain'),
    Pictogram(Icons.air, 'Windy'),
    Pictogram(Icons.thermostat, 'Hot'),
  ];

  static const List<Pictogram> issuePriorities = <Pictogram>[
    Pictogram(Icons.keyboard_arrow_down, 'low'),
    Pictogram(Icons.remove, 'medium'),
    Pictogram(Icons.keyboard_arrow_up, 'high'),
    Pictogram(Icons.priority_high, 'critical'),
  ];

  static const List<Pictogram> issueStatuses = <Pictogram>[
    Pictogram(Icons.radio_button_unchecked, 'open'),
    Pictogram(Icons.timelapse, 'in_progress'),
    Pictogram(Icons.task_alt, 'resolved'),
    Pictogram(Icons.lock_outline, 'closed'),
  ];

  static const List<Pictogram> attendanceStatuses = <Pictogram>[
    Pictogram(Icons.check, 'present'),
    Pictogram(Icons.contrast, 'half'),
    Pictogram(Icons.more_time, 'overtime'),
    Pictogram(Icons.close, 'absent'),
  ];

  static Pictogram trade(String value) => _find(trades, value);
  static Pictogram expense(String value) => _find(expenseCategories, value);
  static Pictogram weather(String value) => _find(weatherKinds, value);
  static Pictogram priority(String value) => _find(issuePriorities, value);
  static Pictogram issueStatus(String value) => _find(issueStatuses, value);
  static Pictogram attendance(String value) => _find(attendanceStatuses, value);

  static Pictogram _find(List<Pictogram> list, String value) {
    final wanted = _normalise(value);
    for (final item in list) {
      if (_normalise(item.key) == wanted) return item;
    }
    return Pictogram(fallback, value);
  }

  static String _normalise(String value) =>
      value.trim().toLowerCase().replaceAll('_', ' ');
}
