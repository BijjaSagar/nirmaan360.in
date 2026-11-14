import 'package:flutter/widgets.dart';

/// The motion language of Nirmaan360.
///
/// ## What motion means here
///
/// Everything on a construction site is placed, lifted, filled or stamped.
/// Motion in the app says the same things, and only those things:
///
/// * **Land.** A control sinks a little under the thumb ([N360Durations.press])
///   and comes back ([N360Durations.release]). A status stamp lands with a
///   thud ([N360Curves.stamp], [N360Durations.stamp]). That thud is the one
///   signature motion; nothing else is allowed to be louder than it.
/// * **Rise.** Content enters by moving up into place ([N360Curves.rise],
///   [N360Durations.rise]), rows a few frames apart ([N360Durations.riseStep]).
///   Pages slide in from the side ([N360Durations.page]) over the shared paper,
///   and the page beneath drifts back a little, as a sheet does when another is
///   laid over it. Bottom sheets rise and settle ([N360Curves.settle]).
/// * **Fill.** Progress bars, gauges and the building drawing fill to their
///   value once, on entry ([N360Durations.fill], [N360Durations.build]). The
///   hero figure counts up once ([N360Durations.count]). After that they only
///   move when the value does.
/// * **Roll.** Figures that change roll like an odometer
///   ([N360Curves.roll], [N360Durations.roll]). The active tile on the bottom
///   bar slides to the chosen item.
///
/// Nothing floats, pulses, bounces for attention or fades in and out. A
/// loading placeholder is still paper with one slow sheen; it does not spin.
///
/// ## Rules that bind
///
/// * Text is never hidden behind an animation. Entrances move content with
///   transforms; they never fade text in from zero, and a figure is painted at
///   frame 0 even while its digits roll.
/// * `MediaQuery.disableAnimations` gives the final state at once. Every
///   animated widget reads [N360MotionContext.motion] and gets zero durations.
/// * It must hold 60fps on a ten-thousand-rupee Android phone: transforms over
///   layout, a `RepaintBoundary` around anything that ticks, one painter per
///   animated figure, no blur, no backdrop filter, no animated opacity on a
///   large subtree.
///
/// ## Choreography of a screen entry
///
/// Everything starts at t = 0 and nothing waits on anything else, so the screen
/// is whole within about a second:
///
/// ```text
/// 0ms      page slides in over the paper                 (page, 320)
/// 0ms      rows rise, 34ms apart, at most ten of them    (rise 360 + step)
/// 0ms      bars, gauges and the square percent fill      (fill, 620)
/// 0ms      the hero figure counts up                     (count, 720)
/// 0ms      the building rises floor by floor             (build, 1150)
/// ```
///
/// A moment that confirms work (a stamp, a tick on a slip, the sync finishing)
/// happens after the user's action and holds just long enough to be seen
/// ([N360Durations.slipHold]) before it leaves ([N360Durations.slipOut]).
abstract final class N360Curves {
  /// Everything that moves into place. Web `rise` and `rule`:
  /// `cubic-bezier(.2, .7, .2, 1)`.
  static const Curve rise = Cubic(0.2, 0.7, 0.2, 1);

  /// A stamp landing. Web `stamp`: `cubic-bezier(.3, 1.5, .5, 1)`. Overshoots
  /// past 1 and returns.
  static const Curve stamp = Cubic(0.3, 1.5, 0.5, 1);

  /// Accelerates into the paper for the full-screen slam.
  static const Curve slam = Cubic(0.6, 0, 0.95, 0.5);

  /// Odometer columns coasting into place.
  static const Curve roll = Cubic(0.2, 0.75, 0.25, 1);

  /// A sheet rising and settling with the smallest overshoot. Used for bottom
  /// sheets and for the bottom-bar tile.
  static const Curve settle = Cubic(0.22, 1.08, 0.36, 1);

  /// Anything leaving the screen: quick to go, no bounce.
  static const Curve exit = Curves.easeInCubic;

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

  /// A bar, gauge or square percent filling to its value on entry.
  static const Duration fill = Duration(milliseconds: 620);

  /// The hero figure counting up on entry.
  static const Duration count = Duration(milliseconds: 720);

  /// The building drawing rising floor by floor on entry.
  static const Duration build = Duration(milliseconds: 1150);

  /// A bottom sheet rising into place.
  static const Duration sheet = Duration(milliseconds: 380);

  /// The active tile sliding along the bottom bar.
  static const Duration bar = Duration(milliseconds: 280);

  /// One pass of the sheen across a loading placeholder.
  static const Duration sheen = Duration(milliseconds: 1400);

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
  Duration get fill => of(N360Durations.fill);
  Duration get count => of(N360Durations.count);
  Duration get build => of(N360Durations.build);
  Duration get sheet => of(N360Durations.sheet);
  Duration get bar => of(N360Durations.bar);
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

/// The shrink a soft tile makes under the thumb: 0 at rest, 1 fully pressed.
/// Shared by every pressable surface so a press feels the same everywhere.
double pressedScale(double depth) => 1 - 0.035 * depth;
