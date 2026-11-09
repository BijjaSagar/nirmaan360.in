import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';
import 'action_tile.dart';

@immutable
class N360BarItem {
  const N360BarItem({required this.icon, required this.label, this.badge});

  final IconData icon;
  final String label;

  /// A short count on the corner of the icon. Null hides it.
  final String? badge;
}

/// Bottom navigation: a floating rounded bar in the thumb's arc. A gold tile
/// slides to the chosen item. Every icon keeps its word underneath.
class N360BottomBar extends StatelessWidget {
  const N360BottomBar({
    super.key,
    required this.items,
    required this.currentIndex,
    required this.onTap,
  });

  final List<N360BarItem> items;
  final int currentIndex;
  final ValueChanged<int> onTap;

  static const double _inset = 6;

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(N360Space.gutter - 4, N360Space.sm, N360Space.gutter - 4, N360Space.sm + safe),
      child: CustomPaint(
        painter: const SoftSurfacePainter(radius: 26, edge: N360Colors.cardEdge, shadow: true),
        child: Padding(
          padding: const EdgeInsets.all(_inset),
          child: SizedBox(
            height: N360Size.bar,
            child: Stack(
              children: <Widget>[
                Positioned.fill(
                  child: _SlidingTile(index: currentIndex, count: items.length),
                ),
                Positioned.fill(
                  child: Row(
                    children: <Widget>[
                      for (var i = 0; i < items.length; i++)
                        Expanded(
                          child: _BarItem(
                            item: items[i],
                            selected: i == currentIndex,
                            onTap: () {
                              if (i != currentIndex) onTap(i);
                            },
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SlidingTile extends StatelessWidget {
  const _SlidingTile({required this.index, required this.count});

  final int index;
  final int count;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final double cell = constraints.maxWidth / count;
        return TweenAnimationBuilder<double>(
          tween: Tween<double>(end: index.toDouble()),
          duration: context.motion.quick + context.motion.quick,
          curve: N360Curves.rise,
          builder: (context, value, child) => Transform.translate(
            offset: Offset(value * cell, 0),
            child: child,
          ),
          child: Align(
            alignment: Alignment.centerLeft,
            child: SizedBox(
              width: cell,
              height: double.infinity,
              child: const Padding(
                padding: EdgeInsets.symmetric(horizontal: 3),
                child: CustomPaint(
                  painter: SoftSurfacePainter(
                    radius: N360Radius.tile,
                    gradient: N360Gradients.gold,
                    edge: Color(0x55FFFFFF),
                  ),
                  child: SizedBox.expand(),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _BarItem extends StatelessWidget {
  const _BarItem({required this.item, required this.selected, required this.onTap});

  final N360BarItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final Color ink = selected ? N360Colors.ink : N360Colors.inkLabel;
    final String? badge = item.badge;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: N360Size.bar,
      minWidth: 0,
      semanticsLabel: badge == null ? item.label : '${item.label}, $badge',
      selected: selected,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(4, 6, 4, 4),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Stack(
              clipBehavior: Clip.none,
              children: <Widget>[
                Icon(item.icon, size: 25, color: ink),
                if (badge != null)
                  Positioned(
                    top: -6,
                    right: -14,
                    child: N360Badge(text: badge),
                  ),
              ],
            ),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                item.label,
                maxLines: 1,
                style: N360Type.caption.copyWith(
                  color: ink,
                  fontSize: 13,
                  height: 1.3,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
      ),
      builder: (context, depth, child) => Transform.scale(scale: pressedScale(depth), child: child),
    );
  }
}
