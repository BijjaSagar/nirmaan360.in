import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:nirmaan360/core/sync/photo_capture.dart';

/// Records what the app asked the picker for.
class RecordingPicker extends ImagePicker {
  final List<({double? maxWidth, double? maxHeight, int? quality, ImageSource source})> asked =
      <({double? maxWidth, double? maxHeight, int? quality, ImageSource source})>[];

  @override
  Future<XFile?> pickImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
    bool requestFullMetadata = true,
  }) async {
    asked.add((maxWidth: maxWidth, maxHeight: maxHeight, quality: imageQuality, source: source));
    return XFile('/tmp/picked.jpg');
  }
}

void main() {
  late RecordingPicker picker;

  setUp(() {
    PhotoCapture.stand = null;
    picker = RecordingPicker();
  });

  test('field photos stay at 1600 px and quality 70', () async {
    final String? path = await PhotoCapture(picker).capture();

    expect(path, '/tmp/picked.jpg');
    expect(picker.asked.single.maxWidth, 1600);
    expect(picker.asked.single.maxHeight, 1600);
    expect(picker.asked.single.quality, 70);
    expect(picker.asked.single.source, ImageSource.camera);
  });

  test('a document page is taken at 1900 px and quality 85', () async {
    await PhotoCapture(picker).capture(mode: CaptureMode.document, source: ImageSource.gallery);

    expect(picker.asked.single.maxWidth, 1900);
    expect(picker.asked.single.maxHeight, 1900);
    expect(picker.asked.single.quality, 85);
    expect(picker.asked.single.source, ImageSource.gallery);
  });

  test('a test stand-in replaces the camera in either mode', () async {
    PhotoCapture.stand = () async => '/tmp/stand.jpg';
    addTearDown(() => PhotoCapture.stand = null);

    expect(await PhotoCapture(picker).capture(mode: CaptureMode.document), '/tmp/stand.jpg');
    expect(picker.asked, isEmpty);
  });
}
