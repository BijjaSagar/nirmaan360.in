import 'package:flutter/material.dart';

import '../motion.dart';
import '../surfaces/ledger_paper.dart';
import '../tokens.dart';
import '../typography.dart';

/// A go_router page with the app's page turn: the ink slides sideways over the
/// paper, which stays put.
///
/// ```dart
/// GoRoute(path: 'x', pageBuilder: (c, s) => N360Page(key: s.pageKey, child: X()));
/// ```
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
  Route<T> createRoute(BuildContext context) => _N360PageRoute<T>(page: this);
}

class _N360PageRoute<T> extends PageRoute<T> {
  _N360PageRoute({required N360Page<T> page}) : super(settings: page, fullscreenDialog: page.fullscreenDialog);

  N360Page<T> get _page => settings as N360Page<T>;

  @override
  Color? get barrierColor => null;

  @override
  String? get barrierLabel => null;

  @override
  bool get maintainState => true;

  @override
  Duration get transitionDuration => N360Durations.page;

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
    return n360PageTransition(context, animation, secondaryAnimation, child);
  }
}

/// The page transition, also installed in the theme so a plain `MaterialPageRoute`
/// matches. Pages move by exactly one screen so outgoing and incoming text never
/// overlap on the shared paper, and nothing fades.
Widget n360PageTransition(
  BuildContext context,
  Animation<double> animation,
  Animation<double> secondaryAnimation,
  Widget child,
) {
  if (context.motion.reduced) return child;
  final Widget page = LedgerPaper.hasRoot(context)
      ? ValueListenableBuilder<Offset>(
          valueListenable: paperJolt,
          builder: (context, offset, child) => Transform.translate(offset: offset, child: child),
          child: child,
        )
      : child;
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
final Animatable<Offset> _slideOut = Tween<Offset>(begin: Offset.zero, end: const Offset(-1, 0))
    .chain(CurveTween(curve: N360Curves.rise));

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
    return n360PageTransition(context, animation, secondaryAnimation, child);
  }
}

/// A hairline at the leading edge of a page while it is moving: the edge of the
/// sheet. It does not exist once the page has settled.
class _SeamPainter extends CustomPainter {
  _SeamPainter({required this.animation, required this.secondary})
      : super(repaint: Listenable.merge(<Listenable>[animation, secondary]));

  final Animation<double> animation;
  final Animation<double> secondary;

  @override
  void paint(Canvas canvas, Size size) {
    final bool moving = animation.value < 1 || secondary.value > 0;
    if (!moving) return;
    canvas.drawRect(Rect.fromLTWH(0, 0, 1, size.height), Paint()..color = N360Colors.ruleStrong);
  }

  @override
  bool shouldRepaint(_SeamPainter oldDelegate) => false;
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
            border: Border.all(color: N360Colors.rule),
            borderRadius: N360Radius.sm,
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
