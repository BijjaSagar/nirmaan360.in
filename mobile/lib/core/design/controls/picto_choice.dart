import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

@immutable
class PictoOption<T> {
  const PictoOption({required this.value, required this.icon, required this.label});

  final T value;
  final IconData icon;
  final String label;
}

/// Single choice as a grid of picture-and-word tiles, for weather, priority and
/// category. Picking one takes one tap and fills it with ink at once.
class PictoChoice<T> extends StatelessWidget {
  const PictoChoice({
    super.key,
    required this.options,
    required this.value,
    required this.onChanged,
    this.columns = 3,
    this.semanticsLabel,
  });

  final List<PictoOption<T>> options;
  final T? value;
  final ValueChanged<T> onChanged;
  final int columns;
  final String? semanticsLabel;

  /// Horizontal padding inside a tile, on both sides together.
  static const double _tileInset = N360Space.xs * 2 + 2;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: semanticsLabel,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final double gaps = N360Space.sm * (columns - 1);
          final double labelWidth = (constraints.maxWidth - gaps) / columns - _tileInset;
          final List<Widget> rows = <Widget>[];
          for (var start = 0; start < options.length; start += columns) {
            final int end = (start + columns).clamp(0, options.length);
            final List<Widget> cells = <Widget>[];
            for (var i = start; i < end; i++) {
              if (i > start) cells.add(const SizedBox(width: N360Space.sm));
              final option = options[i];
              cells.add(
                Expanded(
                  child: _PictoTile(
                    icon: option.icon,
                    label: option.label,
                    labelWidth: labelWidth,
                    selected: option.value == value,
                    onTap: () => onChanged(option.value),
                  ),
                ),
              );
            }
            for (var pad = end - start; pad < columns; pad++) {
              cells.add(const SizedBox(width: N360Space.sm));
              cells.add(const Expanded(child: SizedBox.shrink()));
            }
            if (start > 0) rows.add(const SizedBox(height: N360Space.sm));
            rows.add(
              IntrinsicHeight(
                child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: cells),
              ),
            );
          }
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: rows,
          );
        },
      ),
    );
  }
}

class _PictoTile extends StatelessWidget {
  const _PictoTile({
    required this.icon,
    required this.label,
    required this.labelWidth,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final double labelWidth;
  final bool selected;
  final VoidCallback onTap;

  static const double _minHeight = 64;

  @override
  Widget build(BuildContext context) {
    const Color ink = N360Colors.ink;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minHeight: _minHeight,
      semanticsLabel: label,
      selected: selected,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Icon(icon, size: N360IconSize.xl, color: ink),
          const SizedBox(height: 3),
          _WholeWordLabel(
            label: label,
            maxWidth: labelWidth,
            style: N360Type.fit(N360Type.label, label).copyWith(color: ink, height: 1.28),
          ),
        ],
      ),
      builder: (context, depth, child) {
        return Transform.scale(
          scale: pressedScale(depth),
          child: CustomPaint(
            painter: selected
                ? const SoftSurfacePainter(
                    radius: N360Radius.tile,
                    gradient: N360Gradients.gold,
                    edge: Color(0x55FFFFFF),
                  )
                : const SoftSurfacePainter(radius: N360Radius.tile, edge: N360Colors.rule),
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: _minHeight),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: N360Space.xs, vertical: N360Space.sm),
                child: Center(child: child),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// Wraps between words but never inside one: if the longest word is wider than
/// the tile, the font shrinks just enough for that word to fit whole.
class _WholeWordLabel extends StatelessWidget {
  const _WholeWordLabel({required this.label, required this.maxWidth, required this.style});

  final String label;
  final double maxWidth;
  final TextStyle style;

  double _longestWord(TextScaler scaler) {
    var longest = 0.0;
    for (final String word in label.split(RegExp(r'\s+'))) {
      final painter = TextPainter(
        text: TextSpan(text: word, style: style),
        textDirection: TextDirection.ltr,
        textScaler: scaler,
      )..layout();
      longest = longest > painter.width ? longest : painter.width;
      painter.dispose();
    }
    return longest;
  }

  @override
  Widget build(BuildContext context) {
    final TextScaler scaler = MediaQuery.textScalerOf(context);
    final double longest = _longestWord(scaler);
    final double shrink = longest > maxWidth && longest > 0 ? maxWidth / longest : 1;
    return Text(
      label,
      textAlign: TextAlign.center,
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      textScaler: scaler,
      style: shrink == 1 ? style : style.copyWith(fontSize: style.fontSize! * shrink * 0.98),
    );
  }
}
