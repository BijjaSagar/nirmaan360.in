/// A worker's printed QR card. The server makes the code and is the only one
/// that can check it: `N360W-<worker id>-<card version>-<20 hex signature>`,
/// signed with a key the phone never has (`WorkerCardService`).
///
/// The phone reads the shape only, so it can turn away a QR that is not a card
/// at once, and show whose card it is from its own list of workers. It never
/// treats a code as proven. The version and the signature are the server's to check.
abstract final class WorkerCard {
  static final RegExp _shape = RegExp(r'^N360W-(\d+)-(\d+)-([a-f0-9]{20})$');

  /// The longest code the server takes (`max:100`).
  static const int longest = 100;

  /// The worker id the card names, or null when [code] is not shaped like a card.
  static int? workerIdOf(String code) {
    final RegExpMatch? match = _shape.firstMatch(code.trim());
    return match == null ? null : int.tryParse(match.group(1)!);
  }
}
