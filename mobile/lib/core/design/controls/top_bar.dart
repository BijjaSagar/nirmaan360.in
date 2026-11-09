import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// The one header every pushed screen wears: a labelled tile on the left for
/// the way back, the title centred with an optional line under it, and room
/// for one more labelled tile on the right. The title stays centred whichever
/// side is empty.
///
/// The top of the screen is not a thumb zone, so nothing up here is the main
/// action: Back, Settings, Change. Each is an [N360TopTile], an icon with its
/// word under it, the same idiom as the bottom bar, because an icon alone
/// means little to someone who reads little and nothing in bright sun.
class N360TopBar extends StatelessWidget {
  const N360TopBar({
    super.key,
    required this.backLabel,
    this.title,
    this.subtitle,
    this.onBack,
    this.showBack = true,
    this.trailing,
    this.padding = const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.sm),
  });

  /// The translated word under the back arrow. The design system carries no
  /// strings of its own, so the screen passes it.
  final String backLabel;

  /// Null leaves the middle empty, for a page still loading its name.
  final String? title;

  /// A small line under the title: the project, or what kind of page it is.
  final String? subtitle;

  /// What Back does. Null pops the nearest route when there is one to pop.
  final VoidCallback? onBack;

  /// False hides Back even when the route could pop, for a root tab.
  final bool showBack;

  /// A second [N360TopTile], or any compact control. Its slot is kept even
  /// when empty so the title stays centred.
  final Widget? trailing;

  final EdgeInsetsGeometry padding;

  /// Room each side keeps. A tile grows to fit its word but never past this.
  static const double sideWidth = N360Size.tap + N360Space.sm;

  @override
  Widget build(BuildContext context) {
    final NavigatorState? navigator = Navigator.maybeOf(context);
    final VoidCallback? back = !showBack
        ? null
        : onBack ?? ((navigator?.canPop() ?? false) ? () => navigator!.maybePop() : null);
    final String? heading = title;
    final String? sub = subtitle;

    return Padding(
      padding: padding,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: <Widget>[
          SizedBox(width: sideWidth, child: back == null ? null : N360BackTile(label: backLabel, onTap: back)),
          const SizedBox(width: N360Space.xs),
          Expanded(
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: N360Size.tap),
              child: heading == null
                  ? const SizedBox.shrink()
                  : Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: <Widget>[
                        Semantics(
                          header: true,
                          child: Text(
                            heading,
                            maxLines: 2,
                            textAlign: TextAlign.center,
                            overflow: TextOverflow.ellipsis,
                            style: N360Type.fit(N360Type.title, heading),
                          ),
                        ),
                        if (sub != null)
                          Text(
                            sub,
                            maxLines: 1,
                            textAlign: TextAlign.center,
                            overflow: TextOverflow.ellipsis,
                            style: N360Type.fit(N360Type.caption, sub),
                          ),
                      ],
                    ),
            ),
          ),
          const SizedBox(width: N360Space.xs),
          // The right slot matches the left so the title sits centred; a wider
          // status (two words of "saved on phone") may take the room it needs.
          if (trailing == null)
            const SizedBox(width: sideWidth)
          else
            ConstrainedBox(
              constraints: const BoxConstraints(minWidth: sideWidth),
              child: Center(widthFactor: 1, child: trailing),
            ),
        ],
      ),
    );
  }
}

/// The way back, as an [N360TopTile] with the arrow and the word "Back".
class N360BackTile extends StatelessWidget {
  const N360BackTile({super.key, required this.label, required this.onTap});

  /// The translated "Back".
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => N360TopTile(icon: Icons.arrow_back, label: label, onTap: onTap);
}

/// A compact header control: an icon with its word under it on a white tile
/// with a hairline, inside a full [N360Size.tap] target. The visible tile is
/// [N360Size.barTile] square at least and grows to fit a longer word rather
/// than shrink the word under [N360Type.minimumSize].
class N360TopTile extends StatelessWidget {
  const N360TopTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.badge,
  });

  final IconData icon;

  /// The word under the icon. Required: a header icon never stands alone.
  final String label;
  final VoidCallback? onTap;

  /// A short count on the corner, for a header tile that opens a list.
  final String? badge;

  @override
  Widget build(BuildContext context) {
    final String spoken = badge == null ? label : '$label, $badge';
    final TextStyle word = N360Type.fit(N360Type.caption, label).copyWith(color: N360Colors.ink, height: 1.15);

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      semanticsLabel: spoken,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: N360Space.xs),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(icon, size: N360IconSize.lg, color: N360Colors.ink),
            const SizedBox(height: 1),
            Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: word),
          ],
        ),
      ),
      builder: (context, depth, child) => Center(
        widthFactor: 1,
        child: Transform.scale(
          scale: pressedScale(depth),
          child: Stack(
            clipBehavior: Clip.none,
            children: <Widget>[
              DecoratedBox(
                decoration: BoxDecoration(
                  color: N360Colors.sheet,
                  borderRadius: N360Radius.md,
                  border: Border.all(color: N360Colors.rule),
                ),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(
                    minWidth: N360Size.barTile,
                    minHeight: N360Size.barTile,
                    maxWidth: N360TopBar.sideWidth,
                  ),
                  child: Center(widthFactor: 1, heightFactor: 1, child: child),
                ),
              ),
              if (badge != null) Positioned(top: -5, right: -5, child: _CornerCount(text: badge!)),
            ],
          ),
        ),
      ),
    );
  }
}

class _CornerCount extends StatelessWidget {
  const _CornerCount({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(color: N360Colors.primary, borderRadius: N360Radius.sm),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
        child: Text(
          text,
          style: N360Type.figureSmall.copyWith(
            fontSize: N360Type.minimumSize,
            height: 1.3,
            fontWeight: FontWeight.w600,
            color: N360Colors.onPrimary,
          ),
        ),
      ),
    );
  }
}
