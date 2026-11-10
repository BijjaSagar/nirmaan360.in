import 'dart:async';

import 'package:flutter/widgets.dart';

import 'app/app.dart';
import 'app/app_gate.dart';
import 'core/app_services.dart';
import 'core/sync/background_sync.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  final services = AppServices.production();
  final gate = AppGate(services);

  // The first frame is plain paper, painted before anything slow begins.
  runApp(Nirmaan360App(services: services, gate: gate));

  WidgetsBinding.instance.addPostFrameCallback((_) {
    unawaited(_start(services, gate));
    unawaited(_registerBackground());
  });
}

Future<void> _start(AppServices services, AppGate gate) async {
  try {
    await services.start();
    // After the app is up, and on its own: push never decides whether the app opens.
    // A build with no Firebase settings does nothing here.
    unawaited(services.startPush());
  } catch (error, stack) {
    // A broken local store must never leave the user on the launch screen.
    FlutterError.reportError(
      FlutterErrorDetails(exception: error, stack: stack, library: 'app start-up'),
    );
    gate.markBootFailed();
  }
}

Future<void> _registerBackground() async {
  try {
    await registerBackgroundSync();
  } catch (error, stack) {
    // Foreground sync still works without it, so this is only worth logging.
    FlutterError.reportError(
      FlutterErrorDetails(exception: error, stack: stack, library: 'background sync'),
    );
  }
}
