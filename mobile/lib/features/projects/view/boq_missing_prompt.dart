import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';

/// What a BOQ picker shows when the project has no lines: says so plainly and,
/// for someone who can add them, offers the way there.
///
/// Meant for the inside of a bottom sheet: the button closes the sheet first.
class BoqMissingPrompt extends StatelessWidget {
  const BoqMissingPrompt({super.key, required this.services});

  final AppServices services;

  void _openBoq(BuildContext context) {
    final GoRouter router = GoRouter.of(context);
    Navigator.of(context).maybePop();
    router.push(Routes.boq);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canManage = services.auth.can('boq.manage');
    return SingleChildScrollView(
      child: EmptyLedger(
        title: l.boqMissingTitle,
        message: canManage ? l.boqMissingManage : l.boqMissingAsk,
        action: canManage
            ? GloveButton(
                label: l.boqOpenAction,
                icon: Icons.list_alt,
                large: true,
                onPressed: () => _openBoq(context),
              )
            : null,
      ),
    );
  }
}
