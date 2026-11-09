import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/design/design.dart';
import '../../l10n/generated/app_localizations.dart';
import 'labelled_tile.dart';

/// The top of a page pushed over the shell: a back tile with its word, the
/// page title beside it, and room for one labelled tile on the right.
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.showBack = true, this.trailing});

  final String title;
  final bool showBack;

  /// Usually a [LabelledTile]. Sits flush with the right edge.
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Row(
      children: <Widget>[
        if (showBack) ...<Widget>[
          LabelledTile(icon: Icons.arrow_back, label: t.commonBack, onTap: () => context.pop()),
          const SizedBox(width: N360Space.sm),
        ],
        Expanded(
          child: Text(
            title,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: N360Type.fit(N360Type.title, title),
          ),
        ),
        if (trailing != null) ...<Widget>[
          const SizedBox(width: N360Space.sm),
          trailing!,
        ],
      ],
    );
  }
}
