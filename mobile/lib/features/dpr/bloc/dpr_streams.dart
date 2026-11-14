import 'dart:async';

/// The first event of [stream].
///
/// `Stream.first` holds its result until the subscription has finished
/// cancelling. A Drift stream's cancel does not complete inside a widget
/// test's fake-async zone, so `.first` on `watch*` would hang there. Nothing
/// here needs to wait for the cancel, so this does not.
Future<T> dprFirst<T>(Stream<T> stream) {
  final completer = Completer<T>();
  late final StreamSubscription<T> subscription;
  subscription = stream.listen(
    (value) {
      if (completer.isCompleted) return;
      completer.complete(value);
      unawaited(subscription.cancel());
    },
    onError: (Object error, StackTrace stack) {
      if (!completer.isCompleted) completer.completeError(error, stack);
    },
  );
  return completer.future;
}
