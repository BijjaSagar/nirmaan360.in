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
  String get syncTypeIssueStatus => 'समस्येची स्थिती';

  @override
  String get syncTypeMeasurement => 'मोजमाप';

  @override
  String get syncTypeMeasurementSubmit => 'मोजमाप सादर करणे';

  @override
  String get syncTypePettyBill => 'बिलाचा फोटो';

  @override
  String get syncTypePettyCash => 'किरकोळ रोख नोंद';
}
