import 'dart:async';

import 'package:flutter/foundation.dart' show visibleForTesting;
import 'package:geolocator/geolocator.dart';

import '../data/selfie_models.dart';

/// Why the phone could not say where it is.
enum LocationProblem {
  /// The phone's location is switched off.
  off,

  /// The person said no to this app.
  denied,

  /// The person said no and asked not to be asked again, so only the phone's settings can change it.
  deniedForever,

  /// No reading came in time, or the reading cannot be trusted (a mock location).
  failed,
}

/// A reading, or the reason there is none.
class LocationResult {
  const LocationResult.found(SelfieFix this.fix) : problem = null;

  const LocationResult.lacking(LocationProblem this.problem) : fix = null;

  final SelfieFix? fix;
  final LocationProblem? problem;
}

/// Where the phone is. Asked once, when a photo of a worker is taken, and never
/// again: nothing here follows the phone or runs in the background. Only "when in
/// use" permission is ever requested.
class SelfieLocator {
  const SelfieLocator();

  /// For tests: a stand-in for the phone's location.
  @visibleForTesting
  static Future<LocationResult> Function()? stand;

  /// How long to wait for a reading before saying there is none.
  static const Duration wait = Duration(seconds: 20);

  Future<LocationResult> locate() async {
    final stand = SelfieLocator.stand;
    if (stand != null) return stand();
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return const LocationResult.lacking(LocationProblem.off);
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.deniedForever) return const LocationResult.lacking(LocationProblem.deniedForever);
      if (permission == LocationPermission.denied || permission == LocationPermission.unableToDetermine) {
        return const LocationResult.lacking(LocationProblem.denied);
      }
      final Position position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: wait),
      );
      // A reading made up by a mock-location app proves nothing about where the photo was taken.
      if (position.isMocked) return const LocationResult.lacking(LocationProblem.failed);
      return LocationResult.found(
        SelfieFix(
          lat: position.latitude.toStringAsFixed(7),
          lng: position.longitude.toStringAsFixed(7),
          accuracy: position.accuracy.toStringAsFixed(1),
        ),
      );
    } on Object {
      // A timeout, a service that went away, a platform error: there is no reading.
      return const LocationResult.lacking(LocationProblem.failed);
    }
  }
}
