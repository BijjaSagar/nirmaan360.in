import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_en.dart';
import 'app_localizations_hi.dart';
import 'app_localizations_mr.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'generated/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('en'),
    Locale('hi'),
    Locale('mr'),
  ];

  /// No description provided for @appName.
  ///
  /// In en, this message translates to:
  /// **'Nirmaan360'**
  String get appName;

  /// No description provided for @commonBack.
  ///
  /// In en, this message translates to:
  /// **'Back'**
  String get commonBack;

  /// No description provided for @commonCancel.
  ///
  /// In en, this message translates to:
  /// **'Cancel'**
  String get commonCancel;

  /// No description provided for @commonDone.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get commonDone;

  /// No description provided for @commonRetry.
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get commonRetry;

  /// No description provided for @commonSave.
  ///
  /// In en, this message translates to:
  /// **'Save'**
  String get commonSave;

  /// No description provided for @commonToday.
  ///
  /// In en, this message translates to:
  /// **'Today'**
  String get commonToday;

  /// No description provided for @designAttendanceAbsent.
  ///
  /// In en, this message translates to:
  /// **'Absent'**
  String get designAttendanceAbsent;

  /// No description provided for @designAttendanceHalf.
  ///
  /// In en, this message translates to:
  /// **'Half day'**
  String get designAttendanceHalf;

  /// No description provided for @designAttendanceOvertime.
  ///
  /// In en, this message translates to:
  /// **'Overtime'**
  String get designAttendanceOvertime;

  /// No description provided for @designAttendancePresent.
  ///
  /// In en, this message translates to:
  /// **'Present'**
  String get designAttendancePresent;

  /// No description provided for @designExpenseFood.
  ///
  /// In en, this message translates to:
  /// **'Food'**
  String get designExpenseFood;

  /// No description provided for @designExpenseFuel.
  ///
  /// In en, this message translates to:
  /// **'Fuel'**
  String get designExpenseFuel;

  /// No description provided for @designExpenseLabour.
  ///
  /// In en, this message translates to:
  /// **'Labour'**
  String get designExpenseLabour;

  /// No description provided for @designExpenseMaterials.
  ///
  /// In en, this message translates to:
  /// **'Materials'**
  String get designExpenseMaterials;

  /// No description provided for @designExpenseOther.
  ///
  /// In en, this message translates to:
  /// **'Other'**
  String get designExpenseOther;

  /// No description provided for @designExpenseRepairs.
  ///
  /// In en, this message translates to:
  /// **'Repairs'**
  String get designExpenseRepairs;

  /// No description provided for @designExpenseTools.
  ///
  /// In en, this message translates to:
  /// **'Tools'**
  String get designExpenseTools;

  /// No description provided for @designExpenseTravel.
  ///
  /// In en, this message translates to:
  /// **'Travel'**
  String get designExpenseTravel;

  /// No description provided for @designIssueClosed.
  ///
  /// In en, this message translates to:
  /// **'Closed'**
  String get designIssueClosed;

  /// No description provided for @designIssueInProgress.
  ///
  /// In en, this message translates to:
  /// **'In progress'**
  String get designIssueInProgress;

  /// No description provided for @designIssueOpen.
  ///
  /// In en, this message translates to:
  /// **'Open'**
  String get designIssueOpen;

  /// No description provided for @designIssueResolved.
  ///
  /// In en, this message translates to:
  /// **'Resolved'**
  String get designIssueResolved;

  /// No description provided for @designKeypadDelete.
  ///
  /// In en, this message translates to:
  /// **'Delete'**
  String get designKeypadDelete;

  /// No description provided for @designKeypadNext.
  ///
  /// In en, this message translates to:
  /// **'Next'**
  String get designKeypadNext;

  /// No description provided for @designPriorityCritical.
  ///
  /// In en, this message translates to:
  /// **'Critical'**
  String get designPriorityCritical;

  /// No description provided for @designPriorityHigh.
  ///
  /// In en, this message translates to:
  /// **'High'**
  String get designPriorityHigh;

  /// No description provided for @designPriorityLow.
  ///
  /// In en, this message translates to:
  /// **'Low'**
  String get designPriorityLow;

  /// No description provided for @designPriorityMedium.
  ///
  /// In en, this message translates to:
  /// **'Medium'**
  String get designPriorityMedium;

  /// No description provided for @designStepperDecrease.
  ///
  /// In en, this message translates to:
  /// **'Decrease'**
  String get designStepperDecrease;

  /// No description provided for @designStepperIncrease.
  ///
  /// In en, this message translates to:
  /// **'Increase'**
  String get designStepperIncrease;

  /// No description provided for @designTradeBarBender.
  ///
  /// In en, this message translates to:
  /// **'Bar bender'**
  String get designTradeBarBender;

  /// No description provided for @designTradeCarpenter.
  ///
  /// In en, this message translates to:
  /// **'Carpenter'**
  String get designTradeCarpenter;

  /// No description provided for @designTradeElectrician.
  ///
  /// In en, this message translates to:
  /// **'Electrician'**
  String get designTradeElectrician;

  /// No description provided for @designTradeHelper.
  ///
  /// In en, this message translates to:
  /// **'Helper'**
  String get designTradeHelper;

  /// No description provided for @designTradeMason.
  ///
  /// In en, this message translates to:
  /// **'Mason'**
  String get designTradeMason;

  /// No description provided for @designTradeOperator.
  ///
  /// In en, this message translates to:
  /// **'Operator'**
  String get designTradeOperator;

  /// No description provided for @designTradePainter.
  ///
  /// In en, this message translates to:
  /// **'Painter'**
  String get designTradePainter;

  /// No description provided for @designTradePlumber.
  ///
  /// In en, this message translates to:
  /// **'Plumber'**
  String get designTradePlumber;

  /// No description provided for @designTradeSupervisor.
  ///
  /// In en, this message translates to:
  /// **'Supervisor'**
  String get designTradeSupervisor;

  /// No description provided for @designTradeWelder.
  ///
  /// In en, this message translates to:
  /// **'Welder'**
  String get designTradeWelder;

  /// No description provided for @designWeatherClear.
  ///
  /// In en, this message translates to:
  /// **'Clear'**
  String get designWeatherClear;

  /// No description provided for @designWeatherCloudy.
  ///
  /// In en, this message translates to:
  /// **'Cloudy'**
  String get designWeatherCloudy;

  /// No description provided for @designWeatherHot.
  ///
  /// In en, this message translates to:
  /// **'Hot'**
  String get designWeatherHot;

  /// No description provided for @designWeatherRain.
  ///
  /// In en, this message translates to:
  /// **'Rain'**
  String get designWeatherRain;

  /// No description provided for @designWeatherWindy.
  ///
  /// In en, this message translates to:
  /// **'Windy'**
  String get designWeatherWindy;

  /// No description provided for @statusApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get statusApproved;

  /// No description provided for @statusDraft.
  ///
  /// In en, this message translates to:
  /// **'Draft'**
  String get statusDraft;

  /// No description provided for @statusInReview.
  ///
  /// In en, this message translates to:
  /// **'In review'**
  String get statusInReview;

  /// No description provided for @statusQueued.
  ///
  /// In en, this message translates to:
  /// **'On phone'**
  String get statusQueued;

  /// No description provided for @statusRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get statusRejected;

  /// No description provided for @statusReturned.
  ///
  /// In en, this message translates to:
  /// **'Returned'**
  String get statusReturned;

  /// No description provided for @statusSynced.
  ///
  /// In en, this message translates to:
  /// **'Sent'**
  String get statusSynced;

  /// No description provided for @syncAllSent.
  ///
  /// In en, this message translates to:
  /// **'Everything is sent'**
  String get syncAllSent;

  /// No description provided for @syncAttentionCount.
  ///
  /// In en, this message translates to:
  /// **'{count} need your attention'**
  String syncAttentionCount(int count);

  /// No description provided for @syncBlocked.
  ///
  /// In en, this message translates to:
  /// **'Waiting for an earlier item'**
  String get syncBlocked;

  /// No description provided for @syncDiscard.
  ///
  /// In en, this message translates to:
  /// **'Discard'**
  String get syncDiscard;

  /// No description provided for @syncInProgress.
  ///
  /// In en, this message translates to:
  /// **'Sending…'**
  String get syncInProgress;

  /// No description provided for @syncLastSynced.
  ///
  /// In en, this message translates to:
  /// **'Last synced {time}'**
  String syncLastSynced(String time);

  /// No description provided for @syncNow.
  ///
  /// In en, this message translates to:
  /// **'Sync now'**
  String get syncNow;

  /// No description provided for @syncOffline.
  ///
  /// In en, this message translates to:
  /// **'No network. Your work is safe on this phone.'**
  String get syncOffline;

  /// No description provided for @syncPendingCount.
  ///
  /// In en, this message translates to:
  /// **'{count} waiting to send'**
  String syncPendingCount(int count);

  /// No description provided for @syncProgress.
  ///
  /// In en, this message translates to:
  /// **'{done} of {total} sent'**
  String syncProgress(int done, int total);

  /// No description provided for @syncPulling.
  ///
  /// In en, this message translates to:
  /// **'Getting latest figures…'**
  String get syncPulling;

  /// No description provided for @syncRateLimited.
  ///
  /// In en, this message translates to:
  /// **'The server is busy. Sending will resume shortly.'**
  String get syncRateLimited;

  /// No description provided for @syncReasonApproved.
  ///
  /// In en, this message translates to:
  /// **'This record was already approved. Your change was not applied.'**
  String get syncReasonApproved;

  /// No description provided for @syncReasonFileMissing.
  ///
  /// In en, this message translates to:
  /// **'The photo is missing from this phone.'**
  String get syncReasonFileMissing;

  /// No description provided for @syncReasonForbidden.
  ///
  /// In en, this message translates to:
  /// **'You do not have permission to do this.'**
  String get syncReasonForbidden;

  /// No description provided for @syncReasonInReview.
  ///
  /// In en, this message translates to:
  /// **'This record is with the approver. Your change was not applied.'**
  String get syncReasonInReview;

  /// No description provided for @syncReasonParentMissing.
  ///
  /// In en, this message translates to:
  /// **'The record this file belongs to was not found on the server.'**
  String get syncReasonParentMissing;

  /// No description provided for @syncReasonPlanFeature.
  ///
  /// In en, this message translates to:
  /// **'Your plan does not include this module.'**
  String get syncReasonPlanFeature;

  /// No description provided for @syncReasonRejected.
  ///
  /// In en, this message translates to:
  /// **'This record was rejected. Your change was not applied.'**
  String get syncReasonRejected;

  /// No description provided for @syncReasonUnexpected.
  ///
  /// In en, this message translates to:
  /// **'The server sent an unexpected reply.'**
  String get syncReasonUnexpected;

  /// No description provided for @syncReasonValidation.
  ///
  /// In en, this message translates to:
  /// **'The server could not accept this entry. Please correct it.'**
  String get syncReasonValidation;

  /// No description provided for @syncRetry.
  ///
  /// In en, this message translates to:
  /// **'Try again'**
  String get syncRetry;

  /// No description provided for @syncSignInAgain.
  ///
  /// In en, this message translates to:
  /// **'Sign in again to keep sending. Your work is safe.'**
  String get syncSignInAgain;

  /// No description provided for @syncStaleLog.
  ///
  /// In en, this message translates to:
  /// **'A newer change was already on the server, so this one was not applied.'**
  String get syncStaleLog;

  /// No description provided for @syncTypeAttendance.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get syncTypeAttendance;

  /// No description provided for @syncTypeDpr.
  ///
  /// In en, this message translates to:
  /// **'Daily report'**
  String get syncTypeDpr;

  /// No description provided for @syncTypeDprPhoto.
  ///
  /// In en, this message translates to:
  /// **'Report photo'**
  String get syncTypeDprPhoto;

  /// No description provided for @syncTypeDprSubmit.
  ///
  /// In en, this message translates to:
  /// **'Report submission'**
  String get syncTypeDprSubmit;

  /// No description provided for @syncTypeIssue.
  ///
  /// In en, this message translates to:
  /// **'Issue'**
  String get syncTypeIssue;

  /// No description provided for @syncTypeIssueStatus.
  ///
  /// In en, this message translates to:
  /// **'Issue status'**
  String get syncTypeIssueStatus;

  /// No description provided for @syncTypeMeasurement.
  ///
  /// In en, this message translates to:
  /// **'Measurement'**
  String get syncTypeMeasurement;

  /// No description provided for @syncTypeMeasurementSubmit.
  ///
  /// In en, this message translates to:
  /// **'Measurement submission'**
  String get syncTypeMeasurementSubmit;

  /// No description provided for @syncTypePettyBill.
  ///
  /// In en, this message translates to:
  /// **'Bill photo'**
  String get syncTypePettyBill;

  /// No description provided for @syncTypePettyCash.
  ///
  /// In en, this message translates to:
  /// **'Petty cash entry'**
  String get syncTypePettyCash;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['en', 'hi', 'mr'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'en':
      return AppLocalizationsEn();
    case 'hi':
      return AppLocalizationsHi();
    case 'mr':
      return AppLocalizationsMr();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
