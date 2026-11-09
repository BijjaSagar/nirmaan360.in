import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/design/design.dart';
import '../../l10n/generated/app_localizations.dart';

/// The top of a page pushed over the shell: a back tile, the title centred
/// with an optional line under it, and room for one icon tile on the right so
/// the title stays centred either way.
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.subtitle, this.showBack = true, this.trailing});

  final String title;

  /// A small line under the title, such as the project code.
  final String? subtitle;
  final bool showBack;

  /// Usually an [IconTile] with `onTap`. Its space is kept even when absent.
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Row(
      children: <Widget>[
        if (showBack)
          IconTile(icon: Icons.arrow_back, semanticLabel: t.commonBack, onTap: () => context.pop())
        else
          const SizedBox(width: N360Size.tap),
        Expanded(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                title,
                maxLines: 2,
                textAlign: TextAlign.center,
                overflow: TextOverflow.ellipsis,
                style: N360Type.fit(N360Type.title, title),
              ),
              if (subtitle != null)
                Text(
                  subtitle!,
                  maxLines: 1,
                  textAlign: TextAlign.center,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.fit(N360Type.caption, subtitle!),
                ),
            ],
          ),
        ),
        trailing ?? const SizedBox(width: N360Size.tap),
      ],
    );
  }
}
