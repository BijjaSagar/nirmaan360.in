import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../motion.dart';
import '../tokens.dart';
import '../typography.dart';

/// The one top bar every screen wears, tabs and pushed screens alike: a real
/// full-width bar that includes the status-bar area, with a labelled Back tile
/// on the left where there is somewhere to go back to, the title centred (or
/// a screen's own title block on the left), and room for one more labelled
/// tile on the right.
///
/// It is painted in [N360Chrome.topBar]'s tone, so the owner's choice between
/// a white bar and a brand bar is one switch. The top of the screen is not a
/// thumb zone, so nothing up here is the main action: Back, Settings, Change.
/// Each is an [N360TopTile], an icon with its word under it, the same idiom as
/// the bottom bar, because an icon alone means little to someone who reads
/// little and nothing in bright sun.
///
/// Put it first in a screen's column and keep the screen's `SafeArea` off at
/// the top: the bar consumes the status-bar inset itself so the bar colour
/// runs up behind the clock.
class N360TopBar extends StatelessWidget {
  const N360TopBar({
    super.key,
    required this.backLabel,
    this.title,
    this.subtitle,
    this.titleWidget,
    this.centerTitle = true,
    this.onBack,
    this.showBack = true,
    this.leading,
    this.trailing,
    this.reserveTrailing = true,
    this.statusBar = true,
  });

  /// The translated word under the back arrow. The design system carries no
  /// strings of its own, so the screen passes it.
  final String backLabel;

  /// Null leaves the middle empty, for a page still loading its name.
  final String? title;

  /// A small line under the title: the project, or what kind of page it is.
  final String? subtitle;

  /// A screen's own title block, in place of [title] and [subtitle]. Home
  /// uses it for the project name and switcher.
  final Widget? titleWidget;

  /// Centred between two equal slots, or flush left after the back tile.
  final bool centerTitle;

  /// What Back does. Null pops the nearest route when there is one to pop.
  final VoidCallback? onBack;

  /// False hides Back even when the route could pop, for a root tab.
  final bool showBack;

  /// Shown in the left slot when there is no Back: the brand mark on a root
  /// tab, for instance. Never an action without a word.
  final Widget? leading;

  /// A second [N360TopTile], a status, or any compact control.
  final Widget? trailing;

  /// With [centerTitle], keeps the right slot even when [trailing] is null so
  /// the title stays centred. A screen whose title block needs the room (the
  /// BOQ line chooser) turns it off.
  final bool reserveTrailing;

  /// Whether the bar paints the status-bar inset above its row.
  final bool statusBar;

  /// The bar's row below the status inset.
  static const double rowHeight = N360Size.tap;

  /// Room each side keeps. A tile grows to fit its word but never past this.
  static const double sideWidth = N360Size.tap + N360Space.sm;

  /// The bar's height below the status inset.
  static const double height = rowHeight + 2 * N360Space.xs;

  @override
  Widget build(BuildContext context) {
    final N360BarTone tone = N360Chrome.topBar;
    final NavigatorState? navigator = Navigator.maybeOf(context);
    final VoidCallback? back = !showBack
        ? null
        : onBack ?? ((navigator?.canPop() ?? false) ? () => navigator!.maybePop() : null);
    final double inset = statusBar ? MediaQuery.paddingOf(context).top : 0;

    final Widget? left = back != null ? N360BackTile(label: backLabel, onTap: back) : leading;
    final bool leftSlot = left != null || centerTitle;
    final Widget? right = trailing;
    final bool rightSlot = right != null || (centerTitle && reserveTrailing);

    final String? heading = title;
    final String? sub = subtitle;
    final Widget middle = titleWidget ??
        (heading == null
            ? const SizedBox.shrink()
            : Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: centerTitle ? CrossAxisAlignment.center : CrossAxisAlignment.start,
                children: <Widget>[
                  Semantics(
                    header: true,
                    child: Text(
                      heading,
                      maxLines: 2,
                      textAlign: centerTitle ? TextAlign.center : TextAlign.start,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.fit(N360Type.title, heading).copyWith(color: tone.ink),
                    ),
                  ),
                  if (sub != null)
                    Text(
                      sub,
                      maxLines: 1,
                      textAlign: centerTitle ? TextAlign.center : TextAlign.start,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.fit(N360Type.caption, sub).copyWith(color: tone.inkSoft),
                    ),
                ],
              ));

    return _BarToneScope(
      tone: tone,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: tone.fill,
          border: Border(bottom: BorderSide(color: tone.edge)),
        ),
        child: Padding(
          padding: EdgeInsets.fromLTRB(N360Space.gutter, inset + N360Space.xs, N360Space.gutter, N360Space.xs),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              if (leftSlot) ...<Widget>[
                SizedBox(width: sideWidth, child: left == null ? null : Center(child: left)),
                const SizedBox(width: N360Space.xs),
              ],
              Expanded(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: rowHeight),
                  child: Align(
                    alignment: centerTitle ? Alignment.center : Alignment.centerLeft,
                    child: middle,
                  ),
                ),
              ),
              if (rightSlot) ...<Widget>[
                const SizedBox(width: N360Space.xs),
                if (right == null)
                  const SizedBox(width: sideWidth)
                else
                  // The right slot matches the left so the title sits centred;
                  // a wider status may take the room it needs.
                  ConstrainedBox(
                    constraints: BoxConstraints(minWidth: centerTitle ? sideWidth : 0),
                    child: Center(widthFactor: 1, child: right),
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// The tone of the bar above [context], or the app's current tone.
  static N360BarTone toneOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<_BarToneScope>()?.tone ?? N360Chrome.topBar;
}

class _BarToneScope extends InheritedWidget {
  const _BarToneScope({required this.tone, required super.child});

  final N360BarTone tone;

  @override
  bool updateShouldNotify(_BarToneScope oldWidget) => oldWidget.tone != tone;
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

/// A compact header control: an icon with its word under it on a tile inside
/// a full [N360Size.tap] target. The visible tile is [N360Size.barTile] square
/// at least and grows to fit a longer word rather than shrink the word under
/// [N360Type.minimumSize]. It takes its colours from the bar it sits on.
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
    final N360BarTone tone = N360TopBar.toneOf(context);
    final String spoken = badge == null ? label : '$label, $badge';
    final TextStyle word = N360Type.fit(N360Type.caption, label).copyWith(color: tone.tileInk, height: 1.15);

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
            Icon(icon, size: N360IconSize.lg, color: tone.tileInk),
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
                  color: tone.tileFill,
                  borderRadius: N360Radius.md,
                  border: Border.all(color: tone.tileEdge),
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
