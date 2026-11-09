// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appName => 'Nirmaan360';

  @override
  String get commonBack => 'Back';

  @override
  String get commonCancel => 'Cancel';

  @override
  String get commonDone => 'Done';

  @override
  String get commonRetry => 'Try again';

  @override
  String get commonSave => 'Save';

  @override
  String get commonToday => 'Today';

  @override
  String get designAttendanceAbsent => 'Absent';

  @override
  String get designAttendanceHalf => 'Half day';

  @override
  String get designAttendanceOvertime => 'Overtime';

  @override
  String get designAttendancePresent => 'Present';

  @override
  String get designExpenseFood => 'Food';

  @override
  String get designExpenseFuel => 'Fuel';

  @override
  String get designExpenseLabour => 'Labour';

  @override
  String get designExpenseMaterials => 'Materials';

  @override
  String get designExpenseOther => 'Other';

  @override
  String get designExpenseRepairs => 'Repairs';

  @override
  String get designExpenseTools => 'Tools';

  @override
  String get designExpenseTravel => 'Travel';

  @override
  String get designIssueClosed => 'Closed';

  @override
  String get designIssueInProgress => 'In progress';

  @override
  String get designIssueOpen => 'Open';

  @override
  String get designIssueResolved => 'Resolved';

  @override
  String get designKeypadDelete => 'Delete';

  @override
  String get designKeypadNext => 'Next';

  @override
  String get designPriorityCritical => 'Critical';

  @override
  String get designPriorityHigh => 'High';

  @override
  String get designPriorityLow => 'Low';

  @override
  String get designPriorityMedium => 'Medium';

  @override
  String get designStepperDecrease => 'Decrease';

  @override
  String get designStepperIncrease => 'Increase';

  @override
  String get designTradeBarBender => 'Bar bender';

  @override
  String get designTradeCarpenter => 'Carpenter';

  @override
  String get designTradeElectrician => 'Electrician';

  @override
  String get designTradeHelper => 'Helper';

  @override
  String get designTradeMason => 'Mason';

  @override
  String get designTradeOperator => 'Operator';

  @override
  String get designTradePainter => 'Painter';

  @override
  String get designTradePlumber => 'Plumber';

  @override
  String get designTradeSupervisor => 'Supervisor';

  @override
  String get designTradeWelder => 'Welder';

  @override
  String get designWeatherClear => 'Clear';

  @override
  String get designWeatherCloudy => 'Cloudy';

  @override
  String get designWeatherHot => 'Hot';

  @override
  String get designWeatherRain => 'Rain';

  @override
  String get designWeatherWindy => 'Windy';

  @override
  String get statusApproved => 'Approved';

  @override
  String get statusDraft => 'Draft';

  @override
  String get statusInReview => 'In review';

  @override
  String get statusQueued => 'On phone';

  @override
  String get statusRejected => 'Rejected';

  @override
  String get statusReturned => 'Returned';

  @override
  String get statusSynced => 'Sent';

  @override
  String get syncAllSent => 'Everything is sent';

  @override
  String syncAttentionCount(int count) {
    return '$count need your attention';
  }

  @override
  String get syncBlocked => 'Waiting for an earlier item';

  @override
  String get syncDiscard => 'Discard';

  @override
  String get syncInProgress => 'Sending…';

  @override
  String syncLastSynced(String time) {
    return 'Last synced $time';
  }

  @override
  String get syncNow => 'Sync now';

  @override
  String get syncOffline => 'No network. Your work is safe on this phone.';

  @override
  String syncPendingCount(int count) {
    return '$count waiting to send';
  }

  @override
  String syncProgress(int done, int total) {
    return '$done of $total sent';
  }

  @override
  String get syncPulling => 'Getting latest figures…';

  @override
  String get syncRateLimited =>
      'The server is busy. Sending will resume shortly.';

  @override
  String get syncReasonApproved =>
      'This record was already approved. Your change was not applied.';

  @override
  String get syncReasonFileMissing => 'The photo is missing from this phone.';

  @override
  String get syncReasonForbidden => 'You do not have permission to do this.';

  @override
  String get syncReasonInReview =>
      'This record is with the approver. Your change was not applied.';

  @override
  String get syncReasonParentMissing =>
      'The record this file belongs to was not found on the server.';

  @override
  String get syncReasonPlanFeature => 'Your plan does not include this module.';

  @override
  String get syncReasonRejected =>
      'This record was rejected. Your change was not applied.';

  @override
  String get syncReasonUnexpected => 'The server sent an unexpected reply.';

  @override
  String get syncReasonValidation =>
      'The server could not accept this entry. Please correct it.';

  @override
  String get syncRetry => 'Try again';

  @override
  String get syncSignInAgain =>
      'Sign in again to keep sending. Your work is safe.';

  @override
  String get syncStaleLog =>
      'A newer change was already on the server, so this one was not applied.';

  @override
  String get syncTypeAttendance => 'Attendance';

  @override
  String get syncTypeDpr => 'Daily report';

  @override
  String get syncTypeDprPhoto => 'Report photo';

  @override
  String get syncTypeDprSubmit => 'Report submission';

  @override
  String get syncTypeIssue => 'Issue';

  @override
  String get syncTypeIssueStatus => 'Issue status';

  @override
  String get syncTypeMeasurement => 'Measurement';

  @override
  String get syncTypeMeasurementSubmit => 'Measurement submission';

  @override
  String get syncTypePettyBill => 'Bill photo';

  @override
  String get syncTypePettyCash => 'Petty cash entry';
}
