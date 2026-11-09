import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/design/design.dart';
import '../../l10n/generated/app_localizations.dart';

/// A back control with its word, 56dp tall, for pages pushed over the shell.
class BackHeader extends StatelessWidget {
  const BackHeader({super.key});

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Semantics(
      container: true,
      button: true,
      label: t.commonBack,
      onTap: () => context.pop(),
      child: ExcludeSemantics(
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: (_) => N360Haptics.tap(),
          onTap: () => context.pop(),
          child: SizedBox(
            height: N360Size.tap,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                const Icon(Icons.arrow_back, size: 26, color: N360Colors.ink),
                const SizedBox(width: N360Space.sm),
                Text(t.commonBack, style: N360Type.button.copyWith(fontSize: 20)),
                const SizedBox(width: N360Space.md),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
