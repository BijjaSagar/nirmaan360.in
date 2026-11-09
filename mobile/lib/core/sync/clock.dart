/// Time source, swappable in tests.
abstract interface class AppClock {
  DateTime now();
}

class SystemClock implements AppClock {
  const SystemClock();

  @override
  DateTime now() => DateTime.now();
}

const Duration _ist = Duration(hours: 5, minutes: 30);

/// Formats the calendar date of [date] as `YYYY-MM-DD`.
String dateString(DateTime date) {
  return '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}

/// The calendar date in India for [instant], as `YYYY-MM-DD`.
String istDate(DateTime instant) => dateString(instant.toUtc().add(_ist));

/// Formats [instant] as ISO 8601 in India time with whole seconds:
/// `2026-11-09T10:00:00+05:30`.
String toWireTimestamp(DateTime instant) {
  final local = instant.toUtc().add(_ist);
  String two(int value) => value.toString().padLeft(2, '0');
  return '${local.year.toString().padLeft(4, '0')}-${two(local.month)}-'
      '${two(local.day)}T${two(local.hour)}:${two(local.minute)}:'
      '${two(local.second)}+05:30';
}

/// Parses an API timestamp. Null or empty gives null.
DateTime? parseServerTime(Object? raw) {
  if (raw == null) return null;
  final text = raw.toString();
  if (text.isEmpty) return null;
  return DateTime.parse(text);
}

DateTime _wholeSeconds(DateTime instant) {
  final seconds = instant.millisecondsSinceEpoch ~/ 1000;
  return DateTime.fromMillisecondsSinceEpoch(seconds * 1000, isUtc: true);
}

/// The next `client_updated_at` for a record: `max(now, previous + 1s)`.
///
/// Both sides are truncated to whole seconds first, because the server keeps
/// second precision: two edits inside one second must still produce strictly
/// increasing stamps, or the second would be treated as a duplicate and lost.
///
/// Pass the newest stamp known for the record, including the server's
/// `updated_at`, so an edit on a phone with a slow clock is not stale.
DateTime nextClientStamp(DateTime now, Iterable<DateTime?> previous) {
  var stamp = _wholeSeconds(now);
  for (final earlier in previous) {
    if (earlier == null) continue;
    final minimum = _wholeSeconds(earlier).add(const Duration(seconds: 1));
    if (stamp.isBefore(minimum)) stamp = minimum;
  }
  return stamp;
}
