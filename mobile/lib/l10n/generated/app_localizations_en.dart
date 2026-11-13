// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get accountDeleteBusy => 'Deleting your account…';

  @override
  String get accountDeleteCancelButton => 'Cancel the deletion';

  @override
  String get accountDeleteCancelled =>
      'Deletion cancelled. Your company stays as it is.';

  @override
  String get accountDeleteChanged =>
      'Something changed in your company. Check what will happen, then try again.';

  @override
  String accountDeleteCompanyErases(String company) {
    return 'You are the only owner of $company, so deleting your account erases the company: every project, bill, photo, worker and user.';
  }

  @override
  String get accountDeleteCompanyTitle => 'Delete your account and company';

  @override
  String accountDeleteCompanyWhen(String date) {
    return 'It is erased after $date. Until then the company keeps working, and you can cancel by signing in again and opening Settings, Delete account.';
  }

  @override
  String accountDeleteConfirmName(String company) {
    return 'Type $company to confirm';
  }

  @override
  String get accountDeleteExport =>
      'Want a copy first? An owner can export the company\'s data on the website, under Account, Company data.';

  @override
  String get accountDeleteHandOver =>
      'To keep the company going without you, make another member an owner on the website (Team) first, then delete your account here.';

  @override
  String get accountDeleteHold => 'Hold to delete my account';

  @override
  String get accountDeleteLoading => 'Checking what will happen';

  @override
  String get accountDeleteMemberNow =>
      'Your sign-in, name, email and mobile number are removed now. You cannot undo this.';

  @override
  String accountDeleteMemberWork(String company) {
    return 'Work you recorded for $company — reports, measurements, bills, attendance and photos — stays with the company, shown as “Deleted user”.';
  }

  @override
  String get accountDeleteOfflineBody => 'Connect and try again.';

  @override
  String get accountDeleteOfflineTitle =>
      'Deleting an account needs the internet.';

  @override
  String accountDeleteOthers(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count other people will lose access.',
      one: '1 other person will lose access.',
      zero: 'No one else uses this company.',
    );
    return '$_temp0';
  }

  @override
  String get accountDeletePassword => 'Your password';

  @override
  String accountDeletePendingBody(String company, String date) {
    return '$company will be erased after $date.';
  }

  @override
  String get accountDeletePendingTitle => 'Your company is set to be erased';

  @override
  String get accountDeletePhoneCleared =>
      'This phone is signed out and its Nirmaan360 data is cleared.';

  @override
  String get accountDeleteSpoken => 'Delete my account';

  @override
  String get accountDeleteTaxRecords =>
      'Records of payments your company made to Nirmaan360 are kept, as Indian tax law requires.';

  @override
  String get accountDeleteTile => 'Delete account';

  @override
  String get accountDeleteTileSubtitle =>
      'Remove your sign-in and personal details';

  @override
  String accountDeleteUnsent(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other:
          '$count items on this phone have not reached the server. They will be lost.',
      one: '1 item on this phone has not reached the server. It will be lost.',
    );
    return '$_temp0';
  }

  @override
  String get accountDeleteWrongName =>
      'Type the company name exactly as shown.';

  @override
  String get accountDeleteWrongPassword => 'That password is not right.';

  @override
  String accountDeletedCompanyToast(String date) {
    return 'Your company will be erased after $date. Sign in before then to cancel.';
  }

  @override
  String get accountDeletedToast => 'Your account has been deleted.';

  @override
  String get alertsDashboardEmpty => 'No figures for this project yet.';

  @override
  String alertsHomeCriticalCount(int count) {
    return '$count critical';
  }

  @override
  String get alertsHomeDashboardOnly => 'Dashboard for this site';

  @override
  String alertsHomeOpen(int count) {
    return '$count open';
  }

  @override
  String get alertsHomeOverview => 'Dashboard and alerts for this site';

  @override
  String get alertsHomeTitle => 'Site alerts';

  @override
  String get alertsListRefusedMessage =>
      'Alerts are not open to your role. Ask your office if you need them.';

  @override
  String get alertsLoading => 'Loading site overview';

  @override
  String get alertsNoDataMessage =>
      'Connect to the internet once to load this project\'s overview. After that it opens offline.';

  @override
  String get alertsNoneMessage =>
      'When something on this site needs a look, it will show here.';

  @override
  String get alertsNoneTitle => 'Nothing needs attention';

  @override
  String get alertsRefusedMessage =>
      'The site overview is not open to your role. Ask your office if you need it.';

  @override
  String get alertsRefusedTitle => 'Not part of your role';

  @override
  String alertsScanDone(int added, int open, int resolved) {
    return 'Scanned. $added new, $open open, $resolved cleared.';
  }

  @override
  String get alertsScanFailed =>
      'The scan did not go through. Try again in a moment.';

  @override
  String get alertsScanNow => 'Scan now';

  @override
  String get alertsScanOffline =>
      'Scanning needs the internet. Connect and try again.';

  @override
  String alertsScanRefused(String message) {
    return 'The server said: $message';
  }

  @override
  String alertsScanWait(int seconds) {
    return 'Try again in $seconds seconds.';
  }

  @override
  String get alertsScanning => 'Scanning the site…';

  @override
  String get alertsSeverityCritical => 'Critical';

  @override
  String get alertsSeverityInfo => 'For information';

  @override
  String get alertsSeverityWarning => 'Warning';

  @override
  String alertsShowingSome(int shown, int total) {
    return 'Showing the $shown most urgent of $total. The rest are on the website.';
  }

  @override
  String alertsSince(String date) {
    return 'Since $date';
  }

  @override
  String get alertsTabAlerts => 'Alerts';

  @override
  String get alertsTabDashboard => 'Dashboard';

  @override
  String get alertsTitle => 'Site overview';

  @override
  String get announcementsEmptyMessage =>
      'News and reminders from your company show here.';

  @override
  String get announcementsEmptyTitle => 'No announcements yet';

  @override
  String get announcementsFailedMessage => 'Try again in a moment.';

  @override
  String get announcementsFailedTitle => 'Could not read announcements';

  @override
  String get announcementsLoading => 'Reading announcements';

  @override
  String get announcementsMore => 'Show older';

  @override
  String get announcementsOfflineMessage =>
      'Announcements are kept on the server. Connect and try again.';

  @override
  String get announcementsOfflineTitle => 'Needs the internet';

  @override
  String get announcementsReminder => 'Reminder';

  @override
  String get announcementsRetry => 'Try again';

  @override
  String get announcementsSettingsSubtitle => 'News and reminders sent to you';

  @override
  String get announcementsTitle => 'Announcements';

  @override
  String get appName => 'Nirmaan360';

  @override
  String get approvalsActionApproved => 'Approved';

  @override
  String get approvalsActionRejected => 'Rejected';

  @override
  String get approvalsActionReturned => 'Sent back';

  @override
  String get approvalsActionSubmitted => 'Submitted';

  @override
  String get approvalsApprove => 'Approve';

  @override
  String get approvalsBackToInbox => 'Back to approvals';

  @override
  String get approvalsBillNumber => 'Bill number';

  @override
  String get approvalsBillType => 'Type';

  @override
  String get approvalsChainNext => 'After that';

  @override
  String get approvalsChainTitle => 'Approval trail';

  @override
  String get approvalsChainWaiting => 'Waiting now';

  @override
  String get approvalsCouldNotRefresh => 'Could not refresh. Please try again.';

  @override
  String get approvalsCouldNotRefreshOffline =>
      'Could not reach the server. Showing what was last fetched.';

  @override
  String approvalsDaysAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count days ago',
      one: '1 day ago',
    );
    return '$_temp0';
  }

  @override
  String get approvalsDeductions => 'Deductions';

  @override
  String get approvalsDeviation => 'Deviation';

  @override
  String get approvalsDeviationBody =>
      'Goes over the BOQ quantity plus tolerance. Project manager must approve.';

  @override
  String get approvalsDocBill => 'RA bill';

  @override
  String get approvalsDocBillNote => 'Credit or debit note';

  @override
  String get approvalsDocContractorPayment => 'Contractor payment';

  @override
  String get approvalsDocDpr => 'Daily report';

  @override
  String get approvalsDocIndent => 'Indent';

  @override
  String get approvalsDocMeasurement => 'Measurement';

  @override
  String get approvalsDocOther => 'Document';

  @override
  String get approvalsDocRfi => 'RFI';

  @override
  String get approvalsDocVendorBill => 'Vendor bill';

  @override
  String get approvalsEmptyBody => 'New approvals will appear here.';

  @override
  String get approvalsEmptyTitle => 'Nothing waiting';

  @override
  String get approvalsFailOfflineBody =>
      'Your decision was not sent. Nothing was queued. Try again when connected.';

  @override
  String get approvalsFailOfflineTitle => 'No network';

  @override
  String get approvalsFailRaceBody =>
      'Someone else acted on this first. Refresh to see what is waiting now.';

  @override
  String get approvalsFailRaceTitle => 'Already decided';

  @override
  String get approvalsFailRejectedTitle => 'The server refused this';

  @override
  String get approvalsFailServerBody =>
      'Something went wrong on the server. Your decision was not recorded. Try again.';

  @override
  String get approvalsFailServerTitle => 'Could not send';

  @override
  String get approvalsFailSessionBody =>
      'Your session has ended. Your decision was not sent.';

  @override
  String get approvalsFailSessionTitle => 'Please sign in again';

  @override
  String get approvalsForYou => 'Waiting for you';

  @override
  String get approvalsGoneBody => 'Someone has already decided this one.';

  @override
  String get approvalsGoneTitle => 'No longer waiting';

  @override
  String get approvalsGross => 'Gross value of work';

  @override
  String get approvalsGst => 'GST';

  @override
  String get approvalsHindrances => 'Hindrances';

  @override
  String approvalsHoursAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count hours ago',
      one: '1 hour ago',
    );
    return '$_temp0';
  }

  @override
  String approvalsIndentForLine(String code) {
    return 'For BOQ line $code';
  }

  @override
  String get approvalsIndentItems => 'Material asked for';

  @override
  String get approvalsIndentLines => 'materials';

  @override
  String get approvalsIndentRequiredBy => 'Needed by';

  @override
  String get approvalsIndentStore => 'Store';

  @override
  String get approvalsJustNow => 'just now';

  @override
  String get approvalsManpower => 'Manpower';

  @override
  String approvalsMinutesAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count minutes ago',
      one: '1 minute ago',
    );
    return '$_temp0';
  }

  @override
  String get approvalsNeedsNetwork =>
      'Approve and Send back need network. Nothing is queued.';

  @override
  String get approvalsNetPayable => 'Net payable';

  @override
  String get approvalsNoteAgainstBill => 'Against bill';

  @override
  String get approvalsNoteCredit => 'Credit note';

  @override
  String get approvalsNoteDebit => 'Debit note';

  @override
  String get approvalsNoteEffect => 'Effect on bill';

  @override
  String get approvalsNoteNumber => 'Note number';

  @override
  String get approvalsNoteReason => 'Reason';

  @override
  String get approvalsNoteTaxable => 'Taxable amount';

  @override
  String get approvalsNoteTotal => 'Note total';

  @override
  String get approvalsOffline => 'No network.';

  @override
  String get approvalsOfflineDetail =>
      'You can read these, but approvals need network.';

  @override
  String get approvalsOnOthers => 'Waiting on others';

  @override
  String get approvalsOpen => 'Open';

  @override
  String get approvalsOpenOnWeb =>
      'This document cannot be shown on the phone yet. Please review and approve it on the website.';

  @override
  String get approvalsParty => 'Party';

  @override
  String approvalsPayAhead(String gap) {
    return 'Approving puts payment $gap points ahead of the work done.';
  }

  @override
  String get approvalsPayContractor => 'Contractor';

  @override
  String get approvalsPayInStep =>
      'Approving keeps payment within the work done.';

  @override
  String get approvalsPayNoValue =>
      'This contract has no value, so no payment on it is covered by work done.';

  @override
  String approvalsPayOverLimit(String limit) {
    return 'That is beyond this project\'s limit of $limit points.';
  }

  @override
  String get approvalsPayOverrideApprove => 'Approve with override';

  @override
  String get approvalsPayOverrideLaterTitle =>
      'The last approval will need the owner';

  @override
  String get approvalsPayOverrideOwnerTitle =>
      'Your written override is needed';

  @override
  String get approvalsPayOverrideReasonHelp =>
      'Write at least one full sentence. It is kept with the payment and in the audit log.';

  @override
  String get approvalsPayOverrideReasonLabel => 'Owner\'s reason';

  @override
  String get approvalsPayOverrideReasonTitle =>
      'Why may this payment go ahead of the work done?';

  @override
  String get approvalsPayOverrideShort => 'At least 4 words and 20 letters.';

  @override
  String get approvalsPayOverrideTitle => 'Only the owner can approve this';

  @override
  String get approvalsPayPaidAfter => 'Paid if approved';

  @override
  String get approvalsPayPaidNow => 'Paid so far';

  @override
  String approvalsPayRatio(String paid, String work) {
    return 'Paid $paid, work $work';
  }

  @override
  String get approvalsPayWorkValue => 'Value of work done';

  @override
  String get approvalsPayWorkVsPaid => 'Work against paid';

  @override
  String get approvalsPeriod => 'Period';

  @override
  String get approvalsPhotos => 'Photos';

  @override
  String get approvalsRaisedBy => 'Raised by';

  @override
  String get approvalsReasonAmounts => 'Amounts do not match';

  @override
  String get approvalsReasonBillMissing => 'Bill or receipt is missing';

  @override
  String get approvalsReasonDeductions => 'Check the deductions';

  @override
  String get approvalsReasonDimensions => 'Check the dimensions';

  @override
  String get approvalsReasonDuplicate => 'Already paid, or asked for twice';

  @override
  String get approvalsReasonIndentMaterial => 'Wrong material or unit';

  @override
  String get approvalsReasonIndentNotNeeded => 'Not needed now';

  @override
  String get approvalsReasonIndentStock => 'Stock is already in the store';

  @override
  String get approvalsReasonLocation => 'Write the location clearly';

  @override
  String get approvalsReasonManpower => 'Manpower count is wrong';

  @override
  String get approvalsReasonNote => 'Reason is not clear';

  @override
  String get approvalsReasonPaymentAhead => 'Payment is ahead of the work done';

  @override
  String get approvalsReasonPeriod => 'Billing period is wrong';

  @override
  String get approvalsReasonPhotos => 'Add photos';

  @override
  String get approvalsReasonQuantityHigh => 'Quantity looks too high';

  @override
  String get approvalsReasonRfiAnswered => 'Already answered, or not needed';

  @override
  String get approvalsReasonRfiDrawing => 'Drawing reference is missing';

  @override
  String get approvalsReasonRfiUnclear => 'The question is not clear';

  @override
  String get approvalsReasonVbNotReceived =>
      'Material or service was not received';

  @override
  String get approvalsReasonVbTax => 'GST or TDS is wrong';

  @override
  String get approvalsReasonWorkDetail => 'Add more work detail';

  @override
  String get approvalsReasonWorkNotDone => 'The work for this is not done yet';

  @override
  String get approvalsReasonWrongItem => 'Wrong BOQ line';

  @override
  String get approvalsRefresh => 'Refresh';

  @override
  String get approvalsRefreshing => 'Refreshing';

  @override
  String get approvalsReject => 'Reject';

  @override
  String get approvalsRejectHelp =>
      'A rejected document is closed for good. It cannot be corrected and sent again. Pick a reason or write your own.';

  @override
  String get approvalsRejectHint => 'Why it is rejected';

  @override
  String get approvalsRejectTitle => 'Why reject it?';

  @override
  String get approvalsRejectedToast => 'Rejected with your remark.';

  @override
  String get approvalsRemarkHint => 'What needs fixing';

  @override
  String get approvalsRemarkLabel => 'Remark';

  @override
  String get approvalsRemarks => 'Remarks';

  @override
  String get approvalsReportDate => 'Report date';

  @override
  String get approvalsRfiAddressedTo => 'Addressed to';

  @override
  String get approvalsRfiAnswerBy => 'Answer needed by';

  @override
  String get approvalsRfiDrawing => 'Drawing';

  @override
  String get approvalsRfiQuestion => 'Question';

  @override
  String get approvalsRoleAccounts => 'Accounts';

  @override
  String get approvalsRoleAdmin => 'Admin';

  @override
  String get approvalsRoleClient => 'Client';

  @override
  String get approvalsRoleEngineer => 'Site engineer';

  @override
  String get approvalsRoleOwner => 'Owner';

  @override
  String get approvalsRolePm => 'Project manager';

  @override
  String get approvalsRoleQs => 'Estimator / QS';

  @override
  String get approvalsSendBack => 'Send back';

  @override
  String get approvalsSendBackHelp =>
      'Tell them what to fix. Pick one or write your own.';

  @override
  String get approvalsSendBackTitle => 'Why send it back?';

  @override
  String get approvalsSentBackToast => 'Sent back with your remark.';

  @override
  String get approvalsSessionExpired => 'Please sign in again.';

  @override
  String get approvalsSummary => 'Summary of work';

  @override
  String get approvalsTitle => 'Approvals';

  @override
  String get approvalsTotal => 'Total';

  @override
  String get approvalsVbBillDate => 'Bill date';

  @override
  String get approvalsVbBillTotal => 'Bill total';

  @override
  String get approvalsVbCostHead => 'Cost head';

  @override
  String get approvalsVbDescription => 'Description';

  @override
  String get approvalsVbDueDate => 'Pay by';

  @override
  String get approvalsVbTds => 'TDS';

  @override
  String get approvalsWeather => 'Weather';

  @override
  String get approvalsWorkDone => 'Work done';

  @override
  String get approvalsWorkers => 'workers';

  @override
  String get attendanceAddFirstWorker => 'Add first worker';

  @override
  String get attendanceAddWorker => 'Add worker';

  @override
  String get attendanceAllMarked => 'Everyone is marked';

  @override
  String attendanceAllPresent(int count) {
    return 'All present ($count)';
  }

  @override
  String attendanceAllPresentDone(int count) {
    return '$count marked present';
  }

  @override
  String get attendanceAllPresentHelper => 'Skips anyone already marked.';

  @override
  String get attendanceChooseProject => 'Choose a project';

  @override
  String attendanceDateTitle(String date) {
    return 'Attendance for $date';
  }

  @override
  String get attendanceDayToday => 'Today';

  @override
  String get attendanceDayYesterday => 'Yesterday';

  @override
  String get attendanceEyebrow => 'Muster roll';

  @override
  String attendanceGroupProgress(int marked, int total) {
    return '$marked of $total';
  }

  @override
  String get attendanceHoursUnit => 'h';

  @override
  String get attendanceLeft => 'Unmarked';

  @override
  String get attendanceLoadFailedMessage =>
      'Your marks are safe on this phone. Try again.';

  @override
  String get attendanceLoadFailedTitle => 'Could not open the muster';

  @override
  String get attendanceLoading => 'Loading the muster';

  @override
  String get attendanceMarkedSoFar => 'Marked so far';

  @override
  String get attendanceNoProjectMessage =>
      'Choose a project first, then mark the muster here.';

  @override
  String get attendanceNoProjectTitle => 'No project chosen';

  @override
  String get attendanceNoWorkersAsk => 'Ask the office to add the workers.';

  @override
  String get attendanceNoWorkersMessage =>
      'Add your first worker to start the muster.';

  @override
  String get attendanceNoWorkersTitle => 'No workers on the muster';

  @override
  String get attendanceOtHours => 'Overtime hours';

  @override
  String get attendanceOtLess => 'Half hour less';

  @override
  String get attendanceOtMore => 'Half hour more';

  @override
  String get attendanceSaveFailed => 'That mark was not saved. Tap it again.';

  @override
  String get attendanceScanCard => 'Scan card';

  @override
  String get attendanceSelfie => 'Selfie';

  @override
  String attendanceSheetDone(String name) {
    return '$name added to the muster';
  }

  @override
  String get attendanceSheetFailed =>
      'The worker could not be added. Try again.';

  @override
  String get attendanceSheetName => 'Worker\'s name';

  @override
  String get attendanceSheetNameHint => 'As on the muster';

  @override
  String get attendanceSheetNameRequired => 'Write the worker\'s name.';

  @override
  String get attendanceSheetOfflineError =>
      'No network. Try again when the phone is back online.';

  @override
  String get attendanceSheetOfflineMessage =>
      'Attendance still works without network. Add this worker when the phone is back online.';

  @override
  String get attendanceSheetOfflineTitle => 'Adding a worker needs network';

  @override
  String get attendanceSheetTitle => 'New worker';

  @override
  String get attendanceSheetTrade => 'Trade';

  @override
  String get attendanceSheetTradeRequired => 'Choose the trade.';

  @override
  String attendanceSheetWageFor(String name) {
    return 'Daily wage for $name';
  }

  @override
  String get attendanceSheetWageHelper => 'Leave blank if you do not know it.';

  @override
  String get attendanceTitle => 'Attendance';

  @override
  String get attendanceTodayTitle => 'Today\'s attendance';

  @override
  String get boqAddItem => 'Add item';

  @override
  String get boqAmountLabel => 'Amount';

  @override
  String boqBalance(String qty, String unit) {
    return 'Balance $qty $unit';
  }

  @override
  String get boqBalanceLabel => 'Balance';

  @override
  String get boqBalanceValueLabel => 'Balance value';

  @override
  String get boqDeleteAction => 'Delete this item';

  @override
  String get boqDeleteBody =>
      'It will be taken out of the BOQ for everyone. The BOQ history keeps it with your name and reason. It cannot be brought back from the app.';

  @override
  String get boqDeleteConfirm => 'Delete item';

  @override
  String boqDeleteDone(String code) {
    return 'Item $code deleted from the BOQ.';
  }

  @override
  String get boqDeleteFailed => 'The item could not be deleted. Try again.';

  @override
  String get boqDeleteKeep => 'Keep it';

  @override
  String get boqDeleteOffline => 'Deleting needs the internet.';

  @override
  String get boqDeleteOwnerNote =>
      'Only the company owner can delete BOQ items. An item with measurements, daily report work, tenders or bills cannot be deleted.';

  @override
  String get boqDeleteOwnerOnly =>
      'Only the company owner can delete a BOQ item.';

  @override
  String get boqDeleteReason => 'Why is it being deleted?';

  @override
  String get boqDeleteReasonRequired => 'Write why it is being deleted.';

  @override
  String get boqDeleteRefusedTitle => 'This item cannot be deleted';

  @override
  String boqDeleteTitle(String code) {
    return 'Delete item $code?';
  }

  @override
  String get boqDeleteUnsent =>
      'This phone has work on this item that has not been sent yet. Send it first, then delete.';

  @override
  String get boqEditAction => 'Edit item';

  @override
  String get boqEditChanged =>
      'Someone changed this item after you opened it. It now shows as the office has it. Check it and save again.';

  @override
  String get boqEditCode => 'Item code';

  @override
  String get boqEditCodeRequired => 'Enter the item code.';

  @override
  String get boqEditGone => 'This item is no longer in the BOQ.';

  @override
  String boqEditMeasured(String qty, String unit) {
    return 'Measured so far: $qty $unit. The quantity cannot go below what is measured or billed.';
  }

  @override
  String get boqEditNotAllowed => 'You cannot change the BOQ. Ask the office.';

  @override
  String get boqEditOfflineDetail => 'BOQ items can be changed only online.';

  @override
  String get boqEditQtyInvalid =>
      'Enter the quantity as a number with up to 3 decimals, like 120.5.';

  @override
  String get boqEditRateInvalid =>
      'Enter the rate as a number with up to 2 decimals, like 450.00.';

  @override
  String get boqEditReason => 'Why is it changing?';

  @override
  String get boqEditReasonHelper =>
      'Kept in the BOQ history with your name. Measurements and bills already made do not change.';

  @override
  String get boqEditReasonHint => 'e.g. Revised drawing from the architect';

  @override
  String get boqEditReasonRequired => 'Write why it is changing.';

  @override
  String get boqEditSave => 'Save changes';

  @override
  String get boqEditSaved => 'BOQ item saved.';

  @override
  String get boqEditTitle => 'Edit BOQ item';

  @override
  String get boqEmptyAsk =>
      'A BOQ is the list of work on this project. Ask the office to add it.';

  @override
  String get boqEmptyExplain =>
      'A BOQ is the list of work on this project, with quantity and rate.';

  @override
  String get boqEmptyTitle => 'No BOQ lines yet';

  @override
  String get boqFormAdded => 'Line added. Add the next one.';

  @override
  String get boqFormAmount => 'Amount';

  @override
  String get boqFormCode => 'Code (optional)';

  @override
  String get boqFormCodeHelper => 'Leave blank to get the next number.';

  @override
  String get boqFormDescription => 'Work description';

  @override
  String get boqFormDescriptionHint => 'e.g. M25 concrete in slab';

  @override
  String get boqFormDescriptionRequired => 'Write the work description.';

  @override
  String get boqFormFailed => 'The line could not be saved. Try again.';

  @override
  String get boqFormFinish => 'Save and finish';

  @override
  String get boqFormQty => 'Quantity';

  @override
  String get boqFormQtyRequired => 'Enter the quantity.';

  @override
  String get boqFormRate => 'Rate';

  @override
  String get boqFormRateRequired => 'Enter the rate.';

  @override
  String get boqFormSaveAnother => 'Save and add another';

  @override
  String get boqFormSection => 'Section (optional)';

  @override
  String get boqFormSectionHint => 'e.g. Superstructure';

  @override
  String get boqFormTitle => 'Add BOQ item';

  @override
  String get boqFormUnit => 'Unit';

  @override
  String get boqFormUnitRequired => 'Choose the unit.';

  @override
  String boqItemCode(String code) {
    return 'Item $code';
  }

  @override
  String boqLinesAdded(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'lines',
      one: 'line',
    );
    return '$count $_temp0 added';
  }

  @override
  String get boqLoading => 'Loading BOQ';

  @override
  String boqMeasuredShare(int percent) {
    return '$percent% measured';
  }

  @override
  String get boqMissingAsk =>
      'Ask the office to add them. If they are added already, connect to the network once.';

  @override
  String get boqMissingManage => 'Add the lines, then pick them here.';

  @override
  String get boqMissingTitle => 'This project has no BOQ lines yet';

  @override
  String get boqNoMatchMessage => 'Try another code or word.';

  @override
  String get boqNoMatchTitle => 'Nothing found';

  @override
  String get boqNoProjectMessage => 'Choose a project first to see its BOQ.';

  @override
  String get boqNoProjectTitle => 'No project chosen';

  @override
  String get boqNoSection => 'Other lines';

  @override
  String get boqOfflineDetail => 'BOQ lines can be saved only online.';

  @override
  String get boqOfflineTitle => 'No network.';

  @override
  String get boqOpenAction => 'Open BOQ';

  @override
  String boqOver(String qty, String unit) {
    return 'Over by $qty $unit';
  }

  @override
  String get boqOverLabel => 'Over by';

  @override
  String get boqOverValueLabel => 'Value over BOQ';

  @override
  String get boqRefreshFailed =>
      'Could not update. Showing what is on this phone.';

  @override
  String get boqSearchHint => 'Code or work';

  @override
  String get boqSearchLabel => 'Search lines';

  @override
  String get boqShownTotalTitle => 'Total of lines shown';

  @override
  String get boqTitle => 'BOQ';

  @override
  String get boqTotalTitle => 'BOQ total';

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
  String get contractorAdvance => 'Advance';

  @override
  String get contractorAgainst => 'Against';

  @override
  String get contractorAgainstLine => 'A BOQ line';

  @override
  String get contractorAgainstPart => 'A part';

  @override
  String get contractorAgainstWhole => 'Whole contract';

  @override
  String get contractorAmount => 'Amount (₹)';

  @override
  String get contractorAmountError =>
      'Enter an amount above zero, with at most two decimal places.';

  @override
  String get contractorAmountLabel => 'Amount';

  @override
  String get contractorApprovalNote =>
      'This goes for approval. Nothing counts as paid until it is approved.';

  @override
  String get contractorBalance => 'Balance to pay';

  @override
  String get contractorBillFailed =>
      'Could not load the bill. Check your internet.';

  @override
  String get contractorBillNotPicture =>
      'This bill is not a picture. Open it on the website.';

  @override
  String get contractorBillPhoto => 'Add bill photo';

  @override
  String get contractorClosed => 'Closed';

  @override
  String get contractorContract => 'Contract';

  @override
  String get contractorContractChoose => 'Choose a contract';

  @override
  String get contractorContractClosed => 'This contract is closed.';

  @override
  String get contractorContractSearch => 'Search contracts';

  @override
  String get contractorEmptyMessage =>
      'Work orders for this project will show here.';

  @override
  String get contractorEmptyTitle => 'No contracts yet';

  @override
  String get contractorEnteredBy => 'Entered by';

  @override
  String contractorHomeAhead(int count) {
    return '$count paid ahead of work';
  }

  @override
  String contractorHomeCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count contracts',
      one: '1 contract',
    );
    return '$_temp0';
  }

  @override
  String get contractorHomeInStep => 'All in step with work';

  @override
  String contractorHomeOver(int count) {
    return '$count over the limit';
  }

  @override
  String get contractorHomeOverview => 'Work done against each contract';

  @override
  String get contractorImpactAfter => 'If approved';

  @override
  String get contractorImpactLine => 'This line';

  @override
  String get contractorImpactNow => 'Now';

  @override
  String get contractorInStep => 'In step with work';

  @override
  String get contractorKind => 'This payment is';

  @override
  String get contractorKindAdvance => 'An advance';

  @override
  String get contractorKindWork => 'For work done';

  @override
  String get contractorLatestOnly =>
      'Showing the latest 50. The rest are on the website.';

  @override
  String get contractorLineChoose => 'Choose a BOQ line';

  @override
  String get contractorLines => 'Work lines';

  @override
  String get contractorLinesLoadOnline =>
      'BOQ lines and parts load once this contract has been opened online.';

  @override
  String get contractorLoading => 'Loading contracts';

  @override
  String get contractorMode => 'Paid by';

  @override
  String get contractorModeBank => 'Bank';

  @override
  String get contractorModeCash => 'Cash';

  @override
  String get contractorModeRequired => 'Choose how it was paid.';

  @override
  String get contractorModeUpi => 'UPI';

  @override
  String get contractorNoDataMessage =>
      'Connect to the internet once to load this project\'s contracts. After that they open offline.';

  @override
  String get contractorNoPaymentsMessage =>
      'Requests you raise will show here with their status.';

  @override
  String get contractorNoPaymentsTitle => 'No payment requests yet';

  @override
  String get contractorNotes => 'Notes';

  @override
  String get contractorOptional => 'Optional';

  @override
  String get contractorOtherDate => 'Other date';

  @override
  String get contractorOverLimit => 'Over the limit';

  @override
  String get contractorPaid => 'Paid';

  @override
  String get contractorPaidAhead => 'Paid ahead of work';

  @override
  String get contractorPaidOn => 'Paid on';

  @override
  String get contractorPaidTo => 'Paid to';

  @override
  String get contractorPaidToHint => 'Leave blank for the contractor';

  @override
  String get contractorPartChoose => 'Choose a part';

  @override
  String get contractorPartDone => 'Done';

  @override
  String get contractorPartNotDone => 'Not done';

  @override
  String get contractorPartNotPaid => 'Not paid';

  @override
  String get contractorPartOver => 'Paid more than the part';

  @override
  String get contractorPartPaid => 'Paid';

  @override
  String get contractorPartPartPaid => 'Part paid';

  @override
  String get contractorParts => 'Parts';

  @override
  String get contractorReference => 'Reference';

  @override
  String get contractorRefusedMessage =>
      'Contracts are not open to your role. Ask your office if you need them.';

  @override
  String get contractorRefusedTitle => 'Not part of your role';

  @override
  String get contractorRequest => 'Request payment';

  @override
  String get contractorReversal => 'Reversal';

  @override
  String get contractorSearchNone => 'Nothing matches';

  @override
  String get contractorSend => 'Send request';

  @override
  String get contractorShortBy => 'Short by';

  @override
  String get contractorTabContracts => 'Contracts';

  @override
  String get contractorTabPayments => 'Payments';

  @override
  String get contractorTitle => 'Contractors';

  @override
  String get contractorValue => 'Contract value';

  @override
  String get contractorViewBill => 'View bill';

  @override
  String get contractorWorkDone => 'Work done';

  @override
  String get contractorWorkLeft => 'Work left';

  @override
  String get contractorWorkLeftValue => 'Value of work left';

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
  String get dprAdd => 'Add';

  @override
  String get dprAddNotes => 'Add hindrances or notes';

  @override
  String get dprAddWork => 'Add work';

  @override
  String get dprAddedTag => 'Added';

  @override
  String get dprApproverRemark => 'Approver\'s remark';

  @override
  String get dprAttentionBody =>
      'This report could not be sent. Open Outbox to fix it.';

  @override
  String get dprBalance => 'Balance';

  @override
  String dprBoqDonePercent(int percent) {
    return '$percent percent of BOQ measured';
  }

  @override
  String get dprBoqEmpty => 'No BOQ items found.';

  @override
  String get dprCameraFailed =>
      'Could not open the camera. Allow camera in phone settings.';

  @override
  String get dprCanStillSend => 'You can still send.';

  @override
  String get dprChooseAnother => 'Change item';

  @override
  String get dprCorrectAndSend => 'Correct and send again';

  @override
  String get dprEdit => 'Edit';

  @override
  String get dprEmptyMessage => 'Your first report starts this book.';

  @override
  String get dprEmptyTitle => 'No reports yet';

  @override
  String get dprFilledFromYesterday => 'Filled from yesterday';

  @override
  String get dprFormTitle => 'Daily report';

  @override
  String get dprFutureDate => 'The report date cannot be in the future.';

  @override
  String get dprHindrances => 'Hindrances';

  @override
  String get dprHindrancesHint => 'Material late, rain, power cut';

  @override
  String get dprListTitle => 'Daily reports';

  @override
  String get dprLoading => 'Loading';

  @override
  String get dprLocked =>
      'This report has gone for approval and cannot be changed now.';

  @override
  String get dprMissedDays => 'Missed days';

  @override
  String dprMoreRows(int count) {
    return 'and $count more';
  }

  @override
  String dprMoreTrades(int count) {
    return 'More trades ($count)';
  }

  @override
  String get dprNeedWeather => 'Choose today\'s weather.';

  @override
  String get dprNeedWorkers => 'Enter the workers who came.';

  @override
  String get dprNoPhotosYet => 'No photos added.';

  @override
  String get dprNoProject => 'Pick a project first';

  @override
  String get dprNoWorkYet => 'No work added.';

  @override
  String get dprNotSaved => 'Not saved';

  @override
  String get dprNotes => 'Notes';

  @override
  String get dprNotesHint => 'Anything else from today';

  @override
  String get dprNothingRecorded => 'Nothing recorded';

  @override
  String dprOfDays(int days) {
    return 'of $days days';
  }

  @override
  String get dprOpenOutbox => 'Open Outbox';

  @override
  String get dprOpenToday => 'Open today\'s report';

  @override
  String get dprOptional => 'Optional';

  @override
  String get dprOverBalance => 'More than the BOQ balance. Check the figure.';

  @override
  String get dprPhotoFailed => 'Could not add this photo. Try again.';

  @override
  String get dprPhotoMissing => 'Not available';

  @override
  String dprPhotosCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count photos',
      one: '1 photo',
    );
    return '$_temp0';
  }

  @override
  String get dprPhotosFull => 'A report can hold 10 photos.';

  @override
  String get dprQtyShort => 'Qty';

  @override
  String get dprQuantity => 'Quantity';

  @override
  String get dprRemove => 'Remove';

  @override
  String get dprRemoved => 'Removed';

  @override
  String get dprReportsThisMonth => 'Reports this month';

  @override
  String get dprReturnedNoRemark => 'Please correct and send again.';

  @override
  String get dprReturnedTitle => 'Sent back for correction';

  @override
  String get dprReviewOffline =>
      'No network now. The report stays on this phone and goes by itself when network returns.';

  @override
  String get dprReviewTitle => 'Check and send';

  @override
  String dprRowsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count items',
      one: '1 item',
    );
    return '$_temp0';
  }

  @override
  String dprSameAsYesterday(int count) {
    return 'Same as yesterday ($count)';
  }

  @override
  String get dprSavedOnPhone => 'Saved on phone';

  @override
  String get dprSavedSendingNow => 'Saved on phone, sending now';

  @override
  String get dprSavedWillSend =>
      'Saved on phone, will send when network returns';

  @override
  String get dprSaving => 'Saving on phone';

  @override
  String get dprSearchBoq => 'Search BOQ';

  @override
  String get dprSearchHint => 'Code or description';

  @override
  String get dprSectionNotes => 'Hindrances and notes';

  @override
  String get dprSectionPhotos => 'Photos';

  @override
  String get dprSectionWeather => 'Weather';

  @override
  String get dprSectionWork => 'Work done';

  @override
  String get dprSectionWorkers => 'Workers';

  @override
  String get dprSend => 'Send';

  @override
  String get dprSendAgain => 'Send again';

  @override
  String get dprSendFailed => 'Could not save this report. Try again.';

  @override
  String get dprSendReport => 'Send report';

  @override
  String get dprShareAfterSend =>
      'You can share this report once it has reached the office.';

  @override
  String get dprShareFailed => 'Could not get the report. Try again.';

  @override
  String dprShareLinkNote(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other:
          'The WhatsApp link opens the PDF for $days days. Anyone who has the link can open it.',
      one:
          'The WhatsApp link opens the PDF for 1 day. Anyone who has the link can open it.',
    );
    return '$_temp0';
  }

  @override
  String get dprShareNeedsInternet => 'Sharing needs internet.';

  @override
  String get dprShareNotAllowed =>
      'This report cannot be shared from your account.';

  @override
  String get dprSharePdf => 'Share PDF';

  @override
  String get dprShareTitle => 'Share this report';

  @override
  String get dprShareWhatsApp => 'Share on WhatsApp';

  @override
  String get dprSiteClosedWrite => 'Site closed? Write the reason';

  @override
  String get dprSyncAttention => 'Needs attention';

  @override
  String get dprSyncPhoneOnly => 'Phone only';

  @override
  String get dprSyncSending => 'Sending';

  @override
  String get dprSyncWaiting => 'Not sent yet';

  @override
  String get dprTakePhoto => 'Take photo';

  @override
  String get dprTotalWorkers => 'Total workers';

  @override
  String get dprUndo => 'Undo';

  @override
  String dprWorkersCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count workers',
      one: '1 worker',
    );
    return '$_temp0';
  }

  @override
  String get dprWorkersOnSite => 'Workers on site';

  @override
  String dprWorkersWord(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'workers',
      one: 'worker',
    );
    return '$_temp0';
  }

  @override
  String dprWriteFor(String date) {
    return 'Write report for $date';
  }

  @override
  String get dprWriteToday => 'Write today\'s report';

  @override
  String get dprYesterdayLabel => 'Yesterday';

  @override
  String get dprYesterdayTag => 'Yesterday';

  @override
  String floorPlanBoqQty(String qty, String unit) {
    return 'BOQ $qty $unit';
  }

  @override
  String get floorPlanEmptyMessage => 'Add BOQ lines to the project first.';

  @override
  String get floorPlanEmptyTitle => 'No BOQ lines';

  @override
  String floorPlanFloorDone(String percent) {
    return '$percent done on this floor';
  }

  @override
  String get floorPlanNoMatch => 'No line matches';

  @override
  String get floorPlanNotPlanned => 'Not planned here';

  @override
  String floorPlanPlanned(String planned, String unit, String done) {
    return 'Planned $planned $unit · done $done';
  }

  @override
  String floorPlanQty(String unit) {
    return 'Planned quantity on this floor ($unit)';
  }

  @override
  String get floorPlanQtyHelp => 'Enter 0 to remove it.';

  @override
  String get floorPlanQtyInvalid =>
      'Enter a quantity from zero up, with at most three decimals.';

  @override
  String get floorPlanSaved => 'Planned quantity saved.';

  @override
  String get floorPlanSearch => 'Search by code or name';

  @override
  String get floorPlanTitle => 'Planned quantities';

  @override
  String get floorSetupAddFloor => 'Add floor';

  @override
  String get floorSetupAddSegments => 'Split into segments';

  @override
  String floorSetupAdded(String name) {
    return '$name added.';
  }

  @override
  String get floorSetupChooseDate => 'Choose a date';

  @override
  String get floorSetupClearDate => 'Clear';

  @override
  String get floorSetupDays => 'Planned days';

  @override
  String get floorSetupDaysInvalid => 'Enter whole days, from 1 to 5000.';

  @override
  String get floorSetupDeadline => 'Deadline';

  @override
  String get floorSetupDelete => 'Remove floor';

  @override
  String get floorSetupDeleteBody =>
      'The floor and the quantities planned on it are removed. A floor with work recorded on it, or with segments under it, cannot be removed.';

  @override
  String get floorSetupDeleteConfirm => 'Remove';

  @override
  String get floorSetupDeleteKeep => 'Keep it';

  @override
  String floorSetupDeleteTitle(String name) {
    return 'Remove $name?';
  }

  @override
  String floorSetupDeleted(String name) {
    return '$name removed.';
  }

  @override
  String get floorSetupEmptyMessage =>
      'Add the floors of this building. Split a floor into flats or segments to track each one.';

  @override
  String get floorSetupEmptyTitle => 'No floors yet';

  @override
  String get floorSetupFailed => 'Could not save. Try again.';

  @override
  String get floorSetupFloorName => 'Floor name';

  @override
  String get floorSetupFloorNameHint => 'For example: Ground floor';

  @override
  String get floorSetupForbidden => 'You are not allowed to do this.';

  @override
  String get floorSetupLoadFailed => 'Could not load the floors.';

  @override
  String get floorSetupLoading => 'Loading floors';

  @override
  String get floorSetupNameRequired => 'Enter a name.';

  @override
  String get floorSetupNotPlanned => 'Nothing planned yet';

  @override
  String get floorSetupOffline => 'No internet. Floors are set up online only.';

  @override
  String get floorSetupOpen => 'Set up floors';

  @override
  String get floorSetupOwnerNote =>
      'Only the company owner can remove a floor.';

  @override
  String get floorSetupPlan => 'Plan quantities';

  @override
  String floorSetupPlannedCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count BOQ lines planned',
      one: '1 BOQ line planned',
    );
    return '$_temp0';
  }

  @override
  String get floorSetupRename => 'Rename';

  @override
  String floorSetupRenameTitle(String name) {
    return 'Rename $name';
  }

  @override
  String get floorSetupRenamed => 'Name saved.';

  @override
  String get floorSetupRetry => 'Try again';

  @override
  String get floorSetupSave => 'Save';

  @override
  String floorSetupSegmentCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count segments',
      one: '1 segment',
    );
    return '$_temp0';
  }

  @override
  String floorSetupSegmentsAdded(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count segments added.',
      one: '1 segment added.',
    );
    return '$_temp0';
  }

  @override
  String get floorSetupSegmentsHint => 'Flat 101\nFlat 102';

  @override
  String get floorSetupSegmentsLabel => 'Segment names, one per line';

  @override
  String floorSetupSegmentsNotAdded(String names) {
    return 'Not added: $names';
  }

  @override
  String floorSetupSegmentsTitle(String name) {
    return 'Segments of $name';
  }

  @override
  String get floorSetupShare => 'Share BOQ across floors';

  @override
  String get floorSetupStart => 'Start date';

  @override
  String get floorSetupTimeFrame => 'Time frame';

  @override
  String get floorSetupTimeFrameNone => 'Not set';

  @override
  String get floorSetupTimeFrameSaved => 'Time frame saved.';

  @override
  String floorSetupTimeFrameSummary(
    String start,
    String days,
    String deadline,
  ) {
    return 'Start $start · $days days · deadline $deadline';
  }

  @override
  String get floorSetupTitle => 'Floors and segments';

  @override
  String get floorSplitAllLines => 'All BOQ lines';

  @override
  String floorSplitDone(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count BOQ lines shared.',
      one: '1 BOQ line shared.',
    );
    return '$_temp0';
  }

  @override
  String get floorSplitExplain =>
      'Each chosen BOQ line is shared equally across the chosen floors and segments. What was planned for these lines on other floors is replaced.';

  @override
  String get floorSplitFloors => 'Floors and segments';

  @override
  String get floorSplitLines => 'BOQ lines';

  @override
  String get floorSplitNeedFloor => 'Choose at least one floor.';

  @override
  String get floorSplitNeedLine => 'Choose at least one BOQ line.';

  @override
  String get floorSplitSubmit => 'Share equally';

  @override
  String get floorSplitTitle => 'Share BOQ across floors';

  @override
  String get inspectionCategoryGeneral => 'General';

  @override
  String get inspectionCategoryPourCard => 'Pour card';

  @override
  String get inspectionCategoryPpe => 'PPE';

  @override
  String get inspectionCategoryReinforcement => 'Reinforcement';

  @override
  String get inspectionCategorySafety => 'Safety';

  @override
  String get inspectionCategoryShuttering => 'Shuttering';

  @override
  String get inspectionDiscard => 'Discard';

  @override
  String get inspectionDiscardBody => 'What you marked so far will be lost.';

  @override
  String get inspectionDiscardTitle => 'Discard this inspection?';

  @override
  String get inspectionFillChoose => 'Choose Pass, Fail or Not applicable';

  @override
  String get inspectionFillDate => 'Inspection date';

  @override
  String get inspectionFillLocation => 'Location';

  @override
  String get inspectionFillLocationHint =>
      'For example Slab L3 or Gate 2. Optional.';

  @override
  String get inspectionFillLockedNote =>
      'Once finished, an inspection cannot be changed.';

  @override
  String inspectionFillMarked(int done, int total) {
    return '$done of $total marked';
  }

  @override
  String inspectionFillNeedRemark(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count failed items need a reason',
      one: '1 failed item needs a reason',
    );
    return '$_temp0';
  }

  @override
  String inspectionFillUnmarked(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count items still have no result',
      one: '1 item still has no result',
    );
    return '$_temp0';
  }

  @override
  String get inspectionFillWrong => 'What is wrong?';

  @override
  String get inspectionFillWrongRequired =>
      'Write what is wrong. A failed item needs this.';

  @override
  String get inspectionFinish => 'Finish inspection';

  @override
  String get inspectionGoneMessage =>
      'This inspection was finished or discarded.';

  @override
  String get inspectionGoneTitle => 'Not on this phone';

  @override
  String get inspectionItemsNotLoaded =>
      'The items are not on this phone yet. Connect to the internet to load them.';

  @override
  String get inspectionNotOnPhone => 'Connect to the internet to load it.';

  @override
  String get inspectionOpenNote =>
      'This inspection was started on the website and is not finished. Finish it there.';

  @override
  String get inspectionOpenOutbox => 'Open the sending list';

  @override
  String get inspectionResultFail => 'Fail';

  @override
  String get inspectionResultNa => 'Not applicable';

  @override
  String get inspectionResultPass => 'Pass';

  @override
  String get inspectionResultPending => 'No result yet';

  @override
  String get inspectionStatusFailed => 'Failed';

  @override
  String get inspectionStatusOpen => 'In progress';

  @override
  String get inspectionStatusPassed => 'Passed';

  @override
  String get inspectionWaitingNote =>
      'Saved on this phone. It will be sent when you are online, and cannot be changed after that.';

  @override
  String get inspectionsChecklistSearch => 'Search checklists';

  @override
  String get inspectionsDraftWord => 'Not finished';

  @override
  String get inspectionsDraftsHeading => 'Being filled in';

  @override
  String get inspectionsEmptyMessage =>
      'Start one from a checklist. It is saved on this phone and sent when you are online.';

  @override
  String get inspectionsEmptyTitle => 'No inspections yet';

  @override
  String inspectionsFailedCount(int count) {
    return '$count failed';
  }

  @override
  String inspectionsItemCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count items',
      one: '1 item',
    );
    return '$_temp0';
  }

  @override
  String get inspectionsLatestOnly =>
      'Showing the latest 25. The rest are on the website.';

  @override
  String get inspectionsLoading => 'Loading inspections';

  @override
  String get inspectionsNoDataMessage =>
      'Connect to the internet once to load this project\'s checklists. After that they open offline.';

  @override
  String get inspectionsRecentHeading => 'Recent';

  @override
  String get inspectionsRefusedMessage =>
      'Checklists are not open to your role. Ask your office if you need them.';

  @override
  String get inspectionsRefusedTitle => 'Not part of your role';

  @override
  String get inspectionsSearchNone => 'No checklist matches';

  @override
  String get inspectionsStart => 'Start an inspection';

  @override
  String get inspectionsTitle => 'Inspections';

  @override
  String get issuesActionClose => 'Close issue';

  @override
  String get issuesActionReopen => 'Reopen';

  @override
  String get issuesActionResolve => 'Mark resolved';

  @override
  String get issuesActionStart => 'Start work';

  @override
  String get issuesAssignedUnknown => 'Assigned';

  @override
  String get issuesAssigneeSheetTitle => 'Assign to';

  @override
  String get issuesDetailAssignee => 'Assigned to';

  @override
  String get issuesDetailDescription => 'Details';

  @override
  String get issuesDetailDue => 'Due date';

  @override
  String get issuesDetailMissingMessage =>
      'It may have been removed. Go back to the list.';

  @override
  String get issuesDetailMissingTitle => 'This issue is not on this phone';

  @override
  String get issuesDetailPriority => 'Priority';

  @override
  String get issuesDetailRaisedBy => 'Raised by';

  @override
  String get issuesDetailResolvedOn => 'Resolved on';

  @override
  String issuesDueOn(String date) {
    return 'Due $date';
  }

  @override
  String get issuesDueToday => 'Due today';

  @override
  String get issuesEmptyFilterMessage => 'No issues with this status.';

  @override
  String get issuesEmptyFilterTitle => 'Nothing here';

  @override
  String get issuesEmptyMessage =>
      'If something is wrong on site, raise it here so it gets fixed.';

  @override
  String get issuesEmptyTitle => 'No issues on this site';

  @override
  String get issuesEyebrow => 'Site issues';

  @override
  String get issuesFilterAll => 'All';

  @override
  String get issuesFormAssignee => 'Who will fix it? (optional)';

  @override
  String get issuesFormDue => 'Fix by (optional)';

  @override
  String get issuesFormDueClear => 'Tap the chosen day again to clear it.';

  @override
  String get issuesFormDueThree => '3 days';

  @override
  String get issuesFormDueToday => 'Today';

  @override
  String get issuesFormDueWeek => '1 week';

  @override
  String get issuesFormEyebrow => 'New issue';

  @override
  String get issuesFormLocation => 'Where on site? (optional)';

  @override
  String get issuesFormLocationHint => 'e.g. Grid C, third floor';

  @override
  String get issuesFormPhotos => 'Photos of the problem (optional)';

  @override
  String get issuesFormPriority => 'How urgent is it?';

  @override
  String get issuesFormSave => 'Save issue';

  @override
  String get issuesFormTitle => 'Raise an issue';

  @override
  String get issuesFormWhat => 'What is wrong?';

  @override
  String get issuesFormWhatHint => 'e.g. Crack in column C3';

  @override
  String get issuesFormWhatRequired => 'Write what is wrong.';

  @override
  String get issuesLoading => 'Loading issues';

  @override
  String get issuesNoDueDate => 'No due date';

  @override
  String get issuesNoProjectMessage =>
      'Choose a project first, then raise issues here.';

  @override
  String get issuesNoProjectTitle => 'No project chosen';

  @override
  String issuesOverdue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: 'days',
      one: 'day',
    );
    return 'Overdue by $days $_temp0';
  }

  @override
  String get issuesPhotoAddAfter => 'Add after photo';

  @override
  String get issuesPhotoAddBefore => 'Add before photo';

  @override
  String get issuesPhotoFailed => 'Could not add the photo. Try again.';

  @override
  String get issuesPhotoSavedOffline =>
      'Photo saved on phone. Sends when network returns.';

  @override
  String get issuesPhotoSavedOnline => 'Photo saved. Sending now.';

  @override
  String get issuesPhotosAfter => 'After';

  @override
  String get issuesPhotosBefore => 'Before';

  @override
  String get issuesPhotosNone => 'No photos yet';

  @override
  String get issuesPhotosTitle => 'Photos';

  @override
  String get issuesRaise => 'Raise issue';

  @override
  String get issuesRefreshFailed =>
      'Could not update. Showing what is on this phone.';

  @override
  String issuesResolvedOn(String date) {
    return 'Resolved $date';
  }

  @override
  String get issuesSaveFailed => 'Could not save the issue. Try again.';

  @override
  String get issuesSavedOffline =>
      'Saved on phone. Sends when network returns.';

  @override
  String get issuesSavedOnline => 'Saved. Sending now.';

  @override
  String get issuesStatusFailed => 'Could not change the status. Try again.';

  @override
  String get issuesSyncAttention => 'Needs attention';

  @override
  String get issuesSyncSending => 'Sending';

  @override
  String get issuesTitle => 'Issues';

  @override
  String get issuesUnassigned => 'Not assigned';

  @override
  String get machineCategoryBackhoe => 'JCB / Backhoe loader';

  @override
  String get machineCategoryCompressor => 'Compressor';

  @override
  String get machineCategoryCrane => 'Crane';

  @override
  String get machineCategoryDgSet => 'DG set';

  @override
  String get machineCategoryExcavator => 'Excavator';

  @override
  String get machineCategoryFormwork => 'Shuttering / Formwork';

  @override
  String get machineCategoryMixer => 'Concrete mixer';

  @override
  String get machineCategoryOther => 'Other';

  @override
  String get machineCategoryPump => 'Concrete pump';

  @override
  String get machineCategoryRoller => 'Roller / Compactor';

  @override
  String get machineCategoryScaffolding => 'Scaffolding';

  @override
  String get machineCategoryTipper => 'Tipper / Truck';

  @override
  String get machineCategoryTools => 'Tools and small plant';

  @override
  String get machineCategoryTransitMixer => 'Transit mixer';

  @override
  String get machineCategoryVehicle => 'Vehicle';

  @override
  String get machineFormBreakdown => 'Hours lost to breakdown';

  @override
  String get machineFormChoose => 'Choose a machine';

  @override
  String get machineFormClosing => 'Closing reading';

  @override
  String get machineFormClosingBelow =>
      'The closing reading cannot be lower than the opening reading';

  @override
  String get machineFormDate => 'Date';

  @override
  String get machineFormHours => 'Hours worked';

  @override
  String get machineFormHoursError =>
      'Enter hours from 0 to 24, with one decimal at most';

  @override
  String get machineFormHoursHint => 'One decimal at most, like 7.5';

  @override
  String get machineFormKm => 'Km run';

  @override
  String get machineFormKmError => 'Enter km from 0 to 99,999';

  @override
  String machineFormLogged(String hours) {
    return 'Already logged that day: $hours h. One machine cannot pass 24 h in a day.';
  }

  @override
  String get machineFormMachine => 'Machine';

  @override
  String get machineFormMachineRequired => 'Choose the machine first';

  @override
  String get machineFormNoMachinesTitle => 'No machines to log against';

  @override
  String get machineFormNoneMatch => 'No machine matches';

  @override
  String get machineFormNothing =>
      'Enter the hours or km worked, or the hours lost to breakdown';

  @override
  String get machineFormOpening => 'Opening reading';

  @override
  String get machineFormOperator => 'Operator';

  @override
  String get machineFormReadingError => 'Enter a number from 0 to 99,999,999';

  @override
  String get machineFormReadingsHintHours =>
      'Leave Hours worked blank and the hours are worked out from the two readings.';

  @override
  String get machineFormReadingsHintKm =>
      'Leave Km run blank and the km are worked out from the two readings.';

  @override
  String get machineFormRemarks => 'Remarks';

  @override
  String get machineFormSave => 'Save log';

  @override
  String get machineFormSearch => 'Search by code or name';

  @override
  String get machinesAdd => 'Add a log';

  @override
  String machinesBreakdownValue(String hours) {
    return 'Breakdown $hours h';
  }

  @override
  String get machinesEmptyMessage =>
      'A day\'s work of a machine on this project will show here.';

  @override
  String get machinesEmptyTitle => 'No logs yet';

  @override
  String machinesHoursValue(String hours) {
    return '$hours h';
  }

  @override
  String machinesKmValue(String km) {
    return '$km km';
  }

  @override
  String get machinesLatestOnly =>
      'Showing the latest 50. The rest are on the website.';

  @override
  String get machinesLoading => 'Loading machine logs';

  @override
  String get machinesNoDataMessage =>
      'Connect to the internet once to load the machines. After that the log opens offline.';

  @override
  String get machinesNoMachinesMessage =>
      'Machines are added on the website. They show here after that.';

  @override
  String get machinesNoMachinesTitle => 'No machines yet';

  @override
  String machinesOperatorValue(String name) {
    return 'Operator $name';
  }

  @override
  String get machinesRefusedMessage =>
      'The machine log is not open to your role. Ask your office if you need it.';

  @override
  String get machinesRefusedTitle => 'Not part of your role';

  @override
  String get machinesTitle => 'Machine log';

  @override
  String get machinesUnderRepair => 'Under repair';

  @override
  String machinesUnknownMachine(int id) {
    return 'Machine no. $id';
  }

  @override
  String get materialActualUsed => 'Used';

  @override
  String get materialAdd => 'Add entry';

  @override
  String get materialAgainstNorm => 'Against the norm';

  @override
  String get materialAllowed => 'Allowed';

  @override
  String get materialAllowedWastage => 'Allowed wastage';

  @override
  String get materialAsPerNorm => 'As per norm';

  @override
  String get materialBasisApproved => 'Approved measurements';

  @override
  String get materialBasisDpr => 'Daily reports';

  @override
  String get materialBasisMeasured => 'Measurement book';

  @override
  String materialBeyondAllowed(String qty) {
    return '$qty beyond what is allowed';
  }

  @override
  String get materialBillAmount => 'Amount';

  @override
  String get materialBillAmountInvalid =>
      'Enter an amount above 0 with up to 2 decimals.';

  @override
  String get materialBillAttach => 'Attach bill';

  @override
  String get materialBillAttached => 'Bill attached';

  @override
  String materialBillAttachedBy(String name) {
    return 'Attached by $name';
  }

  @override
  String get materialBillDate => 'Bill date';

  @override
  String get materialBillFailed =>
      'Could not load the bill. Check your internet.';

  @override
  String get materialBillFromGallery => 'From gallery';

  @override
  String get materialBillHeading => 'Bill';

  @override
  String get materialBillNeeded => 'Add a photo of the bill.';

  @override
  String get materialBillNeededOrNumber =>
      'Add a photo of the bill, or enter its number.';

  @override
  String get materialBillNo => 'Bill no.';

  @override
  String get materialBillNotPicture =>
      'This bill is a PDF. Open it on the website.';

  @override
  String get materialBillPending => 'Bill pending';

  @override
  String get materialBillPhoto => 'Photo of the bill';

  @override
  String get materialBillRemovePhoto => 'Remove photo';

  @override
  String get materialBillReplace => 'Replace bill';

  @override
  String get materialBillReplaceNote => 'A new file replaces the one attached.';

  @override
  String get materialBillSaved => 'Bill attached.';

  @override
  String get materialBillSend => 'Save bill';

  @override
  String get materialBillTakePhoto => 'Take photo';

  @override
  String get materialBillView => 'View bill';

  @override
  String get materialBillWaiting => 'Waiting for the bill.';

  @override
  String materialBillsAlertNote(int days) {
    return 'An alert goes out after $days days without a bill.';
  }

  @override
  String materialBillsDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days waiting',
      one: '1 day waiting',
      zero: 'Came today',
    );
    return '$_temp0';
  }

  @override
  String get materialBillsLoadFailed =>
      'Could not load the bills. Pull down to try again.';

  @override
  String get materialBillsNoneAttached => 'No bills attached yet';

  @override
  String get materialBillsNoneMessage =>
      'Challans taken on site show here until their bill is attached.';

  @override
  String get materialBillsNonePending => 'No bills pending';

  @override
  String get materialBillsOffline =>
      'Connect to the internet to see the bills.';

  @override
  String get materialBillsOverdue => 'Overdue';

  @override
  String get materialBillsRefused => 'Bills are not open to your role.';

  @override
  String get materialBillsTitle => 'Bills against challans';

  @override
  String materialBillsWaitingCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count waiting for a bill',
      one: '1 waiting for a bill',
      zero: 'No bill waiting',
    );
    return '$_temp0';
  }

  @override
  String get materialBoqForWork => 'BOQ for work done';

  @override
  String get materialChallanLoadFailed =>
      'Could not load this challan. Pull down to try again.';

  @override
  String get materialChallanNo => 'Challan no. (optional)';

  @override
  String get materialChallanNoShort => 'Challan no.';

  @override
  String get materialChallanOffline =>
      'Connect to the internet to open this challan.';

  @override
  String get materialChallanOpen => 'Open challan';

  @override
  String get materialChallanPhoto => 'Photo of challan';

  @override
  String get materialChallanRefused => 'This challan is not open to you.';

  @override
  String get materialChallanTitle => 'Challan';

  @override
  String get materialChoose => 'Choose material';

  @override
  String get materialCount => 'Count stock';

  @override
  String get materialCountHint =>
      'Stock is corrected to what you counted. Any missing quantity shows as unaccounted.';

  @override
  String get materialDate => 'Date';

  @override
  String materialDifference(String qty) {
    return 'Difference $qty';
  }

  @override
  String get materialEmptyMessage =>
      'Entries for material received and used will show here.';

  @override
  String get materialEmptyTitle => 'Nothing to check yet';

  @override
  String get materialEnteredBy => 'Entered by';

  @override
  String get materialExtraRequired => 'Say what the extra work was';

  @override
  String get materialExtraWhat => 'What was the extra work?';

  @override
  String get materialFlagsTitle => 'Questions to answer';

  @override
  String get materialInStock => 'In stock';

  @override
  String get materialLastCounted => 'Last counted';

  @override
  String get materialLoading => 'Loading material';

  @override
  String get materialMoreFailed => 'Could not load more. Try again.';

  @override
  String get materialName => 'Material';

  @override
  String get materialNeedsNetwork =>
      'This needs the internet. Connect and try again.';

  @override
  String get materialNoCountsTitle => 'No counts yet';

  @override
  String get materialNoDataMessage =>
      'Connect to the internet once to load this project\'s material. After that it works offline.';

  @override
  String get materialNoDeliveriesTitle => 'No challans yet';

  @override
  String get materialNoPhoto => 'No photo was attached.';

  @override
  String get materialNoUseTitle => 'No material used yet';

  @override
  String get materialNorm => 'Norm';

  @override
  String materialNormPer(String qty, String unit, String workUnit) {
    return '$qty $unit per $workUnit';
  }

  @override
  String get materialNotAllowed => 'Your role cannot do this. Ask your office.';

  @override
  String get materialOnBoq => 'BOQ work';

  @override
  String get materialOnChallan => 'On challan';

  @override
  String get materialOnExtra => 'Extra work';

  @override
  String get materialOnSite => 'Stock on site';

  @override
  String get materialOptional => 'Optional';

  @override
  String get materialOverStock =>
      'This is more than the stock shown here. The server may refuse it.';

  @override
  String get materialPhotoFailed =>
      'Could not load the photo. Check your internet.';

  @override
  String get materialQtyCounted => 'Counted quantity';

  @override
  String get materialQtyIssued => 'Quantity issued';

  @override
  String get materialQtyOnChallan => 'Quantity written on challan (optional)';

  @override
  String get materialQtyOnChallanHint =>
      'If less arrived than the challan says, enter the challan figure.';

  @override
  String get materialQtyReceived => 'Quantity received';

  @override
  String get materialQtyRequired => 'Enter the quantity';

  @override
  String get materialQtyUsed => 'Quantity used';

  @override
  String get materialReasonAnswered => 'Answered';

  @override
  String get materialReasonEmpty => 'Write the reason first.';

  @override
  String materialReasonFrom(String name) {
    return 'Reason from $name';
  }

  @override
  String get materialReasonHint =>
      'Say what happened on site. If the gap grows, it will ask again.';

  @override
  String get materialReasonLabel => 'Why is there a gap?';

  @override
  String get materialReasonNotYours =>
      'The engineer, store keeper, QS or project manager writes the reason.';

  @override
  String get materialReasonSaved => 'Reason saved.';

  @override
  String get materialReasonSend => 'Save reason';

  @override
  String get materialReasonTooLong => 'Keep the reason to 2000 characters.';

  @override
  String get materialReasonWrite => 'Write the reason';

  @override
  String get materialReceived => 'Material received';

  @override
  String get materialReceivedShort => 'Received';

  @override
  String get materialRemarks => 'Remarks';

  @override
  String get materialReorderHeading => 'Below reorder level';

  @override
  String materialReorderLine(String balance, String level, String unit) {
    return '$balance of $level $unit';
  }

  @override
  String materialReversedNote(String name, String date, String reason) {
    return 'Reversed by $name on $date: $reason';
  }

  @override
  String get materialSearch => 'Search material';

  @override
  String get materialSearchNone => 'Nothing matches';

  @override
  String get materialSendFailed => 'Could not save. Try again.';

  @override
  String get materialShort => 'Short delivered';

  @override
  String get materialShortAsk => 'Delivered short?';

  @override
  String get materialShortAtGate => 'Found at the gate';

  @override
  String materialShortBy(String unit) {
    return 'Short by ($unit)';
  }

  @override
  String get materialShortFoundOn => 'Found on';

  @override
  String get materialShortHeading => 'Short deliveries';

  @override
  String get materialShortHint =>
      'This takes the quantity out of stock and counts against the supplier.';

  @override
  String get materialShortLater => 'Found later';

  @override
  String get materialShortNote => 'Note';

  @override
  String get materialShortNoteTooLong => 'Keep the note to 500 characters.';

  @override
  String get materialShortQtyInvalid =>
      'Enter a quantity above 0 with up to 3 decimals.';

  @override
  String get materialShortSaved =>
      'Short delivery recorded against the supplier.';

  @override
  String get materialShortSend => 'Record short delivery';

  @override
  String get materialShouldBe => 'Should be in stock';

  @override
  String get materialShowMore => 'Show more';

  @override
  String get materialStage => 'Stage (optional)';

  @override
  String get materialStageHint => 'Such as slab 3';

  @override
  String get materialStatusNoNorm => 'No norm set';

  @override
  String get materialStatusNoWork => 'Issued, no work yet';

  @override
  String get materialStatusOk => 'Within norm';

  @override
  String get materialStatusUnder => 'Under norm';

  @override
  String get materialStatusWastage => 'Wastage';

  @override
  String get materialStore => 'Store';

  @override
  String get materialSupplier => 'Supplier';

  @override
  String get materialSupplierChoose => 'Choose supplier';

  @override
  String get materialSupplierFromList => 'Choose from list';

  @override
  String get materialSupplierName => 'Supplier name';

  @override
  String get materialSupplierNew => 'New supplier';

  @override
  String get materialSupplierRequired => 'Say who supplied it';

  @override
  String get materialSupplierSearch => 'Search supplier';

  @override
  String get materialTabCounts => 'Counts';

  @override
  String get materialTabDeliveries => 'Challans';

  @override
  String get materialTabStock => 'Stock';

  @override
  String get materialTabUsed => 'Used';

  @override
  String get materialTitle => 'Material';

  @override
  String materialToAnswer(int count) {
    return '$count to answer';
  }

  @override
  String get materialUnaccounted => 'Unaccounted';

  @override
  String get materialUnplannedHeading => 'Issued without a BOQ line';

  @override
  String get materialUsed => 'Material used';

  @override
  String get materialUsedShort => 'Used';

  @override
  String get materialVehicle => 'Vehicle no.';

  @override
  String get materialVehicleNo => 'Vehicle no. (optional)';

  @override
  String get materialViewPhoto => 'View challan photo';

  @override
  String get materialWastageBasis => 'Work done from';

  @override
  String get materialWastageEmptyMessage =>
      'Norms are set on the website. Issue material against BOQ lines to see it here.';

  @override
  String get materialWastageEmptyTitle => 'Nothing to reconcile yet';

  @override
  String get materialWastageHint =>
      'Allowed = work done × norm, plus the norm\'s wastage %. Norms are set on the website.';

  @override
  String get materialWastageLoadFailed =>
      'Could not load the wastage. Pull down to try again.';

  @override
  String get materialWastageOffline =>
      'Connect to the internet to see wastage against the BOQ.';

  @override
  String materialWastageOverCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count lines over their allowance',
      one: '1 line over its allowance',
      zero: 'No line over its allowance',
    );
    return '$_temp0';
  }

  @override
  String get materialWastageRefused =>
      'Wastage against the BOQ is not open to your role.';

  @override
  String get materialWastageTile => 'Wastage against BOQ norms';

  @override
  String get materialWastageTileHint =>
      'Material used on each BOQ line against what its norm allows';

  @override
  String get materialWastageTitle => 'Wastage against BOQ';

  @override
  String get materialWorkDone => 'Work done';

  @override
  String get measurementAdd => 'Add';

  @override
  String get measurementAddEntry => 'Add measurement';

  @override
  String get measurementAllLines => 'All lines';

  @override
  String get measurementApprovedLockedBody =>
      'Corrections are made on the web as a reversal entry.';

  @override
  String get measurementApprovedLockedTitle => 'Approved and locked';

  @override
  String get measurementApproverSaid => 'Approver\'s remark';

  @override
  String get measurementAttentionBody =>
      'The server refused this entry. It is safe on your phone.';

  @override
  String get measurementAttentionTitle => 'Could not send';

  @override
  String get measurementBalance => 'Balance';

  @override
  String get measurementBalanceAfter => 'Balance after this';

  @override
  String get measurementBookTitle => 'Measurement book';

  @override
  String get measurementBoqProgress => 'BOQ used';

  @override
  String get measurementBoqQty => 'BOQ';

  @override
  String measurementBoqUsed(String percent) {
    return '$percent of BOQ used';
  }

  @override
  String get measurementBreadth => 'Breadth';

  @override
  String get measurementBreadthShort => '× B';

  @override
  String get measurementChange => 'Change';

  @override
  String get measurementChangeLine => 'Change BOQ line';

  @override
  String get measurementChooseLine => 'Choose BOQ line';

  @override
  String get measurementClose => 'Close';

  @override
  String get measurementDateLabel => 'Measured on';

  @override
  String get measurementDeduct => 'Deduct';

  @override
  String get measurementDelete => 'Delete';

  @override
  String get measurementDepth => 'Depth';

  @override
  String get measurementDepthShort => '× D';

  @override
  String get measurementDescriptionHint => 'What was measured';

  @override
  String get measurementDescriptionLabel => 'Description';

  @override
  String get measurementDetailTitle => 'Measurement';

  @override
  String get measurementDetailsShort => 'Location, date';

  @override
  String get measurementDetailsTitle => 'Location, note and date';

  @override
  String get measurementDeviation => 'Deviation';

  @override
  String measurementDeviationBody(String percent) {
    return 'Over BOQ + $percent%. It will need PM approval.';
  }

  @override
  String get measurementDeviationDetail =>
      'This entry goes over the BOQ quantity plus tolerance, so the project manager must approve it.';

  @override
  String measurementDraftsLeft(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count rows kept as drafts. Send them from the book.',
      one: '1 row kept as a draft. Send it from the book.',
    );
    return '$_temp0';
  }

  @override
  String get measurementEdit => 'Edit';

  @override
  String get measurementEmptyBody => 'Your first entry starts this book.';

  @override
  String get measurementEmptyFilteredBody => 'No entries have this status.';

  @override
  String get measurementEmptyFilteredTitle => 'Nothing here';

  @override
  String get measurementEmptyTitle => 'No measurements yet';

  @override
  String get measurementFilterAll => 'All';

  @override
  String get measurementFixLast => 'Fix last';

  @override
  String get measurementFormulaHint => 'Enter Nos to begin';

  @override
  String get measurementGoneBody => 'It may have been deleted on the web.';

  @override
  String get measurementGoneTitle => 'Entry not found';

  @override
  String get measurementInReviewBody =>
      'With the approver. It cannot be changed until they decide.';

  @override
  String get measurementItem => 'Item';

  @override
  String get measurementLeaveBody =>
      'The figures typed for this row will be lost.';

  @override
  String get measurementLeaveGo => 'Leave';

  @override
  String get measurementLeaveKeep => 'Keep entering';

  @override
  String get measurementLeaveTitle => 'Leave without saving?';

  @override
  String get measurementLength => 'Length';

  @override
  String get measurementLengthShort => '× L';

  @override
  String get measurementLoading => 'Loading';

  @override
  String get measurementLocationHint => 'Grid, floor, footing';

  @override
  String get measurementLocationLabel => 'Location';

  @override
  String get measurementLockedBody =>
      'It has been approved or is under review.';

  @override
  String get measurementLockedTitle => 'This entry cannot be changed';

  @override
  String get measurementNet => 'net';

  @override
  String get measurementNext => 'Next';

  @override
  String get measurementNoMatchTitle => 'Nothing matches';

  @override
  String get measurementNos => 'Nos';

  @override
  String get measurementOffline => 'No network.';

  @override
  String get measurementOfflineDetail =>
      'Entries stay on this phone and go when network returns.';

  @override
  String get measurementOtherDate => 'Other date';

  @override
  String get measurementPhotoAdd => 'Add photo';

  @override
  String get measurementPhotoFailed => 'Could not add the photo. Try again.';

  @override
  String get measurementPhotoSaved => 'Photo saved. Sending now.';

  @override
  String get measurementPhotoSavedOffline =>
      'Photo saved on phone. Sends when network returns.';

  @override
  String get measurementPhotosLocked =>
      'Photos cannot be added once the entry is with the approver.';

  @override
  String get measurementPhotosNone => 'No photos yet';

  @override
  String get measurementPhotosTitle => 'Photos';

  @override
  String get measurementPreviewNote =>
      'Figures on this phone are a preview. The office\'s figures are final.';

  @override
  String get measurementPreviewTag => 'preview';

  @override
  String get measurementProblemFuture => 'The date cannot be in the future.';

  @override
  String get measurementProblemLocked =>
      'This entry has already been sent for approval.';

  @override
  String get measurementProblemNoLine => 'Choose the BOQ line first.';

  @override
  String get measurementProblemNos => 'Enter Nos. It is required.';

  @override
  String get measurementProblemPositive =>
      'This figure must be more than zero.';

  @override
  String get measurementProblemTooLarge => 'This figure is too large.';

  @override
  String get measurementProblemUnknown => 'Could not save. Please try again.';

  @override
  String get measurementQuantity => 'Quantity';

  @override
  String get measurementRecentLines => 'Recent';

  @override
  String get measurementRejectedBody =>
      'This entry was rejected and will not count. Make a new entry if the work needs measuring again.';

  @override
  String get measurementSavedOffline =>
      'Saved on phone. It will go when network returns.';

  @override
  String get measurementSavedOnPhone => 'Saved on phone.';

  @override
  String get measurementSearchHint => 'e.g. 2.1 or slab';

  @override
  String get measurementSearchLines => 'Search code or description';

  @override
  String get measurementSendAgain => 'Send again';

  @override
  String measurementSendCount(int count) {
    return 'Send $count for approval';
  }

  @override
  String get measurementSendForApproval => 'Send for approval';

  @override
  String get measurementSendsWhenOnline =>
      'Not sent yet. It will go for approval when network returns.';

  @override
  String get measurementShareOfBoq => 'Share of BOQ';

  @override
  String get measurementSheetAction => 'Sheet PDF';

  @override
  String get measurementSheetFailed => 'Could not get the sheet. Try again.';

  @override
  String get measurementSheetFrom => 'From';

  @override
  String get measurementSheetHint =>
      'Approved measurements for the dates you choose, grouped by BOQ item.';

  @override
  String get measurementSheetNeedsInternet => 'The sheet needs internet.';

  @override
  String get measurementSheetNotAllowed =>
      'This sheet cannot be shared from your account.';

  @override
  String get measurementSheetShare => 'Share PDF';

  @override
  String get measurementSheetTitle => 'Measurement sheet PDF';

  @override
  String get measurementSheetTo => 'To';

  @override
  String get measurementSheetTooLong =>
      'Too many measurements in these dates. Choose a shorter period.';

  @override
  String get measurementStampSent => 'Sent';

  @override
  String get measurementSubmitWaitingTitle => 'Waiting to go for approval';

  @override
  String get measurementSyncAttention => 'Needs a look';

  @override
  String get measurementSyncSending => 'Sending';

  @override
  String measurementTally(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count rows saved on phone',
      one: '1 row saved on phone',
    );
    return '$_temp0';
  }

  @override
  String get measurementYesterday => 'Yesterday';

  @override
  String get pettyCashAddExpense => 'Add expense';

  @override
  String get pettyCashAddMoney => 'Add money';

  @override
  String get pettyCashAmount => 'Amount';

  @override
  String get pettyCashAmountRequired => 'Enter an amount above zero.';

  @override
  String get pettyCashBalance => 'Balance';

  @override
  String pettyCashBalanceAfter(String amount) {
    return 'Balance after this: $amount';
  }

  @override
  String get pettyCashBillAdded => 'Bill photo added';

  @override
  String get pettyCashBillFailed => 'Could not open the camera. Try again.';

  @override
  String get pettyCashBillLabel => 'Bill';

  @override
  String get pettyCashBillRetake => 'Take again';

  @override
  String get pettyCashCategory => 'What was it for?';

  @override
  String get pettyCashCategoryRequired => 'Choose what it was for.';

  @override
  String get pettyCashChooseProject => 'Choose a project';

  @override
  String get pettyCashConfirmed => 'Confirmed';

  @override
  String get pettyCashCreateWallet => 'Create wallet';

  @override
  String get pettyCashDate => 'Date';

  @override
  String get pettyCashDayToday => 'Today';

  @override
  String get pettyCashDayYesterday => 'Yesterday';

  @override
  String get pettyCashEntries => 'Entries';

  @override
  String get pettyCashEntriesEmptyMessage => 'Expenses you add will show here.';

  @override
  String get pettyCashEntriesEmptyTitle => 'No entries yet';

  @override
  String get pettyCashEyebrow => 'Cash book';

  @override
  String get pettyCashFinalAfterSync => 'The final balance comes after sync.';

  @override
  String get pettyCashFormEyebrow => 'New expense';

  @override
  String get pettyCashFormTitle => 'Add an expense';

  @override
  String get pettyCashLoading => 'Loading the cash book';

  @override
  String get pettyCashNoProjectMessage =>
      'Choose a project first, then record expenses here.';

  @override
  String get pettyCashNoProjectTitle => 'No project chosen';

  @override
  String get pettyCashNoWalletManageMessage =>
      'Create one to record site expenses here.';

  @override
  String get pettyCashNoWalletManageTitle => 'No wallet on this project yet';

  @override
  String get pettyCashNoWalletMessage =>
      'Ask your project manager or the accounts office to open one for you.';

  @override
  String get pettyCashNoWalletTitle => 'You have no wallet on this project';

  @override
  String get pettyCashNote => 'Note (optional)';

  @override
  String get pettyCashNoteHint => 'Diesel for the mixer';

  @override
  String get pettyCashOpeningAmount => 'Opening amount';

  @override
  String get pettyCashOpeningHelper => 'Leave blank if there is no money yet.';

  @override
  String get pettyCashOpeningNote => 'Opening balance';

  @override
  String pettyCashOverBalance(String balance) {
    return 'More than the wallet balance of $balance. Ask the office for a top-up.';
  }

  @override
  String get pettyCashPhotoBill => 'Photo of bill';

  @override
  String get pettyCashProvisional => 'Provisional';

  @override
  String get pettyCashSave => 'Save expense';

  @override
  String get pettyCashSaveFailed => 'Could not save the expense. Try again.';

  @override
  String get pettyCashSavedOffline =>
      'Saved on phone. It will send when network returns.';

  @override
  String get pettyCashSavedOnline => 'Saved on phone. Sending now.';

  @override
  String get pettyCashSyncAttention => 'Needs attention';

  @override
  String get pettyCashSyncSending => 'Sending';

  @override
  String get pettyCashTapToChange => 'Tap to change';

  @override
  String get pettyCashTitle => 'Petty cash';

  @override
  String get pettyCashTopUpEyebrow => 'Money into the wallet';

  @override
  String get pettyCashTopUpFailed => 'Could not save the money. Try again.';

  @override
  String get pettyCashTopUpNoteHint => 'Cash from office';

  @override
  String get pettyCashTopUpSave => 'Save money received';

  @override
  String get pettyCashTopup => 'Top-up';

  @override
  String get pettyCashUnsent => 'Not sent yet';

  @override
  String get pettyCashWallet => 'Wallet';

  @override
  String get pettyCashWalletCreateKey => 'Create';

  @override
  String get pettyCashWalletCreated => 'Wallet created';

  @override
  String pettyCashWalletCreatedMoney(String amount) {
    return 'Wallet created. $amount saved on phone, sending now.';
  }

  @override
  String pettyCashWalletCreatedOffline(String amount) {
    return 'Wallet created. $amount saved on phone, sends when network returns.';
  }

  @override
  String pettyCashWalletDefaultName(String code) {
    return '$code site cash';
  }

  @override
  String get pettyCashWalletFailed =>
      'The wallet could not be created. Try again.';

  @override
  String get pettyCashWalletFormEyebrow => 'New wallet';

  @override
  String get pettyCashWalletHolder => 'Who holds the cash?';

  @override
  String get pettyCashWalletMoneyFailed =>
      'Wallet created, but the money was not saved. Add it again.';

  @override
  String get pettyCashWalletName => 'Wallet name';

  @override
  String get pettyCashWalletNameRequired => 'Write a name for the wallet.';

  @override
  String get pettyCashWalletOffline => 'No network.';

  @override
  String get pettyCashWalletOfflineDetail =>
      'A wallet can be created only online.';

  @override
  String get photosClose => 'Close';

  @override
  String get photosDocument => 'PDF, on the website';

  @override
  String get photosNeedNetwork => 'Needs network';

  @override
  String get photosNotSent => 'Not sent';

  @override
  String get photosOpen => 'Open photo';

  @override
  String get photosWaiting => 'Waiting to send';

  @override
  String get portalBillDeductions => 'Deductions';

  @override
  String get portalBillFinal => 'Final bill';

  @override
  String get portalBillGross => 'Gross amount';

  @override
  String get portalBillGst => 'GST';

  @override
  String portalBillLineQty(String qty, String unit, String rate) {
    return '$qty $unit at $rate';
  }

  @override
  String portalBillLineSoFar(String qty, String unit) {
    return 'So far $qty $unit';
  }

  @override
  String get portalBillLines => 'Work billed';

  @override
  String get portalBillLinesOnline =>
      'The lines of this bill load once it has been opened online.';

  @override
  String get portalBillNet => 'Net payable';

  @override
  String get portalBillPeriod => 'Period';

  @override
  String portalBillPeriodRange(String from, String to) {
    return '$from to $to';
  }

  @override
  String get portalBillRetentionReleased => 'Retention released';

  @override
  String portalBillsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count bills',
      one: '1 bill',
    );
    return '$_temp0';
  }

  @override
  String get portalBillsTitle => 'Bills';

  @override
  String portalDaysLeft(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count days left',
      one: '1 day left',
    );
    return '$_temp0';
  }

  @override
  String get portalDeadlineLabel => 'Deadline';

  @override
  String get portalDueToday => 'Due today';

  @override
  String portalLineDone(String done, String total, String unit) {
    return '$done of $total $unit done';
  }

  @override
  String portalLineRate(String rate, String unit) {
    return '$rate per $unit';
  }

  @override
  String portalLineWorth(String done, String total) {
    return 'Worth $done of $total';
  }

  @override
  String get portalLoading => 'Loading your contracts';

  @override
  String get portalNoBillsMessage =>
      'Bills the company has approved will show here.';

  @override
  String get portalNoBillsTitle => 'No bills yet';

  @override
  String get portalNoContractsMessage =>
      'Work orders given to you will show here.';

  @override
  String get portalNoDataMessage =>
      'Connect to the internet once to load your contracts. After that they open offline.';

  @override
  String get portalNoPaymentsMessage =>
      'Payments the company has approved will show here.';

  @override
  String get portalNoPaymentsTitle => 'No payments yet';

  @override
  String get portalNotFoundMessage => 'Connect to the internet to load it.';

  @override
  String get portalNotFoundTitle => 'Not on this phone yet';

  @override
  String portalOverdueBy(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count days late',
      one: '1 day late',
    );
    return '$_temp0';
  }

  @override
  String portalPaidVsWork(String paid, String work) {
    return 'Paid $paid · Work done $work';
  }

  @override
  String portalPaymentsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count payments',
      one: '1 payment',
    );
    return '$_temp0';
  }

  @override
  String get portalPaymentsTitle => 'Payments';

  @override
  String get portalRefusedMessage =>
      'This login cannot open the contractor portal right now. Ask the company that gave you this login.';

  @override
  String get portalRefusedTitle => 'Portal not open';

  @override
  String portalSavedAgo(String ago) {
    return 'Updated $ago';
  }

  @override
  String get portalTitle => 'Contractor portal';

  @override
  String get portalWorkComplete => 'Work complete';

  @override
  String get portalYourContracts => 'All your contracts';

  @override
  String priceWatchAbove(String diff, String pct) {
    return '$diff above the BOQ rate ($pct)';
  }

  @override
  String get priceWatchBasisNorms => 'from the BOQ norms';

  @override
  String get priceWatchBasisTyped => 'quantity typed in for this project';

  @override
  String priceWatchBelow(String diff, String pct) {
    return '$diff below the BOQ rate ($pct)';
  }

  @override
  String get priceWatchBeyond => 'Beyond the margin';

  @override
  String get priceWatchBoqRate => 'BOQ rate';

  @override
  String get priceWatchBoqValue => 'BOQ value';

  @override
  String get priceWatchBudget => 'Budget';

  @override
  String get priceWatchBudgetNoData =>
      'Connect to the internet once to load the budget effect for this project.';

  @override
  String get priceWatchBudgetNote =>
      'If the rest of each material is bought at its latest recorded price instead of the BOQ rate.';

  @override
  String get priceWatchBudgetRefused =>
      'The budget effect is not open to your role.';

  @override
  String get priceWatchBudgetTitle => 'Effect on the budget';

  @override
  String priceWatchChecked(String ago) {
    return 'Checked $ago';
  }

  @override
  String priceWatchDown(String pct, String rate, String date) {
    return 'Down $pct from $rate on $date';
  }

  @override
  String get priceWatchEmptyMessage =>
      'Materials your company watches will show here with their recorded prices.';

  @override
  String get priceWatchEmptyTitle => 'No materials on the board';

  @override
  String get priceWatchFresh => 'From prices not out of date';

  @override
  String get priceWatchGapNoBoqRate => 'No BOQ rate set for this project';

  @override
  String get priceWatchGapNoQty => 'No quantity to work it out from';

  @override
  String get priceWatchGapUnit => 'The price unit does not match the BOQ unit';

  @override
  String get priceWatchHistory => 'Every recorded price';

  @override
  String get priceWatchLatest => 'Latest';

  @override
  String priceWatchLess(String amount) {
    return '$amount less';
  }

  @override
  String get priceWatchLevel => 'Same as the BOQ rate';

  @override
  String get priceWatchLoading => 'Loading recorded prices';

  @override
  String get priceWatchMaterialMissing => 'This material is not on the board.';

  @override
  String priceWatchMore(String amount) {
    return '$amount more';
  }

  @override
  String get priceWatchNoDataMessage =>
      'Connect to the internet once to load the recorded prices. After that they open offline.';

  @override
  String get priceWatchNoEffect => 'No effect';

  @override
  String get priceWatchNone => 'No price recorded yet';

  @override
  String get priceWatchNote =>
      'Prices your company wrote down, each with the date it was recorded. Nothing here updates by itself.';

  @override
  String priceWatchNotedBy(String name) {
    return 'Noted by $name';
  }

  @override
  String get priceWatchOnlyOne => 'Only one price recorded';

  @override
  String priceWatchPerUnit(String unit) {
    return 'per $unit';
  }

  @override
  String get priceWatchProjected => 'BOQ at these prices';

  @override
  String priceWatchRecordedOn(String date) {
    return 'Recorded $date';
  }

  @override
  String get priceWatchRecordedRate => 'Latest recorded';

  @override
  String get priceWatchRefusedMessage =>
      'Recorded prices are not open to your role. Ask your office if you need them.';

  @override
  String get priceWatchRefusedTitle => 'Not open to your role';

  @override
  String priceWatchSame(String rate, String date) {
    return 'Same as $rate on $date';
  }

  @override
  String get priceWatchShareBoq => 'Share of the BOQ value';

  @override
  String get priceWatchShareBudget => 'Share of the budget';

  @override
  String priceWatchStale(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: 'Older than $days days',
      one: 'Older than 1 day',
    );
    return '$_temp0';
  }

  @override
  String priceWatchStillToBuy(String qty, String unit) {
    return 'Still to buy $qty $unit';
  }

  @override
  String get priceWatchTabBudget => 'Effect on budget';

  @override
  String get priceWatchTabPrices => 'Prices';

  @override
  String get priceWatchTile => 'Prices';

  @override
  String get priceWatchTitle => 'Recorded prices';

  @override
  String priceWatchUp(String pct, String rate, String date) {
    return 'Up $pct from $rate on $date';
  }

  @override
  String get priceWatchWithin => 'Within the margin';

  @override
  String get procurementAccepted => 'Received and accepted';

  @override
  String get procurementActivity => 'Or the activity';

  @override
  String get procurementActivityHint => 'Such as slab 3 shuttering';

  @override
  String get procurementAddItem => 'Add another item';

  @override
  String get procurementForBoq => 'BOQ item';

  @override
  String procurementInStore(String qty) {
    return 'In this store $qty';
  }

  @override
  String get procurementIssueStock => 'Issue from store';

  @override
  String get procurementIssuedTo => 'Issued to (optional)';

  @override
  String get procurementIssuedToHint => 'A person or a gang';

  @override
  String procurementItemN(int number) {
    return 'Item $number';
  }

  @override
  String get procurementNoOrdersMessage =>
      'An order shows here once the office has sent it to the supplier and goods can be received against it.';

  @override
  String get procurementNoOrdersTitle => 'No open purchase orders';

  @override
  String get procurementNoReceiptsTitle => 'No receipts yet';

  @override
  String get procurementNoStoreMessage =>
      'Ask your office to add a store on the website, then try again.';

  @override
  String get procurementNoStoreTitle => 'No store on this project';

  @override
  String get procurementNothingReceived =>
      'Enter what was accepted on at least one line';

  @override
  String get procurementOpenOrders => 'Open orders';

  @override
  String get procurementOrder => 'Purchase order';

  @override
  String get procurementOrderChoose => 'Choose purchase order';

  @override
  String procurementOrderDue(int count) {
    return '$count items still due';
  }

  @override
  String get procurementOrderRequired => 'Choose the purchase order';

  @override
  String get procurementOrderSearch => 'Search by number or supplier';

  @override
  String procurementOrdered(String qty) {
    return 'Ordered $qty';
  }

  @override
  String procurementOverDue(String qty) {
    return 'Only $qty is still due on this order';
  }

  @override
  String get procurementPartialNote =>
      'Part of an order is fine. Leave a line empty if nothing came on it, and the order stays open for the rest.';

  @override
  String get procurementQtyInvalid => 'Enter a number';

  @override
  String get procurementReceiveOrder => 'Receive against order';

  @override
  String get procurementReceivedHeading => 'Received';

  @override
  String get procurementRejected => 'Turned away (optional)';

  @override
  String procurementRejectedFigure(String qty) {
    return 'Turned away $qty';
  }

  @override
  String get procurementRejectedHint =>
      'Goods you did not accept. They are not added to stock and are still due from the supplier.';

  @override
  String get procurementRejectedOnly =>
      'Turned-away goods need some accepted quantity on the same line. For a delivery that was all turned away, use the website.';

  @override
  String get procurementRemarks => 'Note (optional)';

  @override
  String get procurementRemarksHint => 'Such as why goods were turned away';

  @override
  String get procurementRemoveItem => 'Remove';

  @override
  String get procurementReversed => 'Reversed';

  @override
  String procurementStillDue(String qty) {
    return 'Still due $qty';
  }

  @override
  String get procurementTabReceipts => 'Order receipts';

  @override
  String get procurementTooManyDecimals => 'Use at most 3 decimal places';

  @override
  String get procurementWorkRequired =>
      'Choose a BOQ item or write the activity';

  @override
  String get progressAdd => 'Add today\'s work';

  @override
  String get progressBeforeFinishing => 'Before finishing check';

  @override
  String progressBehind(String percent) {
    return 'Behind by $percent';
  }

  @override
  String get progressCorrect => 'Correct';

  @override
  String get progressCorrectFailed =>
      'Could not save the correction. Please try again.';

  @override
  String get progressCorrectHint =>
      'The first entry stays. A correction is added beside it.';

  @override
  String progressCorrectNow(String qty) {
    return 'Counted now: $qty';
  }

  @override
  String get progressCorrectQty => 'What it should have been';

  @override
  String get progressCorrectQtyInvalid => 'Enter a quantity from zero up';

  @override
  String get progressCorrectRemark => 'Why is it being corrected?';

  @override
  String get progressCorrectRemarkRequired => 'Say why it is being corrected';

  @override
  String get progressCorrectSame => 'That is already the quantity';

  @override
  String get progressCorrectSave => 'Save correction';

  @override
  String get progressCorrectSavedOffline =>
      'Correction saved on this phone. It will send when you are online.';

  @override
  String get progressCorrectSavedOnline => 'Correction saved. Sending now.';

  @override
  String get progressCorrectTakenBack => 'Correction taken back.';

  @override
  String get progressCorrectTitle => 'Correct this entry';

  @override
  String get progressCorrectionNotSent => 'Correction not sent yet';

  @override
  String progressEntryBy(String name) {
    return 'by $name';
  }

  @override
  String progressEntryCorrectedFrom(String qty) {
    return 'Corrected from $qty';
  }

  @override
  String get progressFinishingHint =>
      'Read this before plaster, POP or paint starts.';

  @override
  String get progressFinishingPending => 'Pending work';

  @override
  String get progressFinishingUnfinished => 'Not finished yet';

  @override
  String get progressFloorBalance => 'Balance';

  @override
  String get progressFloorDone => 'Done';

  @override
  String get progressFloorFlats => 'Flats';

  @override
  String get progressFloorLines => 'Work on this floor';

  @override
  String get progressFloorNoLines =>
      'No quantities planned for this floor yet.';

  @override
  String get progressFloorOffline =>
      'Connect to the internet once to see this floor.';

  @override
  String get progressFloorPendingHeading => 'Pending here';

  @override
  String get progressFloorPlanned => 'Planned';

  @override
  String get progressFloorStages => 'Plumbing and electrical stages';

  @override
  String get progressFloorTitle => 'Floor progress';

  @override
  String progressFormBalance(String qty) {
    return 'Balance $qty';
  }

  @override
  String get progressFormDate => 'Date';

  @override
  String get progressFormFloor => 'Floor or flat';

  @override
  String get progressFormFloorNone => 'Whole project';

  @override
  String get progressFormItem => 'BOQ item';

  @override
  String get progressFormItemChoose => 'Choose an item';

  @override
  String get progressFormItemNone => 'No item matches';

  @override
  String get progressFormItemSearch => 'Search by code or name';

  @override
  String get progressFormKindPending => 'Only pending';

  @override
  String get progressFormKindWork => 'Work done';

  @override
  String get progressFormOverBalance =>
      'This is more than the BOQ balance. You can still save it.';

  @override
  String get progressFormPendingRemark => 'What is pending?';

  @override
  String get progressFormPhoto => 'Add photo';

  @override
  String get progressFormQty => 'Quantity done';

  @override
  String get progressFormQtyRequired => 'Enter the quantity done';

  @override
  String get progressFormRemark => 'Remark (optional)';

  @override
  String get progressFormRemarkRequired => 'Say what is pending';

  @override
  String get progressFormRemovePhoto => 'Remove photo';

  @override
  String get progressFormSave => 'Save work';

  @override
  String get progressFormTitle => 'Add work';

  @override
  String get progressFormToday => 'Today';

  @override
  String get progressFormWhose => 'Whose work is this?';

  @override
  String get progressFormWhoseRequired => 'Choose the contractor';

  @override
  String get progressFormYesterday => 'Yesterday';

  @override
  String get progressLoading => 'Loading progress';

  @override
  String get progressNoDataMessage =>
      'Connect to the internet once to load this project\'s progress. After that it works offline.';

  @override
  String get progressNoDataTitle => 'Not loaded yet';

  @override
  String get progressNoFloorsMessage =>
      'Ask your project manager to add floors and flats.';

  @override
  String get progressNoFloorsTitle => 'No floors added yet';

  @override
  String get progressNoPendingMessage =>
      'Items marked pending on site will show here.';

  @override
  String get progressNoPendingTitle => 'Nothing pending';

  @override
  String get progressNoProjectMessage => 'Choose a project first.';

  @override
  String get progressNoProjectTitle => 'No project selected';

  @override
  String get progressNotSent => 'Not sent yet';

  @override
  String get progressOnTrack => 'On track';

  @override
  String progressPendingCount(int count) {
    return '$count pending';
  }

  @override
  String get progressReadyMessage =>
      'All plumbing and electrical stages are done here.';

  @override
  String get progressReadyTitle => 'Ready for finishing';

  @override
  String get progressRecentEntries => 'Recent entries on this floor';

  @override
  String get progressRefreshFailed =>
      'Could not refresh. Showing what this phone has.';

  @override
  String get progressResolveRemark => 'Remark (optional)';

  @override
  String get progressResolveSave => 'Resolve';

  @override
  String get progressResolveTitle => 'Mark as resolved';

  @override
  String get progressResolveWait =>
      'This entry has not reached the office yet. Try again after it is sent.';

  @override
  String get progressResolved => 'Marked as resolved';

  @override
  String get progressSaveFailed => 'Could not save. Please try again.';

  @override
  String get progressSavedOffline =>
      'Saved on this phone. It will send when you are online.';

  @override
  String get progressSavedOnline => 'Saved. Sending now.';

  @override
  String get progressStageEarlierPending => 'An earlier stage is still pending';

  @override
  String get progressStageNote => 'Note (optional)';

  @override
  String get progressStageSave => 'Save';

  @override
  String get progressStageSaved => 'Stage saved';

  @override
  String get progressStageStatus => 'Status';

  @override
  String progressStagesDone(int done, int total) {
    return '$done of $total done';
  }

  @override
  String get progressTabFloors => 'Floors';

  @override
  String get progressTabPending => 'Pending';

  @override
  String get progressTabStages => 'Stages';

  @override
  String progressTimeUsed(String percent) {
    return 'Time used $percent';
  }

  @override
  String get progressTitle => 'Site progress';

  @override
  String get progressWeekDone => 'Done this week';

  @override
  String progressWeekEntries(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count entries',
      one: '1 entry',
    );
    return '$_temp0';
  }

  @override
  String get progressWeekFailed => 'Could not load the week.';

  @override
  String progressWeekLeft(String left, String unit, String percent) {
    return '$left $unit left · $percent done';
  }

  @override
  String progressWeekMore(int count) {
    return 'And $count more';
  }

  @override
  String get progressWeekNext => 'Next week';

  @override
  String progressWeekNotStarted(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count BOQ lines not started',
      one: '1 BOQ line not started',
    );
    return '$_temp0';
  }

  @override
  String get progressWeekNothingDone => 'No work entered this week.';

  @override
  String get progressWeekOffline =>
      'No internet. The weekly view needs a connection.';

  @override
  String get progressWeekOpen => 'This week';

  @override
  String get progressWeekPending => 'Pending';

  @override
  String get progressWeekPrevious => 'Previous week';

  @override
  String progressWeekQty(String qty, String unit) {
    return '$qty $unit this week';
  }

  @override
  String progressWeekRange(String start, String end) {
    return '$start to $end';
  }

  @override
  String get progressWeekTitle => 'Week';

  @override
  String get progressWeekUnderWay => 'Under way';

  @override
  String get progressWorkDone => 'Work done';

  @override
  String get projectEditLoading => 'Opening the project…';

  @override
  String get projectEditOffline =>
      'A project can only be changed with network. Try again when you have signal.';

  @override
  String get projectEditRowSubtitle => 'Name, place, dates and value by phase';

  @override
  String get projectEditSave => 'Save changes';

  @override
  String get projectEditSaving => 'Saving…';

  @override
  String get projectEditTitle => 'Edit project';

  @override
  String get projectPhaseAdd => 'Add phase';

  @override
  String get projectPhaseAmount => 'Amount';

  @override
  String get projectPhaseAmountRequired => 'Enter the amount';

  @override
  String get projectPhaseName => 'Phase name';

  @override
  String get projectPhaseNameHint => 'For example Foundation';

  @override
  String get projectPhaseNameRequired => 'Name this phase';

  @override
  String projectPhaseNumbered(int number) {
    return 'Phase $number';
  }

  @override
  String projectPhaseRemove(String phase) {
    return 'Remove $phase';
  }

  @override
  String projectPhaseShare(String share) {
    return '$share of the total';
  }

  @override
  String get projectPhaseTotal => 'Total';

  @override
  String get projectPhasesHint =>
      'Enter the value phase by phase. Each phase\'s share is worked out for you.';

  @override
  String get pushPrefsAlerts => 'Site alerts';

  @override
  String get pushPrefsAlertsHint =>
      'New alerts on your projects, such as work falling behind. Critical ones reach you at any hour.';

  @override
  String get pushPrefsAnnouncements => 'Announcements and reminders';

  @override
  String get pushPrefsAnnouncementsHint =>
      'News and reminders from your company or from Nirmaan360. They wait for quiet hours to end.';

  @override
  String get pushPrefsApprovals => 'Approvals';

  @override
  String get pushPrefsApprovalsHint =>
      'Something waits for your approval, or a decision was made on what you sent.';

  @override
  String get pushPrefsContractor => 'My payments and bills';

  @override
  String get pushPrefsContractorHint =>
      'A payment to you was approved, or one of your bills was paid.';

  @override
  String get pushPrefsEmail => 'Email';

  @override
  String get pushPrefsEmailHint => 'The same updates by email.';

  @override
  String get pushPrefsFailedMessage => 'Try again in a moment.';

  @override
  String get pushPrefsFailedTitle => 'Could not read your settings';

  @override
  String get pushPrefsIssues => 'Issues';

  @override
  String get pushPrefsIssuesHint =>
      'An issue was assigned to you, raised as urgent, or changed status.';

  @override
  String get pushPrefsLoading => 'Reading your notification settings';

  @override
  String get pushPrefsMaterial => 'Material checks';

  @override
  String get pushPrefsMaterialHint =>
      'A material gap or a short delivery needs a written reason.';

  @override
  String get pushPrefsNotConnected =>
      'The office has not connected notifications yet. Your choices are kept for when it does.';

  @override
  String get pushPrefsOfflineMessage =>
      'Your notification settings are kept on the server. Connect and try again.';

  @override
  String get pushPrefsOfflineTitle => 'Needs the internet';

  @override
  String get pushPrefsOtherSection => 'Other ways to reach you';

  @override
  String get pushPrefsPayments => 'Contractor payments';

  @override
  String get pushPrefsPaymentsHint =>
      'A contractor payment needs your approval, or one you raised was decided.';

  @override
  String get pushPrefsPush => 'Push notifications';

  @override
  String get pushPrefsPushHint =>
      'For all your phones. Off stops every kind below.';

  @override
  String get pushPrefsQuiet => 'Hold notifications at night';

  @override
  String get pushPrefsQuietFrom => 'From';

  @override
  String get pushPrefsQuietHint =>
      'Between these hours they wait for the morning. Critical site alerts still come at once.';

  @override
  String get pushPrefsQuietSection => 'Quiet hours';

  @override
  String get pushPrefsQuietUntil => 'Until';

  @override
  String get pushPrefsRetry => 'Try again';

  @override
  String get pushPrefsSaveFailed =>
      'Not saved. Check the internet and try again.';

  @override
  String get pushPrefsSection => 'On your phones';

  @override
  String get pushPrefsTitle => 'Notifications';

  @override
  String get pushPrefsWhatsapp => 'WhatsApp';

  @override
  String get pushPrefsWhatsappHint => 'The same updates on WhatsApp.';

  @override
  String get pushPromptBody =>
      'We tell you when something waits for your approval, when a report or measurement is sent back to you, and when a site alert opens. You can change this any time in Settings.';

  @override
  String get pushPromptBodyContractor =>
      'We tell you when a payment to you is approved and when one of your bills is paid. You can change this any time in Settings.';

  @override
  String get pushPromptNotNow => 'Not now';

  @override
  String get pushPromptTitle => 'Get alerts on this phone?';

  @override
  String get pushPromptTurnOn => 'Turn on alerts';

  @override
  String get pushSettingsBlocked =>
      'Open your phone\'s Settings, allow notifications for Nirmaan360, then come back. Tap Turn on to check again.';

  @override
  String get pushSettingsBlockedShort => 'Not allowed on this phone';

  @override
  String get pushSettingsIsOn => 'On';

  @override
  String get pushSettingsOff => 'Off. Tap to turn on.';

  @override
  String get pushSettingsOn => 'On. Approvals and alerts reach this phone.';

  @override
  String get pushSettingsOnContractor =>
      'On. Payments and bills reach this phone.';

  @override
  String get pushSettingsTitle => 'Phone alerts';

  @override
  String get pushSettingsTurnOn => 'Turn on';

  @override
  String get readerCompanyLimit =>
      'The AI reader is switched off for your company. Please contact support.';

  @override
  String get readerConsentRequired =>
      'Your company has not agreed to the notice for sending documents to the AI service. An owner or admin can do that in Settings on the website.';

  @override
  String get readerDailyBudget =>
      'Your company\'s AI budget for today is used up. Try again tomorrow.';

  @override
  String get readerNeedsInternet => 'Reading a document needs the internet.';

  @override
  String get readerNoCredits => 'Your company has no AI credits left.';

  @override
  String get readerNotConnected =>
      'The AI service is not available right now. Try again later.';

  @override
  String get readerNotReleased => 'The AI reader is not switched on yet.';

  @override
  String readerOverLimit(int count) {
    return 'Only $count pages fit the limit. Choose fewer pages.';
  }

  @override
  String get readerPagesMissing =>
      'Some pages are not sent yet. Send them, then start again.';

  @override
  String get readerPausedMonth => 'The AI reader is paused for this month.';

  @override
  String get readerPausedToday =>
      'The AI reader is paused for today. Try again tomorrow.';

  @override
  String get readerPhotoMirrored =>
      'This photo is saved mirrored. Take it again.';

  @override
  String get readerPhotoTooSmall =>
      'This photo is too small to read. Take it closer.';

  @override
  String get readerPhotoUnreadable =>
      'This photo cannot be read. Take it again.';

  @override
  String get readerProblem => 'Something went wrong. Try again.';

  @override
  String get readerRetriesUsed => 'This page cannot be read again.';

  @override
  String get readerServerBusy =>
      'The server cannot process pictures right now. Nothing was stored. Try again later.';

  @override
  String get readerStillWorking => 'Still working. Pull to refresh.';

  @override
  String get readerTooManyRequests =>
      'Too many requests. Wait a minute and try again.';

  @override
  String get readerWrongState =>
      'This cannot be done with the document as it is now. Refresh and try again.';

  @override
  String scanAlready(String name, String status) {
    return '$name is already marked $status today';
  }

  @override
  String get scanAlreadyUnknown => 'Already marked today';

  @override
  String get scanCameraFailedMessage =>
      'Close this page and open it again. You can still mark attendance by hand.';

  @override
  String get scanCameraFailedTitle => 'The camera did not start';

  @override
  String get scanCameraOffMessage =>
      'Allow the camera for Nirmaan360 in your phone\'s settings, then come back. You can still mark attendance by hand.';

  @override
  String get scanCameraOffTitle => 'The camera is off';

  @override
  String get scanCameraStarting => 'Starting the camera';

  @override
  String scanCount(int count) {
    return '$count scanned in this sitting';
  }

  @override
  String get scanFailed => 'Not marked. Try again.';

  @override
  String get scanHint => 'Hold the camera over the QR on a worker\'s card.';

  @override
  String scanMarked(String name) {
    return '$name marked present';
  }

  @override
  String get scanMarkedUnknown => 'Marked present';

  @override
  String get scanNotACard => 'This is not a worker card.';

  @override
  String scanQueued(String name) {
    return 'Saved on this phone. $name will be marked when you are online.';
  }

  @override
  String get scanQueuedNote =>
      'A saved scan is sent today only. After today, mark that day by hand.';

  @override
  String get scanQueuedUnknown =>
      'Saved on this phone. It will be marked when you are online.';

  @override
  String get scanStatusAbsent => 'absent';

  @override
  String get scanStatusHalf => 'half day';

  @override
  String get scanStatusOvertime => 'overtime';

  @override
  String get scanStatusPresent => 'present';

  @override
  String get scanTitle => 'Scan worker card';

  @override
  String get selfieConsentAgree => 'The worker agrees';

  @override
  String get selfieConsentAsk =>
      'Read this to the worker. Take the photo only if they agree.';

  @override
  String get selfieConsentNeedsNetwork =>
      'Connect to the internet once to load the consent note. It has to be shown before the first photo.';

  @override
  String get selfieConsentRead => 'Read the consent note';

  @override
  String get selfieConsentTitle => 'Before the first photo';

  @override
  String selfieConsentVersion(String version) {
    return 'Consent note $version';
  }

  @override
  String get selfieFailed => 'Could not save. Please try again.';

  @override
  String get selfieIntro =>
      'Take a photo of the worker at the site. The phone notes where it was taken, and the server checks that it was near the site.';

  @override
  String get selfieLocating => 'Finding your location';

  @override
  String get selfieLocationDenied =>
      'Location is off for this app. Turn it on in your phone\'s settings, then take the photo again. Without it the photo cannot be checked.';

  @override
  String get selfieLocationFailed =>
      'Could not find your location. Move to open sky and try again.';

  @override
  String selfieLocationNote(String metres) {
    return 'Location noted, accurate to about $metres m';
  }

  @override
  String get selfieLocationOff =>
      'The phone\'s location is switched off. Switch it on, then take the photo again.';

  @override
  String get selfieLocationWhy =>
      'The phone asks for your location only now, to note where this photo was taken. It is not tracked in the background.';

  @override
  String selfieMarked(String name, int distance, int radius) {
    return '$name marked present. $distance m from the site centre, $radius m allowed.';
  }

  @override
  String selfieMarkedPlain(String name) {
    return '$name marked present';
  }

  @override
  String get selfieNoCamera =>
      'The camera could not open. Allow the camera in your phone\'s settings.';

  @override
  String get selfieNoSiteMessage =>
      'A photo is checked against a site\'s map position. Ask your office to add a site on the website.';

  @override
  String get selfieNoSiteTitle => 'This project has no site';

  @override
  String get selfiePhotoRequired => 'Take the photo first';

  @override
  String selfieQueued(String name) {
    return 'Saved on this phone. $name will be marked when you are online, and the server will check the location then.';
  }

  @override
  String get selfieQueuedNote =>
      'A saved photo is sent within 7 days. After that the server refuses it.';

  @override
  String selfieRefused(String reason) {
    return 'Not marked: $reason';
  }

  @override
  String get selfieRetake => 'Take again';

  @override
  String get selfieSend => 'Mark present';

  @override
  String get selfieSite => 'Site';

  @override
  String get selfieTakePhoto => 'Take photo';

  @override
  String get selfieTitle => 'Attendance by photo';

  @override
  String get selfieWorker => 'Worker';

  @override
  String get selfieWorkerChoose => 'Choose worker';

  @override
  String get selfieWorkerRequired => 'Choose the worker';

  @override
  String get selfieWorkerSearch => 'Search worker';

  @override
  String get shellCaptionMarked => 'marked';

  @override
  String get shellCaptionOpen => 'open';

  @override
  String get shellCaptionToAnswer => 'to answer';

  @override
  String get shellCaptionToday => 'today';

  @override
  String get shellCaptionWorkDone => 'work done';

  @override
  String get shellCopyLink => 'Copy link';

  @override
  String get shellDiscardBody =>
      'It has not reached the server. It will be removed from this phone.';

  @override
  String get shellDiscardConfirm => 'Yes, throw away';

  @override
  String get shellDiscardKeep => 'Keep it';

  @override
  String get shellDiscardTitle => 'Throw away this entry?';

  @override
  String get shellLinkCopied => 'Link copied';

  @override
  String get shellLinkFailedBody =>
      'Copy the link and open it in your browser.';

  @override
  String get shellLinkFailedTitle => 'The page did not open';

  @override
  String get shellOfflineApprovals => 'Approvals need network.';

  @override
  String get shellOfflineDetail => 'Entries wait on phone.';

  @override
  String get shellOfflineLabel => 'Offline';

  @override
  String get shellOutboxAllSentBody => 'Nothing is left on this phone.';

  @override
  String get shellOutboxAllSentStamp => 'All sent';

  @override
  String shellOutboxAttention(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count need your attention',
      one: '1 needs your attention',
    );
    return '$_temp0';
  }

  @override
  String get shellOutboxLoading => 'Loading the outbox';

  @override
  String get shellOutboxLogTitle => 'Not applied';

  @override
  String get shellOutboxNeverSynced => 'Not synced yet';

  @override
  String get shellOutboxServerError =>
      'The server is having trouble. Your work is safe and will send later.';

  @override
  String get shellOutboxTitle => 'Outbox';

  @override
  String get shellOutboxTrayLabel => 'on phone';

  @override
  String get shellOutboxWaitingLabel => 'waiting to send';

  @override
  String get shellPickerCurrent => 'Current';

  @override
  String get shellPickerEmptyBody =>
      'Ask your office to add you to a project, then try again.';

  @override
  String get shellPickerEmptyTitle => 'No projects yet';

  @override
  String get shellPickerEyebrow => 'Your projects';

  @override
  String get shellPickerFirstBody =>
      'Add your project and run its work from this phone.';

  @override
  String get shellPickerFirstButton => 'Create your first project';

  @override
  String get shellPickerFirstTitle => 'Let\'s set up your first site';

  @override
  String get shellPickerLoading => 'Getting your projects…';

  @override
  String get shellPickerTitle => 'Which site today?';

  @override
  String get shellPillAllSent => 'All sent';

  @override
  String get shellPillAttention => 'needs a look';

  @override
  String get shellPillSending => 'sending';

  @override
  String get shellPillWaiting => 'waiting';

  @override
  String get shellProgressBasis => 'By value of approved work';

  @override
  String get shellProgressDaysLate => 'Days late';

  @override
  String get shellProgressDaysLeft => 'Days left';

  @override
  String get shellProgressEmptyBody =>
      'Progress will show here once the BOQ is added.';

  @override
  String get shellProgressEmptyTitle => 'No BOQ yet';

  @override
  String get shellProgressEnd => 'End';

  @override
  String get shellProgressNoEnd => 'Not set';

  @override
  String get shellProgressNothingYet => 'No approved work yet';

  @override
  String shellProgressSemantics(String percent) {
    return '$percent percent complete';
  }

  @override
  String get shellProgressTitle => 'Construction progress';

  @override
  String get shellProjectChoose => 'Choose';

  @override
  String get shellProjectCreate => 'Create project';

  @override
  String get shellProjectCreating => 'Creating…';

  @override
  String get shellProjectEnd => 'End date';

  @override
  String get shellProjectEndBeforeStart =>
      'End date must be after the start date';

  @override
  String get shellProjectFormTitle => 'New project';

  @override
  String get shellProjectLocation => 'Location';

  @override
  String get shellProjectName => 'Project name';

  @override
  String get shellProjectNameRequired => 'Enter a name for the project';

  @override
  String get shellProjectOffline =>
      'A project can only be created with network. Try again when you have signal.';

  @override
  String get shellProjectPlanBody =>
      'This company has reached the number of projects its plan allows.';

  @override
  String get shellProjectPlanTitle => 'Project limit reached';

  @override
  String get shellProjectStart => 'Start date';

  @override
  String get shellProjectValue => 'Contract value (optional)';

  @override
  String get shellProjectValueEmpty => 'Tap to enter';

  @override
  String get shellReportAttention => 'Needs a look';

  @override
  String get shellReportNotStarted => 'Not started';

  @override
  String get shellReportSaved => 'Saved';

  @override
  String shellReturnedDpr(String date) {
    return 'Daily report · $date';
  }

  @override
  String get shellReturnedFix => 'Fix';

  @override
  String shellReturnedMeasurement(String code, String qty, String unit) {
    return 'Measurement $code · $qty $unit';
  }

  @override
  String get shellReturnedNoRemark => 'No remark given';

  @override
  String get shellReturnedRemarkLabel => 'Remark';

  @override
  String get shellReturnedTitle => 'Returned to you';

  @override
  String get shellSettings => 'Settings';

  @override
  String get shellSettingsLanguage => 'Language';

  @override
  String get shellSettingsSignedIn => 'Signed in as';

  @override
  String get shellSettingsVersion => 'App version';

  @override
  String get shellSetupBoq => 'BOQ items';

  @override
  String shellSetupNext(String step) {
    return 'Next: $step';
  }

  @override
  String shellSetupStepSemantics(String step, int count) {
    return '$step: $count';
  }

  @override
  String get shellSetupTitle => 'Set up your site';

  @override
  String get shellSetupWallet => 'Petty cash wallet';

  @override
  String get shellSetupWorkers => 'Workers';

  @override
  String get shellSignInBusy => 'Signing in…';

  @override
  String get shellSignInButton => 'Sign in';

  @override
  String get shellSignInEmail => 'Email';

  @override
  String get shellSignInEmailRequired => 'Enter your email';

  @override
  String get shellSignInExpired =>
      'You were signed out. Nothing is lost: your work is safe on this phone. Sign in to send it.';

  @override
  String get shellSignInHide => 'Hide';

  @override
  String get shellSignInNetworkBody => 'Check your signal and try again.';

  @override
  String get shellSignInNetworkTitle => 'No network';

  @override
  String get shellSignInNewCompany => 'New company? Create an account';

  @override
  String shellSignInOtherUserBody(int count) {
    return 'This phone has $count unsent entries from another person. They must sign in and send them first.';
  }

  @override
  String get shellSignInOtherUserTitle => 'Someone else\'s work is waiting';

  @override
  String get shellSignInPassword => 'Password';

  @override
  String get shellSignInPasswordRequired => 'Enter your password';

  @override
  String get shellSignInServerBody =>
      'It is not your phone. Try again in a few minutes.';

  @override
  String get shellSignInServerTitle => 'Server is not responding';

  @override
  String get shellSignInShow => 'Show';

  @override
  String get shellSignInTooManyBody => 'Wait a minute and try again.';

  @override
  String get shellSignInTooManyTitle => 'Too many tries';

  @override
  String get shellSignInWelcome => 'Welcome';

  @override
  String get shellSignInWrongBody => 'Check both and try again.';

  @override
  String get shellSignInWrongTitle => 'Wrong email or password';

  @override
  String get shellSignOut => 'Sign out';

  @override
  String get shellSignOutConfirmBody =>
      'You will need network to sign in again.';

  @override
  String get shellSignOutConfirmTitle => 'Sign out of this phone?';

  @override
  String get shellSignOutDiscard => 'Sign out and lose them';

  @override
  String get shellSignOutHold => 'Press and hold to lose them';

  @override
  String get shellSignOutSendFirst => 'Send them first';

  @override
  String get shellSignOutUnsentBody =>
      'If you sign out now they are deleted from this phone and cannot be brought back. Send them first.';

  @override
  String shellSignOutUnsentTitle(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count entries not sent',
      one: '1 entry not sent',
    );
    return '$_temp0';
  }

  @override
  String get shellSignUpBusy => 'Creating your account…';

  @override
  String get shellSignUpButton => 'Create account';

  @override
  String get shellSignUpClosedBody => 'Try again later.';

  @override
  String get shellSignUpClosedTitle =>
      'New companies can\'t be started from the app right now';

  @override
  String get shellSignUpCompany => 'Company name';

  @override
  String get shellSignUpCompanyMissing => 'Enter the company name';

  @override
  String get shellSignUpConfirm => 'Confirm password';

  @override
  String shellSignUpConsent(String terms, String privacy) {
    return 'By creating an account you agree to the $terms and the $privacy.';
  }

  @override
  String get shellSignUpEmailInvalid => 'Enter an email like name@example.com';

  @override
  String get shellSignUpEmailTakenBody =>
      'Sign in with it instead, or use another email.';

  @override
  String get shellSignUpEmailTakenTitle => 'This email already has an account';

  @override
  String get shellSignUpFieldInvalid => 'Check this and try again';

  @override
  String get shellSignUpHaveAccount => 'Already have an account? Sign in';

  @override
  String get shellSignUpIntro =>
      'This starts a new company on Nirmaan360 with you as its owner. If your company already uses Nirmaan360, ask your office to invite you instead.';

  @override
  String get shellSignUpName => 'Your name';

  @override
  String get shellSignUpNameMissing => 'Enter your name';

  @override
  String get shellSignUpPasswordHelper => 'At least 8 characters.';

  @override
  String get shellSignUpPasswordMismatch =>
      'The two passwords are not the same';

  @override
  String get shellSignUpPasswordShort => 'Use at least 8 characters';

  @override
  String get shellSignUpPhone => 'Mobile number (optional)';

  @override
  String get shellSignUpPhoneTaken =>
      'This mobile number is used by another account.';

  @override
  String get shellSignUpPrivacy => 'Privacy policy';

  @override
  String get shellSignUpTerms => 'Terms';

  @override
  String get shellSignUpTitle => 'Create an account';

  @override
  String shellSignUpTooLong(int max) {
    return 'Keep it to $max characters or fewer';
  }

  @override
  String get shellSlipSending => 'Sending';

  @override
  String get shellSlipWaiting => 'Waiting';

  @override
  String get shellSwitchProject => 'Switch project';

  @override
  String get shellSyncingLabel => 'Sending and updating';

  @override
  String get shellTabApprovals => 'Approvals';

  @override
  String get shellTabHome => 'Home';

  @override
  String get shellTabMeasure => 'Measure';

  @override
  String get shellTabOutbox => 'Outbox';

  @override
  String get shellTileApprovals => 'Approvals';

  @override
  String get shellTileAttendance => 'Attendance';

  @override
  String get shellTileDaily => 'Daily report';

  @override
  String get shellTileExpenses => 'Expenses';

  @override
  String get shellTileIssues => 'Issues';

  @override
  String get shellTileMaterial => 'Material';

  @override
  String get shellTileMeasure => 'Measure';

  @override
  String get shellTilePetty => 'Petty cash';

  @override
  String get shellTileProgress => 'Progress';

  @override
  String shellTimeDays(int count) {
    return '$count days ago';
  }

  @override
  String shellTimeHours(int count) {
    return '$count hr ago';
  }

  @override
  String get shellTimeJustNow => 'just now';

  @override
  String shellTimeMinutes(int count) {
    return '$count min ago';
  }

  @override
  String get shellTimeYesterday => 'yesterday';

  @override
  String get shellTodayAttendance => 'Attendance';

  @override
  String get shellTodayExpenses => 'Spent';

  @override
  String get shellTodayMeasured => 'Measured';

  @override
  String get shellTodayReport => 'Report';

  @override
  String shellTodaySemantics(String label, String value) {
    return '$label: $value';
  }

  @override
  String get siteToolsInspectionsHint =>
      'Fill a checklist for a pour, shuttering or a safety round';

  @override
  String get siteToolsMachinesHint =>
      'Record what a machine worked today, in hours or km';

  @override
  String get siteToolsTitle => 'Site tools';

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
  String get syncReasonProjectGone =>
      'You no longer have access to this project. Ask your office to add you back, then try again.';

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
  String get syncTypeAttendanceScan => 'Card scan';

  @override
  String get syncTypeAttendanceSelfie => 'Attendance photo';

  @override
  String get syncTypeContractorPayment => 'Contractor payment request';

  @override
  String get syncTypeDpr => 'Daily report';

  @override
  String get syncTypeDprPhoto => 'Report photo';

  @override
  String get syncTypeDprSubmit => 'Report submission';

  @override
  String get syncTypeGoodsReceipt => 'Goods receipt';

  @override
  String get syncTypeInspection => 'Inspection';

  @override
  String get syncTypeIssue => 'Issue';

  @override
  String get syncTypeIssuePhoto => 'Issue photo';

  @override
  String get syncTypeIssueStatus => 'Issue status';

  @override
  String get syncTypeMachineLog => 'Machine log';

  @override
  String get syncTypeMaterialIssue => 'Store issue';

  @override
  String get syncTypeMeasurement => 'Measurement';

  @override
  String get syncTypeMeasurementPhoto => 'Measurement photo';

  @override
  String get syncTypeMeasurementSubmit => 'Measurement submission';

  @override
  String get syncTypePettyBill => 'Bill photo';

  @override
  String get syncTypePettyCash => 'Petty cash entry';

  @override
  String get syncTypeProgress => 'Site progress';

  @override
  String get syncTypeProgressCorrection => 'Progress correction';

  @override
  String get syncTypeProgressPhoto => 'Progress photo';

  @override
  String get syncTypeProgressResolve => 'Pending item resolved';

  @override
  String get syncTypeStage => 'Stage update';

  @override
  String get syncTypeStagePhoto => 'Stage photo';

  @override
  String get tenderAddQuote => 'Add a quote';

  @override
  String get tenderAdvance => 'Advance (₹)';

  @override
  String get tenderAdvanceError =>
      'Enter 0 or more, with two decimals at most.';

  @override
  String get tenderAdvanceRecovery => 'Advance recovery %';

  @override
  String get tenderAmountError =>
      'Enter an amount above zero, with two decimals at most.';

  @override
  String get tenderAwardExplain =>
      'A work order is made at this quote\'s rates. Measurements, RA bills and payments then run on it.';

  @override
  String get tenderAwardSend => 'Create work order';

  @override
  String get tenderAwardTitle => 'Select this contractor';

  @override
  String get tenderAwarded => 'Work order made';

  @override
  String get tenderBasisGross => 'Gross';

  @override
  String get tenderBasisRunning => 'Balance';

  @override
  String tenderBoqAmount(String amount) {
    return 'BOQ amount $amount';
  }

  @override
  String tenderBoqQty(String qty, String unit) {
    return 'BOQ $qty $unit';
  }

  @override
  String tenderBoqRate(String rate) {
    return 'BOQ rate $rate';
  }

  @override
  String get tenderClosed =>
      'This tender is closed, so it takes no more quotes.';

  @override
  String get tenderCompletionDays => 'Days to finish';

  @override
  String get tenderContractor => 'Contractor';

  @override
  String get tenderContractorAdd => 'Add a contractor';

  @override
  String get tenderContractorChoose => 'Choose a contractor';

  @override
  String get tenderContractorName => 'Name';

  @override
  String get tenderContractorNameRequired => 'Enter the contractor\'s name.';

  @override
  String get tenderContractorNone => 'No contractor by that name';

  @override
  String get tenderContractorPerson => 'Contact person';

  @override
  String get tenderContractorPhone => 'Phone';

  @override
  String get tenderContractorRequired => 'Choose who quoted.';

  @override
  String get tenderContractorSave => 'Add contractor';

  @override
  String get tenderContractorSearch => 'Search contractors';

  @override
  String get tenderContractorsFailed =>
      'The contractor list could not be loaded.';

  @override
  String tenderCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count tenders',
      one: '1 tender',
    );
    return '$_temp0';
  }

  @override
  String tenderDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '1 day',
    );
    return '$_temp0';
  }

  @override
  String get tenderDaysError => 'Enter whole days, 1 to 3650.';

  @override
  String get tenderDeductionBasis => 'Deductions on';

  @override
  String get tenderDeductionOrder => 'Deduction order';

  @override
  String tenderDue(String date) {
    return 'Quotes due $date';
  }

  @override
  String get tenderEndDate => 'Finish by';

  @override
  String get tenderEndError => 'Finish by cannot be before the start.';

  @override
  String tenderEnteredBy(String name) {
    return 'Entered by $name';
  }

  @override
  String get tenderFieldDue => 'Quotes due by';

  @override
  String get tenderFieldScope => 'Scope of work';

  @override
  String get tenderFieldTitle => 'Title';

  @override
  String get tenderGst => 'GST %';

  @override
  String get tenderIncludesMaterial => 'Material included in the rate';

  @override
  String tenderLineAmount(String qty, String unit, String amount) {
    return '$qty $unit × rate = $amount';
  }

  @override
  String get tenderLinkEmpty => 'Tenders · Get quotes from contractors';

  @override
  String get tenderLoadFailed =>
      'Tenders could not be loaded. Pull down to try again.';

  @override
  String get tenderLoading => 'Loading tenders';

  @override
  String tenderLowest(String rate) {
    return 'Lowest $rate';
  }

  @override
  String tenderLowestBy(String rate, String contractor) {
    return 'Lowest $rate · $contractor';
  }

  @override
  String get tenderMaterialIn => 'Material included';

  @override
  String get tenderMaterialOut => 'Labour only';

  @override
  String get tenderMoneyHidden =>
      'Rates, terms and files are shown only to those who may see money.';

  @override
  String get tenderNew => 'New tender';

  @override
  String get tenderNewContractorTitle => 'New contractor';

  @override
  String get tenderNewTitle => 'New tender';

  @override
  String get tenderNoDate => 'No date';

  @override
  String get tenderNoQuantity =>
      'This line has no quantity yet, so it cannot be put out to tender. Enter the quantity in the BOQ first, so quotes can be compared.';

  @override
  String get tenderNoneAsk =>
      'No one has asked contractors for quotes on this line yet.';

  @override
  String get tenderNoneStart =>
      'Start a tender to collect quotes from contractors for this line.';

  @override
  String get tenderNoneTitle => 'No tender yet';

  @override
  String get tenderNote => 'Note';

  @override
  String get tenderOfflineMessage =>
      'Quotes and awards are read from the office as they are now. Connect, then pull down to try again.';

  @override
  String get tenderOfflineTitle => 'Tenders need the internet';

  @override
  String get tenderOpenElsewhere => 'Open in another app';

  @override
  String get tenderOptional => 'Optional';

  @override
  String get tenderOrderAdvanceFirst => 'Advance → Retention → Material → TDS';

  @override
  String get tenderOrderRetentionFirst =>
      'Retention → Advance → Material → TDS';

  @override
  String get tenderOrderTdsFirst => 'TDS → Retention → Advance → Material';

  @override
  String tenderOtherLines(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Also $count other lines',
      one: 'Also 1 other line',
    );
    return '$_temp0';
  }

  @override
  String get tenderPaymentTerms => 'Payment terms';

  @override
  String get tenderPctError => 'Enter 0 to 100, with two decimals at most.';

  @override
  String tenderPerUnit(String rate, String unit) {
    return '$rate per $unit';
  }

  @override
  String get tenderPickDate => 'Pick a date';

  @override
  String get tenderQuotationFailed => 'The quotation could not be opened.';

  @override
  String get tenderQuotationNotPicture =>
      'This quotation is a file, not a photo. Open it in another app.';

  @override
  String get tenderQuotationPhoto => 'Photo of the quotation';

  @override
  String tenderQuoteCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count quotes',
      one: '1 quote',
      zero: 'no quotes',
    );
    return '$_temp0';
  }

  @override
  String get tenderQuoteGone =>
      'This quote is no longer in the tender. Go back and choose again.';

  @override
  String get tenderQuoteSaved => 'Quote saved';

  @override
  String get tenderQuoteTitle => 'Add a quote';

  @override
  String get tenderQuotedAmount => 'Quoted amount (₹)';

  @override
  String get tenderQuotesNone => 'No quotes yet';

  @override
  String get tenderRateError =>
      'Enter a rate above zero, with two decimals at most.';

  @override
  String tenderRatePer(String unit) {
    return 'Rate per $unit';
  }

  @override
  String tenderReceived(String date) {
    return 'Received $date';
  }

  @override
  String get tenderReceivedOn => 'Received on';

  @override
  String get tenderRefusedMessage =>
      'Ask your office if you need to see tenders.';

  @override
  String get tenderRefusedTitle => 'Not for your role';

  @override
  String get tenderRemarks => 'Remarks';

  @override
  String get tenderRetention => 'Retention %';

  @override
  String get tenderSaveFailed =>
      'Could not save. Check the connection and try again.';

  @override
  String get tenderSaveQuote => 'Save quote';

  @override
  String get tenderSelect => 'Select this contractor';

  @override
  String get tenderStart => 'Start tender';

  @override
  String get tenderStartDate => 'Start date';

  @override
  String get tenderStarted => 'Tender started';

  @override
  String get tenderStatusAwarded => 'Awarded';

  @override
  String get tenderStatusCancelled => 'Cancelled';

  @override
  String get tenderStatusOpen => 'Open';

  @override
  String get tenderTagLowest => 'Lowest';

  @override
  String get tenderTagReplaced => 'Replaced';

  @override
  String get tenderTagSelected => 'Selected';

  @override
  String get tenderTds => 'TDS %';

  @override
  String get tenderTitle => 'Tenders';

  @override
  String get tenderTitleRequired => 'Give the tender a title.';

  @override
  String tenderTotal(String amount) {
    return 'Quoted total $amount';
  }

  @override
  String get tenderValidError =>
      'Valid till cannot be before the day it was received.';

  @override
  String get tenderValidTill => 'Valid till';

  @override
  String tenderValidUntil(String date) {
    return 'Valid till $date';
  }

  @override
  String get tenderViewQuotation => 'View quotation';

  @override
  String tenderVsBoq(String pct) {
    return '$pct% vs BOQ';
  }

  @override
  String tenderWorkOrder(String number) {
    return 'Work order $number';
  }
}
