import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// Pieces shared by the attendance, issues and petty cash screens, so the three
/// read as one app. They live here because `lib/core` belongs to the design
/// system and these are only compositions of it.

/// The top of a screen: a labelled back tile, a centred title, and an optional
/// tile on the right. The back tile keeps its word, so nothing depends on
/// recognising the arrow.
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

  static const double _sideWidth = 64;

  @override
  Widget build(BuildContext context) {
    final NavigatorState navigator = Navigator.of(context);
    final VoidCallback? back = onBack ?? (navigator.canPop() ? () => navigator.maybePop() : null);

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.xs,
        N360Space.gutter,
        N360Space.sm,
      ),
      child: Row(
        children: <Widget>[
          SizedBox(
            width: _sideWidth,
            child: back == null ? null : _BackTile(onTap: back),
          ),
          const SizedBox(width: N360Space.xs),
          Expanded(
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: N360Size.tap),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: <Widget>[
                  Text(
                    title,
                    maxLines: 2,
                    textAlign: TextAlign.center,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.heading.copyWith(fontSize: 18, height: 1.3),
                  ),
                  if (subtitle != null)
                    Text(
                      subtitle!,
                      maxLines: 1,
                      textAlign: TextAlign.center,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.caption,
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(width: N360Space.xs),
          SizedBox(width: _sideWidth, child: trailing),
        ],
      ),
    );
  }
}

class _BackTile extends StatelessWidget {
  const _BackTile({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return PressTarget(
      onTap: onTap,
      semanticsLabel: l.commonBack,
      minWidth: 56,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          const Icon(Icons.chevron_left, size: 26, color: N360Colors.ink),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              l.commonBack,
              maxLines: 1,
              style: N360Type.caption.copyWith(
                fontSize: 13,
                height: 1.15,
                color: N360Colors.ink,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
      builder: (context, depth, child) => Transform.scale(
        scale: 1 - 0.035 * depth,
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.tileRadius,
          ),
          child: SizedBox(height: N360Size.tap, child: Center(child: child)),
        ),
      ),
    );
  }
}

/// Holds a screen's main action: a floating white panel on the warm foot of the
/// page, in the thumb zone.
class FieldActionBar extends StatelessWidget {
  const FieldActionBar({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.xs, N360Space.md, N360Space.sm),
        child: LedgerCard(
          padding: const EdgeInsets.all(10),
          child: child,
        ),
      ),
    );
  }
}

/// One white card around a lazily built group of rows. A single painted card
/// keeps a long list free of per-row shadows and per-row surfaces.
class FieldGroupSliver extends StatelessWidget {
  const FieldGroupSliver({
    super.key,
    required this.sliver,
    this.padding = const EdgeInsets.fromLTRB(12, 12, 12, 4),
    this.margin = const EdgeInsets.fromLTRB(
      N360Space.gutter,
      0,
      N360Space.gutter,
      N360Space.md,
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

/// A selectable chip: white at rest, gold with dark ink when chosen. At least
/// 56dp high for a gloved thumb.
class FieldChoiceTile extends StatelessWidget {
  const FieldChoiceTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
    this.trailing,
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

  /// A second, smaller line under the label.
  final String? detail;

  /// Fixes the width, for tiles in an even row.
  final double? width;
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) {
    final String spoken = semanticsLabel ??
        <String>[label, ?detail, ?trailing].join(', ');
    final Color secondary = selected ? N360Colors.ink : N360Colors.inkLabel;

    final Widget text = Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(
            label,
            maxLines: 1,
            style: N360Type.bodyStrong.copyWith(height: 1.25, color: N360Colors.ink),
          ),
        ),
        if (detail != null)
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              detail!,
              maxLines: 1,
              style: N360Type.caption.copyWith(fontSize: 13, height: 1.2, color: secondary),
            ),
          ),
      ],
    );

    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: spoken,
      selected: selected,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
          child: Row(
            mainAxisSize: width == null ? MainAxisSize.min : MainAxisSize.max,
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              if (icon != null) ...<Widget>[
                Icon(icon, size: 20, color: N360Colors.ink),
                const SizedBox(width: 6),
              ],
              if (width == null) text else Flexible(child: text),
              if (trailing != null) ...<Widget>[
                const SizedBox(width: 6),
                Text(
                  trailing!,
                  style: N360Type.bodyStrong.copyWith(height: 1.25, color: secondary),
                ),
              ],
            ],
          ),
        ),
      ),
      builder: (context, depth, child) => Transform.scale(
        scale: 1 - 0.035 * depth,
        child: SizedBox(
          width: width,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: selected ? null : N360Colors.sheet,
              gradient: selected ? N360Gradients.gold : null,
              borderRadius: N360Radius.tileRadius,
              border: selected ? null : Border.all(color: N360Colors.rule),
            ),
            child: child,
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

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      minHeight: N360Size.tapLarge,
      semanticsLabel: label,
      child: SizedBox(
        width: 92,
        height: N360Size.tapLarge,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.xs),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              Icon(icon, size: 24, color: N360Colors.ink),
              const SizedBox(height: 2),
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  label,
                  maxLines: 1,
                  style: N360Type.label.copyWith(height: 1.2, color: N360Colors.ink),
                ),
              ),
            ],
          ),
        ),
      ),
      builder: (context, depth, child) => Transform.scale(
        scale: 1 - 0.035 * depth,
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: N360Colors.paper2,
            borderRadius: N360Radius.tileRadius,
          ),
          child: child,
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
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Padding(
          padding: EdgeInsets.only(top: 2, right: 6),
          child: Icon(Icons.error_outline, size: 18, color: N360Colors.stampRed),
        ),
        Expanded(
          child: Text(
            message,
            style: N360Type.caption.copyWith(
              color: N360Colors.stampRed,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}
