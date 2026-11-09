import 'package:flutter/material.dart';

import '../tokens.dart';

/// The foot of a screen that has a main action: a white bar with a hairline
/// along its top, the action inside it in the thumb zone, and the system
/// inset below. The body scrolls above it and never under it, so the page
/// ends in a bar rather than in a button floating over empty paper.
///
/// Keep the screen's `SafeArea` off at the bottom: the bar pads for the home
/// indicator itself so its white runs down to the edge of the glass.
class N360ActionBar extends StatelessWidget {
  const N360ActionBar({super.key, required this.child, this.above});

  /// Usually one large `GloveButton`, or a row of two.
  final Widget child;

  /// A short line or slip above the action, inside the bar: a helper, an
  /// error, a note that the phone is offline.
  final Widget? above;

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.sheet,
        border: Border(top: BorderSide(color: N360Colors.rule)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(N360Space.gutter, N360Space.tile, N360Space.gutter, N360Space.tile + safe),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (above != null) ...<Widget>[above!, const SizedBox(height: N360Space.sm)],
            child,
          ],
        ),
      ),
    );
  }
}
