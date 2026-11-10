// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

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
  String get approvalsPeriod => 'Period';

  @override
  String get approvalsPhotos => 'Photos';

  @override
  String get approvalsReasonAmounts => 'Amounts do not match';

  @override
  String get approvalsReasonDeductions => 'Check the deductions';

  @override
  String get approvalsReasonDimensions => 'Check the dimensions';

  @override
  String get approvalsReasonLocation => 'Write the location clearly';

  @override
  String get approvalsReasonManpower => 'Manpower count is wrong';

  @override
  String get approvalsReasonNote => 'Reason is not clear';

  @override
  String get approvalsReasonPeriod => 'Billing period is wrong';

  @override
  String get approvalsReasonPhotos => 'Add photos';

  @override
  String get approvalsReasonQuantityHigh => 'Quantity looks too high';

  @override
  String get approvalsReasonWorkDetail => 'Add more work detail';

  @override
  String get approvalsReasonWrongItem => 'Wrong BOQ line';

  @override
  String get approvalsRefresh => 'Refresh';

  @override
  String get approvalsRefreshing => 'Refreshing';

  @override
  String get approvalsRemarkHint => 'What needs fixing';

  @override
  String get approvalsRemarkLabel => 'Remark';

  @override
  String get approvalsRemarks => 'Remarks';

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
  String boqBalance(String qty, String unit) {
    return 'Balance $qty $unit';
  }

  @override
  String get boqBalanceLabel => 'Balance';

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
  String get boqRefreshFailed =>
      'Could not update. Showing what is on this phone.';

  @override
  String get boqSearchHint => 'Code or work';

  @override
  String get boqSearchLabel => 'Search lines';

  @override
  String get boqTitle => 'BOQ';

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
  String get materialAdd => 'Add entry';

  @override
  String get materialBoqForWork => 'BOQ for work done';

  @override
  String get materialChallanNo => 'Challan no. (optional)';

  @override
  String get materialChallanPhoto => 'Photo of challan';

  @override
  String get materialChoose => 'Choose material';

  @override
  String get materialCount => 'Count stock';

  @override
  String get materialCountHint =>
      'Stock is corrected to what you counted. Any missing quantity shows as unaccounted.';

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
  String get materialName => 'Material';

  @override
  String get materialNoCountsTitle => 'No counts yet';

  @override
  String get materialNoDataMessage =>
      'Connect to the internet once to load this project\'s material. After that it works offline.';

  @override
  String get materialNoDeliveriesTitle => 'No challans yet';

  @override
  String get materialNoUseTitle => 'No material used yet';

  @override
  String get materialOnBoq => 'BOQ work';

  @override
  String get materialOnExtra => 'Extra work';

  @override
  String get materialOnSite => 'Stock on site';

  @override
  String get materialOverStock =>
      'This is more than the stock shown here. The server may refuse it.';

  @override
  String get materialQtyCounted => 'Counted quantity';

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
  String get materialReasonOnWeb => 'Write the reason on the website.';

  @override
  String get materialReceived => 'Material received';

  @override
  String get materialReceivedShort => 'Received';

  @override
  String get materialSearch => 'Search material';

  @override
  String get materialSearchNone => 'Nothing matches';

  @override
  String get materialShort => 'Short delivered';

  @override
  String get materialShouldBe => 'Should be in stock';

  @override
  String get materialStage => 'Stage (optional)';

  @override
  String get materialStageHint => 'Such as slab 3';

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
  String get materialUsed => 'Material used';

  @override
  String get materialUsedShort => 'Used';

  @override
  String get materialVehicleNo => 'Vehicle no. (optional)';

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
  String get offlineActivateAgain => 'Activate this phone';

  @override
  String get offlineCopyLink => 'Copy link';

  @override
  String get offlineDeviceLimitBody =>
      'Your plan allows a set number of phones and they are all in use. Free one on the website, then try again.';

  @override
  String get offlineDeviceLimitTitle => 'Another phone holds your licence';

  @override
  String get offlineFreeBody =>
      'Your data does not move by itself. Export a copy first. This phone keeps its data and works until its licence ends.';

  @override
  String get offlineFreeConfirm => 'Free it';

  @override
  String get offlineFreeFailed =>
      'That did not work. Check your internet and try again.';

  @override
  String get offlineFreeThisPhone => 'Move to a new phone';

  @override
  String get offlineFreeTitle => 'Free this phone\'s licence?';

  @override
  String get offlineHereEntries => 'Entries and photos';

  @override
  String get offlineHereProjects => 'Projects made here';

  @override
  String get offlineHomeEnded => 'Your licence has ended. See how to renew.';

  @override
  String get offlineHomeHint => 'Your data is only here. Keep a copy.';

  @override
  String get offlineInvalidTitle => 'The licence could not be checked';

  @override
  String offlineLicenceActive(String date) {
    return 'Good until $date';
  }

  @override
  String get offlineLicenceEndedBody =>
      'Nothing is lost and you can still read, add and export. To renew, connect to the internet and open this app. If it stays like this, check your plan on the Nirmaan360 website.';

  @override
  String get offlineLicenceEndedTitle => 'Your licence has ended';

  @override
  String offlineLicenceEndingBody(String date) {
    return 'Ends on $date. Connect to the internet once and it renews by itself.';
  }

  @override
  String get offlineLicenceHeading => 'Licence';

  @override
  String get offlineLicenceNone =>
      'Not ready yet. Connect to the internet once.';

  @override
  String get offlineLinkCopied => 'Link copied';

  @override
  String get offlineManageLink => 'Free a phone here';

  @override
  String get offlineNeedsInternet =>
      'This needs the internet. Your data here is not affected.';

  @override
  String get offlineNeedsSignInBody =>
      'Nothing is lost. Your work continues here. The licence can only renew after you sign in.';

  @override
  String get offlineNeedsSignInTitle => 'Sign in again to renew';

  @override
  String get offlineOnPhoneTag => 'On this phone';

  @override
  String get offlineOnPhoneTitle => 'Only on this phone';

  @override
  String get offlineOnlyHereBody =>
      'Nothing is kept on our server. If this phone is lost or reset, the data is gone. Export a copy and keep it safe.';

  @override
  String get offlineOnlyHereTitle => 'Your data is only on this phone';

  @override
  String get offlinePlanTitle => 'Offline plan';

  @override
  String get offlineReviewTitle => 'Check and save';

  @override
  String get offlineRevokedBody =>
      'Your data is safe here and the app keeps working until the licence ends. To use this phone again, free a slot on the website, then activate it.';

  @override
  String get offlineRevokedTitle => 'This phone was freed';

  @override
  String get offlineSaveReport => 'Save report';

  @override
  String get offlineSavedOnPhone => 'Saved on this phone';

  @override
  String get offlineSignInButton => 'Sign in';

  @override
  String get offlineSignOutBody =>
      'Your data stays on this phone. You will need internet to sign in again.';

  @override
  String get offlineSignOutTitle => 'Sign out?';

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
      'Ask your office to add floors and flats on the website.';

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
  String get progressWorkDone => 'Work done';

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
  String get shellDiscardBody =>
      'It has not reached the server. It will be removed from this phone.';

  @override
  String get shellDiscardConfirm => 'Yes, throw away';

  @override
  String get shellDiscardKeep => 'Keep it';

  @override
  String get shellDiscardTitle => 'Throw away this entry?';

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
  String get syncTypeContractorPayment => 'Contractor payment request';

  @override
  String get syncTypeDpr => 'Daily report';

  @override
  String get syncTypeDprPhoto => 'Report photo';

  @override
  String get syncTypeDprSubmit => 'Report submission';

  @override
  String get syncTypeIssue => 'Issue';

  @override
  String get syncTypeIssuePhoto => 'Issue photo';

  @override
  String get syncTypeIssueStatus => 'Issue status';

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
}
