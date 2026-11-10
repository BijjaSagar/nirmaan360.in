import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';

/// The short list behind Home's one "Site tools" row. Each tool is here only for
/// a person and a plan that can use it, so the list is never longer than what
/// works for them.
class SiteToolsScreen extends StatelessWidget {
  const SiteToolsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProjectRow? project = context.select((AppGate gate) => gate.state.currentProject);
    final bool inspect = context.select((AppGate gate) => gate.state.canInspect);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              FieldTopBar(title: l.siteToolsTitle, subtitle: project?.code),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.lg),
                  children: <Widget>[
                    LedgerList(
                      children: <Widget>[
                        if (inspect)
                          LedgerListTile(
                            key: const ValueKey<String>('site-tool-inspections'),
                            leading: const Icon(Icons.fact_check_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
                            title: l.inspectionsTitle,
                            subtitle: l.siteToolsInspectionsHint,
                            onTap: () => context.push(Routes.inspections),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
