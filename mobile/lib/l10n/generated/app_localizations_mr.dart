// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Marathi (`mr`).
class AppLocalizationsMr extends AppLocalizations {
  AppLocalizationsMr([String locale = 'mr']) : super(locale);

  @override
  String get appName => 'निर्माण360';

  @override
  String get approvalsActionApproved => 'मंजूर केले';

  @override
  String get approvalsActionRejected => 'नाकारले';

  @override
  String get approvalsActionReturned => 'परत पाठवले';

  @override
  String get approvalsActionSubmitted => 'पाठवले';

  @override
  String get approvalsApprove => 'मंजूर करा';

  @override
  String get approvalsBackToInbox => 'मंजुरीकडे परत';

  @override
  String get approvalsBillNumber => 'बिल क्रमांक';

  @override
  String get approvalsBillType => 'प्रकार';

  @override
  String get approvalsChainNext => 'त्यानंतर';

  @override
  String get approvalsChainTitle => 'मंजुरीची नोंद';

  @override
  String get approvalsChainWaiting => 'आता बाकी';

  @override
  String get approvalsCouldNotRefresh =>
      'ताजे करता आले नाही. पुन्हा प्रयत्न करा.';

  @override
  String get approvalsCouldNotRefreshOffline =>
      'सर्व्हरशी संपर्क झाला नाही. शेवटची माहिती दाखवत आहे.';

  @override
  String approvalsDaysAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count दिवसांपूर्वी',
      one: '1 दिवसापूर्वी',
    );
    return '$_temp0';
  }

  @override
  String get approvalsDeductions => 'वजावटी';

  @override
  String get approvalsDeviation => 'तफावत';

  @override
  String get approvalsDeviationBody =>
      'BOQ प्रमाण आणि सवलतीपेक्षा जास्त. प्रकल्प व्यवस्थापकाची मंजुरी आवश्यक.';

  @override
  String get approvalsDocBill => 'RA बिल';

  @override
  String get approvalsDocBillNote => 'क्रेडिट / डेबिट नोट';

  @override
  String get approvalsDocDpr => 'दैनिक अहवाल';

  @override
  String get approvalsDocIndent => 'इंडेंट';

  @override
  String get approvalsDocMeasurement => 'मोजमाप';

  @override
  String get approvalsDocOther => 'दस्तऐवज';

  @override
  String get approvalsDocRfi => 'RFI';

  @override
  String get approvalsDocVendorBill => 'विक्रेत्याचे बिल';

  @override
  String get approvalsEmptyBody => 'नवीन मंजुरी इथे दिसतील.';

  @override
  String get approvalsEmptyTitle => 'काहीही बाकी नाही';

  @override
  String get approvalsFailOfflineBody =>
      'तुमचा निर्णय गेला नाही. काहीही रांगेत नाही. नेटवर्क आल्यावर पुन्हा करा.';

  @override
  String get approvalsFailOfflineTitle => 'नेटवर्क नाही';

  @override
  String get approvalsFailRaceBody =>
      'दुसऱ्या कोणीतरी आधी निर्णय घेतला. आता काय बाकी आहे ते पाहण्यासाठी ताजे करा.';

  @override
  String get approvalsFailRaceTitle => 'आधीच निर्णय झाला';

  @override
  String get approvalsFailRejectedTitle => 'सर्व्हरने हे स्वीकारले नाही';

  @override
  String get approvalsFailServerBody =>
      'सर्व्हरवर अडचण आली. तुमचा निर्णय नोंदला गेला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get approvalsFailServerTitle => 'पाठवता आले नाही';

  @override
  String get approvalsFailSessionBody =>
      'तुमचे सत्र संपले आहे. तुमचा निर्णय गेला नाही.';

  @override
  String get approvalsFailSessionTitle => 'कृपया पुन्हा साइन इन करा';

  @override
  String get approvalsForYou => 'तुमच्याकडे बाकी';

  @override
  String get approvalsGoneBody => 'यावर आधीच निर्णय झाला आहे.';

  @override
  String get approvalsGoneTitle => 'आता बाकी नाही';

  @override
  String get approvalsGross => 'कामाचे एकूण मूल्य';

  @override
  String get approvalsGst => 'GST';

  @override
  String get approvalsHindrances => 'अडथळे';

  @override
  String approvalsHoursAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count तासांपूर्वी',
      one: '1 तासापूर्वी',
    );
    return '$_temp0';
  }

  @override
  String get approvalsJustNow => 'आत्ताच';

  @override
  String get approvalsManpower => 'मनुष्यबळ';

  @override
  String approvalsMinutesAgo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count मिनिटांपूर्वी',
      one: '1 मिनिटापूर्वी',
    );
    return '$_temp0';
  }

  @override
  String get approvalsNeedsNetwork =>
      'मंजूर किंवा परत पाठवण्यासाठी नेटवर्क लागते. काहीही रांगेत ठेवले जात नाही.';

  @override
  String get approvalsNetPayable => 'देय रक्कम';

  @override
  String get approvalsNoteAgainstBill => 'संबंधित बिल';

  @override
  String get approvalsNoteCredit => 'क्रेडिट नोट';

  @override
  String get approvalsNoteDebit => 'डेबिट नोट';

  @override
  String get approvalsNoteEffect => 'बिलावर परिणाम';

  @override
  String get approvalsNoteNumber => 'नोट क्रमांक';

  @override
  String get approvalsNoteReason => 'कारण';

  @override
  String get approvalsNoteTaxable => 'करपात्र रक्कम';

  @override
  String get approvalsNoteTotal => 'नोटची एकूण रक्कम';

  @override
  String get approvalsOffline => 'नेटवर्क नाही.';

  @override
  String get approvalsOfflineDetail =>
      'वाचता येईल, पण मंजुरीसाठी नेटवर्क लागते.';

  @override
  String get approvalsOnOthers => 'इतरांकडे बाकी';

  @override
  String get approvalsOpen => 'उघडा';

  @override
  String get approvalsOpenOnWeb =>
      'हा दस्तऐवज अजून फोनवर दिसत नाही. कृपया वेबसाइटवर तपासून मंजूर करा.';

  @override
  String get approvalsParty => 'पक्षकार';

  @override
  String get approvalsPeriod => 'कालावधी';

  @override
  String get approvalsPhotos => 'फोटो';

  @override
  String get approvalsReasonAmounts => 'रकमा जुळत नाहीत';

  @override
  String get approvalsReasonDeductions => 'वजावटी तपासा';

  @override
  String get approvalsReasonDimensions => 'मापे तपासा';

  @override
  String get approvalsReasonLocation => 'ठिकाण स्पष्ट लिहा';

  @override
  String get approvalsReasonManpower => 'मनुष्यबळाची संख्या चुकली आहे';

  @override
  String get approvalsReasonNote => 'कारण स्पष्ट नाही';

  @override
  String get approvalsReasonPeriod => 'बिलाचा कालावधी चुकला आहे';

  @override
  String get approvalsReasonPhotos => 'फोटो जोडा';

  @override
  String get approvalsReasonQuantityHigh => 'प्रमाण जास्त वाटते';

  @override
  String get approvalsReasonWorkDetail => 'कामाचा अधिक तपशील लिहा';

  @override
  String get approvalsReasonWrongItem => 'BOQ ओळ चुकली आहे';

  @override
  String get approvalsRefresh => 'ताजे करा';

  @override
  String get approvalsRefreshing => 'ताजे करत आहे';

  @override
  String get approvalsRemarkHint => 'काय दुरुस्त करायचे';

  @override
  String get approvalsRemarkLabel => 'शेरा';

  @override
  String get approvalsRemarks => 'शेरा';

  @override
  String get approvalsRoleAccounts => 'लेखा';

  @override
  String get approvalsRoleAdmin => 'अॅडमिन';

  @override
  String get approvalsRoleClient => 'क्लायंट';

  @override
  String get approvalsRoleEngineer => 'साइट अभियंता';

  @override
  String get approvalsRoleOwner => 'मालक';

  @override
  String get approvalsRolePm => 'प्रकल्प व्यवस्थापक';

  @override
  String get approvalsRoleQs => 'एस्टिमेटर / QS';

  @override
  String get approvalsSendBack => 'परत पाठवा';

  @override
  String get approvalsSendBackHelp =>
      'काय दुरुस्त करायचे ते सांगा. एक निवडा किंवा स्वतः लिहा.';

  @override
  String get approvalsSendBackTitle => 'परत का पाठवत आहात?';

  @override
  String get approvalsSentBackToast => 'तुमच्या शेऱ्यासह परत पाठवले.';

  @override
  String get approvalsSessionExpired => 'कृपया पुन्हा साइन इन करा.';

  @override
  String get approvalsSummary => 'कामाचा सारांश';

  @override
  String get approvalsTitle => 'मंजुरी';

  @override
  String get approvalsTotal => 'एकूण';

  @override
  String get approvalsWeather => 'हवामान';

  @override
  String get approvalsWorkDone => 'झालेले काम';

  @override
  String get approvalsWorkers => 'कामगार';

  @override
  String get attendanceAddFirstWorker => 'पहिला कामगार जोडा';

  @override
  String get attendanceAddWorker => 'कामगार जोडा';

  @override
  String get attendanceAllMarked => 'सर्वांची हजेरी झाली';

  @override
  String attendanceAllPresent(int count) {
    return 'सर्व हजर ($count)';
  }

  @override
  String attendanceAllPresentDone(int count) {
    return '$count जण हजर नोंदवले';
  }

  @override
  String get attendanceAllPresentHelper =>
      'ज्यांची हजेरी लागली आहे त्यांना वगळते.';

  @override
  String get attendanceChooseProject => 'प्रकल्प निवडा';

  @override
  String attendanceDateTitle(String date) {
    return '$date ची हजेरी';
  }

  @override
  String get attendanceDayToday => 'आज';

  @override
  String get attendanceDayYesterday => 'काल';

  @override
  String get attendanceEyebrow => 'हजेरी पत्रक';

  @override
  String attendanceGroupProgress(int marked, int total) {
    return '$total पैकी $marked';
  }

  @override
  String get attendanceHoursUnit => 'तास';

  @override
  String get attendanceLeft => 'बाकी';

  @override
  String get attendanceLoadFailedMessage =>
      'तुमच्या नोंदी या फोनवर सुरक्षित आहेत. पुन्हा प्रयत्न करा.';

  @override
  String get attendanceLoadFailedTitle => 'हजेरी उघडता आली नाही';

  @override
  String get attendanceLoading => 'हजेरी लोड होत आहे';

  @override
  String get attendanceMarkedSoFar => 'आतापर्यंत नोंदवले';

  @override
  String get attendanceNoProjectMessage =>
      'आधी प्रकल्प निवडा, मग इथे हजेरी लावा.';

  @override
  String get attendanceNoProjectTitle => 'प्रकल्प निवडलेला नाही';

  @override
  String get attendanceNoWorkersAsk => 'ऑफिसला कामगार जोडायला सांगा.';

  @override
  String get attendanceNoWorkersMessage =>
      'हजेरी सुरू करण्यासाठी पहिला कामगार जोडा.';

  @override
  String get attendanceNoWorkersTitle => 'हजेरीत एकही कामगार नाही';

  @override
  String get attendanceOtHours => 'ओव्हरटाइम तास';

  @override
  String get attendanceOtLess => 'अर्धा तास कमी';

  @override
  String get attendanceOtMore => 'अर्धा तास जास्त';

  @override
  String get attendanceSaveFailed => 'ती नोंद जतन झाली नाही. पुन्हा टॅप करा.';

  @override
  String attendanceSheetDone(String name) {
    return '$name हजेरीत जोडले';
  }

  @override
  String get attendanceSheetFailed =>
      'कामगार जोडता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get attendanceSheetName => 'कामगाराचे नाव';

  @override
  String get attendanceSheetNameHint => 'हजेरीत आहे तसे';

  @override
  String get attendanceSheetNameRequired => 'कामगाराचे नाव लिहा.';

  @override
  String get attendanceSheetOfflineError =>
      'नेटवर्क नाही. फोन ऑनलाइन आल्यावर पुन्हा प्रयत्न करा.';

  @override
  String get attendanceSheetOfflineMessage =>
      'हजेरी नेटवर्कशिवायही चालते. फोन ऑनलाइन आल्यावर हा कामगार जोडा.';

  @override
  String get attendanceSheetOfflineTitle => 'कामगार जोडायला नेटवर्क हवे';

  @override
  String get attendanceSheetTitle => 'नवीन कामगार';

  @override
  String get attendanceSheetTrade => 'कामाचा प्रकार';

  @override
  String get attendanceSheetTradeRequired => 'कामाचा प्रकार निवडा.';

  @override
  String attendanceSheetWageFor(String name) {
    return '$name यांची दैनिक मजुरी';
  }

  @override
  String get attendanceSheetWageHelper => 'माहीत नसेल तर रिकामे ठेवा.';

  @override
  String get attendanceTitle => 'हजेरी';

  @override
  String get attendanceTodayTitle => 'आजची हजेरी';

  @override
  String get boqAddItem => 'आयटम जोडा';

  @override
  String boqBalance(String qty, String unit) {
    return 'शिल्लक $qty $unit';
  }

  @override
  String get boqBalanceLabel => 'शिल्लक';

  @override
  String get boqEmptyAsk =>
      'BOQ म्हणजे या प्रकल्पातील कामांची यादी. ऑफिसला ती जोडायला सांगा.';

  @override
  String get boqEmptyExplain =>
      'BOQ म्हणजे या प्रकल्पातील कामांची यादी, परिमाण आणि दरासह.';

  @override
  String get boqEmptyTitle => 'BOQ ओळी अजून नाहीत';

  @override
  String get boqFormAdded => 'ओळ जोडली. पुढची जोडा.';

  @override
  String get boqFormAmount => 'रक्कम';

  @override
  String get boqFormCode => 'कोड (ऐच्छिक)';

  @override
  String get boqFormCodeHelper => 'रिकामे ठेवले तर पुढचा क्रमांक मिळेल.';

  @override
  String get boqFormDescription => 'कामाचे वर्णन';

  @override
  String get boqFormDescriptionHint => 'उदा. स्लॅबमध्ये M25 काँक्रीट';

  @override
  String get boqFormDescriptionRequired => 'कामाचे वर्णन लिहा.';

  @override
  String get boqFormFailed => 'ओळ जतन करता आली नाही. पुन्हा प्रयत्न करा.';

  @override
  String get boqFormFinish => 'जतन करून संपवा';

  @override
  String get boqFormQty => 'परिमाण';

  @override
  String get boqFormQtyRequired => 'परिमाण टाका.';

  @override
  String get boqFormRate => 'दर';

  @override
  String get boqFormRateRequired => 'दर टाका.';

  @override
  String get boqFormSaveAnother => 'जतन करून आणखी जोडा';

  @override
  String get boqFormSection => 'विभाग (ऐच्छिक)';

  @override
  String get boqFormSectionHint => 'उदा. सुपरस्ट्रक्चर';

  @override
  String get boqFormTitle => 'BOQ आयटम जोडा';

  @override
  String get boqFormUnit => 'एकक';

  @override
  String get boqFormUnitRequired => 'एकक निवडा.';

  @override
  String boqItemCode(String code) {
    return 'आयटम $code';
  }

  @override
  String boqLinesAdded(int count) {
    return '$count ओळी जोडल्या';
  }

  @override
  String get boqLoading => 'BOQ लोड होत आहे';

  @override
  String boqMeasuredShare(int percent) {
    return '$percent% मोजले';
  }

  @override
  String get boqMissingAsk =>
      'ऑफिसला जोडायला सांगा. आधीच जोडल्या असतील तर एकदा नेटवर्कला जोडा.';

  @override
  String get boqMissingManage => 'ओळी जोडा, मग इथे निवडा.';

  @override
  String get boqMissingTitle => 'या प्रकल्पात अजून BOQ ओळी नाहीत';

  @override
  String get boqNoMatchMessage => 'दुसरा कोड किंवा शब्द वापरून पाहा.';

  @override
  String get boqNoMatchTitle => 'काही सापडले नाही';

  @override
  String get boqNoProjectMessage => 'BOQ पाहण्यासाठी आधी प्रकल्प निवडा.';

  @override
  String get boqNoProjectTitle => 'प्रकल्प निवडलेला नाही';

  @override
  String get boqNoSection => 'इतर ओळी';

  @override
  String get boqOfflineDetail => 'BOQ ओळी फक्त ऑनलाइन जतन होतात.';

  @override
  String get boqOfflineTitle => 'नेटवर्क नाही.';

  @override
  String get boqOpenAction => 'BOQ उघडा';

  @override
  String boqOver(String qty, String unit) {
    return '$qty $unit जास्त';
  }

  @override
  String get boqOverLabel => 'जास्त';

  @override
  String get boqRefreshFailed =>
      'अपडेट करता आले नाही. फोनवर आहे तेच दाखवत आहे.';

  @override
  String get boqSearchHint => 'कोड किंवा काम';

  @override
  String get boqSearchLabel => 'ओळी शोधा';

  @override
  String get boqTitle => 'BOQ';

  @override
  String get commonBack => 'मागे';

  @override
  String get commonCancel => 'रद्द करा';

  @override
  String get commonDone => 'झाले';

  @override
  String get commonRetry => 'पुन्हा प्रयत्न करा';

  @override
  String get commonSave => 'जतन करा';

  @override
  String get commonToday => 'आज';

  @override
  String get designAttendanceAbsent => 'गैरहजर';

  @override
  String get designAttendanceHalf => 'अर्धा दिवस';

  @override
  String get designAttendanceOvertime => 'ओव्हरटाइम';

  @override
  String get designAttendancePresent => 'हजर';

  @override
  String get designExpenseFood => 'जेवण';

  @override
  String get designExpenseFuel => 'इंधन';

  @override
  String get designExpenseLabour => 'मजुरी';

  @override
  String get designExpenseMaterials => 'साहित्य';

  @override
  String get designExpenseOther => 'इतर';

  @override
  String get designExpenseRepairs => 'दुरुस्ती';

  @override
  String get designExpenseTools => 'हत्यारे';

  @override
  String get designExpenseTravel => 'प्रवास';

  @override
  String get designIssueClosed => 'बंद';

  @override
  String get designIssueInProgress => 'सुरू आहे';

  @override
  String get designIssueOpen => 'उघडे';

  @override
  String get designIssueResolved => 'सोडवले';

  @override
  String get designKeypadDelete => 'हटवा';

  @override
  String get designKeypadNext => 'पुढे';

  @override
  String get designPriorityCritical => 'गंभीर';

  @override
  String get designPriorityHigh => 'जास्त';

  @override
  String get designPriorityLow => 'कमी';

  @override
  String get designPriorityMedium => 'मध्यम';

  @override
  String get designStepperDecrease => 'कमी करा';

  @override
  String get designStepperIncrease => 'वाढवा';

  @override
  String get designTradeBarBender => 'बार बेंडर';

  @override
  String get designTradeCarpenter => 'सुतार';

  @override
  String get designTradeElectrician => 'इलेक्ट्रिशियन';

  @override
  String get designTradeHelper => 'मदतनीस';

  @override
  String get designTradeMason => 'गवंडी';

  @override
  String get designTradeOperator => 'ऑपरेटर';

  @override
  String get designTradePainter => 'रंगारी';

  @override
  String get designTradePlumber => 'प्लंबर';

  @override
  String get designTradeSupervisor => 'सुपरवायझर';

  @override
  String get designTradeWelder => 'वेल्डर';

  @override
  String get designWeatherClear => 'स्वच्छ';

  @override
  String get designWeatherCloudy => 'ढगाळ';

  @override
  String get designWeatherHot => 'उष्ण';

  @override
  String get designWeatherRain => 'पाऊस';

  @override
  String get designWeatherWindy => 'वारा';

  @override
  String get dprAdd => 'जोडा';

  @override
  String get dprAddNotes => 'अडथळे किंवा टीप लिहा';

  @override
  String get dprAddWork => 'काम जोडा';

  @override
  String get dprAddedTag => 'जोडले';

  @override
  String get dprApproverRemark => 'मंजुरी देणाऱ्याचा शेरा';

  @override
  String get dprAttentionBody =>
      'हा अहवाल पाठवता आला नाही. दुरुस्तीसाठी आउटबॉक्स उघडा.';

  @override
  String get dprBalance => 'शिल्लक';

  @override
  String dprBoqDonePercent(int percent) {
    return 'BOQ चे $percent टक्के मोजले';
  }

  @override
  String get dprBoqEmpty => 'BOQ आयटम सापडले नाहीत.';

  @override
  String get dprCameraFailed =>
      'कॅमेरा उघडला नाही. फोनच्या सेटिंगमध्ये कॅमेऱ्याला परवानगी द्या.';

  @override
  String get dprCanStillSend => 'तरीही पाठवता येईल.';

  @override
  String get dprChooseAnother => 'दुसरा आयटम';

  @override
  String get dprCorrectAndSend => 'दुरुस्त करून पुन्हा पाठवा';

  @override
  String get dprEdit => 'बदला';

  @override
  String get dprEmptyMessage => 'तुमचा पहिला अहवाल या वहीची सुरुवात करेल.';

  @override
  String get dprEmptyTitle => 'अजून अहवाल नाहीत';

  @override
  String get dprFilledFromYesterday => 'कालच्या नोंदीवरून भरले';

  @override
  String get dprFormTitle => 'दैनिक अहवाल';

  @override
  String get dprFutureDate => 'अहवालाची तारीख भविष्यातील असू शकत नाही.';

  @override
  String get dprHindrances => 'अडथळे';

  @override
  String get dprHindrancesHint => 'साहित्य उशिरा, पाऊस, वीज गेली';

  @override
  String get dprListTitle => 'दैनिक अहवाल';

  @override
  String get dprLoading => 'लोड होत आहे';

  @override
  String get dprLocked => 'हा अहवाल मंजुरीसाठी गेला आहे, आता बदलता येणार नाही.';

  @override
  String get dprMissedDays => 'राहिलेले दिवस';

  @override
  String dprMoreRows(int count) {
    return 'आणखी $count';
  }

  @override
  String dprMoreTrades(int count) {
    return 'आणखी कामाचे प्रकार ($count)';
  }

  @override
  String get dprNeedWeather => 'आजचे हवामान निवडा.';

  @override
  String get dprNeedWorkers => 'आलेल्या कामगारांची संख्या भरा.';

  @override
  String get dprNoPhotosYet => 'फोटो जोडलेले नाहीत.';

  @override
  String get dprNoProject => 'आधी प्रकल्प निवडा';

  @override
  String get dprNoWorkYet => 'काम जोडलेले नाही.';

  @override
  String get dprNotSaved => 'जतन झाले नाही';

  @override
  String get dprNotes => 'टीप';

  @override
  String get dprNotesHint => 'आजचे आणखी काही';

  @override
  String get dprNothingRecorded => 'काहीही नोंदवलेले नाही';

  @override
  String dprOfDays(int days) {
    return '$days दिवसांपैकी';
  }

  @override
  String get dprOpenOutbox => 'आउटबॉक्स उघडा';

  @override
  String get dprOpenToday => 'आजचा अहवाल उघडा';

  @override
  String get dprOptional => 'ऐच्छिक';

  @override
  String get dprOverBalance => 'BOQ शिलकीपेक्षा जास्त आहे. आकडा तपासा.';

  @override
  String get dprPhotoFailed => 'हा फोटो जोडता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get dprPhotoMissing => 'उपलब्ध नाही';

  @override
  String dprPhotosCount(int count) {
    return '$count फोटो';
  }

  @override
  String get dprPhotosFull => 'एका अहवालात 10 फोटोपर्यंत ठेवता येतात.';

  @override
  String get dprQtyShort => 'प्रमाण';

  @override
  String get dprQuantity => 'प्रमाण';

  @override
  String get dprRemove => 'काढा';

  @override
  String get dprRemoved => 'काढले';

  @override
  String get dprReportsThisMonth => 'या महिन्याचे अहवाल';

  @override
  String get dprReturnedNoRemark => 'कृपया दुरुस्त करून पुन्हा पाठवा.';

  @override
  String get dprReturnedTitle => 'दुरुस्तीसाठी परत पाठवले';

  @override
  String get dprReviewOffline =>
      'आत्ता नेटवर्क नाही. अहवाल या फोनवर राहील आणि नेटवर्क आल्यावर आपोआप जाईल.';

  @override
  String get dprReviewTitle => 'तपासा आणि पाठवा';

  @override
  String dprRowsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count कामे',
      one: '1 काम',
    );
    return '$_temp0';
  }

  @override
  String dprSameAsYesterday(int count) {
    return 'कालच्यासारखेच ($count)';
  }

  @override
  String get dprSavedOnPhone => 'फोनवर जतन झाले';

  @override
  String get dprSavedSendingNow => 'फोनवर जतन झाले, आता पाठवत आहे';

  @override
  String get dprSavedWillSend => 'फोनवर जतन झाले, नेटवर्क आल्यावर पाठवले जाईल';

  @override
  String get dprSaving => 'फोनवर जतन होत आहे';

  @override
  String get dprSearchBoq => 'BOQ शोधा';

  @override
  String get dprSearchHint => 'कोड किंवा वर्णन';

  @override
  String get dprSectionNotes => 'अडथळे आणि टीप';

  @override
  String get dprSectionPhotos => 'फोटो';

  @override
  String get dprSectionWeather => 'हवामान';

  @override
  String get dprSectionWork => 'झालेले काम';

  @override
  String get dprSectionWorkers => 'कामगार';

  @override
  String get dprSend => 'पाठवा';

  @override
  String get dprSendAgain => 'पुन्हा पाठवा';

  @override
  String get dprSendFailed => 'अहवाल जतन करता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get dprSendReport => 'अहवाल पाठवा';

  @override
  String get dprSiteClosedWrite => 'साइट बंद? कारण लिहा';

  @override
  String get dprSyncAttention => 'लक्ष द्या';

  @override
  String get dprSyncPhoneOnly => 'फक्त फोनवर';

  @override
  String get dprSyncSending => 'पाठवत आहे';

  @override
  String get dprSyncWaiting => 'पाठवायचे बाकी';

  @override
  String get dprTakePhoto => 'फोटो काढा';

  @override
  String get dprTotalWorkers => 'एकूण कामगार';

  @override
  String get dprUndo => 'परत आणा';

  @override
  String dprWorkersCount(int count) {
    return '$count कामगार';
  }

  @override
  String get dprWorkersOnSite => 'साइटवरील कामगार';

  @override
  String dprWorkersWord(int count) {
    return 'कामगार';
  }

  @override
  String dprWriteFor(String date) {
    return '$date चा अहवाल लिहा';
  }

  @override
  String get dprWriteToday => 'आजचा अहवाल लिहा';

  @override
  String get dprYesterdayLabel => 'काल';

  @override
  String get dprYesterdayTag => 'काल';

  @override
  String get issuesActionClose => 'समस्या बंद करा';

  @override
  String get issuesActionReopen => 'पुन्हा उघडा';

  @override
  String get issuesActionResolve => 'सोडवले म्हणून नोंदवा';

  @override
  String get issuesActionStart => 'काम सुरू करा';

  @override
  String get issuesAssignedUnknown => 'सोपवलेले';

  @override
  String get issuesAssigneeSheetTitle => 'कोणाकडे सोपवायचे';

  @override
  String get issuesDetailAssignee => 'सोपवले';

  @override
  String get issuesDetailDescription => 'तपशील';

  @override
  String get issuesDetailDue => 'अंतिम तारीख';

  @override
  String get issuesDetailMissingMessage =>
      'ती काढली गेली असावी. यादीकडे परत जा.';

  @override
  String get issuesDetailMissingTitle => 'ही समस्या या फोनवर नाही';

  @override
  String get issuesDetailPriority => 'प्राधान्य';

  @override
  String get issuesDetailRaisedBy => 'नोंदवणारे';

  @override
  String get issuesDetailResolvedOn => 'सोडवल्याची तारीख';

  @override
  String issuesDueOn(String date) {
    return 'मुदत $date';
  }

  @override
  String get issuesDueToday => 'आजच मुदत';

  @override
  String get issuesEmptyFilterMessage => 'या स्थितीत एकही समस्या नाही.';

  @override
  String get issuesEmptyFilterTitle => 'इथे काही नाही';

  @override
  String get issuesEmptyMessage =>
      'साइटवर काही बिघडले असेल तर इथे नोंदवा, म्हणजे ते दुरुस्त होईल.';

  @override
  String get issuesEmptyTitle => 'या साइटवर एकही समस्या नाही';

  @override
  String get issuesEyebrow => 'साइटवरील समस्या';

  @override
  String get issuesFilterAll => 'सर्व';

  @override
  String get issuesFormAssignee => 'कोण दुरुस्त करेल? (ऐच्छिक)';

  @override
  String get issuesFormDue => 'कधीपर्यंत दुरुस्त करायचे? (ऐच्छिक)';

  @override
  String get issuesFormDueClear =>
      'निवडलेल्या दिवसावर पुन्हा टॅप करून तो काढा.';

  @override
  String get issuesFormDueThree => '3 दिवस';

  @override
  String get issuesFormDueToday => 'आज';

  @override
  String get issuesFormDueWeek => '1 आठवडा';

  @override
  String get issuesFormEyebrow => 'नवीन समस्या';

  @override
  String get issuesFormLocation => 'साइटवर कुठे? (ऐच्छिक)';

  @override
  String get issuesFormLocationHint => 'उदा. ग्रिड C, तिसरा मजला';

  @override
  String get issuesFormPhotos => 'समस्येचे फोटो (ऐच्छिक)';

  @override
  String get issuesFormPriority => 'किती तातडीचे आहे?';

  @override
  String get issuesFormSave => 'समस्या जतन करा';

  @override
  String get issuesFormTitle => 'समस्या नोंदवा';

  @override
  String get issuesFormWhat => 'काय बिघडले आहे?';

  @override
  String get issuesFormWhatHint => 'उदा. कॉलम C3 ला भेग';

  @override
  String get issuesFormWhatRequired => 'काय बिघडले ते लिहा.';

  @override
  String get issuesLoading => 'समस्या लोड होत आहेत';

  @override
  String get issuesNoDueDate => 'मुदत नाही';

  @override
  String get issuesNoProjectMessage =>
      'आधी प्रकल्प निवडा, मग इथे समस्या नोंदवा.';

  @override
  String get issuesNoProjectTitle => 'प्रकल्प निवडलेला नाही';

  @override
  String issuesOverdue(int days) {
    return '$days दिवस उशीर';
  }

  @override
  String get issuesPhotoAddAfter => 'नंतरचा फोटो जोडा';

  @override
  String get issuesPhotoAddBefore => 'आधीचा फोटो जोडा';

  @override
  String get issuesPhotoFailed => 'फोटो जोडता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get issuesPhotoSavedOffline =>
      'फोटो फोनवर जतन झाला. नेटवर्क आल्यावर पाठवला जाईल.';

  @override
  String get issuesPhotoSavedOnline => 'फोटो जतन झाला. आता पाठवत आहे.';

  @override
  String get issuesPhotosAfter => 'नंतर';

  @override
  String get issuesPhotosBefore => 'आधी';

  @override
  String get issuesPhotosNone => 'अजून फोटो नाहीत';

  @override
  String get issuesPhotosTitle => 'फोटो';

  @override
  String get issuesRaise => 'समस्या नोंदवा';

  @override
  String get issuesRefreshFailed =>
      'अपडेट करता आले नाही. फोनवर आहे तेच दाखवत आहे.';

  @override
  String issuesResolvedOn(String date) {
    return 'सोडवले $date';
  }

  @override
  String get issuesSaveFailed =>
      'समस्या जतन करता आली नाही. पुन्हा प्रयत्न करा.';

  @override
  String get issuesSavedOffline =>
      'फोनवर जतन झाले. नेटवर्क आल्यावर पाठवले जाईल.';

  @override
  String get issuesSavedOnline => 'जतन झाले. आता पाठवत आहे.';

  @override
  String get issuesStatusFailed => 'स्थिती बदलता आली नाही. पुन्हा प्रयत्न करा.';

  @override
  String get issuesSyncAttention => 'लक्ष द्या';

  @override
  String get issuesSyncSending => 'पाठवत आहे';

  @override
  String get issuesTitle => 'समस्या';

  @override
  String get issuesUnassigned => 'कोणाकडेही नाही';

  @override
  String get materialAdd => 'नोंद करा';

  @override
  String get materialBoqForWork => 'झालेल्या कामासाठी BOQ';

  @override
  String get materialChallanNo => 'चलन क्रमांक (ऐच्छिक)';

  @override
  String get materialChallanPhoto => 'चलनाचा फोटो';

  @override
  String get materialChoose => 'मटेरियल निवडा';

  @override
  String get materialCount => 'स्टॉक मोजा';

  @override
  String get materialCountHint =>
      'तुम्ही मोजलेल्या प्रमाणानुसार स्टॉक दुरुस्त होतो. कमी प्रमाण हिशोब नसलेले म्हणून दिसते.';

  @override
  String materialDifference(String qty) {
    return 'फरक $qty';
  }

  @override
  String get materialEmptyMessage =>
      'आलेल्या आणि वापरलेल्या मटेरियलच्या नोंदी इथे दिसतील.';

  @override
  String get materialEmptyTitle => 'अजून तपासण्यासारखे काही नाही';

  @override
  String get materialExtraRequired => 'जादा काम कोणते ते लिहा';

  @override
  String get materialExtraWhat => 'जादा काम कोणते होते?';

  @override
  String get materialFlagsTitle => 'उत्तर द्यायचे प्रश्न';

  @override
  String get materialInStock => 'स्टॉकमध्ये';

  @override
  String get materialLastCounted => 'शेवटची मोजणी';

  @override
  String get materialLoading => 'मटेरियल लोड होत आहे';

  @override
  String get materialName => 'मटेरियल';

  @override
  String get materialNoCountsTitle => 'अजून मोजणी नाही';

  @override
  String get materialNoDataMessage =>
      'या प्रकल्पाचे मटेरियल लोड करण्यासाठी एकदा इंटरनेटशी जोडा. त्यानंतर ऑफलाइनही चालेल.';

  @override
  String get materialNoDeliveriesTitle => 'अजून चलन नाही';

  @override
  String get materialNoUseTitle => 'अजून मटेरियल वापरले नाही';

  @override
  String get materialOnBoq => 'BOQ काम';

  @override
  String get materialOnExtra => 'जादा काम';

  @override
  String get materialOnSite => 'साइटवरील स्टॉक';

  @override
  String get materialOverStock =>
      'हे इथे दाखवलेल्या स्टॉकपेक्षा जास्त आहे. सर्व्हर ते नाकारू शकतो.';

  @override
  String get materialQtyCounted => 'मोजलेले प्रमाण';

  @override
  String get materialQtyOnChallan => 'चलनावर लिहिलेले प्रमाण (ऐच्छिक)';

  @override
  String get materialQtyOnChallanHint =>
      'चलनापेक्षा कमी आले असल्यास चलनावरील आकडा लिहा.';

  @override
  String get materialQtyReceived => 'आलेले प्रमाण';

  @override
  String get materialQtyRequired => 'प्रमाण लिहा';

  @override
  String get materialQtyUsed => 'वापरलेले प्रमाण';

  @override
  String get materialReasonOnWeb => 'कारण वेबसाइटवर लिहा.';

  @override
  String get materialReceived => 'मटेरियल आले';

  @override
  String get materialReceivedShort => 'आले';

  @override
  String get materialSearch => 'मटेरियल शोधा';

  @override
  String get materialSearchNone => 'काहीही जुळले नाही';

  @override
  String get materialShort => 'कमी आलेले';

  @override
  String get materialShouldBe => 'स्टॉकमध्ये असायला हवे';

  @override
  String get materialStage => 'टप्पा (ऐच्छिक)';

  @override
  String get materialStageHint => 'उदा. स्लॅब 3';

  @override
  String get materialStore => 'स्टोअर';

  @override
  String get materialSupplier => 'पुरवठादार';

  @override
  String get materialSupplierChoose => 'पुरवठादार निवडा';

  @override
  String get materialSupplierFromList => 'यादीतून निवडा';

  @override
  String get materialSupplierName => 'पुरवठादाराचे नाव';

  @override
  String get materialSupplierNew => 'नवीन पुरवठादार';

  @override
  String get materialSupplierRequired => 'कोणी पुरवले ते लिहा';

  @override
  String get materialSupplierSearch => 'पुरवठादार शोधा';

  @override
  String get materialTabCounts => 'मोजणी';

  @override
  String get materialTabDeliveries => 'चलन';

  @override
  String get materialTabStock => 'स्टॉक';

  @override
  String get materialTabUsed => 'वापर';

  @override
  String get materialTitle => 'मटेरियल';

  @override
  String materialToAnswer(int count) {
    return '$count उत्तरे बाकी';
  }

  @override
  String get materialUnaccounted => 'हिशोब नसलेले';

  @override
  String get materialUsed => 'मटेरियल वापरले';

  @override
  String get materialUsedShort => 'वापरले';

  @override
  String get materialVehicleNo => 'गाडी क्रमांक (ऐच्छिक)';

  @override
  String get measurementAdd => 'जोडा';

  @override
  String get measurementAddEntry => 'मोजमाप जोडा';

  @override
  String get measurementAllLines => 'सर्व ओळी';

  @override
  String get measurementApprovedLockedBody =>
      'दुरुस्ती वेबवर उलट नोंद करून होते.';

  @override
  String get measurementApprovedLockedTitle => 'मंजूर आणि बंद';

  @override
  String get measurementApproverSaid => 'मंजूर करणाऱ्याचा शेरा';

  @override
  String get measurementAttentionBody =>
      'सर्व्हरने ही नोंद स्वीकारली नाही. ती फोनवर सुरक्षित आहे.';

  @override
  String get measurementAttentionTitle => 'पाठवता आले नाही';

  @override
  String get measurementBalance => 'शिल्लक';

  @override
  String get measurementBalanceAfter => 'यानंतर शिल्लक';

  @override
  String get measurementBookTitle => 'मोजमाप पुस्तिका';

  @override
  String get measurementBoqProgress => 'वापरलेला BOQ';

  @override
  String get measurementBoqQty => 'BOQ';

  @override
  String measurementBoqUsed(String percent) {
    return 'BOQ चा $percent वापरला';
  }

  @override
  String get measurementBreadth => 'रुंदी';

  @override
  String get measurementBreadthShort => 'रुंदी';

  @override
  String get measurementChange => 'बदला';

  @override
  String get measurementChangeLine => 'BOQ ओळ बदला';

  @override
  String get measurementChooseLine => 'BOQ ओळ निवडा';

  @override
  String get measurementClose => 'बंद करा';

  @override
  String get measurementDateLabel => 'मोजमापाची तारीख';

  @override
  String get measurementDeduct => 'वजा करा';

  @override
  String get measurementDelete => 'खोडा';

  @override
  String get measurementDepth => 'खोली';

  @override
  String get measurementDepthShort => 'खोली';

  @override
  String get measurementDescriptionHint => 'काय मोजले ते लिहा';

  @override
  String get measurementDescriptionLabel => 'वर्णन';

  @override
  String get measurementDetailTitle => 'मोजमाप';

  @override
  String get measurementDetailsShort => 'ठिकाण, तारीख';

  @override
  String get measurementDetailsTitle => 'ठिकाण, टीप आणि तारीख';

  @override
  String get measurementDeviation => 'तफावत';

  @override
  String measurementDeviationBody(String percent) {
    return 'BOQ + $percent% पेक्षा जास्त. प्रकल्प व्यवस्थापकाची मंजुरी लागेल.';
  }

  @override
  String get measurementDeviationDetail =>
      'ही नोंद BOQ प्रमाण आणि सवलतीपेक्षा जास्त आहे, त्यामुळे प्रकल्प व्यवस्थापकाची मंजुरी लागेल.';

  @override
  String measurementDraftsLeft(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count ओळी मसुदा म्हणून ठेवल्या. पुस्तिकेतून पाठवा.',
      one: '1 ओळ मसुदा म्हणून ठेवली. पुस्तिकेतून पाठवा.',
    );
    return '$_temp0';
  }

  @override
  String get measurementEdit => 'बदला';

  @override
  String get measurementEmptyBody => 'तुमची पहिली नोंद ही पुस्तिका सुरू करेल.';

  @override
  String get measurementEmptyFilteredBody => 'या स्थितीच्या नोंदी नाहीत.';

  @override
  String get measurementEmptyFilteredTitle => 'इथे काही नाही';

  @override
  String get measurementEmptyTitle => 'अजून मोजमाप नाही';

  @override
  String get measurementFilterAll => 'सर्व';

  @override
  String get measurementFixLast => 'शेवटची दुरुस्त करा';

  @override
  String get measurementFormulaHint => 'सुरुवातीला नग भरा';

  @override
  String get measurementGoneBody => 'ती वेबवर काढली असावी.';

  @override
  String get measurementGoneTitle => 'नोंद सापडली नाही';

  @override
  String get measurementInReviewBody =>
      'मंजूर करणाऱ्याकडे आहे. निर्णय होईपर्यंत बदलता येणार नाही.';

  @override
  String get measurementItem => 'बाब';

  @override
  String get measurementLeaveBody => 'या ओळीचे भरलेले आकडे जातील.';

  @override
  String get measurementLeaveGo => 'बाहेर पडा';

  @override
  String get measurementLeaveKeep => 'भरत राहा';

  @override
  String get measurementLeaveTitle => 'जतन न करता बाहेर पडायचे?';

  @override
  String get measurementLength => 'लांबी';

  @override
  String get measurementLengthShort => 'लांबी';

  @override
  String get measurementLoading => 'लोड होत आहे';

  @override
  String get measurementLocationHint => 'ग्रिड, मजला, फूटिंग';

  @override
  String get measurementLocationLabel => 'ठिकाण';

  @override
  String get measurementLockedBody => 'ती मंजूर झाली आहे किंवा तपासणीत आहे.';

  @override
  String get measurementLockedTitle => 'ही नोंद बदलता येणार नाही';

  @override
  String get measurementNet => 'निव्वळ';

  @override
  String get measurementNext => 'पुढे';

  @override
  String get measurementNoMatchTitle => 'काहीही जुळत नाही';

  @override
  String get measurementNos => 'नग';

  @override
  String get measurementOffline => 'नेटवर्क नाही.';

  @override
  String get measurementOfflineDetail =>
      'नोंदी फोनवरच राहतील, नेटवर्क आले की जातील.';

  @override
  String get measurementOtherDate => 'दुसरी तारीख';

  @override
  String get measurementPhotoAdd => 'फोटो जोडा';

  @override
  String get measurementPhotoFailed =>
      'फोटो जोडता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get measurementPhotoSaved => 'फोटो जतन झाला. आता पाठवत आहे.';

  @override
  String get measurementPhotoSavedOffline =>
      'फोटो फोनवर जतन झाला. नेटवर्क आल्यावर पाठवला जाईल.';

  @override
  String get measurementPhotosLocked =>
      'नोंद मंजुरीसाठी गेल्यावर फोटो जोडता येत नाहीत.';

  @override
  String get measurementPhotosNone => 'अजून फोटो नाहीत';

  @override
  String get measurementPhotosTitle => 'फोटो';

  @override
  String get measurementPreviewNote =>
      'या फोनवरचे आकडे अंदाजी आहेत. ऑफिसचे आकडे अंतिम असतात.';

  @override
  String get measurementPreviewTag => 'अंदाजे';

  @override
  String get measurementProblemFuture => 'तारीख भविष्यातील असू शकत नाही.';

  @override
  String get measurementProblemLocked => 'ही नोंद आधीच मंजुरीसाठी गेली आहे.';

  @override
  String get measurementProblemNoLine => 'आधी BOQ ओळ निवडा.';

  @override
  String get measurementProblemNos => 'नग भरा. ते आवश्यक आहे.';

  @override
  String get measurementProblemPositive => 'हा आकडा शून्यापेक्षा जास्त हवा.';

  @override
  String get measurementProblemTooLarge => 'हा आकडा खूप मोठा आहे.';

  @override
  String get measurementProblemUnknown => 'जतन झाले नाही. पुन्हा प्रयत्न करा.';

  @override
  String get measurementQuantity => 'प्रमाण';

  @override
  String get measurementRecentLines => 'अलीकडचे';

  @override
  String get measurementRejectedBody =>
      'ही नोंद नाकारली आहे, ती धरली जाणार नाही. पुन्हा मोजायचे असल्यास नवीन नोंद करा.';

  @override
  String get measurementSavedOffline => 'फोनवर जतन केले. नेटवर्क आले की जाईल.';

  @override
  String get measurementSavedOnPhone => 'फोनवर जतन केले.';

  @override
  String get measurementSearchHint => 'उदा. 2.1 किंवा स्लॅब';

  @override
  String get measurementSearchLines => 'कोड किंवा वर्णन शोधा';

  @override
  String get measurementSendAgain => 'पुन्हा पाठवा';

  @override
  String measurementSendCount(int count) {
    return '$count मंजुरीसाठी पाठवा';
  }

  @override
  String get measurementSendForApproval => 'मंजुरीसाठी पाठवा';

  @override
  String get measurementSendsWhenOnline =>
      'अजून गेले नाही. नेटवर्क आले की मंजुरीसाठी जाईल.';

  @override
  String get measurementShareOfBoq => 'BOQ मधील वाटा';

  @override
  String get measurementStampSent => 'पाठवले';

  @override
  String get measurementSubmitWaitingTitle =>
      'मंजुरीसाठी जाण्याच्या प्रतीक्षेत';

  @override
  String get measurementSyncAttention => 'पाहणे गरजेचे';

  @override
  String get measurementSyncSending => 'जात आहे';

  @override
  String measurementTally(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count ओळी फोनवर जतन',
      one: '1 ओळ फोनवर जतन',
    );
    return '$_temp0';
  }

  @override
  String get measurementYesterday => 'काल';

  @override
  String get pettyCashAddExpense => 'खर्च जोडा';

  @override
  String get pettyCashAddMoney => 'पैसे जमा करा';

  @override
  String get pettyCashAmount => 'रक्कम';

  @override
  String get pettyCashAmountRequired => 'शून्यापेक्षा जास्त रक्कम टाका.';

  @override
  String get pettyCashBalance => 'शिल्लक';

  @override
  String pettyCashBalanceAfter(String amount) {
    return 'यानंतर शिल्लक: $amount';
  }

  @override
  String get pettyCashBillAdded => 'बिलाचा फोटो जोडला';

  @override
  String get pettyCashBillFailed =>
      'कॅमेरा उघडता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get pettyCashBillLabel => 'बिल';

  @override
  String get pettyCashBillRetake => 'पुन्हा काढा';

  @override
  String get pettyCashCategory => 'कशासाठी?';

  @override
  String get pettyCashCategoryRequired => 'कशासाठी ते निवडा.';

  @override
  String get pettyCashChooseProject => 'प्रकल्प निवडा';

  @override
  String get pettyCashConfirmed => 'पक्की शिल्लक';

  @override
  String get pettyCashCreateWallet => 'पाकीट तयार करा';

  @override
  String get pettyCashDate => 'तारीख';

  @override
  String get pettyCashDayToday => 'आज';

  @override
  String get pettyCashDayYesterday => 'काल';

  @override
  String get pettyCashEntries => 'नोंदी';

  @override
  String get pettyCashEntriesEmptyMessage => 'तुम्ही जोडलेले खर्च इथे दिसतील.';

  @override
  String get pettyCashEntriesEmptyTitle => 'अजून एकही नोंद नाही';

  @override
  String get pettyCashEyebrow => 'रोख वही';

  @override
  String get pettyCashFinalAfterSync => 'सिंक झाल्यावर अंतिम शिल्लक कळेल.';

  @override
  String get pettyCashFormEyebrow => 'नवीन खर्च';

  @override
  String get pettyCashFormTitle => 'खर्च जोडा';

  @override
  String get pettyCashLoading => 'रोख वही लोड होत आहे';

  @override
  String get pettyCashNoProjectMessage =>
      'आधी प्रकल्प निवडा, मग इथे खर्च नोंदवा.';

  @override
  String get pettyCashNoProjectTitle => 'प्रकल्प निवडलेला नाही';

  @override
  String get pettyCashNoWalletManageMessage =>
      'साइटचे खर्च इथे नोंदवण्यासाठी पाकीट तयार करा.';

  @override
  String get pettyCashNoWalletManageTitle => 'या प्रकल्पावर अजून पाकीट नाही';

  @override
  String get pettyCashNoWalletMessage =>
      'तुमच्यासाठी पाकीट उघडायला प्रकल्प व्यवस्थापक किंवा अकाउंट्स ऑफिसला सांगा.';

  @override
  String get pettyCashNoWalletTitle => 'या प्रकल्पावर तुमचे पाकीट नाही';

  @override
  String get pettyCashNote => 'टीप (ऐच्छिक)';

  @override
  String get pettyCashNoteHint => 'मिक्सरसाठी डिझेल';

  @override
  String get pettyCashOpeningAmount => 'सुरुवातीची रक्कम';

  @override
  String get pettyCashOpeningHelper => 'अजून पैसे नसतील तर रिकामे ठेवा.';

  @override
  String get pettyCashOpeningNote => 'सुरुवातीची शिल्लक';

  @override
  String pettyCashOverBalance(String balance) {
    return 'ही रक्कम पाकिटातील $balance पेक्षा जास्त आहे. ऑफिसकडे रक्कम जमा करायला सांगा.';
  }

  @override
  String get pettyCashPhotoBill => 'बिलाचा फोटो';

  @override
  String get pettyCashProvisional => 'तात्पुरती';

  @override
  String get pettyCashSave => 'खर्च जतन करा';

  @override
  String get pettyCashSaveFailed =>
      'खर्च जतन करता आला नाही. पुन्हा प्रयत्न करा.';

  @override
  String get pettyCashSavedOffline =>
      'फोनवर जतन झाले. नेटवर्क आल्यावर पाठवले जाईल.';

  @override
  String get pettyCashSavedOnline => 'फोनवर जतन झाले. आता पाठवत आहे.';

  @override
  String get pettyCashSyncAttention => 'लक्ष द्या';

  @override
  String get pettyCashSyncSending => 'पाठवत आहे';

  @override
  String get pettyCashTapToChange => 'बदलण्यासाठी टॅप करा';

  @override
  String get pettyCashTitle => 'किरकोळ रोख';

  @override
  String get pettyCashTopUpEyebrow => 'पाकिटात रक्कम';

  @override
  String get pettyCashTopUpFailed =>
      'रक्कम जतन करता आली नाही. पुन्हा प्रयत्न करा.';

  @override
  String get pettyCashTopUpNoteHint => 'ऑफिसकडून रोख';

  @override
  String get pettyCashTopUpSave => 'मिळालेली रक्कम जतन करा';

  @override
  String get pettyCashTopup => 'रक्कम जमा';

  @override
  String get pettyCashUnsent => 'अजून पाठवले नाहीत';

  @override
  String get pettyCashWallet => 'पाकीट';

  @override
  String get pettyCashWalletCreateKey => 'तयार करा';

  @override
  String get pettyCashWalletCreated => 'पाकीट तयार झाले';

  @override
  String pettyCashWalletCreatedMoney(String amount) {
    return 'पाकीट तयार झाले. $amount फोनवर जतन झाले, आता पाठवत आहे.';
  }

  @override
  String pettyCashWalletCreatedOffline(String amount) {
    return 'पाकीट तयार झाले. $amount फोनवर जतन झाले, नेटवर्क आल्यावर पाठवले जातील.';
  }

  @override
  String pettyCashWalletDefaultName(String code) {
    return '$code साइट रोख';
  }

  @override
  String get pettyCashWalletFailed =>
      'पाकीट तयार करता आले नाही. पुन्हा प्रयत्न करा.';

  @override
  String get pettyCashWalletFormEyebrow => 'नवीन पाकीट';

  @override
  String get pettyCashWalletHolder => 'रोख कोणाकडे असेल?';

  @override
  String get pettyCashWalletMoneyFailed =>
      'पाकीट तयार झाले, पण रक्कम जतन झाली नाही. पुन्हा जमा करा.';

  @override
  String get pettyCashWalletName => 'पाकिटाचे नाव';

  @override
  String get pettyCashWalletNameRequired => 'पाकिटाला नाव द्या.';

  @override
  String get pettyCashWalletOffline => 'नेटवर्क नाही.';

  @override
  String get pettyCashWalletOfflineDetail => 'पाकीट फक्त ऑनलाइन तयार होते.';

  @override
  String get photosClose => 'बंद करा';

  @override
  String get photosDocument => 'PDF, वेबसाइटवर';

  @override
  String get photosNeedNetwork => 'नेटवर्क हवे';

  @override
  String get photosNotSent => 'पाठवला नाही';

  @override
  String get photosOpen => 'फोटो उघडा';

  @override
  String get photosWaiting => 'पाठवायचा बाकी';

  @override
  String get progressAdd => 'आजचे काम नोंदवा';

  @override
  String get progressBeforeFinishing => 'फिनिशिंगपूर्वीची तपासणी';

  @override
  String progressBehind(String percent) {
    return '$percent मागे';
  }

  @override
  String get progressCorrect => 'दुरुस्त करा';

  @override
  String get progressCorrectFailed =>
      'दुरुस्ती जतन झाली नाही. पुन्हा प्रयत्न करा.';

  @override
  String get progressCorrectHint =>
      'पहिली नोंद तशीच राहते. तिच्या शेजारी दुरुस्ती जोडली जाते.';

  @override
  String progressCorrectNow(String qty) {
    return 'आता मोजलेले: $qty';
  }

  @override
  String get progressCorrectQty => 'ते किती असायला हवे होते';

  @override
  String get progressCorrectQtyInvalid =>
      'शून्य किंवा त्यापेक्षा जास्त प्रमाण लिहा';

  @override
  String get progressCorrectRemark => 'दुरुस्ती का करायची आहे?';

  @override
  String get progressCorrectRemarkRequired => 'दुरुस्ती का करत आहात ते लिहा';

  @override
  String get progressCorrectSame => 'हेच प्रमाण आधीच आहे';

  @override
  String get progressCorrectSave => 'दुरुस्ती जतन करा';

  @override
  String get progressCorrectSavedOffline =>
      'दुरुस्ती या फोनवर जतन झाली. इंटरनेट आल्यावर पाठवली जाईल.';

  @override
  String get progressCorrectSavedOnline => 'दुरुस्ती जतन झाली. पाठवत आहे.';

  @override
  String get progressCorrectTakenBack => 'दुरुस्ती मागे घेतली.';

  @override
  String get progressCorrectTitle => 'ही नोंद दुरुस्त करा';

  @override
  String get progressCorrectionNotSent => 'दुरुस्ती अजून गेली नाही';

  @override
  String progressEntryBy(String name) {
    return '$name यांनी';
  }

  @override
  String progressEntryCorrectedFrom(String qty) {
    return '$qty वरून दुरुस्त केले';
  }

  @override
  String get progressFinishingHint =>
      'प्लास्टर, POP किंवा रंगकाम सुरू करण्यापूर्वी हे वाचा.';

  @override
  String get progressFinishingPending => 'बाकी काम';

  @override
  String get progressFinishingUnfinished => 'अजून पूर्ण नाही';

  @override
  String get progressFloorBalance => 'शिल्लक';

  @override
  String get progressFloorDone => 'पूर्ण';

  @override
  String get progressFloorFlats => 'फ्लॅट';

  @override
  String get progressFloorLines => 'या मजल्यावरील काम';

  @override
  String get progressFloorNoLines => 'या मजल्यासाठी अजून प्रमाण ठरलेले नाही.';

  @override
  String get progressFloorOffline => 'हा मजला पाहण्यासाठी एकदा इंटरनेटशी जोडा.';

  @override
  String get progressFloorPendingHeading => 'इथे बाकी';

  @override
  String get progressFloorPlanned => 'नियोजित';

  @override
  String get progressFloorStages => 'प्लंबिंग आणि इलेक्ट्रिकल टप्पे';

  @override
  String get progressFloorTitle => 'मजल्याची प्रगती';

  @override
  String progressFormBalance(String qty) {
    return 'शिल्लक $qty';
  }

  @override
  String get progressFormDate => 'दिनांक';

  @override
  String get progressFormFloor => 'मजला किंवा फ्लॅट';

  @override
  String get progressFormFloorNone => 'संपूर्ण प्रकल्प';

  @override
  String get progressFormItem => 'BOQ आयटम';

  @override
  String get progressFormItemChoose => 'आयटम निवडा';

  @override
  String get progressFormItemNone => 'जुळणारा आयटम नाही';

  @override
  String get progressFormItemSearch => 'कोड किंवा नावाने शोधा';

  @override
  String get progressFormKindPending => 'फक्त बाकी';

  @override
  String get progressFormKindWork => 'काम झाले';

  @override
  String get progressFormOverBalance =>
      'हे BOQ शिल्लकपेक्षा जास्त आहे. तरीही जतन करता येईल.';

  @override
  String get progressFormPendingRemark => 'काय बाकी आहे?';

  @override
  String get progressFormPhoto => 'फोटो जोडा';

  @override
  String get progressFormQty => 'झालेले प्रमाण';

  @override
  String get progressFormQtyRequired => 'झालेले प्रमाण लिहा';

  @override
  String get progressFormRemark => 'टीप (ऐच्छिक)';

  @override
  String get progressFormRemarkRequired => 'काय बाकी आहे ते लिहा';

  @override
  String get progressFormRemovePhoto => 'फोटो काढा';

  @override
  String get progressFormSave => 'काम जतन करा';

  @override
  String get progressFormTitle => 'काम नोंदवा';

  @override
  String get progressFormToday => 'आज';

  @override
  String get progressFormWhose => 'हे काम कोणाचे आहे?';

  @override
  String get progressFormWhoseRequired => 'कंत्राटदार निवडा';

  @override
  String get progressFormYesterday => 'काल';

  @override
  String get progressLoading => 'प्रगती लोड होत आहे';

  @override
  String get progressNoDataMessage =>
      'या प्रकल्पाची प्रगती लोड करण्यासाठी एकदा इंटरनेटशी जोडा. त्यानंतर ऑफलाइनही चालेल.';

  @override
  String get progressNoDataTitle => 'अजून लोड झाले नाही';

  @override
  String get progressNoFloorsMessage =>
      'कार्यालयाला वेबसाइटवर मजले आणि फ्लॅट जोडायला सांगा.';

  @override
  String get progressNoFloorsTitle => 'अजून मजले जोडलेले नाहीत';

  @override
  String get progressNoPendingMessage =>
      'साइटवर बाकी म्हणून नोंदवलेले काम इथे दिसेल.';

  @override
  String get progressNoPendingTitle => 'काहीही बाकी नाही';

  @override
  String get progressNoProjectMessage => 'आधी प्रकल्प निवडा.';

  @override
  String get progressNoProjectTitle => 'प्रकल्प निवडलेला नाही';

  @override
  String get progressNotSent => 'अजून पाठवले नाही';

  @override
  String get progressOnTrack => 'वेळेवर';

  @override
  String progressPendingCount(int count) {
    return '$count बाकी';
  }

  @override
  String get progressReadyMessage =>
      'इथले सर्व प्लंबिंग आणि इलेक्ट्रिकल टप्पे पूर्ण आहेत.';

  @override
  String get progressReadyTitle => 'फिनिशिंगसाठी तयार';

  @override
  String get progressRecentEntries => 'या मजल्यावरील अलीकडील नोंदी';

  @override
  String get progressRefreshFailed =>
      'ताजी माहिती मिळाली नाही. फोनवरची माहिती दाखवत आहे.';

  @override
  String get progressResolveRemark => 'टीप (ऐच्छिक)';

  @override
  String get progressResolveSave => 'पूर्ण करा';

  @override
  String get progressResolveTitle => 'पूर्ण झाले म्हणून नोंदवा';

  @override
  String get progressResolveWait =>
      'ही नोंद अजून कार्यालयात पोहोचली नाही. पाठवल्यानंतर पुन्हा प्रयत्न करा.';

  @override
  String get progressResolved => 'पूर्ण म्हणून नोंदवले';

  @override
  String get progressSaveFailed => 'जतन झाले नाही. पुन्हा प्रयत्न करा.';

  @override
  String get progressSavedOffline =>
      'या फोनवर जतन झाले. इंटरनेट आल्यावर पाठवले जाईल.';

  @override
  String get progressSavedOnline => 'जतन झाले. पाठवत आहे.';

  @override
  String get progressStageEarlierPending => 'आधीचा टप्पा अजून बाकी आहे';

  @override
  String get progressStageNote => 'टीप (ऐच्छिक)';

  @override
  String get progressStageSave => 'जतन करा';

  @override
  String get progressStageSaved => 'टप्पा जतन झाला';

  @override
  String get progressStageStatus => 'स्थिती';

  @override
  String progressStagesDone(int done, int total) {
    return '$total पैकी $done पूर्ण';
  }

  @override
  String get progressTabFloors => 'मजले';

  @override
  String get progressTabPending => 'बाकी';

  @override
  String get progressTabStages => 'टप्पे';

  @override
  String progressTimeUsed(String percent) {
    return 'वापरलेला वेळ $percent';
  }

  @override
  String get progressTitle => 'साइट प्रगती';

  @override
  String get progressWorkDone => 'झालेले काम';

  @override
  String get shellCaptionMarked => 'नोंदवले';

  @override
  String get shellCaptionOpen => 'उघड्या';

  @override
  String get shellCaptionToAnswer => 'उत्तरे बाकी';

  @override
  String get shellCaptionToday => 'आज';

  @override
  String get shellCaptionWorkDone => 'झालेले काम';

  @override
  String get shellDiscardBody =>
      'ती सर्व्हरवर पोहोचलेली नाही. ती या फोनवरून काढली जाईल.';

  @override
  String get shellDiscardConfirm => 'होय, काढून टाका';

  @override
  String get shellDiscardKeep => 'राहू द्या';

  @override
  String get shellDiscardTitle => 'ही नोंद काढून टाकायची?';

  @override
  String get shellOfflineApprovals => 'मंजुरीसाठी नेटवर्क हवे.';

  @override
  String get shellOfflineDetail => 'नोंदी फोनवर राहतील.';

  @override
  String get shellOfflineLabel => 'ऑफलाइन';

  @override
  String get shellOutboxAllSentBody => 'या फोनवर काही बाकी नाही.';

  @override
  String get shellOutboxAllSentStamp => 'सर्व पाठवले';

  @override
  String shellOutboxAttention(int count) {
    return '$count नोंदींकडे लक्ष द्या';
  }

  @override
  String get shellOutboxLoading => 'आउटबॉक्स आणत आहे';

  @override
  String get shellOutboxLogTitle => 'लागू झाले नाही';

  @override
  String get shellOutboxNeverSynced => 'अजून सिंक झालेले नाही';

  @override
  String get shellOutboxServerError =>
      'सर्व्हरला अडचण आहे. तुमचे काम सुरक्षित आहे, नंतर पाठवले जाईल.';

  @override
  String get shellOutboxTitle => 'आउटबॉक्स';

  @override
  String get shellOutboxTrayLabel => 'फोनवर';

  @override
  String get shellOutboxWaitingLabel => 'पाठवायचे बाकी';

  @override
  String get shellPickerCurrent => 'चालू';

  @override
  String get shellPickerEmptyBody =>
      'कार्यालयाला तुम्हाला प्रकल्पात जोडायला सांगा, मग पुन्हा प्रयत्न करा.';

  @override
  String get shellPickerEmptyTitle => 'अजून प्रकल्प नाहीत';

  @override
  String get shellPickerEyebrow => 'तुमचे प्रकल्प';

  @override
  String get shellPickerFirstBody =>
      'तुमचा प्रकल्प जोडा आणि त्याचे काम या फोनवरून चालवा.';

  @override
  String get shellPickerFirstButton => 'तुमचा पहिला प्रकल्प तयार करा';

  @override
  String get shellPickerFirstTitle => 'चला, तुमची पहिली साइट तयार करूया';

  @override
  String get shellPickerLoading => 'तुमचे प्रकल्प आणत आहे…';

  @override
  String get shellPickerTitle => 'आज कोणती साइट?';

  @override
  String get shellPillAllSent => 'सर्व पाठवले';

  @override
  String get shellPillAttention => 'तपासा';

  @override
  String get shellPillSending => 'पाठवत आहे';

  @override
  String get shellPillWaiting => 'बाकी';

  @override
  String get shellProgressBasis => 'मंजूर कामाच्या किमतीनुसार';

  @override
  String get shellProgressDaysLate => 'उशीर, दिवस';

  @override
  String get shellProgressDaysLeft => 'बाकी दिवस';

  @override
  String get shellProgressEmptyBody => 'BOQ जोडल्यावर प्रगती इथे दिसेल.';

  @override
  String get shellProgressEmptyTitle => 'अजून BOQ नाही';

  @override
  String get shellProgressEnd => 'शेवट';

  @override
  String get shellProgressNoEnd => 'ठरलेला नाही';

  @override
  String get shellProgressNothingYet => 'अजून मंजूर काम नाही';

  @override
  String shellProgressSemantics(String percent) {
    return '$percent टक्के पूर्ण';
  }

  @override
  String get shellProgressTitle => 'बांधकामाची प्रगती';

  @override
  String get shellProjectChoose => 'निवडा';

  @override
  String get shellProjectCreate => 'प्रकल्प तयार करा';

  @override
  String get shellProjectCreating => 'तयार होत आहे…';

  @override
  String get shellProjectEnd => 'पूर्ण होण्याची तारीख';

  @override
  String get shellProjectEndBeforeStart =>
      'पूर्ण होण्याची तारीख सुरू होण्याच्या तारखेनंतरची हवी';

  @override
  String get shellProjectFormTitle => 'नवीन प्रकल्प';

  @override
  String get shellProjectLocation => 'ठिकाण';

  @override
  String get shellProjectName => 'प्रकल्पाचे नाव';

  @override
  String get shellProjectNameRequired => 'प्रकल्पाचे नाव लिहा';

  @override
  String get shellProjectOffline =>
      'नेटवर्क असतानाच प्रकल्प तयार करता येतो. सिग्नल आल्यावर पुन्हा प्रयत्न करा.';

  @override
  String get shellProjectPlanTitle => 'प्रकल्पांची मर्यादा पूर्ण झाली';

  @override
  String get shellProjectStart => 'सुरू होण्याची तारीख';

  @override
  String get shellProjectValue => 'कराराची रक्कम (ऐच्छिक)';

  @override
  String get shellProjectValueEmpty => 'भरण्यासाठी दाबा';

  @override
  String get shellReportAttention => 'तपासा';

  @override
  String get shellReportNotStarted => 'सुरू नाही';

  @override
  String get shellReportSaved => 'जतन झाला';

  @override
  String shellReturnedDpr(String date) {
    return 'दैनिक अहवाल · $date';
  }

  @override
  String get shellReturnedFix => 'दुरुस्त करा';

  @override
  String shellReturnedMeasurement(String code, String qty, String unit) {
    return 'मोजमाप $code · $qty $unit';
  }

  @override
  String get shellReturnedNoRemark => 'शेरा दिलेला नाही';

  @override
  String get shellReturnedRemarkLabel => 'शेरा';

  @override
  String get shellReturnedTitle => 'तुम्हाला परत पाठवलेले';

  @override
  String get shellSettings => 'सेटिंग्ज';

  @override
  String get shellSettingsLanguage => 'भाषा';

  @override
  String get shellSettingsSignedIn => 'साइन इन केलेले';

  @override
  String get shellSettingsVersion => 'ॲप आवृत्ती';

  @override
  String get shellSetupBoq => 'BOQ बाबी';

  @override
  String shellSetupNext(String step) {
    return 'पुढे: $step';
  }

  @override
  String shellSetupStepSemantics(String step, int count) {
    return '$step: $count';
  }

  @override
  String get shellSetupTitle => 'तुमची साइट तयार करा';

  @override
  String get shellSetupWallet => 'रोख पाकीट';

  @override
  String get shellSetupWorkers => 'कामगार';

  @override
  String get shellSignInBusy => 'साइन इन होत आहे…';

  @override
  String get shellSignInButton => 'साइन इन करा';

  @override
  String get shellSignInEmail => 'ईमेल';

  @override
  String get shellSignInEmailRequired => 'तुमचा ईमेल लिहा';

  @override
  String get shellSignInExpired =>
      'तुम्हाला साइन आउट केले आहे. काहीही गेलेले नाही: तुमचे काम या फोनवर सुरक्षित आहे. ते पाठवण्यासाठी पुन्हा साइन इन करा.';

  @override
  String get shellSignInHide => 'लपवा';

  @override
  String get shellSignInNetworkBody => 'सिग्नल तपासून पुन्हा प्रयत्न करा.';

  @override
  String get shellSignInNetworkTitle => 'नेटवर्क नाही';

  @override
  String shellSignInOtherUserBody(int count) {
    return 'या फोनवर दुसऱ्या व्यक्तीच्या $count नोंदी पाठवायच्या बाकी आहेत. त्यांनी आधी साइन इन करून त्या पाठवाव्यात.';
  }

  @override
  String get shellSignInOtherUserTitle => 'दुसऱ्या व्यक्तीचे काम बाकी आहे';

  @override
  String get shellSignInPassword => 'पासवर्ड';

  @override
  String get shellSignInPasswordRequired => 'तुमचा पासवर्ड लिहा';

  @override
  String get shellSignInServerBody =>
      'तुमच्या फोनचा दोष नाही. थोड्या वेळाने पुन्हा प्रयत्न करा.';

  @override
  String get shellSignInServerTitle => 'सर्व्हर प्रतिसाद देत नाही';

  @override
  String get shellSignInShow => 'दाखवा';

  @override
  String get shellSignInTooManyBody => 'एक मिनिट थांबून पुन्हा प्रयत्न करा.';

  @override
  String get shellSignInTooManyTitle => 'खूप वेळा प्रयत्न झाले';

  @override
  String get shellSignInWelcome => 'नमस्कार';

  @override
  String get shellSignInWrongBody => 'दोन्ही तपासून पुन्हा प्रयत्न करा.';

  @override
  String get shellSignInWrongTitle => 'ईमेल किंवा पासवर्ड चुकीचा आहे';

  @override
  String get shellSignOut => 'साइन आउट';

  @override
  String get shellSignOutConfirmBody => 'पुन्हा साइन इन करायला नेटवर्क लागेल.';

  @override
  String get shellSignOutConfirmTitle => 'या फोनवरून साइन आउट करायचे?';

  @override
  String get shellSignOutDiscard => 'साइन आउट करून गमवा';

  @override
  String get shellSignOutHold => 'नोंदी गमावण्यासाठी दाबून ठेवा';

  @override
  String get shellSignOutSendFirst => 'आधी पाठवा';

  @override
  String get shellSignOutUnsentBody =>
      'आता साइन आउट केल्यास त्या या फोनवरून कायमच्या जातील. आधी त्या पाठवा.';

  @override
  String shellSignOutUnsentTitle(int count) {
    return '$count नोंदी पाठवलेल्या नाहीत';
  }

  @override
  String get shellSlipSending => 'पाठवत आहे';

  @override
  String get shellSlipWaiting => 'बाकी';

  @override
  String get shellSwitchProject => 'प्रकल्प बदला';

  @override
  String get shellSyncingLabel => 'पाठवत आणि अद्ययावत करत आहे';

  @override
  String get shellTabApprovals => 'मंजुरी';

  @override
  String get shellTabHome => 'मुख्य';

  @override
  String get shellTabMeasure => 'मोजमाप';

  @override
  String get shellTabOutbox => 'आउटबॉक्स';

  @override
  String get shellTileApprovals => 'मंजुरी';

  @override
  String get shellTileAttendance => 'हजेरी';

  @override
  String get shellTileDaily => 'दैनिक अहवाल';

  @override
  String get shellTileExpenses => 'खर्च';

  @override
  String get shellTileIssues => 'समस्या';

  @override
  String get shellTileMaterial => 'मटेरियल';

  @override
  String get shellTileMeasure => 'मोजमाप';

  @override
  String get shellTilePetty => 'किरकोळ रोख';

  @override
  String get shellTileProgress => 'प्रगती';

  @override
  String shellTimeDays(int count) {
    return '$count दिवसांपूर्वी';
  }

  @override
  String shellTimeHours(int count) {
    return '$count तासांपूर्वी';
  }

  @override
  String get shellTimeJustNow => 'आत्ताच';

  @override
  String shellTimeMinutes(int count) {
    return '$count मिनिटांपूर्वी';
  }

  @override
  String get shellTimeYesterday => 'काल';

  @override
  String get shellTodayAttendance => 'हजेरी';

  @override
  String get shellTodayExpenses => 'खर्च';

  @override
  String get shellTodayMeasured => 'मोजमाप';

  @override
  String get shellTodayReport => 'अहवाल';

  @override
  String shellTodaySemantics(String label, String value) {
    return '$label: $value';
  }

  @override
  String get statusApproved => 'मंजूर';

  @override
  String get statusDraft => 'मसुदा';

  @override
  String get statusInReview => 'तपासणीत';

  @override
  String get statusQueued => 'फोनवर';

  @override
  String get statusRejected => 'नाकारले';

  @override
  String get statusReturned => 'परत पाठवले';

  @override
  String get statusSynced => 'पाठवले';

  @override
  String get syncAllSent => 'सर्व पाठवले';

  @override
  String syncAttentionCount(int count) {
    return '$count कडे लक्ष द्या';
  }

  @override
  String get syncBlocked => 'आधीच्या नोंदीची वाट पाहत आहे';

  @override
  String get syncDiscard => 'रद्द करा';

  @override
  String get syncInProgress => 'पाठवत आहे…';

  @override
  String syncLastSynced(String time) {
    return 'शेवटचे सिंक $time';
  }

  @override
  String get syncNow => 'आता सिंक करा';

  @override
  String get syncOffline => 'नेटवर्क नाही. तुमचे काम या फोनवर सुरक्षित आहे.';

  @override
  String syncPendingCount(int count) {
    return '$count पाठवायचे बाकी';
  }

  @override
  String syncProgress(int done, int total) {
    return '$total पैकी $done पाठवले';
  }

  @override
  String get syncPulling => 'नवीन आकडे घेत आहे…';

  @override
  String get syncRateLimited =>
      'सर्व्हर व्यस्त आहे. पाठवणे लवकरच पुन्हा सुरू होईल.';

  @override
  String get syncReasonApproved =>
      'ही नोंद आधीच मंजूर झाली आहे. तुमचा बदल लागू झाला नाही.';

  @override
  String get syncReasonFileMissing => 'फोटो या फोनवर सापडला नाही.';

  @override
  String get syncReasonForbidden => 'हे करण्याची तुम्हाला परवानगी नाही.';

  @override
  String get syncReasonInReview =>
      'ही नोंद मंजुरीसाठी गेली आहे. तुमचा बदल लागू झाला नाही.';

  @override
  String get syncReasonParentMissing =>
      'ही फाइल ज्या नोंदीची आहे ती सर्व्हरवर सापडली नाही.';

  @override
  String get syncReasonPlanFeature => 'तुमच्या प्लॅनमध्ये हा विभाग नाही.';

  @override
  String get syncReasonProjectGone =>
      'या प्रकल्पाचा प्रवेश आता तुमच्याकडे नाही. कार्यालयाला पुन्हा जोडायला सांगा आणि मग पुन्हा प्रयत्न करा.';

  @override
  String get syncReasonRejected =>
      'ही नोंद नाकारली गेली. तुमचा बदल लागू झाला नाही.';

  @override
  String get syncReasonUnexpected => 'सर्व्हरकडून अनपेक्षित उत्तर आले.';

  @override
  String get syncReasonValidation =>
      'सर्व्हरने ही नोंद स्वीकारली नाही. कृपया दुरुस्त करा.';

  @override
  String get syncRetry => 'पुन्हा प्रयत्न करा';

  @override
  String get syncSignInAgain =>
      'पाठवणे सुरू ठेवण्यासाठी पुन्हा साइन इन करा. तुमचे काम सुरक्षित आहे.';

  @override
  String get syncStaleLog =>
      'सर्व्हरवर यापेक्षा नवीन बदल होता, म्हणून हा लागू झाला नाही.';

  @override
  String get syncTypeAttendance => 'हजेरी';

  @override
  String get syncTypeDpr => 'दैनिक अहवाल';

  @override
  String get syncTypeDprPhoto => 'अहवालाचा फोटो';

  @override
  String get syncTypeDprSubmit => 'अहवाल सादर करणे';

  @override
  String get syncTypeIssue => 'समस्या';

  @override
  String get syncTypeIssuePhoto => 'समस्येचा फोटो';

  @override
  String get syncTypeIssueStatus => 'समस्येची स्थिती';

  @override
  String get syncTypeMeasurement => 'मोजमाप';

  @override
  String get syncTypeMeasurementPhoto => 'मोजमापाचा फोटो';

  @override
  String get syncTypeMeasurementSubmit => 'मोजमाप सादर करणे';

  @override
  String get syncTypePettyBill => 'बिलाचा फोटो';

  @override
  String get syncTypePettyCash => 'किरकोळ रोख नोंद';

  @override
  String get syncTypeProgress => 'साइट प्रगती';

  @override
  String get syncTypeProgressCorrection => 'प्रगतीची दुरुस्ती';

  @override
  String get syncTypeProgressPhoto => 'प्रगतीचा फोटो';

  @override
  String get syncTypeProgressResolve => 'बाकी काम पूर्ण';

  @override
  String get syncTypeStage => 'टप्प्याची नोंद';

  @override
  String get syncTypeStagePhoto => 'टप्प्याचा फोटो';
}
