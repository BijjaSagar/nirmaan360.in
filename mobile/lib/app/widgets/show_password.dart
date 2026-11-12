import 'package:flutter/material.dart';

import '../../core/design/design.dart';

/// The eye and its word, as wide as a thumb.
class ShowPasswordToggle extends StatelessWidget {
  const ShowPasswordToggle({
    super.key,
    required this.shown,
    required this.showLabel,
    required this.hideLabel,
    required this.onToggle,
  });

  final bool shown;
  final String showLabel;
  final String hideLabel;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final String label = shown ? hideLabel : showLabel;
    return Semantics(
      container: true,
      button: true,
      label: label,
      onTap: onToggle,
      child: ExcludeSemantics(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: (_) => N360Haptics.select(),
          onTap: onToggle,
          child: ConstrainedBox(
            constraints: const BoxConstraints(
              minWidth: 72,
              minHeight: N360Size.tap,
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: N360Space.sm),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: <Widget>[
                  Icon(
                    shown
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                    size: N360IconSize.button,
                    color: N360Colors.ink2,
                  ),
                  const SizedBox(width: N360Space.xs),
                  Text(label, style: N360Type.fit(N360Type.label, label)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
