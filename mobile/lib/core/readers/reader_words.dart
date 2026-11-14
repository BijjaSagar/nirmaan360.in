import '../../l10n/generated/app_localizations.dart';
import '../api/api_failure.dart';

/// The sentence a reader screen shows for a failed reader call, in the app's
/// language. Every reader screen uses this, so the same refusal reads the same
/// on the challan form, the BOQ capture and the voice draft. A code the app
/// does not know falls back to the server's own sentence.
String readerProblemText(AppLocalizations l, ApiFailure failure) {
  switch (failure) {
    case OfflineFailure():
      return l.readerNeedsInternet;
    case RateLimited(:final String? serverMessage):
      return serverMessage ?? l.readerTooManyRequests;
    case ValidationFailure(:final String? code):
      return switch (code) {
        'image_too_small' => l.readerPhotoTooSmall,
        'image_mirrored' => l.readerPhotoMirrored,
        'not_an_image' || 'image_too_big' || 'file_too_big' => l.readerPhotoUnreadable,
        _ => _serverWords(l, failure),
      };
    case ServerFailure(:final String? code, :final int? statusCode, :final Map<String, dynamic>? data):
      switch (code) {
        case 'not_released':
          return l.readerNotReleased;
        case 'not_connected':
          return l.readerNotConnected;
        case 'consent_required':
          return l.readerConsentRequired;
        case 'company_limit':
          return l.readerCompanyLimit;
        case 'reader_paused':
          return data?['period'] == 'monthly' ? l.readerPausedMonth : l.readerPausedToday;
        case 'no_credits':
          return l.readerNoCredits;
        case 'daily_budget':
          return l.readerDailyBudget;
        case 'pages_missing':
          return l.readerPagesMissing;
        case 'over_limit':
          final Object? fit = data?['pages_that_fit'];
          return fit is num ? l.readerOverLimit(fit.toInt()) : _serverWords(l, failure);
        case 'wrong_state':
          return l.readerWrongState;
        case 'retries_used':
          return l.readerRetriesUsed;
        case 'server_cannot_process':
          return l.readerServerBusy;
      }
      if (statusCode == 403) return l.syncReasonForbidden;
      return _serverWords(l, failure);
    case AuthExpired():
      return _serverWords(l, failure);
  }
}

String _serverWords(AppLocalizations l, ApiFailure failure) {
  final String said = failure.message.trim();
  return said.isEmpty ? l.readerProblem : said;
}
