import 'dart:math' as math;
import 'dart:ui' show lerpDouble;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';

import '../motion.dart';
import '../surfaces/ledger_paper.dart';
import '../tokens.dart';
import '../typography.dart';

/// A go_router page with the app's page turn: the ink slides sideways over the
/// paper, which stays put, and the page beneath drifts back a little.
///
/// ```dart
/// GoRoute(path: 'x', pageBuilder: (c, s) => N360Page(key: s.pageKey, child: X()));
/// ```
///
/// With [fullscreenDialog] the page rises from the bottom and settles, as a
/// sheet does, and the page beneath stays still.
class N360Page<T> extends Page<T> {
  const N360Page({
    required this.child,
    super.key,
    super.name,
    super.arguments,
    super.restorationId,
    this.fullscreenDialog = false,
  });

  final Widget child;
  final bool fullscreenDialog;

  @override
  Route<T> createRoute(BuildContext context) =>
      _N360PageRoute<T>(page: this, duration: context.motion.page);
}

class _N360PageRoute<T> extends PageRoute<T> {
  _N360PageRoute({required N360Page<T> page, required this.duration})
      : super(settings: page, fullscreenDialog: page.fullscreenDialog);

  /// Zero when the user has asked the system to remove animations.
  final Duration duration;

  N360Page<T> get _page => settings as N360Page<T>;

  @override
  Color? get barrierColor => null;

  @override
  String? get barrierLabel => null;

  @override
  bool get maintainState => true;

  @override
  Duration get transitionDuration => duration;

  /// The page beneath a sheet stays still: only a sideways page turn makes it
  /// drift back.
  @override
  bool canTransitionTo(TransitionRoute<dynamic> nextRoute) =>
      nextRoute is PageRoute<dynamic> && !nextRoute.fullscreenDialog;

  @override
  Widget buildPage(BuildContext context, Animation<double> animation, Animation<double> secondaryAnimation) {
    return Semantics(scopesRoute: true, explicitChildNodes: true, child: _page.child);
  }

  /// Whether a left-edge swipe may start to pop this route now, as Cupertino
  /// decides it: not the first route, not a sheet, nothing else animating.
  bool get _swipeEnabled {
    if (isFirst || willHandlePopInternally || fullscreenDialog) return false;
    if (animation!.status != AnimationStatus.completed) return false;
    if (secondaryAnimation!.status != AnimationStatus.dismissed) return false;
    if (popGestureInProgress) return false;
    return true;
  }

  /// Whether a `PopScope` on the page is holding the pop back, as it does
  /// while a form holds typed work.
  bool get _popHeld => popDisposition == RoutePopDisposition.doNotPop;

  @override
  Widget buildTransitions(
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    final bool cupertino = switch (Theme.of(context).platform) {
      TargetPlatform.iOS || TargetPlatform.macOS => true,
      _ => false,
    };
    Widget page = child;
    if (cupertino && !fullscreenDialog) {
      page = _BackSwipeDetector(
        canSwipe: () => _swipeEnabled && !_popHeld,
        canAskToLeave: () => _swipeEnabled && _popHeld,
        onStart: () => _BackSwipe(
          navigator: navigator!,
          controller: controller!,
          isCurrent: () => isCurrent,
          isActive: () => isActive,
        ),
        // A page holding typed work gets the same question a Back tap or the
        // Android back button would put to it, instead of a silent nothing.
        onAskToLeave: () => navigator!.maybePop(),
        child: child,
      );
    }
    return n360PageTransition(
      context,
      animation,
      secondaryAnimation,
      page,
      vertical: fullscreenDialog,
      linear: popGestureInProgress,
    );
  }
}

/// Width of the strip along the leading edge that starts a back swipe, as on
/// Cupertino.
const double _kBackSwipeWidth = 20;

/// Swipes faster than this, in screen widths per second, decide by direction.
const double _kMinFlingVelocity = 1;

/// Longest the page takes to finish going, or to come back, after a release.
const int _kMaxDroppedSwipeForwardMs = 800;
const int _kMaxBackMs = 300;

/// Listens along the leading edge of a page for the iOS back swipe and drives
/// the route's own animation with the finger, so the page tracks it. Android
/// keeps the system back and never builds this.
class _BackSwipeDetector extends StatefulWidget {
  const _BackSwipeDetector({
    required this.canSwipe,
    required this.canAskToLeave,
    required this.onStart,
    required this.onAskToLeave,
    required this.child,
  });

  /// Whether a swipe may pop the route right now.
  final ValueGetter<bool> canSwipe;

  /// Whether the route could pop but a `PopScope` is holding it. The page
  /// stays put and, on a committed swipe, is asked to leave as Back would ask.
  final ValueGetter<bool> canAskToLeave;
  final ValueGetter<_BackSwipe> onStart;
  final VoidCallback onAskToLeave;
  final Widget child;

  @override
  State<_BackSwipeDetector> createState() => _BackSwipeDetectorState();
}

class _BackSwipeDetectorState extends State<_BackSwipeDetector> {
  _BackSwipe? _swipe;
  bool _asking = false;
  double _askTravel = 0;
  late final HorizontalDragGestureRecognizer _recognizer = HorizontalDragGestureRecognizer(debugOwner: this)
    ..dragStartBehavior = DragStartBehavior.down
    ..onStart = _onStart
    ..onUpdate = _onUpdate
    ..onEnd = _onEnd
    ..onCancel = _onCancel;

  @override
  void dispose() {
    _recognizer.dispose();
    super.dispose();
  }

  void _pointerDown(PointerDownEvent event) {
    if (widget.canSwipe() || widget.canAskToLeave()) _recognizer.addPointer(event);
  }

  double _toLogical(double value) =>
      Directionality.of(context) == TextDirection.rtl ? -value : value;

  void _onStart(DragStartDetails details) {
    if (widget.canSwipe()) {
      _swipe = widget.onStart();
    } else if (widget.canAskToLeave()) {
      _asking = true;
      _askTravel = 0;
    }
  }

  void _onUpdate(DragUpdateDetails details) {
    final double width = context.size!.width;
    if (_swipe != null) {
      _swipe!.dragUpdate(_toLogical(details.primaryDelta! / width));
    } else if (_asking) {
      _askTravel += _toLogical(details.primaryDelta!);
    }
  }

  void _onEnd(DragEndDetails details) {
    final double width = context.size!.width;
    if (_swipe != null) {
      _swipe!.dragEnd(_toLogical(details.velocity.pixelsPerSecond.dx / width));
      _swipe = null;
    } else if (_asking) {
      _asking = false;
      final double velocity = _toLogical(details.velocity.pixelsPerSecond.dx / width);
      if (_askTravel > width / 3 || velocity >= _kMinFlingVelocity) widget.onAskToLeave();
    }
  }

  void _onCancel() {
    _swipe?.dragEnd(0);
    _swipe = null;
    _asking = false;
  }

  @override
  Widget build(BuildContext context) {
    final double strip = math.max(_kBackSwipeWidth, MediaQuery.paddingOf(context).left);
    return Stack(
      fit: StackFit.passthrough,
      children: <Widget>[
        widget.child,
        PositionedDirectional(
          start: 0,
          width: strip,
          top: 0,
          bottom: 0,
          child: Listener(onPointerDown: _pointerDown, behavior: HitTestBehavior.translucent),
        ),
      ],
    );
  }
}

/// One back swipe in flight: it owns the route's animation from the moment the
/// finger lands until the page has either gone or settled back.
class _BackSwipe {
  _BackSwipe({
    required this.navigator,
    required this.controller,
    required this.isCurrent,
    required this.isActive,
  }) {
    navigator.didStartUserGesture();
  }

  final NavigatorState navigator;
  final AnimationController controller;
  final ValueGetter<bool> isCurrent;
  final ValueGetter<bool> isActive;

  /// [delta] is a fraction of the page width, positive towards the trailing edge.
  void dragUpdate(double delta) {
    controller.value -= delta;
  }

  /// [velocity] is in page widths per second, positive towards the trailing edge.
  void dragEnd(double velocity) {
    const Curve curve = Curves.fastLinearToSlowEaseIn;
    final bool current = isCurrent();
    final bool stay;
    if (!current) {
      // Something popped the route under the finger; follow what it did.
      stay = isActive();
    } else if (velocity.abs() >= _kMinFlingVelocity) {
      stay = velocity <= 0;
    } else {
      stay = controller.value > 0.5;
    }

    if (stay) {
      final int ms = math.min(
        lerpDouble(_kMaxDroppedSwipeForwardMs, 0, controller.value)!.floor(),
        _kMaxBackMs,
      );
      controller.animateTo(1, duration: Duration(milliseconds: ms), curve: curve);
    } else {
      if (current) navigator.pop();
      if (controller.isAnimating) {
        final int ms = lerpDouble(0, _kMaxDroppedSwipeForwardMs, controller.value)!.floor();
        controller.animateBack(0, duration: Duration(milliseconds: ms), curve: curve);
      }
    }

    if (controller.isAnimating) {
      late final AnimationStatusListener done;
      done = (AnimationStatus status) {
        navigator.didStopUserGesture();
        controller.removeStatusListener(done);
      };
      controller.addStatusListener(done);
    } else {
      navigator.didStopUserGesture();
    }
  }
}

/// How far the page beneath drifts back while a new one slides over it.
const double _drift = 0.28;

/// The page transition, also installed in the theme so a plain `MaterialPageRoute`
/// matches. The incoming page carries its own paper while it moves, so the page
/// beneath can drift back a little without its text showing through, and
/// nothing fades. Once settled the backing is dropped and the shared paper
/// shows again.
Widget n360PageTransition(
  BuildContext context,
  Animation<double> animation,
  Animation<double> secondaryAnimation,
  Widget child, {
  bool vertical = false,
  bool linear = false,
}) {
  if (context.motion.reduced) return child;
  final bool hasRoot = LedgerPaper.hasRoot(context);
  Widget page = hasRoot
      ? ValueListenableBuilder<Offset>(
          valueListenable: paperJolt,
          builder: (context, offset, child) => Transform.translate(offset: offset, child: child),
          child: child,
        )
      : child;
  if (hasRoot) {
    page = _MovingPaper(animation: animation, secondary: secondaryAnimation, child: page);
  }
  if (vertical) {
    return SlideTransition(
      position: animation.drive(_riseIn),
      child: CustomPaint(
        foregroundPainter: _SeamPainter(animation: animation, secondary: secondaryAnimation, vertical: true),
        child: page,
      ),
    );
  }
  // While a finger drags the page the curve comes off, so the page sits
  // exactly under the finger.
  return SlideTransition(
    position: animation.drive(linear ? _slideInLinear : _slideIn),
    child: SlideTransition(
      position: secondaryAnimation.drive(linear ? _slideOutLinear : _slideOut),
      child: CustomPaint(
        foregroundPainter: _SeamPainter(animation: animation, secondary: secondaryAnimation),
        child: page,
      ),
    ),
  );
}

final Animatable<Offset> _slideInLinear = Tween<Offset>(begin: const Offset(1, 0), end: Offset.zero);
final Animatable<Offset> _slideOutLinear = Tween<Offset>(begin: Offset.zero, end: const Offset(-_drift, 0));
final Animatable<Offset> _slideIn = _slideInLinear.chain(CurveTween(curve: N360Curves.rise));
final Animatable<Offset> _slideOut = _slideOutLinear.chain(CurveTween(curve: N360Curves.rise));
final Animatable<Offset> _riseIn = Tween<Offset>(begin: const Offset(0, 1), end: Offset.zero)
    .chain(CurveTween(curve: N360Curves.settle));

/// The installed theme's page transitions builder.
class N360PageTransitionsBuilder extends PageTransitionsBuilder {
  const N360PageTransitionsBuilder();

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    return n360PageTransition(context, animation, secondaryAnimation, child, vertical: route.fullscreenDialog);
  }
}

/// Paints the page gradient behind a route only while it is moving. Static
/// routes pay nothing: the painter is removed once both animations rest.
class _MovingPaper extends StatelessWidget {
  const _MovingPaper({required this.animation, required this.secondary, required this.child});

  final Animation<double> animation;
  final Animation<double> secondary;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: Listenable.merge(<Listenable>[animation, secondary]),
      child: child,
      builder: (context, child) {
        final bool moving = animation.value < 1 || secondary.value > 0;
        if (!moving) return child!;
        return CustomPaint(painter: const _PaperBackingPainter(), child: child);
      },
    );
  }
}

class _PaperBackingPainter extends CustomPainter {
  const _PaperBackingPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final Rect rect = Offset.zero & size;
    canvas.drawRect(rect, Paint()..shader = N360Gradients.page.createShader(rect));
  }

  @override
  bool shouldRepaint(_PaperBackingPainter oldDelegate) => false;
}

/// A hairline at the leading edge of a page while it is moving: the edge of the
/// sheet. It does not exist once the page has settled.
class _SeamPainter extends CustomPainter {
  _SeamPainter({required this.animation, required this.secondary, this.vertical = false})
      : super(repaint: Listenable.merge(<Listenable>[animation, secondary]));

  final Animation<double> animation;
  final Animation<double> secondary;
  final bool vertical;

  @override
  void paint(Canvas canvas, Size size) {
    final bool moving = animation.value < 1 || secondary.value > 0;
    if (!moving) return;
    final Rect edge = vertical ? Rect.fromLTWH(0, 0, size.width, 1) : Rect.fromLTWH(0, 0, 1, size.height);
    canvas.drawRect(edge, Paint()..color = N360Colors.rule);
  }

  @override
  bool shouldRepaint(_SeamPainter oldDelegate) => oldDelegate.vertical != vertical;
}

/// A list row that flies up into a detail header. The shuttle carries a sheet
/// behind the text and scales the larger of the two layouts, so words stay
/// whole and legible for the entire flight instead of cross-fading.
class LedgerHero extends StatelessWidget {
  const LedgerHero({super.key, required this.tag, required this.child});

  final Object tag;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (context.motion.reduced) return child;
    return Hero(
      tag: tag,
      flightShuttleBuilder: _shuttle,
      createRectTween: (begin, end) => RectTween(begin: begin, end: end),
      child: child,
    );
  }

  static Widget _shuttle(
    BuildContext flightContext,
    Animation<double> animation,
    HeroFlightDirection direction,
    BuildContext fromContext,
    BuildContext toContext,
  ) {
    final RenderBox fromBox = fromContext.findRenderObject()! as RenderBox;
    final RenderBox toBox = toContext.findRenderObject()! as RenderBox;
    final bool useTo = toBox.size.height >= fromBox.size.height;
    final Hero source = (useTo ? toContext : fromContext).widget as Hero;
    final Size sourceSize = (useTo ? toBox : fromBox).size;

    return Material(
      type: MaterialType.transparency,
      child: DefaultTextStyle(
        style: N360Type.body,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            border: Border.all(color: N360Colors.cardEdge),
            borderRadius: N360Radius.md,
          ),
          child: ClipRect(
            child: FittedBox(
              fit: BoxFit.contain,
              alignment: Alignment.centerLeft,
              child: SizedBox.fromSize(size: sourceSize, child: source.child),
            ),
          ),
        ),
      ),
    );
  }
}
