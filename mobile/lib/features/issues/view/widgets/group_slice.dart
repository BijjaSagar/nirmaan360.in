import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';

/// One item of a long, lazily built list drawn as a slice of a white group.
///
/// A `LedgerList` is a column, so every row of it is built at once. A BOQ or an
/// issue list can run to thousands of rows, so those lists put each section
/// heading and each row in one `SliverList.builder`, and every item draws its
/// own piece of the group: white, a hairline down both sides, rounded at the
/// top when it is the [first] item of a group and at the bottom when it is the
/// [last]. Laid end to end the slices read as the same card a `LedgerList`
/// draws. The last item of a group leaves [N360Space.cardGap] below it, which
/// is the space between groups.
///
/// Put the hairline between rows in [child]: a [RuledDivider] with
/// `indent: N360Space.card` above every row but the first of a group, and a
/// full-width one under the heading.
class GroupSlice extends StatelessWidget {
  const GroupSlice({super.key, required this.first, required this.last, required this.child});

  /// The first item of its group, so its top corners are round.
  final bool first;

  /// The last item of its group, so its bottom corners are round.
  final bool last;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        N360Space.gutter,
        0,
        N360Space.gutter,
        last ? N360Space.cardGap : 0,
      ),
      child: CustomPaint(
        painter: _SlicePainter(first: first, last: last),
        child: child,
      ),
    );
  }
}

/// Paints the card as if it were the whole group, then shows only this item's
/// part of it. A middle slice keeps just the two side hairlines.
class _SlicePainter extends CustomPainter {
  const _SlicePainter({required this.first, required this.last});

  final bool first;
  final bool last;

  /// Room past a joined edge, so two slices overlap by a pixel rather than
  /// leave a seam where a list sits on a fractional offset.
  static const double _overlap = 1;

  @override
  void paint(Canvas canvas, Size size) {
    const double r = N360Radius.card;
    canvas.save();
    canvas.clipRect(
      Rect.fromLTRB(
        0,
        first ? 0 : -_overlap,
        size.width,
        last ? size.height : size.height + _overlap,
      ),
    );
    // The card runs past a joined edge by its own corner radius, so the round
    // corners fall outside the clip and only the straight sides remain.
    final RRect card = RRect.fromLTRBR(
      0,
      first ? 0 : -r * 2,
      size.width,
      last ? size.height : size.height + r * 2,
      const Radius.circular(r),
    );
    canvas.drawRRect(card, Paint()..color = N360Colors.sheet);
    canvas.drawRRect(
      card.deflate(N360Size.hairline / 2),
      Paint()
        ..color = N360Colors.rule
        ..style = PaintingStyle.stroke
        ..strokeWidth = N360Size.hairline,
    );
    canvas.restore();
  }

  @override
  bool shouldRepaint(_SlicePainter oldDelegate) =>
      oldDelegate.first != first || oldDelegate.last != last;
}
