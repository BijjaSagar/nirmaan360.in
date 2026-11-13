import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nirmaan360/core/api/api_failure.dart';
import 'package:nirmaan360/core/readers/reader_words.dart';
import 'package:nirmaan360/l10n/generated/app_localizations.dart';

void main() {
  final AppLocalizations en = lookupAppLocalizations(const Locale('en'));
  final AppLocalizations hi = lookupAppLocalizations(const Locale('hi'));
  final AppLocalizations mr = lookupAppLocalizations(const Locale('mr'));

  ServerFailure refused(String code, {int status = 409, Map<String, dynamic>? data}) =>
      ServerFailure('Server sentence.', statusCode: status, code: code, data: <String, dynamic>{'code': code, ...?data});

  test('each shared refusal has its own sentence', () {
    expect(readerProblemText(en, refused('consent_required')), en.readerConsentRequired);
    expect(readerProblemText(en, refused('not_released')), en.readerNotReleased);
    expect(readerProblemText(en, refused('not_connected')), en.readerNotConnected);
    expect(readerProblemText(en, refused('company_limit', data: {'limit': 'blocked'})), en.readerCompanyLimit);
    expect(readerProblemText(en, refused('no_credits', status: 402)), en.readerNoCredits);
    expect(readerProblemText(en, refused('daily_budget', status: 402)), en.readerDailyBudget);
    expect(readerProblemText(en, refused('pages_missing', data: {'missing': [4, 9]})), en.readerPagesMissing);
    expect(readerProblemText(en, refused('wrong_state')), en.readerWrongState);
    expect(readerProblemText(en, refused('retries_used')), en.readerRetriesUsed);
    expect(readerProblemText(en, refused('server_cannot_process')), en.readerServerBusy);
  });

  test('a pause says whether it is for the day or the month', () {
    expect(readerProblemText(en, refused('reader_paused', data: {'period': 'daily'})), en.readerPausedToday);
    expect(readerProblemText(en, refused('reader_paused', data: {'period': 'monthly'})), en.readerPausedMonth);
  });

  test('over the limit says how many pages fit', () {
    expect(readerProblemText(en, refused('over_limit', data: {'pages_that_fit': 35})), 'Only 35 pages fit the limit. Choose fewer pages.');
  });

  test('a refused photo says what to do', () {
    expect(readerProblemText(en, const ValidationFailure('x', code: 'image_too_small')), en.readerPhotoTooSmall);
    expect(readerProblemText(en, const ValidationFailure('x', code: 'image_mirrored')), en.readerPhotoMirrored);
    expect(readerProblemText(en, const ValidationFailure('x', code: 'image_too_big')), en.readerPhotoUnreadable);
  });

  test('offline, a 429 and a 403', () {
    expect(readerProblemText(en, const OfflineFailure()), en.readerNeedsInternet);
    expect(readerProblemText(en, const RateLimited()), en.readerTooManyRequests);
    expect(readerProblemText(en, const RateLimited(serverMessage: 'Slow down.')), 'Slow down.');
    expect(readerProblemText(en, const ServerFailure('Forbidden.', statusCode: 403)), en.syncReasonForbidden);
  });

  test('an unknown code falls back to the server sentence, then to a plain one', () {
    expect(readerProblemText(en, refused('cut_off')), 'Server sentence.');
    expect(readerProblemText(en, const ServerFailure('  ', statusCode: 500)), en.readerProblem);
  });

  test('the sentences exist in Hindi and Marathi', () {
    expect(readerProblemText(hi, refused('consent_required')), isNot(en.readerConsentRequired));
    expect(readerProblemText(mr, const OfflineFailure()), mr.readerNeedsInternet);
    expect(mr.readerOverLimit(3), contains('3'));
  });
}
