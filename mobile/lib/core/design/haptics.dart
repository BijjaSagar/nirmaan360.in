import 'package:flutter/services.dart';

/// One vocabulary for haptics, so a press feels the same on every screen.
abstract final class N360Haptics {
  /// A key or button press.
  static Future<void> tap() => HapticFeedback.lightImpact();

  /// A choice changed: chip, segment, tab.
  static Future<void> select() => HapticFeedback.selectionClick();

  /// One stepper increment.
  static Future<void> step() => HapticFeedback.selectionClick();

  /// Something finished well.
  static Future<void> success() => HapticFeedback.mediumImpact();

  /// Something needs a look: two short pulses.
  static Future<void> warning() async {
    await HapticFeedback.mediumImpact();
    await Future<void>.delayed(const Duration(milliseconds: 90));
    await HapticFeedback.mediumImpact();
  }

  /// A rubber stamp landing.
  static Future<void> thud() => HapticFeedback.heavyImpact();
}
