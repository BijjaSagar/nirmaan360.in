import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// Pieces shared by the attendance, issues and petty cash screens, so the three
/// read as one app. They live here because `lib/core` belongs to the design
/// system and these are only compositions of it.
///
/// Every visible thing here is sized for the eye (a 44dp tile, 18 and 14 point
/// type) and every tappable thing carries a 56dp target around it.

/// The top of a screen. One header for the whole app: the design system's
/// [N360TopBar], with the translated word under the back arrow.
class FieldTopBar extends StatelessWidget {
  const FieldTopBar({
    super.key,
    required this.title,
    this.subtitle,
    this.onBack,
    this.trailing,
  });

  final String title;
  final String? subtitle;

  /// Defaults to popping the route when there is somewhere to go back to.
  final VoidCallback? onBack;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return N360TopBar(
      backLabel: AppLocalizations.of(context).commonBack,
      title: title,
      subtitle: subtitle,
      onBack: onBack,
      trailing: trailing,
    );
  }
}

/// Holds a screen's main action: the design system's [N360ActionBar], a white
/// bar with a hairline above it that the page ends in.
class FieldActionBar extends StatelessWidget {
  const FieldActionBar({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => N360ActionBar(child: child);
}

/// One white card around a lazily built group of rows. A single painted card
/// keeps a long list free of per-row shadows and per-row surfaces.
class FieldGroupSliver extends StatelessWidget {
  const FieldGroupSliver({
    super.key,
    required this.sliver,
    this.padding = const EdgeInsets.fromLTRB(
      N360Space.md,
      N360Space.md,
      N360Space.md,
      N360Space.xs,
    ),
    this.margin = const EdgeInsets.fromLTRB(
      N360Space.gutter,
      0,
      N360Space.gutter,
      N360Space.cardGap,
    ),
  });

  final Widget sliver;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry margin;

  @override
  Widget build(BuildContext context) {
    return SliverPadding(
      padding: margin,
      sliver: DecoratedSliver(
        decoration: BoxDecoration(
          color: N360Colors.sheet,
          borderRadius: N360Radius.cardRadius,
          border: Border.all(color: N360Colors.cardEdge),
        ),
        sliver: SliverPadding(padding: padding, sliver: sliver),
      ),
    );
  }
}

/// A horizontal strip of [N360Chip]s: the days of the week, the wallets of a
/// project. Chips keep their 56dp target and the strip gives them air.
///
/// Build each chip in [itemBuilder]. With [reverse] the first item sits at the
/// right edge, where the thumb rests, and the strip opens scrolled there.
///
/// The row is as high as its chips, not a fixed height: a chip drawn into a
/// bounded height stretches to fill it.
class FieldChoiceStrip extends StatelessWidget {
  const FieldChoiceStrip({
    super.key,
    required this.itemCount,
    required this.itemBuilder,
    this.reverse = false,
  });

  final int itemCount;
  final IndexedWidgetBuilder itemBuilder;
  final bool reverse;

  @override
  Widget build(BuildContext context) {
    final List<Widget> chips = <Widget>[
      for (var i = 0; i < itemCount; i++) itemBuilder(context, i),
    ];
    final List<Widget> ordered = reverse ? chips.reversed.toList() : chips;
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      reverse: reverse,
      padding: const EdgeInsets.symmetric(
        horizontal: N360Space.gutter,
        vertical: N360Space.xs,
      ),
      child: Row(
        children: <Widget>[
          for (final (i, chip) in ordered.indexed) ...<Widget>[
            if (i > 0) const SizedBox(width: N360Space.sm),
            chip,
          ],
        ],
      ),
    );
  }
}

/// A selectable tile for a small choice (today or yesterday, one of a few
/// people): white with a hairline at rest, the primary tint with a primary edge
/// when chosen, always with dark words. For a strip of filters use [N360Chip].
///
/// With [trailingIcon] the tile is a picker: it looks like a field, reads left
/// to right and shows its mark at the far edge. The tile is light and sits
/// inside a 56dp target, so it is easy to hit in gloves.
class FieldChoiceTile extends StatelessWidget {
  const FieldChoiceTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.trailing,
    this.trailingIcon,
    this.detail,
    this.width,
    this.semanticsLabel,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;

  /// A count after the label.
  final String? trailing;

  /// A small mark after the label that says the tile opens a list. It sits
  /// beside the label and never stands alone.
  final IconData? trailingIcon;

  /// A second, smaller line under the label.
  final String? detail;

  /// Fixes the width, for tiles in an even row.
  final double? width;
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) {
    final String spoken =
        semanticsLabel ?? <String>[label, ?detail, ?trailing].join(', ');
    final String? sub = detail;
    final String? count = trailing;
    final bool picker = trailingIcon != null;

    // A tile of fixed width gives its words the room the padding would take.
    final double inset = picker
        ? N360Space.card
        : (width != null && width!.isFinite ? N360Space.sm : N360Space.md);

    final Widget text = Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(
            label,
            maxLines: 1,
            style: N360Type.fit(N360Type.bodyStrong, label),
          ),
        ),
        if (sub != null)
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              sub,
              maxLines: 1,
              style: N360Type.fit(N360Type.caption, sub),
            ),
          ),
      ],
    );

    final BoxDecoration face = picker
        ? BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.md,
            border: Border.all(color: N360Colors.ruleStrong),
          )
        : BoxDecoration(
            color: selected ? N360Colors.primaryTint : N360Colors.sheet,
            borderRadius: N360Radius.tileRadius,
            border: Border.all(
              color: selected ? N360Colors.primary : N360Colors.rule,
              width: selected ? 1.5 : N360Size.hairline,
            ),
          );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: spoken,
      selected: selected,
      child: Padding(
        padding: EdgeInsets.symmetric(
          horizontal: inset,
          vertical: N360Space.xs,
        ),
        child: Row(
          mainAxisSize: width == null ? MainAxisSize.min : MainAxisSize.max,
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            if (icon != null) ...<Widget>[
              Icon(icon, size: N360IconSize.lg, color: N360Colors.ink2),
              const SizedBox(width: N360Space.sm),
            ],
            if (width == null)
              text
            else if (picker)
              // A tile that opens a list reads left to right, with its mark
              // at the far edge.
              Expanded(
                child: Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: text,
                ),
              )
            else
              Flexible(child: text),
            if (count != null) ...<Widget>[
              const SizedBox(width: N360Space.sm),
              Text(count, style: N360Type.figureSmall),
            ],
            if (picker) ...<Widget>[
              const SizedBox(width: N360Space.sm),
              Icon(trailingIcon, size: N360IconSize.lg, color: N360Colors.ink2),
            ],
          ],
        ),
      ),
      builder: (context, depth, child) => Center(
        widthFactor: 1,
        child: Transform.scale(
          scale: pressedScale(depth),
          child: SizedBox(
            width: width,
            child: DecoratedBox(
              decoration: face,
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  minHeight: picker ? N360Size.tap : N360Size.tile,
                ),
                child: Center(widthFactor: 1, heightFactor: 1, child: child),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// A secondary action beside a screen's main button: an icon with its word
/// under it, so the main button keeps a whole line.
class FieldSideTile extends StatelessWidget {
  const FieldSideTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  /// Wide enough for "Add worker" and its Marathi and Hindi words at 13 points.
  static const double _width = 92;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      minHeight: N360Size.tapLarge,
      semanticsLabel: label,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.xs),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(icon, size: N360IconSize.xl, color: N360Colors.ink),
            const SizedBox(height: N360Space.xxs),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                label,
                maxLines: 1,
                style: N360Type.fit(N360Type.label, label),
              ),
            ),
          ],
        ),
      ),
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.tileRadius,
            border: Border.all(color: N360Colors.ruleStrong),
          ),
          child: SizedBox(
            width: _width,
            height: N360Size.tapLarge,
            child: child,
          ),
        ),
      ),
    );
  }
}

/// A short problem under a field, with an icon so it is not colour alone.
class FieldInlineError extends StatelessWidget {
  const FieldInlineError(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      liveRegion: true,
      label: message,
      child: ExcludeSemantics(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.only(top: N360Space.xxs, right: N360Space.sm),
              child: Icon(
                Icons.error_outline,
                size: N360IconSize.md,
                color: N360Colors.stampRed,
              ),
            ),
            Expanded(
              child: Text(
                message,
                style: N360Type.fit(
                  N360Type.label,
                  message,
                ).copyWith(color: N360Colors.stampRed),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
