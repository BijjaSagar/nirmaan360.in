import '../../l10n/generated/app_localizations.dart';
import 'controls/hajiri_segment.dart';
import 'pictograms.dart';

/// Translations for the server's fixed vocabularies.
extension N360PictogramLabels on AppLocalizations {
  /// The translated word for [pictogram]. A server value the app does not know
  /// comes back as the server sent it, so it is still readable.
  String pictogramLabel(Pictogram pictogram) {
    return switch (pictogram.key.trim().toLowerCase().replaceAll('_', ' ')) {
      'mason' => designTradeMason,
      'helper' => designTradeHelper,
      'carpenter' => designTradeCarpenter,
      'bar bender' => designTradeBarBender,
      'electrician' => designTradeElectrician,
      'plumber' => designTradePlumber,
      'painter' => designTradePainter,
      'welder' => designTradeWelder,
      'operator' => designTradeOperator,
      'supervisor' => designTradeSupervisor,
      'fuel' => designExpenseFuel,
      'food' => designExpenseFood,
      'travel' => designExpenseTravel,
      'tools' => designExpenseTools,
      'materials' => designExpenseMaterials,
      'labour' => designExpenseLabour,
      'repairs' => designExpenseRepairs,
      'other' => designExpenseOther,
      'clear' => designWeatherClear,
      'cloudy' => designWeatherCloudy,
      'rain' => designWeatherRain,
      'windy' => designWeatherWindy,
      'hot' => designWeatherHot,
      'low' => designPriorityLow,
      'medium' => designPriorityMedium,
      'high' => designPriorityHigh,
      'critical' => designPriorityCritical,
      'open' => designIssueOpen,
      'in progress' => designIssueInProgress,
      'resolved' => designIssueResolved,
      'closed' => designIssueClosed,
      'present' => designAttendancePresent,
      'half' => designAttendanceHalf,
      'overtime' => designAttendanceOvertime,
      'absent' => designAttendanceAbsent,
      _ => pictogram.key,
    };
  }

  /// Spoken words for `HajiriSegment`.
  HajiriLabels get hajiriLabels => HajiriLabels(
        present: designAttendancePresent,
        half: designAttendanceHalf,
        overtime: designAttendanceOvertime,
        absent: designAttendanceAbsent,
      );
}
