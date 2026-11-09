import 'dart:async';

import 'package:flutter/material.dart';

import '../motion.dart';

/// Lifts a child into place with a staggered translate. It is the web's `rise`.
///
/// Nothing fades: the child is fully painted and readable at frame 0, 18dp below
/// its final position, so a stalled animation can never hide content. Past
/// [maxAnimated] children render in place, and anything built while its list is
/// already scrolled stays put, so scrolling back never replays the entrance.
class StaggeredRise extends StatefulWidget {
  const StaggeredRise({
    super.key,
    required this.index,
    required this.child,
    this.distance = 18,
    this.maxAnimated = 10,
  });

  final int index;
  final Widget child;
  final double distance;

  /// The stagger stops growing at this index and later children do not move.
  final int maxAnimated;

  @override
  State<StaggeredRise> createState() => _StaggeredRiseState();
}

class _StaggeredRiseState extends State<StaggeredRise> with SingleTickerProviderStateMixin {
  AnimationController? _rise;
  Timer? _delay;
  bool _decided = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_decided) return;
    _decided = true;
    final motion = context.motion;
    if (motion.reduced || widget.index >= widget.maxAnimated) return;

    final position = Scrollable.maybeOf(context)?.position;
    final bool scrolled = position != null && position.hasPixels && position.pixels > 1;
    if (scrolled) return;

    final rise = AnimationController(vsync: this, duration: motion.rise);
    _rise = rise;
    final Duration wait = motion.riseStep * widget.index;
    if (wait == Duration.zero) {
      rise.forward();
    } else {
      _delay = Timer(wait, () {
        if (mounted) rise.forward();
      });
    }
  }

  @override
  void dispose() {
    _delay?.cancel();
    _rise?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final rise = _rise;
    if (rise == null) return widget.child;
    return AnimatedBuilder(
      animation: rise,
      child: RepaintBoundary(child: widget.child),
      builder: (context, child) {
        final double remaining = 1 - N360Curves.rise.transform(rise.value);
        return Transform.translate(offset: Offset(0, widget.distance * remaining), child: child);
      },
    );
  }
}
