import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

@immutable
class N360BarItem {
  const N360BarItem({required this.icon, required this.label});

  final IconData icon;
  final String label;
}

/// Bottom navigation: icon and word together, 64dp, in the thumb's arc. A laterite
/// rule slides along the top edge to the chosen item.
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
    return ColoredBox(
      color: N360Colors.sheet,
      child: Padding(
        padding: EdgeInsets.only(bottom: safe),
        child: SizedBox(
          height: N360Size.bar,
          child: Stack(
            children: <Widget>[
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
              const Positioned(
                top: 0,
                left: 0,
                right: 0,
                child: SizedBox(height: 1, child: ColoredBox(color: N360Colors.ruleStrong)),
              ),
              Positioned.fill(
                bottom: null,
                child: _SlidingRule(index: currentIndex, count: items.length),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SlidingRule extends StatelessWidget {
  const _SlidingRule({required this.index, required this.count});

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
          builder: (context, value, _) => Transform.translate(
            offset: Offset(value * cell, 0),
            child: Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: cell,
                height: 3,
                child: const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 12),
                  child: ColoredBox(color: N360Colors.laterite),
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
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: N360Size.bar,
      minWidth: 0,
      semanticsLabel: item.label,
      selected: selected,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.xxs, 5, N360Space.xxs, 3),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(item.icon, size: 25, color: ink),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                item.label,
                maxLines: 1,
                style: N360Type.caption.copyWith(
                  color: ink,
                  fontSize: 14,
                  height: 1.3,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
      ),
      builder: (context, depth, child) => Transform.translate(offset: Offset(0, depth * 2), child: child),
    );
  }
}
