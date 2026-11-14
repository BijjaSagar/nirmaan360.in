/// The consent note the server shows before a photo of a worker is taken. The text is
/// the server's, in the person's language, and the server stores its own copy of it
/// with each photo as proof, so the phone shows it as it came and never edits it.
class SelfieConsent {
  const SelfieConsent({required this.version, required this.text});

  static SelfieConsent? tryFromJson(Object? json) {
    if (json is! Map) return null;
    final Object? version = json['version'];
    final Object? text = json['text'];
    if (version is! String || version.isEmpty || text is! String || text.trim().isEmpty) return null;
    return SelfieConsent(version: version, text: text.trim());
  }

  final String version;
  final String text;

  Map<String, String> toJson() => <String, String>{'version': version, 'text': text};
}

/// Where the phone was when the photo was taken. Text, as the server takes it:
/// latitude and longitude to seven places, the accuracy in metres to one. Nothing
/// here is arithmetic, so there is no number to round.
class SelfieFix {
  const SelfieFix({required this.lat, required this.lng, required this.accuracy});

  final String lat;
  final String lng;

  /// How far off the reading may be, in metres. The server refuses a reading vaguer
  /// than the site's fence.
  final String accuracy;
}

enum SelfieResult {
  /// The server marked the worker present.
  marked,

  /// Kept on this phone to be sent when there is a network. The server checks the place then.
  queued,

  /// The server said no, in its own words: too far from the site, a worker who already has a photo today.
  refused,
}

class SelfieOutcome {
  const SelfieOutcome(this.result, {this.workerName, this.message, this.distance, this.radius});

  final SelfieResult result;
  final String? workerName;

  /// For [SelfieResult.refused]: the server's words.
  final String? message;

  /// For [SelfieResult.marked]: how far from the site's centre the photo was taken, and how far is allowed, in metres.
  final int? distance;
  final int? radius;
}
