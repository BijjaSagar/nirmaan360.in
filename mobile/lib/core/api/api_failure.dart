/// Every failure from the API client is one of these.
sealed class ApiFailure implements Exception {
  const ApiFailure(this.message);

  final String message;

  @override
  String toString() => '$runtimeType: $message';
}

/// No connection, DNS failure, or a timeout. Unsent work simply waits.
class OfflineFailure extends ApiFailure {
  const OfflineFailure([super.message = 'You are offline.']);
}

/// HTTP 401. The user must sign in again; unsent work is untouched.
class AuthExpired extends ApiFailure {
  const AuthExpired([super.message = 'Please sign in again.']);
}

/// HTTP 429. The server asked for a pause. Not an error with the data: the
/// request is simply repeated once [retryAfter] has passed.
class RateLimited extends ApiFailure {
  const RateLimited({this.retryAfter, this.serverMessage}) : super('Too many requests.');

  /// What the server wrote in the reply, when it wrote anything. A screen that
  /// has to explain a refusal shows this, in the server's own words.
  final String? serverMessage;

  /// How long the server asked to wait, from `Retry-After`.
  final Duration? retryAfter;
}

/// HTTP 422. [errors] maps field names to messages.
class ValidationFailure extends ApiFailure {
  const ValidationFailure(super.message, {this.errors = const {}});

  final Map<String, List<String>> errors;
}

/// Any other non-success response, including 403, 404, 409 and 5xx.
class ServerFailure extends ApiFailure {
  const ServerFailure(super.message, {this.statusCode, this.code, this.data});

  final int? statusCode;

  /// The reply's machine-readable `code`, when it has one.
  final String? code;

  /// The whole reply, for a caller that needs more than the [code].
  final Map<String, dynamic>? data;
}

/// HTTP 403 with code `offline_plan`: this company keeps its data on the phone.
///
/// Not a [ServerFailure], on purpose. The sync engine moves a row to attention
/// on an ordinary 403, and this one says nothing about the row.
class OfflinePlanRefused extends ApiFailure {
  const OfflinePlanRefused(super.message, {this.upgradeUrl});

  final String? upgradeUrl;
}

/// Raised on the phone, never by the server: the offline licence has ended, so
/// nothing new may be added or changed until it is renewed. Reading, the export
/// and everything already on the phone are untouched.
///
/// An [ApiFailure] on purpose, so every form that already knows how to say
/// "could not save" also stops cleanly. The app then opens the renewal page.
class LicenceEnded extends ApiFailure {
  const LicenceEnded({this.endedAt}) : super('The licence has ended. Connect to the internet to renew it.');

  /// When the signed token ran out, if the phone knows.
  final DateTime? endedAt;
}
