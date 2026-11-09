import 'package:intl/intl.dart';

import '../core/money/money.dart';
import '../l10n/generated/app_localizations.dart';

/// Dates and short figures for headers and strips.
abstract final class AppFormat {
  static DateFormat _pattern(String pattern, String locale) {
    // Field users read Western digits, and DM Mono has no Devanagari digits.
    DateFormat.useNativeDigitsByDefaultFor(locale, false);
    return DateFormat(pattern, locale);
  }

  /// `2026-11-09` as "Mon, 9 Nov" in [locale].
  static String weekdayDate(String isoDate, String locale) =>
      _pattern('EEE, d MMM', locale).format(DateTime.parse(isoDate));

  /// `2026-11-09` as "9 Nov" in [locale].
  static String dayMonth(String isoDate, String locale) {
    final parsed = DateTime.tryParse(isoDate);
    if (parsed == null) return isoDate;
    return _pattern('d MMM', locale).format(parsed);
  }

  /// Rupees without paise when the paise are zero, for narrow strips.
  static String rupees(Money amount) =>
      amount.value.isInteger ? '₹${amount.indian(scale: 0)}' : amount.inr();

  /// How long ago [then] was, in plain words.
  static String ago(AppLocalizations t, DateTime then, DateTime now) {
    final elapsed = now.difference(then);
    if (elapsed.inMinutes < 1) return t.shellTimeJustNow;
    if (elapsed.inMinutes < 60) return t.shellTimeMinutes(elapsed.inMinutes);
    if (elapsed.inHours < 24) return t.shellTimeHours(elapsed.inHours);
    final days = DateTime(now.year, now.month, now.day)
        .difference(DateTime(then.year, then.month, then.day))
        .inDays;
    return days <= 1 ? t.shellTimeYesterday : t.shellTimeDays(days);
  }
}
