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

/// Bottom navigation: a white bar with a hairline along its top. A quiet
/// page-toned tile slides to the chosen item and settles. Every icon keeps its
/// word underneath.
///
/// Each item is a full [N360Size.tap] target; the painted tile behind the
/// active one is [N360Size.barTile] high.
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

  @override
  Widget build(BuildContext context) {
    final double safe = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.sheet,
        border: Border(top: BorderSide(color: N360Colors.rule)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(N360Space.sm, 0, N360Space.sm, safe),
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
        return RepaintBoundary(
          child: TweenAnimationBuilder<double>(
            tween: Tween<double>(end: index.toDouble()),
            duration: context.motion.bar,
            curve: N360Curves.settle,
            builder: (context, value, child) => Transform.translate(
              offset: Offset(value * cell, 0),
              child: child,
            ),
            child: Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: cell,
                height: N360Size.barTile,
                child: const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 4),
                  child: CustomPaint(
                    painter: SoftSurfacePainter(radius: N360Radius.medium, fill: N360Colors.paper),
                    child: SizedBox.expand(),
                  ),
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
    final Color ink = selected ? N360Colors.laterite : N360Colors.ink2;
    final String? badge = item.badge;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: N360Size.bar,
      minWidth: 0,
      semanticsLabel: badge == null ? item.label : '${item.label}, $badge',
      selected: selected,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(4, 4, 4, 4),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Stack(
              clipBehavior: Clip.none,
              children: <Widget>[
                Icon(item.icon, size: N360IconSize.bar, color: ink),
                if (badge != null)
                  Positioned(
                    top: -6,
                    right: -12,
                    child: N360Badge(text: badge),
                  ),
              ],
            ),
            const SizedBox(height: 2),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                item.label,
                maxLines: 1,
                style: N360Type.fit(N360Type.caption, item.label).copyWith(
                  color: ink,
                  height: 1.25,
                  fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
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
