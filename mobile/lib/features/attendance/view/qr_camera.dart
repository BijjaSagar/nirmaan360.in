import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';

/// The live camera, looking for QR codes only. [onCode] gets the text of each one.
///
/// The camera runs only while this is on screen. It opens when the page opens and
/// stops when the page closes or the app goes to the background, which the package
/// handles. Tests put their own view here, because a test has no camera.
class QrCameraView extends StatefulWidget {
  const QrCameraView({super.key, required this.onCode});

  final ValueChanged<String> onCode;

  /// For tests: a stand-in for the camera.
  @visibleForTesting
  static Widget Function(BuildContext context, ValueChanged<String> onCode)? cameraOverride;

  @override
  State<QrCameraView> createState() => _QrCameraViewState();
}

class _QrCameraViewState extends State<QrCameraView> {
  MobileScannerController? _controller;

  @override
  void initState() {
    super.initState();
    if (QrCameraView.cameraOverride == null) {
      _controller = MobileScannerController(
        formats: const <BarcodeFormat>[BarcodeFormat.qrCode],
        detectionSpeed: DetectionSpeed.noDuplicates,
      );
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    for (final Barcode code in capture.barcodes) {
      final String? text = code.rawValue;
      if (text != null && text.isNotEmpty) {
        widget.onCode(text);
        return;
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final stand = QrCameraView.cameraOverride;
    if (stand != null) return stand(context, widget.onCode);
    final AppLocalizations l = AppLocalizations.of(context);
    return MobileScanner(
      controller: _controller,
      onDetect: _onDetect,
      placeholderBuilder: (context) => ColoredBox(
        color: N360Colors.ink,
        child: Center(
          child: Text(l.scanCameraStarting, style: N360Type.body.copyWith(color: N360Colors.onPrimary)),
        ),
      ),
      errorBuilder: (context, error) {
        final bool denied = error.errorCode == MobileScannerErrorCode.permissionDenied;
        return ColoredBox(
          color: N360Colors.sheet,
          child: Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: EmptyLedger(
              animated: false,
              title: denied ? l.scanCameraOffTitle : l.scanCameraFailedTitle,
              message: denied ? l.scanCameraOffMessage : l.scanCameraFailedMessage,
            ),
          ),
        );
      },
    );
  }
}
