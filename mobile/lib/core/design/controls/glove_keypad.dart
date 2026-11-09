import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../internal/soft_surface.dart';
import '../tokens.dart';
import '../typography.dart';

/// An on-screen numeric keypad, because a phone's own keyboard cannot be used
/// with gloves. Keys are [N360Size.key] tall and the next-field key sits under
/// the thumb.
///
/// The keypad owns the input rules and hands back the new string, so it never
/// touches `double`: feed [value] straight into your `Qty` or `Money`.
class GloveKeypad extends StatelessWidget {
  const GloveKeypad({
    super.key,
    required this.value,
    required this.onChanged,
    required this.onNext,
    required this.nextLabel,
    this.backspaceLabel = 'Delete',
    this.decimalPlaces = 3,
    this.maxIntegerDigits = 9,
    this.nextIcon = Icons.arrow_forward,
  });

  final String value;
  final ValueChanged<String> onChanged;

  /// Moves to the next field, or finishes. Null shows the key as unavailable.
  final VoidCallback? onNext;
  final String nextLabel;
  final String backspaceLabel;

  /// Decimals allowed: 3 for quantities, 2 for rupees, 0 hides the point.
  final int decimalPlaces;
  final int maxIntegerDigits;
  final IconData nextIcon;

  static const double _gap = N360Space.sm;

  /// Applies one key to [value]. Keys are `0` to `9`, `.` and `backspace`.
  static String applyKey(String value, String key, {int decimalPlaces = 3, int maxIntegerDigits = 9}) {
    if (key == 'backspace') {
      return value.isEmpty ? value : value.substring(0, value.length - 1);
    }
    if (key == '.') {
      if (decimalPlaces == 0 || value.contains('.')) return value;
      return value.isEmpty ? '0.' : '$value.';
    }
    final int point = value.indexOf('.');
    if (point != -1) {
      return value.length - point - 1 >= decimalPlaces ? value : '$value$key';
    }
    if (value == '0') return key;
    return value.length >= maxIntegerDigits ? value : '$value$key';
  }

  void _press(String key) {
    onChanged(applyKey(value, key, decimalPlaces: decimalPlaces, maxIntegerDigits: maxIntegerDigits));
  }

  Widget _digit(String digit) => Expanded(child: _DigitKey(digit: digit, onTap: () => _press(digit)));

  Widget _row(List<String> digits) => Row(
        children: <Widget>[
          _digit(digits[0]),
          const SizedBox(width: _gap),
          _digit(digits[1]),
          const SizedBox(width: _gap),
          _digit(digits[2]),
        ],
      );

  @override
  Widget build(BuildContext context) {
    final bool decimal = decimalPlaces > 0;
    final double total = N360Size.key * 4 + _gap * 3;

    return SizedBox(
      height: total,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Expanded(
            flex: 3,
            child: Column(
              children: <Widget>[
                _row(const <String>['7', '8', '9']),
                const SizedBox(height: _gap),
                _row(const <String>['4', '5', '6']),
                const SizedBox(height: _gap),
                _row(const <String>['1', '2', '3']),
                const SizedBox(height: _gap),
                Row(
                  children: <Widget>[
                    Expanded(
                      flex: decimal ? 2 : 3,
                      child: _DigitKey(digit: '0', onTap: () => _press('0')),
                    ),
                    if (decimal) ...<Widget>[
                      const SizedBox(width: _gap),
                      Expanded(child: _DigitKey(digit: '.', onTap: () => _press('.'))),
                    ],
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: _gap),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Expanded(
                  child: _ActionKey(
                    icon: Icons.backspace_outlined,
                    label: backspaceLabel,
                    onTap: value.isEmpty ? null : () => _press('backspace'),
                    onLongPress: value.isEmpty ? null : () => onChanged(''),
                  ),
                ),
                const SizedBox(height: _gap),
                Expanded(
                  child: _ActionKey(
                    icon: nextIcon,
                    label: nextLabel,
                    primary: true,
                    onTap: onNext,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _DigitKey extends StatelessWidget {
  const _DigitKey({required this.digit, required this.onTap});

  final String digit;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.tap,
      minHeight: N360Size.key,
      semanticsLabel: digit,
      child: Text(digit, style: N360Type.figureLarge.copyWith(height: 1.1, letterSpacing: 0)),
      builder: (context, depth, child) {
        return Transform.scale(
          scale: pressedScale(depth),
          child: CustomPaint(
            painter: const SoftSurfacePainter(radius: N360Radius.tile, edge: N360Colors.cardEdge),
            child: SizedBox(height: N360Size.key, child: Center(child: child)),
          ),
        );
      },
    );
  }
}

class _ActionKey extends StatelessWidget {
  const _ActionKey({required this.icon, required this.label, required this.onTap, this.onLongPress, this.primary = false});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final bool primary;

  @override
  Widget build(BuildContext context) {
    final bool on = onTap != null;
    final SoftSurfacePainter surface = !on
        ? const SoftSurfacePainter(radius: N360Radius.tile, fill: N360Colors.paper2)
        : primary
            ? const SoftSurfacePainter(
                radius: N360Radius.tile,
                gradient: N360Gradients.gold,
                edge: Color(0x55FFFFFF),
              )
            : const SoftSurfacePainter(radius: N360Radius.tile, fill: N360Colors.paper2);
    final Color ink = on ? N360Colors.ink : N360Colors.inkLabel;

    return PressTarget(
      onTap: onTap,
      onLongPress: onLongPress,
      haptic: N360Haptics.tap,
      minHeight: N360Size.key,
      semanticsLabel: label,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Icon(icon, size: N360IconSize.xl, color: ink),
          const SizedBox(height: 3),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(label, style: N360Type.fit(N360Type.label, label).copyWith(color: ink, height: 1.25)),
          ),
        ],
      ),
      builder: (context, depth, child) {
        return Transform.scale(
          scale: pressedScale(depth),
          child: CustomPaint(
            painter: surface,
            child: SizedBox.expand(child: child),
          ),
        );
      },
    );
  }
}
