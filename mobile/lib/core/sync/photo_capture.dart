import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:image_picker/image_picker.dart';

/// Takes or picks a photo as a compressed file.
///
/// The picker writes the compressed copy to a temporary location. Pass the
/// returned path to `DprRepository.addPhoto` or `PettyCashRepository.addExpense`,
/// which copy it into app storage before anything refers to it.
class PhotoCapture {
  PhotoCapture([ImagePicker? picker]) : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  /// For tests: a stand-in for the camera, which a test does not have.
  @visibleForTesting
  static Future<String?> Function()? stand;

  /// Field photos stay readable at this size and upload on a weak network.
  static const _maxEdge = 1600;
  static const _quality = 70;

  /// Returns the compressed file's path, or null if the user cancelled.
  Future<String?> capture({ImageSource source = ImageSource.camera}) async {
    final stand = PhotoCapture.stand;
    if (stand != null) return stand();
    final file = await _picker.pickImage(
      source: source,
      maxWidth: _maxEdge.toDouble(),
      maxHeight: _maxEdge.toDouble(),
      imageQuality: _quality,
    );
    return file?.path;
  }
}
