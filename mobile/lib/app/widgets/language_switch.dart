import 'package:flutter/material.dart';

import '../../core/design/design.dart';
import '../app_gate.dart';

/// Every language in its own script, so nobody needs to read English to find
/// their own. The gold tile slides to the chosen one.
class LanguageSwitch extends StatelessWidget {
  const LanguageSwitch({super.key, required this.current, required this.onChanged});

  final String? current;
  final ValueChanged<String> onChanged;

  static const Map<String, String> _labels = <String, String>{
    'en': 'English',
    'mr': 'मराठी',
    'hi': 'हिन्दी',
  };

  @override
  Widget build(BuildContext context) {
    final int active = current == null ? -1 : kAppLocales.indexOf(current!);
    return SizedBox(
      height: N360Size.tap,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final double cell = constraints.maxWidth / kAppLocales.length;
          return DecoratedBox(
            decoration: BoxDecoration(
              color: N360Colors.sheet,
              borderRadius: N360Radius.tileRadius,
              border: Border.all(color: N360Colors.cardEdge),
            ),
            child: Stack(
              children: <Widget>[
                if (active >= 0)
                  TweenAnimationBuilder<double>(
                    tween: Tween<double>(end: active.toDouble()),
                    duration: context.motion.quick + context.motion.quick,
                    curve: N360Curves.rise,
                    builder: (context, value, child) => Transform.translate(
                      offset: Offset(value * cell, 0),
                      child: child,
                    ),
                    child: SizedBox(
                      width: cell,
                      height: N360Size.tap,
                      child: const Padding(
                        padding: EdgeInsets.all(4),
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            gradient: N360Gradients.gold,
                            borderRadius: N360Radius.md,
                          ),
                        ),
                      ),
                    ),
                  ),
                Row(
                  children: <Widget>[
                    for (final (index, code) in kAppLocales.indexed)
                      Expanded(
                        child: _LanguageCell(
                          label: _labels[code]!,
                          selected: index == active,
                          onTap: () {
                            if (index != active) onChanged(code);
                          },
                        ),
                      ),
                  ],
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _LanguageCell extends StatelessWidget {
  const _LanguageCell({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      button: true,
      selected: selected,
      label: label,
      onTap: onTap,
      child: ExcludeSemantics(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: (_) => N360Haptics.select(),
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints.expand(height: N360Size.tap),
            child: Center(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: N360Space.xs),
                  child: Text(label, maxLines: 1, style: N360Type.button.copyWith(color: N360Colors.ink)),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
