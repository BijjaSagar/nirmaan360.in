import 'package:flutter/widgets.dart';

/// Curves shared with the web app's keyframes, plus the ones the app adds.
abstract final class N360Curves {
  /// Web `rise` and `rule`: `cubic-bezier(.2, .7, .2, 1)`.
  static const Curve rise = Cubic(0.2, 0.7, 0.2, 1);

  /// Web `stamp`: `cubic-bezier(.3, 1.5, .5, 1)`. Overshoots past 1 and returns.
  static const Curve stamp = Cubic(0.3, 1.5, 0.5, 1);

  /// Accelerates into the paper for the full-screen slam.
  static const Curve slam = Cubic(0.6, 0, 0.95, 0.5);

  /// Odometer columns coasting into place.
  static const Curve roll = Cubic(0.2, 0.75, 0.25, 1);

  static const Curve press = Curves.easeOut;
}

/// Base durations. Read them through `context.motion` so that reduced motion
/// resolves them to zero.
abstract final class N360Durations {
  static const Duration press = Duration(milliseconds: 60);
  static const Duration release = Duration(milliseconds: 110);
  static const Duration quick = Duration(milliseconds: 140);
  static const Duration roll = Duration(milliseconds: 260);
  static const Duration rise = Duration(milliseconds: 360);
  static const Duration stamp = Duration(milliseconds: 450);
  static const Duration rule = Duration(milliseconds: 560);
  static const Duration page = Duration(milliseconds: 320);
  static const Duration tick = Duration(milliseconds: 280);
  static const Duration slipHold = Duration(milliseconds: 240);
  static const Duration slipOut = Duration(milliseconds: 260);
  static const Duration toast = Duration(milliseconds: 240);

  /// One stagger step for `StaggeredRise`.
  static const Duration riseStep = Duration(milliseconds: 34);

  /// Total length of the full-screen stamp moment.
  static const Duration thud = Duration(milliseconds: 880);

  /// How long the stamp stays when motion is reduced and nothing moves.
  static const Duration thudStill = Duration(milliseconds: 640);
}

/// Durations resolved against the user's accessibility setting.
@immutable
class N360Motion {
  const N360Motion._(this.reduced);

  static const N360Motion standard = N360Motion._(false);
  static const N360Motion none = N360Motion._(true);

  /// True when `MediaQuery.disableAnimations` is set.
  final bool reduced;

  /// Resolves any duration: zero when motion is reduced.
  Duration of(Duration base) => reduced ? Duration.zero : base;

  Duration get press => of(N360Durations.press);
  Duration get release => of(N360Durations.release);
  Duration get quick => of(N360Durations.quick);
  Duration get roll => of(N360Durations.roll);
  Duration get rise => of(N360Durations.rise);
  Duration get stamp => of(N360Durations.stamp);
  Duration get rule => of(N360Durations.rule);
  Duration get page => of(N360Durations.page);
  Duration get tick => of(N360Durations.tick);
  Duration get slipHold => of(N360Durations.slipHold);
  Duration get slipOut => of(N360Durations.slipOut);
  Duration get toast => of(N360Durations.toast);
  Duration get riseStep => of(N360Durations.riseStep);
  Duration get thud => of(N360Durations.thud);
}

extension N360MotionContext on BuildContext {
  /// The motion spec for this widget: zero durations when the user has asked
  /// the system to remove animations. Every animated widget reads this.
  N360Motion get motion {
    final reduced = MediaQuery.maybeDisableAnimationsOf(this) ?? false;
    return reduced ? N360Motion.none : N360Motion.standard;
  }
}
