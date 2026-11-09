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
