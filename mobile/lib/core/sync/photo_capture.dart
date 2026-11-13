import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:image_picker/image_picker.dart';

/// What a photo is for, which decides its size and quality.
enum CaptureMode {
  /// Site, bill and slip photos: readable at this size, and quick to upload
  /// on a weak network.
  field(maxEdge: 1600, quality: 70),

  /// A page for a document reader. 1900 x 1900 is 4,624 tokens, so a page of
  /// any shape fits the reader without being cut down again; the higher
  /// quality keeps small print legible.
  document(maxEdge: 1900, quality: 85);

  const CaptureMode({required this.maxEdge, required this.quality});

  /// The longest side, in pixels. The picker keeps the aspect ratio.
  final int maxEdge;

  /// JPEG quality, 0 to 100.
  final int quality;
}

/// Takes or picks a photo as a compressed file.
///
/// The picker writes the compressed copy to a temporary location. Pass the
/// returned path to `DprRepository.addPhoto` or `PettyCashRepository.addExpense`,
/// which copy it into app storage before anything refers to it. A document
/// page goes straight to `NirmaanApi.uploadReadPage`.
///
/// The picker copies the photo's metadata, location included, into the copy.
/// The phone does not strip it: the server removes it and turns the picture
/// upright before anything is stored or sent to a reader.
class PhotoCapture {
  PhotoCapture([ImagePicker? picker]) : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  /// For tests: a stand-in for the camera, which a test does not have.
  @visibleForTesting
  static Future<String?> Function()? stand;

  /// Returns the compressed file's path, or null if the user cancelled.
  Future<String?> capture({ImageSource source = ImageSource.camera, CaptureMode mode = CaptureMode.field}) async {
    final stand = PhotoCapture.stand;
    if (stand != null) return stand();
    final file = await _picker.pickImage(
      source: source,
      maxWidth: mode.maxEdge.toDouble(),
      maxHeight: mode.maxEdge.toDouble(),
      imageQuality: mode.quality,
    );
    return file?.path;
  }
}
