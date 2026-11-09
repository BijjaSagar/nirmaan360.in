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
      nextRoute is! PageRoute<dynamic> || !nextRoute.fullscreenDialog;

  @override
  Widget buildPage(BuildContext context, Animation<double> animation, Animation<double> secondaryAnimation) {
    return Semantics(scopesRoute: true, explicitChildNodes: true, child: _page.child);
  }

  @override
  Widget buildTransitions(
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    return n360PageTransition(context, animation, secondaryAnimation, child, vertical: fullscreenDialog);
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
  return SlideTransition(
    position: animation.drive(_slideIn),
    child: SlideTransition(
      position: secondaryAnimation.drive(_slideOut),
      child: CustomPaint(
        foregroundPainter: _SeamPainter(animation: animation, secondary: secondaryAnimation),
        child: page,
      ),
    ),
  );
}

final Animatable<Offset> _slideIn = Tween<Offset>(begin: const Offset(1, 0), end: Offset.zero)
    .chain(CurveTween(curve: N360Curves.rise));
final Animatable<Offset> _slideOut = Tween<Offset>(begin: Offset.zero, end: const Offset(-_drift, 0))
    .chain(CurveTween(curve: N360Curves.rise));
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
