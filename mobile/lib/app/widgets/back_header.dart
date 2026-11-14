import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/design/design.dart';
import '../../l10n/generated/app_localizations.dart';

/// The top of a page pushed over the shell: the app's one [N360TopBar], with
/// the labelled back tile, the title centred and room for one more labelled
/// tile on the right. It is the full bar, status-bar area included, so it
/// goes first in the screen's column, outside any padding.
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.subtitle, this.showBack = true, this.leading, this.trailing});

  final String title;

  /// A small line under the title, such as the project code.
  final String? subtitle;
  final bool showBack;

  /// What sits on the left when there is no way back, such as the brand mark.
  final Widget? leading;

  /// Usually an [N360TopTile] with its word. Its space is kept even when absent.
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return N360TopBar(
      backLabel: AppLocalizations.of(context).commonBack,
      title: title,
      subtitle: subtitle,
      showBack: showBack,
      onBack: showBack ? () => context.pop() : null,
      leading: leading,
      trailing: trailing,
    );
  }
}
