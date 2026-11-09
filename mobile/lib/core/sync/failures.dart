/// The record cannot change locally: it is approved, in review, or has a
/// submission still waiting to go out.
class RecordLocked implements Exception {
  const RecordLocked(this.message);

  final String message;

  @override
  String toString() => 'RecordLocked: $message';
}

/// Sign-out was refused because [count] items have not reached the server.
class UnsentWorkFailure implements Exception {
  const UnsentWorkFailure(this.count);

  final int count;

  @override
  String toString() => 'UnsentWorkFailure: $count items not sent';
}

/// Someone else tried to sign in while this phone still holds another
/// user's unsent work, which must not be pushed under the wrong account.
class DifferentUserFailure implements Exception {
  const DifferentUserFailure(this.unsentCount);

  final int unsentCount;

  @override
  String toString() => 'DifferentUserFailure: $unsentCount items unsent';
}
