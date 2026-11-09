// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Hindi (`hi`).
class AppLocalizationsHi extends AppLocalizations {
  AppLocalizationsHi([String locale = 'hi']) : super(locale);

  @override
  String get appName => 'निर्माण360';

  @override
  String get approvalsActionApproved => 'मंज़ूर किया';

  @override
  String get approvalsActionRejected => 'अस्वीकार किया';

  @override
  String get approvalsActionReturned => 'वापस भेजा';

  @override
  String get approvalsActionSubmitted => 'भेजा';

  @override
  String get approvalsApprove => 'मंज़ूर करें';

  @override
  String get approvalsBackToInbox => 'मंज़ूरियों पर वापस';

  @override
  String get approvalsBillNumber => 'बिल नंबर';

  @override
  String get approvalsBillType => 'प्रकार';

  @override
  String get approvalsChainNext => 'उसके बाद';

  @override
  String get approvalsChainTitle => 'मंज़ूरी का रिकॉर्ड';

  @override
  String get approvalsChainWaiting => 'अभी बाकी';

  @override
  String get approvalsCouldNotRefresh =>
      'ताज़ा नहीं हो सका। फिर से कोशिश करें।';

  @override
  String get approvalsCouldNotRefreshOffline =>
      'सर्वर से संपर्क नहीं हुआ। पिछली जानकारी दिखा रहे हैं।';

  @override
  String approvalsDaysAgo(int count) {
    return '$count दिन पहले';
  }

  @override
  String get approvalsDeductions => 'कटौतियाँ';

  @override
  String get approvalsDeviation => 'विचलन';

  @override
  String get approvalsDeviationBody =>
      'BOQ मात्रा और छूट से ज़्यादा। प्रोजेक्ट मैनेजर की मंज़ूरी ज़रूरी।';

  @override
  String get approvalsDocBill => 'RA बिल';

  @override
  String get approvalsDocDpr => 'दैनिक रिपोर्ट';

  @override
  String get approvalsDocMeasurement => 'माप';

  @override
  String get approvalsEmptyBody => 'नई मंज़ूरियाँ यहाँ दिखेंगी।';

  @override
  String get approvalsEmptyTitle => 'कुछ बाकी नहीं';

  @override
  String approvalsEyebrow(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count आपके पास बाकी',
      zero: 'कुछ बाकी नहीं',
    );
    return '$_temp0';
  }

  @override
  String get approvalsFailOfflineBody =>
      'आपका फ़ैसला नहीं गया। कुछ भी कतार में नहीं है। नेटवर्क आने पर फिर करें।';

  @override
  String get approvalsFailOfflineTitle => 'नेटवर्क नहीं है';

  @override
  String get approvalsFailRaceBody =>
      'किसी और ने पहले फ़ैसला कर लिया। अभी क्या बाकी है देखने के लिए ताज़ा करें।';

  @override
  String get approvalsFailRaceTitle => 'पहले ही फ़ैसला हो गया';

  @override
  String get approvalsFailRejectedTitle => 'सर्वर ने इसे नहीं लिया';

  @override
  String get approvalsFailServerBody =>
      'सर्वर पर दिक्कत आई। आपका फ़ैसला दर्ज नहीं हुआ। फिर से कोशिश करें।';

  @override
  String get approvalsFailServerTitle => 'भेजा नहीं जा सका';

  @override
  String get approvalsFailSessionBody =>
      'आपका सत्र समाप्त हो गया। आपका फ़ैसला नहीं गया।';

  @override
  String get approvalsFailSessionTitle => 'कृपया फिर से साइन इन करें';

  @override
  String get approvalsGoneBody => 'इस पर पहले ही फ़ैसला हो चुका है।';

  @override
  String get approvalsGoneTitle => 'अब बाकी नहीं';

  @override
  String get approvalsGross => 'काम का कुल मूल्य';

  @override
  String get approvalsGst => 'GST';

  @override
  String get approvalsHindrances => 'रुकावटें';

  @override
  String approvalsHoursAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count घंटे पहले',
      one: '1 घंटा पहले',
    );
    return '$_temp0';
  }

  @override
  String get approvalsJustNow => 'अभी';

  @override
  String get approvalsManpower => 'मज़दूर संख्या';

  @override
  String approvalsMinutesAgo(int count) {
    return '$count मिनट पहले';
  }

  @override
  String get approvalsNeedsNetwork =>
      'मंज़ूर करने या वापस भेजने के लिए नेटवर्क चाहिए। कुछ भी कतार में नहीं रखा जाता।';

  @override
  String get approvalsNetPayable => 'देय राशि';

  @override
  String get approvalsOffline => 'नेटवर्क नहीं है।';

  @override
  String get approvalsOfflineDetail =>
      'पढ़ सकते हैं, पर मंज़ूरी के लिए नेटवर्क चाहिए।';

  @override
  String get approvalsOpen => 'खोलें';

  @override
  String get approvalsParty => 'पार्टी';

  @override
  String get approvalsPeriod => 'अवधि';

  @override
  String get approvalsPhotos => 'फ़ोटो';

  @override
  String get approvalsReasonAmounts => 'राशि मेल नहीं खाती';

  @override
  String get approvalsReasonDeductions => 'कटौतियाँ जाँचें';

  @override
  String get approvalsReasonDimensions => 'माप जाँचें';

  @override
  String get approvalsReasonLocation => 'स्थान साफ़ लिखें';

  @override
  String get approvalsReasonManpower => 'मज़दूर संख्या गलत है';

  @override
  String get approvalsReasonPeriod => 'बिल की अवधि गलत है';

  @override
  String get approvalsReasonPhotos => 'फ़ोटो जोड़ें';

  @override
  String get approvalsReasonQuantityHigh => 'मात्रा ज़्यादा लग रही है';

  @override
  String get approvalsReasonWorkDetail => 'काम का और विवरण लिखें';

  @override
  String get approvalsReasonWrongItem => 'BOQ लाइन गलत है';

  @override
  String get approvalsRefresh => 'ताज़ा करें';

  @override
  String get approvalsRefreshing => 'ताज़ा हो रहा है';

  @override
  String get approvalsRemarkHint => 'क्या ठीक करना है';

  @override
  String get approvalsRemarkLabel => 'टिप्पणी';

  @override
  String get approvalsRemarks => 'टिप्पणी';

  @override
  String get approvalsRoleAccounts => 'लेखा';

  @override
  String get approvalsRoleAdmin => 'एडमिन';

  @override
  String get approvalsRoleClient => 'क्लाइंट';

  @override
  String get approvalsRoleEngineer => 'साइट इंजीनियर';

  @override
  String get approvalsRoleOwner => 'मालिक';

  @override
  String get approvalsRolePm => 'प्रोजेक्ट मैनेजर';

  @override
  String get approvalsRoleQs => 'एस्टिमेटर / QS';

  @override
  String get approvalsSendBack => 'वापस भेजें';

  @override
  String get approvalsSendBackHelp =>
      'क्या ठीक करना है, बताएँ। एक चुनें या खुद लिखें।';

  @override
  String get approvalsSendBackTitle => 'वापस क्यों भेज रहे हैं?';

  @override
  String get approvalsSentBackToast => 'आपकी टिप्पणी के साथ वापस भेजा।';

  @override
  String get approvalsSessionExpired => 'कृपया फिर से साइन इन करें।';

  @override
  String get approvalsSummary => 'काम का सारांश';

  @override
  String get approvalsTitle => 'मंज़ूरियाँ';

  @override
  String get approvalsTotal => 'कुल';

  @override
  String approvalsWaitingOn(String role) {
    return '$role के पास बाकी';
  }

  @override
  String get approvalsWeather => 'मौसम';

  @override
  String get approvalsWorkDone => 'किया गया काम';

  @override
  String get approvalsWorkers => 'मज़दूर';

  @override
  String get attendanceAddWorker => 'कामगार जोड़ें';

  @override
  String get attendanceAllMarked => 'सबकी हाज़िरी हो गई';

  @override
  String attendanceAllPresent(int count) {
    return 'सभी उपस्थित ($count)';
  }

  @override
  String attendanceAllPresentDone(int count) {
    return '$count लोग उपस्थित दर्ज';
  }

  @override
  String get attendanceAllPresentHelper =>
      'सिर्फ़ बचे लोगों को उपस्थित करता है। अनुपस्थित नहीं बदलते।';

  @override
  String attendanceDateTitle(String date) {
    return '$date की हाज़िरी';
  }

  @override
  String get attendanceDayToday => 'आज';

  @override
  String get attendanceDayYesterday => 'कल';

  @override
  String get attendanceEyebrow => 'हाज़िरी रजिस्टर';

  @override
  String attendanceGroupProgress(int marked, int total) {
    return '$total में से $marked';
  }

  @override
  String get attendanceHoursUnit => 'घं.';

  @override
  String get attendanceLeft => 'बाकी';

  @override
  String get attendanceLoadFailedMessage =>
      'आपकी प्रविष्टियाँ इस फ़ोन पर सुरक्षित हैं। फिर से कोशिश करें।';

  @override
  String get attendanceLoadFailedTitle => 'हाज़िरी नहीं खुली';

  @override
  String get attendanceNoProjectMessage =>
      'पहले प्रोजेक्ट चुनें, फिर यहाँ हाज़िरी लगाएँ।';

  @override
  String get attendanceNoProjectTitle => 'कोई प्रोजेक्ट नहीं चुना';

  @override
  String get attendanceNoWorkersMessage =>
      'अपने कामगार यहाँ जोड़ें या ऑफ़िस से जुड़वाएँ।';

  @override
  String get attendanceNoWorkersTitle => 'हाज़िरी में कोई कामगार नहीं';

  @override
  String get attendanceOtHours => 'ओवरटाइम घंटे';

  @override
  String get attendanceOtLess => 'आधा घंटा कम';

  @override
  String get attendanceOtMore => 'आधा घंटा ज़्यादा';

  @override
  String get attendanceSaveFailed =>
      'वह प्रविष्टि सेव नहीं हुई। फिर से टैप करें।';

  @override
  String attendanceSheetDone(String name) {
    return '$name हाज़िरी में जोड़े गए';
  }

  @override
  String get attendanceSheetFailed => 'कामगार नहीं जुड़ा। फिर से कोशिश करें।';

  @override
  String get attendanceSheetName => 'कामगार का नाम';

  @override
  String get attendanceSheetNameHint => 'जैसा हाज़िरी में है';

  @override
  String get attendanceSheetNameRequired => 'कामगार का नाम लिखें।';

  @override
  String get attendanceSheetOfflineError =>
      'नेटवर्क नहीं है। फ़ोन ऑनलाइन आने पर फिर से कोशिश करें।';

  @override
  String get attendanceSheetOfflineMessage =>
      'हाज़िरी बिना नेटवर्क के भी चलती है। फ़ोन ऑनलाइन आने पर यह कामगार जोड़ें।';

  @override
  String get attendanceSheetOfflineTitle =>
      'कामगार जोड़ने के लिए नेटवर्क चाहिए';

  @override
  String get attendanceSheetTitle => 'नया कामगार';

  @override
  String get attendanceSheetTrade => 'काम का प्रकार';

  @override
  String get attendanceSheetTradeRequired => 'काम का प्रकार चुनें।';

  @override
  String get attendanceSheetWage => 'दैनिक मज़दूरी';

  @override
  String attendanceSheetWageFor(String name) {
    return '$name की दैनिक मज़दूरी';
  }

  @override
  String get attendanceSheetWageHelper => 'पता न हो तो खाली छोड़ दें।';

  @override
  String get attendanceTitle => 'हाज़िरी';

  @override
  String get attendanceTodayTitle => 'आज की हाज़िरी';

  @override
  String get commonBack => 'वापस';

  @override
  String get commonCancel => 'रद्द करें';

  @override
  String get commonDone => 'हो गया';

  @override
  String get commonRetry => 'फिर से कोशिश करें';

  @override
  String get commonSave => 'सहेजें';

  @override
  String get commonToday => 'आज';

  @override
  String get designAttendanceAbsent => 'अनुपस्थित';

  @override
  String get designAttendanceHalf => 'आधा दिन';

  @override
  String get designAttendanceOvertime => 'ओवरटाइम';

  @override
  String get designAttendancePresent => 'उपस्थित';

  @override
  String get designExpenseFood => 'भोजन';

  @override
  String get designExpenseFuel => 'ईंधन';

  @override
  String get designExpenseLabour => 'मज़दूरी';

  @override
  String get designExpenseMaterials => 'सामग्री';

  @override
  String get designExpenseOther => 'अन्य';

  @override
  String get designExpenseRepairs => 'मरम्मत';

  @override
  String get designExpenseTools => 'औज़ार';

  @override
  String get designExpenseTravel => 'यात्रा';

  @override
  String get designIssueClosed => 'बंद';

  @override
  String get designIssueInProgress => 'चल रहा है';

  @override
  String get designIssueOpen => 'खुला';

  @override
  String get designIssueResolved => 'हल हुआ';

  @override
  String get designKeypadDelete => 'हटाएँ';

  @override
  String get designKeypadNext => 'आगे';

  @override
  String get designPriorityCritical => 'गंभीर';

  @override
  String get designPriorityHigh => 'ऊँची';

  @override
  String get designPriorityLow => 'कम';

  @override
  String get designPriorityMedium => 'मध्यम';

  @override
  String get designStepperDecrease => 'कम करें';

  @override
  String get designStepperIncrease => 'बढ़ाएँ';

  @override
  String get designTradeBarBender => 'बार बेंडर';

  @override
  String get designTradeCarpenter => 'बढ़ई';

  @override
  String get designTradeElectrician => 'इलेक्ट्रीशियन';

  @override
  String get designTradeHelper => 'हेल्पर';

  @override
  String get designTradeMason => 'राजमिस्त्री';

  @override
  String get designTradeOperator => 'ऑपरेटर';

  @override
  String get designTradePainter => 'पेंटर';

  @override
  String get designTradePlumber => 'प्लंबर';

  @override
  String get designTradeSupervisor => 'सुपरवाइज़र';

  @override
  String get designTradeWelder => 'वेल्डर';

  @override
  String get designWeatherClear => 'साफ़';

  @override
  String get designWeatherCloudy => 'बादल';

  @override
  String get designWeatherHot => 'गर्म';

  @override
  String get designWeatherRain => 'बारिश';

  @override
  String get designWeatherWindy => 'तेज़ हवा';

  @override
  String get dprAdd => 'जोड़ें';

  @override
  String get dprAddNotes => 'रुकावटें या नोट लिखें';

  @override
  String get dprAddWork => 'काम जोड़ें';

  @override
  String get dprAddedTag => 'जोड़ा';

  @override
  String get dprApproverRemark => 'मंज़ूरी देने वाले की टिप्पणी';

  @override
  String get dprAttentionBody =>
      'यह रिपोर्ट नहीं भेजी जा सकी। ठीक करने के लिए आउटबॉक्स खोलें।';

  @override
  String get dprBalance => 'शेष';

  @override
  String get dprBoqEmpty => 'BOQ आइटम नहीं मिले।';

  @override
  String get dprBoqNotSynced =>
      'BOQ अभी इस फ़ोन पर नहीं आया। एक बार नेटवर्क से जुड़ें।';

  @override
  String get dprCameraFailed =>
      'कैमरा नहीं खुला। फ़ोन की सेटिंग में कैमरे की अनुमति दें।';

  @override
  String get dprChooseAnother => 'दूसरा आइटम';

  @override
  String get dprCorrectAndSend => 'सुधारकर फिर से भेजें';

  @override
  String get dprEdit => 'बदलें';

  @override
  String get dprEmptyMessage => 'आपकी पहली रिपोर्ट से यह बही शुरू होगी।';

  @override
  String get dprEmptyTitle => 'अभी कोई रिपोर्ट नहीं';

  @override
  String get dprFilledFromYesterday => 'कल की जानकारी से भरा';

  @override
  String get dprFormTitle => 'दैनिक रिपोर्ट';

  @override
  String get dprFutureDate => 'रिपोर्ट की तारीख़ भविष्य की नहीं हो सकती।';

  @override
  String get dprHindrances => 'रुकावटें';

  @override
  String get dprHindrancesHint => 'सामान देर से, बारिश, बिजली गई';

  @override
  String get dprListTitle => 'दैनिक रिपोर्ट';

  @override
  String get dprLocked =>
      'यह रिपोर्ट मंज़ूरी के लिए जा चुकी है, अब बदली नहीं जा सकती।';

  @override
  String dprMoreRows(int count) {
    return '$count और';
  }

  @override
  String dprMoreTrades(int count) {
    return 'काम के और प्रकार ($count)';
  }

  @override
  String get dprNeedWeather => 'आज का मौसम चुनें।';

  @override
  String get dprNeedWorkers =>
      'आए कामगारों की संख्या भरें। साइट बंद थी तो रुकावटों में कारण लिखें।';

  @override
  String get dprNoProject => 'पहले प्रोजेक्ट चुनें';

  @override
  String get dprNotSaved => 'सेव नहीं हुआ';

  @override
  String get dprNotes => 'नोट';

  @override
  String get dprNotesHint => 'आज का और कुछ';

  @override
  String get dprNothingRecorded => 'कुछ दर्ज नहीं';

  @override
  String get dprOpenToday => 'आज की रिपोर्ट खोलें';

  @override
  String get dprOptional => 'वैकल्पिक';

  @override
  String get dprOverBalance => 'BOQ शेष से ज़्यादा है। आँकड़ा जाँचें।';

  @override
  String get dprPhotoFailed => 'यह फ़ोटो नहीं जुड़ी। फिर से कोशिश करें।';

  @override
  String get dprPhotoMissing => 'उपलब्ध नहीं';

  @override
  String dprPhotosCount(int count) {
    return '$count फ़ोटो';
  }

  @override
  String get dprPhotosFull => 'एक रिपोर्ट में 10 फ़ोटो तक रख सकते हैं।';

  @override
  String get dprQtyShort => 'मात्रा';

  @override
  String get dprQuantity => 'मात्रा';

  @override
  String get dprRemove => 'हटाएँ';

  @override
  String get dprReturnedNoRemark => 'कृपया सुधारकर फिर से भेजें।';

  @override
  String get dprReturnedTitle => 'सुधार के लिए वापस भेजा';

  @override
  String get dprReviewOffline =>
      'अभी नेटवर्क नहीं है। रिपोर्ट इस फ़ोन पर रहेगी और नेटवर्क आने पर अपने आप चली जाएगी।';

  @override
  String get dprReviewTitle => 'जाँचें और भेजें';

  @override
  String dprRowsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count काम',
      one: '1 काम',
    );
    return '$_temp0';
  }

  @override
  String dprSameAsYesterday(int count) {
    return 'कल जैसा ही ($count)';
  }

  @override
  String get dprSavedOnPhone => 'फ़ोन पर सेव हुआ';

  @override
  String get dprSavedSendingNow => 'फ़ोन पर सेव हुआ, अभी भेज रहे हैं';

  @override
  String get dprSavedWillSend => 'फ़ोन पर सेव हुआ, नेटवर्क आने पर भेजा जाएगा';

  @override
  String get dprSaving => 'फ़ोन पर सेव हो रहा है';

  @override
  String get dprSearchBoq => 'BOQ खोजें';

  @override
  String get dprSearchHint => 'कोड या विवरण';

  @override
  String get dprSectionNotes => 'रुकावटें और नोट';

  @override
  String get dprSectionPhotos => 'फ़ोटो';

  @override
  String get dprSectionWeather => 'मौसम';

  @override
  String get dprSectionWork => 'किया गया काम';

  @override
  String get dprSectionWorkers => 'कामगार';

  @override
  String get dprSend => 'भेजें';

  @override
  String get dprSendAgain => 'फिर से भेजें';

  @override
  String get dprSendFailed => 'रिपोर्ट सेव नहीं हो सकी। फिर से कोशिश करें।';

  @override
  String get dprSendReport => 'रिपोर्ट भेजें';

  @override
  String get dprSyncAttention => 'ध्यान दें';

  @override
  String get dprSyncPhoneOnly => 'केवल इस फ़ोन पर';

  @override
  String get dprSyncSending => 'भेज रहे हैं';

  @override
  String get dprSyncWaiting => 'भेजना बाकी';

  @override
  String get dprTakePhoto => 'फ़ोटो लें';

  @override
  String get dprTotalWorkers => 'कुल कामगार';

  @override
  String dprWorkersCount(int count) {
    return '$count कामगार';
  }

  @override
  String dprWorkersWord(int count) {
    return 'कामगार';
  }

  @override
  String get dprWriteToday => 'आज की रिपोर्ट लिखें';

  @override
  String get dprYesterdayTag => 'कल';

  @override
  String get issuesActionClose => 'समस्या बंद करें';

  @override
  String get issuesActionReopen => 'फिर से खोलें';

  @override
  String get issuesActionResolve => 'हल हुआ दर्ज करें';

  @override
  String get issuesActionStart => 'काम शुरू करें';

  @override
  String get issuesAssignedUnknown => 'सौंपा गया';

  @override
  String get issuesAssigneeSheetTitle => 'किसे सौंपें';

  @override
  String get issuesDetailAssignee => 'सौंपा गया';

  @override
  String get issuesDetailDescription => 'विवरण';

  @override
  String get issuesDetailDue => 'अंतिम तारीख़';

  @override
  String get issuesDetailLocation => 'स्थान';

  @override
  String get issuesDetailMissingMessage =>
      'शायद हटा दी गई हो। सूची पर वापस जाएँ।';

  @override
  String get issuesDetailMissingTitle => 'यह समस्या इस फ़ोन पर नहीं है';

  @override
  String get issuesDetailPriority => 'प्राथमिकता';

  @override
  String get issuesDetailRaisedBy => 'दर्ज करने वाले';

  @override
  String get issuesDetailResolvedOn => 'हल होने की तारीख़';

  @override
  String issuesDueOn(String date) {
    return 'समय सीमा $date';
  }

  @override
  String get issuesDueToday => 'आज तक';

  @override
  String get issuesEmptyFilterMessage => 'इस स्थिति में कोई समस्या नहीं।';

  @override
  String get issuesEmptyFilterTitle => 'यहाँ कुछ नहीं';

  @override
  String get issuesEmptyMessage =>
      'साइट पर कुछ गड़बड़ हो तो यहाँ दर्ज करें, ताकि ठीक हो सके।';

  @override
  String get issuesEmptyTitle => 'इस साइट पर कोई समस्या नहीं';

  @override
  String get issuesEyebrow => 'साइट की समस्याएँ';

  @override
  String get issuesFilterAll => 'सभी';

  @override
  String get issuesFormAssignee => 'कौन ठीक करेगा?';

  @override
  String get issuesFormDue => 'कब तक ठीक करना है?';

  @override
  String get issuesFormDueThree => '3 दिन';

  @override
  String get issuesFormDueToday => 'आज';

  @override
  String get issuesFormDueWeek => '1 हफ़्ता';

  @override
  String get issuesFormEyebrow => 'नई समस्या';

  @override
  String get issuesFormLocation => 'साइट पर कहाँ?';

  @override
  String get issuesFormLocationHint => 'ग्रिड C, तीसरी मंज़िल';

  @override
  String get issuesFormNoDue => 'तारीख़ नहीं';

  @override
  String get issuesFormPriority => 'कितना ज़रूरी है?';

  @override
  String get issuesFormSave => 'समस्या सेव करें';

  @override
  String get issuesFormTitle => 'समस्या दर्ज करें';

  @override
  String get issuesFormWhat => 'क्या गड़बड़ है?';

  @override
  String get issuesFormWhatHint => 'कॉलम C3 में दरार';

  @override
  String get issuesFormWhatRequired => 'क्या गड़बड़ है, लिखें।';

  @override
  String get issuesNoDueDate => 'कोई समय सीमा नहीं';

  @override
  String get issuesNoProjectMessage =>
      'पहले प्रोजेक्ट चुनें, फिर यहाँ समस्या दर्ज करें।';

  @override
  String get issuesNoProjectTitle => 'कोई प्रोजेक्ट नहीं चुना';

  @override
  String issuesOverdue(int days) {
    return '$days दिन की देरी';
  }

  @override
  String get issuesRaise => 'समस्या दर्ज करें';

  @override
  String get issuesSaveFailed => 'समस्या सेव नहीं हुई। फिर से कोशिश करें।';

  @override
  String get issuesSavedOffline =>
      'फ़ोन पर सेव हुआ। नेटवर्क आने पर भेजा जाएगा।';

  @override
  String get issuesSavedOnline => 'सेव हुआ। अभी भेज रहे हैं।';

  @override
  String get issuesStatusFailed => 'स्थिति नहीं बदली। फिर से कोशिश करें।';

  @override
  String get issuesSyncAttention => 'ध्यान दें';

  @override
  String get issuesSyncSending => 'भेज रहे हैं';

  @override
  String get issuesTitle => 'समस्याएँ';

  @override
  String get issuesUnassigned => 'किसी को नहीं सौंपा';

  @override
  String get measurementAdd => 'जोड़ें';

  @override
  String get measurementAddEntry => 'माप जोड़ें';

  @override
  String get measurementAllLines => 'सभी लाइनें';

  @override
  String get measurementApprovedLockedBody =>
      'सुधार वेब पर रिवर्सल एंट्री से होते हैं।';

  @override
  String get measurementApprovedLockedTitle => 'मंज़ूर और बंद';

  @override
  String get measurementApproverSaid => 'मंज़ूर करने वाले की टिप्पणी';

  @override
  String get measurementAttentionBody =>
      'सर्वर ने यह एंट्री नहीं ली। यह आपके फ़ोन पर सुरक्षित है।';

  @override
  String get measurementAttentionTitle => 'भेजा नहीं जा सका';

  @override
  String get measurementBalance => 'शेष';

  @override
  String get measurementBalanceAfter => 'इसके बाद शेष';

  @override
  String get measurementBookTitle => 'मापन पुस्तिका';

  @override
  String get measurementBoqQty => 'BOQ';

  @override
  String get measurementBreadth => 'चौड़ाई';

  @override
  String get measurementBreadthShort => 'चौड़ाई';

  @override
  String get measurementChange => 'बदलें';

  @override
  String get measurementChangeLine => 'BOQ लाइन बदलें';

  @override
  String get measurementChooseLine => 'BOQ लाइन चुनें';

  @override
  String get measurementClose => 'बंद करें';

  @override
  String get measurementDateLabel => 'माप की तारीख़';

  @override
  String get measurementDeduct => 'घटाएँ';

  @override
  String get measurementDelete => 'मिटाएँ';

  @override
  String get measurementDepth => 'गहराई';

  @override
  String get measurementDepthShort => 'गहराई';

  @override
  String get measurementDescriptionHint => 'क्या मापा, लिखें';

  @override
  String get measurementDescriptionLabel => 'विवरण';

  @override
  String get measurementDetailTitle => 'माप';

  @override
  String get measurementDetailsShort => 'विवरण';

  @override
  String get measurementDetailsTitle => 'स्थान, नोट और तारीख़';

  @override
  String get measurementDeviation => 'विचलन';

  @override
  String measurementDeviationBody(String percent) {
    return 'BOQ + $percent% से ज़्यादा। प्रोजेक्ट मैनेजर की मंज़ूरी लगेगी।';
  }

  @override
  String get measurementDeviationDetail =>
      'यह एंट्री BOQ मात्रा और छूट से ज़्यादा है, इसलिए प्रोजेक्ट मैनेजर की मंज़ूरी लगेगी।';

  @override
  String get measurementEdit => 'बदलें';

  @override
  String get measurementEmptyBody =>
      'आपकी पहली एंट्री से यह पुस्तिका शुरू होगी।';

  @override
  String get measurementEmptyFilteredBody => 'इस स्थिति की कोई एंट्री नहीं।';

  @override
  String get measurementEmptyFilteredTitle => 'यहाँ कुछ नहीं';

  @override
  String get measurementEmptyTitle => 'अभी कोई माप नहीं';

  @override
  String get measurementFilterAll => 'सभी';

  @override
  String get measurementFormulaHint => 'शुरू करने के लिए नग भरें';

  @override
  String get measurementGoneBody => 'शायद यह वेब पर हटा दी गई है।';

  @override
  String get measurementGoneTitle => 'एंट्री नहीं मिली';

  @override
  String get measurementItem => 'आइटम';

  @override
  String get measurementLength => 'लंबाई';

  @override
  String get measurementLengthShort => 'लंबाई';

  @override
  String get measurementLocationHint => 'ग्रिड, मंज़िल, फ़ुटिंग';

  @override
  String get measurementLocationLabel => 'स्थान';

  @override
  String get measurementLockedBody => 'यह मंज़ूर हो चुकी है या जाँच में है।';

  @override
  String get measurementLockedTitle => 'यह एंट्री बदली नहीं जा सकती';

  @override
  String get measurementNet => 'कुल';

  @override
  String get measurementNext => 'आगे';

  @override
  String get measurementNoBoqBody =>
      'एक बार नेटवर्क से जुड़ें, ताकि BOQ इस फ़ोन पर आ जाए।';

  @override
  String get measurementNoBoqTitle => 'BOQ लाइनें अभी नहीं हैं';

  @override
  String get measurementNoMatchTitle => 'कुछ नहीं मिला';

  @override
  String get measurementNos => 'नग';

  @override
  String get measurementOffline => 'नेटवर्क नहीं है।';

  @override
  String get measurementOfflineDetail =>
      'एंट्री फ़ोन पर रहेंगी, नेटवर्क आते ही चली जाएँगी।';

  @override
  String get measurementOtherDate => 'दूसरी तारीख़';

  @override
  String get measurementPreviewNote =>
      'इस फ़ोन के आँकड़े अनुमानित हैं। सर्वर के आँकड़े ही अंतिम हैं।';

  @override
  String get measurementPreviewTag => 'अनुमानित';

  @override
  String get measurementProblemFuture => 'तारीख़ भविष्य की नहीं हो सकती।';

  @override
  String get measurementProblemLocked =>
      'यह एंट्री पहले ही मंज़ूरी के लिए जा चुकी है।';

  @override
  String get measurementProblemNoLine => 'पहले BOQ लाइन चुनें।';

  @override
  String get measurementProblemNos => 'नग भरें। यह ज़रूरी है।';

  @override
  String get measurementProblemPositive =>
      'यह आँकड़ा शून्य से ज़्यादा होना चाहिए।';

  @override
  String get measurementProblemTooLarge => 'यह आँकड़ा बहुत बड़ा है।';

  @override
  String get measurementProblemUnknown =>
      'सहेजा नहीं जा सका। फिर से कोशिश करें।';

  @override
  String get measurementQuantity => 'मात्रा';

  @override
  String get measurementRecentLines => 'हाल के';

  @override
  String measurementRowCount(int count) {
    return '$count एंट्री';
  }

  @override
  String get measurementSavedOffline =>
      'फ़ोन पर सहेजा गया। नेटवर्क आते ही चला जाएगा।';

  @override
  String get measurementSavedOnPhone => 'फ़ोन पर सहेजा गया।';

  @override
  String get measurementSearchHint => 'जैसे 2.1 या स्लैब';

  @override
  String get measurementSearchLines => 'कोड या विवरण खोजें';

  @override
  String get measurementSendAgain => 'फिर से भेजें';

  @override
  String measurementSendCount(int count) {
    return '$count पंक्तियाँ भेजें';
  }

  @override
  String get measurementSendForApproval => 'मंज़ूरी के लिए भेजें';

  @override
  String get measurementSendsWhenOnline =>
      'अभी नहीं गया। नेटवर्क आते ही मंज़ूरी के लिए जाएगा।';

  @override
  String get measurementStampSent => 'भेजा गया';

  @override
  String get measurementSubmitWaitingTitle =>
      'मंज़ूरी को जाने की प्रतीक्षा में';

  @override
  String get measurementSyncAttention => 'देखना ज़रूरी';

  @override
  String get measurementSyncSending => 'जा रहा है';

  @override
  String measurementTally(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count पंक्तियाँ फ़ोन पर सहेजी गईं',
      one: '1 पंक्ति फ़ोन पर सहेजी गई',
    );
    return '$_temp0';
  }

  @override
  String get measurementYesterday => 'कल';

  @override
  String get pettyCashAddExpense => 'ख़र्च जोड़ें';

  @override
  String get pettyCashAmount => 'रकम';

  @override
  String get pettyCashAmountRequired => 'शून्य से ज़्यादा रकम डालें।';

  @override
  String get pettyCashBalance => 'शेष';

  @override
  String pettyCashBalanceAfter(String amount) {
    return 'इसके बाद शेष: $amount';
  }

  @override
  String get pettyCashBillAdded => 'बिल की फ़ोटो जुड़ी';

  @override
  String get pettyCashBillFailed => 'कैमरा नहीं खुला। फिर से कोशिश करें।';

  @override
  String get pettyCashBillLabel => 'बिल';

  @override
  String get pettyCashBillRetake => 'फिर से लें';

  @override
  String get pettyCashCategory => 'किसके लिए?';

  @override
  String get pettyCashCategoryRequired => 'किसके लिए, चुनें।';

  @override
  String get pettyCashDate => 'तारीख़';

  @override
  String get pettyCashDayToday => 'आज';

  @override
  String get pettyCashDayYesterday => 'कल';

  @override
  String get pettyCashEntries => 'प्रविष्टियाँ';

  @override
  String get pettyCashEntriesEmptyMessage =>
      'आपके जोड़े गए ख़र्च यहाँ दिखेंगे।';

  @override
  String get pettyCashEntriesEmptyTitle => 'अभी कोई प्रविष्टि नहीं';

  @override
  String get pettyCashEyebrow => 'नकद बही';

  @override
  String get pettyCashFormEyebrow => 'नया ख़र्च';

  @override
  String get pettyCashFormTitle => 'ख़र्च जोड़ें';

  @override
  String get pettyCashLoadFailedTitle => 'फुटकर नकद नहीं खुला';

  @override
  String get pettyCashNext => 'आगे';

  @override
  String get pettyCashNoProjectMessage =>
      'पहले प्रोजेक्ट चुनें, फिर यहाँ ख़र्च दर्ज करें।';

  @override
  String get pettyCashNoProjectTitle => 'कोई प्रोजेक्ट नहीं चुना';

  @override
  String get pettyCashNoWalletMessage =>
      'अपने लिए वॉलेट खुलवाने के लिए प्रोजेक्ट मैनेजर या अकाउंट्स ऑफ़िस से कहें।';

  @override
  String get pettyCashNoWalletTitle => 'इस प्रोजेक्ट पर आपका वॉलेट नहीं है';

  @override
  String get pettyCashNote => 'नोट (वैकल्पिक)';

  @override
  String get pettyCashNoteHint => 'मिक्सर के लिए डीज़ल';

  @override
  String pettyCashOverBalance(String balance) {
    return 'यह रकम वॉलेट के $balance से ज़्यादा है। ऑफ़िस से राशि जुड़वाएँ।';
  }

  @override
  String get pettyCashPhotoBill => 'बिल की फ़ोटो';

  @override
  String get pettyCashProvisional => 'अनंतिम';

  @override
  String pettyCashProvisionalNote(String amount) {
    return '$amount अभी भेजे नहीं गए। सिंक होने पर अंतिम शेष पता चलेगा।';
  }

  @override
  String get pettyCashSave => 'ख़र्च सेव करें';

  @override
  String get pettyCashSaveFailed => 'ख़र्च सेव नहीं हुआ। फिर से कोशिश करें।';

  @override
  String get pettyCashSavedOffline =>
      'फ़ोन पर सेव हुआ। नेटवर्क आने पर भेजा जाएगा।';

  @override
  String get pettyCashSavedOnline => 'फ़ोन पर सेव हुआ। अभी भेज रहे हैं।';

  @override
  String get pettyCashSyncAttention => 'ध्यान दें';

  @override
  String get pettyCashSyncSending => 'भेज रहे हैं';

  @override
  String get pettyCashTitle => 'फुटकर नकद';

  @override
  String get pettyCashTopup => 'राशि जोड़ें';

  @override
  String get pettyCashWallet => 'वॉलेट';

  @override
  String get shellDiscardBody =>
      'यह सर्वर तक नहीं पहुँची है। इसे इस फ़ोन से हटा दिया जाएगा।';

  @override
  String get shellDiscardConfirm => 'हाँ, हटाएँ';

  @override
  String get shellDiscardKeep => 'रहने दें';

  @override
  String get shellDiscardTitle => 'यह प्रविष्टि हटाएँ?';

  @override
  String get shellOfflineDetail => 'प्रविष्टियाँ फ़ोन पर रहेंगी।';

  @override
  String get shellOfflineLabel => 'ऑफ़लाइन';

  @override
  String get shellOutboxAllSentBody => 'इस फ़ोन पर कुछ बाकी नहीं।';

  @override
  String get shellOutboxAllSentStamp => 'सब भेजा';

  @override
  String shellOutboxAttention(int count) {
    return '$count प्रविष्टियों पर ध्यान दें';
  }

  @override
  String get shellOutboxLogTitle => 'लागू नहीं हुआ';

  @override
  String get shellOutboxNeverSynced => 'अभी सिंक नहीं हुआ';

  @override
  String get shellOutboxServerError =>
      'सर्वर में दिक्कत है। आपका काम सुरक्षित है, बाद में भेजा जाएगा।';

  @override
  String get shellOutboxTitle => 'आउटबॉक्स';

  @override
  String get shellOutboxTrayLabel => 'फ़ोन पर';

  @override
  String get shellOutboxWaitingLabel => 'भेजना बाकी';

  @override
  String get shellPickerCurrent => 'चालू';

  @override
  String get shellPickerEmptyBody =>
      'कार्यालय से आपको परियोजना में जोड़ने को कहें, फिर कोशिश करें।';

  @override
  String get shellPickerEmptyTitle => 'अभी कोई परियोजना नहीं';

  @override
  String get shellPickerEyebrow => 'आपकी परियोजनाएँ';

  @override
  String get shellPickerLoading => 'आपकी परियोजनाएँ ला रहे हैं…';

  @override
  String get shellPickerTitle => 'आज कौन सी साइट?';

  @override
  String get shellPillAllSent => 'सब भेज दिया';

  @override
  String get shellPillAttention => 'देखें';

  @override
  String get shellPillSending => 'भेज रहे हैं';

  @override
  String get shellPillWaiting => 'बाकी';

  @override
  String get shellReportAttention => 'देखें';

  @override
  String get shellReportNotStarted => 'शुरू नहीं';

  @override
  String get shellReportSaved => 'सहेजा गया';

  @override
  String shellReturnedDpr(String date) {
    return 'दैनिक रिपोर्ट · $date';
  }

  @override
  String get shellReturnedFix => 'सुधारें';

  @override
  String shellReturnedMeasurement(String code, String qty, String unit) {
    return 'माप $code · $qty $unit';
  }

  @override
  String get shellReturnedNoRemark => 'कोई टिप्पणी नहीं';

  @override
  String get shellReturnedRemarkLabel => 'टिप्पणी';

  @override
  String get shellReturnedTitle => 'आपको वापस भेजा गया';

  @override
  String get shellSettings => 'सेटिंग्स';

  @override
  String get shellSettingsLanguage => 'भाषा';

  @override
  String get shellSettingsSignedIn => 'साइन इन';

  @override
  String get shellSettingsVersion => 'ऐप वर्ज़न';

  @override
  String get shellSignInBusy => 'साइन इन हो रहा है…';

  @override
  String get shellSignInButton => 'साइन इन करें';

  @override
  String get shellSignInEmail => 'ईमेल';

  @override
  String get shellSignInEmailRequired => 'अपना ईमेल लिखें';

  @override
  String get shellSignInExpired =>
      'आपको साइन आउट कर दिया गया। कुछ नहीं खोया: आपका काम इस फ़ोन पर सुरक्षित है। भेजने के लिए फिर साइन इन करें।';

  @override
  String get shellSignInHide => 'छिपाएँ';

  @override
  String get shellSignInNetworkBody => 'सिग्नल जाँचकर फिर कोशिश करें।';

  @override
  String get shellSignInNetworkTitle => 'नेटवर्क नहीं है';

  @override
  String shellSignInOtherUserBody(int count) {
    return 'इस फ़ोन पर किसी और की $count प्रविष्टियाँ भेजनी बाकी हैं। उन्हें पहले साइन इन करके भेजना होगा।';
  }

  @override
  String get shellSignInOtherUserTitle => 'किसी और का काम बाकी है';

  @override
  String get shellSignInPassword => 'पासवर्ड';

  @override
  String get shellSignInPasswordRequired => 'अपना पासवर्ड लिखें';

  @override
  String get shellSignInServerBody =>
      'आपके फ़ोन की गलती नहीं है। कुछ देर बाद फिर कोशिश करें।';

  @override
  String get shellSignInServerTitle => 'सर्वर जवाब नहीं दे रहा';

  @override
  String get shellSignInShow => 'दिखाएँ';

  @override
  String get shellSignInTooManyBody => 'एक मिनट रुककर फिर कोशिश करें।';

  @override
  String get shellSignInTooManyTitle => 'बहुत बार कोशिश हुई';

  @override
  String get shellSignInWelcome => 'नमस्ते';

  @override
  String get shellSignInWrongBody => 'दोनों जाँचकर फिर कोशिश करें।';

  @override
  String get shellSignInWrongTitle => 'ईमेल या पासवर्ड गलत है';

  @override
  String get shellSignOut => 'साइन आउट';

  @override
  String get shellSignOutConfirmBody =>
      'फिर साइन इन करने के लिए नेटवर्क चाहिए।';

  @override
  String get shellSignOutConfirmTitle => 'इस फ़ोन से साइन आउट करें?';

  @override
  String get shellSignOutDiscard => 'साइन आउट कर मिटाएँ';

  @override
  String get shellSignOutHold => 'मिटाने के लिए दबाए रखें';

  @override
  String get shellSignOutSendFirst => 'पहले भेजें';

  @override
  String get shellSignOutUnsentBody =>
      'अभी साइन आउट करने पर वे इस फ़ोन से हमेशा के लिए मिट जाएँगी। पहले उन्हें भेजें।';

  @override
  String shellSignOutUnsentTitle(int count) {
    return '$count प्रविष्टियाँ नहीं भेजी गईं';
  }

  @override
  String get shellSlipSending => 'भेज रहे हैं';

  @override
  String get shellSlipWaiting => 'बाकी';

  @override
  String get shellSwitchProject => 'परियोजना बदलें';

  @override
  String get shellSyncingLabel => 'भेज रहे हैं और अपडेट कर रहे हैं';

  @override
  String get shellTabApprovals => 'मंज़ूरी';

  @override
  String get shellTabHome => 'होम';

  @override
  String get shellTabMeasure => 'माप';

  @override
  String get shellTabOutbox => 'आउटबॉक्स';

  @override
  String get shellTileApprovals => 'मंज़ूरी';

  @override
  String get shellTileAttendance => 'हाज़िरी';

  @override
  String get shellTileDaily => 'दैनिक रिपोर्ट';

  @override
  String get shellTileIssues => 'समस्याएँ';

  @override
  String get shellTileMeasure => 'माप';

  @override
  String get shellTilePetty => 'फुटकर नकद';

  @override
  String shellTimeDays(int count) {
    return '$count दिन पहले';
  }

  @override
  String shellTimeHours(int count) {
    return '$count घंटे पहले';
  }

  @override
  String get shellTimeJustNow => 'अभी';

  @override
  String shellTimeMinutes(int count) {
    return '$count मिनट पहले';
  }

  @override
  String get shellTimeYesterday => 'कल';

  @override
  String get shellTodayAttendance => 'हाज़िरी';

  @override
  String get shellTodayExpenses => 'खर्च';

  @override
  String get shellTodayMeasured => 'माप';

  @override
  String get shellTodayReport => 'रिपोर्ट';

  @override
  String shellTodaySemantics(String label, String value) {
    return '$label: $value';
  }

  @override
  String get statusApproved => 'मंज़ूर';

  @override
  String get statusDraft => 'मसौदा';

  @override
  String get statusInReview => 'समीक्षा में';

  @override
  String get statusQueued => 'फ़ोन पर';

  @override
  String get statusRejected => 'अस्वीकृत';

  @override
  String get statusReturned => 'वापस भेजा';

  @override
  String get statusSynced => 'भेजा गया';

  @override
  String get syncAllSent => 'सब भेज दिया';

  @override
  String syncAttentionCount(int count) {
    return '$count पर ध्यान दें';
  }

  @override
  String get syncBlocked => 'पिछली प्रविष्टि की प्रतीक्षा में';

  @override
  String get syncDiscard => 'रद्द करें';

  @override
  String get syncInProgress => 'भेज रहे हैं…';

  @override
  String syncLastSynced(String time) {
    return 'पिछला सिंक $time';
  }

  @override
  String get syncNow => 'अभी सिंक करें';

  @override
  String get syncOffline => 'नेटवर्क नहीं है। आपका काम इस फ़ोन पर सुरक्षित है।';

  @override
  String syncPendingCount(int count) {
    return '$count भेजना बाकी';
  }

  @override
  String syncProgress(int done, int total) {
    return '$total में से $done भेजे';
  }

  @override
  String get syncPulling => 'नए आँकड़े ले रहे हैं…';

  @override
  String get syncRateLimited => 'सर्वर व्यस्त है। भेजना जल्द फिर शुरू होगा।';

  @override
  String get syncReasonApproved =>
      'यह रिकॉर्ड पहले ही मंज़ूर हो चुका है। आपका बदलाव लागू नहीं हुआ।';

  @override
  String get syncReasonFileMissing => 'फ़ोटो इस फ़ोन पर नहीं मिली।';

  @override
  String get syncReasonForbidden => 'आपको यह करने की अनुमति नहीं है।';

  @override
  String get syncReasonInReview =>
      'यह रिकॉर्ड मंज़ूरी के लिए गया है। आपका बदलाव लागू नहीं हुआ।';

  @override
  String get syncReasonParentMissing =>
      'जिस रिकॉर्ड की यह फ़ाइल है वह सर्वर पर नहीं मिला।';

  @override
  String get syncReasonPlanFeature =>
      'आपके प्लान में यह मॉड्यूल शामिल नहीं है।';

  @override
  String get syncReasonRejected =>
      'यह रिकॉर्ड अस्वीकृत हुआ। आपका बदलाव लागू नहीं हुआ।';

  @override
  String get syncReasonUnexpected => 'सर्वर से अप्रत्याशित उत्तर मिला।';

  @override
  String get syncReasonValidation =>
      'सर्वर ने यह प्रविष्टि स्वीकार नहीं की। कृपया सुधारें।';

  @override
  String get syncRetry => 'फिर से कोशिश करें';

  @override
  String get syncSignInAgain =>
      'भेजना जारी रखने के लिए फिर साइन इन करें। आपका काम सुरक्षित है।';

  @override
  String get syncStaleLog =>
      'सर्वर पर इससे नया बदलाव था, इसलिए यह लागू नहीं हुआ।';

  @override
  String get syncTypeAttendance => 'हाज़िरी';

  @override
  String get syncTypeDpr => 'दैनिक रिपोर्ट';

  @override
  String get syncTypeDprPhoto => 'रिपोर्ट फ़ोटो';

  @override
  String get syncTypeDprSubmit => 'रिपोर्ट जमा करना';

  @override
  String get syncTypeIssue => 'समस्या';

  @override
  String get syncTypeIssueStatus => 'समस्या की स्थिति';

  @override
  String get syncTypeMeasurement => 'माप';

  @override
  String get syncTypeMeasurementSubmit => 'माप जमा करना';

  @override
  String get syncTypePettyBill => 'बिल की फ़ोटो';

  @override
  String get syncTypePettyCash => 'फुटकर नकद प्रविष्टि';
}
