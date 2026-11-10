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

  /// No description provided for @alertsDashboardEmpty.
  ///
  /// In en, this message translates to:
  /// **'No figures for this project yet.'**
  String get alertsDashboardEmpty;

  /// No description provided for @alertsHomeCriticalCount.
  ///
  /// In en, this message translates to:
  /// **'{count} critical'**
  String alertsHomeCriticalCount(int count);

  /// No description provided for @alertsHomeDashboardOnly.
  ///
  /// In en, this message translates to:
  /// **'Dashboard for this site'**
  String get alertsHomeDashboardOnly;

  /// No description provided for @alertsHomeOpen.
  ///
  /// In en, this message translates to:
  /// **'{count} open'**
  String alertsHomeOpen(int count);

  /// No description provided for @alertsHomeOverview.
  ///
  /// In en, this message translates to:
  /// **'Dashboard and alerts for this site'**
  String get alertsHomeOverview;

  /// No description provided for @alertsHomeTitle.
  ///
  /// In en, this message translates to:
  /// **'Site alerts'**
  String get alertsHomeTitle;

  /// No description provided for @alertsListRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'Alerts are not open to your role. Ask your office if you need them.'**
  String get alertsListRefusedMessage;

  /// No description provided for @alertsLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading site overview'**
  String get alertsLoading;

  /// No description provided for @alertsNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load this project\'s overview. After that it opens offline.'**
  String get alertsNoDataMessage;

  /// No description provided for @alertsNoneMessage.
  ///
  /// In en, this message translates to:
  /// **'When something on this site needs a look, it will show here.'**
  String get alertsNoneMessage;

  /// No description provided for @alertsNoneTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing needs attention'**
  String get alertsNoneTitle;

  /// No description provided for @alertsRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'The site overview is not open to your role. Ask your office if you need it.'**
  String get alertsRefusedMessage;

  /// No description provided for @alertsRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'Not part of your role'**
  String get alertsRefusedTitle;

  /// No description provided for @alertsScanDone.
  ///
  /// In en, this message translates to:
  /// **'Scanned. {added} new, {open} open, {resolved} cleared.'**
  String alertsScanDone(int added, int open, int resolved);

  /// No description provided for @alertsScanFailed.
  ///
  /// In en, this message translates to:
  /// **'The scan did not go through. Try again in a moment.'**
  String get alertsScanFailed;

  /// No description provided for @alertsScanNow.
  ///
  /// In en, this message translates to:
  /// **'Scan now'**
  String get alertsScanNow;

  /// No description provided for @alertsScanOffline.
  ///
  /// In en, this message translates to:
  /// **'Scanning needs the internet. Connect and try again.'**
  String get alertsScanOffline;

  /// No description provided for @alertsScanRefused.
  ///
  /// In en, this message translates to:
  /// **'The server said: {message}'**
  String alertsScanRefused(String message);

  /// No description provided for @alertsScanWait.
  ///
  /// In en, this message translates to:
  /// **'Try again in {seconds} seconds.'**
  String alertsScanWait(int seconds);

  /// No description provided for @alertsScanning.
  ///
  /// In en, this message translates to:
  /// **'Scanning the site…'**
  String get alertsScanning;

  /// No description provided for @alertsSeverityCritical.
  ///
  /// In en, this message translates to:
  /// **'Critical'**
  String get alertsSeverityCritical;

  /// No description provided for @alertsSeverityInfo.
  ///
  /// In en, this message translates to:
  /// **'For information'**
  String get alertsSeverityInfo;

  /// No description provided for @alertsSeverityWarning.
  ///
  /// In en, this message translates to:
  /// **'Warning'**
  String get alertsSeverityWarning;

  /// No description provided for @alertsShowingSome.
  ///
  /// In en, this message translates to:
  /// **'Showing the {shown} most urgent of {total}. The rest are on the website.'**
  String alertsShowingSome(int shown, int total);

  /// No description provided for @alertsSince.
  ///
  /// In en, this message translates to:
  /// **'Since {date}'**
  String alertsSince(String date);

  /// No description provided for @alertsTabAlerts.
  ///
  /// In en, this message translates to:
  /// **'Alerts'**
  String get alertsTabAlerts;

  /// No description provided for @alertsTabDashboard.
  ///
  /// In en, this message translates to:
  /// **'Dashboard'**
  String get alertsTabDashboard;

  /// No description provided for @alertsTitle.
  ///
  /// In en, this message translates to:
  /// **'Site overview'**
  String get alertsTitle;

  /// No description provided for @appName.
  ///
  /// In en, this message translates to:
  /// **'Nirmaan360'**
  String get appName;

  /// No description provided for @approvalsActionApproved.
  ///
  /// In en, this message translates to:
  /// **'Approved'**
  String get approvalsActionApproved;

  /// No description provided for @approvalsActionRejected.
  ///
  /// In en, this message translates to:
  /// **'Rejected'**
  String get approvalsActionRejected;

  /// No description provided for @approvalsActionReturned.
  ///
  /// In en, this message translates to:
  /// **'Sent back'**
  String get approvalsActionReturned;

  /// No description provided for @approvalsActionSubmitted.
  ///
  /// In en, this message translates to:
  /// **'Submitted'**
  String get approvalsActionSubmitted;

  /// No description provided for @approvalsApprove.
  ///
  /// In en, this message translates to:
  /// **'Approve'**
  String get approvalsApprove;

  /// No description provided for @approvalsBackToInbox.
  ///
  /// In en, this message translates to:
  /// **'Back to approvals'**
  String get approvalsBackToInbox;

  /// No description provided for @approvalsBillNumber.
  ///
  /// In en, this message translates to:
  /// **'Bill number'**
  String get approvalsBillNumber;

  /// No description provided for @approvalsBillType.
  ///
  /// In en, this message translates to:
  /// **'Type'**
  String get approvalsBillType;

  /// No description provided for @approvalsChainNext.
  ///
  /// In en, this message translates to:
  /// **'After that'**
  String get approvalsChainNext;

  /// No description provided for @approvalsChainTitle.
  ///
  /// In en, this message translates to:
  /// **'Approval trail'**
  String get approvalsChainTitle;

  /// No description provided for @approvalsChainWaiting.
  ///
  /// In en, this message translates to:
  /// **'Waiting now'**
  String get approvalsChainWaiting;

  /// No description provided for @approvalsCouldNotRefresh.
  ///
  /// In en, this message translates to:
  /// **'Could not refresh. Please try again.'**
  String get approvalsCouldNotRefresh;

  /// No description provided for @approvalsCouldNotRefreshOffline.
  ///
  /// In en, this message translates to:
  /// **'Could not reach the server. Showing what was last fetched.'**
  String get approvalsCouldNotRefreshOffline;

  /// No description provided for @approvalsDaysAgo.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 day ago} other{{count} days ago}}'**
  String approvalsDaysAgo(int count);

  /// No description provided for @approvalsDeductions.
  ///
  /// In en, this message translates to:
  /// **'Deductions'**
  String get approvalsDeductions;

  /// No description provided for @approvalsDeviation.
  ///
  /// In en, this message translates to:
  /// **'Deviation'**
  String get approvalsDeviation;

  /// No description provided for @approvalsDeviationBody.
  ///
  /// In en, this message translates to:
  /// **'Goes over the BOQ quantity plus tolerance. Project manager must approve.'**
  String get approvalsDeviationBody;

  /// No description provided for @approvalsDocBill.
  ///
  /// In en, this message translates to:
  /// **'RA bill'**
  String get approvalsDocBill;

  /// No description provided for @approvalsDocBillNote.
  ///
  /// In en, this message translates to:
  /// **'Credit or debit note'**
  String get approvalsDocBillNote;

  /// No description provided for @approvalsDocDpr.
  ///
  /// In en, this message translates to:
  /// **'Daily report'**
  String get approvalsDocDpr;

  /// No description provided for @approvalsDocIndent.
  ///
  /// In en, this message translates to:
  /// **'Indent'**
  String get approvalsDocIndent;

  /// No description provided for @approvalsDocMeasurement.
  ///
  /// In en, this message translates to:
  /// **'Measurement'**
  String get approvalsDocMeasurement;

  /// No description provided for @approvalsDocOther.
  ///
  /// In en, this message translates to:
  /// **'Document'**
  String get approvalsDocOther;

  /// No description provided for @approvalsDocRfi.
  ///
  /// In en, this message translates to:
  /// **'RFI'**
  String get approvalsDocRfi;

  /// No description provided for @approvalsDocVendorBill.
  ///
  /// In en, this message translates to:
  /// **'Vendor bill'**
  String get approvalsDocVendorBill;

  /// No description provided for @approvalsEmptyBody.
  ///
  /// In en, this message translates to:
  /// **'New approvals will appear here.'**
  String get approvalsEmptyBody;

  /// No description provided for @approvalsEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing waiting'**
  String get approvalsEmptyTitle;

  /// No description provided for @approvalsFailOfflineBody.
  ///
  /// In en, this message translates to:
  /// **'Your decision was not sent. Nothing was queued. Try again when connected.'**
  String get approvalsFailOfflineBody;

  /// No description provided for @approvalsFailOfflineTitle.
  ///
  /// In en, this message translates to:
  /// **'No network'**
  String get approvalsFailOfflineTitle;

  /// No description provided for @approvalsFailRaceBody.
  ///
  /// In en, this message translates to:
  /// **'Someone else acted on this first. Refresh to see what is waiting now.'**
  String get approvalsFailRaceBody;

  /// No description provided for @approvalsFailRaceTitle.
  ///
  /// In en, this message translates to:
  /// **'Already decided'**
  String get approvalsFailRaceTitle;

  /// No description provided for @approvalsFailRejectedTitle.
  ///
  /// In en, this message translates to:
  /// **'The server refused this'**
  String get approvalsFailRejectedTitle;

  /// No description provided for @approvalsFailServerBody.
  ///
  /// In en, this message translates to:
  /// **'Something went wrong on the server. Your decision was not recorded. Try again.'**
  String get approvalsFailServerBody;

  /// No description provided for @approvalsFailServerTitle.
  ///
  /// In en, this message translates to:
  /// **'Could not send'**
  String get approvalsFailServerTitle;

  /// No description provided for @approvalsFailSessionBody.
  ///
  /// In en, this message translates to:
  /// **'Your session has ended. Your decision was not sent.'**
  String get approvalsFailSessionBody;

  /// No description provided for @approvalsFailSessionTitle.
  ///
  /// In en, this message translates to:
  /// **'Please sign in again'**
  String get approvalsFailSessionTitle;

  /// No description provided for @approvalsForYou.
  ///
  /// In en, this message translates to:
  /// **'Waiting for you'**
  String get approvalsForYou;

  /// No description provided for @approvalsGoneBody.
  ///
  /// In en, this message translates to:
  /// **'Someone has already decided this one.'**
  String get approvalsGoneBody;

  /// No description provided for @approvalsGoneTitle.
  ///
  /// In en, this message translates to:
  /// **'No longer waiting'**
  String get approvalsGoneTitle;

  /// No description provided for @approvalsGross.
  ///
  /// In en, this message translates to:
  /// **'Gross value of work'**
  String get approvalsGross;

  /// No description provided for @approvalsGst.
  ///
  /// In en, this message translates to:
  /// **'GST'**
  String get approvalsGst;

  /// No description provided for @approvalsHindrances.
  ///
  /// In en, this message translates to:
  /// **'Hindrances'**
  String get approvalsHindrances;

  /// No description provided for @approvalsHoursAgo.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 hour ago} other{{count} hours ago}}'**
  String approvalsHoursAgo(int count);

  /// No description provided for @approvalsJustNow.
  ///
  /// In en, this message translates to:
  /// **'just now'**
  String get approvalsJustNow;

  /// No description provided for @approvalsManpower.
  ///
  /// In en, this message translates to:
  /// **'Manpower'**
  String get approvalsManpower;

  /// No description provided for @approvalsMinutesAgo.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 minute ago} other{{count} minutes ago}}'**
  String approvalsMinutesAgo(int count);

  /// No description provided for @approvalsNeedsNetwork.
  ///
  /// In en, this message translates to:
  /// **'Approve and Send back need network. Nothing is queued.'**
  String get approvalsNeedsNetwork;

  /// No description provided for @approvalsNetPayable.
  ///
  /// In en, this message translates to:
  /// **'Net payable'**
  String get approvalsNetPayable;

  /// No description provided for @approvalsNoteAgainstBill.
  ///
  /// In en, this message translates to:
  /// **'Against bill'**
  String get approvalsNoteAgainstBill;

  /// No description provided for @approvalsNoteCredit.
  ///
  /// In en, this message translates to:
  /// **'Credit note'**
  String get approvalsNoteCredit;

  /// No description provided for @approvalsNoteDebit.
  ///
  /// In en, this message translates to:
  /// **'Debit note'**
  String get approvalsNoteDebit;

  /// No description provided for @approvalsNoteEffect.
  ///
  /// In en, this message translates to:
  /// **'Effect on bill'**
  String get approvalsNoteEffect;

  /// No description provided for @approvalsNoteNumber.
  ///
  /// In en, this message translates to:
  /// **'Note number'**
  String get approvalsNoteNumber;

  /// No description provided for @approvalsNoteReason.
  ///
  /// In en, this message translates to:
  /// **'Reason'**
  String get approvalsNoteReason;

  /// No description provided for @approvalsNoteTaxable.
  ///
  /// In en, this message translates to:
  /// **'Taxable amount'**
  String get approvalsNoteTaxable;

  /// No description provided for @approvalsNoteTotal.
  ///
  /// In en, this message translates to:
  /// **'Note total'**
  String get approvalsNoteTotal;

  /// No description provided for @approvalsOffline.
  ///
  /// In en, this message translates to:
  /// **'No network.'**
  String get approvalsOffline;

  /// No description provided for @approvalsOfflineDetail.
  ///
  /// In en, this message translates to:
  /// **'You can read these, but approvals need network.'**
  String get approvalsOfflineDetail;

  /// No description provided for @approvalsOnOthers.
  ///
  /// In en, this message translates to:
  /// **'Waiting on others'**
  String get approvalsOnOthers;

  /// No description provided for @approvalsOpen.
  ///
  /// In en, this message translates to:
  /// **'Open'**
  String get approvalsOpen;

  /// No description provided for @approvalsOpenOnWeb.
  ///
  /// In en, this message translates to:
  /// **'This document cannot be shown on the phone yet. Please review and approve it on the website.'**
  String get approvalsOpenOnWeb;

  /// No description provided for @approvalsParty.
  ///
  /// In en, this message translates to:
  /// **'Party'**
  String get approvalsParty;

  /// No description provided for @approvalsPeriod.
  ///
  /// In en, this message translates to:
  /// **'Period'**
  String get approvalsPeriod;

  /// No description provided for @approvalsPhotos.
  ///
  /// In en, this message translates to:
  /// **'Photos'**
  String get approvalsPhotos;

  /// No description provided for @approvalsReasonAmounts.
  ///
  /// In en, this message translates to:
  /// **'Amounts do not match'**
  String get approvalsReasonAmounts;

  /// No description provided for @approvalsReasonDeductions.
  ///
  /// In en, this message translates to:
  /// **'Check the deductions'**
  String get approvalsReasonDeductions;

  /// No description provided for @approvalsReasonDimensions.
  ///
  /// In en, this message translates to:
  /// **'Check the dimensions'**
  String get approvalsReasonDimensions;

  /// No description provided for @approvalsReasonLocation.
  ///
  /// In en, this message translates to:
  /// **'Write the location clearly'**
  String get approvalsReasonLocation;

  /// No description provided for @approvalsReasonManpower.
  ///
  /// In en, this message translates to:
  /// **'Manpower count is wrong'**
  String get approvalsReasonManpower;

  /// No description provided for @approvalsReasonNote.
  ///
  /// In en, this message translates to:
  /// **'Reason is not clear'**
  String get approvalsReasonNote;

  /// No description provided for @approvalsReasonPeriod.
  ///
  /// In en, this message translates to:
  /// **'Billing period is wrong'**
  String get approvalsReasonPeriod;

  /// No description provided for @approvalsReasonPhotos.
  ///
  /// In en, this message translates to:
  /// **'Add photos'**
  String get approvalsReasonPhotos;

  /// No description provided for @approvalsReasonQuantityHigh.
  ///
  /// In en, this message translates to:
  /// **'Quantity looks too high'**
  String get approvalsReasonQuantityHigh;

  /// No description provided for @approvalsReasonWorkDetail.
  ///
  /// In en, this message translates to:
  /// **'Add more work detail'**
  String get approvalsReasonWorkDetail;

  /// No description provided for @approvalsReasonWrongItem.
  ///
  /// In en, this message translates to:
  /// **'Wrong BOQ line'**
  String get approvalsReasonWrongItem;

  /// No description provided for @approvalsRefresh.
  ///
  /// In en, this message translates to:
  /// **'Refresh'**
  String get approvalsRefresh;

  /// No description provided for @approvalsRefreshing.
  ///
  /// In en, this message translates to:
  /// **'Refreshing'**
  String get approvalsRefreshing;

  /// No description provided for @approvalsRemarkHint.
  ///
  /// In en, this message translates to:
  /// **'What needs fixing'**
  String get approvalsRemarkHint;

  /// No description provided for @approvalsRemarkLabel.
  ///
  /// In en, this message translates to:
  /// **'Remark'**
  String get approvalsRemarkLabel;

  /// No description provided for @approvalsRemarks.
  ///
  /// In en, this message translates to:
  /// **'Remarks'**
  String get approvalsRemarks;

  /// No description provided for @approvalsRoleAccounts.
  ///
  /// In en, this message translates to:
  /// **'Accounts'**
  String get approvalsRoleAccounts;

  /// No description provided for @approvalsRoleAdmin.
  ///
  /// In en, this message translates to:
  /// **'Admin'**
  String get approvalsRoleAdmin;

  /// No description provided for @approvalsRoleClient.
  ///
  /// In en, this message translates to:
  /// **'Client'**
  String get approvalsRoleClient;

  /// No description provided for @approvalsRoleEngineer.
  ///
  /// In en, this message translates to:
  /// **'Site engineer'**
  String get approvalsRoleEngineer;

  /// No description provided for @approvalsRoleOwner.
  ///
  /// In en, this message translates to:
  /// **'Owner'**
  String get approvalsRoleOwner;

  /// No description provided for @approvalsRolePm.
  ///
  /// In en, this message translates to:
  /// **'Project manager'**
  String get approvalsRolePm;

  /// No description provided for @approvalsRoleQs.
  ///
  /// In en, this message translates to:
  /// **'Estimator / QS'**
  String get approvalsRoleQs;

  /// No description provided for @approvalsSendBack.
  ///
  /// In en, this message translates to:
  /// **'Send back'**
  String get approvalsSendBack;

  /// No description provided for @approvalsSendBackHelp.
  ///
  /// In en, this message translates to:
  /// **'Tell them what to fix. Pick one or write your own.'**
  String get approvalsSendBackHelp;

  /// No description provided for @approvalsSendBackTitle.
  ///
  /// In en, this message translates to:
  /// **'Why send it back?'**
  String get approvalsSendBackTitle;

  /// No description provided for @approvalsSentBackToast.
  ///
  /// In en, this message translates to:
  /// **'Sent back with your remark.'**
  String get approvalsSentBackToast;

  /// No description provided for @approvalsSessionExpired.
  ///
  /// In en, this message translates to:
  /// **'Please sign in again.'**
  String get approvalsSessionExpired;

  /// No description provided for @approvalsSummary.
  ///
  /// In en, this message translates to:
  /// **'Summary of work'**
  String get approvalsSummary;

  /// No description provided for @approvalsTitle.
  ///
  /// In en, this message translates to:
  /// **'Approvals'**
  String get approvalsTitle;

  /// No description provided for @approvalsTotal.
  ///
  /// In en, this message translates to:
  /// **'Total'**
  String get approvalsTotal;

  /// No description provided for @approvalsWeather.
  ///
  /// In en, this message translates to:
  /// **'Weather'**
  String get approvalsWeather;

  /// No description provided for @approvalsWorkDone.
  ///
  /// In en, this message translates to:
  /// **'Work done'**
  String get approvalsWorkDone;

  /// No description provided for @approvalsWorkers.
  ///
  /// In en, this message translates to:
  /// **'workers'**
  String get approvalsWorkers;

  /// No description provided for @attendanceAddFirstWorker.
  ///
  /// In en, this message translates to:
  /// **'Add first worker'**
  String get attendanceAddFirstWorker;

  /// No description provided for @attendanceAddWorker.
  ///
  /// In en, this message translates to:
  /// **'Add worker'**
  String get attendanceAddWorker;

  /// No description provided for @attendanceAllMarked.
  ///
  /// In en, this message translates to:
  /// **'Everyone is marked'**
  String get attendanceAllMarked;

  /// No description provided for @attendanceAllPresent.
  ///
  /// In en, this message translates to:
  /// **'All present ({count})'**
  String attendanceAllPresent(int count);

  /// No description provided for @attendanceAllPresentDone.
  ///
  /// In en, this message translates to:
  /// **'{count} marked present'**
  String attendanceAllPresentDone(int count);

  /// No description provided for @attendanceAllPresentHelper.
  ///
  /// In en, this message translates to:
  /// **'Skips anyone already marked.'**
  String get attendanceAllPresentHelper;

  /// No description provided for @attendanceChooseProject.
  ///
  /// In en, this message translates to:
  /// **'Choose a project'**
  String get attendanceChooseProject;

  /// No description provided for @attendanceDateTitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance for {date}'**
  String attendanceDateTitle(String date);

  /// No description provided for @attendanceDayToday.
  ///
  /// In en, this message translates to:
  /// **'Today'**
  String get attendanceDayToday;

  /// No description provided for @attendanceDayYesterday.
  ///
  /// In en, this message translates to:
  /// **'Yesterday'**
  String get attendanceDayYesterday;

  /// No description provided for @attendanceEyebrow.
  ///
  /// In en, this message translates to:
  /// **'Muster roll'**
  String get attendanceEyebrow;

  /// No description provided for @attendanceGroupProgress.
  ///
  /// In en, this message translates to:
  /// **'{marked} of {total}'**
  String attendanceGroupProgress(int marked, int total);

  /// No description provided for @attendanceHoursUnit.
  ///
  /// In en, this message translates to:
  /// **'h'**
  String get attendanceHoursUnit;

  /// No description provided for @attendanceLeft.
  ///
  /// In en, this message translates to:
  /// **'Unmarked'**
  String get attendanceLeft;

  /// No description provided for @attendanceLoadFailedMessage.
  ///
  /// In en, this message translates to:
  /// **'Your marks are safe on this phone. Try again.'**
  String get attendanceLoadFailedMessage;

  /// No description provided for @attendanceLoadFailedTitle.
  ///
  /// In en, this message translates to:
  /// **'Could not open the muster'**
  String get attendanceLoadFailedTitle;

  /// No description provided for @attendanceLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading the muster'**
  String get attendanceLoading;

  /// No description provided for @attendanceMarkedSoFar.
  ///
  /// In en, this message translates to:
  /// **'Marked so far'**
  String get attendanceMarkedSoFar;

  /// No description provided for @attendanceNoProjectMessage.
  ///
  /// In en, this message translates to:
  /// **'Choose a project first, then mark the muster here.'**
  String get attendanceNoProjectMessage;

  /// No description provided for @attendanceNoProjectTitle.
  ///
  /// In en, this message translates to:
  /// **'No project chosen'**
  String get attendanceNoProjectTitle;

  /// No description provided for @attendanceNoWorkersAsk.
  ///
  /// In en, this message translates to:
  /// **'Ask the office to add the workers.'**
  String get attendanceNoWorkersAsk;

  /// No description provided for @attendanceNoWorkersMessage.
  ///
  /// In en, this message translates to:
  /// **'Add your first worker to start the muster.'**
  String get attendanceNoWorkersMessage;

  /// No description provided for @attendanceNoWorkersTitle.
  ///
  /// In en, this message translates to:
  /// **'No workers on the muster'**
  String get attendanceNoWorkersTitle;

  /// No description provided for @attendanceOtHours.
  ///
  /// In en, this message translates to:
  /// **'Overtime hours'**
  String get attendanceOtHours;

  /// No description provided for @attendanceOtLess.
  ///
  /// In en, this message translates to:
  /// **'Half hour less'**
  String get attendanceOtLess;

  /// No description provided for @attendanceOtMore.
  ///
  /// In en, this message translates to:
  /// **'Half hour more'**
  String get attendanceOtMore;

  /// No description provided for @attendanceSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'That mark was not saved. Tap it again.'**
  String get attendanceSaveFailed;

  /// No description provided for @attendanceScanCard.
  ///
  /// In en, this message translates to:
  /// **'Scan card'**
  String get attendanceScanCard;

  /// No description provided for @attendanceSelfie.
  ///
  /// In en, this message translates to:
  /// **'Selfie'**
  String get attendanceSelfie;

  /// No description provided for @attendanceSheetDone.
  ///
  /// In en, this message translates to:
  /// **'{name} added to the muster'**
  String attendanceSheetDone(String name);

  /// No description provided for @attendanceSheetFailed.
  ///
  /// In en, this message translates to:
  /// **'The worker could not be added. Try again.'**
  String get attendanceSheetFailed;

  /// No description provided for @attendanceSheetName.
  ///
  /// In en, this message translates to:
  /// **'Worker\'s name'**
  String get attendanceSheetName;

  /// No description provided for @attendanceSheetNameHint.
  ///
  /// In en, this message translates to:
  /// **'As on the muster'**
  String get attendanceSheetNameHint;

  /// No description provided for @attendanceSheetNameRequired.
  ///
  /// In en, this message translates to:
  /// **'Write the worker\'s name.'**
  String get attendanceSheetNameRequired;

  /// No description provided for @attendanceSheetOfflineError.
  ///
  /// In en, this message translates to:
  /// **'No network. Try again when the phone is back online.'**
  String get attendanceSheetOfflineError;

  /// No description provided for @attendanceSheetOfflineMessage.
  ///
  /// In en, this message translates to:
  /// **'Attendance still works without network. Add this worker when the phone is back online.'**
  String get attendanceSheetOfflineMessage;

  /// No description provided for @attendanceSheetOfflineTitle.
  ///
  /// In en, this message translates to:
  /// **'Adding a worker needs network'**
  String get attendanceSheetOfflineTitle;

  /// No description provided for @attendanceSheetTitle.
  ///
  /// In en, this message translates to:
  /// **'New worker'**
  String get attendanceSheetTitle;

  /// No description provided for @attendanceSheetTrade.
  ///
  /// In en, this message translates to:
  /// **'Trade'**
  String get attendanceSheetTrade;

  /// No description provided for @attendanceSheetTradeRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose the trade.'**
  String get attendanceSheetTradeRequired;

  /// No description provided for @attendanceSheetWageFor.
  ///
  /// In en, this message translates to:
  /// **'Daily wage for {name}'**
  String attendanceSheetWageFor(String name);

  /// No description provided for @attendanceSheetWageHelper.
  ///
  /// In en, this message translates to:
  /// **'Leave blank if you do not know it.'**
  String get attendanceSheetWageHelper;

  /// No description provided for @attendanceTitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get attendanceTitle;

  /// No description provided for @attendanceTodayTitle.
  ///
  /// In en, this message translates to:
  /// **'Today\'s attendance'**
  String get attendanceTodayTitle;

  /// No description provided for @boqAddItem.
  ///
  /// In en, this message translates to:
  /// **'Add item'**
  String get boqAddItem;

  /// No description provided for @boqBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance {qty} {unit}'**
  String boqBalance(String qty, String unit);

  /// No description provided for @boqBalanceLabel.
  ///
  /// In en, this message translates to:
  /// **'Balance'**
  String get boqBalanceLabel;

  /// No description provided for @boqEmptyAsk.
  ///
  /// In en, this message translates to:
  /// **'A BOQ is the list of work on this project. Ask the office to add it.'**
  String get boqEmptyAsk;

  /// No description provided for @boqEmptyExplain.
  ///
  /// In en, this message translates to:
  /// **'A BOQ is the list of work on this project, with quantity and rate.'**
  String get boqEmptyExplain;

  /// No description provided for @boqEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No BOQ lines yet'**
  String get boqEmptyTitle;

  /// No description provided for @boqFormAdded.
  ///
  /// In en, this message translates to:
  /// **'Line added. Add the next one.'**
  String get boqFormAdded;

  /// No description provided for @boqFormAmount.
  ///
  /// In en, this message translates to:
  /// **'Amount'**
  String get boqFormAmount;

  /// No description provided for @boqFormCode.
  ///
  /// In en, this message translates to:
  /// **'Code (optional)'**
  String get boqFormCode;

  /// No description provided for @boqFormCodeHelper.
  ///
  /// In en, this message translates to:
  /// **'Leave blank to get the next number.'**
  String get boqFormCodeHelper;

  /// No description provided for @boqFormDescription.
  ///
  /// In en, this message translates to:
  /// **'Work description'**
  String get boqFormDescription;

  /// No description provided for @boqFormDescriptionHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. M25 concrete in slab'**
  String get boqFormDescriptionHint;

  /// No description provided for @boqFormDescriptionRequired.
  ///
  /// In en, this message translates to:
  /// **'Write the work description.'**
  String get boqFormDescriptionRequired;

  /// No description provided for @boqFormFailed.
  ///
  /// In en, this message translates to:
  /// **'The line could not be saved. Try again.'**
  String get boqFormFailed;

  /// No description provided for @boqFormFinish.
  ///
  /// In en, this message translates to:
  /// **'Save and finish'**
  String get boqFormFinish;

  /// No description provided for @boqFormQty.
  ///
  /// In en, this message translates to:
  /// **'Quantity'**
  String get boqFormQty;

  /// No description provided for @boqFormQtyRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter the quantity.'**
  String get boqFormQtyRequired;

  /// No description provided for @boqFormRate.
  ///
  /// In en, this message translates to:
  /// **'Rate'**
  String get boqFormRate;

  /// No description provided for @boqFormRateRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter the rate.'**
  String get boqFormRateRequired;

  /// No description provided for @boqFormSaveAnother.
  ///
  /// In en, this message translates to:
  /// **'Save and add another'**
  String get boqFormSaveAnother;

  /// No description provided for @boqFormSection.
  ///
  /// In en, this message translates to:
  /// **'Section (optional)'**
  String get boqFormSection;

  /// No description provided for @boqFormSectionHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. Superstructure'**
  String get boqFormSectionHint;

  /// No description provided for @boqFormTitle.
  ///
  /// In en, this message translates to:
  /// **'Add BOQ item'**
  String get boqFormTitle;

  /// No description provided for @boqFormUnit.
  ///
  /// In en, this message translates to:
  /// **'Unit'**
  String get boqFormUnit;

  /// No description provided for @boqFormUnitRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose the unit.'**
  String get boqFormUnitRequired;

  /// No description provided for @boqItemCode.
  ///
  /// In en, this message translates to:
  /// **'Item {code}'**
  String boqItemCode(String code);

  /// No description provided for @boqLinesAdded.
  ///
  /// In en, this message translates to:
  /// **'{count} {count, plural, =1{line} other{lines}} added'**
  String boqLinesAdded(int count);

  /// No description provided for @boqLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading BOQ'**
  String get boqLoading;

  /// No description provided for @boqMeasuredShare.
  ///
  /// In en, this message translates to:
  /// **'{percent}% measured'**
  String boqMeasuredShare(int percent);

  /// No description provided for @boqMissingAsk.
  ///
  /// In en, this message translates to:
  /// **'Ask the office to add them. If they are added already, connect to the network once.'**
  String get boqMissingAsk;

  /// No description provided for @boqMissingManage.
  ///
  /// In en, this message translates to:
  /// **'Add the lines, then pick them here.'**
  String get boqMissingManage;

  /// No description provided for @boqMissingTitle.
  ///
  /// In en, this message translates to:
  /// **'This project has no BOQ lines yet'**
  String get boqMissingTitle;

  /// No description provided for @boqNoMatchMessage.
  ///
  /// In en, this message translates to:
  /// **'Try another code or word.'**
  String get boqNoMatchMessage;

  /// No description provided for @boqNoMatchTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing found'**
  String get boqNoMatchTitle;

  /// No description provided for @boqNoProjectMessage.
  ///
  /// In en, this message translates to:
  /// **'Choose a project first to see its BOQ.'**
  String get boqNoProjectMessage;

  /// No description provided for @boqNoProjectTitle.
  ///
  /// In en, this message translates to:
  /// **'No project chosen'**
  String get boqNoProjectTitle;

  /// No description provided for @boqNoSection.
  ///
  /// In en, this message translates to:
  /// **'Other lines'**
  String get boqNoSection;

  /// No description provided for @boqOfflineDetail.
  ///
  /// In en, this message translates to:
  /// **'BOQ lines can be saved only online.'**
  String get boqOfflineDetail;

  /// No description provided for @boqOfflineTitle.
  ///
  /// In en, this message translates to:
  /// **'No network.'**
  String get boqOfflineTitle;

  /// No description provided for @boqOpenAction.
  ///
  /// In en, this message translates to:
  /// **'Open BOQ'**
  String get boqOpenAction;

  /// No description provided for @boqOver.
  ///
  /// In en, this message translates to:
  /// **'Over by {qty} {unit}'**
  String boqOver(String qty, String unit);

  /// No description provided for @boqOverLabel.
  ///
  /// In en, this message translates to:
  /// **'Over by'**
  String get boqOverLabel;

  /// No description provided for @boqRefreshFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not update. Showing what is on this phone.'**
  String get boqRefreshFailed;

  /// No description provided for @boqSearchHint.
  ///
  /// In en, this message translates to:
  /// **'Code or work'**
  String get boqSearchHint;

  /// No description provided for @boqSearchLabel.
  ///
  /// In en, this message translates to:
  /// **'Search lines'**
  String get boqSearchLabel;

  /// No description provided for @boqTitle.
  ///
  /// In en, this message translates to:
  /// **'BOQ'**
  String get boqTitle;

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

  /// No description provided for @contractorAdvance.
  ///
  /// In en, this message translates to:
  /// **'Advance'**
  String get contractorAdvance;

  /// No description provided for @contractorAgainst.
  ///
  /// In en, this message translates to:
  /// **'Against'**
  String get contractorAgainst;

  /// No description provided for @contractorAgainstLine.
  ///
  /// In en, this message translates to:
  /// **'A BOQ line'**
  String get contractorAgainstLine;

  /// No description provided for @contractorAgainstPart.
  ///
  /// In en, this message translates to:
  /// **'A part'**
  String get contractorAgainstPart;

  /// No description provided for @contractorAgainstWhole.
  ///
  /// In en, this message translates to:
  /// **'Whole contract'**
  String get contractorAgainstWhole;

  /// No description provided for @contractorAmount.
  ///
  /// In en, this message translates to:
  /// **'Amount (₹)'**
  String get contractorAmount;

  /// No description provided for @contractorAmountError.
  ///
  /// In en, this message translates to:
  /// **'Enter an amount above zero, with at most two decimal places.'**
  String get contractorAmountError;

  /// No description provided for @contractorAmountLabel.
  ///
  /// In en, this message translates to:
  /// **'Amount'**
  String get contractorAmountLabel;

  /// No description provided for @contractorApprovalNote.
  ///
  /// In en, this message translates to:
  /// **'This goes for approval. Nothing counts as paid until it is approved.'**
  String get contractorApprovalNote;

  /// No description provided for @contractorBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance to pay'**
  String get contractorBalance;

  /// No description provided for @contractorBillFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not load the bill. Check your internet.'**
  String get contractorBillFailed;

  /// No description provided for @contractorBillNotPicture.
  ///
  /// In en, this message translates to:
  /// **'This bill is not a picture. Open it on the website.'**
  String get contractorBillNotPicture;

  /// No description provided for @contractorBillPhoto.
  ///
  /// In en, this message translates to:
  /// **'Add bill photo'**
  String get contractorBillPhoto;

  /// No description provided for @contractorClosed.
  ///
  /// In en, this message translates to:
  /// **'Closed'**
  String get contractorClosed;

  /// No description provided for @contractorContract.
  ///
  /// In en, this message translates to:
  /// **'Contract'**
  String get contractorContract;

  /// No description provided for @contractorContractChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose a contract'**
  String get contractorContractChoose;

  /// No description provided for @contractorContractClosed.
  ///
  /// In en, this message translates to:
  /// **'This contract is closed.'**
  String get contractorContractClosed;

  /// No description provided for @contractorContractSearch.
  ///
  /// In en, this message translates to:
  /// **'Search contracts'**
  String get contractorContractSearch;

  /// No description provided for @contractorEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Work orders for this project will show here.'**
  String get contractorEmptyMessage;

  /// No description provided for @contractorEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No contracts yet'**
  String get contractorEmptyTitle;

  /// No description provided for @contractorEnteredBy.
  ///
  /// In en, this message translates to:
  /// **'Entered by'**
  String get contractorEnteredBy;

  /// No description provided for @contractorHomeAhead.
  ///
  /// In en, this message translates to:
  /// **'{count} paid ahead of work'**
  String contractorHomeAhead(int count);

  /// No description provided for @contractorHomeCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 contract} other{{count} contracts}}'**
  String contractorHomeCount(int count);

  /// No description provided for @contractorHomeInStep.
  ///
  /// In en, this message translates to:
  /// **'All in step with work'**
  String get contractorHomeInStep;

  /// No description provided for @contractorHomeOver.
  ///
  /// In en, this message translates to:
  /// **'{count} over the limit'**
  String contractorHomeOver(int count);

  /// No description provided for @contractorHomeOverview.
  ///
  /// In en, this message translates to:
  /// **'Work done against each contract'**
  String get contractorHomeOverview;

  /// No description provided for @contractorImpactAfter.
  ///
  /// In en, this message translates to:
  /// **'If approved'**
  String get contractorImpactAfter;

  /// No description provided for @contractorImpactLine.
  ///
  /// In en, this message translates to:
  /// **'This line'**
  String get contractorImpactLine;

  /// No description provided for @contractorImpactNow.
  ///
  /// In en, this message translates to:
  /// **'Now'**
  String get contractorImpactNow;

  /// No description provided for @contractorInStep.
  ///
  /// In en, this message translates to:
  /// **'In step with work'**
  String get contractorInStep;

  /// No description provided for @contractorKind.
  ///
  /// In en, this message translates to:
  /// **'This payment is'**
  String get contractorKind;

  /// No description provided for @contractorKindAdvance.
  ///
  /// In en, this message translates to:
  /// **'An advance'**
  String get contractorKindAdvance;

  /// No description provided for @contractorKindWork.
  ///
  /// In en, this message translates to:
  /// **'For work done'**
  String get contractorKindWork;

  /// No description provided for @contractorLatestOnly.
  ///
  /// In en, this message translates to:
  /// **'Showing the latest 50. The rest are on the website.'**
  String get contractorLatestOnly;

  /// No description provided for @contractorLineChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose a BOQ line'**
  String get contractorLineChoose;

  /// No description provided for @contractorLines.
  ///
  /// In en, this message translates to:
  /// **'Work lines'**
  String get contractorLines;

  /// No description provided for @contractorLinesLoadOnline.
  ///
  /// In en, this message translates to:
  /// **'BOQ lines and parts load once this contract has been opened online.'**
  String get contractorLinesLoadOnline;

  /// No description provided for @contractorLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading contracts'**
  String get contractorLoading;

  /// No description provided for @contractorMode.
  ///
  /// In en, this message translates to:
  /// **'Paid by'**
  String get contractorMode;

  /// No description provided for @contractorModeBank.
  ///
  /// In en, this message translates to:
  /// **'Bank'**
  String get contractorModeBank;

  /// No description provided for @contractorModeCash.
  ///
  /// In en, this message translates to:
  /// **'Cash'**
  String get contractorModeCash;

  /// No description provided for @contractorModeRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose how it was paid.'**
  String get contractorModeRequired;

  /// No description provided for @contractorModeUpi.
  ///
  /// In en, this message translates to:
  /// **'UPI'**
  String get contractorModeUpi;

  /// No description provided for @contractorNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load this project\'s contracts. After that they open offline.'**
  String get contractorNoDataMessage;

  /// No description provided for @contractorNoPaymentsMessage.
  ///
  /// In en, this message translates to:
  /// **'Requests you raise will show here with their status.'**
  String get contractorNoPaymentsMessage;

  /// No description provided for @contractorNoPaymentsTitle.
  ///
  /// In en, this message translates to:
  /// **'No payment requests yet'**
  String get contractorNoPaymentsTitle;

  /// No description provided for @contractorNotes.
  ///
  /// In en, this message translates to:
  /// **'Notes'**
  String get contractorNotes;

  /// No description provided for @contractorOptional.
  ///
  /// In en, this message translates to:
  /// **'Optional'**
  String get contractorOptional;

  /// No description provided for @contractorOtherDate.
  ///
  /// In en, this message translates to:
  /// **'Other date'**
  String get contractorOtherDate;

  /// No description provided for @contractorOverLimit.
  ///
  /// In en, this message translates to:
  /// **'Over the limit'**
  String get contractorOverLimit;

  /// No description provided for @contractorPaid.
  ///
  /// In en, this message translates to:
  /// **'Paid'**
  String get contractorPaid;

  /// No description provided for @contractorPaidAhead.
  ///
  /// In en, this message translates to:
  /// **'Paid ahead of work'**
  String get contractorPaidAhead;

  /// No description provided for @contractorPaidOn.
  ///
  /// In en, this message translates to:
  /// **'Paid on'**
  String get contractorPaidOn;

  /// No description provided for @contractorPaidTo.
  ///
  /// In en, this message translates to:
  /// **'Paid to'**
  String get contractorPaidTo;

  /// No description provided for @contractorPaidToHint.
  ///
  /// In en, this message translates to:
  /// **'Leave blank for the contractor'**
  String get contractorPaidToHint;

  /// No description provided for @contractorPartChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose a part'**
  String get contractorPartChoose;

  /// No description provided for @contractorPartDone.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get contractorPartDone;

  /// No description provided for @contractorPartNotDone.
  ///
  /// In en, this message translates to:
  /// **'Not done'**
  String get contractorPartNotDone;

  /// No description provided for @contractorPartNotPaid.
  ///
  /// In en, this message translates to:
  /// **'Not paid'**
  String get contractorPartNotPaid;

  /// No description provided for @contractorPartOver.
  ///
  /// In en, this message translates to:
  /// **'Paid more than the part'**
  String get contractorPartOver;

  /// No description provided for @contractorPartPaid.
  ///
  /// In en, this message translates to:
  /// **'Paid'**
  String get contractorPartPaid;

  /// No description provided for @contractorPartPartPaid.
  ///
  /// In en, this message translates to:
  /// **'Part paid'**
  String get contractorPartPartPaid;

  /// No description provided for @contractorParts.
  ///
  /// In en, this message translates to:
  /// **'Parts'**
  String get contractorParts;

  /// No description provided for @contractorReference.
  ///
  /// In en, this message translates to:
  /// **'Reference'**
  String get contractorReference;

  /// No description provided for @contractorRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'Contracts are not open to your role. Ask your office if you need them.'**
  String get contractorRefusedMessage;

  /// No description provided for @contractorRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'Not part of your role'**
  String get contractorRefusedTitle;

  /// No description provided for @contractorRequest.
  ///
  /// In en, this message translates to:
  /// **'Request payment'**
  String get contractorRequest;

  /// No description provided for @contractorReversal.
  ///
  /// In en, this message translates to:
  /// **'Reversal'**
  String get contractorReversal;

  /// No description provided for @contractorSearchNone.
  ///
  /// In en, this message translates to:
  /// **'Nothing matches'**
  String get contractorSearchNone;

  /// No description provided for @contractorSend.
  ///
  /// In en, this message translates to:
  /// **'Send request'**
  String get contractorSend;

  /// No description provided for @contractorShortBy.
  ///
  /// In en, this message translates to:
  /// **'Short by'**
  String get contractorShortBy;

  /// No description provided for @contractorTabContracts.
  ///
  /// In en, this message translates to:
  /// **'Contracts'**
  String get contractorTabContracts;

  /// No description provided for @contractorTabPayments.
  ///
  /// In en, this message translates to:
  /// **'Payments'**
  String get contractorTabPayments;

  /// No description provided for @contractorTitle.
  ///
  /// In en, this message translates to:
  /// **'Contractors'**
  String get contractorTitle;

  /// No description provided for @contractorValue.
  ///
  /// In en, this message translates to:
  /// **'Contract value'**
  String get contractorValue;

  /// No description provided for @contractorViewBill.
  ///
  /// In en, this message translates to:
  /// **'View bill'**
  String get contractorViewBill;

  /// No description provided for @contractorWorkDone.
  ///
  /// In en, this message translates to:
  /// **'Work done'**
  String get contractorWorkDone;

  /// No description provided for @contractorWorkLeft.
  ///
  /// In en, this message translates to:
  /// **'Work left'**
  String get contractorWorkLeft;

  /// No description provided for @contractorWorkLeftValue.
  ///
  /// In en, this message translates to:
  /// **'Value of work left'**
  String get contractorWorkLeftValue;

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

  /// No description provided for @dprAdd.
  ///
  /// In en, this message translates to:
  /// **'Add'**
  String get dprAdd;

  /// No description provided for @dprAddNotes.
  ///
  /// In en, this message translates to:
  /// **'Add hindrances or notes'**
  String get dprAddNotes;

  /// No description provided for @dprAddWork.
  ///
  /// In en, this message translates to:
  /// **'Add work'**
  String get dprAddWork;

  /// No description provided for @dprAddedTag.
  ///
  /// In en, this message translates to:
  /// **'Added'**
  String get dprAddedTag;

  /// No description provided for @dprApproverRemark.
  ///
  /// In en, this message translates to:
  /// **'Approver\'s remark'**
  String get dprApproverRemark;

  /// No description provided for @dprAttentionBody.
  ///
  /// In en, this message translates to:
  /// **'This report could not be sent. Open Outbox to fix it.'**
  String get dprAttentionBody;

  /// No description provided for @dprBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance'**
  String get dprBalance;

  /// No description provided for @dprBoqDonePercent.
  ///
  /// In en, this message translates to:
  /// **'{percent} percent of BOQ measured'**
  String dprBoqDonePercent(int percent);

  /// No description provided for @dprBoqEmpty.
  ///
  /// In en, this message translates to:
  /// **'No BOQ items found.'**
  String get dprBoqEmpty;

  /// No description provided for @dprCameraFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not open the camera. Allow camera in phone settings.'**
  String get dprCameraFailed;

  /// No description provided for @dprCanStillSend.
  ///
  /// In en, this message translates to:
  /// **'You can still send.'**
  String get dprCanStillSend;

  /// No description provided for @dprChooseAnother.
  ///
  /// In en, this message translates to:
  /// **'Change item'**
  String get dprChooseAnother;

  /// No description provided for @dprCorrectAndSend.
  ///
  /// In en, this message translates to:
  /// **'Correct and send again'**
  String get dprCorrectAndSend;

  /// No description provided for @dprEdit.
  ///
  /// In en, this message translates to:
  /// **'Edit'**
  String get dprEdit;

  /// No description provided for @dprEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Your first report starts this book.'**
  String get dprEmptyMessage;

  /// No description provided for @dprEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No reports yet'**
  String get dprEmptyTitle;

  /// No description provided for @dprFilledFromYesterday.
  ///
  /// In en, this message translates to:
  /// **'Filled from yesterday'**
  String get dprFilledFromYesterday;

  /// No description provided for @dprFormTitle.
  ///
  /// In en, this message translates to:
  /// **'Daily report'**
  String get dprFormTitle;

  /// No description provided for @dprFutureDate.
  ///
  /// In en, this message translates to:
  /// **'The report date cannot be in the future.'**
  String get dprFutureDate;

  /// No description provided for @dprHindrances.
  ///
  /// In en, this message translates to:
  /// **'Hindrances'**
  String get dprHindrances;

  /// No description provided for @dprHindrancesHint.
  ///
  /// In en, this message translates to:
  /// **'Material late, rain, power cut'**
  String get dprHindrancesHint;

  /// No description provided for @dprListTitle.
  ///
  /// In en, this message translates to:
  /// **'Daily reports'**
  String get dprListTitle;

  /// No description provided for @dprLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading'**
  String get dprLoading;

  /// No description provided for @dprLocked.
  ///
  /// In en, this message translates to:
  /// **'This report has gone for approval and cannot be changed now.'**
  String get dprLocked;

  /// No description provided for @dprMissedDays.
  ///
  /// In en, this message translates to:
  /// **'Missed days'**
  String get dprMissedDays;

  /// No description provided for @dprMoreRows.
  ///
  /// In en, this message translates to:
  /// **'and {count} more'**
  String dprMoreRows(int count);

  /// No description provided for @dprMoreTrades.
  ///
  /// In en, this message translates to:
  /// **'More trades ({count})'**
  String dprMoreTrades(int count);

  /// No description provided for @dprNeedWeather.
  ///
  /// In en, this message translates to:
  /// **'Choose today\'s weather.'**
  String get dprNeedWeather;

  /// No description provided for @dprNeedWorkers.
  ///
  /// In en, this message translates to:
  /// **'Enter the workers who came.'**
  String get dprNeedWorkers;

  /// No description provided for @dprNoPhotosYet.
  ///
  /// In en, this message translates to:
  /// **'No photos added.'**
  String get dprNoPhotosYet;

  /// No description provided for @dprNoProject.
  ///
  /// In en, this message translates to:
  /// **'Pick a project first'**
  String get dprNoProject;

  /// No description provided for @dprNoWorkYet.
  ///
  /// In en, this message translates to:
  /// **'No work added.'**
  String get dprNoWorkYet;

  /// No description provided for @dprNotSaved.
  ///
  /// In en, this message translates to:
  /// **'Not saved'**
  String get dprNotSaved;

  /// No description provided for @dprNotes.
  ///
  /// In en, this message translates to:
  /// **'Notes'**
  String get dprNotes;

  /// No description provided for @dprNotesHint.
  ///
  /// In en, this message translates to:
  /// **'Anything else from today'**
  String get dprNotesHint;

  /// No description provided for @dprNothingRecorded.
  ///
  /// In en, this message translates to:
  /// **'Nothing recorded'**
  String get dprNothingRecorded;

  /// No description provided for @dprOfDays.
  ///
  /// In en, this message translates to:
  /// **'of {days} days'**
  String dprOfDays(int days);

  /// No description provided for @dprOpenOutbox.
  ///
  /// In en, this message translates to:
  /// **'Open Outbox'**
  String get dprOpenOutbox;

  /// No description provided for @dprOpenToday.
  ///
  /// In en, this message translates to:
  /// **'Open today\'s report'**
  String get dprOpenToday;

  /// No description provided for @dprOptional.
  ///
  /// In en, this message translates to:
  /// **'Optional'**
  String get dprOptional;

  /// No description provided for @dprOverBalance.
  ///
  /// In en, this message translates to:
  /// **'More than the BOQ balance. Check the figure.'**
  String get dprOverBalance;

  /// No description provided for @dprPhotoFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not add this photo. Try again.'**
  String get dprPhotoFailed;

  /// No description provided for @dprPhotoMissing.
  ///
  /// In en, this message translates to:
  /// **'Not available'**
  String get dprPhotoMissing;

  /// No description provided for @dprPhotosCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 photo} other{{count} photos}}'**
  String dprPhotosCount(int count);

  /// No description provided for @dprPhotosFull.
  ///
  /// In en, this message translates to:
  /// **'A report can hold 10 photos.'**
  String get dprPhotosFull;

  /// No description provided for @dprQtyShort.
  ///
  /// In en, this message translates to:
  /// **'Qty'**
  String get dprQtyShort;

  /// No description provided for @dprQuantity.
  ///
  /// In en, this message translates to:
  /// **'Quantity'**
  String get dprQuantity;

  /// No description provided for @dprRemove.
  ///
  /// In en, this message translates to:
  /// **'Remove'**
  String get dprRemove;

  /// No description provided for @dprRemoved.
  ///
  /// In en, this message translates to:
  /// **'Removed'**
  String get dprRemoved;

  /// No description provided for @dprReportsThisMonth.
  ///
  /// In en, this message translates to:
  /// **'Reports this month'**
  String get dprReportsThisMonth;

  /// No description provided for @dprReturnedNoRemark.
  ///
  /// In en, this message translates to:
  /// **'Please correct and send again.'**
  String get dprReturnedNoRemark;

  /// No description provided for @dprReturnedTitle.
  ///
  /// In en, this message translates to:
  /// **'Sent back for correction'**
  String get dprReturnedTitle;

  /// No description provided for @dprReviewOffline.
  ///
  /// In en, this message translates to:
  /// **'No network now. The report stays on this phone and goes by itself when network returns.'**
  String get dprReviewOffline;

  /// No description provided for @dprReviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Check and send'**
  String get dprReviewTitle;

  /// No description provided for @dprRowsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 item} other{{count} items}}'**
  String dprRowsCount(int count);

  /// No description provided for @dprSameAsYesterday.
  ///
  /// In en, this message translates to:
  /// **'Same as yesterday ({count})'**
  String dprSameAsYesterday(int count);

  /// No description provided for @dprSavedOnPhone.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone'**
  String get dprSavedOnPhone;

  /// No description provided for @dprSavedSendingNow.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone, sending now'**
  String get dprSavedSendingNow;

  /// No description provided for @dprSavedWillSend.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone, will send when network returns'**
  String get dprSavedWillSend;

  /// No description provided for @dprSaving.
  ///
  /// In en, this message translates to:
  /// **'Saving on phone'**
  String get dprSaving;

  /// No description provided for @dprSearchBoq.
  ///
  /// In en, this message translates to:
  /// **'Search BOQ'**
  String get dprSearchBoq;

  /// No description provided for @dprSearchHint.
  ///
  /// In en, this message translates to:
  /// **'Code or description'**
  String get dprSearchHint;

  /// No description provided for @dprSectionNotes.
  ///
  /// In en, this message translates to:
  /// **'Hindrances and notes'**
  String get dprSectionNotes;

  /// No description provided for @dprSectionPhotos.
  ///
  /// In en, this message translates to:
  /// **'Photos'**
  String get dprSectionPhotos;

  /// No description provided for @dprSectionWeather.
  ///
  /// In en, this message translates to:
  /// **'Weather'**
  String get dprSectionWeather;

  /// No description provided for @dprSectionWork.
  ///
  /// In en, this message translates to:
  /// **'Work done'**
  String get dprSectionWork;

  /// No description provided for @dprSectionWorkers.
  ///
  /// In en, this message translates to:
  /// **'Workers'**
  String get dprSectionWorkers;

  /// No description provided for @dprSend.
  ///
  /// In en, this message translates to:
  /// **'Send'**
  String get dprSend;

  /// No description provided for @dprSendAgain.
  ///
  /// In en, this message translates to:
  /// **'Send again'**
  String get dprSendAgain;

  /// No description provided for @dprSendFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save this report. Try again.'**
  String get dprSendFailed;

  /// No description provided for @dprSendReport.
  ///
  /// In en, this message translates to:
  /// **'Send report'**
  String get dprSendReport;

  /// No description provided for @dprSiteClosedWrite.
  ///
  /// In en, this message translates to:
  /// **'Site closed? Write the reason'**
  String get dprSiteClosedWrite;

  /// No description provided for @dprSyncAttention.
  ///
  /// In en, this message translates to:
  /// **'Needs attention'**
  String get dprSyncAttention;

  /// No description provided for @dprSyncPhoneOnly.
  ///
  /// In en, this message translates to:
  /// **'Phone only'**
  String get dprSyncPhoneOnly;

  /// No description provided for @dprSyncSending.
  ///
  /// In en, this message translates to:
  /// **'Sending'**
  String get dprSyncSending;

  /// No description provided for @dprSyncWaiting.
  ///
  /// In en, this message translates to:
  /// **'Not sent yet'**
  String get dprSyncWaiting;

  /// No description provided for @dprTakePhoto.
  ///
  /// In en, this message translates to:
  /// **'Take photo'**
  String get dprTakePhoto;

  /// No description provided for @dprTotalWorkers.
  ///
  /// In en, this message translates to:
  /// **'Total workers'**
  String get dprTotalWorkers;

  /// No description provided for @dprUndo.
  ///
  /// In en, this message translates to:
  /// **'Undo'**
  String get dprUndo;

  /// No description provided for @dprWorkersCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 worker} other{{count} workers}}'**
  String dprWorkersCount(int count);

  /// No description provided for @dprWorkersOnSite.
  ///
  /// In en, this message translates to:
  /// **'Workers on site'**
  String get dprWorkersOnSite;

  /// No description provided for @dprWorkersWord.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{worker} other{workers}}'**
  String dprWorkersWord(int count);

  /// No description provided for @dprWriteFor.
  ///
  /// In en, this message translates to:
  /// **'Write report for {date}'**
  String dprWriteFor(String date);

  /// No description provided for @dprWriteToday.
  ///
  /// In en, this message translates to:
  /// **'Write today\'s report'**
  String get dprWriteToday;

  /// No description provided for @dprYesterdayLabel.
  ///
  /// In en, this message translates to:
  /// **'Yesterday'**
  String get dprYesterdayLabel;

  /// No description provided for @dprYesterdayTag.
  ///
  /// In en, this message translates to:
  /// **'Yesterday'**
  String get dprYesterdayTag;

  /// No description provided for @inspectionCategoryGeneral.
  ///
  /// In en, this message translates to:
  /// **'General'**
  String get inspectionCategoryGeneral;

  /// No description provided for @inspectionCategoryPourCard.
  ///
  /// In en, this message translates to:
  /// **'Pour card'**
  String get inspectionCategoryPourCard;

  /// No description provided for @inspectionCategoryPpe.
  ///
  /// In en, this message translates to:
  /// **'PPE'**
  String get inspectionCategoryPpe;

  /// No description provided for @inspectionCategoryReinforcement.
  ///
  /// In en, this message translates to:
  /// **'Reinforcement'**
  String get inspectionCategoryReinforcement;

  /// No description provided for @inspectionCategorySafety.
  ///
  /// In en, this message translates to:
  /// **'Safety'**
  String get inspectionCategorySafety;

  /// No description provided for @inspectionCategoryShuttering.
  ///
  /// In en, this message translates to:
  /// **'Shuttering'**
  String get inspectionCategoryShuttering;

  /// No description provided for @inspectionDiscard.
  ///
  /// In en, this message translates to:
  /// **'Discard'**
  String get inspectionDiscard;

  /// No description provided for @inspectionDiscardBody.
  ///
  /// In en, this message translates to:
  /// **'What you marked so far will be lost.'**
  String get inspectionDiscardBody;

  /// No description provided for @inspectionDiscardTitle.
  ///
  /// In en, this message translates to:
  /// **'Discard this inspection?'**
  String get inspectionDiscardTitle;

  /// No description provided for @inspectionFillChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose Pass, Fail or Not applicable'**
  String get inspectionFillChoose;

  /// No description provided for @inspectionFillDate.
  ///
  /// In en, this message translates to:
  /// **'Inspection date'**
  String get inspectionFillDate;

  /// No description provided for @inspectionFillLocation.
  ///
  /// In en, this message translates to:
  /// **'Location'**
  String get inspectionFillLocation;

  /// No description provided for @inspectionFillLocationHint.
  ///
  /// In en, this message translates to:
  /// **'For example Slab L3 or Gate 2. Optional.'**
  String get inspectionFillLocationHint;

  /// No description provided for @inspectionFillLockedNote.
  ///
  /// In en, this message translates to:
  /// **'Once finished, an inspection cannot be changed.'**
  String get inspectionFillLockedNote;

  /// No description provided for @inspectionFillMarked.
  ///
  /// In en, this message translates to:
  /// **'{done} of {total} marked'**
  String inspectionFillMarked(int done, int total);

  /// No description provided for @inspectionFillNeedRemark.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 failed item needs a reason} other{{count} failed items need a reason}}'**
  String inspectionFillNeedRemark(int count);

  /// No description provided for @inspectionFillUnmarked.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 item still has no result} other{{count} items still have no result}}'**
  String inspectionFillUnmarked(int count);

  /// No description provided for @inspectionFillWrong.
  ///
  /// In en, this message translates to:
  /// **'What is wrong?'**
  String get inspectionFillWrong;

  /// No description provided for @inspectionFillWrongRequired.
  ///
  /// In en, this message translates to:
  /// **'Write what is wrong. A failed item needs this.'**
  String get inspectionFillWrongRequired;

  /// No description provided for @inspectionFinish.
  ///
  /// In en, this message translates to:
  /// **'Finish inspection'**
  String get inspectionFinish;

  /// No description provided for @inspectionGoneMessage.
  ///
  /// In en, this message translates to:
  /// **'This inspection was finished or discarded.'**
  String get inspectionGoneMessage;

  /// No description provided for @inspectionGoneTitle.
  ///
  /// In en, this message translates to:
  /// **'Not on this phone'**
  String get inspectionGoneTitle;

  /// No description provided for @inspectionItemsNotLoaded.
  ///
  /// In en, this message translates to:
  /// **'The items are not on this phone yet. Connect to the internet to load them.'**
  String get inspectionItemsNotLoaded;

  /// No description provided for @inspectionNotOnPhone.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet to load it.'**
  String get inspectionNotOnPhone;

  /// No description provided for @inspectionOpenNote.
  ///
  /// In en, this message translates to:
  /// **'This inspection was started on the website and is not finished. Finish it there.'**
  String get inspectionOpenNote;

  /// No description provided for @inspectionOpenOutbox.
  ///
  /// In en, this message translates to:
  /// **'Open the sending list'**
  String get inspectionOpenOutbox;

  /// No description provided for @inspectionResultFail.
  ///
  /// In en, this message translates to:
  /// **'Fail'**
  String get inspectionResultFail;

  /// No description provided for @inspectionResultNa.
  ///
  /// In en, this message translates to:
  /// **'Not applicable'**
  String get inspectionResultNa;

  /// No description provided for @inspectionResultPass.
  ///
  /// In en, this message translates to:
  /// **'Pass'**
  String get inspectionResultPass;

  /// No description provided for @inspectionResultPending.
  ///
  /// In en, this message translates to:
  /// **'No result yet'**
  String get inspectionResultPending;

  /// No description provided for @inspectionStatusFailed.
  ///
  /// In en, this message translates to:
  /// **'Failed'**
  String get inspectionStatusFailed;

  /// No description provided for @inspectionStatusOpen.
  ///
  /// In en, this message translates to:
  /// **'In progress'**
  String get inspectionStatusOpen;

  /// No description provided for @inspectionStatusPassed.
  ///
  /// In en, this message translates to:
  /// **'Passed'**
  String get inspectionStatusPassed;

  /// No description provided for @inspectionWaitingNote.
  ///
  /// In en, this message translates to:
  /// **'Saved on this phone. It will be sent when you are online, and cannot be changed after that.'**
  String get inspectionWaitingNote;

  /// No description provided for @inspectionsChecklistSearch.
  ///
  /// In en, this message translates to:
  /// **'Search checklists'**
  String get inspectionsChecklistSearch;

  /// No description provided for @inspectionsDraftWord.
  ///
  /// In en, this message translates to:
  /// **'Not finished'**
  String get inspectionsDraftWord;

  /// No description provided for @inspectionsDraftsHeading.
  ///
  /// In en, this message translates to:
  /// **'Being filled in'**
  String get inspectionsDraftsHeading;

  /// No description provided for @inspectionsEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Start one from a checklist. It is saved on this phone and sent when you are online.'**
  String get inspectionsEmptyMessage;

  /// No description provided for @inspectionsEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No inspections yet'**
  String get inspectionsEmptyTitle;

  /// No description provided for @inspectionsFailedCount.
  ///
  /// In en, this message translates to:
  /// **'{count} failed'**
  String inspectionsFailedCount(int count);

  /// No description provided for @inspectionsItemCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 item} other{{count} items}}'**
  String inspectionsItemCount(int count);

  /// No description provided for @inspectionsLatestOnly.
  ///
  /// In en, this message translates to:
  /// **'Showing the latest 25. The rest are on the website.'**
  String get inspectionsLatestOnly;

  /// No description provided for @inspectionsLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading inspections'**
  String get inspectionsLoading;

  /// No description provided for @inspectionsNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load this project\'s checklists. After that they open offline.'**
  String get inspectionsNoDataMessage;

  /// No description provided for @inspectionsRecentHeading.
  ///
  /// In en, this message translates to:
  /// **'Recent'**
  String get inspectionsRecentHeading;

  /// No description provided for @inspectionsRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'Checklists are not open to your role. Ask your office if you need them.'**
  String get inspectionsRefusedMessage;

  /// No description provided for @inspectionsRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'Not part of your role'**
  String get inspectionsRefusedTitle;

  /// No description provided for @inspectionsSearchNone.
  ///
  /// In en, this message translates to:
  /// **'No checklist matches'**
  String get inspectionsSearchNone;

  /// No description provided for @inspectionsStart.
  ///
  /// In en, this message translates to:
  /// **'Start an inspection'**
  String get inspectionsStart;

  /// No description provided for @inspectionsTitle.
  ///
  /// In en, this message translates to:
  /// **'Inspections'**
  String get inspectionsTitle;

  /// No description provided for @issuesActionClose.
  ///
  /// In en, this message translates to:
  /// **'Close issue'**
  String get issuesActionClose;

  /// No description provided for @issuesActionReopen.
  ///
  /// In en, this message translates to:
  /// **'Reopen'**
  String get issuesActionReopen;

  /// No description provided for @issuesActionResolve.
  ///
  /// In en, this message translates to:
  /// **'Mark resolved'**
  String get issuesActionResolve;

  /// No description provided for @issuesActionStart.
  ///
  /// In en, this message translates to:
  /// **'Start work'**
  String get issuesActionStart;

  /// No description provided for @issuesAssignedUnknown.
  ///
  /// In en, this message translates to:
  /// **'Assigned'**
  String get issuesAssignedUnknown;

  /// No description provided for @issuesAssigneeSheetTitle.
  ///
  /// In en, this message translates to:
  /// **'Assign to'**
  String get issuesAssigneeSheetTitle;

  /// No description provided for @issuesDetailAssignee.
  ///
  /// In en, this message translates to:
  /// **'Assigned to'**
  String get issuesDetailAssignee;

  /// No description provided for @issuesDetailDescription.
  ///
  /// In en, this message translates to:
  /// **'Details'**
  String get issuesDetailDescription;

  /// No description provided for @issuesDetailDue.
  ///
  /// In en, this message translates to:
  /// **'Due date'**
  String get issuesDetailDue;

  /// No description provided for @issuesDetailMissingMessage.
  ///
  /// In en, this message translates to:
  /// **'It may have been removed. Go back to the list.'**
  String get issuesDetailMissingMessage;

  /// No description provided for @issuesDetailMissingTitle.
  ///
  /// In en, this message translates to:
  /// **'This issue is not on this phone'**
  String get issuesDetailMissingTitle;

  /// No description provided for @issuesDetailPriority.
  ///
  /// In en, this message translates to:
  /// **'Priority'**
  String get issuesDetailPriority;

  /// No description provided for @issuesDetailRaisedBy.
  ///
  /// In en, this message translates to:
  /// **'Raised by'**
  String get issuesDetailRaisedBy;

  /// No description provided for @issuesDetailResolvedOn.
  ///
  /// In en, this message translates to:
  /// **'Resolved on'**
  String get issuesDetailResolvedOn;

  /// No description provided for @issuesDueOn.
  ///
  /// In en, this message translates to:
  /// **'Due {date}'**
  String issuesDueOn(String date);

  /// No description provided for @issuesDueToday.
  ///
  /// In en, this message translates to:
  /// **'Due today'**
  String get issuesDueToday;

  /// No description provided for @issuesEmptyFilterMessage.
  ///
  /// In en, this message translates to:
  /// **'No issues with this status.'**
  String get issuesEmptyFilterMessage;

  /// No description provided for @issuesEmptyFilterTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing here'**
  String get issuesEmptyFilterTitle;

  /// No description provided for @issuesEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'If something is wrong on site, raise it here so it gets fixed.'**
  String get issuesEmptyMessage;

  /// No description provided for @issuesEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No issues on this site'**
  String get issuesEmptyTitle;

  /// No description provided for @issuesEyebrow.
  ///
  /// In en, this message translates to:
  /// **'Site issues'**
  String get issuesEyebrow;

  /// No description provided for @issuesFilterAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get issuesFilterAll;

  /// No description provided for @issuesFormAssignee.
  ///
  /// In en, this message translates to:
  /// **'Who will fix it? (optional)'**
  String get issuesFormAssignee;

  /// No description provided for @issuesFormDue.
  ///
  /// In en, this message translates to:
  /// **'Fix by (optional)'**
  String get issuesFormDue;

  /// No description provided for @issuesFormDueClear.
  ///
  /// In en, this message translates to:
  /// **'Tap the chosen day again to clear it.'**
  String get issuesFormDueClear;

  /// No description provided for @issuesFormDueThree.
  ///
  /// In en, this message translates to:
  /// **'3 days'**
  String get issuesFormDueThree;

  /// No description provided for @issuesFormDueToday.
  ///
  /// In en, this message translates to:
  /// **'Today'**
  String get issuesFormDueToday;

  /// No description provided for @issuesFormDueWeek.
  ///
  /// In en, this message translates to:
  /// **'1 week'**
  String get issuesFormDueWeek;

  /// No description provided for @issuesFormEyebrow.
  ///
  /// In en, this message translates to:
  /// **'New issue'**
  String get issuesFormEyebrow;

  /// No description provided for @issuesFormLocation.
  ///
  /// In en, this message translates to:
  /// **'Where on site? (optional)'**
  String get issuesFormLocation;

  /// No description provided for @issuesFormLocationHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. Grid C, third floor'**
  String get issuesFormLocationHint;

  /// No description provided for @issuesFormPhotos.
  ///
  /// In en, this message translates to:
  /// **'Photos of the problem (optional)'**
  String get issuesFormPhotos;

  /// No description provided for @issuesFormPriority.
  ///
  /// In en, this message translates to:
  /// **'How urgent is it?'**
  String get issuesFormPriority;

  /// No description provided for @issuesFormSave.
  ///
  /// In en, this message translates to:
  /// **'Save issue'**
  String get issuesFormSave;

  /// No description provided for @issuesFormTitle.
  ///
  /// In en, this message translates to:
  /// **'Raise an issue'**
  String get issuesFormTitle;

  /// No description provided for @issuesFormWhat.
  ///
  /// In en, this message translates to:
  /// **'What is wrong?'**
  String get issuesFormWhat;

  /// No description provided for @issuesFormWhatHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. Crack in column C3'**
  String get issuesFormWhatHint;

  /// No description provided for @issuesFormWhatRequired.
  ///
  /// In en, this message translates to:
  /// **'Write what is wrong.'**
  String get issuesFormWhatRequired;

  /// No description provided for @issuesLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading issues'**
  String get issuesLoading;

  /// No description provided for @issuesNoDueDate.
  ///
  /// In en, this message translates to:
  /// **'No due date'**
  String get issuesNoDueDate;

  /// No description provided for @issuesNoProjectMessage.
  ///
  /// In en, this message translates to:
  /// **'Choose a project first, then raise issues here.'**
  String get issuesNoProjectMessage;

  /// No description provided for @issuesNoProjectTitle.
  ///
  /// In en, this message translates to:
  /// **'No project chosen'**
  String get issuesNoProjectTitle;

  /// No description provided for @issuesOverdue.
  ///
  /// In en, this message translates to:
  /// **'Overdue by {days} {days, plural, =1{day} other{days}}'**
  String issuesOverdue(int days);

  /// No description provided for @issuesPhotoAddAfter.
  ///
  /// In en, this message translates to:
  /// **'Add after photo'**
  String get issuesPhotoAddAfter;

  /// No description provided for @issuesPhotoAddBefore.
  ///
  /// In en, this message translates to:
  /// **'Add before photo'**
  String get issuesPhotoAddBefore;

  /// No description provided for @issuesPhotoFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not add the photo. Try again.'**
  String get issuesPhotoFailed;

  /// No description provided for @issuesPhotoSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Photo saved on phone. Sends when network returns.'**
  String get issuesPhotoSavedOffline;

  /// No description provided for @issuesPhotoSavedOnline.
  ///
  /// In en, this message translates to:
  /// **'Photo saved. Sending now.'**
  String get issuesPhotoSavedOnline;

  /// No description provided for @issuesPhotosAfter.
  ///
  /// In en, this message translates to:
  /// **'After'**
  String get issuesPhotosAfter;

  /// No description provided for @issuesPhotosBefore.
  ///
  /// In en, this message translates to:
  /// **'Before'**
  String get issuesPhotosBefore;

  /// No description provided for @issuesPhotosNone.
  ///
  /// In en, this message translates to:
  /// **'No photos yet'**
  String get issuesPhotosNone;

  /// No description provided for @issuesPhotosTitle.
  ///
  /// In en, this message translates to:
  /// **'Photos'**
  String get issuesPhotosTitle;

  /// No description provided for @issuesRaise.
  ///
  /// In en, this message translates to:
  /// **'Raise issue'**
  String get issuesRaise;

  /// No description provided for @issuesRefreshFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not update. Showing what is on this phone.'**
  String get issuesRefreshFailed;

  /// No description provided for @issuesResolvedOn.
  ///
  /// In en, this message translates to:
  /// **'Resolved {date}'**
  String issuesResolvedOn(String date);

  /// No description provided for @issuesSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the issue. Try again.'**
  String get issuesSaveFailed;

  /// No description provided for @issuesSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone. Sends when network returns.'**
  String get issuesSavedOffline;

  /// No description provided for @issuesSavedOnline.
  ///
  /// In en, this message translates to:
  /// **'Saved. Sending now.'**
  String get issuesSavedOnline;

  /// No description provided for @issuesStatusFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not change the status. Try again.'**
  String get issuesStatusFailed;

  /// No description provided for @issuesSyncAttention.
  ///
  /// In en, this message translates to:
  /// **'Needs attention'**
  String get issuesSyncAttention;

  /// No description provided for @issuesSyncSending.
  ///
  /// In en, this message translates to:
  /// **'Sending'**
  String get issuesSyncSending;

  /// No description provided for @issuesTitle.
  ///
  /// In en, this message translates to:
  /// **'Issues'**
  String get issuesTitle;

  /// No description provided for @issuesUnassigned.
  ///
  /// In en, this message translates to:
  /// **'Not assigned'**
  String get issuesUnassigned;

  /// No description provided for @licenceContinue.
  ///
  /// In en, this message translates to:
  /// **'Continue'**
  String get licenceContinue;

  /// No description provided for @licenceEndedOn.
  ///
  /// In en, this message translates to:
  /// **'Ended on {date}'**
  String licenceEndedOn(String date);

  /// No description provided for @licenceEndedUploadBody.
  ///
  /// In en, this message translates to:
  /// **'Your company\'s plan now keeps data on the server, so this licence cannot be renewed. Nothing is lost. Make a copy if you like, then upload your data to the server to carry on.'**
  String get licenceEndedUploadBody;

  /// No description provided for @licenceNoneBody.
  ///
  /// In en, this message translates to:
  /// **'Nothing is lost. You can still look at everything here and make a copy of your data, but nothing can be added or changed until this phone has a licence. Connect to the internet and try now.'**
  String get licenceNoneBody;

  /// No description provided for @licenceNoneTitle.
  ///
  /// In en, this message translates to:
  /// **'This phone has no licence yet'**
  String get licenceNoneTitle;

  /// No description provided for @licenceRenewedBody.
  ///
  /// In en, this message translates to:
  /// **'You can add and change things again.'**
  String get licenceRenewedBody;

  /// No description provided for @licenceRenewedTitle.
  ///
  /// In en, this message translates to:
  /// **'Licence renewed'**
  String get licenceRenewedTitle;

  /// No description provided for @licenceResultNoAnswer.
  ///
  /// In en, this message translates to:
  /// **'The server did not answer. Try again in a minute.'**
  String get licenceResultNoAnswer;

  /// No description provided for @licenceResultNoLicence.
  ///
  /// In en, this message translates to:
  /// **'Connected, but this phone could not be given a licence. Check your plan on the Nirmaan360 website, then try again.'**
  String get licenceResultNoLicence;

  /// No description provided for @licenceResultOffline.
  ///
  /// In en, this message translates to:
  /// **'No internet found. Connect to Wi-Fi or mobile data, then try again.'**
  String get licenceResultOffline;

  /// No description provided for @licenceResultPlanSyncs.
  ///
  /// In en, this message translates to:
  /// **'Your plan keeps data on the server now, so there is no licence to renew. Upload your data to carry on.'**
  String get licenceResultPlanSyncs;

  /// No description provided for @licenceResultStillEnded.
  ///
  /// In en, this message translates to:
  /// **'Connected, but the licence is still ended. Check your plan on the Nirmaan360 website, then try again.'**
  String get licenceResultStillEnded;

  /// No description provided for @licenceScreenTitle.
  ///
  /// In en, this message translates to:
  /// **'Renew licence'**
  String get licenceScreenTitle;

  /// No description provided for @licenceTryNow.
  ///
  /// In en, this message translates to:
  /// **'Try renewing now'**
  String get licenceTryNow;

  /// No description provided for @licenceTrying.
  ///
  /// In en, this message translates to:
  /// **'Asking the server…'**
  String get licenceTrying;

  /// No description provided for @licenceUploadNow.
  ///
  /// In en, this message translates to:
  /// **'Upload to the server'**
  String get licenceUploadNow;

  /// No description provided for @licenceViewData.
  ///
  /// In en, this message translates to:
  /// **'Look at my data'**
  String get licenceViewData;

  /// No description provided for @machineCategoryBackhoe.
  ///
  /// In en, this message translates to:
  /// **'JCB / Backhoe loader'**
  String get machineCategoryBackhoe;

  /// No description provided for @machineCategoryCompressor.
  ///
  /// In en, this message translates to:
  /// **'Compressor'**
  String get machineCategoryCompressor;

  /// No description provided for @machineCategoryCrane.
  ///
  /// In en, this message translates to:
  /// **'Crane'**
  String get machineCategoryCrane;

  /// No description provided for @machineCategoryDgSet.
  ///
  /// In en, this message translates to:
  /// **'DG set'**
  String get machineCategoryDgSet;

  /// No description provided for @machineCategoryExcavator.
  ///
  /// In en, this message translates to:
  /// **'Excavator'**
  String get machineCategoryExcavator;

  /// No description provided for @machineCategoryFormwork.
  ///
  /// In en, this message translates to:
  /// **'Shuttering / Formwork'**
  String get machineCategoryFormwork;

  /// No description provided for @machineCategoryMixer.
  ///
  /// In en, this message translates to:
  /// **'Concrete mixer'**
  String get machineCategoryMixer;

  /// No description provided for @machineCategoryOther.
  ///
  /// In en, this message translates to:
  /// **'Other'**
  String get machineCategoryOther;

  /// No description provided for @machineCategoryPump.
  ///
  /// In en, this message translates to:
  /// **'Concrete pump'**
  String get machineCategoryPump;

  /// No description provided for @machineCategoryRoller.
  ///
  /// In en, this message translates to:
  /// **'Roller / Compactor'**
  String get machineCategoryRoller;

  /// No description provided for @machineCategoryScaffolding.
  ///
  /// In en, this message translates to:
  /// **'Scaffolding'**
  String get machineCategoryScaffolding;

  /// No description provided for @machineCategoryTipper.
  ///
  /// In en, this message translates to:
  /// **'Tipper / Truck'**
  String get machineCategoryTipper;

  /// No description provided for @machineCategoryTools.
  ///
  /// In en, this message translates to:
  /// **'Tools and small plant'**
  String get machineCategoryTools;

  /// No description provided for @machineCategoryTransitMixer.
  ///
  /// In en, this message translates to:
  /// **'Transit mixer'**
  String get machineCategoryTransitMixer;

  /// No description provided for @machineCategoryVehicle.
  ///
  /// In en, this message translates to:
  /// **'Vehicle'**
  String get machineCategoryVehicle;

  /// No description provided for @machineFormBreakdown.
  ///
  /// In en, this message translates to:
  /// **'Hours lost to breakdown'**
  String get machineFormBreakdown;

  /// No description provided for @machineFormChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose a machine'**
  String get machineFormChoose;

  /// No description provided for @machineFormClosing.
  ///
  /// In en, this message translates to:
  /// **'Closing reading'**
  String get machineFormClosing;

  /// No description provided for @machineFormClosingBelow.
  ///
  /// In en, this message translates to:
  /// **'The closing reading cannot be lower than the opening reading'**
  String get machineFormClosingBelow;

  /// No description provided for @machineFormDate.
  ///
  /// In en, this message translates to:
  /// **'Date'**
  String get machineFormDate;

  /// No description provided for @machineFormHours.
  ///
  /// In en, this message translates to:
  /// **'Hours worked'**
  String get machineFormHours;

  /// No description provided for @machineFormHoursError.
  ///
  /// In en, this message translates to:
  /// **'Enter hours from 0 to 24, with one decimal at most'**
  String get machineFormHoursError;

  /// No description provided for @machineFormHoursHint.
  ///
  /// In en, this message translates to:
  /// **'One decimal at most, like 7.5'**
  String get machineFormHoursHint;

  /// No description provided for @machineFormKm.
  ///
  /// In en, this message translates to:
  /// **'Km run'**
  String get machineFormKm;

  /// No description provided for @machineFormKmError.
  ///
  /// In en, this message translates to:
  /// **'Enter km from 0 to 99,999'**
  String get machineFormKmError;

  /// No description provided for @machineFormLogged.
  ///
  /// In en, this message translates to:
  /// **'Already logged that day: {hours} h. One machine cannot pass 24 h in a day.'**
  String machineFormLogged(String hours);

  /// No description provided for @machineFormMachine.
  ///
  /// In en, this message translates to:
  /// **'Machine'**
  String get machineFormMachine;

  /// No description provided for @machineFormMachineRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose the machine first'**
  String get machineFormMachineRequired;

  /// No description provided for @machineFormNoMachinesTitle.
  ///
  /// In en, this message translates to:
  /// **'No machines to log against'**
  String get machineFormNoMachinesTitle;

  /// No description provided for @machineFormNoneMatch.
  ///
  /// In en, this message translates to:
  /// **'No machine matches'**
  String get machineFormNoneMatch;

  /// No description provided for @machineFormNothing.
  ///
  /// In en, this message translates to:
  /// **'Enter the hours or km worked, or the hours lost to breakdown'**
  String get machineFormNothing;

  /// No description provided for @machineFormOpening.
  ///
  /// In en, this message translates to:
  /// **'Opening reading'**
  String get machineFormOpening;

  /// No description provided for @machineFormOperator.
  ///
  /// In en, this message translates to:
  /// **'Operator'**
  String get machineFormOperator;

  /// No description provided for @machineFormReadingError.
  ///
  /// In en, this message translates to:
  /// **'Enter a number from 0 to 99,999,999'**
  String get machineFormReadingError;

  /// No description provided for @machineFormReadingsHintHours.
  ///
  /// In en, this message translates to:
  /// **'Leave Hours worked blank and the hours are worked out from the two readings.'**
  String get machineFormReadingsHintHours;

  /// No description provided for @machineFormReadingsHintKm.
  ///
  /// In en, this message translates to:
  /// **'Leave Km run blank and the km are worked out from the two readings.'**
  String get machineFormReadingsHintKm;

  /// No description provided for @machineFormRemarks.
  ///
  /// In en, this message translates to:
  /// **'Remarks'**
  String get machineFormRemarks;

  /// No description provided for @machineFormSave.
  ///
  /// In en, this message translates to:
  /// **'Save log'**
  String get machineFormSave;

  /// No description provided for @machineFormSearch.
  ///
  /// In en, this message translates to:
  /// **'Search by code or name'**
  String get machineFormSearch;

  /// No description provided for @machinesAdd.
  ///
  /// In en, this message translates to:
  /// **'Add a log'**
  String get machinesAdd;

  /// No description provided for @machinesBreakdownValue.
  ///
  /// In en, this message translates to:
  /// **'Breakdown {hours} h'**
  String machinesBreakdownValue(String hours);

  /// No description provided for @machinesEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'A day\'s work of a machine on this project will show here.'**
  String get machinesEmptyMessage;

  /// No description provided for @machinesEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No logs yet'**
  String get machinesEmptyTitle;

  /// No description provided for @machinesHoursValue.
  ///
  /// In en, this message translates to:
  /// **'{hours} h'**
  String machinesHoursValue(String hours);

  /// No description provided for @machinesKmValue.
  ///
  /// In en, this message translates to:
  /// **'{km} km'**
  String machinesKmValue(String km);

  /// No description provided for @machinesLatestOnly.
  ///
  /// In en, this message translates to:
  /// **'Showing the latest 50. The rest are on the website.'**
  String get machinesLatestOnly;

  /// No description provided for @machinesLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading machine logs'**
  String get machinesLoading;

  /// No description provided for @machinesNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load the machines. After that the log opens offline.'**
  String get machinesNoDataMessage;

  /// No description provided for @machinesNoMachinesMessage.
  ///
  /// In en, this message translates to:
  /// **'Machines are added on the website. They show here after that.'**
  String get machinesNoMachinesMessage;

  /// No description provided for @machinesNoMachinesTitle.
  ///
  /// In en, this message translates to:
  /// **'No machines yet'**
  String get machinesNoMachinesTitle;

  /// No description provided for @machinesOperatorValue.
  ///
  /// In en, this message translates to:
  /// **'Operator {name}'**
  String machinesOperatorValue(String name);

  /// No description provided for @machinesRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'The machine log is not open to your role. Ask your office if you need it.'**
  String get machinesRefusedMessage;

  /// No description provided for @machinesRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'Not part of your role'**
  String get machinesRefusedTitle;

  /// No description provided for @machinesTitle.
  ///
  /// In en, this message translates to:
  /// **'Machine log'**
  String get machinesTitle;

  /// No description provided for @machinesUnderRepair.
  ///
  /// In en, this message translates to:
  /// **'Under repair'**
  String get machinesUnderRepair;

  /// No description provided for @machinesUnknownMachine.
  ///
  /// In en, this message translates to:
  /// **'Machine no. {id}'**
  String machinesUnknownMachine(int id);

  /// No description provided for @materialAdd.
  ///
  /// In en, this message translates to:
  /// **'Add entry'**
  String get materialAdd;

  /// No description provided for @materialBoqForWork.
  ///
  /// In en, this message translates to:
  /// **'BOQ for work done'**
  String get materialBoqForWork;

  /// No description provided for @materialChallanNo.
  ///
  /// In en, this message translates to:
  /// **'Challan no. (optional)'**
  String get materialChallanNo;

  /// No description provided for @materialChallanPhoto.
  ///
  /// In en, this message translates to:
  /// **'Photo of challan'**
  String get materialChallanPhoto;

  /// No description provided for @materialChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose material'**
  String get materialChoose;

  /// No description provided for @materialCount.
  ///
  /// In en, this message translates to:
  /// **'Count stock'**
  String get materialCount;

  /// No description provided for @materialCountHint.
  ///
  /// In en, this message translates to:
  /// **'Stock is corrected to what you counted. Any missing quantity shows as unaccounted.'**
  String get materialCountHint;

  /// No description provided for @materialDifference.
  ///
  /// In en, this message translates to:
  /// **'Difference {qty}'**
  String materialDifference(String qty);

  /// No description provided for @materialEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Entries for material received and used will show here.'**
  String get materialEmptyMessage;

  /// No description provided for @materialEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing to check yet'**
  String get materialEmptyTitle;

  /// No description provided for @materialExtraRequired.
  ///
  /// In en, this message translates to:
  /// **'Say what the extra work was'**
  String get materialExtraRequired;

  /// No description provided for @materialExtraWhat.
  ///
  /// In en, this message translates to:
  /// **'What was the extra work?'**
  String get materialExtraWhat;

  /// No description provided for @materialFlagsTitle.
  ///
  /// In en, this message translates to:
  /// **'Questions to answer'**
  String get materialFlagsTitle;

  /// No description provided for @materialInStock.
  ///
  /// In en, this message translates to:
  /// **'In stock'**
  String get materialInStock;

  /// No description provided for @materialLastCounted.
  ///
  /// In en, this message translates to:
  /// **'Last counted'**
  String get materialLastCounted;

  /// No description provided for @materialLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading material'**
  String get materialLoading;

  /// No description provided for @materialName.
  ///
  /// In en, this message translates to:
  /// **'Material'**
  String get materialName;

  /// No description provided for @materialNoCountsTitle.
  ///
  /// In en, this message translates to:
  /// **'No counts yet'**
  String get materialNoCountsTitle;

  /// No description provided for @materialNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load this project\'s material. After that it works offline.'**
  String get materialNoDataMessage;

  /// No description provided for @materialNoDeliveriesTitle.
  ///
  /// In en, this message translates to:
  /// **'No challans yet'**
  String get materialNoDeliveriesTitle;

  /// No description provided for @materialNoUseTitle.
  ///
  /// In en, this message translates to:
  /// **'No material used yet'**
  String get materialNoUseTitle;

  /// No description provided for @materialOnBoq.
  ///
  /// In en, this message translates to:
  /// **'BOQ work'**
  String get materialOnBoq;

  /// No description provided for @materialOnExtra.
  ///
  /// In en, this message translates to:
  /// **'Extra work'**
  String get materialOnExtra;

  /// No description provided for @materialOnSite.
  ///
  /// In en, this message translates to:
  /// **'Stock on site'**
  String get materialOnSite;

  /// No description provided for @materialOverStock.
  ///
  /// In en, this message translates to:
  /// **'This is more than the stock shown here. The server may refuse it.'**
  String get materialOverStock;

  /// No description provided for @materialQtyCounted.
  ///
  /// In en, this message translates to:
  /// **'Counted quantity'**
  String get materialQtyCounted;

  /// No description provided for @materialQtyIssued.
  ///
  /// In en, this message translates to:
  /// **'Quantity issued'**
  String get materialQtyIssued;

  /// No description provided for @materialQtyOnChallan.
  ///
  /// In en, this message translates to:
  /// **'Quantity written on challan (optional)'**
  String get materialQtyOnChallan;

  /// No description provided for @materialQtyOnChallanHint.
  ///
  /// In en, this message translates to:
  /// **'If less arrived than the challan says, enter the challan figure.'**
  String get materialQtyOnChallanHint;

  /// No description provided for @materialQtyReceived.
  ///
  /// In en, this message translates to:
  /// **'Quantity received'**
  String get materialQtyReceived;

  /// No description provided for @materialQtyRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter the quantity'**
  String get materialQtyRequired;

  /// No description provided for @materialQtyUsed.
  ///
  /// In en, this message translates to:
  /// **'Quantity used'**
  String get materialQtyUsed;

  /// No description provided for @materialReasonOnWeb.
  ///
  /// In en, this message translates to:
  /// **'Write the reason on the website.'**
  String get materialReasonOnWeb;

  /// No description provided for @materialReceived.
  ///
  /// In en, this message translates to:
  /// **'Material received'**
  String get materialReceived;

  /// No description provided for @materialReceivedShort.
  ///
  /// In en, this message translates to:
  /// **'Received'**
  String get materialReceivedShort;

  /// No description provided for @materialSearch.
  ///
  /// In en, this message translates to:
  /// **'Search material'**
  String get materialSearch;

  /// No description provided for @materialSearchNone.
  ///
  /// In en, this message translates to:
  /// **'Nothing matches'**
  String get materialSearchNone;

  /// No description provided for @materialShort.
  ///
  /// In en, this message translates to:
  /// **'Short delivered'**
  String get materialShort;

  /// No description provided for @materialShouldBe.
  ///
  /// In en, this message translates to:
  /// **'Should be in stock'**
  String get materialShouldBe;

  /// No description provided for @materialStage.
  ///
  /// In en, this message translates to:
  /// **'Stage (optional)'**
  String get materialStage;

  /// No description provided for @materialStageHint.
  ///
  /// In en, this message translates to:
  /// **'Such as slab 3'**
  String get materialStageHint;

  /// No description provided for @materialStore.
  ///
  /// In en, this message translates to:
  /// **'Store'**
  String get materialStore;

  /// No description provided for @materialSupplier.
  ///
  /// In en, this message translates to:
  /// **'Supplier'**
  String get materialSupplier;

  /// No description provided for @materialSupplierChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose supplier'**
  String get materialSupplierChoose;

  /// No description provided for @materialSupplierFromList.
  ///
  /// In en, this message translates to:
  /// **'Choose from list'**
  String get materialSupplierFromList;

  /// No description provided for @materialSupplierName.
  ///
  /// In en, this message translates to:
  /// **'Supplier name'**
  String get materialSupplierName;

  /// No description provided for @materialSupplierNew.
  ///
  /// In en, this message translates to:
  /// **'New supplier'**
  String get materialSupplierNew;

  /// No description provided for @materialSupplierRequired.
  ///
  /// In en, this message translates to:
  /// **'Say who supplied it'**
  String get materialSupplierRequired;

  /// No description provided for @materialSupplierSearch.
  ///
  /// In en, this message translates to:
  /// **'Search supplier'**
  String get materialSupplierSearch;

  /// No description provided for @materialTabCounts.
  ///
  /// In en, this message translates to:
  /// **'Counts'**
  String get materialTabCounts;

  /// No description provided for @materialTabDeliveries.
  ///
  /// In en, this message translates to:
  /// **'Challans'**
  String get materialTabDeliveries;

  /// No description provided for @materialTabStock.
  ///
  /// In en, this message translates to:
  /// **'Stock'**
  String get materialTabStock;

  /// No description provided for @materialTabUsed.
  ///
  /// In en, this message translates to:
  /// **'Used'**
  String get materialTabUsed;

  /// No description provided for @materialTitle.
  ///
  /// In en, this message translates to:
  /// **'Material'**
  String get materialTitle;

  /// No description provided for @materialToAnswer.
  ///
  /// In en, this message translates to:
  /// **'{count} to answer'**
  String materialToAnswer(int count);

  /// No description provided for @materialUnaccounted.
  ///
  /// In en, this message translates to:
  /// **'Unaccounted'**
  String get materialUnaccounted;

  /// No description provided for @materialUsed.
  ///
  /// In en, this message translates to:
  /// **'Material used'**
  String get materialUsed;

  /// No description provided for @materialUsedShort.
  ///
  /// In en, this message translates to:
  /// **'Used'**
  String get materialUsedShort;

  /// No description provided for @materialVehicleNo.
  ///
  /// In en, this message translates to:
  /// **'Vehicle no. (optional)'**
  String get materialVehicleNo;

  /// No description provided for @measurementAdd.
  ///
  /// In en, this message translates to:
  /// **'Add'**
  String get measurementAdd;

  /// No description provided for @measurementAddEntry.
  ///
  /// In en, this message translates to:
  /// **'Add measurement'**
  String get measurementAddEntry;

  /// No description provided for @measurementAllLines.
  ///
  /// In en, this message translates to:
  /// **'All lines'**
  String get measurementAllLines;

  /// No description provided for @measurementApprovedLockedBody.
  ///
  /// In en, this message translates to:
  /// **'Corrections are made on the web as a reversal entry.'**
  String get measurementApprovedLockedBody;

  /// No description provided for @measurementApprovedLockedTitle.
  ///
  /// In en, this message translates to:
  /// **'Approved and locked'**
  String get measurementApprovedLockedTitle;

  /// No description provided for @measurementApproverSaid.
  ///
  /// In en, this message translates to:
  /// **'Approver\'s remark'**
  String get measurementApproverSaid;

  /// No description provided for @measurementAttentionBody.
  ///
  /// In en, this message translates to:
  /// **'The server refused this entry. It is safe on your phone.'**
  String get measurementAttentionBody;

  /// No description provided for @measurementAttentionTitle.
  ///
  /// In en, this message translates to:
  /// **'Could not send'**
  String get measurementAttentionTitle;

  /// No description provided for @measurementBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance'**
  String get measurementBalance;

  /// No description provided for @measurementBalanceAfter.
  ///
  /// In en, this message translates to:
  /// **'Balance after this'**
  String get measurementBalanceAfter;

  /// No description provided for @measurementBookTitle.
  ///
  /// In en, this message translates to:
  /// **'Measurement book'**
  String get measurementBookTitle;

  /// No description provided for @measurementBoqProgress.
  ///
  /// In en, this message translates to:
  /// **'BOQ used'**
  String get measurementBoqProgress;

  /// No description provided for @measurementBoqQty.
  ///
  /// In en, this message translates to:
  /// **'BOQ'**
  String get measurementBoqQty;

  /// No description provided for @measurementBoqUsed.
  ///
  /// In en, this message translates to:
  /// **'{percent} of BOQ used'**
  String measurementBoqUsed(String percent);

  /// No description provided for @measurementBreadth.
  ///
  /// In en, this message translates to:
  /// **'Breadth'**
  String get measurementBreadth;

  /// No description provided for @measurementBreadthShort.
  ///
  /// In en, this message translates to:
  /// **'× B'**
  String get measurementBreadthShort;

  /// No description provided for @measurementChange.
  ///
  /// In en, this message translates to:
  /// **'Change'**
  String get measurementChange;

  /// No description provided for @measurementChangeLine.
  ///
  /// In en, this message translates to:
  /// **'Change BOQ line'**
  String get measurementChangeLine;

  /// No description provided for @measurementChooseLine.
  ///
  /// In en, this message translates to:
  /// **'Choose BOQ line'**
  String get measurementChooseLine;

  /// No description provided for @measurementClose.
  ///
  /// In en, this message translates to:
  /// **'Close'**
  String get measurementClose;

  /// No description provided for @measurementDateLabel.
  ///
  /// In en, this message translates to:
  /// **'Measured on'**
  String get measurementDateLabel;

  /// No description provided for @measurementDeduct.
  ///
  /// In en, this message translates to:
  /// **'Deduct'**
  String get measurementDeduct;

  /// No description provided for @measurementDelete.
  ///
  /// In en, this message translates to:
  /// **'Delete'**
  String get measurementDelete;

  /// No description provided for @measurementDepth.
  ///
  /// In en, this message translates to:
  /// **'Depth'**
  String get measurementDepth;

  /// No description provided for @measurementDepthShort.
  ///
  /// In en, this message translates to:
  /// **'× D'**
  String get measurementDepthShort;

  /// No description provided for @measurementDescriptionHint.
  ///
  /// In en, this message translates to:
  /// **'What was measured'**
  String get measurementDescriptionHint;

  /// No description provided for @measurementDescriptionLabel.
  ///
  /// In en, this message translates to:
  /// **'Description'**
  String get measurementDescriptionLabel;

  /// No description provided for @measurementDetailTitle.
  ///
  /// In en, this message translates to:
  /// **'Measurement'**
  String get measurementDetailTitle;

  /// No description provided for @measurementDetailsShort.
  ///
  /// In en, this message translates to:
  /// **'Location, date'**
  String get measurementDetailsShort;

  /// No description provided for @measurementDetailsTitle.
  ///
  /// In en, this message translates to:
  /// **'Location, note and date'**
  String get measurementDetailsTitle;

  /// No description provided for @measurementDeviation.
  ///
  /// In en, this message translates to:
  /// **'Deviation'**
  String get measurementDeviation;

  /// No description provided for @measurementDeviationBody.
  ///
  /// In en, this message translates to:
  /// **'Over BOQ + {percent}%. It will need PM approval.'**
  String measurementDeviationBody(String percent);

  /// No description provided for @measurementDeviationDetail.
  ///
  /// In en, this message translates to:
  /// **'This entry goes over the BOQ quantity plus tolerance, so the project manager must approve it.'**
  String get measurementDeviationDetail;

  /// No description provided for @measurementDraftsLeft.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 row kept as a draft. Send it from the book.} other{{count} rows kept as drafts. Send them from the book.}}'**
  String measurementDraftsLeft(int count);

  /// No description provided for @measurementEdit.
  ///
  /// In en, this message translates to:
  /// **'Edit'**
  String get measurementEdit;

  /// No description provided for @measurementEmptyBody.
  ///
  /// In en, this message translates to:
  /// **'Your first entry starts this book.'**
  String get measurementEmptyBody;

  /// No description provided for @measurementEmptyFilteredBody.
  ///
  /// In en, this message translates to:
  /// **'No entries have this status.'**
  String get measurementEmptyFilteredBody;

  /// No description provided for @measurementEmptyFilteredTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing here'**
  String get measurementEmptyFilteredTitle;

  /// No description provided for @measurementEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No measurements yet'**
  String get measurementEmptyTitle;

  /// No description provided for @measurementFilterAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get measurementFilterAll;

  /// No description provided for @measurementFixLast.
  ///
  /// In en, this message translates to:
  /// **'Fix last'**
  String get measurementFixLast;

  /// No description provided for @measurementFormulaHint.
  ///
  /// In en, this message translates to:
  /// **'Enter Nos to begin'**
  String get measurementFormulaHint;

  /// No description provided for @measurementGoneBody.
  ///
  /// In en, this message translates to:
  /// **'It may have been deleted on the web.'**
  String get measurementGoneBody;

  /// No description provided for @measurementGoneTitle.
  ///
  /// In en, this message translates to:
  /// **'Entry not found'**
  String get measurementGoneTitle;

  /// No description provided for @measurementInReviewBody.
  ///
  /// In en, this message translates to:
  /// **'With the approver. It cannot be changed until they decide.'**
  String get measurementInReviewBody;

  /// No description provided for @measurementItem.
  ///
  /// In en, this message translates to:
  /// **'Item'**
  String get measurementItem;

  /// No description provided for @measurementLeaveBody.
  ///
  /// In en, this message translates to:
  /// **'The figures typed for this row will be lost.'**
  String get measurementLeaveBody;

  /// No description provided for @measurementLeaveGo.
  ///
  /// In en, this message translates to:
  /// **'Leave'**
  String get measurementLeaveGo;

  /// No description provided for @measurementLeaveKeep.
  ///
  /// In en, this message translates to:
  /// **'Keep entering'**
  String get measurementLeaveKeep;

  /// No description provided for @measurementLeaveTitle.
  ///
  /// In en, this message translates to:
  /// **'Leave without saving?'**
  String get measurementLeaveTitle;

  /// No description provided for @measurementLength.
  ///
  /// In en, this message translates to:
  /// **'Length'**
  String get measurementLength;

  /// No description provided for @measurementLengthShort.
  ///
  /// In en, this message translates to:
  /// **'× L'**
  String get measurementLengthShort;

  /// No description provided for @measurementLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading'**
  String get measurementLoading;

  /// No description provided for @measurementLocationHint.
  ///
  /// In en, this message translates to:
  /// **'Grid, floor, footing'**
  String get measurementLocationHint;

  /// No description provided for @measurementLocationLabel.
  ///
  /// In en, this message translates to:
  /// **'Location'**
  String get measurementLocationLabel;

  /// No description provided for @measurementLockedBody.
  ///
  /// In en, this message translates to:
  /// **'It has been approved or is under review.'**
  String get measurementLockedBody;

  /// No description provided for @measurementLockedTitle.
  ///
  /// In en, this message translates to:
  /// **'This entry cannot be changed'**
  String get measurementLockedTitle;

  /// No description provided for @measurementNet.
  ///
  /// In en, this message translates to:
  /// **'net'**
  String get measurementNet;

  /// No description provided for @measurementNext.
  ///
  /// In en, this message translates to:
  /// **'Next'**
  String get measurementNext;

  /// No description provided for @measurementNoMatchTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing matches'**
  String get measurementNoMatchTitle;

  /// No description provided for @measurementNos.
  ///
  /// In en, this message translates to:
  /// **'Nos'**
  String get measurementNos;

  /// No description provided for @measurementOffline.
  ///
  /// In en, this message translates to:
  /// **'No network.'**
  String get measurementOffline;

  /// No description provided for @measurementOfflineDetail.
  ///
  /// In en, this message translates to:
  /// **'Entries stay on this phone and go when network returns.'**
  String get measurementOfflineDetail;

  /// No description provided for @measurementOtherDate.
  ///
  /// In en, this message translates to:
  /// **'Other date'**
  String get measurementOtherDate;

  /// No description provided for @measurementPhotoAdd.
  ///
  /// In en, this message translates to:
  /// **'Add photo'**
  String get measurementPhotoAdd;

  /// No description provided for @measurementPhotoFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not add the photo. Try again.'**
  String get measurementPhotoFailed;

  /// No description provided for @measurementPhotoSaved.
  ///
  /// In en, this message translates to:
  /// **'Photo saved. Sending now.'**
  String get measurementPhotoSaved;

  /// No description provided for @measurementPhotoSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Photo saved on phone. Sends when network returns.'**
  String get measurementPhotoSavedOffline;

  /// No description provided for @measurementPhotosLocked.
  ///
  /// In en, this message translates to:
  /// **'Photos cannot be added once the entry is with the approver.'**
  String get measurementPhotosLocked;

  /// No description provided for @measurementPhotosNone.
  ///
  /// In en, this message translates to:
  /// **'No photos yet'**
  String get measurementPhotosNone;

  /// No description provided for @measurementPhotosTitle.
  ///
  /// In en, this message translates to:
  /// **'Photos'**
  String get measurementPhotosTitle;

  /// No description provided for @measurementPreviewNote.
  ///
  /// In en, this message translates to:
  /// **'Figures on this phone are a preview. The office\'s figures are final.'**
  String get measurementPreviewNote;

  /// No description provided for @measurementPreviewTag.
  ///
  /// In en, this message translates to:
  /// **'preview'**
  String get measurementPreviewTag;

  /// No description provided for @measurementProblemFuture.
  ///
  /// In en, this message translates to:
  /// **'The date cannot be in the future.'**
  String get measurementProblemFuture;

  /// No description provided for @measurementProblemLocked.
  ///
  /// In en, this message translates to:
  /// **'This entry has already been sent for approval.'**
  String get measurementProblemLocked;

  /// No description provided for @measurementProblemNoLine.
  ///
  /// In en, this message translates to:
  /// **'Choose the BOQ line first.'**
  String get measurementProblemNoLine;

  /// No description provided for @measurementProblemNos.
  ///
  /// In en, this message translates to:
  /// **'Enter Nos. It is required.'**
  String get measurementProblemNos;

  /// No description provided for @measurementProblemPositive.
  ///
  /// In en, this message translates to:
  /// **'This figure must be more than zero.'**
  String get measurementProblemPositive;

  /// No description provided for @measurementProblemTooLarge.
  ///
  /// In en, this message translates to:
  /// **'This figure is too large.'**
  String get measurementProblemTooLarge;

  /// No description provided for @measurementProblemUnknown.
  ///
  /// In en, this message translates to:
  /// **'Could not save. Please try again.'**
  String get measurementProblemUnknown;

  /// No description provided for @measurementQuantity.
  ///
  /// In en, this message translates to:
  /// **'Quantity'**
  String get measurementQuantity;

  /// No description provided for @measurementRecentLines.
  ///
  /// In en, this message translates to:
  /// **'Recent'**
  String get measurementRecentLines;

  /// No description provided for @measurementRejectedBody.
  ///
  /// In en, this message translates to:
  /// **'This entry was rejected and will not count. Make a new entry if the work needs measuring again.'**
  String get measurementRejectedBody;

  /// No description provided for @measurementSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone. It will go when network returns.'**
  String get measurementSavedOffline;

  /// No description provided for @measurementSavedOnPhone.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone.'**
  String get measurementSavedOnPhone;

  /// No description provided for @measurementSearchHint.
  ///
  /// In en, this message translates to:
  /// **'e.g. 2.1 or slab'**
  String get measurementSearchHint;

  /// No description provided for @measurementSearchLines.
  ///
  /// In en, this message translates to:
  /// **'Search code or description'**
  String get measurementSearchLines;

  /// No description provided for @measurementSendAgain.
  ///
  /// In en, this message translates to:
  /// **'Send again'**
  String get measurementSendAgain;

  /// No description provided for @measurementSendCount.
  ///
  /// In en, this message translates to:
  /// **'Send {count} for approval'**
  String measurementSendCount(int count);

  /// No description provided for @measurementSendForApproval.
  ///
  /// In en, this message translates to:
  /// **'Send for approval'**
  String get measurementSendForApproval;

  /// No description provided for @measurementSendsWhenOnline.
  ///
  /// In en, this message translates to:
  /// **'Not sent yet. It will go for approval when network returns.'**
  String get measurementSendsWhenOnline;

  /// No description provided for @measurementShareOfBoq.
  ///
  /// In en, this message translates to:
  /// **'Share of BOQ'**
  String get measurementShareOfBoq;

  /// No description provided for @measurementStampSent.
  ///
  /// In en, this message translates to:
  /// **'Sent'**
  String get measurementStampSent;

  /// No description provided for @measurementSubmitWaitingTitle.
  ///
  /// In en, this message translates to:
  /// **'Waiting to go for approval'**
  String get measurementSubmitWaitingTitle;

  /// No description provided for @measurementSyncAttention.
  ///
  /// In en, this message translates to:
  /// **'Needs a look'**
  String get measurementSyncAttention;

  /// No description provided for @measurementSyncSending.
  ///
  /// In en, this message translates to:
  /// **'Sending'**
  String get measurementSyncSending;

  /// No description provided for @measurementTally.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 row saved on phone} other{{count} rows saved on phone}}'**
  String measurementTally(int count);

  /// No description provided for @measurementYesterday.
  ///
  /// In en, this message translates to:
  /// **'Yesterday'**
  String get measurementYesterday;

  /// No description provided for @offlineActivateAgain.
  ///
  /// In en, this message translates to:
  /// **'Activate this phone'**
  String get offlineActivateAgain;

  /// No description provided for @offlineCopyLink.
  ///
  /// In en, this message translates to:
  /// **'Copy link'**
  String get offlineCopyLink;

  /// No description provided for @offlineDeviceLimitBody.
  ///
  /// In en, this message translates to:
  /// **'Your plan allows a set number of phones and they are all in use. Free one on the website, then try again.'**
  String get offlineDeviceLimitBody;

  /// No description provided for @offlineDeviceLimitTitle.
  ///
  /// In en, this message translates to:
  /// **'Another phone holds your licence'**
  String get offlineDeviceLimitTitle;

  /// No description provided for @offlineExportButton.
  ///
  /// In en, this message translates to:
  /// **'Export my data'**
  String get offlineExportButton;

  /// No description provided for @offlineExportDone.
  ///
  /// In en, this message translates to:
  /// **'Copy made with {count} photos. Keep the file somewhere safe.'**
  String offlineExportDone(int count);

  /// No description provided for @offlineExportFailed.
  ///
  /// In en, this message translates to:
  /// **'The copy could not be made. Your data here is safe. Try again.'**
  String get offlineExportFailed;

  /// No description provided for @offlineExportHeading.
  ///
  /// In en, this message translates to:
  /// **'Keep a copy'**
  String get offlineExportHeading;

  /// No description provided for @offlineExportHint.
  ///
  /// In en, this message translates to:
  /// **'Makes one file with all your data and photos. Save it to Drive, email it, or send it to another phone.'**
  String get offlineExportHint;

  /// No description provided for @offlineExportMissing.
  ///
  /// In en, this message translates to:
  /// **'{count} photos could not be found on this phone.'**
  String offlineExportMissing(int count);

  /// No description provided for @offlineFreeBody.
  ///
  /// In en, this message translates to:
  /// **'Your data does not move by itself. Export a copy first. This phone keeps its data and works until its licence ends.'**
  String get offlineFreeBody;

  /// No description provided for @offlineFreeConfirm.
  ///
  /// In en, this message translates to:
  /// **'Free it'**
  String get offlineFreeConfirm;

  /// No description provided for @offlineFreeFailed.
  ///
  /// In en, this message translates to:
  /// **'That did not work. Check your internet and try again.'**
  String get offlineFreeFailed;

  /// No description provided for @offlineFreeThisPhone.
  ///
  /// In en, this message translates to:
  /// **'Move to a new phone'**
  String get offlineFreeThisPhone;

  /// No description provided for @offlineFreeTitle.
  ///
  /// In en, this message translates to:
  /// **'Free this phone\'s licence?'**
  String get offlineFreeTitle;

  /// No description provided for @offlineHereBoq.
  ///
  /// In en, this message translates to:
  /// **'BOQ lines'**
  String get offlineHereBoq;

  /// No description provided for @offlineHereEntries.
  ///
  /// In en, this message translates to:
  /// **'Entries and photos'**
  String get offlineHereEntries;

  /// No description provided for @offlineHereProjects.
  ///
  /// In en, this message translates to:
  /// **'Projects made here'**
  String get offlineHereProjects;

  /// No description provided for @offlineHereWallets.
  ///
  /// In en, this message translates to:
  /// **'Wallets'**
  String get offlineHereWallets;

  /// No description provided for @offlineHereWorkers.
  ///
  /// In en, this message translates to:
  /// **'Workers'**
  String get offlineHereWorkers;

  /// No description provided for @offlineHomeEnded.
  ///
  /// In en, this message translates to:
  /// **'Your licence has ended. See how to renew.'**
  String get offlineHomeEnded;

  /// No description provided for @offlineHomeHint.
  ///
  /// In en, this message translates to:
  /// **'Your data is only here. Keep a copy.'**
  String get offlineHomeHint;

  /// No description provided for @offlineInvalidTitle.
  ///
  /// In en, this message translates to:
  /// **'The licence could not be checked'**
  String get offlineInvalidTitle;

  /// No description provided for @offlineLicenceActive.
  ///
  /// In en, this message translates to:
  /// **'Good until {date}'**
  String offlineLicenceActive(String date);

  /// No description provided for @offlineLicenceEndedBody.
  ///
  /// In en, this message translates to:
  /// **'Nothing is lost. You can still look at everything here and make a copy of your data, but nothing can be added or changed until the licence renews. Connect to the internet to renew it.'**
  String get offlineLicenceEndedBody;

  /// No description provided for @offlineLicenceEndedTitle.
  ///
  /// In en, this message translates to:
  /// **'Your licence has ended'**
  String get offlineLicenceEndedTitle;

  /// No description provided for @offlineLicenceEndingBody.
  ///
  /// In en, this message translates to:
  /// **'Ends on {date}. Connect to the internet once and it renews by itself.'**
  String offlineLicenceEndingBody(String date);

  /// No description provided for @offlineLicenceHeading.
  ///
  /// In en, this message translates to:
  /// **'Licence'**
  String get offlineLicenceHeading;

  /// No description provided for @offlineLicenceNone.
  ///
  /// In en, this message translates to:
  /// **'Not ready yet. Connect to the internet once.'**
  String get offlineLicenceNone;

  /// No description provided for @offlineLinkCopied.
  ///
  /// In en, this message translates to:
  /// **'Link copied'**
  String get offlineLinkCopied;

  /// No description provided for @offlineManageLink.
  ///
  /// In en, this message translates to:
  /// **'Free a phone here'**
  String get offlineManageLink;

  /// No description provided for @offlineNeedsInternet.
  ///
  /// In en, this message translates to:
  /// **'This needs the internet. Your data here is not affected.'**
  String get offlineNeedsInternet;

  /// No description provided for @offlineNeedsSignInBody.
  ///
  /// In en, this message translates to:
  /// **'Nothing is lost. Your work continues here. The licence can only renew after you sign in.'**
  String get offlineNeedsSignInBody;

  /// No description provided for @offlineNeedsSignInTitle.
  ///
  /// In en, this message translates to:
  /// **'Sign in again to renew'**
  String get offlineNeedsSignInTitle;

  /// No description provided for @offlineNoticeEnded.
  ///
  /// In en, this message translates to:
  /// **'Your licence ended on {date}. You can look at everything, but nothing can be added or changed. Connect to the internet to renew.'**
  String offlineNoticeEnded(String date);

  /// No description provided for @offlineNoticeEndedNoDate.
  ///
  /// In en, this message translates to:
  /// **'Your licence has ended. You can look at everything, but nothing can be added or changed. Connect to the internet to renew.'**
  String get offlineNoticeEndedNoDate;

  /// No description provided for @offlineNoticeEnding.
  ///
  /// In en, this message translates to:
  /// **'Your licence ends on {date}. Connect to the internet once before then and it renews by itself.'**
  String offlineNoticeEnding(String date);

  /// No description provided for @offlineNoticeNoLicence.
  ///
  /// In en, this message translates to:
  /// **'This phone has no licence yet. You can look at everything, but nothing can be added or changed. Connect to the internet to get one.'**
  String get offlineNoticeNoLicence;

  /// No description provided for @offlineNoticeUpload.
  ///
  /// In en, this message translates to:
  /// **'Your licence has ended, and your plan now keeps data on the server. You can look at everything, but nothing can be added or changed. Upload your data to carry on.'**
  String get offlineNoticeUpload;

  /// No description provided for @offlineOnPhoneTag.
  ///
  /// In en, this message translates to:
  /// **'On this phone'**
  String get offlineOnPhoneTag;

  /// No description provided for @offlineOnPhoneTitle.
  ///
  /// In en, this message translates to:
  /// **'Only on this phone'**
  String get offlineOnPhoneTitle;

  /// No description provided for @offlineOnlyHereBody.
  ///
  /// In en, this message translates to:
  /// **'Nothing is kept on our server. If this phone is lost or reset, the data is gone. Export a copy and keep it safe.'**
  String get offlineOnlyHereBody;

  /// No description provided for @offlineOnlyHereTitle.
  ///
  /// In en, this message translates to:
  /// **'Your data is only on this phone'**
  String get offlineOnlyHereTitle;

  /// No description provided for @offlinePlanTitle.
  ///
  /// In en, this message translates to:
  /// **'Offline plan'**
  String get offlinePlanTitle;

  /// No description provided for @offlineReviewTitle.
  ///
  /// In en, this message translates to:
  /// **'Check and save'**
  String get offlineReviewTitle;

  /// No description provided for @offlineRevokedBody.
  ///
  /// In en, this message translates to:
  /// **'Your data is safe here and the app keeps working until the licence ends. To use this phone again, free a slot on the website, then activate it.'**
  String get offlineRevokedBody;

  /// No description provided for @offlineRevokedTitle.
  ///
  /// In en, this message translates to:
  /// **'This phone was freed'**
  String get offlineRevokedTitle;

  /// No description provided for @offlineSaveReport.
  ///
  /// In en, this message translates to:
  /// **'Save report'**
  String get offlineSaveReport;

  /// No description provided for @offlineSavedOnPhone.
  ///
  /// In en, this message translates to:
  /// **'Saved on this phone'**
  String get offlineSavedOnPhone;

  /// No description provided for @offlineShareSubject.
  ///
  /// In en, this message translates to:
  /// **'Nirmaan360 data backup'**
  String get offlineShareSubject;

  /// No description provided for @offlineSignInButton.
  ///
  /// In en, this message translates to:
  /// **'Sign in'**
  String get offlineSignInButton;

  /// No description provided for @offlineSignOutBody.
  ///
  /// In en, this message translates to:
  /// **'Your data stays on this phone. You will need internet to sign in again.'**
  String get offlineSignOutBody;

  /// No description provided for @offlineSignOutTitle.
  ///
  /// In en, this message translates to:
  /// **'Sign out?'**
  String get offlineSignOutTitle;

  /// No description provided for @offlineUploadAskBody.
  ///
  /// In en, this message translates to:
  /// **'Your company\'s plan has changed, so the server can keep your data now. Nothing goes up unless you say yes.'**
  String get offlineUploadAskBody;

  /// No description provided for @offlineUploadAskTitle.
  ///
  /// In en, this message translates to:
  /// **'Upload your data to the server?'**
  String get offlineUploadAskTitle;

  /// No description provided for @offlineUploadBusy.
  ///
  /// In en, this message translates to:
  /// **'The server is busy. Try again in a minute.'**
  String get offlineUploadBusy;

  /// No description provided for @offlineUploadContinue.
  ///
  /// In en, this message translates to:
  /// **'Continue upload'**
  String get offlineUploadContinue;

  /// No description provided for @offlineUploadDone.
  ///
  /// In en, this message translates to:
  /// **'Your data is moving to the server. Anything that needs a look will show in Outbox.'**
  String get offlineUploadDone;

  /// No description provided for @offlineUploadInterruptedBody.
  ///
  /// In en, this message translates to:
  /// **'Nothing is lost and nothing will go twice. Continue when you are ready, or keep working on this phone for now.'**
  String get offlineUploadInterruptedBody;

  /// No description provided for @offlineUploadInterruptedTitle.
  ///
  /// In en, this message translates to:
  /// **'The upload stopped part way'**
  String get offlineUploadInterruptedTitle;

  /// No description provided for @offlineUploadLaterHint.
  ///
  /// In en, this message translates to:
  /// **'Your plan can keep data on the server now, if you want it there.'**
  String get offlineUploadLaterHint;

  /// No description provided for @offlineUploadLaterTile.
  ///
  /// In en, this message translates to:
  /// **'Upload to the server'**
  String get offlineUploadLaterTile;

  /// No description provided for @offlineUploadNo.
  ///
  /// In en, this message translates to:
  /// **'No, keep it on this phone'**
  String get offlineUploadNo;

  /// No description provided for @offlineUploadNoNetwork.
  ///
  /// In en, this message translates to:
  /// **'No internet right now. Continue when you have a connection.'**
  String get offlineUploadNoNetwork;

  /// No description provided for @offlineUploadPlanLimit.
  ///
  /// In en, this message translates to:
  /// **'Your plan does not allow this yet: {reason}'**
  String offlineUploadPlanLimit(String reason);

  /// No description provided for @offlineUploadProgress.
  ///
  /// In en, this message translates to:
  /// **'{done} of {total} moved'**
  String offlineUploadProgress(int done, int total);

  /// No description provided for @offlineUploadRefused.
  ///
  /// In en, this message translates to:
  /// **'The server did not accept this: {reason}'**
  String offlineUploadRefused(String reason);

  /// No description provided for @offlineUploadRunningBody.
  ///
  /// In en, this message translates to:
  /// **'Keep the app open. If it stops, nothing is lost and nothing goes twice.'**
  String get offlineUploadRunningBody;

  /// No description provided for @offlineUploadRunningTitle.
  ///
  /// In en, this message translates to:
  /// **'Uploading your data'**
  String get offlineUploadRunningTitle;

  /// No description provided for @offlineUploadSignIn.
  ///
  /// In en, this message translates to:
  /// **'Please sign in again, then continue. Nothing is lost.'**
  String get offlineUploadSignIn;

  /// No description provided for @offlineUploadStillOffline.
  ///
  /// In en, this message translates to:
  /// **'Your company is on the offline plan again, so nothing went up.'**
  String get offlineUploadStillOffline;

  /// No description provided for @offlineUploadTitle.
  ///
  /// In en, this message translates to:
  /// **'Your data'**
  String get offlineUploadTitle;

  /// No description provided for @offlineUploadYes.
  ///
  /// In en, this message translates to:
  /// **'Yes, upload my data'**
  String get offlineUploadYes;

  /// No description provided for @pettyCashAddExpense.
  ///
  /// In en, this message translates to:
  /// **'Add expense'**
  String get pettyCashAddExpense;

  /// No description provided for @pettyCashAddMoney.
  ///
  /// In en, this message translates to:
  /// **'Add money'**
  String get pettyCashAddMoney;

  /// No description provided for @pettyCashAmount.
  ///
  /// In en, this message translates to:
  /// **'Amount'**
  String get pettyCashAmount;

  /// No description provided for @pettyCashAmountRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter an amount above zero.'**
  String get pettyCashAmountRequired;

  /// No description provided for @pettyCashBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance'**
  String get pettyCashBalance;

  /// No description provided for @pettyCashBalanceAfter.
  ///
  /// In en, this message translates to:
  /// **'Balance after this: {amount}'**
  String pettyCashBalanceAfter(String amount);

  /// No description provided for @pettyCashBillAdded.
  ///
  /// In en, this message translates to:
  /// **'Bill photo added'**
  String get pettyCashBillAdded;

  /// No description provided for @pettyCashBillFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not open the camera. Try again.'**
  String get pettyCashBillFailed;

  /// No description provided for @pettyCashBillLabel.
  ///
  /// In en, this message translates to:
  /// **'Bill'**
  String get pettyCashBillLabel;

  /// No description provided for @pettyCashBillRetake.
  ///
  /// In en, this message translates to:
  /// **'Take again'**
  String get pettyCashBillRetake;

  /// No description provided for @pettyCashCategory.
  ///
  /// In en, this message translates to:
  /// **'What was it for?'**
  String get pettyCashCategory;

  /// No description provided for @pettyCashCategoryRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose what it was for.'**
  String get pettyCashCategoryRequired;

  /// No description provided for @pettyCashChooseProject.
  ///
  /// In en, this message translates to:
  /// **'Choose a project'**
  String get pettyCashChooseProject;

  /// No description provided for @pettyCashConfirmed.
  ///
  /// In en, this message translates to:
  /// **'Confirmed'**
  String get pettyCashConfirmed;

  /// No description provided for @pettyCashCreateWallet.
  ///
  /// In en, this message translates to:
  /// **'Create wallet'**
  String get pettyCashCreateWallet;

  /// No description provided for @pettyCashDate.
  ///
  /// In en, this message translates to:
  /// **'Date'**
  String get pettyCashDate;

  /// No description provided for @pettyCashDayToday.
  ///
  /// In en, this message translates to:
  /// **'Today'**
  String get pettyCashDayToday;

  /// No description provided for @pettyCashDayYesterday.
  ///
  /// In en, this message translates to:
  /// **'Yesterday'**
  String get pettyCashDayYesterday;

  /// No description provided for @pettyCashEntries.
  ///
  /// In en, this message translates to:
  /// **'Entries'**
  String get pettyCashEntries;

  /// No description provided for @pettyCashEntriesEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Expenses you add will show here.'**
  String get pettyCashEntriesEmptyMessage;

  /// No description provided for @pettyCashEntriesEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No entries yet'**
  String get pettyCashEntriesEmptyTitle;

  /// No description provided for @pettyCashEyebrow.
  ///
  /// In en, this message translates to:
  /// **'Cash book'**
  String get pettyCashEyebrow;

  /// No description provided for @pettyCashFinalAfterSync.
  ///
  /// In en, this message translates to:
  /// **'The final balance comes after sync.'**
  String get pettyCashFinalAfterSync;

  /// No description provided for @pettyCashFormEyebrow.
  ///
  /// In en, this message translates to:
  /// **'New expense'**
  String get pettyCashFormEyebrow;

  /// No description provided for @pettyCashFormTitle.
  ///
  /// In en, this message translates to:
  /// **'Add an expense'**
  String get pettyCashFormTitle;

  /// No description provided for @pettyCashLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading the cash book'**
  String get pettyCashLoading;

  /// No description provided for @pettyCashNoProjectMessage.
  ///
  /// In en, this message translates to:
  /// **'Choose a project first, then record expenses here.'**
  String get pettyCashNoProjectMessage;

  /// No description provided for @pettyCashNoProjectTitle.
  ///
  /// In en, this message translates to:
  /// **'No project chosen'**
  String get pettyCashNoProjectTitle;

  /// No description provided for @pettyCashNoWalletManageMessage.
  ///
  /// In en, this message translates to:
  /// **'Create one to record site expenses here.'**
  String get pettyCashNoWalletManageMessage;

  /// No description provided for @pettyCashNoWalletManageTitle.
  ///
  /// In en, this message translates to:
  /// **'No wallet on this project yet'**
  String get pettyCashNoWalletManageTitle;

  /// No description provided for @pettyCashNoWalletMessage.
  ///
  /// In en, this message translates to:
  /// **'Ask your project manager or the accounts office to open one for you.'**
  String get pettyCashNoWalletMessage;

  /// No description provided for @pettyCashNoWalletTitle.
  ///
  /// In en, this message translates to:
  /// **'You have no wallet on this project'**
  String get pettyCashNoWalletTitle;

  /// No description provided for @pettyCashNote.
  ///
  /// In en, this message translates to:
  /// **'Note (optional)'**
  String get pettyCashNote;

  /// No description provided for @pettyCashNoteHint.
  ///
  /// In en, this message translates to:
  /// **'Diesel for the mixer'**
  String get pettyCashNoteHint;

  /// No description provided for @pettyCashOpeningAmount.
  ///
  /// In en, this message translates to:
  /// **'Opening amount'**
  String get pettyCashOpeningAmount;

  /// No description provided for @pettyCashOpeningHelper.
  ///
  /// In en, this message translates to:
  /// **'Leave blank if there is no money yet.'**
  String get pettyCashOpeningHelper;

  /// No description provided for @pettyCashOpeningNote.
  ///
  /// In en, this message translates to:
  /// **'Opening balance'**
  String get pettyCashOpeningNote;

  /// No description provided for @pettyCashOverBalance.
  ///
  /// In en, this message translates to:
  /// **'More than the wallet balance of {balance}. Ask the office for a top-up.'**
  String pettyCashOverBalance(String balance);

  /// No description provided for @pettyCashPhotoBill.
  ///
  /// In en, this message translates to:
  /// **'Photo of bill'**
  String get pettyCashPhotoBill;

  /// No description provided for @pettyCashProvisional.
  ///
  /// In en, this message translates to:
  /// **'Provisional'**
  String get pettyCashProvisional;

  /// No description provided for @pettyCashSave.
  ///
  /// In en, this message translates to:
  /// **'Save expense'**
  String get pettyCashSave;

  /// No description provided for @pettyCashSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the expense. Try again.'**
  String get pettyCashSaveFailed;

  /// No description provided for @pettyCashSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone. It will send when network returns.'**
  String get pettyCashSavedOffline;

  /// No description provided for @pettyCashSavedOnline.
  ///
  /// In en, this message translates to:
  /// **'Saved on phone. Sending now.'**
  String get pettyCashSavedOnline;

  /// No description provided for @pettyCashSyncAttention.
  ///
  /// In en, this message translates to:
  /// **'Needs attention'**
  String get pettyCashSyncAttention;

  /// No description provided for @pettyCashSyncSending.
  ///
  /// In en, this message translates to:
  /// **'Sending'**
  String get pettyCashSyncSending;

  /// No description provided for @pettyCashTapToChange.
  ///
  /// In en, this message translates to:
  /// **'Tap to change'**
  String get pettyCashTapToChange;

  /// No description provided for @pettyCashTitle.
  ///
  /// In en, this message translates to:
  /// **'Petty cash'**
  String get pettyCashTitle;

  /// No description provided for @pettyCashTopUpEyebrow.
  ///
  /// In en, this message translates to:
  /// **'Money into the wallet'**
  String get pettyCashTopUpEyebrow;

  /// No description provided for @pettyCashTopUpFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the money. Try again.'**
  String get pettyCashTopUpFailed;

  /// No description provided for @pettyCashTopUpNoteHint.
  ///
  /// In en, this message translates to:
  /// **'Cash from office'**
  String get pettyCashTopUpNoteHint;

  /// No description provided for @pettyCashTopUpSave.
  ///
  /// In en, this message translates to:
  /// **'Save money received'**
  String get pettyCashTopUpSave;

  /// No description provided for @pettyCashTopup.
  ///
  /// In en, this message translates to:
  /// **'Top-up'**
  String get pettyCashTopup;

  /// No description provided for @pettyCashUnsent.
  ///
  /// In en, this message translates to:
  /// **'Not sent yet'**
  String get pettyCashUnsent;

  /// No description provided for @pettyCashWallet.
  ///
  /// In en, this message translates to:
  /// **'Wallet'**
  String get pettyCashWallet;

  /// No description provided for @pettyCashWalletCreateKey.
  ///
  /// In en, this message translates to:
  /// **'Create'**
  String get pettyCashWalletCreateKey;

  /// No description provided for @pettyCashWalletCreated.
  ///
  /// In en, this message translates to:
  /// **'Wallet created'**
  String get pettyCashWalletCreated;

  /// No description provided for @pettyCashWalletCreatedMoney.
  ///
  /// In en, this message translates to:
  /// **'Wallet created. {amount} saved on phone, sending now.'**
  String pettyCashWalletCreatedMoney(String amount);

  /// No description provided for @pettyCashWalletCreatedOffline.
  ///
  /// In en, this message translates to:
  /// **'Wallet created. {amount} saved on phone, sends when network returns.'**
  String pettyCashWalletCreatedOffline(String amount);

  /// No description provided for @pettyCashWalletDefaultName.
  ///
  /// In en, this message translates to:
  /// **'{code} site cash'**
  String pettyCashWalletDefaultName(String code);

  /// No description provided for @pettyCashWalletFailed.
  ///
  /// In en, this message translates to:
  /// **'The wallet could not be created. Try again.'**
  String get pettyCashWalletFailed;

  /// No description provided for @pettyCashWalletFormEyebrow.
  ///
  /// In en, this message translates to:
  /// **'New wallet'**
  String get pettyCashWalletFormEyebrow;

  /// No description provided for @pettyCashWalletHolder.
  ///
  /// In en, this message translates to:
  /// **'Who holds the cash?'**
  String get pettyCashWalletHolder;

  /// No description provided for @pettyCashWalletMoneyFailed.
  ///
  /// In en, this message translates to:
  /// **'Wallet created, but the money was not saved. Add it again.'**
  String get pettyCashWalletMoneyFailed;

  /// No description provided for @pettyCashWalletName.
  ///
  /// In en, this message translates to:
  /// **'Wallet name'**
  String get pettyCashWalletName;

  /// No description provided for @pettyCashWalletNameRequired.
  ///
  /// In en, this message translates to:
  /// **'Write a name for the wallet.'**
  String get pettyCashWalletNameRequired;

  /// No description provided for @pettyCashWalletOffline.
  ///
  /// In en, this message translates to:
  /// **'No network.'**
  String get pettyCashWalletOffline;

  /// No description provided for @pettyCashWalletOfflineDetail.
  ///
  /// In en, this message translates to:
  /// **'A wallet can be created only online.'**
  String get pettyCashWalletOfflineDetail;

  /// No description provided for @photosClose.
  ///
  /// In en, this message translates to:
  /// **'Close'**
  String get photosClose;

  /// No description provided for @photosDocument.
  ///
  /// In en, this message translates to:
  /// **'PDF, on the website'**
  String get photosDocument;

  /// No description provided for @photosNeedNetwork.
  ///
  /// In en, this message translates to:
  /// **'Needs network'**
  String get photosNeedNetwork;

  /// No description provided for @photosNotSent.
  ///
  /// In en, this message translates to:
  /// **'Not sent'**
  String get photosNotSent;

  /// No description provided for @photosOpen.
  ///
  /// In en, this message translates to:
  /// **'Open photo'**
  String get photosOpen;

  /// No description provided for @photosWaiting.
  ///
  /// In en, this message translates to:
  /// **'Waiting to send'**
  String get photosWaiting;

  /// No description provided for @portalBillDeductions.
  ///
  /// In en, this message translates to:
  /// **'Deductions'**
  String get portalBillDeductions;

  /// No description provided for @portalBillFinal.
  ///
  /// In en, this message translates to:
  /// **'Final bill'**
  String get portalBillFinal;

  /// No description provided for @portalBillGross.
  ///
  /// In en, this message translates to:
  /// **'Gross amount'**
  String get portalBillGross;

  /// No description provided for @portalBillGst.
  ///
  /// In en, this message translates to:
  /// **'GST'**
  String get portalBillGst;

  /// No description provided for @portalBillLineQty.
  ///
  /// In en, this message translates to:
  /// **'{qty} {unit} at {rate}'**
  String portalBillLineQty(String qty, String unit, String rate);

  /// No description provided for @portalBillLineSoFar.
  ///
  /// In en, this message translates to:
  /// **'So far {qty} {unit}'**
  String portalBillLineSoFar(String qty, String unit);

  /// No description provided for @portalBillLines.
  ///
  /// In en, this message translates to:
  /// **'Work billed'**
  String get portalBillLines;

  /// No description provided for @portalBillLinesOnline.
  ///
  /// In en, this message translates to:
  /// **'The lines of this bill load once it has been opened online.'**
  String get portalBillLinesOnline;

  /// No description provided for @portalBillNet.
  ///
  /// In en, this message translates to:
  /// **'Net payable'**
  String get portalBillNet;

  /// No description provided for @portalBillPeriod.
  ///
  /// In en, this message translates to:
  /// **'Period'**
  String get portalBillPeriod;

  /// No description provided for @portalBillPeriodRange.
  ///
  /// In en, this message translates to:
  /// **'{from} to {to}'**
  String portalBillPeriodRange(String from, String to);

  /// No description provided for @portalBillRetentionReleased.
  ///
  /// In en, this message translates to:
  /// **'Retention released'**
  String get portalBillRetentionReleased;

  /// No description provided for @portalBillsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 bill} other{{count} bills}}'**
  String portalBillsCount(int count);

  /// No description provided for @portalBillsTitle.
  ///
  /// In en, this message translates to:
  /// **'Bills'**
  String get portalBillsTitle;

  /// No description provided for @portalDaysLeft.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 day left} other{{count} days left}}'**
  String portalDaysLeft(int count);

  /// No description provided for @portalDeadlineLabel.
  ///
  /// In en, this message translates to:
  /// **'Deadline'**
  String get portalDeadlineLabel;

  /// No description provided for @portalDueToday.
  ///
  /// In en, this message translates to:
  /// **'Due today'**
  String get portalDueToday;

  /// No description provided for @portalLineDone.
  ///
  /// In en, this message translates to:
  /// **'{done} of {total} {unit} done'**
  String portalLineDone(String done, String total, String unit);

  /// No description provided for @portalLineRate.
  ///
  /// In en, this message translates to:
  /// **'{rate} per {unit}'**
  String portalLineRate(String rate, String unit);

  /// No description provided for @portalLineWorth.
  ///
  /// In en, this message translates to:
  /// **'Worth {done} of {total}'**
  String portalLineWorth(String done, String total);

  /// No description provided for @portalLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading your contracts'**
  String get portalLoading;

  /// No description provided for @portalNoBillsMessage.
  ///
  /// In en, this message translates to:
  /// **'Bills the company has approved will show here.'**
  String get portalNoBillsMessage;

  /// No description provided for @portalNoBillsTitle.
  ///
  /// In en, this message translates to:
  /// **'No bills yet'**
  String get portalNoBillsTitle;

  /// No description provided for @portalNoContractsMessage.
  ///
  /// In en, this message translates to:
  /// **'Work orders given to you will show here.'**
  String get portalNoContractsMessage;

  /// No description provided for @portalNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load your contracts. After that they open offline.'**
  String get portalNoDataMessage;

  /// No description provided for @portalNoPaymentsMessage.
  ///
  /// In en, this message translates to:
  /// **'Payments the company has approved will show here.'**
  String get portalNoPaymentsMessage;

  /// No description provided for @portalNoPaymentsTitle.
  ///
  /// In en, this message translates to:
  /// **'No payments yet'**
  String get portalNoPaymentsTitle;

  /// No description provided for @portalNotFoundMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet to load it.'**
  String get portalNotFoundMessage;

  /// No description provided for @portalNotFoundTitle.
  ///
  /// In en, this message translates to:
  /// **'Not on this phone yet'**
  String get portalNotFoundTitle;

  /// No description provided for @portalOverdueBy.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 day late} other{{count} days late}}'**
  String portalOverdueBy(int count);

  /// No description provided for @portalPaidVsWork.
  ///
  /// In en, this message translates to:
  /// **'Paid {paid} · Work done {work}'**
  String portalPaidVsWork(String paid, String work);

  /// No description provided for @portalPaymentsCount.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 payment} other{{count} payments}}'**
  String portalPaymentsCount(int count);

  /// No description provided for @portalPaymentsTitle.
  ///
  /// In en, this message translates to:
  /// **'Payments'**
  String get portalPaymentsTitle;

  /// No description provided for @portalRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'This login cannot open the contractor portal right now. Ask the company that gave you this login.'**
  String get portalRefusedMessage;

  /// No description provided for @portalRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'Portal not open'**
  String get portalRefusedTitle;

  /// No description provided for @portalSavedAgo.
  ///
  /// In en, this message translates to:
  /// **'Updated {ago}'**
  String portalSavedAgo(String ago);

  /// No description provided for @portalTitle.
  ///
  /// In en, this message translates to:
  /// **'Contractor portal'**
  String get portalTitle;

  /// No description provided for @portalWorkComplete.
  ///
  /// In en, this message translates to:
  /// **'Work complete'**
  String get portalWorkComplete;

  /// No description provided for @portalYourContracts.
  ///
  /// In en, this message translates to:
  /// **'All your contracts'**
  String get portalYourContracts;

  /// No description provided for @priceWatchAbove.
  ///
  /// In en, this message translates to:
  /// **'{diff} above the BOQ rate ({pct})'**
  String priceWatchAbove(String diff, String pct);

  /// No description provided for @priceWatchBasisNorms.
  ///
  /// In en, this message translates to:
  /// **'from the BOQ norms'**
  String get priceWatchBasisNorms;

  /// No description provided for @priceWatchBasisTyped.
  ///
  /// In en, this message translates to:
  /// **'quantity typed in for this project'**
  String get priceWatchBasisTyped;

  /// No description provided for @priceWatchBelow.
  ///
  /// In en, this message translates to:
  /// **'{diff} below the BOQ rate ({pct})'**
  String priceWatchBelow(String diff, String pct);

  /// No description provided for @priceWatchBeyond.
  ///
  /// In en, this message translates to:
  /// **'Beyond the margin'**
  String get priceWatchBeyond;

  /// No description provided for @priceWatchBoqRate.
  ///
  /// In en, this message translates to:
  /// **'BOQ rate'**
  String get priceWatchBoqRate;

  /// No description provided for @priceWatchBoqValue.
  ///
  /// In en, this message translates to:
  /// **'BOQ value'**
  String get priceWatchBoqValue;

  /// No description provided for @priceWatchBudget.
  ///
  /// In en, this message translates to:
  /// **'Budget'**
  String get priceWatchBudget;

  /// No description provided for @priceWatchBudgetNoData.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load the budget effect for this project.'**
  String get priceWatchBudgetNoData;

  /// No description provided for @priceWatchBudgetNote.
  ///
  /// In en, this message translates to:
  /// **'If the rest of each material is bought at its latest recorded price instead of the BOQ rate.'**
  String get priceWatchBudgetNote;

  /// No description provided for @priceWatchBudgetRefused.
  ///
  /// In en, this message translates to:
  /// **'The budget effect is not open to your role.'**
  String get priceWatchBudgetRefused;

  /// No description provided for @priceWatchBudgetTitle.
  ///
  /// In en, this message translates to:
  /// **'Effect on the budget'**
  String get priceWatchBudgetTitle;

  /// No description provided for @priceWatchChecked.
  ///
  /// In en, this message translates to:
  /// **'Checked {ago}'**
  String priceWatchChecked(String ago);

  /// No description provided for @priceWatchDown.
  ///
  /// In en, this message translates to:
  /// **'Down {pct} from {rate} on {date}'**
  String priceWatchDown(String pct, String rate, String date);

  /// No description provided for @priceWatchEmptyMessage.
  ///
  /// In en, this message translates to:
  /// **'Materials your company watches will show here with their recorded prices.'**
  String get priceWatchEmptyMessage;

  /// No description provided for @priceWatchEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No materials on the board'**
  String get priceWatchEmptyTitle;

  /// No description provided for @priceWatchFresh.
  ///
  /// In en, this message translates to:
  /// **'From prices not out of date'**
  String get priceWatchFresh;

  /// No description provided for @priceWatchGapNoBoqRate.
  ///
  /// In en, this message translates to:
  /// **'No BOQ rate set for this project'**
  String get priceWatchGapNoBoqRate;

  /// No description provided for @priceWatchGapNoQty.
  ///
  /// In en, this message translates to:
  /// **'No quantity to work it out from'**
  String get priceWatchGapNoQty;

  /// No description provided for @priceWatchGapUnit.
  ///
  /// In en, this message translates to:
  /// **'The price unit does not match the BOQ unit'**
  String get priceWatchGapUnit;

  /// No description provided for @priceWatchHistory.
  ///
  /// In en, this message translates to:
  /// **'Every recorded price'**
  String get priceWatchHistory;

  /// No description provided for @priceWatchLatest.
  ///
  /// In en, this message translates to:
  /// **'Latest'**
  String get priceWatchLatest;

  /// No description provided for @priceWatchLess.
  ///
  /// In en, this message translates to:
  /// **'{amount} less'**
  String priceWatchLess(String amount);

  /// No description provided for @priceWatchLevel.
  ///
  /// In en, this message translates to:
  /// **'Same as the BOQ rate'**
  String get priceWatchLevel;

  /// No description provided for @priceWatchLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading recorded prices'**
  String get priceWatchLoading;

  /// No description provided for @priceWatchMaterialMissing.
  ///
  /// In en, this message translates to:
  /// **'This material is not on the board.'**
  String get priceWatchMaterialMissing;

  /// No description provided for @priceWatchMore.
  ///
  /// In en, this message translates to:
  /// **'{amount} more'**
  String priceWatchMore(String amount);

  /// No description provided for @priceWatchNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load the recorded prices. After that they open offline.'**
  String get priceWatchNoDataMessage;

  /// No description provided for @priceWatchNoEffect.
  ///
  /// In en, this message translates to:
  /// **'No effect'**
  String get priceWatchNoEffect;

  /// No description provided for @priceWatchNone.
  ///
  /// In en, this message translates to:
  /// **'No price recorded yet'**
  String get priceWatchNone;

  /// No description provided for @priceWatchNote.
  ///
  /// In en, this message translates to:
  /// **'Prices your company wrote down, each with the date it was recorded. Nothing here updates by itself.'**
  String get priceWatchNote;

  /// No description provided for @priceWatchNotedBy.
  ///
  /// In en, this message translates to:
  /// **'Noted by {name}'**
  String priceWatchNotedBy(String name);

  /// No description provided for @priceWatchOnlyOne.
  ///
  /// In en, this message translates to:
  /// **'Only one price recorded'**
  String get priceWatchOnlyOne;

  /// No description provided for @priceWatchPerUnit.
  ///
  /// In en, this message translates to:
  /// **'per {unit}'**
  String priceWatchPerUnit(String unit);

  /// No description provided for @priceWatchProjected.
  ///
  /// In en, this message translates to:
  /// **'BOQ at these prices'**
  String get priceWatchProjected;

  /// No description provided for @priceWatchRecordedOn.
  ///
  /// In en, this message translates to:
  /// **'Recorded {date}'**
  String priceWatchRecordedOn(String date);

  /// No description provided for @priceWatchRecordedRate.
  ///
  /// In en, this message translates to:
  /// **'Latest recorded'**
  String get priceWatchRecordedRate;

  /// No description provided for @priceWatchRefusedMessage.
  ///
  /// In en, this message translates to:
  /// **'Recorded prices are not open to your role. Ask your office if you need them.'**
  String get priceWatchRefusedMessage;

  /// No description provided for @priceWatchRefusedTitle.
  ///
  /// In en, this message translates to:
  /// **'Not open to your role'**
  String get priceWatchRefusedTitle;

  /// No description provided for @priceWatchSame.
  ///
  /// In en, this message translates to:
  /// **'Same as {rate} on {date}'**
  String priceWatchSame(String rate, String date);

  /// No description provided for @priceWatchShareBoq.
  ///
  /// In en, this message translates to:
  /// **'Share of the BOQ value'**
  String get priceWatchShareBoq;

  /// No description provided for @priceWatchShareBudget.
  ///
  /// In en, this message translates to:
  /// **'Share of the budget'**
  String get priceWatchShareBudget;

  /// No description provided for @priceWatchStale.
  ///
  /// In en, this message translates to:
  /// **'{days, plural, =1{Older than 1 day} other{Older than {days} days}}'**
  String priceWatchStale(int days);

  /// No description provided for @priceWatchStillToBuy.
  ///
  /// In en, this message translates to:
  /// **'Still to buy {qty} {unit}'**
  String priceWatchStillToBuy(String qty, String unit);

  /// No description provided for @priceWatchTabBudget.
  ///
  /// In en, this message translates to:
  /// **'Effect on budget'**
  String get priceWatchTabBudget;

  /// No description provided for @priceWatchTabPrices.
  ///
  /// In en, this message translates to:
  /// **'Prices'**
  String get priceWatchTabPrices;

  /// No description provided for @priceWatchTile.
  ///
  /// In en, this message translates to:
  /// **'Prices'**
  String get priceWatchTile;

  /// No description provided for @priceWatchTitle.
  ///
  /// In en, this message translates to:
  /// **'Recorded prices'**
  String get priceWatchTitle;

  /// No description provided for @priceWatchUp.
  ///
  /// In en, this message translates to:
  /// **'Up {pct} from {rate} on {date}'**
  String priceWatchUp(String pct, String rate, String date);

  /// No description provided for @priceWatchWithin.
  ///
  /// In en, this message translates to:
  /// **'Within the margin'**
  String get priceWatchWithin;

  /// No description provided for @procurementAccepted.
  ///
  /// In en, this message translates to:
  /// **'Received and accepted'**
  String get procurementAccepted;

  /// No description provided for @procurementActivity.
  ///
  /// In en, this message translates to:
  /// **'Or the activity'**
  String get procurementActivity;

  /// No description provided for @procurementActivityHint.
  ///
  /// In en, this message translates to:
  /// **'Such as slab 3 shuttering'**
  String get procurementActivityHint;

  /// No description provided for @procurementAddItem.
  ///
  /// In en, this message translates to:
  /// **'Add another item'**
  String get procurementAddItem;

  /// No description provided for @procurementForBoq.
  ///
  /// In en, this message translates to:
  /// **'BOQ item'**
  String get procurementForBoq;

  /// No description provided for @procurementInStore.
  ///
  /// In en, this message translates to:
  /// **'In this store {qty}'**
  String procurementInStore(String qty);

  /// No description provided for @procurementIssueStock.
  ///
  /// In en, this message translates to:
  /// **'Issue from store'**
  String get procurementIssueStock;

  /// No description provided for @procurementIssuedTo.
  ///
  /// In en, this message translates to:
  /// **'Issued to (optional)'**
  String get procurementIssuedTo;

  /// No description provided for @procurementIssuedToHint.
  ///
  /// In en, this message translates to:
  /// **'A person or a gang'**
  String get procurementIssuedToHint;

  /// No description provided for @procurementItemN.
  ///
  /// In en, this message translates to:
  /// **'Item {number}'**
  String procurementItemN(int number);

  /// No description provided for @procurementNoOrdersMessage.
  ///
  /// In en, this message translates to:
  /// **'An order shows here once the office has sent it to the supplier and goods can be received against it.'**
  String get procurementNoOrdersMessage;

  /// No description provided for @procurementNoOrdersTitle.
  ///
  /// In en, this message translates to:
  /// **'No open purchase orders'**
  String get procurementNoOrdersTitle;

  /// No description provided for @procurementNoReceiptsTitle.
  ///
  /// In en, this message translates to:
  /// **'No receipts yet'**
  String get procurementNoReceiptsTitle;

  /// No description provided for @procurementNoStoreMessage.
  ///
  /// In en, this message translates to:
  /// **'Ask your office to add a store on the website, then try again.'**
  String get procurementNoStoreMessage;

  /// No description provided for @procurementNoStoreTitle.
  ///
  /// In en, this message translates to:
  /// **'No store on this project'**
  String get procurementNoStoreTitle;

  /// No description provided for @procurementNothingReceived.
  ///
  /// In en, this message translates to:
  /// **'Enter what was accepted on at least one line'**
  String get procurementNothingReceived;

  /// No description provided for @procurementOpenOrders.
  ///
  /// In en, this message translates to:
  /// **'Open orders'**
  String get procurementOpenOrders;

  /// No description provided for @procurementOrder.
  ///
  /// In en, this message translates to:
  /// **'Purchase order'**
  String get procurementOrder;

  /// No description provided for @procurementOrderChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose purchase order'**
  String get procurementOrderChoose;

  /// No description provided for @procurementOrderDue.
  ///
  /// In en, this message translates to:
  /// **'{count} items still due'**
  String procurementOrderDue(int count);

  /// No description provided for @procurementOrderRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose the purchase order'**
  String get procurementOrderRequired;

  /// No description provided for @procurementOrderSearch.
  ///
  /// In en, this message translates to:
  /// **'Search by number or supplier'**
  String get procurementOrderSearch;

  /// No description provided for @procurementOrdered.
  ///
  /// In en, this message translates to:
  /// **'Ordered {qty}'**
  String procurementOrdered(String qty);

  /// No description provided for @procurementOverDue.
  ///
  /// In en, this message translates to:
  /// **'Only {qty} is still due on this order'**
  String procurementOverDue(String qty);

  /// No description provided for @procurementPartialNote.
  ///
  /// In en, this message translates to:
  /// **'Part of an order is fine. Leave a line empty if nothing came on it, and the order stays open for the rest.'**
  String get procurementPartialNote;

  /// No description provided for @procurementQtyInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter a number'**
  String get procurementQtyInvalid;

  /// No description provided for @procurementReceiveOrder.
  ///
  /// In en, this message translates to:
  /// **'Receive against order'**
  String get procurementReceiveOrder;

  /// No description provided for @procurementReceivedHeading.
  ///
  /// In en, this message translates to:
  /// **'Received'**
  String get procurementReceivedHeading;

  /// No description provided for @procurementRejected.
  ///
  /// In en, this message translates to:
  /// **'Turned away (optional)'**
  String get procurementRejected;

  /// No description provided for @procurementRejectedFigure.
  ///
  /// In en, this message translates to:
  /// **'Turned away {qty}'**
  String procurementRejectedFigure(String qty);

  /// No description provided for @procurementRejectedHint.
  ///
  /// In en, this message translates to:
  /// **'Goods you did not accept. They are not added to stock and are still due from the supplier.'**
  String get procurementRejectedHint;

  /// No description provided for @procurementRejectedOnly.
  ///
  /// In en, this message translates to:
  /// **'Turned-away goods need some accepted quantity on the same line. For a delivery that was all turned away, use the website.'**
  String get procurementRejectedOnly;

  /// No description provided for @procurementRemarks.
  ///
  /// In en, this message translates to:
  /// **'Note (optional)'**
  String get procurementRemarks;

  /// No description provided for @procurementRemarksHint.
  ///
  /// In en, this message translates to:
  /// **'Such as why goods were turned away'**
  String get procurementRemarksHint;

  /// No description provided for @procurementRemoveItem.
  ///
  /// In en, this message translates to:
  /// **'Remove'**
  String get procurementRemoveItem;

  /// No description provided for @procurementReversed.
  ///
  /// In en, this message translates to:
  /// **'Reversed'**
  String get procurementReversed;

  /// No description provided for @procurementStillDue.
  ///
  /// In en, this message translates to:
  /// **'Still due {qty}'**
  String procurementStillDue(String qty);

  /// No description provided for @procurementTabReceipts.
  ///
  /// In en, this message translates to:
  /// **'Order receipts'**
  String get procurementTabReceipts;

  /// No description provided for @procurementTooManyDecimals.
  ///
  /// In en, this message translates to:
  /// **'Use at most 3 decimal places'**
  String get procurementTooManyDecimals;

  /// No description provided for @procurementWorkRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose a BOQ item or write the activity'**
  String get procurementWorkRequired;

  /// No description provided for @progressAdd.
  ///
  /// In en, this message translates to:
  /// **'Add today\'s work'**
  String get progressAdd;

  /// No description provided for @progressBeforeFinishing.
  ///
  /// In en, this message translates to:
  /// **'Before finishing check'**
  String get progressBeforeFinishing;

  /// No description provided for @progressBehind.
  ///
  /// In en, this message translates to:
  /// **'Behind by {percent}'**
  String progressBehind(String percent);

  /// No description provided for @progressCorrect.
  ///
  /// In en, this message translates to:
  /// **'Correct'**
  String get progressCorrect;

  /// No description provided for @progressCorrectFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save the correction. Please try again.'**
  String get progressCorrectFailed;

  /// No description provided for @progressCorrectHint.
  ///
  /// In en, this message translates to:
  /// **'The first entry stays. A correction is added beside it.'**
  String get progressCorrectHint;

  /// No description provided for @progressCorrectNow.
  ///
  /// In en, this message translates to:
  /// **'Counted now: {qty}'**
  String progressCorrectNow(String qty);

  /// No description provided for @progressCorrectQty.
  ///
  /// In en, this message translates to:
  /// **'What it should have been'**
  String get progressCorrectQty;

  /// No description provided for @progressCorrectQtyInvalid.
  ///
  /// In en, this message translates to:
  /// **'Enter a quantity from zero up'**
  String get progressCorrectQtyInvalid;

  /// No description provided for @progressCorrectRemark.
  ///
  /// In en, this message translates to:
  /// **'Why is it being corrected?'**
  String get progressCorrectRemark;

  /// No description provided for @progressCorrectRemarkRequired.
  ///
  /// In en, this message translates to:
  /// **'Say why it is being corrected'**
  String get progressCorrectRemarkRequired;

  /// No description provided for @progressCorrectSame.
  ///
  /// In en, this message translates to:
  /// **'That is already the quantity'**
  String get progressCorrectSame;

  /// No description provided for @progressCorrectSave.
  ///
  /// In en, this message translates to:
  /// **'Save correction'**
  String get progressCorrectSave;

  /// No description provided for @progressCorrectSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Correction saved on this phone. It will send when you are online.'**
  String get progressCorrectSavedOffline;

  /// No description provided for @progressCorrectSavedOnline.
  ///
  /// In en, this message translates to:
  /// **'Correction saved. Sending now.'**
  String get progressCorrectSavedOnline;

  /// No description provided for @progressCorrectTakenBack.
  ///
  /// In en, this message translates to:
  /// **'Correction taken back.'**
  String get progressCorrectTakenBack;

  /// No description provided for @progressCorrectTitle.
  ///
  /// In en, this message translates to:
  /// **'Correct this entry'**
  String get progressCorrectTitle;

  /// No description provided for @progressCorrectionNotSent.
  ///
  /// In en, this message translates to:
  /// **'Correction not sent yet'**
  String get progressCorrectionNotSent;

  /// No description provided for @progressEntryBy.
  ///
  /// In en, this message translates to:
  /// **'by {name}'**
  String progressEntryBy(String name);

  /// No description provided for @progressEntryCorrectedFrom.
  ///
  /// In en, this message translates to:
  /// **'Corrected from {qty}'**
  String progressEntryCorrectedFrom(String qty);

  /// No description provided for @progressFinishingHint.
  ///
  /// In en, this message translates to:
  /// **'Read this before plaster, POP or paint starts.'**
  String get progressFinishingHint;

  /// No description provided for @progressFinishingPending.
  ///
  /// In en, this message translates to:
  /// **'Pending work'**
  String get progressFinishingPending;

  /// No description provided for @progressFinishingUnfinished.
  ///
  /// In en, this message translates to:
  /// **'Not finished yet'**
  String get progressFinishingUnfinished;

  /// No description provided for @progressFloorBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance'**
  String get progressFloorBalance;

  /// No description provided for @progressFloorDone.
  ///
  /// In en, this message translates to:
  /// **'Done'**
  String get progressFloorDone;

  /// No description provided for @progressFloorFlats.
  ///
  /// In en, this message translates to:
  /// **'Flats'**
  String get progressFloorFlats;

  /// No description provided for @progressFloorLines.
  ///
  /// In en, this message translates to:
  /// **'Work on this floor'**
  String get progressFloorLines;

  /// No description provided for @progressFloorNoLines.
  ///
  /// In en, this message translates to:
  /// **'No quantities planned for this floor yet.'**
  String get progressFloorNoLines;

  /// No description provided for @progressFloorOffline.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to see this floor.'**
  String get progressFloorOffline;

  /// No description provided for @progressFloorPendingHeading.
  ///
  /// In en, this message translates to:
  /// **'Pending here'**
  String get progressFloorPendingHeading;

  /// No description provided for @progressFloorPlanned.
  ///
  /// In en, this message translates to:
  /// **'Planned'**
  String get progressFloorPlanned;

  /// No description provided for @progressFloorStages.
  ///
  /// In en, this message translates to:
  /// **'Plumbing and electrical stages'**
  String get progressFloorStages;

  /// No description provided for @progressFloorTitle.
  ///
  /// In en, this message translates to:
  /// **'Floor progress'**
  String get progressFloorTitle;

  /// No description provided for @progressFormBalance.
  ///
  /// In en, this message translates to:
  /// **'Balance {qty}'**
  String progressFormBalance(String qty);

  /// No description provided for @progressFormDate.
  ///
  /// In en, this message translates to:
  /// **'Date'**
  String get progressFormDate;

  /// No description provided for @progressFormFloor.
  ///
  /// In en, this message translates to:
  /// **'Floor or flat'**
  String get progressFormFloor;

  /// No description provided for @progressFormFloorNone.
  ///
  /// In en, this message translates to:
  /// **'Whole project'**
  String get progressFormFloorNone;

  /// No description provided for @progressFormItem.
  ///
  /// In en, this message translates to:
  /// **'BOQ item'**
  String get progressFormItem;

  /// No description provided for @progressFormItemChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose an item'**
  String get progressFormItemChoose;

  /// No description provided for @progressFormItemNone.
  ///
  /// In en, this message translates to:
  /// **'No item matches'**
  String get progressFormItemNone;

  /// No description provided for @progressFormItemSearch.
  ///
  /// In en, this message translates to:
  /// **'Search by code or name'**
  String get progressFormItemSearch;

  /// No description provided for @progressFormKindPending.
  ///
  /// In en, this message translates to:
  /// **'Only pending'**
  String get progressFormKindPending;

  /// No description provided for @progressFormKindWork.
  ///
  /// In en, this message translates to:
  /// **'Work done'**
  String get progressFormKindWork;

  /// No description provided for @progressFormOverBalance.
  ///
  /// In en, this message translates to:
  /// **'This is more than the BOQ balance. You can still save it.'**
  String get progressFormOverBalance;

  /// No description provided for @progressFormPendingRemark.
  ///
  /// In en, this message translates to:
  /// **'What is pending?'**
  String get progressFormPendingRemark;

  /// No description provided for @progressFormPhoto.
  ///
  /// In en, this message translates to:
  /// **'Add photo'**
  String get progressFormPhoto;

  /// No description provided for @progressFormQty.
  ///
  /// In en, this message translates to:
  /// **'Quantity done'**
  String get progressFormQty;

  /// No description provided for @progressFormQtyRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter the quantity done'**
  String get progressFormQtyRequired;

  /// No description provided for @progressFormRemark.
  ///
  /// In en, this message translates to:
  /// **'Remark (optional)'**
  String get progressFormRemark;

  /// No description provided for @progressFormRemarkRequired.
  ///
  /// In en, this message translates to:
  /// **'Say what is pending'**
  String get progressFormRemarkRequired;

  /// No description provided for @progressFormRemovePhoto.
  ///
  /// In en, this message translates to:
  /// **'Remove photo'**
  String get progressFormRemovePhoto;

  /// No description provided for @progressFormSave.
  ///
  /// In en, this message translates to:
  /// **'Save work'**
  String get progressFormSave;

  /// No description provided for @progressFormTitle.
  ///
  /// In en, this message translates to:
  /// **'Add work'**
  String get progressFormTitle;

  /// No description provided for @progressFormToday.
  ///
  /// In en, this message translates to:
  /// **'Today'**
  String get progressFormToday;

  /// No description provided for @progressFormWhose.
  ///
  /// In en, this message translates to:
  /// **'Whose work is this?'**
  String get progressFormWhose;

  /// No description provided for @progressFormWhoseRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose the contractor'**
  String get progressFormWhoseRequired;

  /// No description provided for @progressFormYesterday.
  ///
  /// In en, this message translates to:
  /// **'Yesterday'**
  String get progressFormYesterday;

  /// No description provided for @progressLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading progress'**
  String get progressLoading;

  /// No description provided for @progressNoDataMessage.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load this project\'s progress. After that it works offline.'**
  String get progressNoDataMessage;

  /// No description provided for @progressNoDataTitle.
  ///
  /// In en, this message translates to:
  /// **'Not loaded yet'**
  String get progressNoDataTitle;

  /// No description provided for @progressNoFloorsMessage.
  ///
  /// In en, this message translates to:
  /// **'Ask your office to add floors and flats on the website.'**
  String get progressNoFloorsMessage;

  /// No description provided for @progressNoFloorsTitle.
  ///
  /// In en, this message translates to:
  /// **'No floors added yet'**
  String get progressNoFloorsTitle;

  /// No description provided for @progressNoPendingMessage.
  ///
  /// In en, this message translates to:
  /// **'Items marked pending on site will show here.'**
  String get progressNoPendingMessage;

  /// No description provided for @progressNoPendingTitle.
  ///
  /// In en, this message translates to:
  /// **'Nothing pending'**
  String get progressNoPendingTitle;

  /// No description provided for @progressNoProjectMessage.
  ///
  /// In en, this message translates to:
  /// **'Choose a project first.'**
  String get progressNoProjectMessage;

  /// No description provided for @progressNoProjectTitle.
  ///
  /// In en, this message translates to:
  /// **'No project selected'**
  String get progressNoProjectTitle;

  /// No description provided for @progressNotSent.
  ///
  /// In en, this message translates to:
  /// **'Not sent yet'**
  String get progressNotSent;

  /// No description provided for @progressOnTrack.
  ///
  /// In en, this message translates to:
  /// **'On track'**
  String get progressOnTrack;

  /// No description provided for @progressPendingCount.
  ///
  /// In en, this message translates to:
  /// **'{count} pending'**
  String progressPendingCount(int count);

  /// No description provided for @progressReadyMessage.
  ///
  /// In en, this message translates to:
  /// **'All plumbing and electrical stages are done here.'**
  String get progressReadyMessage;

  /// No description provided for @progressReadyTitle.
  ///
  /// In en, this message translates to:
  /// **'Ready for finishing'**
  String get progressReadyTitle;

  /// No description provided for @progressRecentEntries.
  ///
  /// In en, this message translates to:
  /// **'Recent entries on this floor'**
  String get progressRecentEntries;

  /// No description provided for @progressRefreshFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not refresh. Showing what this phone has.'**
  String get progressRefreshFailed;

  /// No description provided for @progressResolveRemark.
  ///
  /// In en, this message translates to:
  /// **'Remark (optional)'**
  String get progressResolveRemark;

  /// No description provided for @progressResolveSave.
  ///
  /// In en, this message translates to:
  /// **'Resolve'**
  String get progressResolveSave;

  /// No description provided for @progressResolveTitle.
  ///
  /// In en, this message translates to:
  /// **'Mark as resolved'**
  String get progressResolveTitle;

  /// No description provided for @progressResolveWait.
  ///
  /// In en, this message translates to:
  /// **'This entry has not reached the office yet. Try again after it is sent.'**
  String get progressResolveWait;

  /// No description provided for @progressResolved.
  ///
  /// In en, this message translates to:
  /// **'Marked as resolved'**
  String get progressResolved;

  /// No description provided for @progressSaveFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save. Please try again.'**
  String get progressSaveFailed;

  /// No description provided for @progressSavedOffline.
  ///
  /// In en, this message translates to:
  /// **'Saved on this phone. It will send when you are online.'**
  String get progressSavedOffline;

  /// No description provided for @progressSavedOnline.
  ///
  /// In en, this message translates to:
  /// **'Saved. Sending now.'**
  String get progressSavedOnline;

  /// No description provided for @progressStageEarlierPending.
  ///
  /// In en, this message translates to:
  /// **'An earlier stage is still pending'**
  String get progressStageEarlierPending;

  /// No description provided for @progressStageNote.
  ///
  /// In en, this message translates to:
  /// **'Note (optional)'**
  String get progressStageNote;

  /// No description provided for @progressStageSave.
  ///
  /// In en, this message translates to:
  /// **'Save'**
  String get progressStageSave;

  /// No description provided for @progressStageSaved.
  ///
  /// In en, this message translates to:
  /// **'Stage saved'**
  String get progressStageSaved;

  /// No description provided for @progressStageStatus.
  ///
  /// In en, this message translates to:
  /// **'Status'**
  String get progressStageStatus;

  /// No description provided for @progressStagesDone.
  ///
  /// In en, this message translates to:
  /// **'{done} of {total} done'**
  String progressStagesDone(int done, int total);

  /// No description provided for @progressTabFloors.
  ///
  /// In en, this message translates to:
  /// **'Floors'**
  String get progressTabFloors;

  /// No description provided for @progressTabPending.
  ///
  /// In en, this message translates to:
  /// **'Pending'**
  String get progressTabPending;

  /// No description provided for @progressTabStages.
  ///
  /// In en, this message translates to:
  /// **'Stages'**
  String get progressTabStages;

  /// No description provided for @progressTimeUsed.
  ///
  /// In en, this message translates to:
  /// **'Time used {percent}'**
  String progressTimeUsed(String percent);

  /// No description provided for @progressTitle.
  ///
  /// In en, this message translates to:
  /// **'Site progress'**
  String get progressTitle;

  /// No description provided for @progressWorkDone.
  ///
  /// In en, this message translates to:
  /// **'Work done'**
  String get progressWorkDone;

  /// No description provided for @scanAlready.
  ///
  /// In en, this message translates to:
  /// **'{name} is already marked {status} today'**
  String scanAlready(String name, String status);

  /// No description provided for @scanAlreadyUnknown.
  ///
  /// In en, this message translates to:
  /// **'Already marked today'**
  String get scanAlreadyUnknown;

  /// No description provided for @scanCameraFailedMessage.
  ///
  /// In en, this message translates to:
  /// **'Close this page and open it again. You can still mark attendance by hand.'**
  String get scanCameraFailedMessage;

  /// No description provided for @scanCameraFailedTitle.
  ///
  /// In en, this message translates to:
  /// **'The camera did not start'**
  String get scanCameraFailedTitle;

  /// No description provided for @scanCameraOffMessage.
  ///
  /// In en, this message translates to:
  /// **'Allow the camera for Nirmaan360 in your phone\'s settings, then come back. You can still mark attendance by hand.'**
  String get scanCameraOffMessage;

  /// No description provided for @scanCameraOffTitle.
  ///
  /// In en, this message translates to:
  /// **'The camera is off'**
  String get scanCameraOffTitle;

  /// No description provided for @scanCameraStarting.
  ///
  /// In en, this message translates to:
  /// **'Starting the camera'**
  String get scanCameraStarting;

  /// No description provided for @scanCount.
  ///
  /// In en, this message translates to:
  /// **'{count} scanned in this sitting'**
  String scanCount(int count);

  /// No description provided for @scanFailed.
  ///
  /// In en, this message translates to:
  /// **'Not marked. Try again.'**
  String get scanFailed;

  /// No description provided for @scanHint.
  ///
  /// In en, this message translates to:
  /// **'Hold the camera over the QR on a worker\'s card.'**
  String get scanHint;

  /// No description provided for @scanMarked.
  ///
  /// In en, this message translates to:
  /// **'{name} marked present'**
  String scanMarked(String name);

  /// No description provided for @scanMarkedUnknown.
  ///
  /// In en, this message translates to:
  /// **'Marked present'**
  String get scanMarkedUnknown;

  /// No description provided for @scanNotACard.
  ///
  /// In en, this message translates to:
  /// **'This is not a worker card.'**
  String get scanNotACard;

  /// No description provided for @scanQueued.
  ///
  /// In en, this message translates to:
  /// **'Saved on this phone. {name} will be marked when you are online.'**
  String scanQueued(String name);

  /// No description provided for @scanQueuedNote.
  ///
  /// In en, this message translates to:
  /// **'A saved scan is sent today only. After today, mark that day by hand.'**
  String get scanQueuedNote;

  /// No description provided for @scanQueuedUnknown.
  ///
  /// In en, this message translates to:
  /// **'Saved on this phone. It will be marked when you are online.'**
  String get scanQueuedUnknown;

  /// No description provided for @scanStatusAbsent.
  ///
  /// In en, this message translates to:
  /// **'absent'**
  String get scanStatusAbsent;

  /// No description provided for @scanStatusHalf.
  ///
  /// In en, this message translates to:
  /// **'half day'**
  String get scanStatusHalf;

  /// No description provided for @scanStatusOvertime.
  ///
  /// In en, this message translates to:
  /// **'overtime'**
  String get scanStatusOvertime;

  /// No description provided for @scanStatusPresent.
  ///
  /// In en, this message translates to:
  /// **'present'**
  String get scanStatusPresent;

  /// No description provided for @scanTitle.
  ///
  /// In en, this message translates to:
  /// **'Scan worker card'**
  String get scanTitle;

  /// No description provided for @selfieConsentAgree.
  ///
  /// In en, this message translates to:
  /// **'The worker agrees'**
  String get selfieConsentAgree;

  /// No description provided for @selfieConsentAsk.
  ///
  /// In en, this message translates to:
  /// **'Read this to the worker. Take the photo only if they agree.'**
  String get selfieConsentAsk;

  /// No description provided for @selfieConsentNeedsNetwork.
  ///
  /// In en, this message translates to:
  /// **'Connect to the internet once to load the consent note. It has to be shown before the first photo.'**
  String get selfieConsentNeedsNetwork;

  /// No description provided for @selfieConsentRead.
  ///
  /// In en, this message translates to:
  /// **'Read the consent note'**
  String get selfieConsentRead;

  /// No description provided for @selfieConsentTitle.
  ///
  /// In en, this message translates to:
  /// **'Before the first photo'**
  String get selfieConsentTitle;

  /// No description provided for @selfieConsentVersion.
  ///
  /// In en, this message translates to:
  /// **'Consent note {version}'**
  String selfieConsentVersion(String version);

  /// No description provided for @selfieFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not save. Please try again.'**
  String get selfieFailed;

  /// No description provided for @selfieIntro.
  ///
  /// In en, this message translates to:
  /// **'Take a photo of the worker at the site. The phone notes where it was taken, and the server checks that it was near the site.'**
  String get selfieIntro;

  /// No description provided for @selfieLocating.
  ///
  /// In en, this message translates to:
  /// **'Finding your location'**
  String get selfieLocating;

  /// No description provided for @selfieLocationDenied.
  ///
  /// In en, this message translates to:
  /// **'Location is off for this app. Turn it on in your phone\'s settings, then take the photo again. Without it the photo cannot be checked.'**
  String get selfieLocationDenied;

  /// No description provided for @selfieLocationFailed.
  ///
  /// In en, this message translates to:
  /// **'Could not find your location. Move to open sky and try again.'**
  String get selfieLocationFailed;

  /// No description provided for @selfieLocationNote.
  ///
  /// In en, this message translates to:
  /// **'Location noted, accurate to about {metres} m'**
  String selfieLocationNote(String metres);

  /// No description provided for @selfieLocationOff.
  ///
  /// In en, this message translates to:
  /// **'The phone\'s location is switched off. Switch it on, then take the photo again.'**
  String get selfieLocationOff;

  /// No description provided for @selfieLocationWhy.
  ///
  /// In en, this message translates to:
  /// **'The phone asks for your location only now, to note where this photo was taken. It is not tracked in the background.'**
  String get selfieLocationWhy;

  /// No description provided for @selfieMarked.
  ///
  /// In en, this message translates to:
  /// **'{name} marked present. {distance} m from the site centre, {radius} m allowed.'**
  String selfieMarked(String name, int distance, int radius);

  /// No description provided for @selfieMarkedPlain.
  ///
  /// In en, this message translates to:
  /// **'{name} marked present'**
  String selfieMarkedPlain(String name);

  /// No description provided for @selfieNoCamera.
  ///
  /// In en, this message translates to:
  /// **'The camera could not open. Allow the camera in your phone\'s settings.'**
  String get selfieNoCamera;

  /// No description provided for @selfieNoSiteMessage.
  ///
  /// In en, this message translates to:
  /// **'A photo is checked against a site\'s map position. Ask your office to add a site on the website.'**
  String get selfieNoSiteMessage;

  /// No description provided for @selfieNoSiteTitle.
  ///
  /// In en, this message translates to:
  /// **'This project has no site'**
  String get selfieNoSiteTitle;

  /// No description provided for @selfiePhotoRequired.
  ///
  /// In en, this message translates to:
  /// **'Take the photo first'**
  String get selfiePhotoRequired;

  /// No description provided for @selfieQueued.
  ///
  /// In en, this message translates to:
  /// **'Saved on this phone. {name} will be marked when you are online, and the server will check the location then.'**
  String selfieQueued(String name);

  /// No description provided for @selfieQueuedNote.
  ///
  /// In en, this message translates to:
  /// **'A saved photo is sent within 7 days. After that the server refuses it.'**
  String get selfieQueuedNote;

  /// No description provided for @selfieRefused.
  ///
  /// In en, this message translates to:
  /// **'Not marked: {reason}'**
  String selfieRefused(String reason);

  /// No description provided for @selfieRetake.
  ///
  /// In en, this message translates to:
  /// **'Take again'**
  String get selfieRetake;

  /// No description provided for @selfieSend.
  ///
  /// In en, this message translates to:
  /// **'Mark present'**
  String get selfieSend;

  /// No description provided for @selfieSite.
  ///
  /// In en, this message translates to:
  /// **'Site'**
  String get selfieSite;

  /// No description provided for @selfieTakePhoto.
  ///
  /// In en, this message translates to:
  /// **'Take photo'**
  String get selfieTakePhoto;

  /// No description provided for @selfieTitle.
  ///
  /// In en, this message translates to:
  /// **'Attendance by photo'**
  String get selfieTitle;

  /// No description provided for @selfieWorker.
  ///
  /// In en, this message translates to:
  /// **'Worker'**
  String get selfieWorker;

  /// No description provided for @selfieWorkerChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose worker'**
  String get selfieWorkerChoose;

  /// No description provided for @selfieWorkerRequired.
  ///
  /// In en, this message translates to:
  /// **'Choose the worker'**
  String get selfieWorkerRequired;

  /// No description provided for @selfieWorkerSearch.
  ///
  /// In en, this message translates to:
  /// **'Search worker'**
  String get selfieWorkerSearch;

  /// No description provided for @shellCaptionMarked.
  ///
  /// In en, this message translates to:
  /// **'marked'**
  String get shellCaptionMarked;

  /// No description provided for @shellCaptionOpen.
  ///
  /// In en, this message translates to:
  /// **'open'**
  String get shellCaptionOpen;

  /// No description provided for @shellCaptionToAnswer.
  ///
  /// In en, this message translates to:
  /// **'to answer'**
  String get shellCaptionToAnswer;

  /// No description provided for @shellCaptionToday.
  ///
  /// In en, this message translates to:
  /// **'today'**
  String get shellCaptionToday;

  /// No description provided for @shellCaptionWorkDone.
  ///
  /// In en, this message translates to:
  /// **'work done'**
  String get shellCaptionWorkDone;

  /// No description provided for @shellDiscardBody.
  ///
  /// In en, this message translates to:
  /// **'It has not reached the server. It will be removed from this phone.'**
  String get shellDiscardBody;

  /// No description provided for @shellDiscardConfirm.
  ///
  /// In en, this message translates to:
  /// **'Yes, throw away'**
  String get shellDiscardConfirm;

  /// No description provided for @shellDiscardKeep.
  ///
  /// In en, this message translates to:
  /// **'Keep it'**
  String get shellDiscardKeep;

  /// No description provided for @shellDiscardTitle.
  ///
  /// In en, this message translates to:
  /// **'Throw away this entry?'**
  String get shellDiscardTitle;

  /// No description provided for @shellOfflineApprovals.
  ///
  /// In en, this message translates to:
  /// **'Approvals need network.'**
  String get shellOfflineApprovals;

  /// No description provided for @shellOfflineDetail.
  ///
  /// In en, this message translates to:
  /// **'Entries wait on phone.'**
  String get shellOfflineDetail;

  /// No description provided for @shellOfflineLabel.
  ///
  /// In en, this message translates to:
  /// **'Offline'**
  String get shellOfflineLabel;

  /// No description provided for @shellOutboxAllSentBody.
  ///
  /// In en, this message translates to:
  /// **'Nothing is left on this phone.'**
  String get shellOutboxAllSentBody;

  /// No description provided for @shellOutboxAllSentStamp.
  ///
  /// In en, this message translates to:
  /// **'All sent'**
  String get shellOutboxAllSentStamp;

  /// No description provided for @shellOutboxAttention.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 needs your attention} other{{count} need your attention}}'**
  String shellOutboxAttention(int count);

  /// No description provided for @shellOutboxLoading.
  ///
  /// In en, this message translates to:
  /// **'Loading the outbox'**
  String get shellOutboxLoading;

  /// No description provided for @shellOutboxLogTitle.
  ///
  /// In en, this message translates to:
  /// **'Not applied'**
  String get shellOutboxLogTitle;

  /// No description provided for @shellOutboxNeverSynced.
  ///
  /// In en, this message translates to:
  /// **'Not synced yet'**
  String get shellOutboxNeverSynced;

  /// No description provided for @shellOutboxServerError.
  ///
  /// In en, this message translates to:
  /// **'The server is having trouble. Your work is safe and will send later.'**
  String get shellOutboxServerError;

  /// No description provided for @shellOutboxTitle.
  ///
  /// In en, this message translates to:
  /// **'Outbox'**
  String get shellOutboxTitle;

  /// No description provided for @shellOutboxTrayLabel.
  ///
  /// In en, this message translates to:
  /// **'on phone'**
  String get shellOutboxTrayLabel;

  /// No description provided for @shellOutboxWaitingLabel.
  ///
  /// In en, this message translates to:
  /// **'waiting to send'**
  String get shellOutboxWaitingLabel;

  /// No description provided for @shellPickerCurrent.
  ///
  /// In en, this message translates to:
  /// **'Current'**
  String get shellPickerCurrent;

  /// No description provided for @shellPickerEmptyBody.
  ///
  /// In en, this message translates to:
  /// **'Ask your office to add you to a project, then try again.'**
  String get shellPickerEmptyBody;

  /// No description provided for @shellPickerEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No projects yet'**
  String get shellPickerEmptyTitle;

  /// No description provided for @shellPickerEyebrow.
  ///
  /// In en, this message translates to:
  /// **'Your projects'**
  String get shellPickerEyebrow;

  /// No description provided for @shellPickerFirstBody.
  ///
  /// In en, this message translates to:
  /// **'Add your project and run its work from this phone.'**
  String get shellPickerFirstBody;

  /// No description provided for @shellPickerFirstButton.
  ///
  /// In en, this message translates to:
  /// **'Create your first project'**
  String get shellPickerFirstButton;

  /// No description provided for @shellPickerFirstTitle.
  ///
  /// In en, this message translates to:
  /// **'Let\'s set up your first site'**
  String get shellPickerFirstTitle;

  /// No description provided for @shellPickerLoading.
  ///
  /// In en, this message translates to:
  /// **'Getting your projects…'**
  String get shellPickerLoading;

  /// No description provided for @shellPickerTitle.
  ///
  /// In en, this message translates to:
  /// **'Which site today?'**
  String get shellPickerTitle;

  /// No description provided for @shellPillAllSent.
  ///
  /// In en, this message translates to:
  /// **'All sent'**
  String get shellPillAllSent;

  /// No description provided for @shellPillAttention.
  ///
  /// In en, this message translates to:
  /// **'needs a look'**
  String get shellPillAttention;

  /// No description provided for @shellPillSending.
  ///
  /// In en, this message translates to:
  /// **'sending'**
  String get shellPillSending;

  /// No description provided for @shellPillWaiting.
  ///
  /// In en, this message translates to:
  /// **'waiting'**
  String get shellPillWaiting;

  /// No description provided for @shellProgressBasis.
  ///
  /// In en, this message translates to:
  /// **'By value of approved work'**
  String get shellProgressBasis;

  /// No description provided for @shellProgressDaysLate.
  ///
  /// In en, this message translates to:
  /// **'Days late'**
  String get shellProgressDaysLate;

  /// No description provided for @shellProgressDaysLeft.
  ///
  /// In en, this message translates to:
  /// **'Days left'**
  String get shellProgressDaysLeft;

  /// No description provided for @shellProgressEmptyBody.
  ///
  /// In en, this message translates to:
  /// **'Progress will show here once the BOQ is added.'**
  String get shellProgressEmptyBody;

  /// No description provided for @shellProgressEmptyTitle.
  ///
  /// In en, this message translates to:
  /// **'No BOQ yet'**
  String get shellProgressEmptyTitle;

  /// No description provided for @shellProgressEnd.
  ///
  /// In en, this message translates to:
  /// **'End'**
  String get shellProgressEnd;

  /// No description provided for @shellProgressNoEnd.
  ///
  /// In en, this message translates to:
  /// **'Not set'**
  String get shellProgressNoEnd;

  /// No description provided for @shellProgressNothingYet.
  ///
  /// In en, this message translates to:
  /// **'No approved work yet'**
  String get shellProgressNothingYet;

  /// No description provided for @shellProgressSemantics.
  ///
  /// In en, this message translates to:
  /// **'{percent} percent complete'**
  String shellProgressSemantics(String percent);

  /// No description provided for @shellProgressTitle.
  ///
  /// In en, this message translates to:
  /// **'Construction progress'**
  String get shellProgressTitle;

  /// No description provided for @shellProjectChoose.
  ///
  /// In en, this message translates to:
  /// **'Choose'**
  String get shellProjectChoose;

  /// No description provided for @shellProjectCreate.
  ///
  /// In en, this message translates to:
  /// **'Create project'**
  String get shellProjectCreate;

  /// No description provided for @shellProjectCreating.
  ///
  /// In en, this message translates to:
  /// **'Creating…'**
  String get shellProjectCreating;

  /// No description provided for @shellProjectEnd.
  ///
  /// In en, this message translates to:
  /// **'End date'**
  String get shellProjectEnd;

  /// No description provided for @shellProjectEndBeforeStart.
  ///
  /// In en, this message translates to:
  /// **'End date must be after the start date'**
  String get shellProjectEndBeforeStart;

  /// No description provided for @shellProjectFormTitle.
  ///
  /// In en, this message translates to:
  /// **'New project'**
  String get shellProjectFormTitle;

  /// No description provided for @shellProjectLocation.
  ///
  /// In en, this message translates to:
  /// **'Location'**
  String get shellProjectLocation;

  /// No description provided for @shellProjectName.
  ///
  /// In en, this message translates to:
  /// **'Project name'**
  String get shellProjectName;

  /// No description provided for @shellProjectNameRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter a name for the project'**
  String get shellProjectNameRequired;

  /// No description provided for @shellProjectOffline.
  ///
  /// In en, this message translates to:
  /// **'A project can only be created with network. Try again when you have signal.'**
  String get shellProjectOffline;

  /// No description provided for @shellProjectPlanTitle.
  ///
  /// In en, this message translates to:
  /// **'Project limit reached'**
  String get shellProjectPlanTitle;

  /// No description provided for @shellProjectStart.
  ///
  /// In en, this message translates to:
  /// **'Start date'**
  String get shellProjectStart;

  /// No description provided for @shellProjectValue.
  ///
  /// In en, this message translates to:
  /// **'Contract value (optional)'**
  String get shellProjectValue;

  /// No description provided for @shellProjectValueEmpty.
  ///
  /// In en, this message translates to:
  /// **'Tap to enter'**
  String get shellProjectValueEmpty;

  /// No description provided for @shellReportAttention.
  ///
  /// In en, this message translates to:
  /// **'Needs a look'**
  String get shellReportAttention;

  /// No description provided for @shellReportNotStarted.
  ///
  /// In en, this message translates to:
  /// **'Not started'**
  String get shellReportNotStarted;

  /// No description provided for @shellReportSaved.
  ///
  /// In en, this message translates to:
  /// **'Saved'**
  String get shellReportSaved;

  /// No description provided for @shellReturnedDpr.
  ///
  /// In en, this message translates to:
  /// **'Daily report · {date}'**
  String shellReturnedDpr(String date);

  /// No description provided for @shellReturnedFix.
  ///
  /// In en, this message translates to:
  /// **'Fix'**
  String get shellReturnedFix;

  /// No description provided for @shellReturnedMeasurement.
  ///
  /// In en, this message translates to:
  /// **'Measurement {code} · {qty} {unit}'**
  String shellReturnedMeasurement(String code, String qty, String unit);

  /// No description provided for @shellReturnedNoRemark.
  ///
  /// In en, this message translates to:
  /// **'No remark given'**
  String get shellReturnedNoRemark;

  /// No description provided for @shellReturnedRemarkLabel.
  ///
  /// In en, this message translates to:
  /// **'Remark'**
  String get shellReturnedRemarkLabel;

  /// No description provided for @shellReturnedTitle.
  ///
  /// In en, this message translates to:
  /// **'Returned to you'**
  String get shellReturnedTitle;

  /// No description provided for @shellSettings.
  ///
  /// In en, this message translates to:
  /// **'Settings'**
  String get shellSettings;

  /// No description provided for @shellSettingsLanguage.
  ///
  /// In en, this message translates to:
  /// **'Language'**
  String get shellSettingsLanguage;

  /// No description provided for @shellSettingsSignedIn.
  ///
  /// In en, this message translates to:
  /// **'Signed in as'**
  String get shellSettingsSignedIn;

  /// No description provided for @shellSettingsVersion.
  ///
  /// In en, this message translates to:
  /// **'App version'**
  String get shellSettingsVersion;

  /// No description provided for @shellSetupBoq.
  ///
  /// In en, this message translates to:
  /// **'BOQ items'**
  String get shellSetupBoq;

  /// No description provided for @shellSetupNext.
  ///
  /// In en, this message translates to:
  /// **'Next: {step}'**
  String shellSetupNext(String step);

  /// No description provided for @shellSetupStepSemantics.
  ///
  /// In en, this message translates to:
  /// **'{step}: {count}'**
  String shellSetupStepSemantics(String step, int count);

  /// No description provided for @shellSetupTitle.
  ///
  /// In en, this message translates to:
  /// **'Set up your site'**
  String get shellSetupTitle;

  /// No description provided for @shellSetupWallet.
  ///
  /// In en, this message translates to:
  /// **'Petty cash wallet'**
  String get shellSetupWallet;

  /// No description provided for @shellSetupWorkers.
  ///
  /// In en, this message translates to:
  /// **'Workers'**
  String get shellSetupWorkers;

  /// No description provided for @shellSignInBusy.
  ///
  /// In en, this message translates to:
  /// **'Signing in…'**
  String get shellSignInBusy;

  /// No description provided for @shellSignInButton.
  ///
  /// In en, this message translates to:
  /// **'Sign in'**
  String get shellSignInButton;

  /// No description provided for @shellSignInEmail.
  ///
  /// In en, this message translates to:
  /// **'Email'**
  String get shellSignInEmail;

  /// No description provided for @shellSignInEmailRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter your email'**
  String get shellSignInEmailRequired;

  /// No description provided for @shellSignInExpired.
  ///
  /// In en, this message translates to:
  /// **'You were signed out. Nothing is lost: your work is safe on this phone. Sign in to send it.'**
  String get shellSignInExpired;

  /// No description provided for @shellSignInHide.
  ///
  /// In en, this message translates to:
  /// **'Hide'**
  String get shellSignInHide;

  /// No description provided for @shellSignInNetworkBody.
  ///
  /// In en, this message translates to:
  /// **'Check your signal and try again.'**
  String get shellSignInNetworkBody;

  /// No description provided for @shellSignInNetworkTitle.
  ///
  /// In en, this message translates to:
  /// **'No network'**
  String get shellSignInNetworkTitle;

  /// No description provided for @shellSignInOtherUserBody.
  ///
  /// In en, this message translates to:
  /// **'This phone has {count} unsent entries from another person. They must sign in and send them first.'**
  String shellSignInOtherUserBody(int count);

  /// No description provided for @shellSignInOtherUserTitle.
  ///
  /// In en, this message translates to:
  /// **'Someone else\'s work is waiting'**
  String get shellSignInOtherUserTitle;

  /// No description provided for @shellSignInPassword.
  ///
  /// In en, this message translates to:
  /// **'Password'**
  String get shellSignInPassword;

  /// No description provided for @shellSignInPasswordRequired.
  ///
  /// In en, this message translates to:
  /// **'Enter your password'**
  String get shellSignInPasswordRequired;

  /// No description provided for @shellSignInServerBody.
  ///
  /// In en, this message translates to:
  /// **'It is not your phone. Try again in a few minutes.'**
  String get shellSignInServerBody;

  /// No description provided for @shellSignInServerTitle.
  ///
  /// In en, this message translates to:
  /// **'Server is not responding'**
  String get shellSignInServerTitle;

  /// No description provided for @shellSignInShow.
  ///
  /// In en, this message translates to:
  /// **'Show'**
  String get shellSignInShow;

  /// No description provided for @shellSignInTooManyBody.
  ///
  /// In en, this message translates to:
  /// **'Wait a minute and try again.'**
  String get shellSignInTooManyBody;

  /// No description provided for @shellSignInTooManyTitle.
  ///
  /// In en, this message translates to:
  /// **'Too many tries'**
  String get shellSignInTooManyTitle;

  /// No description provided for @shellSignInWelcome.
  ///
  /// In en, this message translates to:
  /// **'Welcome'**
  String get shellSignInWelcome;

  /// No description provided for @shellSignInWrongBody.
  ///
  /// In en, this message translates to:
  /// **'Check both and try again.'**
  String get shellSignInWrongBody;

  /// No description provided for @shellSignInWrongTitle.
  ///
  /// In en, this message translates to:
  /// **'Wrong email or password'**
  String get shellSignInWrongTitle;

  /// No description provided for @shellSignOut.
  ///
  /// In en, this message translates to:
  /// **'Sign out'**
  String get shellSignOut;

  /// No description provided for @shellSignOutConfirmBody.
  ///
  /// In en, this message translates to:
  /// **'You will need network to sign in again.'**
  String get shellSignOutConfirmBody;

  /// No description provided for @shellSignOutConfirmTitle.
  ///
  /// In en, this message translates to:
  /// **'Sign out of this phone?'**
  String get shellSignOutConfirmTitle;

  /// No description provided for @shellSignOutDiscard.
  ///
  /// In en, this message translates to:
  /// **'Sign out and lose them'**
  String get shellSignOutDiscard;

  /// No description provided for @shellSignOutHold.
  ///
  /// In en, this message translates to:
  /// **'Press and hold to lose them'**
  String get shellSignOutHold;

  /// No description provided for @shellSignOutSendFirst.
  ///
  /// In en, this message translates to:
  /// **'Send them first'**
  String get shellSignOutSendFirst;

  /// No description provided for @shellSignOutUnsentBody.
  ///
  /// In en, this message translates to:
  /// **'If you sign out now they are deleted from this phone and cannot be brought back. Send them first.'**
  String get shellSignOutUnsentBody;

  /// No description provided for @shellSignOutUnsentTitle.
  ///
  /// In en, this message translates to:
  /// **'{count, plural, =1{1 entry not sent} other{{count} entries not sent}}'**
  String shellSignOutUnsentTitle(int count);

  /// No description provided for @shellSlipSending.
  ///
  /// In en, this message translates to:
  /// **'Sending'**
  String get shellSlipSending;

  /// No description provided for @shellSlipWaiting.
  ///
  /// In en, this message translates to:
  /// **'Waiting'**
  String get shellSlipWaiting;

  /// No description provided for @shellSwitchProject.
  ///
  /// In en, this message translates to:
  /// **'Switch project'**
  String get shellSwitchProject;

  /// No description provided for @shellSyncingLabel.
  ///
  /// In en, this message translates to:
  /// **'Sending and updating'**
  String get shellSyncingLabel;

  /// No description provided for @shellTabApprovals.
  ///
  /// In en, this message translates to:
  /// **'Approvals'**
  String get shellTabApprovals;

  /// No description provided for @shellTabHome.
  ///
  /// In en, this message translates to:
  /// **'Home'**
  String get shellTabHome;

  /// No description provided for @shellTabMeasure.
  ///
  /// In en, this message translates to:
  /// **'Measure'**
  String get shellTabMeasure;

  /// No description provided for @shellTabOutbox.
  ///
  /// In en, this message translates to:
  /// **'Outbox'**
  String get shellTabOutbox;

  /// No description provided for @shellTileApprovals.
  ///
  /// In en, this message translates to:
  /// **'Approvals'**
  String get shellTileApprovals;

  /// No description provided for @shellTileAttendance.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get shellTileAttendance;

  /// No description provided for @shellTileDaily.
  ///
  /// In en, this message translates to:
  /// **'Daily report'**
  String get shellTileDaily;

  /// No description provided for @shellTileExpenses.
  ///
  /// In en, this message translates to:
  /// **'Expenses'**
  String get shellTileExpenses;

  /// No description provided for @shellTileIssues.
  ///
  /// In en, this message translates to:
  /// **'Issues'**
  String get shellTileIssues;

  /// No description provided for @shellTileMaterial.
  ///
  /// In en, this message translates to:
  /// **'Material'**
  String get shellTileMaterial;

  /// No description provided for @shellTileMeasure.
  ///
  /// In en, this message translates to:
  /// **'Measure'**
  String get shellTileMeasure;

  /// No description provided for @shellTilePetty.
  ///
  /// In en, this message translates to:
  /// **'Petty cash'**
  String get shellTilePetty;

  /// No description provided for @shellTileProgress.
  ///
  /// In en, this message translates to:
  /// **'Progress'**
  String get shellTileProgress;

  /// No description provided for @shellTimeDays.
  ///
  /// In en, this message translates to:
  /// **'{count} days ago'**
  String shellTimeDays(int count);

  /// No description provided for @shellTimeHours.
  ///
  /// In en, this message translates to:
  /// **'{count} hr ago'**
  String shellTimeHours(int count);

  /// No description provided for @shellTimeJustNow.
  ///
  /// In en, this message translates to:
  /// **'just now'**
  String get shellTimeJustNow;

  /// No description provided for @shellTimeMinutes.
  ///
  /// In en, this message translates to:
  /// **'{count} min ago'**
  String shellTimeMinutes(int count);

  /// No description provided for @shellTimeYesterday.
  ///
  /// In en, this message translates to:
  /// **'yesterday'**
  String get shellTimeYesterday;

  /// No description provided for @shellTodayAttendance.
  ///
  /// In en, this message translates to:
  /// **'Attendance'**
  String get shellTodayAttendance;

  /// No description provided for @shellTodayExpenses.
  ///
  /// In en, this message translates to:
  /// **'Spent'**
  String get shellTodayExpenses;

  /// No description provided for @shellTodayMeasured.
  ///
  /// In en, this message translates to:
  /// **'Measured'**
  String get shellTodayMeasured;

  /// No description provided for @shellTodayReport.
  ///
  /// In en, this message translates to:
  /// **'Report'**
  String get shellTodayReport;

  /// No description provided for @shellTodaySemantics.
  ///
  /// In en, this message translates to:
  /// **'{label}: {value}'**
  String shellTodaySemantics(String label, String value);

  /// No description provided for @siteToolsInspectionsHint.
  ///
  /// In en, this message translates to:
  /// **'Fill a checklist for a pour, shuttering or a safety round'**
  String get siteToolsInspectionsHint;

  /// No description provided for @siteToolsMachinesHint.
  ///
  /// In en, this message translates to:
  /// **'Record what a machine worked today, in hours or km'**
  String get siteToolsMachinesHint;

  /// No description provided for @siteToolsTitle.
  ///
  /// In en, this message translates to:
  /// **'Site tools'**
  String get siteToolsTitle;

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

  /// No description provided for @syncReasonProjectGone.
  ///
  /// In en, this message translates to:
  /// **'You no longer have access to this project. Ask your office to add you back, then try again.'**
  String get syncReasonProjectGone;

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

  /// No description provided for @syncTypeAttendanceScan.
  ///
  /// In en, this message translates to:
  /// **'Card scan'**
  String get syncTypeAttendanceScan;

  /// No description provided for @syncTypeAttendanceSelfie.
  ///
  /// In en, this message translates to:
  /// **'Attendance photo'**
  String get syncTypeAttendanceSelfie;

  /// No description provided for @syncTypeContractorPayment.
  ///
  /// In en, this message translates to:
  /// **'Contractor payment request'**
  String get syncTypeContractorPayment;

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

  /// No description provided for @syncTypeGoodsReceipt.
  ///
  /// In en, this message translates to:
  /// **'Goods receipt'**
  String get syncTypeGoodsReceipt;

  /// No description provided for @syncTypeInspection.
  ///
  /// In en, this message translates to:
  /// **'Inspection'**
  String get syncTypeInspection;

  /// No description provided for @syncTypeIssue.
  ///
  /// In en, this message translates to:
  /// **'Issue'**
  String get syncTypeIssue;

  /// No description provided for @syncTypeIssuePhoto.
  ///
  /// In en, this message translates to:
  /// **'Issue photo'**
  String get syncTypeIssuePhoto;

  /// No description provided for @syncTypeIssueStatus.
  ///
  /// In en, this message translates to:
  /// **'Issue status'**
  String get syncTypeIssueStatus;

  /// No description provided for @syncTypeMachineLog.
  ///
  /// In en, this message translates to:
  /// **'Machine log'**
  String get syncTypeMachineLog;

  /// No description provided for @syncTypeMaterialIssue.
  ///
  /// In en, this message translates to:
  /// **'Store issue'**
  String get syncTypeMaterialIssue;

  /// No description provided for @syncTypeMeasurement.
  ///
  /// In en, this message translates to:
  /// **'Measurement'**
  String get syncTypeMeasurement;

  /// No description provided for @syncTypeMeasurementPhoto.
  ///
  /// In en, this message translates to:
  /// **'Measurement photo'**
  String get syncTypeMeasurementPhoto;

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

  /// No description provided for @syncTypeProgress.
  ///
  /// In en, this message translates to:
  /// **'Site progress'**
  String get syncTypeProgress;

  /// No description provided for @syncTypeProgressCorrection.
  ///
  /// In en, this message translates to:
  /// **'Progress correction'**
  String get syncTypeProgressCorrection;

  /// No description provided for @syncTypeProgressPhoto.
  ///
  /// In en, this message translates to:
  /// **'Progress photo'**
  String get syncTypeProgressPhoto;

  /// No description provided for @syncTypeProgressResolve.
  ///
  /// In en, this message translates to:
  /// **'Pending item resolved'**
  String get syncTypeProgressResolve;

  /// No description provided for @syncTypeStage.
  ///
  /// In en, this message translates to:
  /// **'Stage update'**
  String get syncTypeStage;

  /// No description provided for @syncTypeStagePhoto.
  ///
  /// In en, this message translates to:
  /// **'Stage photo'**
  String get syncTypeStagePhoto;
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
