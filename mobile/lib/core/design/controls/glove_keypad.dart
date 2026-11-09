import 'package:flutter/material.dart';

import '../haptics.dart';
import '../internal/press_target.dart';
import '../tokens.dart';
import '../typography.dart';
import 'glove_stepper.dart';

/// An on-screen numeric keypad, because a phone's own keyboard cannot be used
/// with gloves. Keys are 64dp and the next-field key sits under the thumb.
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
      child: Text(digit, style: N360Type.figureLarge.copyWith(fontSize: 30, height: 1.1)),
      builder: (context, depth, child) {
        final double sink = depth * (N360Size.edge - 1);
        return CustomPaint(
          painter: StepKeyPainter(
            face: N360Colors.sheet,
            edge: N360Colors.paperEdge,
            sink: sink,
            outlined: false,
          ),
          foregroundPainter: _KeyBorderPainter(sink: sink),
          child: SizedBox(
            height: N360Size.key,
            child: Padding(
              padding: const EdgeInsets.only(bottom: N360Size.edge),
              child: Transform.translate(
                offset: Offset(0, sink),
                child: Center(child: child),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _KeyBorderPainter extends CustomPainter {
  const _KeyBorderPainter({required this.sink});

  final double sink;

  @override
  void paint(Canvas canvas, Size size) {
    final Rect face = Rect.fromLTRB(0.5, sink + 0.5, size.width - 0.5, size.height - N360Size.edge + sink - 0.5);
    canvas.drawRRect(
      RRect.fromRectAndRadius(face, const Radius.circular(N360Radius.small)),
      Paint()
        ..color = N360Colors.rule
        ..style = PaintingStyle.stroke,
    );
  }

  @override
  bool shouldRepaint(_KeyBorderPainter oldDelegate) => oldDelegate.sink != sink;
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
    final Color face = !on
        ? N360Colors.paper2
        : (primary ? N360Colors.laterite : N360Colors.ink2);
    final Color edge = !on
        ? N360Colors.rule
        : (primary ? N360Colors.lateriteEdge : N360Colors.ink);
    final Color ink = on ? N360Colors.sheet : N360Colors.inkLabel;

    return PressTarget(
      onTap: onTap,
      onLongPress: onLongPress,
      haptic: N360Haptics.tap,
      minHeight: N360Size.key,
      semanticsLabel: label,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          Icon(icon, size: 30, color: ink),
          const SizedBox(height: 4),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(label, style: N360Type.label.copyWith(color: ink, fontSize: 16, height: 1.25)),
          ),
        ],
      ),
      builder: (context, depth, child) {
        final double sink = depth * (N360Size.edge - 1);
        return CustomPaint(
          painter: StepKeyPainter(face: face, edge: edge, sink: sink, outlined: false),
          child: Padding(
            padding: const EdgeInsets.only(bottom: N360Size.edge),
            child: Transform.translate(
              offset: Offset(0, sink),
              child: SizedBox.expand(child: child),
            ),
          ),
        );
      },
    );
  }
}
