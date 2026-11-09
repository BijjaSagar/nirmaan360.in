import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/design/design.dart';
import '../../l10n/generated/app_localizations.dart';

/// The top of a page pushed over the shell: the app's one [N360TopBar], with
/// the labelled back tile, the title centred and room for one more labelled
/// tile on the right. Callers pad the page themselves, so this adds none.
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.subtitle, this.showBack = true, this.trailing});

  final String title;

  /// A small line under the title, such as the project code.
  final String? subtitle;
  final bool showBack;

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
      trailing: trailing,
      padding: EdgeInsets.zero,
    );
  }
}
