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
  String get approvalsDocDpr => 'Daily report';

  @override
  String get approvalsDocMeasurement => 'Measurement';

  @override
  String get approvalsEmptyBody => 'New approvals will appear here.';

  @override
  String get approvalsEmptyTitle => 'Nothing waiting';

  @override
  String approvalsEyebrow(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count waiting for you',
      one: '1 waiting for you',
      zero: 'Nothing waiting',
    );
    return '$_temp0';
  }

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
  String get approvalsOffline => 'No network.';

  @override
  String get approvalsOfflineDetail =>
      'You can read these, but approvals need network.';

  @override
  String get approvalsOpen => 'Open';

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
  String approvalsWaitingOn(String role) {
    return 'Waiting on $role';
  }

  @override
  String get approvalsWeather => 'Weather';

  @override
  String get approvalsWorkDone => 'Work done';

  @override
  String get approvalsWorkers => 'workers';

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
  String get attendanceAllPresentHelper =>
      'Fills unmarked workers only. Absent stays absent.';

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
  String get attendanceLeft => 'Left';

  @override
  String get attendanceLoadFailedMessage =>
      'Your marks are safe on this phone. Try again.';

  @override
  String get attendanceLoadFailedTitle => 'Could not open the muster';

  @override
  String get attendanceMarkedSoFar => 'Marked so far';

  @override
  String get attendanceNoProjectMessage =>
      'Choose a project first, then mark the muster here.';

  @override
  String get attendanceNoProjectTitle => 'No project chosen';

  @override
  String get attendanceNoWorkersMessage =>
      'Add your workers here, or ask the office to add them.';

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
  String get dprBoqNotSynced =>
      'BOQ not on this phone yet. Connect to the network once.';

  @override
  String get dprCameraFailed =>
      'Could not open the camera. Allow camera in phone settings.';

  @override
  String get dprChooseAnother => 'Other item';

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
  String get dprLocked =>
      'This report has gone for approval and cannot be changed now.';

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
  String get dprNeedWorkers =>
      'Enter the workers who came. If the site was closed, write why under Hindrances.';

  @override
  String get dprNoProject => 'Pick a project first';

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
      other: '$count work items',
      one: '1 work item',
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
  String get dprSyncAttention => 'Needs attention';

  @override
  String get dprSyncPhoneOnly => 'Only on this phone';

  @override
  String get dprSyncSending => 'Sending';

  @override
  String get dprSyncWaiting => 'Waiting to send';

  @override
  String get dprTakePhoto => 'Take photo';

  @override
  String get dprTotalWorkers => 'Total workers';

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
  String get issuesFormAssignee => 'Who will fix it?';

  @override
  String get issuesFormDue => 'Fix by';

  @override
  String get issuesFormDueThree => '3 days';

  @override
  String get issuesFormDueToday => 'Today';

  @override
  String get issuesFormDueWeek => '1 week';

  @override
  String get issuesFormEyebrow => 'New issue';

  @override
  String get issuesFormLocation => 'Where on site?';

  @override
  String get issuesFormLocationHint => 'Grid C, third floor';

  @override
  String get issuesFormPriority => 'How urgent is it?';

  @override
  String get issuesFormSave => 'Save issue';

  @override
  String get issuesFormTitle => 'Raise an issue';

  @override
  String get issuesFormWhat => 'What is wrong?';

  @override
  String get issuesFormWhatHint => 'Crack in column C3';

  @override
  String get issuesFormWhatRequired => 'Write what is wrong.';

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
  String get issuesRaise => 'Raise issue';

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
  String get measurementDetailsShort => 'Details';

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
  String get measurementFormulaHint => 'Enter Nos to begin';

  @override
  String get measurementGoneBody => 'It may have been deleted on the web.';

  @override
  String get measurementGoneTitle => 'Entry not found';

  @override
  String get measurementItem => 'Item';

  @override
  String get measurementLength => 'Length';

  @override
  String get measurementLengthShort => '× L';

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
  String get measurementNoBoqBody =>
      'Connect to the network once so the BOQ comes to this phone.';

  @override
  String get measurementNoBoqTitle => 'No BOQ lines yet';

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
  String get measurementPreviewNote =>
      'Figures on this phone are a preview. The server\'s figures are final.';

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
  String measurementRowCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count entries',
      one: '1 entry',
    );
    return '$_temp0';
  }

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
    return 'Send $count rows';
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
  String get pettyCashAddExpense => 'Add expense';

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
  String get pettyCashConfirmed => 'Confirmed';

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
  String get pettyCashNoProjectMessage =>
      'Choose a project first, then record expenses here.';

  @override
  String get pettyCashNoProjectTitle => 'No project chosen';

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
  String get pettyCashTitle => 'Petty cash';

  @override
  String get pettyCashTopup => 'Top-up';

  @override
  String get pettyCashUnsent => 'Not sent yet';

  @override
  String get pettyCashWallet => 'Wallet';

  @override
  String get shellCaptionMarked => 'marked';

  @override
  String get shellCaptionOpen => 'open';

  @override
  String get shellCaptionToday => 'today';

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
  String shellProgressSemantics(String percent) {
    return '$percent percent complete';
  }

  @override
  String get shellProgressTitle => 'Construction progress';

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
  String get shellTileMeasure => 'Measure';

  @override
  String get shellTilePetty => 'Petty cash';

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
