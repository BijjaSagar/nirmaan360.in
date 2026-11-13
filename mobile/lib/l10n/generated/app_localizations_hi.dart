// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Hindi (`hi`).
class AppLocalizationsHi extends AppLocalizations {
  AppLocalizationsHi([String locale = 'hi']) : super(locale);

  @override
  String get accountDeleteBusy => 'आपका खाता हटा रहे हैं…';

  @override
  String get accountDeleteCancelButton => 'हटाना रद्द करें';

  @override
  String get accountDeleteCancelled =>
      'हटाना रद्द हो गया। आपकी कंपनी जैसी है वैसी ही रहेगी।';

  @override
  String get accountDeleteChanged =>
      'आपकी कंपनी में कुछ बदल गया है। क्या होगा यह देखें, फिर दोबारा कोशिश करें।';

  @override
  String accountDeleteCompanyErases(String company) {
    return 'आप $company के अकेले मालिक हैं, इसलिए आपका खाता हटाने से कंपनी मिट जाती है: हर परियोजना, बिल, फ़ोटो, मज़दूर और उपयोगकर्ता।';
  }

  @override
  String get accountDeleteCompanyTitle => 'अपना खाता और कंपनी हटाएँ';

  @override
  String accountDeleteCompanyWhen(String date) {
    return 'यह $date के बाद मिटाई जाती है। तब तक कंपनी का काम चलता रहता है, और आप फिर से साइन इन करके सेटिंग्स, खाता हटाएँ खोलकर इसे रद्द कर सकते हैं।';
  }

  @override
  String accountDeleteConfirmName(String company) {
    return 'पुष्टि के लिए $company लिखें';
  }

  @override
  String get accountDeleteExport =>
      'पहले कॉपी चाहिए? मालिक वेबसाइट पर Account, Company data में कंपनी का डेटा एक्सपोर्ट कर सकते हैं।';

  @override
  String get accountDeleteHandOver =>
      'आपके बिना कंपनी चलती रखनी हो, तो पहले वेबसाइट पर (Team) किसी और सदस्य को मालिक बनाएँ, फिर यहाँ अपना खाता हटाएँ।';

  @override
  String get accountDeleteHold => 'खाता हटाने के लिए दबाए रखें';

  @override
  String get accountDeleteLoading => 'क्या होगा, जाँच रहे हैं';

  @override
  String get accountDeleteMemberNow =>
      'आपका साइन-इन, नाम, ईमेल और मोबाइल नंबर अभी हटा दिए जाते हैं। इसे वापस नहीं किया जा सकता।';

  @override
  String accountDeleteMemberWork(String company) {
    return '$company के लिए आपका दर्ज किया काम — रिपोर्ट, माप, बिल, हाज़िरी और फ़ोटो — कंपनी के पास ही रहता है, “हटाया गया उपयोगकर्ता” के रूप में दिखता है।';
  }

  @override
  String get accountDeleteOfflineBody => 'इंटरनेट जोड़ें और फिर कोशिश करें।';

  @override
  String get accountDeleteOfflineTitle => 'खाता हटाने के लिए इंटरनेट चाहिए।';

  @override
  String accountDeleteOthers(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count और लोगों की पहुँच ख़त्म हो जाएगी।',
      one: '1 और व्यक्ति की पहुँच ख़त्म हो जाएगी।',
      zero: 'इस कंपनी को कोई और इस्तेमाल नहीं करता।',
    );
    return '$_temp0';
  }

  @override
  String get accountDeletePassword => 'आपका पासवर्ड';

  @override
  String accountDeletePendingBody(String company, String date) {
    return '$company $date के बाद मिटा दी जाएगी।';
  }

  @override
  String get accountDeletePendingTitle => 'आपकी कंपनी मिटाई जानी तय है';

  @override
  String get accountDeletePhoneCleared =>
      'यह फ़ोन साइन आउट हो जाता है और इस पर Nirmaan360 का डेटा मिटा दिया जाता है।';

  @override
  String get accountDeleteSpoken => 'मेरा खाता हटाएँ';

  @override
  String get accountDeleteTaxRecords =>
      'आपकी कंपनी द्वारा Nirmaan360 को किए गए भुगतानों के रिकॉर्ड भारतीय कर क़ानून के अनुसार रखे जाते हैं।';

  @override
  String get accountDeleteTile => 'खाता हटाएँ';

  @override
  String get accountDeleteTileSubtitle => 'अपना साइन-इन और निजी जानकारी हटाएँ';

  @override
  String accountDeleteUnsent(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other:
          'इस फ़ोन की $count प्रविष्टियाँ सर्वर तक नहीं पहुँची हैं। वे खो जाएँगी।',
      one: 'इस फ़ोन की 1 प्रविष्टि सर्वर तक नहीं पहुँची है। वह खो जाएगी।',
    );
    return '$_temp0';
  }

  @override
  String get accountDeleteWrongName =>
      'कंपनी का नाम ठीक वैसा ही लिखें जैसा दिखाया गया है।';

  @override
  String get accountDeleteWrongPassword => 'यह पासवर्ड सही नहीं है।';

  @override
  String accountDeletedCompanyToast(String date) {
    return 'आपकी कंपनी $date के बाद मिटा दी जाएगी। रद्द करने के लिए उससे पहले साइन इन करें।';
  }

  @override
  String get accountDeletedToast => 'आपका खाता हटा दिया गया है।';

  @override
  String get alertsDashboardEmpty => 'इस प्रोजेक्ट के लिए अभी कोई आंकड़े नहीं।';

  @override
  String alertsHomeCriticalCount(int count) {
    return '$count गंभीर';
  }

  @override
  String get alertsHomeDashboardOnly => 'इस साइट का डैशबोर्ड';

  @override
  String alertsHomeOpen(int count) {
    return '$count खुले';
  }

  @override
  String get alertsHomeOverview => 'इस साइट का डैशबोर्ड और अलर्ट';

  @override
  String get alertsHomeTitle => 'साइट के अलर्ट';

  @override
  String get alertsListRefusedMessage =>
      'अलर्ट आपकी भूमिका के लिए उपलब्ध नहीं हैं। ज़रूरत हो तो ऑफ़िस से पूछें।';

  @override
  String get alertsLoading => 'साइट का हाल लोड हो रहा है';

  @override
  String get alertsNoDataMessage =>
      'इस प्रोजेक्ट का हाल लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद ऑफ़लाइन भी खुलेगा।';

  @override
  String get alertsNoneMessage => 'साइट पर कुछ देखने लायक होगा तो यहाँ दिखेगा।';

  @override
  String get alertsNoneTitle => 'ध्यान देने लायक कुछ नहीं';

  @override
  String get alertsRefusedMessage =>
      'साइट का हाल आपकी भूमिका के लिए उपलब्ध नहीं है। ज़रूरत हो तो ऑफ़िस से पूछें।';

  @override
  String get alertsRefusedTitle => 'यह आपकी भूमिका में नहीं है';

  @override
  String alertsScanDone(int added, int open, int resolved) {
    return 'स्कैन हो गया। $added नए, $open खुले, $resolved हटाए गए।';
  }

  @override
  String get alertsScanFailed =>
      'स्कैन नहीं हो सका। थोड़ी देर में फिर कोशिश करें।';

  @override
  String get alertsScanNow => 'अभी स्कैन करें';

  @override
  String get alertsScanOffline =>
      'स्कैन के लिए इंटरनेट चाहिए। जुड़ें और फिर कोशिश करें।';

  @override
  String alertsScanRefused(String message) {
    return 'सर्वर ने कहा: $message';
  }

  @override
  String alertsScanWait(int seconds) {
    return '$seconds सेकंड बाद फिर कोशिश करें।';
  }

  @override
  String get alertsScanning => 'साइट स्कैन हो रही है…';

  @override
  String get alertsSeverityCritical => 'गंभीर';

  @override
  String get alertsSeverityInfo => 'जानकारी के लिए';

  @override
  String get alertsSeverityWarning => 'चेतावनी';

  @override
  String alertsShowingSome(int shown, int total) {
    return '$total में से सबसे ज़रूरी $shown दिखाए हैं। बाकी वेबसाइट पर हैं।';
  }

  @override
  String alertsSince(String date) {
    return '$date से';
  }

  @override
  String get alertsTabAlerts => 'अलर्ट';

  @override
  String get alertsTabDashboard => 'डैशबोर्ड';

  @override
  String get alertsTitle => 'साइट का हाल';

  @override
  String get announcementsEmptyMessage =>
      'आपकी कंपनी की ख़बरें और रिमाइंडर यहाँ दिखेंगे।';

  @override
  String get announcementsEmptyTitle => 'अभी कोई घोषणा नहीं';

  @override
  String get announcementsFailedMessage => 'थोड़ी देर में फिर से कोशिश करें।';

  @override
  String get announcementsFailedTitle => 'घोषणाएँ पढ़ी नहीं जा सकीं';

  @override
  String get announcementsLoading => 'घोषणाएँ पढ़ रहे हैं';

  @override
  String get announcementsMore => 'पुरानी दिखाएँ';

  @override
  String get announcementsOfflineMessage =>
      'घोषणाएँ सर्वर पर रहती हैं। इंटरनेट जोड़ें और फिर से कोशिश करें।';

  @override
  String get announcementsOfflineTitle => 'इंटरनेट चाहिए';

  @override
  String get announcementsReminder => 'रिमाइंडर';

  @override
  String get announcementsRetry => 'फिर से कोशिश करें';

  @override
  String get announcementsSettingsSubtitle => 'आपको भेजी गई ख़बरें और रिमाइंडर';

  @override
  String get announcementsTitle => 'घोषणाएँ';

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
  String get approvalsDocBillNote => 'क्रेडिट / डेबिट नोट';

  @override
  String get approvalsDocContractorPayment => 'ठेकेदार भुगतान';

  @override
  String get approvalsDocDpr => 'दैनिक रिपोर्ट';

  @override
  String get approvalsDocIndent => 'इंडेंट';

  @override
  String get approvalsDocMeasurement => 'माप';

  @override
  String get approvalsDocOther => 'दस्तावेज़';

  @override
  String get approvalsDocRfi => 'RFI';

  @override
  String get approvalsDocVendorBill => 'विक्रेता बिल';

  @override
  String get approvalsEmptyBody => 'नई मंज़ूरियाँ यहाँ दिखेंगी।';

  @override
  String get approvalsEmptyTitle => 'कुछ बाकी नहीं';

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
  String get approvalsForYou => 'आपके पास बाकी';

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
  String approvalsIndentForLine(String code) {
    return 'BOQ लाइन $code के लिए';
  }

  @override
  String get approvalsIndentItems => 'माँगी गई सामग्री';

  @override
  String get approvalsIndentLines => 'सामग्री';

  @override
  String get approvalsIndentRequiredBy => 'कब तक चाहिए';

  @override
  String get approvalsIndentStore => 'स्टोर';

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
  String get approvalsNoteAgainstBill => 'संबंधित बिल';

  @override
  String get approvalsNoteCredit => 'क्रेडिट नोट';

  @override
  String get approvalsNoteDebit => 'डेबिट नोट';

  @override
  String get approvalsNoteEffect => 'बिल पर असर';

  @override
  String get approvalsNoteNumber => 'नोट नंबर';

  @override
  String get approvalsNoteReason => 'कारण';

  @override
  String get approvalsNoteTaxable => 'कर योग्य राशि';

  @override
  String get approvalsNoteTotal => 'नोट की कुल राशि';

  @override
  String get approvalsOffline => 'नेटवर्क नहीं है।';

  @override
  String get approvalsOfflineDetail =>
      'पढ़ सकते हैं, पर मंज़ूरी के लिए नेटवर्क चाहिए।';

  @override
  String get approvalsOnOthers => 'दूसरों के पास बाकी';

  @override
  String get approvalsOpen => 'खोलें';

  @override
  String get approvalsOpenOnWeb =>
      'यह दस्तावेज़ अभी फ़ोन पर नहीं दिखता। कृपया वेबसाइट पर जाँचकर मंज़ूर करें।';

  @override
  String get approvalsParty => 'पार्टी';

  @override
  String approvalsPayAhead(String gap) {
    return 'मंज़ूर करने पर भुगतान हुए काम से $gap अंक आगे हो जाएगा।';
  }

  @override
  String get approvalsPayContractor => 'ठेकेदार';

  @override
  String get approvalsPayInStep =>
      'मंज़ूर करने पर भी भुगतान हुए काम के अंदर रहता है।';

  @override
  String approvalsPayOverLimit(String limit) {
    return 'यह इस प्रोजेक्ट की $limit अंक की सीमा से ज़्यादा है।';
  }

  @override
  String get approvalsPayOverrideApprove => 'विशेष मंज़ूरी से मंज़ूर करें';

  @override
  String get approvalsPayOverrideOwnerTitle => 'आपकी लिखित विशेष मंज़ूरी चाहिए';

  @override
  String get approvalsPayOverrideReasonHelp =>
      'कम से कम एक पूरा वाक्य लिखें। यह भुगतान के साथ और ऑडिट लॉग में रखा जाता है।';

  @override
  String get approvalsPayOverrideReasonLabel => 'मालिक का कारण';

  @override
  String get approvalsPayOverrideReasonTitle =>
      'यह भुगतान हुए काम से आगे क्यों जा सकता है?';

  @override
  String get approvalsPayOverrideShort => 'कम से कम 4 शब्द और 20 अक्षर।';

  @override
  String get approvalsPayOverrideTitle => 'इसे सिर्फ़ मालिक मंज़ूर कर सकते हैं';

  @override
  String get approvalsPayPaidAfter => 'मंज़ूर करने पर भुगतान';

  @override
  String get approvalsPayPaidNow => 'अब तक दिया';

  @override
  String approvalsPayRatio(String paid, String work) {
    return 'भुगतान $paid, काम $work';
  }

  @override
  String get approvalsPayWorkVsPaid => 'काम बनाम भुगतान';

  @override
  String get approvalsPeriod => 'अवधि';

  @override
  String get approvalsPhotos => 'फ़ोटो';

  @override
  String get approvalsRaisedBy => 'किसने भेजा';

  @override
  String get approvalsReasonAmounts => 'राशि मेल नहीं खाती';

  @override
  String get approvalsReasonBillMissing => 'बिल या रसीद नहीं है';

  @override
  String get approvalsReasonDeductions => 'कटौतियाँ जाँचें';

  @override
  String get approvalsReasonDimensions => 'माप जाँचें';

  @override
  String get approvalsReasonDuplicate =>
      'पहले ही दिया जा चुका है, या दो बार माँगा गया';

  @override
  String get approvalsReasonIndentMaterial => 'गलत सामग्री या इकाई';

  @override
  String get approvalsReasonIndentNotNeeded => 'अभी ज़रूरत नहीं';

  @override
  String get approvalsReasonIndentStock => 'स्टोर में स्टॉक पहले से है';

  @override
  String get approvalsReasonLocation => 'स्थान साफ़ लिखें';

  @override
  String get approvalsReasonManpower => 'मज़दूर संख्या गलत है';

  @override
  String get approvalsReasonNote => 'कारण स्पष्ट नहीं है';

  @override
  String get approvalsReasonPaymentAhead => 'भुगतान हुए काम से आगे है';

  @override
  String get approvalsReasonPeriod => 'बिल की अवधि गलत है';

  @override
  String get approvalsReasonPhotos => 'फ़ोटो जोड़ें';

  @override
  String get approvalsReasonQuantityHigh => 'मात्रा ज़्यादा लग रही है';

  @override
  String get approvalsReasonRfiAnswered =>
      'पहले ही उत्तर मिल चुका है, या ज़रूरत नहीं';

  @override
  String get approvalsReasonRfiDrawing => 'ड्रॉइंग का संदर्भ नहीं है';

  @override
  String get approvalsReasonRfiUnclear => 'प्रश्न साफ़ नहीं है';

  @override
  String get approvalsReasonVbNotReceived => 'सामग्री या सेवा नहीं मिली';

  @override
  String get approvalsReasonVbTax => 'GST या TDS गलत है';

  @override
  String get approvalsReasonWorkDetail => 'काम का और विवरण लिखें';

  @override
  String get approvalsReasonWorkNotDone => 'इसका काम अभी पूरा नहीं हुआ';

  @override
  String get approvalsReasonWrongItem => 'BOQ लाइन गलत है';

  @override
  String get approvalsRefresh => 'ताज़ा करें';

  @override
  String get approvalsRefreshing => 'ताज़ा हो रहा है';

  @override
  String get approvalsReject => 'अस्वीकार करें';

  @override
  String get approvalsRejectHelp =>
      'अस्वीकार किया गया दस्तावेज़ हमेशा के लिए बंद हो जाता है। उसे ठीक करके दोबारा नहीं भेजा जा सकता। एक कारण चुनें या खुद लिखें।';

  @override
  String get approvalsRejectHint => 'क्यों अस्वीकार किया';

  @override
  String get approvalsRejectTitle => 'अस्वीकार क्यों कर रहे हैं?';

  @override
  String get approvalsRejectedToast => 'आपकी टिप्पणी के साथ अस्वीकार किया।';

  @override
  String get approvalsRemarkHint => 'क्या ठीक करना है';

  @override
  String get approvalsRemarkLabel => 'टिप्पणी';

  @override
  String get approvalsRemarks => 'टिप्पणी';

  @override
  String get approvalsReportDate => 'रिपोर्ट की तारीख़';

  @override
  String get approvalsRfiAddressedTo => 'किसे भेजा जाएगा';

  @override
  String get approvalsRfiAnswerBy => 'उत्तर कब तक चाहिए';

  @override
  String get approvalsRfiDrawing => 'ड्रॉइंग';

  @override
  String get approvalsRfiQuestion => 'प्रश्न';

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
  String get approvalsVbBillDate => 'बिल की तारीख़';

  @override
  String get approvalsVbBillTotal => 'बिल का कुल';

  @override
  String get approvalsVbCostHead => 'खर्च का मद';

  @override
  String get approvalsVbDescription => 'विवरण';

  @override
  String get approvalsVbDueDate => 'भुगतान की आख़िरी तारीख़';

  @override
  String get approvalsVbTds => 'TDS';

  @override
  String get approvalsWeather => 'मौसम';

  @override
  String get approvalsWorkDone => 'किया गया काम';

  @override
  String get approvalsWorkers => 'मज़दूर';

  @override
  String get attendanceAddFirstWorker => 'पहला कामगार जोड़ें';

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
      'जिनकी हाज़िरी लग चुकी है, उन्हें छोड़ देता है।';

  @override
  String get attendanceChooseProject => 'प्रोजेक्ट चुनें';

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
  String get attendanceLoading => 'हाज़िरी लोड हो रही है';

  @override
  String get attendanceMarkedSoFar => 'अब तक दर्ज';

  @override
  String get attendanceNoProjectMessage =>
      'पहले प्रोजेक्ट चुनें, फिर यहाँ हाज़िरी लगाएँ।';

  @override
  String get attendanceNoProjectTitle => 'कोई प्रोजेक्ट नहीं चुना';

  @override
  String get attendanceNoWorkersAsk => 'ऑफ़िस से कामगार जुड़वाएँ।';

  @override
  String get attendanceNoWorkersMessage =>
      'हाज़िरी शुरू करने के लिए पहला कामगार जोड़ें।';

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
  String get attendanceScanCard => 'कार्ड स्कैन';

  @override
  String get attendanceSelfie => 'सेल्फ़ी';

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
  String get boqAddItem => 'आइटम जोड़ें';

  @override
  String get boqAmountLabel => 'रकम';

  @override
  String boqBalance(String qty, String unit) {
    return 'शेष $qty $unit';
  }

  @override
  String get boqBalanceLabel => 'शेष';

  @override
  String get boqBalanceValueLabel => 'शेष मूल्य';

  @override
  String get boqDeleteAction => 'यह आइटम हटाएँ';

  @override
  String get boqDeleteBody =>
      'यह सभी के लिए BOQ से हटा दिया जाएगा। BOQ इतिहास में यह आपके नाम और कारण के साथ रहेगा। ऐप से इसे वापस नहीं लाया जा सकता।';

  @override
  String get boqDeleteConfirm => 'आइटम हटाएँ';

  @override
  String boqDeleteDone(String code) {
    return 'आइटम $code BOQ से हटाया गया।';
  }

  @override
  String get boqDeleteFailed => 'आइटम हटाया नहीं जा सका। फिर से कोशिश करें।';

  @override
  String get boqDeleteKeep => 'रहने दें';

  @override
  String get boqDeleteOffline => 'हटाने के लिए इंटरनेट चाहिए।';

  @override
  String get boqDeleteOwnerNote =>
      'BOQ आइटम केवल कंपनी का मालिक हटा सकता है। जिस आइटम पर माप, रोज़ की रिपोर्ट का काम, टेंडर या बिल हों, उसे हटाया नहीं जा सकता।';

  @override
  String get boqDeleteOwnerOnly => 'BOQ आइटम केवल कंपनी का मालिक हटा सकता है।';

  @override
  String get boqDeleteReason => 'इसे क्यों हटाया जा रहा है?';

  @override
  String get boqDeleteReasonRequired => 'इसे क्यों हटा रहे हैं, लिखें।';

  @override
  String get boqDeleteRefusedTitle => 'यह आइटम हटाया नहीं जा सकता';

  @override
  String boqDeleteTitle(String code) {
    return 'आइटम $code हटाएँ?';
  }

  @override
  String get boqDeleteUnsent =>
      'इस फ़ोन पर इस आइटम का काम है जो अभी भेजा नहीं गया। पहले उसे भेजें, फिर हटाएँ।';

  @override
  String get boqEditAction => 'आइटम बदलें';

  @override
  String get boqEditChanged =>
      'आपके खोलने के बाद किसी ने यह आइटम बदला। अब यह वैसा दिख रहा है जैसा ऑफिस में है। जाँचें और फिर से सहेजें।';

  @override
  String get boqEditCode => 'आइटम कोड';

  @override
  String get boqEditCodeRequired => 'आइटम कोड डालें।';

  @override
  String get boqEditGone => 'यह आइटम अब BOQ में नहीं है।';

  @override
  String boqEditMeasured(String qty, String unit) {
    return 'अब तक मापा गया: $qty $unit। मापी गई या बिल की गई मात्रा से कम मात्रा नहीं रखी जा सकती।';
  }

  @override
  String get boqEditNotAllowed => 'आप BOQ नहीं बदल सकते। ऑफिस से पूछें।';

  @override
  String get boqEditOfflineDetail => 'BOQ आइटम केवल ऑनलाइन बदले जा सकते हैं।';

  @override
  String get boqEditQtyInvalid =>
      'मात्रा अंकों में डालें, अधिकतम 3 दशमलव, जैसे 120.5।';

  @override
  String get boqEditRateInvalid =>
      'दर अंकों में डालें, अधिकतम 2 दशमलव, जैसे 450.00।';

  @override
  String get boqEditReason => 'यह क्यों बदल रहा है?';

  @override
  String get boqEditReasonHelper =>
      'आपके नाम के साथ BOQ इतिहास में रहता है। पहले हुए माप और बिल नहीं बदलते।';

  @override
  String get boqEditReasonHint => 'जैसे आर्किटेक्ट की संशोधित ड्रॉइंग';

  @override
  String get boqEditReasonRequired => 'यह क्यों बदल रहा है, लिखें।';

  @override
  String get boqEditSave => 'बदलाव सहेजें';

  @override
  String get boqEditSaved => 'BOQ आइटम सहेजा गया।';

  @override
  String get boqEditTitle => 'BOQ आइटम बदलें';

  @override
  String get boqEmptyAsk =>
      'BOQ यानी इस प्रोजेक्ट के कामों की सूची। ऑफ़िस से जुड़वाएँ।';

  @override
  String get boqEmptyExplain =>
      'BOQ यानी इस प्रोजेक्ट के कामों की सूची, मात्रा और दर के साथ।';

  @override
  String get boqEmptyTitle => 'BOQ लाइनें अभी नहीं हैं';

  @override
  String get boqFormAdded => 'लाइन जुड़ गई। अगली जोड़ें।';

  @override
  String get boqFormAmount => 'रकम';

  @override
  String get boqFormCode => 'कोड (वैकल्पिक)';

  @override
  String get boqFormCodeHelper => 'खाली छोड़ें तो अगला नंबर मिलेगा।';

  @override
  String get boqFormDescription => 'काम का विवरण';

  @override
  String get boqFormDescriptionHint => 'जैसे: स्लैब में M25 कंक्रीट';

  @override
  String get boqFormDescriptionRequired => 'काम का विवरण लिखें।';

  @override
  String get boqFormFailed => 'लाइन सेव नहीं हुई। फिर से कोशिश करें।';

  @override
  String get boqFormFinish => 'सेव करके पूरा करें';

  @override
  String get boqFormQty => 'मात्रा';

  @override
  String get boqFormQtyRequired => 'मात्रा डालें।';

  @override
  String get boqFormRate => 'दर';

  @override
  String get boqFormRateRequired => 'दर डालें।';

  @override
  String get boqFormSaveAnother => 'सेव करके और जोड़ें';

  @override
  String get boqFormSection => 'सेक्शन (वैकल्पिक)';

  @override
  String get boqFormSectionHint => 'जैसे: सुपरस्ट्रक्चर';

  @override
  String get boqFormTitle => 'BOQ आइटम जोड़ें';

  @override
  String get boqFormUnit => 'इकाई';

  @override
  String get boqFormUnitRequired => 'इकाई चुनें।';

  @override
  String boqItemCode(String code) {
    return 'आइटम $code';
  }

  @override
  String boqLinesAdded(int count) {
    return '$count लाइनें जोड़ीं';
  }

  @override
  String get boqLoading => 'BOQ लोड हो रहा है';

  @override
  String boqMeasuredShare(int percent) {
    return '$percent% मापा';
  }

  @override
  String get boqMissingAsk =>
      'ऑफ़िस से जुड़वाएँ। पहले से जुड़ी हों तो एक बार नेटवर्क से जुड़ें।';

  @override
  String get boqMissingManage => 'लाइनें जोड़ें, फिर यहाँ चुनें।';

  @override
  String get boqMissingTitle => 'इस प्रोजेक्ट में अभी BOQ लाइनें नहीं हैं';

  @override
  String get boqNoMatchMessage => 'कोई और कोड या शब्द आज़माएँ।';

  @override
  String get boqNoMatchTitle => 'कुछ नहीं मिला';

  @override
  String get boqNoProjectMessage => 'BOQ देखने के लिए पहले प्रोजेक्ट चुनें।';

  @override
  String get boqNoProjectTitle => 'कोई प्रोजेक्ट नहीं चुना';

  @override
  String get boqNoSection => 'अन्य लाइनें';

  @override
  String get boqOfflineDetail => 'BOQ लाइनें सिर्फ़ ऑनलाइन सेव होती हैं।';

  @override
  String get boqOfflineTitle => 'नेटवर्क नहीं है।';

  @override
  String get boqOpenAction => 'BOQ खोलें';

  @override
  String boqOver(String qty, String unit) {
    return '$qty $unit ज़्यादा';
  }

  @override
  String get boqOverLabel => 'ज़्यादा';

  @override
  String get boqOverValueLabel => 'BOQ से ज़्यादा मूल्य';

  @override
  String get boqRefreshFailed =>
      'अपडेट नहीं हो सका। फ़ोन पर जो है वही दिखा रहे हैं।';

  @override
  String get boqSearchHint => 'कोड या काम';

  @override
  String get boqSearchLabel => 'लाइनें खोजें';

  @override
  String get boqShownTotalTitle => 'दिखाई गई लाइनों का कुल';

  @override
  String get boqTitle => 'BOQ';

  @override
  String get boqTotalTitle => 'BOQ कुल';

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
  String get contractorAdvance => 'अग्रिम';

  @override
  String get contractorAgainst => 'किसके लिए';

  @override
  String get contractorAgainstLine => 'BOQ मद';

  @override
  String get contractorAgainstPart => 'भाग';

  @override
  String get contractorAgainstWhole => 'पूरा ठेका';

  @override
  String get contractorAmount => 'रकम (₹)';

  @override
  String get contractorAmountError =>
      'शून्य से ज़्यादा रकम लिखें, दशमलव के बाद ज़्यादा से ज़्यादा दो अंक।';

  @override
  String get contractorAmountLabel => 'रकम';

  @override
  String get contractorApprovalNote =>
      'यह मंज़ूरी के लिए जाएगा। मंज़ूर होने तक कुछ भी भुगतान नहीं माना जाएगा।';

  @override
  String get contractorBalance => 'देय शेष';

  @override
  String get contractorBillFailed => 'बिल लोड नहीं हुआ। इंटरनेट जाँचें।';

  @override
  String get contractorBillNotPicture =>
      'यह बिल तस्वीर नहीं है। इसे वेबसाइट पर खोलें।';

  @override
  String get contractorBillPhoto => 'बिल की फ़ोटो जोड़ें';

  @override
  String get contractorClosed => 'बंद';

  @override
  String get contractorContract => 'ठेका';

  @override
  String get contractorContractChoose => 'ठेका चुनें';

  @override
  String get contractorContractClosed => 'यह ठेका बंद है।';

  @override
  String get contractorContractSearch => 'ठेका खोजें';

  @override
  String get contractorEmptyMessage =>
      'इस प्रोजेक्ट के वर्क ऑर्डर यहाँ दिखेंगे।';

  @override
  String get contractorEmptyTitle => 'अभी कोई ठेका नहीं';

  @override
  String get contractorEnteredBy => 'दर्ज करने वाले';

  @override
  String contractorHomeAhead(int count) {
    return '$count काम से ज़्यादा भुगतान';
  }

  @override
  String contractorHomeCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count ठेके',
      one: '1 ठेका',
    );
    return '$_temp0';
  }

  @override
  String get contractorHomeInStep => 'सब काम के बराबर';

  @override
  String contractorHomeOver(int count) {
    return '$count सीमा से ज़्यादा';
  }

  @override
  String get contractorHomeOverview => 'हर ठेके में किया गया काम';

  @override
  String get contractorImpactAfter => 'मंज़ूर होने पर';

  @override
  String get contractorImpactLine => 'यह मद';

  @override
  String get contractorImpactNow => 'अभी';

  @override
  String get contractorInStep => 'काम के बराबर';

  @override
  String get contractorKind => 'यह भुगतान';

  @override
  String get contractorKindAdvance => 'अग्रिम';

  @override
  String get contractorKindWork => 'किए काम के लिए';

  @override
  String get contractorLatestOnly => 'नए 50 दिखाए हैं। बाकी वेबसाइट पर हैं।';

  @override
  String get contractorLineChoose => 'BOQ मद चुनें';

  @override
  String get contractorLines => 'काम की मदें';

  @override
  String get contractorLinesLoadOnline =>
      'यह ठेका एक बार ऑनलाइन खुलने पर BOQ मदें और भाग लोड होंगे।';

  @override
  String get contractorLoading => 'ठेके लोड हो रहे हैं';

  @override
  String get contractorMode => 'भुगतान का तरीका';

  @override
  String get contractorModeBank => 'बैंक';

  @override
  String get contractorModeCash => 'नकद';

  @override
  String get contractorModeRequired => 'भुगतान कैसे हुआ, चुनें।';

  @override
  String get contractorModeUpi => 'UPI';

  @override
  String get contractorNoDataMessage =>
      'इस प्रोजेक्ट के ठेके लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद ऑफ़लाइन भी खुलेंगे।';

  @override
  String get contractorNoPaymentsMessage =>
      'आपके किए अनुरोध उनकी स्थिति के साथ यहाँ दिखेंगे।';

  @override
  String get contractorNoPaymentsTitle => 'अभी भुगतान का कोई अनुरोध नहीं';

  @override
  String get contractorNotes => 'टिप्पणी';

  @override
  String get contractorOptional => 'वैकल्पिक';

  @override
  String get contractorOtherDate => 'दूसरी तारीख';

  @override
  String get contractorOverLimit => 'सीमा से ज़्यादा';

  @override
  String get contractorPaid => 'भुगतान हुआ';

  @override
  String get contractorPaidAhead => 'काम से ज़्यादा भुगतान';

  @override
  String get contractorPaidOn => 'भुगतान की तारीख';

  @override
  String get contractorPaidTo => 'किसे भुगतान';

  @override
  String get contractorPaidToHint => 'ठेकेदार हो तो खाली छोड़ें';

  @override
  String get contractorPartChoose => 'भाग चुनें';

  @override
  String get contractorPartDone => 'पूरा';

  @override
  String get contractorPartNotDone => 'अधूरा';

  @override
  String get contractorPartNotPaid => 'भुगतान नहीं';

  @override
  String get contractorPartOver => 'भाग से ज़्यादा भुगतान';

  @override
  String get contractorPartPaid => 'भुगतान हुआ';

  @override
  String get contractorPartPartPaid => 'आंशिक भुगतान';

  @override
  String get contractorParts => 'भाग';

  @override
  String get contractorReference => 'रेफ़रेंस';

  @override
  String get contractorRefusedMessage =>
      'ठेके आपकी भूमिका के लिए उपलब्ध नहीं हैं। ज़रूरत हो तो ऑफ़िस से पूछें।';

  @override
  String get contractorRefusedTitle => 'यह आपकी भूमिका में नहीं है';

  @override
  String get contractorRequest => 'भुगतान अनुरोध';

  @override
  String get contractorReversal => 'उलटी प्रविष्टि';

  @override
  String get contractorSearchNone => 'कुछ नहीं मिला';

  @override
  String get contractorSend => 'अनुरोध भेजें';

  @override
  String get contractorShortBy => 'कम पड़ने वाली रकम';

  @override
  String get contractorTabContracts => 'ठेके';

  @override
  String get contractorTabPayments => 'भुगतान';

  @override
  String get contractorTitle => 'ठेकेदार';

  @override
  String get contractorValue => 'ठेके की राशि';

  @override
  String get contractorViewBill => 'बिल देखें';

  @override
  String get contractorWorkDone => 'किया गया काम';

  @override
  String get contractorWorkLeft => 'बाकी काम';

  @override
  String get contractorWorkLeftValue => 'बाकी काम की राशि';

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
  String dprBoqDonePercent(int percent) {
    return 'BOQ का $percent प्रतिशत मापा गया';
  }

  @override
  String get dprBoqEmpty => 'BOQ आइटम नहीं मिले।';

  @override
  String get dprCameraFailed =>
      'कैमरा नहीं खुला। फ़ोन की सेटिंग में कैमरे की अनुमति दें।';

  @override
  String get dprCanStillSend => 'फिर भी भेज सकते हैं।';

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
  String get dprLoading => 'लोड हो रहा है';

  @override
  String get dprLocked =>
      'यह रिपोर्ट मंज़ूरी के लिए जा चुकी है, अब बदली नहीं जा सकती।';

  @override
  String get dprMissedDays => 'छूटे हुए दिन';

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
  String get dprNeedWorkers => 'आए कामगारों की संख्या भरें।';

  @override
  String get dprNoPhotosYet => 'कोई फ़ोटो नहीं जोड़ी।';

  @override
  String get dprNoProject => 'पहले प्रोजेक्ट चुनें';

  @override
  String get dprNoWorkYet => 'कोई काम नहीं जोड़ा।';

  @override
  String get dprNotSaved => 'सेव नहीं हुआ';

  @override
  String get dprNotes => 'नोट';

  @override
  String get dprNotesHint => 'आज का और कुछ';

  @override
  String get dprNothingRecorded => 'कुछ दर्ज नहीं';

  @override
  String dprOfDays(int days) {
    return '$days दिनों में से';
  }

  @override
  String get dprOpenOutbox => 'आउटबॉक्स खोलें';

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
  String get dprRemoved => 'हटाया';

  @override
  String get dprReportsThisMonth => 'इस महीने की रिपोर्ट';

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
  String get dprSiteClosedWrite => 'साइट बंद? कारण लिखें';

  @override
  String get dprSyncAttention => 'ध्यान दें';

  @override
  String get dprSyncPhoneOnly => 'केवल फ़ोन पर';

  @override
  String get dprSyncSending => 'भेज रहे हैं';

  @override
  String get dprSyncWaiting => 'भेजना बाकी';

  @override
  String get dprTakePhoto => 'फ़ोटो लें';

  @override
  String get dprTotalWorkers => 'कुल कामगार';

  @override
  String get dprUndo => 'वापस लाएँ';

  @override
  String dprWorkersCount(int count) {
    return '$count कामगार';
  }

  @override
  String get dprWorkersOnSite => 'साइट पर कामगार';

  @override
  String dprWorkersWord(int count) {
    return 'कामगार';
  }

  @override
  String dprWriteFor(String date) {
    return '$date की रिपोर्ट लिखें';
  }

  @override
  String get dprWriteToday => 'आज की रिपोर्ट लिखें';

  @override
  String get dprYesterdayLabel => 'कल';

  @override
  String get dprYesterdayTag => 'कल';

  @override
  String floorPlanBoqQty(String qty, String unit) {
    return 'BOQ $qty $unit';
  }

  @override
  String get floorPlanEmptyMessage => 'पहले प्रोजेक्ट में BOQ आइटम जोड़ें।';

  @override
  String get floorPlanEmptyTitle => 'कोई BOQ आइटम नहीं';

  @override
  String floorPlanFloorDone(String percent) {
    return 'इस मंज़िल पर $percent हुआ';
  }

  @override
  String get floorPlanNoMatch => 'कोई आइटम नहीं मिला';

  @override
  String get floorPlanNotPlanned => 'यहाँ तय नहीं';

  @override
  String floorPlanPlanned(String planned, String unit, String done) {
    return 'तय $planned $unit · हुआ $done';
  }

  @override
  String floorPlanQty(String unit) {
    return 'इस मंज़िल पर तय मात्रा ($unit)';
  }

  @override
  String get floorPlanQtyHelp => 'हटाने के लिए 0 लिखें।';

  @override
  String get floorPlanQtyInvalid =>
      'शून्य या उससे ज़्यादा मात्रा लिखें, ज़्यादा से ज़्यादा तीन दशमलव।';

  @override
  String get floorPlanSaved => 'तय मात्रा सेव हो गई।';

  @override
  String get floorPlanSearch => 'कोड या नाम से खोजें';

  @override
  String get floorPlanTitle => 'तय मात्राएँ';

  @override
  String get floorSetupAddFloor => 'मंज़िल जोड़ें';

  @override
  String get floorSetupAddSegments => 'हिस्सों में बाँटें';

  @override
  String floorSetupAdded(String name) {
    return '$name जोड़ा गया।';
  }

  @override
  String get floorSetupChooseDate => 'तारीख़ चुनें';

  @override
  String get floorSetupClearDate => 'हटाएँ';

  @override
  String get floorSetupDays => 'तय दिन';

  @override
  String get floorSetupDaysInvalid => '1 से 5000 तक पूरे दिन लिखें।';

  @override
  String get floorSetupDeadline => 'आख़िरी तारीख़';

  @override
  String get floorSetupDelete => 'मंज़िल हटाएँ';

  @override
  String get floorSetupDeleteBody =>
      'मंज़िल और उस पर तय मात्राएँ हट जाएँगी। जिस मंज़िल पर काम दर्ज है या जिसके नीचे हिस्से हैं, उसे हटाया नहीं जा सकता।';

  @override
  String get floorSetupDeleteConfirm => 'हटाएँ';

  @override
  String get floorSetupDeleteKeep => 'रहने दें';

  @override
  String floorSetupDeleteTitle(String name) {
    return '$name हटाएँ?';
  }

  @override
  String floorSetupDeleted(String name) {
    return '$name हटा दी गई।';
  }

  @override
  String get floorSetupEmptyMessage =>
      'इस इमारत की मंज़िलें जोड़ें। हर फ़्लैट या हिस्से की प्रगति देखने के लिए मंज़िल को हिस्सों में बाँटें।';

  @override
  String get floorSetupEmptyTitle => 'अभी कोई मंज़िल नहीं';

  @override
  String get floorSetupFailed => 'सेव नहीं हुआ। फिर कोशिश करें।';

  @override
  String get floorSetupFloorName => 'मंज़िल का नाम';

  @override
  String get floorSetupFloorNameHint => 'जैसे: ग्राउंड फ़्लोर';

  @override
  String get floorSetupForbidden => 'यह करने की अनुमति नहीं है।';

  @override
  String get floorSetupLoadFailed => 'मंज़िलें लोड नहीं हुईं।';

  @override
  String get floorSetupLoading => 'मंज़िलें लोड हो रही हैं';

  @override
  String get floorSetupNameRequired => 'नाम लिखें।';

  @override
  String get floorSetupNotPlanned => 'अभी कुछ तय नहीं';

  @override
  String get floorSetupOffline =>
      'इंटरनेट नहीं है। मंज़िलें सिर्फ़ ऑनलाइन सेट होती हैं।';

  @override
  String get floorSetupOpen => 'मंज़िलें सेट करें';

  @override
  String get floorSetupOwnerNote => 'मंज़िल सिर्फ़ कंपनी का मालिक हटा सकता है।';

  @override
  String get floorSetupPlan => 'मात्रा तय करें';

  @override
  String floorSetupPlannedCount(int count) {
    return '$count BOQ आइटम तय';
  }

  @override
  String get floorSetupRename => 'नाम बदलें';

  @override
  String floorSetupRenameTitle(String name) {
    return '$name का नाम बदलें';
  }

  @override
  String get floorSetupRenamed => 'नाम सेव हो गया।';

  @override
  String get floorSetupRetry => 'फिर कोशिश करें';

  @override
  String get floorSetupSave => 'सेव करें';

  @override
  String floorSetupSegmentCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count हिस्से',
      one: '1 हिस्सा',
    );
    return '$_temp0';
  }

  @override
  String floorSetupSegmentsAdded(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count हिस्से जोड़े गए।',
      one: '1 हिस्सा जोड़ा गया।',
    );
    return '$_temp0';
  }

  @override
  String get floorSetupSegmentsHint => 'फ़्लैट 101\nफ़्लैट 102';

  @override
  String get floorSetupSegmentsLabel => 'हिस्सों के नाम, एक लाइन में एक';

  @override
  String floorSetupSegmentsNotAdded(String names) {
    return 'नहीं जुड़े: $names';
  }

  @override
  String floorSetupSegmentsTitle(String name) {
    return '$name के हिस्से';
  }

  @override
  String get floorSetupShare => 'BOQ मंज़िलों में बाँटें';

  @override
  String get floorSetupStart => 'शुरुआत की तारीख़';

  @override
  String get floorSetupTimeFrame => 'समय-सीमा';

  @override
  String get floorSetupTimeFrameNone => 'तय नहीं';

  @override
  String get floorSetupTimeFrameSaved => 'समय-सीमा सेव हो गई।';

  @override
  String floorSetupTimeFrameSummary(
    String start,
    String days,
    String deadline,
  ) {
    return 'शुरुआत $start · $days दिन · आख़िरी तारीख़ $deadline';
  }

  @override
  String get floorSetupTitle => 'मंज़िलें और हिस्से';

  @override
  String get floorSplitAllLines => 'सभी BOQ आइटम';

  @override
  String floorSplitDone(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count BOQ आइटम बाँटे गए।',
      one: '1 BOQ आइटम बाँटा गया।',
    );
    return '$_temp0';
  }

  @override
  String get floorSplitExplain =>
      'चुना गया हर BOQ आइटम चुनी गई मंज़िलों और हिस्सों में बराबर बँटता है। इन आइटम की बाकी मंज़िलों पर तय मात्रा बदल जाती है।';

  @override
  String get floorSplitFloors => 'मंज़िलें और हिस्से';

  @override
  String get floorSplitLines => 'BOQ आइटम';

  @override
  String get floorSplitNeedFloor => 'कम से कम एक मंज़िल चुनें।';

  @override
  String get floorSplitNeedLine => 'कम से कम एक BOQ आइटम चुनें।';

  @override
  String get floorSplitSubmit => 'बराबर बाँटें';

  @override
  String get floorSplitTitle => 'BOQ मंज़िलों में बाँटें';

  @override
  String get inspectionCategoryGeneral => 'सामान्य';

  @override
  String get inspectionCategoryPourCard => 'पोर कार्ड';

  @override
  String get inspectionCategoryPpe => 'PPE';

  @override
  String get inspectionCategoryReinforcement => 'सरिया';

  @override
  String get inspectionCategorySafety => 'सुरक्षा';

  @override
  String get inspectionCategoryShuttering => 'शटरिंग';

  @override
  String get inspectionDiscard => 'रद्द करें';

  @override
  String get inspectionDiscardBody => 'अब तक दर्ज किया हुआ सब मिट जाएगा।';

  @override
  String get inspectionDiscardTitle => 'यह निरीक्षण रद्द करें?';

  @override
  String get inspectionFillChoose => 'पास, फेल या लागू नहीं चुनें';

  @override
  String get inspectionFillDate => 'निरीक्षण की तारीख';

  @override
  String get inspectionFillLocation => 'स्थान';

  @override
  String get inspectionFillLocationHint => 'जैसे स्लैब L3 या गेट 2। वैकल्पिक।';

  @override
  String get inspectionFillLockedNote =>
      'पूरा होने के बाद निरीक्षण बदला नहीं जा सकता।';

  @override
  String inspectionFillMarked(int done, int total) {
    return '$total में से $done दर्ज';
  }

  @override
  String inspectionFillNeedRemark(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'फेल $count बिंदुओं का कारण लिखें',
      one: 'फेल 1 बिंदु का कारण लिखें',
    );
    return '$_temp0';
  }

  @override
  String inspectionFillUnmarked(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count बिंदुओं का नतीजा अभी दर्ज नहीं',
      one: '1 बिंदु का नतीजा अभी दर्ज नहीं',
    );
    return '$_temp0';
  }

  @override
  String get inspectionFillWrong => 'क्या गड़बड़ है?';

  @override
  String get inspectionFillWrongRequired =>
      'लिखें कि क्या गड़बड़ है। फेल बिंदु के लिए यह ज़रूरी है।';

  @override
  String get inspectionFinish => 'निरीक्षण पूरा करें';

  @override
  String get inspectionGoneMessage =>
      'यह निरीक्षण पूरा हो गया या रद्द कर दिया गया।';

  @override
  String get inspectionGoneTitle => 'यह इस फ़ोन पर नहीं है';

  @override
  String get inspectionItemsNotLoaded =>
      'बिंदु अभी इस फ़ोन पर नहीं हैं। लोड करने के लिए इंटरनेट से जुड़ें।';

  @override
  String get inspectionNotOnPhone => 'लोड करने के लिए इंटरनेट से जुड़ें।';

  @override
  String get inspectionOpenNote =>
      'यह निरीक्षण वेबसाइट पर शुरू हुआ और अधूरा है। इसे वहीं पूरा करें।';

  @override
  String get inspectionOpenOutbox => 'भेजने की सूची खोलें';

  @override
  String get inspectionResultFail => 'फेल';

  @override
  String get inspectionResultNa => 'लागू नहीं';

  @override
  String get inspectionResultPass => 'पास';

  @override
  String get inspectionResultPending => 'नतीजा नहीं';

  @override
  String get inspectionStatusFailed => 'फेल हुआ';

  @override
  String get inspectionStatusOpen => 'चल रहा है';

  @override
  String get inspectionStatusPassed => 'पास हुआ';

  @override
  String get inspectionWaitingNote =>
      'इस फ़ोन पर सेव है। इंटरनेट आने पर भेजा जाएगा, उसके बाद बदला नहीं जा सकेगा।';

  @override
  String get inspectionsChecklistSearch => 'चेकलिस्ट खोजें';

  @override
  String get inspectionsDraftWord => 'अधूरा';

  @override
  String get inspectionsDraftsHeading => 'भरे जा रहे हैं';

  @override
  String get inspectionsEmptyMessage =>
      'चेकलिस्ट से एक शुरू करें। यह इस फ़ोन पर सेव रहता है और इंटरनेट आने पर भेज दिया जाता है।';

  @override
  String get inspectionsEmptyTitle => 'अभी कोई निरीक्षण नहीं';

  @override
  String inspectionsFailedCount(int count) {
    return '$count फेल';
  }

  @override
  String inspectionsItemCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count बिंदु',
      one: '1 बिंदु',
    );
    return '$_temp0';
  }

  @override
  String get inspectionsLatestOnly => 'नए 25 दिखाए हैं। बाकी वेबसाइट पर हैं।';

  @override
  String get inspectionsLoading => 'निरीक्षण लोड हो रहे हैं';

  @override
  String get inspectionsNoDataMessage =>
      'इस प्रोजेक्ट की चेकलिस्ट लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद ऑफ़लाइन भी खुलेंगी।';

  @override
  String get inspectionsRecentHeading => 'हाल के';

  @override
  String get inspectionsRefusedMessage =>
      'चेकलिस्ट आपकी भूमिका के लिए उपलब्ध नहीं हैं। ज़रूरत हो तो ऑफ़िस से पूछें।';

  @override
  String get inspectionsRefusedTitle => 'यह आपकी भूमिका में नहीं है';

  @override
  String get inspectionsSearchNone => 'कोई चेकलिस्ट नहीं मिली';

  @override
  String get inspectionsStart => 'निरीक्षण शुरू करें';

  @override
  String get inspectionsTitle => 'निरीक्षण';

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
  String get issuesFormAssignee => 'कौन ठीक करेगा? (वैकल्पिक)';

  @override
  String get issuesFormDue => 'कब तक ठीक करना है? (वैकल्पिक)';

  @override
  String get issuesFormDueClear => 'चुने हुए दिन पर फिर से टैप करके उसे हटाएँ।';

  @override
  String get issuesFormDueThree => '3 दिन';

  @override
  String get issuesFormDueToday => 'आज';

  @override
  String get issuesFormDueWeek => '1 हफ़्ता';

  @override
  String get issuesFormEyebrow => 'नई समस्या';

  @override
  String get issuesFormLocation => 'साइट पर कहाँ? (वैकल्पिक)';

  @override
  String get issuesFormLocationHint => 'जैसे: ग्रिड C, तीसरी मंज़िल';

  @override
  String get issuesFormPhotos => 'समस्या की फ़ोटो (वैकल्पिक)';

  @override
  String get issuesFormPriority => 'कितना ज़रूरी है?';

  @override
  String get issuesFormSave => 'समस्या सेव करें';

  @override
  String get issuesFormTitle => 'समस्या दर्ज करें';

  @override
  String get issuesFormWhat => 'क्या गड़बड़ है?';

  @override
  String get issuesFormWhatHint => 'जैसे: कॉलम C3 में दरार';

  @override
  String get issuesFormWhatRequired => 'क्या गड़बड़ है, लिखें।';

  @override
  String get issuesLoading => 'समस्याएँ लोड हो रही हैं';

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
  String get issuesPhotoAddAfter => 'बाद की फ़ोटो जोड़ें';

  @override
  String get issuesPhotoAddBefore => 'पहले की फ़ोटो जोड़ें';

  @override
  String get issuesPhotoFailed => 'फ़ोटो जोड़ी नहीं जा सकी। फिर से कोशिश करें।';

  @override
  String get issuesPhotoSavedOffline =>
      'फ़ोटो फ़ोन पर सेव हुई। नेटवर्क आने पर भेजी जाएगी।';

  @override
  String get issuesPhotoSavedOnline => 'फ़ोटो सेव हुई। अभी भेज रहे हैं।';

  @override
  String get issuesPhotosAfter => 'बाद में';

  @override
  String get issuesPhotosBefore => 'पहले';

  @override
  String get issuesPhotosNone => 'अभी कोई फ़ोटो नहीं';

  @override
  String get issuesPhotosTitle => 'फ़ोटो';

  @override
  String get issuesRaise => 'समस्या दर्ज करें';

  @override
  String get issuesRefreshFailed =>
      'अपडेट नहीं हो सका। फ़ोन पर जो है वही दिखा रहे हैं।';

  @override
  String issuesResolvedOn(String date) {
    return 'हल हुआ $date';
  }

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
  String get machineCategoryBackhoe => 'JCB / बैकहो लोडर';

  @override
  String get machineCategoryCompressor => 'कंप्रेसर';

  @override
  String get machineCategoryCrane => 'क्रेन';

  @override
  String get machineCategoryDgSet => 'DG सेट';

  @override
  String get machineCategoryExcavator => 'एक्सकेवेटर';

  @override
  String get machineCategoryFormwork => 'शटरिंग / फ़ॉर्मवर्क';

  @override
  String get machineCategoryMixer => 'कंक्रीट मिक्सर';

  @override
  String get machineCategoryOther => 'अन्य';

  @override
  String get machineCategoryPump => 'कंक्रीट पंप';

  @override
  String get machineCategoryRoller => 'रोलर / कॉम्पैक्टर';

  @override
  String get machineCategoryScaffolding => 'स्कैफ़ोल्डिंग';

  @override
  String get machineCategoryTipper => 'टिपर / ट्रक';

  @override
  String get machineCategoryTools => 'औज़ार और छोटी मशीनें';

  @override
  String get machineCategoryTransitMixer => 'ट्रांज़िट मिक्सर';

  @override
  String get machineCategoryVehicle => 'गाड़ी';

  @override
  String get machineFormBreakdown => 'खराबी में गए घंटे';

  @override
  String get machineFormChoose => 'मशीन चुनें';

  @override
  String get machineFormClosing => 'अंतिम रीडिंग';

  @override
  String get machineFormClosingBelow =>
      'अंतिम रीडिंग शुरुआती रीडिंग से कम नहीं हो सकती';

  @override
  String get machineFormDate => 'तारीख';

  @override
  String get machineFormHours => 'काम के घंटे';

  @override
  String get machineFormHoursError =>
      '0 से 24 घंटे लिखें, ज़्यादा से ज़्यादा एक दशमलव';

  @override
  String get machineFormHoursHint => 'ज़्यादा से ज़्यादा एक दशमलव, जैसे 7.5';

  @override
  String get machineFormKm => 'चले किमी';

  @override
  String get machineFormKmError => '0 से 99,999 किमी लिखें';

  @override
  String machineFormLogged(String hours) {
    return 'उस दिन पहले से दर्ज: $hours घंटे। एक मशीन के दिन के घंटे 24 से ज़्यादा नहीं हो सकते।';
  }

  @override
  String get machineFormMachine => 'मशीन';

  @override
  String get machineFormMachineRequired => 'पहले मशीन चुनें';

  @override
  String get machineFormNoMachinesTitle => 'लॉग करने के लिए मशीनें नहीं';

  @override
  String get machineFormNoneMatch => 'कोई मशीन नहीं मिली';

  @override
  String get machineFormNothing =>
      'काम के घंटे या किमी, या खराबी में गए घंटे लिखें';

  @override
  String get machineFormOpening => 'शुरुआती रीडिंग';

  @override
  String get machineFormOperator => 'ऑपरेटर';

  @override
  String get machineFormReadingError => '0 से 99,999,999 तक की संख्या लिखें';

  @override
  String get machineFormReadingsHintHours =>
      'काम के घंटे खाली छोड़ें, दो रीडिंग से घंटे निकाले जाएँगे।';

  @override
  String get machineFormReadingsHintKm =>
      'चले किमी खाली छोड़ें, दो रीडिंग से किमी निकाले जाएँगे।';

  @override
  String get machineFormRemarks => 'टिप्पणी';

  @override
  String get machineFormSave => 'लॉग सेव करें';

  @override
  String get machineFormSearch => 'कोड या नाम से खोजें';

  @override
  String get machinesAdd => 'लॉग जोड़ें';

  @override
  String machinesBreakdownValue(String hours) {
    return 'खराबी $hours घंटे';
  }

  @override
  String get machinesEmptyMessage =>
      'इस प्रोजेक्ट की मशीन का दिन भर का काम यहाँ दिखेगा।';

  @override
  String get machinesEmptyTitle => 'अभी कोई लॉग नहीं';

  @override
  String machinesHoursValue(String hours) {
    return '$hours घंटे';
  }

  @override
  String machinesKmValue(String km) {
    return '$km किमी';
  }

  @override
  String get machinesLatestOnly => 'नए 50 दिखाए हैं। बाकी वेबसाइट पर हैं।';

  @override
  String get machinesLoading => 'मशीन लॉग लोड हो रहे हैं';

  @override
  String get machinesNoDataMessage =>
      'मशीनें लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद लॉग ऑफ़लाइन भी खुलेगा।';

  @override
  String get machinesNoMachinesMessage =>
      'मशीनें वेबसाइट पर जोड़ी जाती हैं। उसके बाद वे यहाँ दिखेंगी।';

  @override
  String get machinesNoMachinesTitle => 'अभी कोई मशीन नहीं';

  @override
  String machinesOperatorValue(String name) {
    return 'ऑपरेटर $name';
  }

  @override
  String get machinesRefusedMessage =>
      'मशीन लॉग आपकी भूमिका के लिए उपलब्ध नहीं है। ज़रूरत हो तो ऑफ़िस से पूछें।';

  @override
  String get machinesRefusedTitle => 'यह आपकी भूमिका में नहीं है';

  @override
  String get machinesTitle => 'मशीन लॉग';

  @override
  String get machinesUnderRepair => 'मरम्मत में';

  @override
  String machinesUnknownMachine(int id) {
    return 'मशीन नं. $id';
  }

  @override
  String get materialActualUsed => 'लगा';

  @override
  String get materialAdd => 'एंट्री करें';

  @override
  String get materialAgainstNorm => 'नॉर्म के मुकाबले';

  @override
  String get materialAllowed => 'मान्य सीमा';

  @override
  String get materialAllowedWastage => 'मान्य वेस्टेज';

  @override
  String get materialAsPerNorm => 'नॉर्म के अनुसार';

  @override
  String get materialBasisApproved => 'मंज़ूर माप';

  @override
  String get materialBasisDpr => 'दैनिक रिपोर्ट';

  @override
  String get materialBasisMeasured => 'माप पुस्तिका';

  @override
  String materialBeyondAllowed(String qty) {
    return 'मान्य सीमा से $qty ज़्यादा';
  }

  @override
  String get materialBillAmount => 'रकम';

  @override
  String get materialBillAmountInvalid =>
      '0 से ज़्यादा रकम, अधिकतम 2 दशमलव, लिखें।';

  @override
  String get materialBillAttach => 'बिल लगाएं';

  @override
  String get materialBillAttached => 'बिल लगा';

  @override
  String materialBillAttachedBy(String name) {
    return '$name ने लगाया';
  }

  @override
  String get materialBillDate => 'बिल की तारीख';

  @override
  String get materialBillFailed => 'बिल नहीं खुला। इंटरनेट जांचें।';

  @override
  String get materialBillFromGallery => 'गैलरी से';

  @override
  String get materialBillHeading => 'बिल';

  @override
  String get materialBillNeeded => 'बिल की फ़ोटो जोड़ें।';

  @override
  String get materialBillNeededOrNumber =>
      'बिल की फ़ोटो जोड़ें, या बिल नंबर लिखें।';

  @override
  String get materialBillNo => 'बिल नं.';

  @override
  String get materialBillNotPicture => 'यह बिल PDF है। इसे वेबसाइट पर खोलें।';

  @override
  String get materialBillPending => 'बिल बाकी';

  @override
  String get materialBillPhoto => 'बिल की फ़ोटो';

  @override
  String get materialBillRemovePhoto => 'फ़ोटो हटाएं';

  @override
  String get materialBillReplace => 'बिल बदलें';

  @override
  String get materialBillReplaceNote => 'नई फ़ाइल पहले वाली की जगह लेगी।';

  @override
  String get materialBillSaved => 'बिल लग गया।';

  @override
  String get materialBillSend => 'बिल सेव करें';

  @override
  String get materialBillTakePhoto => 'फ़ोटो लें';

  @override
  String get materialBillView => 'बिल देखें';

  @override
  String get materialBillWaiting => 'बिल का इंतज़ार है।';

  @override
  String materialBillsAlertNote(int days) {
    return '$days दिन बिल न आने पर अलर्ट जाता है।';
  }

  @override
  String materialBillsDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days दिन से बाकी',
      one: '1 दिन से बाकी',
      zero: 'आज आया',
    );
    return '$_temp0';
  }

  @override
  String get materialBillsLoadFailed =>
      'बिल नहीं खुले। फिर से कोशिश के लिए नीचे खींचें।';

  @override
  String get materialBillsNoneAttached => 'अभी तक कोई बिल नहीं लगा';

  @override
  String get materialBillsNoneMessage =>
      'साइट पर लिए चालान बिल लगने तक यहां दिखते हैं।';

  @override
  String get materialBillsNonePending => 'कोई बिल बाकी नहीं';

  @override
  String get materialBillsOffline => 'बिल देखने के लिए इंटरनेट से जुड़ें।';

  @override
  String get materialBillsOverdue => 'देर हो गई';

  @override
  String get materialBillsRefused => 'बिल आपकी भूमिका के लिए खुले नहीं हैं।';

  @override
  String get materialBillsTitle => 'चालानों के बिल';

  @override
  String materialBillsWaitingCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count बिल बाकी',
      one: '1 बिल बाकी',
      zero: 'कोई बिल बाकी नहीं',
    );
    return '$_temp0';
  }

  @override
  String get materialBoqForWork => 'हुए काम के लिए BOQ';

  @override
  String get materialChallanLoadFailed =>
      'यह चालान नहीं खुला। फिर से कोशिश के लिए नीचे खींचें।';

  @override
  String get materialChallanNo => 'चालान नंबर (वैकल्पिक)';

  @override
  String get materialChallanNoShort => 'चालान नं.';

  @override
  String get materialChallanOffline =>
      'यह चालान देखने के लिए इंटरनेट से जुड़ें।';

  @override
  String get materialChallanOpen => 'चालान खोलें';

  @override
  String get materialChallanPhoto => 'चालान की फ़ोटो';

  @override
  String get materialChallanRefused => 'यह चालान आपके लिए खुला नहीं है।';

  @override
  String get materialChallanTitle => 'चालान';

  @override
  String get materialChoose => 'मटेरियल चुनें';

  @override
  String get materialCount => 'स्टॉक गिनें';

  @override
  String get materialCountHint =>
      'आपकी गिनती के हिसाब से स्टॉक ठीक होता है। कम मात्रा बिना हिसाब के रूप में दिखती है।';

  @override
  String get materialDate => 'तारीख';

  @override
  String materialDifference(String qty) {
    return 'अंतर $qty';
  }

  @override
  String get materialEmptyMessage =>
      'आए और इस्तेमाल हुए मटेरियल की एंट्री यहाँ दिखेगी।';

  @override
  String get materialEmptyTitle => 'अभी जाँचने को कुछ नहीं';

  @override
  String get materialEnteredBy => 'दर्ज करने वाले';

  @override
  String get materialExtraRequired => 'अतिरिक्त काम लिखें';

  @override
  String get materialExtraWhat => 'अतिरिक्त काम क्या था?';

  @override
  String get materialFlagsTitle => 'जवाब देने वाले सवाल';

  @override
  String get materialInStock => 'स्टॉक में';

  @override
  String get materialLastCounted => 'आखिरी गिनती';

  @override
  String get materialLoading => 'मटेरियल लोड हो रहा है';

  @override
  String get materialMoreFailed => 'और नहीं आया। फिर कोशिश करें।';

  @override
  String get materialName => 'मटेरियल';

  @override
  String get materialNeedsNetwork =>
      'इसके लिए इंटरनेट चाहिए। कनेक्ट करके फिर कोशिश करें।';

  @override
  String get materialNoCountsTitle => 'अभी कोई गिनती नहीं';

  @override
  String get materialNoDataMessage =>
      'इस प्रोजेक्ट का मटेरियल लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद ऑफ़लाइन भी चलेगा।';

  @override
  String get materialNoDeliveriesTitle => 'अभी कोई चालान नहीं';

  @override
  String get materialNoPhoto => 'कोई फ़ोटो नहीं लगी।';

  @override
  String get materialNoUseTitle => 'अभी मटेरियल इस्तेमाल नहीं हुआ';

  @override
  String get materialNorm => 'नॉर्म';

  @override
  String materialNormPer(String qty, String unit, String workUnit) {
    return 'प्रति $workUnit $qty $unit';
  }

  @override
  String get materialNotAllowed =>
      'आपकी भूमिका यह नहीं कर सकती। ऑफिस से पूछें।';

  @override
  String get materialOnBoq => 'BOQ काम';

  @override
  String get materialOnChallan => 'चालान पर';

  @override
  String get materialOnExtra => 'अतिरिक्त काम';

  @override
  String get materialOnSite => 'साइट पर स्टॉक';

  @override
  String get materialOptional => 'वैकल्पिक';

  @override
  String get materialOverStock =>
      'यह यहाँ दिखे स्टॉक से ज़्यादा है। सर्वर इसे मना कर सकता है।';

  @override
  String get materialPhotoFailed => 'फ़ोटो नहीं खुली। इंटरनेट जांचें।';

  @override
  String get materialQtyCounted => 'गिनी गई मात्रा';

  @override
  String get materialQtyIssued => 'दी गई मात्रा';

  @override
  String get materialQtyOnChallan => 'चालान पर लिखी मात्रा (वैकल्पिक)';

  @override
  String get materialQtyOnChallanHint =>
      'चालान से कम आया हो तो चालान का आँकड़ा लिखें।';

  @override
  String get materialQtyReceived => 'आई मात्रा';

  @override
  String get materialQtyRequired => 'मात्रा लिखें';

  @override
  String get materialQtyUsed => 'इस्तेमाल मात्रा';

  @override
  String get materialReasonAnswered => 'जवाब दिया';

  @override
  String get materialReasonEmpty => 'पहले कारण लिखें।';

  @override
  String materialReasonFrom(String name) {
    return '$name का दिया कारण';
  }

  @override
  String get materialReasonHint =>
      'साइट पर क्या हुआ, वह लिखें। अंतर बढ़ा तो फिर से पूछा जाएगा।';

  @override
  String get materialReasonLabel => 'यह अंतर क्यों है?';

  @override
  String get materialReasonNotYours =>
      'कारण इंजीनियर, स्टोर कीपर, QS या प्रोजेक्ट मैनेजर लिखते हैं।';

  @override
  String get materialReasonSaved => 'कारण सेव हो गया।';

  @override
  String get materialReasonSend => 'कारण सेव करें';

  @override
  String get materialReasonTooLong => 'कारण 2000 अक्षरों में रखें।';

  @override
  String get materialReasonWrite => 'कारण लिखें';

  @override
  String get materialReceived => 'मटेरियल आया';

  @override
  String get materialReceivedShort => 'आया';

  @override
  String get materialRemarks => 'टिप्पणी';

  @override
  String get materialReorderHeading => 'रीऑर्डर स्तर से नीचे';

  @override
  String materialReorderLine(String balance, String level, String unit) {
    return '$level में से $balance $unit';
  }

  @override
  String materialReversedNote(String name, String date, String reason) {
    return '$name ने $date को उलटा किया: $reason';
  }

  @override
  String get materialSearch => 'मटेरियल खोजें';

  @override
  String get materialSearchNone => 'कुछ नहीं मिला';

  @override
  String get materialSendFailed => 'सेव नहीं हुआ। फिर कोशिश करें।';

  @override
  String get materialShort => 'कम आया';

  @override
  String get materialShortAsk => 'माल कम आया?';

  @override
  String get materialShortAtGate => 'गेट पर ही पता चला';

  @override
  String materialShortBy(String unit) {
    return 'कितना कम ($unit)';
  }

  @override
  String get materialShortFoundOn => 'कब पता चला';

  @override
  String get materialShortHeading => 'कम आया माल';

  @override
  String get materialShortHint =>
      'इससे यह माल स्टॉक से घटता है और सप्लायर के रिकॉर्ड में गिना जाता है।';

  @override
  String get materialShortLater => 'बाद में पता चला';

  @override
  String get materialShortNote => 'नोट';

  @override
  String get materialShortNoteTooLong => 'नोट 500 अक्षरों में रखें।';

  @override
  String get materialShortQtyInvalid => '0 से ज़्यादा, अधिकतम 3 दशमलव, लिखें।';

  @override
  String get materialShortSaved => 'कम माल सप्लायर के नाम दर्ज हुआ।';

  @override
  String get materialShortSend => 'कम माल दर्ज करें';

  @override
  String get materialShouldBe => 'स्टॉक में होना चाहिए';

  @override
  String get materialShowMore => 'और दिखाएं';

  @override
  String get materialStage => 'चरण (वैकल्पिक)';

  @override
  String get materialStageHint => 'जैसे स्लैब 3';

  @override
  String get materialStatusNoNorm => 'नॉर्म तय नहीं';

  @override
  String get materialStatusNoWork => 'माल दिया, काम नहीं';

  @override
  String get materialStatusOk => 'नॉर्म में';

  @override
  String get materialStatusUnder => 'नॉर्म से कम';

  @override
  String get materialStatusWastage => 'वेस्टेज';

  @override
  String get materialStore => 'स्टोर';

  @override
  String get materialSupplier => 'सप्लायर';

  @override
  String get materialSupplierChoose => 'सप्लायर चुनें';

  @override
  String get materialSupplierFromList => 'सूची से चुनें';

  @override
  String get materialSupplierName => 'सप्लायर का नाम';

  @override
  String get materialSupplierNew => 'नया सप्लायर';

  @override
  String get materialSupplierRequired => 'किसने दिया, लिखें';

  @override
  String get materialSupplierSearch => 'सप्लायर खोजें';

  @override
  String get materialTabCounts => 'गिनती';

  @override
  String get materialTabDeliveries => 'चालान';

  @override
  String get materialTabStock => 'स्टॉक';

  @override
  String get materialTabUsed => 'इस्तेमाल';

  @override
  String get materialTitle => 'मटेरियल';

  @override
  String materialToAnswer(int count) {
    return '$count जवाब बाकी';
  }

  @override
  String get materialUnaccounted => 'बिना हिसाब';

  @override
  String get materialUnplannedHeading => 'बिना BOQ लाइन दिया माल';

  @override
  String get materialUsed => 'मटेरियल इस्तेमाल हुआ';

  @override
  String get materialUsedShort => 'इस्तेमाल';

  @override
  String get materialVehicle => 'गाड़ी नं.';

  @override
  String get materialVehicleNo => 'गाड़ी नंबर (वैकल्पिक)';

  @override
  String get materialViewPhoto => 'चालान की फ़ोटो देखें';

  @override
  String get materialWastageBasis => 'किया काम किससे';

  @override
  String get materialWastageEmptyMessage =>
      'नॉर्म वेबसाइट पर तय होते हैं। BOQ लाइन पर माल देने पर यहां दिखेगा।';

  @override
  String get materialWastageEmptyTitle => 'अभी मिलान के लिए कुछ नहीं';

  @override
  String get materialWastageHint =>
      'मान्य सीमा = किया काम × नॉर्म, और नॉर्म का वेस्टेज %. नॉर्म वेबसाइट पर तय होते हैं।';

  @override
  String get materialWastageLoadFailed =>
      'वेस्टेज नहीं खुला। फिर से कोशिश के लिए नीचे खींचें।';

  @override
  String get materialWastageOffline =>
      'BOQ के हिसाब से वेस्टेज देखने के लिए इंटरनेट से जुड़ें।';

  @override
  String materialWastageOverCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count लाइन मान्य सीमा से ज़्यादा',
      one: '1 लाइन मान्य सीमा से ज़्यादा',
      zero: 'कोई लाइन मान्य सीमा से ज़्यादा नहीं',
    );
    return '$_temp0';
  }

  @override
  String get materialWastageRefused =>
      'BOQ के हिसाब से वेस्टेज आपकी भूमिका के लिए खुला नहीं है।';

  @override
  String get materialWastageTile => 'BOQ नॉर्म के हिसाब से वेस्टेज';

  @override
  String get materialWastageTileHint =>
      'हर BOQ लाइन पर लगा माल, नॉर्म की मान्य सीमा के मुकाबले';

  @override
  String get materialWastageTitle => 'BOQ के हिसाब से वेस्टेज';

  @override
  String get materialWorkDone => 'किया काम';

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
  String get measurementBoqProgress => 'उपयोग हुआ BOQ';

  @override
  String get measurementBoqQty => 'BOQ';

  @override
  String measurementBoqUsed(String percent) {
    return 'BOQ का $percent उपयोग हुआ';
  }

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
  String get measurementDetailsShort => 'स्थान, तारीख़';

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
  String measurementDraftsLeft(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count पंक्तियाँ मसौदे में रखीं। पुस्तिका से भेजें।',
      one: '1 पंक्ति मसौदे में रखी। पुस्तिका से भेजें।',
    );
    return '$_temp0';
  }

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
  String get measurementFixLast => 'आख़िरी सुधारें';

  @override
  String get measurementFormulaHint => 'शुरू करने के लिए नग भरें';

  @override
  String get measurementGoneBody => 'शायद यह वेब पर हटा दी गई है।';

  @override
  String get measurementGoneTitle => 'एंट्री नहीं मिली';

  @override
  String get measurementInReviewBody =>
      'मंज़ूर करने वाले के पास है। फ़ैसले तक बदला नहीं जा सकता।';

  @override
  String get measurementItem => 'आइटम';

  @override
  String get measurementLeaveBody => 'इस पंक्ति के भरे आँकड़े मिट जाएँगे।';

  @override
  String get measurementLeaveGo => 'बाहर जाएँ';

  @override
  String get measurementLeaveKeep => 'भरते रहें';

  @override
  String get measurementLeaveTitle => 'बिना सहेजे बाहर जाएँ?';

  @override
  String get measurementLength => 'लंबाई';

  @override
  String get measurementLengthShort => 'लंबाई';

  @override
  String get measurementLoading => 'लोड हो रहा है';

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
  String get measurementPhotoAdd => 'फ़ोटो जोड़ें';

  @override
  String get measurementPhotoFailed =>
      'फ़ोटो जोड़ी नहीं जा सकी। फिर से कोशिश करें।';

  @override
  String get measurementPhotoSaved => 'फ़ोटो सेव हुई। अभी भेज रहे हैं।';

  @override
  String get measurementPhotoSavedOffline =>
      'फ़ोटो फ़ोन पर सेव हुई। नेटवर्क आने पर भेजी जाएगी।';

  @override
  String get measurementPhotosLocked =>
      'एंट्री मंज़ूरी के लिए जाने के बाद फ़ोटो नहीं जोड़ी जा सकतीं।';

  @override
  String get measurementPhotosNone => 'अभी कोई फ़ोटो नहीं';

  @override
  String get measurementPhotosTitle => 'फ़ोटो';

  @override
  String get measurementPreviewNote =>
      'इस फ़ोन के आँकड़े अनुमानित हैं। ऑफ़िस के आँकड़े ही अंतिम हैं।';

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
  String get measurementRejectedBody =>
      'यह एंट्री अस्वीकृत है, गिनी नहीं जाएगी। फिर मापना हो तो नई एंट्री करें।';

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
    return '$count मंज़ूरी के लिए भेजें';
  }

  @override
  String get measurementSendForApproval => 'मंज़ूरी के लिए भेजें';

  @override
  String get measurementSendsWhenOnline =>
      'अभी नहीं गया। नेटवर्क आते ही मंज़ूरी के लिए जाएगा।';

  @override
  String get measurementShareOfBoq => 'BOQ में हिस्सा';

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
  String get pettyCashAddMoney => 'पैसे जोड़ें';

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
  String get pettyCashChooseProject => 'प्रोजेक्ट चुनें';

  @override
  String get pettyCashConfirmed => 'पक्का शेष';

  @override
  String get pettyCashCreateWallet => 'वॉलेट बनाएँ';

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
  String get pettyCashFinalAfterSync => 'सिंक होने पर अंतिम शेष पता चलेगा।';

  @override
  String get pettyCashFormEyebrow => 'नया ख़र्च';

  @override
  String get pettyCashFormTitle => 'ख़र्च जोड़ें';

  @override
  String get pettyCashLoading => 'नकद बही लोड हो रही है';

  @override
  String get pettyCashNoProjectMessage =>
      'पहले प्रोजेक्ट चुनें, फिर यहाँ ख़र्च दर्ज करें।';

  @override
  String get pettyCashNoProjectTitle => 'कोई प्रोजेक्ट नहीं चुना';

  @override
  String get pettyCashNoWalletManageMessage =>
      'साइट के ख़र्च यहाँ दर्ज करने के लिए वॉलेट बनाएँ।';

  @override
  String get pettyCashNoWalletManageTitle =>
      'इस प्रोजेक्ट पर अभी वॉलेट नहीं है';

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
  String get pettyCashOpeningAmount => 'शुरुआती रकम';

  @override
  String get pettyCashOpeningHelper => 'अभी पैसे न हों तो खाली छोड़ें।';

  @override
  String get pettyCashOpeningNote => 'शुरुआती शेष';

  @override
  String pettyCashOverBalance(String balance) {
    return 'यह रकम वॉलेट के $balance से ज़्यादा है। ऑफ़िस से राशि जुड़वाएँ।';
  }

  @override
  String get pettyCashPhotoBill => 'बिल की फ़ोटो';

  @override
  String get pettyCashProvisional => 'अनंतिम';

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
  String get pettyCashTapToChange => 'बदलने के लिए टैप करें';

  @override
  String get pettyCashTitle => 'फुटकर नकद';

  @override
  String get pettyCashTopUpEyebrow => 'वॉलेट में रकम';

  @override
  String get pettyCashTopUpFailed => 'रकम सेव नहीं हुई। फिर से कोशिश करें।';

  @override
  String get pettyCashTopUpNoteHint => 'ऑफ़िस से नकद';

  @override
  String get pettyCashTopUpSave => 'मिली रकम सेव करें';

  @override
  String get pettyCashTopup => 'राशि जोड़ें';

  @override
  String get pettyCashUnsent => 'अभी भेजे नहीं';

  @override
  String get pettyCashWallet => 'वॉलेट';

  @override
  String get pettyCashWalletCreateKey => 'बनाएँ';

  @override
  String get pettyCashWalletCreated => 'वॉलेट बन गया';

  @override
  String pettyCashWalletCreatedMoney(String amount) {
    return 'वॉलेट बन गया। $amount फ़ोन पर सेव हुए, अभी भेज रहे हैं।';
  }

  @override
  String pettyCashWalletCreatedOffline(String amount) {
    return 'वॉलेट बन गया। $amount फ़ोन पर सेव हुए, नेटवर्क आने पर भेजे जाएँगे।';
  }

  @override
  String pettyCashWalletDefaultName(String code) {
    return '$code साइट नकद';
  }

  @override
  String get pettyCashWalletFailed => 'वॉलेट नहीं बना। फिर से कोशिश करें।';

  @override
  String get pettyCashWalletFormEyebrow => 'नया वॉलेट';

  @override
  String get pettyCashWalletHolder => 'नकद किसके पास रहेगा?';

  @override
  String get pettyCashWalletMoneyFailed =>
      'वॉलेट बन गया, पर रकम सेव नहीं हुई। फिर से जोड़ें।';

  @override
  String get pettyCashWalletName => 'वॉलेट का नाम';

  @override
  String get pettyCashWalletNameRequired => 'वॉलेट का नाम लिखें।';

  @override
  String get pettyCashWalletOffline => 'नेटवर्क नहीं है।';

  @override
  String get pettyCashWalletOfflineDetail => 'वॉलेट सिर्फ़ ऑनलाइन बनता है।';

  @override
  String get photosClose => 'बंद करें';

  @override
  String get photosDocument => 'PDF, वेबसाइट पर';

  @override
  String get photosNeedNetwork => 'नेटवर्क चाहिए';

  @override
  String get photosNotSent => 'भेजा नहीं गया';

  @override
  String get photosOpen => 'फ़ोटो खोलें';

  @override
  String get photosWaiting => 'भेजना बाकी';

  @override
  String get portalBillDeductions => 'कटौतियाँ';

  @override
  String get portalBillFinal => 'अंतिम बिल';

  @override
  String get portalBillGross => 'कुल राशि';

  @override
  String get portalBillGst => 'GST';

  @override
  String portalBillLineQty(String qty, String unit, String rate) {
    return '$qty $unit × $rate';
  }

  @override
  String portalBillLineSoFar(String qty, String unit) {
    return 'अब तक $qty $unit';
  }

  @override
  String get portalBillLines => 'बिल किया गया काम';

  @override
  String get portalBillLinesOnline =>
      'इस बिल की मदें एक बार ऑनलाइन खुलने पर लोड होंगी।';

  @override
  String get portalBillNet => 'देय राशि';

  @override
  String get portalBillPeriod => 'अवधि';

  @override
  String portalBillPeriodRange(String from, String to) {
    return '$from से $to';
  }

  @override
  String get portalBillRetentionReleased => 'लौटाया गया रिटेंशन';

  @override
  String portalBillsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count बिल',
      one: '1 बिल',
    );
    return '$_temp0';
  }

  @override
  String get portalBillsTitle => 'बिल';

  @override
  String portalDaysLeft(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count दिन बाकी',
      one: '1 दिन बाकी',
    );
    return '$_temp0';
  }

  @override
  String get portalDeadlineLabel => 'अंतिम तारीख़';

  @override
  String get portalDueToday => 'आज आख़िरी दिन';

  @override
  String portalLineDone(String done, String total, String unit) {
    return '$total $unit में से $done पूरा';
  }

  @override
  String portalLineRate(String rate, String unit) {
    return 'प्रति $unit $rate';
  }

  @override
  String portalLineWorth(String done, String total) {
    return '$total में से $done का काम';
  }

  @override
  String get portalLoading => 'आपके ठेके लोड हो रहे हैं';

  @override
  String get portalNoBillsMessage =>
      'कंपनी ने जो बिल मंज़ूर किए, वे यहाँ दिखेंगे।';

  @override
  String get portalNoBillsTitle => 'अभी कोई बिल नहीं';

  @override
  String get portalNoContractsMessage => 'आपको दिए गए वर्क ऑर्डर यहाँ दिखेंगे।';

  @override
  String get portalNoDataMessage =>
      'अपने ठेके लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद ऑफ़लाइन भी खुलेंगे।';

  @override
  String get portalNoPaymentsMessage =>
      'कंपनी ने जो भुगतान मंज़ूर किए, वे यहाँ दिखेंगे।';

  @override
  String get portalNoPaymentsTitle => 'अभी कोई भुगतान नहीं';

  @override
  String get portalNotFoundMessage => 'इसे लोड करने के लिए इंटरनेट से जुड़ें।';

  @override
  String get portalNotFoundTitle => 'यह अभी इस फ़ोन पर नहीं है';

  @override
  String portalOverdueBy(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count दिन की देरी',
      one: '1 दिन की देरी',
    );
    return '$_temp0';
  }

  @override
  String portalPaidVsWork(String paid, String work) {
    return 'भुगतान $paid · किया गया काम $work';
  }

  @override
  String portalPaymentsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count भुगतान',
      one: '1 भुगतान',
    );
    return '$_temp0';
  }

  @override
  String get portalPaymentsTitle => 'भुगतान';

  @override
  String get portalRefusedMessage =>
      'यह लॉगिन अभी ठेकेदार पोर्टल नहीं खोल सकता। यह लॉगिन देने वाली कंपनी से पूछें।';

  @override
  String get portalRefusedTitle => 'पोर्टल बंद है';

  @override
  String portalSavedAgo(String ago) {
    return 'अपडेट: $ago';
  }

  @override
  String get portalTitle => 'ठेकेदार पोर्टल';

  @override
  String get portalWorkComplete => 'काम पूरा';

  @override
  String get portalYourContracts => 'आपके सभी ठेके';

  @override
  String priceWatchAbove(String diff, String pct) {
    return 'BOQ दर से $diff ज़्यादा ($pct)';
  }

  @override
  String get priceWatchBasisNorms => 'BOQ मानकों के अनुसार';

  @override
  String get priceWatchBasisTyped => 'इस प्रोजेक्ट के लिए लिखी गई मात्रा';

  @override
  String priceWatchBelow(String diff, String pct) {
    return 'BOQ दर से $diff कम ($pct)';
  }

  @override
  String get priceWatchBeyond => 'सीमा से ऊपर';

  @override
  String get priceWatchBoqRate => 'BOQ दर';

  @override
  String get priceWatchBoqValue => 'BOQ राशि';

  @override
  String get priceWatchBudget => 'बजट';

  @override
  String get priceWatchBudgetNoData =>
      'इस प्रोजेक्ट के बजट पर असर को लोड करने के लिए एक बार इंटरनेट से जुड़ें।';

  @override
  String get priceWatchBudgetNote =>
      'अगर हर सामग्री का बचा हुआ हिस्सा BOQ दर की जगह उसके आख़िरी दर्ज दर पर ख़रीदा जाए।';

  @override
  String get priceWatchBudgetRefused =>
      'बजट पर असर आपकी भूमिका के लिए उपलब्ध नहीं है।';

  @override
  String get priceWatchBudgetTitle => 'बजट पर असर';

  @override
  String priceWatchChecked(String ago) {
    return 'जाँचा गया: $ago';
  }

  @override
  String priceWatchDown(String pct, String rate, String date) {
    return '$date को $rate से $pct नीचे';
  }

  @override
  String get priceWatchEmptyMessage =>
      'आपकी कंपनी जिन सामग्रियों पर नज़र रखती है, वे दर्ज दरों के साथ यहाँ दिखेंगी।';

  @override
  String get priceWatchEmptyTitle => 'बोर्ड पर कोई सामग्री नहीं';

  @override
  String get priceWatchFresh => 'जो दर पुराने नहीं हुए, उनसे';

  @override
  String get priceWatchGapNoBoqRate => 'इस प्रोजेक्ट के लिए BOQ दर तय नहीं';

  @override
  String get priceWatchGapNoQty => 'हिसाब के लिए मात्रा नहीं';

  @override
  String get priceWatchGapUnit => 'दर की इकाई BOQ की इकाई से मेल नहीं खाती';

  @override
  String get priceWatchHistory => 'सभी दर्ज दरें';

  @override
  String get priceWatchLatest => 'सबसे नया';

  @override
  String priceWatchLess(String amount) {
    return '$amount कम';
  }

  @override
  String get priceWatchLevel => 'BOQ दर के बराबर';

  @override
  String get priceWatchLoading => 'दर्ज दरें लोड हो रही हैं';

  @override
  String get priceWatchMaterialMissing => 'यह सामग्री बोर्ड पर नहीं है।';

  @override
  String priceWatchMore(String amount) {
    return '$amount ज़्यादा';
  }

  @override
  String get priceWatchNoDataMessage =>
      'दर्ज दरें लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद वे ऑफ़लाइन भी खुलेंगी।';

  @override
  String get priceWatchNoEffect => 'कोई असर नहीं';

  @override
  String get priceWatchNone => 'अभी कोई दर दर्ज नहीं';

  @override
  String get priceWatchNote =>
      'आपकी कंपनी के लिखे हुए दर, हर एक के साथ उसे दर्ज करने की तारीख़। यहाँ कुछ भी अपने आप नहीं बदलता।';

  @override
  String priceWatchNotedBy(String name) {
    return 'दर्ज करने वाले: $name';
  }

  @override
  String get priceWatchOnlyOne => 'सिर्फ़ एक दर दर्ज है';

  @override
  String priceWatchPerUnit(String unit) {
    return 'प्रति $unit';
  }

  @override
  String get priceWatchProjected => 'इन दरों पर BOQ';

  @override
  String priceWatchRecordedOn(String date) {
    return '$date को दर्ज';
  }

  @override
  String get priceWatchRecordedRate => 'आख़िरी दर्ज';

  @override
  String get priceWatchRefusedMessage =>
      'दर्ज दरें आपकी भूमिका के लिए उपलब्ध नहीं हैं। ज़रूरत हो तो ऑफ़िस से पूछें।';

  @override
  String get priceWatchRefusedTitle => 'आपकी भूमिका के लिए उपलब्ध नहीं';

  @override
  String priceWatchSame(String rate, String date) {
    return '$date को $rate के बराबर';
  }

  @override
  String get priceWatchShareBoq => 'BOQ राशि का हिस्सा';

  @override
  String get priceWatchShareBudget => 'बजट का हिस्सा';

  @override
  String priceWatchStale(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days दिन से पुराना',
      one: '1 दिन से पुराना',
    );
    return '$_temp0';
  }

  @override
  String priceWatchStillToBuy(String qty, String unit) {
    return 'अभी ख़रीदना बाक़ी $qty $unit';
  }

  @override
  String get priceWatchTabBudget => 'बजट पर असर';

  @override
  String get priceWatchTabPrices => 'दरें';

  @override
  String get priceWatchTile => 'दरें';

  @override
  String get priceWatchTitle => 'दर्ज दरें';

  @override
  String priceWatchUp(String pct, String rate, String date) {
    return '$date को $rate से $pct ऊपर';
  }

  @override
  String get priceWatchWithin => 'सीमा के भीतर';

  @override
  String get procurementAccepted => 'आया और स्वीकार किया';

  @override
  String get procurementActivity => 'या काम';

  @override
  String get procurementActivityHint => 'जैसे स्लैब 3 शटरिंग';

  @override
  String get procurementAddItem => 'एक और आइटम जोड़ें';

  @override
  String get procurementForBoq => 'BOQ आइटम';

  @override
  String procurementInStore(String qty) {
    return 'इस स्टोर में $qty';
  }

  @override
  String get procurementIssueStock => 'स्टोर से मटेरियल दें';

  @override
  String get procurementIssuedTo => 'किसे दिया (वैकल्पिक)';

  @override
  String get procurementIssuedToHint => 'व्यक्ति या गैंग';

  @override
  String procurementItemN(int number) {
    return 'आइटम $number';
  }

  @override
  String get procurementNoOrdersMessage =>
      'ऑफ़िस के ऑर्डर सप्लायर को भेजते ही वह यहाँ दिखेगा और उस पर माल लिया जा सकेगा।';

  @override
  String get procurementNoOrdersTitle => 'कोई खुला खरीद ऑर्डर नहीं';

  @override
  String get procurementNoReceiptsTitle => 'अभी कोई प्राप्ति नहीं';

  @override
  String get procurementNoStoreMessage =>
      'ऑफ़िस से वेबसाइट पर स्टोर जोड़ने को कहें, फिर से कोशिश करें।';

  @override
  String get procurementNoStoreTitle => 'इस प्रोजेक्ट में कोई स्टोर नहीं';

  @override
  String get procurementNothingReceived =>
      'कम से कम एक आइटम पर स्वीकार की गई मात्रा लिखें';

  @override
  String get procurementOpenOrders => 'खुले ऑर्डर';

  @override
  String get procurementOrder => 'खरीद ऑर्डर';

  @override
  String get procurementOrderChoose => 'खरीद ऑर्डर चुनें';

  @override
  String procurementOrderDue(int count) {
    return '$count आइटम आना बाकी';
  }

  @override
  String get procurementOrderRequired => 'खरीद ऑर्डर चुनें';

  @override
  String get procurementOrderSearch => 'नंबर या सप्लायर से खोजें';

  @override
  String procurementOrdered(String qty) {
    return 'ऑर्डर किया $qty';
  }

  @override
  String procurementOverDue(String qty) {
    return 'इस ऑर्डर पर सिर्फ़ $qty आना बाकी है';
  }

  @override
  String get procurementPartialNote =>
      'ऑर्डर का कुछ हिस्सा आए तो भी चलेगा। जिस आइटम का माल नहीं आया उसे खाली छोड़ें; बाकी के लिए ऑर्डर खुला रहेगा।';

  @override
  String get procurementQtyInvalid => 'संख्या लिखें';

  @override
  String get procurementReceiveOrder => 'ऑर्डर के अनुसार माल लें';

  @override
  String get procurementReceivedHeading => 'प्राप्त हुआ';

  @override
  String get procurementRejected => 'लौटाया गया (वैकल्पिक)';

  @override
  String procurementRejectedFigure(String qty) {
    return 'लौटाया $qty';
  }

  @override
  String get procurementRejectedHint =>
      'जो माल आपने स्वीकार नहीं किया। वह स्टॉक में नहीं जुड़ता और सप्लायर से आना बाकी रहता है।';

  @override
  String get procurementRejectedOnly =>
      'लौटाए गए माल के साथ उसी आइटम पर कुछ स्वीकार की गई मात्रा भी चाहिए। पूरा माल लौटाया हो तो वेबसाइट इस्तेमाल करें।';

  @override
  String get procurementRemarks => 'टिप्पणी (वैकल्पिक)';

  @override
  String get procurementRemarksHint => 'जैसे माल क्यों लौटाया';

  @override
  String get procurementRemoveItem => 'हटाएँ';

  @override
  String get procurementReversed => 'उलटा किया गया';

  @override
  String procurementStillDue(String qty) {
    return 'आना बाकी $qty';
  }

  @override
  String get procurementTabReceipts => 'ऑर्डर की प्राप्ति';

  @override
  String get procurementTooManyDecimals =>
      'ज़्यादा से ज़्यादा 3 दशमलव स्थान लिखें';

  @override
  String get procurementWorkRequired => 'BOQ आइटम चुनें या काम लिखें';

  @override
  String get progressAdd => 'आज का काम जोड़ें';

  @override
  String get progressBeforeFinishing => 'फ़िनिशिंग से पहले की जाँच';

  @override
  String progressBehind(String percent) {
    return '$percent पीछे';
  }

  @override
  String get progressCorrect => 'सुधारें';

  @override
  String get progressCorrectFailed => 'सुधार सेव नहीं हुआ। फिर से कोशिश करें।';

  @override
  String get progressCorrectHint =>
      'पहली प्रविष्टि वैसी ही रहती है। उसके साथ सुधार जुड़ जाता है।';

  @override
  String progressCorrectNow(String qty) {
    return 'अभी गिना गया: $qty';
  }

  @override
  String get progressCorrectQty => 'यह कितना होना चाहिए था';

  @override
  String get progressCorrectQtyInvalid => 'शून्य या उससे ज़्यादा मात्रा लिखें';

  @override
  String get progressCorrectRemark => 'सुधार क्यों किया जा रहा है?';

  @override
  String get progressCorrectRemarkRequired => 'सुधार का कारण लिखें';

  @override
  String get progressCorrectSame => 'यही मात्रा पहले से है';

  @override
  String get progressCorrectSave => 'सुधार सेव करें';

  @override
  String get progressCorrectSavedOffline =>
      'सुधार इस फ़ोन पर सेव हुआ। इंटरनेट आने पर भेजा जाएगा।';

  @override
  String get progressCorrectSavedOnline => 'सुधार सेव हुआ। भेज रहे हैं।';

  @override
  String get progressCorrectTakenBack => 'सुधार वापस ले लिया।';

  @override
  String get progressCorrectTitle => 'यह प्रविष्टि सुधारें';

  @override
  String get progressCorrectionNotSent => 'सुधार अभी नहीं गया';

  @override
  String progressEntryBy(String name) {
    return '$name द्वारा';
  }

  @override
  String progressEntryCorrectedFrom(String qty) {
    return '$qty से सुधारा गया';
  }

  @override
  String get progressFinishingHint =>
      'प्लास्टर, POP या पेंट शुरू करने से पहले यह पढ़ें।';

  @override
  String get progressFinishingPending => 'बाकी काम';

  @override
  String get progressFinishingUnfinished => 'अभी पूरा नहीं';

  @override
  String get progressFloorBalance => 'बाकी';

  @override
  String get progressFloorDone => 'पूरा';

  @override
  String get progressFloorFlats => 'फ़्लैट';

  @override
  String get progressFloorLines => 'इस मंज़िल का काम';

  @override
  String get progressFloorNoLines => 'इस मंज़िल के लिए अभी मात्रा तय नहीं है।';

  @override
  String get progressFloorOffline =>
      'यह मंज़िल देखने के लिए एक बार इंटरनेट से जुड़ें।';

  @override
  String get progressFloorPendingHeading => 'यहाँ बाकी';

  @override
  String get progressFloorPlanned => 'योजना';

  @override
  String get progressFloorStages => 'प्लंबिंग और इलेक्ट्रिकल चरण';

  @override
  String get progressFloorTitle => 'मंज़िल की प्रगति';

  @override
  String progressFormBalance(String qty) {
    return 'बाकी $qty';
  }

  @override
  String get progressFormDate => 'तारीख';

  @override
  String get progressFormFloor => 'मंज़िल या फ़्लैट';

  @override
  String get progressFormFloorNone => 'पूरा प्रोजेक्ट';

  @override
  String get progressFormItem => 'BOQ आइटम';

  @override
  String get progressFormItemChoose => 'आइटम चुनें';

  @override
  String get progressFormItemNone => 'कोई आइटम नहीं मिला';

  @override
  String get progressFormItemSearch => 'कोड या नाम से खोजें';

  @override
  String get progressFormKindPending => 'सिर्फ़ बाकी';

  @override
  String get progressFormKindWork => 'काम हुआ';

  @override
  String get progressFormOverBalance =>
      'यह BOQ के बाकी से ज़्यादा है। फिर भी सेव कर सकते हैं।';

  @override
  String get progressFormPendingRemark => 'क्या बाकी है?';

  @override
  String get progressFormPhoto => 'फ़ोटो जोड़ें';

  @override
  String get progressFormQty => 'हुई मात्रा';

  @override
  String get progressFormQtyRequired => 'हुई मात्रा लिखें';

  @override
  String get progressFormRemark => 'टिप्पणी (वैकल्पिक)';

  @override
  String get progressFormRemarkRequired => 'क्या बाकी है लिखें';

  @override
  String get progressFormRemovePhoto => 'फ़ोटो हटाएँ';

  @override
  String get progressFormSave => 'काम सेव करें';

  @override
  String get progressFormTitle => 'काम जोड़ें';

  @override
  String get progressFormToday => 'आज';

  @override
  String get progressFormWhose => 'यह काम किसका है?';

  @override
  String get progressFormWhoseRequired => 'ठेकेदार चुनें';

  @override
  String get progressFormYesterday => 'कल';

  @override
  String get progressLoading => 'प्रगति लोड हो रही है';

  @override
  String get progressNoDataMessage =>
      'इस प्रोजेक्ट की प्रगति लोड करने के लिए एक बार इंटरनेट से जुड़ें। उसके बाद ऑफ़लाइन भी चलेगा।';

  @override
  String get progressNoDataTitle => 'अभी लोड नहीं हुआ';

  @override
  String get progressNoFloorsMessage =>
      'प्रोजेक्ट मैनेजर से मंज़िलें और फ़्लैट जोड़ने को कहें।';

  @override
  String get progressNoFloorsTitle => 'अभी मंज़िलें नहीं जोड़ी गईं';

  @override
  String get progressNoPendingMessage =>
      'साइट पर बाकी लिखा गया काम यहाँ दिखेगा।';

  @override
  String get progressNoPendingTitle => 'कुछ बाकी नहीं';

  @override
  String get progressNoProjectMessage => 'पहले प्रोजेक्ट चुनें।';

  @override
  String get progressNoProjectTitle => 'प्रोजेक्ट नहीं चुना गया';

  @override
  String get progressNotSent => 'अभी भेजा नहीं';

  @override
  String get progressOnTrack => 'समय पर';

  @override
  String progressPendingCount(int count) {
    return '$count बाकी';
  }

  @override
  String get progressReadyMessage =>
      'यहाँ के सभी प्लंबिंग और इलेक्ट्रिकल चरण पूरे हैं।';

  @override
  String get progressReadyTitle => 'फ़िनिशिंग के लिए तैयार';

  @override
  String get progressRecentEntries => 'इस मंज़िल की हाल की प्रविष्टियाँ';

  @override
  String get progressRefreshFailed =>
      'ताज़ा जानकारी नहीं मिली। फ़ोन पर जो है वही दिखा रहे हैं।';

  @override
  String get progressResolveRemark => 'टिप्पणी (वैकल्पिक)';

  @override
  String get progressResolveSave => 'पूरा करें';

  @override
  String get progressResolveTitle => 'पूरा हुआ लिखें';

  @override
  String get progressResolveWait =>
      'यह एंट्री अभी ऑफ़िस नहीं पहुँची। भेजे जाने के बाद फिर कोशिश करें।';

  @override
  String get progressResolved => 'पूरा लिख दिया';

  @override
  String get progressSaveFailed => 'सेव नहीं हुआ। फिर से कोशिश करें।';

  @override
  String get progressSavedOffline =>
      'इस फ़ोन पर सेव हो गया। इंटरनेट आने पर भेज दिया जाएगा।';

  @override
  String get progressSavedOnline => 'सेव हो गया। भेज रहे हैं।';

  @override
  String get progressStageEarlierPending => 'पिछला चरण अभी बाकी है';

  @override
  String get progressStageNote => 'टिप्पणी (वैकल्पिक)';

  @override
  String get progressStageSave => 'सेव करें';

  @override
  String get progressStageSaved => 'चरण सेव हो गया';

  @override
  String get progressStageStatus => 'स्थिति';

  @override
  String progressStagesDone(int done, int total) {
    return '$total में से $done पूरे';
  }

  @override
  String get progressTabFloors => 'मंज़िलें';

  @override
  String get progressTabPending => 'बाकी';

  @override
  String get progressTabStages => 'चरण';

  @override
  String progressTimeUsed(String percent) {
    return 'बीता समय $percent';
  }

  @override
  String get progressTitle => 'साइट प्रगति';

  @override
  String get progressWeekDone => 'इस हफ़्ते हुआ';

  @override
  String progressWeekEntries(int count) {
    return '$count एंट्री';
  }

  @override
  String get progressWeekFailed => 'हफ़्ता लोड नहीं हुआ।';

  @override
  String progressWeekLeft(String left, String unit, String percent) {
    return '$left $unit बाकी · $percent हुआ';
  }

  @override
  String progressWeekMore(int count) {
    return 'और $count';
  }

  @override
  String get progressWeekNext => 'अगला हफ़्ता';

  @override
  String progressWeekNotStarted(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count BOQ आइटम शुरू नहीं हुए',
      one: '1 BOQ आइटम शुरू नहीं हुआ',
    );
    return '$_temp0';
  }

  @override
  String get progressWeekNothingDone => 'इस हफ़्ते काम दर्ज नहीं हुआ।';

  @override
  String get progressWeekOffline =>
      'इंटरनेट नहीं है। हफ़्ते का हाल देखने के लिए इंटरनेट चाहिए।';

  @override
  String get progressWeekOpen => 'यह हफ़्ता';

  @override
  String get progressWeekPending => 'बाकी';

  @override
  String get progressWeekPrevious => 'पिछला हफ़्ता';

  @override
  String progressWeekQty(String qty, String unit) {
    return 'इस हफ़्ते $qty $unit';
  }

  @override
  String progressWeekRange(String start, String end) {
    return '$start से $end';
  }

  @override
  String get progressWeekTitle => 'हफ़्ता';

  @override
  String get progressWeekUnderWay => 'चल रहा काम';

  @override
  String get progressWorkDone => 'हुआ काम';

  @override
  String get projectEditLoading => 'परियोजना खुल रही है…';

  @override
  String get projectEditOffline =>
      'परियोजना सिर्फ़ नेटवर्क होने पर बदलती है। सिग्नल आने पर फिर कोशिश करें।';

  @override
  String get projectEditRowSubtitle =>
      'नाम, स्थान, तारीखें और चरण के अनुसार राशि';

  @override
  String get projectEditSave => 'बदलाव सहेजें';

  @override
  String get projectEditSaving => 'सहेजा जा रहा है…';

  @override
  String get projectEditTitle => 'परियोजना बदलें';

  @override
  String get projectPhaseAdd => 'चरण जोड़ें';

  @override
  String get projectPhaseAmount => 'राशि';

  @override
  String get projectPhaseAmountRequired => 'राशि भरें';

  @override
  String get projectPhaseName => 'चरण का नाम';

  @override
  String get projectPhaseNameHint => 'जैसे नींव';

  @override
  String get projectPhaseNameRequired => 'इस चरण का नाम लिखें';

  @override
  String projectPhaseNumbered(int number) {
    return 'चरण $number';
  }

  @override
  String projectPhaseRemove(String phase) {
    return '$phase हटाएँ';
  }

  @override
  String projectPhaseShare(String share) {
    return 'कुल का $share';
  }

  @override
  String get projectPhaseTotal => 'कुल';

  @override
  String get projectPhasesHint =>
      'राशि चरण के अनुसार भरें। हर चरण का हिस्सा अपने आप निकलता है।';

  @override
  String get pushPrefsAlerts => 'साइट अलर्ट';

  @override
  String get pushPrefsAlertsHint =>
      'आपके प्रोजेक्ट पर नए अलर्ट, जैसे काम पिछड़ना। ज़रूरी अलर्ट किसी भी समय आते हैं।';

  @override
  String get pushPrefsAnnouncements => 'घोषणाएँ और रिमाइंडर';

  @override
  String get pushPrefsAnnouncementsHint =>
      'आपकी कंपनी या Nirmaan360 की ख़बरें और रिमाइंडर। ये शांत समय ख़त्म होने तक रुकते हैं।';

  @override
  String get pushPrefsApprovals => 'मंज़ूरी';

  @override
  String get pushPrefsApprovalsHint =>
      'कुछ आपकी मंज़ूरी के लिए रुका है, या आपके भेजे पर फ़ैसला हुआ।';

  @override
  String get pushPrefsContractor => 'मेरे भुगतान और बिल';

  @override
  String get pushPrefsContractorHint =>
      'आपको होने वाला भुगतान मंज़ूर हुआ, या आपका बिल चुकाया गया।';

  @override
  String get pushPrefsEmail => 'ईमेल';

  @override
  String get pushPrefsEmailHint => 'यही जानकारी ईमेल पर।';

  @override
  String get pushPrefsFailedMessage => 'थोड़ी देर में फिर से कोशिश करें।';

  @override
  String get pushPrefsFailedTitle => 'सेटिंग पढ़ी नहीं जा सकी';

  @override
  String get pushPrefsIssues => 'मुद्दे';

  @override
  String get pushPrefsIssuesHint =>
      'कोई मुद्दा आपको सौंपा गया, ज़रूरी बताया गया, या उसकी स्थिति बदली।';

  @override
  String get pushPrefsLoading => 'आपकी सूचना सेटिंग पढ़ रहे हैं';

  @override
  String get pushPrefsMaterial => 'सामान की जाँच';

  @override
  String get pushPrefsMaterialHint =>
      'सामान के अंतर या कम आई डिलीवरी के लिए लिखित कारण चाहिए।';

  @override
  String get pushPrefsNotConnected =>
      'ऑफ़िस ने अभी सूचनाएँ नहीं जोड़ी हैं। तब तक आपकी पसंद सँभालकर रखी जाएगी।';

  @override
  String get pushPrefsOfflineMessage =>
      'आपकी सूचना सेटिंग सर्वर पर रहती है। इंटरनेट जोड़ें और फिर से कोशिश करें।';

  @override
  String get pushPrefsOfflineTitle => 'इंटरनेट चाहिए';

  @override
  String get pushPrefsOtherSection => 'आप तक पहुँचने के दूसरे तरीके';

  @override
  String get pushPrefsPayments => 'ठेकेदार भुगतान';

  @override
  String get pushPrefsPaymentsHint =>
      'ठेकेदार का भुगतान आपकी मंज़ूरी के लिए है, या आपके माँगे भुगतान पर फ़ैसला हुआ।';

  @override
  String get pushPrefsPush => 'पुश सूचनाएँ';

  @override
  String get pushPrefsPushHint =>
      'आपके सभी फ़ोन के लिए। बंद करने पर नीचे के सभी प्रकार रुक जाते हैं।';

  @override
  String get pushPrefsQuiet => 'रात में सूचनाएँ रोकें';

  @override
  String get pushPrefsQuietFrom => 'से';

  @override
  String get pushPrefsQuietHint =>
      'इन घंटों में सूचनाएँ सुबह तक रुकती हैं। साइट के ज़रूरी अलर्ट फिर भी तुरंत आते हैं।';

  @override
  String get pushPrefsQuietSection => 'शांत समय';

  @override
  String get pushPrefsQuietUntil => 'तक';

  @override
  String get pushPrefsRetry => 'फिर से कोशिश करें';

  @override
  String get pushPrefsSaveFailed =>
      'सहेजा नहीं गया। इंटरनेट देखें और फिर से कोशिश करें।';

  @override
  String get pushPrefsSection => 'आपके फ़ोन पर';

  @override
  String get pushPrefsTitle => 'सूचनाएँ';

  @override
  String get pushPrefsWhatsapp => 'व्हाट्सऐप';

  @override
  String get pushPrefsWhatsappHint => 'यही जानकारी व्हाट्सऐप पर।';

  @override
  String get pushPromptBody =>
      'जब कोई चीज़ आपकी मंज़ूरी के लिए रुकी हो, कोई रिपोर्ट या माप आपके पास वापस आए, या साइट का नया अलर्ट खुले, तब हम बताएँगे। इसे आप कभी भी सेटिंग्स में बदल सकते हैं।';

  @override
  String get pushPromptBodyContractor =>
      'आपको होने वाला भुगतान मंज़ूर होने पर या आपका बिल चुकाए जाने पर हम बताएँगे। सेटिंग में इसे कभी भी बदल सकते हैं।';

  @override
  String get pushPromptNotNow => 'अभी नहीं';

  @override
  String get pushPromptTitle => 'इस फ़ोन पर अलर्ट चाहिए?';

  @override
  String get pushPromptTurnOn => 'अलर्ट चालू करें';

  @override
  String get pushSettingsBlocked =>
      'फ़ोन की सेटिंग्स खोलें, Nirmaan360 के लिए नोटिफ़िकेशन की अनुमति दें, फिर वापस आएँ। दोबारा जाँचने के लिए चालू करें दबाएँ।';

  @override
  String get pushSettingsBlockedShort => 'इस फ़ोन पर अनुमति नहीं';

  @override
  String get pushSettingsIsOn => 'चालू';

  @override
  String get pushSettingsOff => 'बंद। चालू करने के लिए दबाएँ।';

  @override
  String get pushSettingsOn => 'चालू। मंज़ूरी और अलर्ट इस फ़ोन पर आएँगे।';

  @override
  String get pushSettingsOnContractor =>
      'चालू। भुगतान और बिल की जानकारी इस फ़ोन पर आती है।';

  @override
  String get pushSettingsTitle => 'फ़ोन के अलर्ट';

  @override
  String get pushSettingsTurnOn => 'चालू करें';

  @override
  String get readerCompanyLimit =>
      'आपकी कंपनी के लिए AI रीडर बंद है। कृपया सपोर्ट से संपर्क करें।';

  @override
  String get readerConsentRequired =>
      'आपकी कंपनी ने AI सेवा को दस्तावेज़ भेजने की सूचना पर अभी सहमति नहीं दी है। मालिक या एडमिन वेबसाइट की सेटिंग्स में यह कर सकते हैं।';

  @override
  String get readerDailyBudget =>
      'आपकी कंपनी का आज का AI बजट खत्म हो गया है। कल फिर से कोशिश करें।';

  @override
  String get readerNeedsInternet => 'दस्तावेज़ पढ़ने के लिए इंटरनेट चाहिए।';

  @override
  String get readerNoCredits => 'आपकी कंपनी के पास AI क्रेडिट नहीं बचे हैं।';

  @override
  String get readerNotConnected =>
      'AI सेवा अभी उपलब्ध नहीं है। बाद में फिर से कोशिश करें।';

  @override
  String get readerNotReleased => 'AI रीडर अभी चालू नहीं किया गया है।';

  @override
  String readerOverLimit(int count) {
    return 'सीमा में केवल $count पन्ने आते हैं। कम पन्ने चुनें।';
  }

  @override
  String get readerPagesMissing =>
      'कुछ पन्ने अभी भेजे नहीं गए हैं। उन्हें भेजें, फिर दोबारा शुरू करें।';

  @override
  String get readerPausedMonth => 'AI रीडर इस महीने के लिए रोका गया है।';

  @override
  String get readerPausedToday =>
      'AI रीडर आज के लिए रोका गया है। कल फिर से कोशिश करें।';

  @override
  String get readerPhotoMirrored =>
      'यह फ़ोटो उल्टी (आईने जैसी) सेव हुई है। फिर से लें।';

  @override
  String get readerPhotoTooSmall =>
      'यह फ़ोटो पढ़ने के लिए बहुत छोटी है। पास से लें।';

  @override
  String get readerPhotoUnreadable => 'यह फ़ोटो पढ़ी नहीं जा सकती। फिर से लें।';

  @override
  String get readerProblem => 'कुछ गलत हो गया। फिर से कोशिश करें।';

  @override
  String get readerRetriesUsed => 'यह पन्ना दोबारा नहीं पढ़ा जा सकता।';

  @override
  String get readerServerBusy =>
      'सर्वर अभी फ़ोटो प्रोसेस नहीं कर सकता। कुछ भी सेव नहीं हुआ। बाद में फिर से कोशिश करें।';

  @override
  String get readerStillWorking =>
      'अभी भी काम चल रहा है। ताज़ा करने के लिए नीचे खींचें।';

  @override
  String get readerTooManyRequests =>
      'बहुत ज़्यादा अनुरोध हुए। एक मिनट रुककर फिर से कोशिश करें।';

  @override
  String get readerWrongState =>
      'दस्तावेज़ की अभी की स्थिति में यह नहीं हो सकता। ताज़ा करके फिर से कोशिश करें।';

  @override
  String scanAlready(String name, String status) {
    return '$name की आज पहले से $status दर्ज है';
  }

  @override
  String get scanAlreadyUnknown => 'आज पहले से दर्ज है';

  @override
  String get scanCameraFailedMessage =>
      'यह पन्ना बंद करके फिर खोलें। हाज़िरी हाथ से भी लगाई जा सकती है।';

  @override
  String get scanCameraFailedTitle => 'कैमरा शुरू नहीं हुआ';

  @override
  String get scanCameraOffMessage =>
      'फ़ोन की सेटिंग में Nirmaan360 के लिए कैमरा चालू करें और वापस आएँ। हाज़िरी हाथ से भी लगाई जा सकती है।';

  @override
  String get scanCameraOffTitle => 'कैमरा बंद है';

  @override
  String get scanCameraStarting => 'कैमरा शुरू हो रहा है';

  @override
  String scanCount(int count) {
    return 'इस बार $count स्कैन हुए';
  }

  @override
  String get scanFailed => 'दर्ज नहीं हुआ। फिर कोशिश करें।';

  @override
  String get scanHint => 'मज़दूर के कार्ड के QR पर कैमरा रखें।';

  @override
  String scanMarked(String name) {
    return '$name की हाज़िरी लगी';
  }

  @override
  String get scanMarkedUnknown => 'हाज़िरी लगी';

  @override
  String get scanNotACard => 'यह मज़दूर का कार्ड नहीं है।';

  @override
  String scanQueued(String name) {
    return 'इस फ़ोन पर सेव हुआ। इंटरनेट आने पर $name की हाज़िरी लगेगी।';
  }

  @override
  String get scanQueuedNote =>
      'सेव किया स्कैन सिर्फ़ आज ही भेजा जाता है। आज के बाद उस दिन की हाज़िरी हाथ से लगाएँ।';

  @override
  String get scanQueuedUnknown =>
      'इस फ़ोन पर सेव हुआ। इंटरनेट आने पर हाज़िरी लगेगी।';

  @override
  String get scanStatusAbsent => 'गैरहाज़िर';

  @override
  String get scanStatusHalf => 'आधा दिन';

  @override
  String get scanStatusOvertime => 'ओवरटाइम';

  @override
  String get scanStatusPresent => 'हाज़िर';

  @override
  String get scanTitle => 'मज़दूर का कार्ड स्कैन करें';

  @override
  String get selfieConsentAgree => 'मज़दूर सहमत है';

  @override
  String get selfieConsentAsk =>
      'यह मज़दूर को पढ़कर सुनाएँ। वे सहमत हों तभी फ़ोटो लें।';

  @override
  String get selfieConsentNeedsNetwork =>
      'सहमति का नोट लोड करने के लिए एक बार इंटरनेट से जुड़ें। पहली फ़ोटो से पहले उसे दिखाना ज़रूरी है।';

  @override
  String get selfieConsentRead => 'सहमति का नोट पढ़ें';

  @override
  String get selfieConsentTitle => 'पहली फ़ोटो से पहले';

  @override
  String selfieConsentVersion(String version) {
    return 'सहमति नोट $version';
  }

  @override
  String get selfieFailed => 'सेव नहीं हुआ। फिर से कोशिश करें।';

  @override
  String get selfieIntro =>
      'मज़दूर की साइट पर फ़ोटो लें। फ़ोन दर्ज करता है कि वह कहाँ ली गई, और सर्वर जाँचता है कि वह साइट के पास थी।';

  @override
  String get selfieLocating => 'आपकी जगह खोज रहे हैं';

  @override
  String get selfieLocationDenied =>
      'इस ऐप के लिए लोकेशन बंद है। फ़ोन की सेटिंग में उसे चालू करें और फ़ोटो फिर से लें। उसके बिना फ़ोटो जाँचा नहीं जा सकता।';

  @override
  String get selfieLocationFailed =>
      'आपकी जगह नहीं मिली। खुले आसमान के नीचे जाकर फिर कोशिश करें।';

  @override
  String selfieLocationNote(String metres) {
    return 'जगह दर्ज की, लगभग $metres मी. तक सटीक';
  }

  @override
  String get selfieLocationOff =>
      'फ़ोन का लोकेशन बंद है। उसे चालू करें और फ़ोटो फिर से लें।';

  @override
  String get selfieLocationWhy =>
      'यह फ़ोटो कहाँ ली गई, यह दर्ज करने के लिए ही फ़ोन अभी आपकी जगह पूछता है। पृष्ठभूमि में आपको ट्रैक नहीं किया जाता।';

  @override
  String selfieMarked(String name, int distance, int radius) {
    return '$name की हाज़िरी लगी। साइट के केंद्र से $distance मी., $radius मी. तक मान्य।';
  }

  @override
  String selfieMarkedPlain(String name) {
    return '$name की हाज़िरी लगी';
  }

  @override
  String get selfieNoCamera =>
      'कैमरा नहीं खुला। फ़ोन की सेटिंग में कैमरा चालू करें।';

  @override
  String get selfieNoSiteMessage =>
      'फ़ोटो को साइट की नक्शे वाली जगह से जाँचा जाता है। ऑफ़िस से वेबसाइट पर साइट जोड़ने को कहें।';

  @override
  String get selfieNoSiteTitle => 'इस प्रोजेक्ट में कोई साइट नहीं';

  @override
  String get selfiePhotoRequired => 'पहले फ़ोटो लें';

  @override
  String selfieQueued(String name) {
    return 'इस फ़ोन पर सेव हुआ। इंटरनेट आने पर $name की हाज़िरी लगेगी और तब सर्वर जगह जाँचेगा।';
  }

  @override
  String get selfieQueuedNote =>
      'सेव किया फ़ोटो 7 दिन के भीतर भेजा जाता है। उसके बाद सर्वर उसे मना कर देता है।';

  @override
  String selfieRefused(String reason) {
    return 'दर्ज नहीं हुआ: $reason';
  }

  @override
  String get selfieRetake => 'फिर से लें';

  @override
  String get selfieSend => 'हाज़िर दर्ज करें';

  @override
  String get selfieSite => 'साइट';

  @override
  String get selfieTakePhoto => 'फ़ोटो लें';

  @override
  String get selfieTitle => 'फ़ोटो से हाज़िरी';

  @override
  String get selfieWorker => 'मज़दूर';

  @override
  String get selfieWorkerChoose => 'मज़दूर चुनें';

  @override
  String get selfieWorkerRequired => 'मज़दूर चुनें';

  @override
  String get selfieWorkerSearch => 'मज़दूर खोजें';

  @override
  String get shellCaptionMarked => 'दर्ज';

  @override
  String get shellCaptionOpen => 'खुली';

  @override
  String get shellCaptionToAnswer => 'जवाब बाकी';

  @override
  String get shellCaptionToday => 'आज';

  @override
  String get shellCaptionWorkDone => 'हुआ काम';

  @override
  String get shellCopyLink => 'लिंक कॉपी करें';

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
  String get shellLinkCopied => 'लिंक कॉपी हो गई';

  @override
  String get shellLinkFailedBody => 'लिंक कॉपी करें और ब्राउज़र में खोलें।';

  @override
  String get shellLinkFailedTitle => 'पेज नहीं खुला';

  @override
  String get shellOfflineApprovals => 'मंज़ूरी के लिए नेटवर्क चाहिए।';

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
  String get shellOutboxLoading => 'आउटबॉक्स ला रहे हैं';

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
  String get shellPickerFirstBody =>
      'अपनी परियोजना जोड़ें और उसका काम इसी फ़ोन से चलाएँ।';

  @override
  String get shellPickerFirstButton => 'अपनी पहली परियोजना बनाएँ';

  @override
  String get shellPickerFirstTitle => 'चलिए, अपनी पहली साइट बनाते हैं';

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
  String get shellProgressBasis => 'मंज़ूर काम के मूल्य से';

  @override
  String get shellProgressDaysLate => 'देरी, दिन';

  @override
  String get shellProgressDaysLeft => 'बचे दिन';

  @override
  String get shellProgressEmptyBody => 'BOQ जुड़ने पर प्रगति यहाँ दिखेगी।';

  @override
  String get shellProgressEmptyTitle => 'अभी BOQ नहीं';

  @override
  String get shellProgressEnd => 'समाप्ति';

  @override
  String get shellProgressNoEnd => 'तय नहीं';

  @override
  String get shellProgressNothingYet => 'अभी कोई मंज़ूर काम नहीं';

  @override
  String shellProgressSemantics(String percent) {
    return '$percent प्रतिशत पूरा';
  }

  @override
  String get shellProgressTitle => 'निर्माण की प्रगति';

  @override
  String get shellProjectChoose => 'चुनें';

  @override
  String get shellProjectCreate => 'परियोजना बनाएँ';

  @override
  String get shellProjectCreating => 'बन रही है…';

  @override
  String get shellProjectEnd => 'पूरा होने की तारीख';

  @override
  String get shellProjectEndBeforeStart =>
      'पूरा होने की तारीख शुरू की तारीख के बाद की हो';

  @override
  String get shellProjectFormTitle => 'नई परियोजना';

  @override
  String get shellProjectLocation => 'स्थान';

  @override
  String get shellProjectName => 'परियोजना का नाम';

  @override
  String get shellProjectNameRequired => 'परियोजना का नाम लिखें';

  @override
  String get shellProjectOffline =>
      'परियोजना सिर्फ़ नेटवर्क होने पर बनती है। सिग्नल आने पर फिर कोशिश करें।';

  @override
  String get shellProjectPlanBody =>
      'इस कंपनी ने अपने प्लान में अनुमत परियोजनाओं की संख्या पूरी कर ली है।';

  @override
  String get shellProjectPlanTitle => 'परियोजना की सीमा पूरी हो गई';

  @override
  String get shellProjectStart => 'शुरू की तारीख';

  @override
  String get shellProjectValue => 'अनुबंध राशि (वैकल्पिक)';

  @override
  String get shellProjectValueEmpty => 'भरने के लिए दबाएँ';

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
  String get shellSetupBoq => 'BOQ आइटम';

  @override
  String shellSetupNext(String step) {
    return 'अगला: $step';
  }

  @override
  String shellSetupStepSemantics(String step, int count) {
    return '$step: $count';
  }

  @override
  String get shellSetupTitle => 'अपनी साइट तैयार करें';

  @override
  String get shellSetupWallet => 'नकद बटुआ';

  @override
  String get shellSetupWorkers => 'कामगार';

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
  String get shellSignInNewCompany => 'नई कंपनी? खाता बनाएँ';

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
  String get shellSignUpBusy => 'आपका खाता बन रहा है…';

  @override
  String get shellSignUpButton => 'खाता बनाएँ';

  @override
  String get shellSignUpClosedBody => 'बाद में फिर कोशिश करें।';

  @override
  String get shellSignUpClosedTitle =>
      'अभी ऐप से नई कंपनी शुरू नहीं की जा सकती';

  @override
  String get shellSignUpCompany => 'कंपनी का नाम';

  @override
  String get shellSignUpCompanyMissing => 'कंपनी का नाम लिखें';

  @override
  String get shellSignUpConfirm => 'पासवर्ड दोबारा लिखें';

  @override
  String shellSignUpConsent(String terms, String privacy) {
    return 'खाता बनाकर आप $terms और $privacy से सहमत होते हैं।';
  }

  @override
  String get shellSignUpEmailInvalid => 'name@example.com जैसा ईमेल लिखें';

  @override
  String get shellSignUpEmailTakenBody =>
      'इसके बजाय उससे साइन इन करें, या कोई दूसरा ईमेल इस्तेमाल करें।';

  @override
  String get shellSignUpEmailTakenTitle => 'इस ईमेल का खाता पहले से है';

  @override
  String get shellSignUpFieldInvalid => 'इसे जाँचें और फिर कोशिश करें';

  @override
  String get shellSignUpHaveAccount => 'पहले से खाता है? साइन इन करें';

  @override
  String get shellSignUpIntro =>
      'इससे Nirmaan360 पर एक नई कंपनी शुरू होती है और आप उसके मालिक होते हैं। अगर आपकी कंपनी पहले से Nirmaan360 इस्तेमाल करती है, तो इसके बजाय अपने ऑफ़िस से आपको आमंत्रित करने को कहें।';

  @override
  String get shellSignUpName => 'आपका नाम';

  @override
  String get shellSignUpNameMissing => 'अपना नाम लिखें';

  @override
  String get shellSignUpPasswordHelper => 'कम से कम 8 अक्षर।';

  @override
  String get shellSignUpPasswordMismatch => 'दोनों पासवर्ड एक जैसे नहीं हैं';

  @override
  String get shellSignUpPasswordShort => 'कम से कम 8 अक्षर रखें';

  @override
  String get shellSignUpPhone => 'मोबाइल नंबर (वैकल्पिक)';

  @override
  String get shellSignUpPhoneTaken =>
      'यह मोबाइल नंबर किसी और खाते में इस्तेमाल हो रहा है।';

  @override
  String get shellSignUpPrivacy => 'गोपनीयता नीति';

  @override
  String get shellSignUpTerms => 'शर्तें';

  @override
  String get shellSignUpTitle => 'खाता बनाएँ';

  @override
  String shellSignUpTooLong(int max) {
    return 'ज़्यादा से ज़्यादा $max अक्षर रखें';
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
  String get shellTileExpenses => 'खर्च';

  @override
  String get shellTileIssues => 'समस्याएँ';

  @override
  String get shellTileMaterial => 'मटेरियल';

  @override
  String get shellTileMeasure => 'माप';

  @override
  String get shellTilePetty => 'फुटकर नकद';

  @override
  String get shellTileProgress => 'प्रगति';

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
  String get siteToolsInspectionsHint =>
      'पोर, शटरिंग या सुरक्षा राउंड की चेकलिस्ट भरें';

  @override
  String get siteToolsMachinesHint =>
      'मशीन आज कितना चली, घंटों या किमी में दर्ज करें';

  @override
  String get siteToolsTitle => 'साइट टूल्स';

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
  String get syncReasonProjectGone =>
      'अब इस प्रोजेक्ट की पहुँच आपके पास नहीं है। ऑफ़िस से दोबारा जोड़ने को कहें, फिर से कोशिश करें।';

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
  String get syncTypeAttendanceScan => 'कार्ड स्कैन';

  @override
  String get syncTypeAttendanceSelfie => 'हाज़िरी की फ़ोटो';

  @override
  String get syncTypeContractorPayment => 'ठेकेदार भुगतान अनुरोध';

  @override
  String get syncTypeDpr => 'दैनिक रिपोर्ट';

  @override
  String get syncTypeDprPhoto => 'रिपोर्ट फ़ोटो';

  @override
  String get syncTypeDprSubmit => 'रिपोर्ट जमा करना';

  @override
  String get syncTypeGoodsReceipt => 'माल की प्राप्ति';

  @override
  String get syncTypeInspection => 'निरीक्षण';

  @override
  String get syncTypeIssue => 'समस्या';

  @override
  String get syncTypeIssuePhoto => 'समस्या की फ़ोटो';

  @override
  String get syncTypeIssueStatus => 'समस्या की स्थिति';

  @override
  String get syncTypeMachineLog => 'मशीन लॉग';

  @override
  String get syncTypeMaterialIssue => 'स्टोर से निकासी';

  @override
  String get syncTypeMeasurement => 'माप';

  @override
  String get syncTypeMeasurementPhoto => 'माप की फ़ोटो';

  @override
  String get syncTypeMeasurementSubmit => 'माप जमा करना';

  @override
  String get syncTypePettyBill => 'बिल की फ़ोटो';

  @override
  String get syncTypePettyCash => 'फुटकर नकद प्रविष्टि';

  @override
  String get syncTypeProgress => 'साइट प्रगति';

  @override
  String get syncTypeProgressCorrection => 'प्रगति में सुधार';

  @override
  String get syncTypeProgressPhoto => 'प्रगति की फ़ोटो';

  @override
  String get syncTypeProgressResolve => 'बाकी काम पूरा';

  @override
  String get syncTypeStage => 'चरण की जानकारी';

  @override
  String get syncTypeStagePhoto => 'चरण की फ़ोटो';

  @override
  String get tenderAddQuote => 'कोटेशन जोड़ें';

  @override
  String get tenderAdvance => 'एडवांस (₹)';

  @override
  String get tenderAdvanceError =>
      '0 या ज़्यादा लिखें, ज़्यादा से ज़्यादा दो दशमलव।';

  @override
  String get tenderAdvanceRecovery => 'एडवांस वसूली %';

  @override
  String get tenderAmountError =>
      'शून्य से ज़्यादा राशि लिखें, ज़्यादा से ज़्यादा दो दशमलव।';

  @override
  String get tenderAwardExplain =>
      'इस कोटेशन के रेट पर वर्क ऑर्डर बनेगा। उसके बाद माप, RA बिल और भुगतान उसी पर चलेंगे।';

  @override
  String get tenderAwardSend => 'वर्क ऑर्डर बनाएँ';

  @override
  String get tenderAwardTitle => 'यह ठेकेदार चुनें';

  @override
  String get tenderAwarded => 'वर्क ऑर्डर बन गया';

  @override
  String get tenderBasisGross => 'कुल';

  @override
  String get tenderBasisRunning => 'शेष';

  @override
  String tenderBoqAmount(String amount) {
    return 'BOQ राशि $amount';
  }

  @override
  String tenderBoqQty(String qty, String unit) {
    return 'BOQ $qty $unit';
  }

  @override
  String tenderBoqRate(String rate) {
    return 'BOQ रेट $rate';
  }

  @override
  String get tenderClosed => 'यह टेंडर बंद है, अब कोटेशन नहीं लिए जा सकते।';

  @override
  String get tenderCompletionDays => 'काम पूरा करने के दिन';

  @override
  String get tenderContractor => 'ठेकेदार';

  @override
  String get tenderContractorAdd => 'ठेकेदार जोड़ें';

  @override
  String get tenderContractorChoose => 'ठेकेदार चुनें';

  @override
  String get tenderContractorName => 'नाम';

  @override
  String get tenderContractorNameRequired => 'ठेकेदार का नाम लिखें।';

  @override
  String get tenderContractorNone => 'इस नाम का कोई ठेकेदार नहीं';

  @override
  String get tenderContractorPerson => 'संपर्क व्यक्ति';

  @override
  String get tenderContractorPhone => 'फ़ोन';

  @override
  String get tenderContractorRequired => 'चुनें कि कोटेशन किसने दिया।';

  @override
  String get tenderContractorSave => 'ठेकेदार जोड़ें';

  @override
  String get tenderContractorSearch => 'ठेकेदार खोजें';

  @override
  String get tenderContractorsFailed => 'ठेकेदारों की सूची लोड नहीं हुई।';

  @override
  String tenderCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count टेंडर',
      one: '1 टेंडर',
    );
    return '$_temp0';
  }

  @override
  String tenderDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days दिन',
      one: '1 दिन',
    );
    return '$_temp0';
  }

  @override
  String get tenderDaysError => 'पूरे दिन लिखें, 1 से 3650।';

  @override
  String get tenderDeductionBasis => 'कटौती किस पर';

  @override
  String get tenderDeductionOrder => 'कटौती का क्रम';

  @override
  String tenderDue(String date) {
    return 'कोटेशन की आख़िरी तारीख़ $date';
  }

  @override
  String get tenderEndDate => 'पूरा करने की तारीख़';

  @override
  String get tenderEndError => 'पूरा करने की तारीख़ शुरू से पहले नहीं हो सकती।';

  @override
  String tenderEnteredBy(String name) {
    return '$name ने दर्ज किया';
  }

  @override
  String get tenderFieldDue => 'कोटेशन की आख़िरी तारीख़';

  @override
  String get tenderFieldScope => 'काम का दायरा';

  @override
  String get tenderFieldTitle => 'शीर्षक';

  @override
  String get tenderGst => 'GST %';

  @override
  String get tenderIncludesMaterial => 'रेट में मटेरियल शामिल';

  @override
  String tenderLineAmount(String qty, String unit, String amount) {
    return '$qty $unit × रेट = $amount';
  }

  @override
  String get tenderLinkEmpty => 'टेंडर · ठेकेदारों से कोटेशन लें';

  @override
  String get tenderLoadFailed =>
      'टेंडर लोड नहीं हुए। नीचे खींचकर फिर कोशिश करें।';

  @override
  String get tenderLoading => 'टेंडर लोड हो रहे हैं';

  @override
  String tenderLowest(String rate) {
    return 'सबसे कम $rate';
  }

  @override
  String tenderLowestBy(String rate, String contractor) {
    return 'सबसे कम $rate · $contractor';
  }

  @override
  String get tenderMaterialIn => 'मटेरियल सहित';

  @override
  String get tenderMaterialOut => 'सिर्फ़ मज़दूरी';

  @override
  String get tenderMoneyHidden =>
      'रेट, शर्तें और फ़ाइलें सिर्फ़ उन्हें दिखती हैं जिन्हें पैसे देखने का अधिकार है।';

  @override
  String get tenderNew => 'नया टेंडर';

  @override
  String get tenderNewContractorTitle => 'नया ठेकेदार';

  @override
  String get tenderNewTitle => 'नया टेंडर';

  @override
  String get tenderNoDate => 'कोई तारीख़ नहीं';

  @override
  String get tenderNoQuantity =>
      'इस लाइन में अभी मात्रा नहीं है, इसलिए टेंडर नहीं निकाला जा सकता। कोटेशन की तुलना हो सके, इसके लिए पहले BOQ में मात्रा भरें।';

  @override
  String get tenderNoneAsk =>
      'इस लाइन के लिए अभी किसी ने ठेकेदारों से कोटेशन नहीं माँगे हैं।';

  @override
  String get tenderNoneStart =>
      'इस लाइन के लिए ठेकेदारों के कोटेशन लेने के लिए टेंडर शुरू करें।';

  @override
  String get tenderNoneTitle => 'अभी कोई टेंडर नहीं';

  @override
  String get tenderNote => 'नोट';

  @override
  String get tenderOfflineMessage =>
      'कोटेशन और चयन ऑफ़िस से अभी की स्थिति में पढ़े जाते हैं। नेटवर्क मिलने पर नीचे खींचकर फिर कोशिश करें।';

  @override
  String get tenderOfflineTitle => 'टेंडर के लिए इंटरनेट चाहिए';

  @override
  String get tenderOpenElsewhere => 'दूसरे ऐप में खोलें';

  @override
  String get tenderOptional => 'वैकल्पिक';

  @override
  String get tenderOrderAdvanceFirst => 'एडवांस → रिटेंशन → मटेरियल → TDS';

  @override
  String get tenderOrderRetentionFirst => 'रिटेंशन → एडवांस → मटेरियल → TDS';

  @override
  String get tenderOrderTdsFirst => 'TDS → रिटेंशन → एडवांस → मटेरियल';

  @override
  String tenderOtherLines(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count और लाइनें',
      one: '1 और लाइन',
    );
    return '$_temp0';
  }

  @override
  String get tenderPaymentTerms => 'भुगतान की शर्तें';

  @override
  String get tenderPctError => '0 से 100 लिखें, ज़्यादा से ज़्यादा दो दशमलव।';

  @override
  String tenderPerUnit(String rate, String unit) {
    return '$rate प्रति $unit';
  }

  @override
  String get tenderPickDate => 'तारीख़ चुनें';

  @override
  String get tenderQuotationFailed => 'कोटेशन नहीं खुल सका।';

  @override
  String get tenderQuotationNotPicture =>
      'यह कोटेशन फ़ोटो नहीं, फ़ाइल है। इसे किसी दूसरे ऐप में खोलें।';

  @override
  String get tenderQuotationPhoto => 'कोटेशन की फ़ोटो';

  @override
  String tenderQuoteCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count कोटेशन',
      one: '1 कोटेशन',
      zero: 'कोई कोटेशन नहीं',
    );
    return '$_temp0';
  }

  @override
  String get tenderQuoteGone =>
      'यह कोटेशन अब टेंडर में नहीं है। वापस जाकर फिर चुनें।';

  @override
  String get tenderQuoteSaved => 'कोटेशन सेव हुआ';

  @override
  String get tenderQuoteTitle => 'कोटेशन जोड़ें';

  @override
  String get tenderQuotedAmount => 'कोटेशन राशि (₹)';

  @override
  String get tenderQuotesNone => 'अभी कोई कोटेशन नहीं';

  @override
  String get tenderRateError =>
      'शून्य से ज़्यादा रेट लिखें, ज़्यादा से ज़्यादा दो दशमलव।';

  @override
  String tenderRatePer(String unit) {
    return 'प्रति $unit रेट';
  }

  @override
  String tenderReceived(String date) {
    return 'मिला $date';
  }

  @override
  String get tenderReceivedOn => 'मिलने की तारीख़';

  @override
  String get tenderRefusedMessage => 'टेंडर देखने हों तो ऑफ़िस से पूछें।';

  @override
  String get tenderRefusedTitle => 'आपकी भूमिका के लिए नहीं';

  @override
  String get tenderRemarks => 'टिप्पणी';

  @override
  String get tenderRetention => 'रिटेंशन %';

  @override
  String get tenderSaveFailed => 'सेव नहीं हुआ। नेटवर्क देखकर फिर कोशिश करें।';

  @override
  String get tenderSaveQuote => 'कोटेशन सेव करें';

  @override
  String get tenderSelect => 'यह ठेकेदार चुनें';

  @override
  String get tenderStart => 'टेंडर शुरू करें';

  @override
  String get tenderStartDate => 'शुरू होने की तारीख़';

  @override
  String get tenderStarted => 'टेंडर शुरू हुआ';

  @override
  String get tenderStatusAwarded => 'दिया गया';

  @override
  String get tenderStatusCancelled => 'रद्द';

  @override
  String get tenderStatusOpen => 'खुला';

  @override
  String get tenderTagLowest => 'सबसे कम';

  @override
  String get tenderTagReplaced => 'बदला गया';

  @override
  String get tenderTagSelected => 'चुना गया';

  @override
  String get tenderTds => 'TDS %';

  @override
  String get tenderTitle => 'टेंडर';

  @override
  String get tenderTitleRequired => 'टेंडर का शीर्षक लिखें।';

  @override
  String tenderTotal(String amount) {
    return 'कुल कोटेशन $amount';
  }

  @override
  String get tenderValidError =>
      'मान्यता की तारीख़ मिलने की तारीख़ से पहले नहीं हो सकती।';

  @override
  String get tenderValidTill => 'कब तक मान्य';

  @override
  String tenderValidUntil(String date) {
    return '$date तक मान्य';
  }

  @override
  String get tenderViewQuotation => 'कोटेशन देखें';

  @override
  String tenderVsBoq(String pct) {
    return 'BOQ से $pct%';
  }

  @override
  String tenderWorkOrder(String number) {
    return 'वर्क ऑर्डर $number';
  }
}
