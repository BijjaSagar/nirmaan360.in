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
  const RateLimited({this.retryAfter}) : super('Too many requests.');

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
  const ServerFailure(super.message, {this.statusCode});

  final int? statusCode;
}
