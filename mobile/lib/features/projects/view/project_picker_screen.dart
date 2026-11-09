import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/back_header.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';

/// Pick the site you are on. Only ever shown with two or more projects, or
/// while the first pull is still bringing them in: one white list of sites,
/// the current one marked.
class ProjectPickerScreen extends StatelessWidget {
  const ProjectPickerScreen({super.key});

  /// The most room the first-run drawing takes.
  static const double artMaxHeight = 220;

  Future<void> _choose(BuildContext context, ProjectRow project) async {
    await context.read<AppGate>().selectProject(project.id);
    if (context.mounted) context.go(Routes.home);
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final GateState gate = context.select((AppGate g) => g.state);
    final List<ProjectRow> projects = gate.projects ?? const <ProjectRow>[];
    final int? selected = gate.selectedProjectId;
    final bool syncing = context.select((SyncStatusCubit s) => s.state.isSyncing);
    final bool canGoBack = GoRouter.of(context).canPop();
    final bool canCreate = gate.canManageProjects;
    final bool welcome = projects.isEmpty && canCreate && !syncing;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (syncing) ProgressRule(semanticsLabel: t.shellPickerLoading),
            Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
              child: PageHeader(
                title: welcome ? t.shellSignInWelcome : t.shellPickerTitle,
                showBack: canGoBack,
                // With nothing to go back to, settings is the way to sign out
                // of the wrong account; otherwise Home already offers it.
                trailing: canGoBack
                    ? null
                    : IconTile(
                        icon: Icons.settings_outlined,
                        semanticLabel: t.shellSettings,
                        onTap: () => context.push(Routes.settings),
                      ),
              ),
            ),
            Expanded(
              child: projects.isEmpty
                  ? (welcome ? const _FirstProject() : _EmptyProjects(syncing: syncing))
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        Expanded(
                          child: SingleChildScrollView(
                            padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.md),
                            child: LedgerList(
                              children: <Widget>[
                                for (final (index, project) in projects.indexed)
                                  StaggeredRise(
                                    index: index,
                                    child: _ProjectRow(
                                      project: project,
                                      isCurrent: project.id == selected,
                                      currentLabel: t.shellPickerCurrent,
                                      onTap: () => _choose(context, project),
                                    ),
                                  ),
                              ],
                            ),
                          ),
                        ),
                        if (canCreate)
                          Padding(
                            padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.md),
                            child: GloveButton(
                              label: t.shellProjectFormTitle,
                              icon: Icons.add,
                              kind: GloveButtonKind.secondary,
                              fullWidth: true,
                              onPressed: () => context.push(Routes.projectForm),
                            ),
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ProjectRow extends StatelessWidget {
  const _ProjectRow({
    required this.project,
    required this.isCurrent,
    required this.currentLabel,
    required this.onTap,
  });

  final ProjectRow project;
  final bool isCurrent;
  final String currentLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final String? location = project.location?.trim().isEmpty ?? true ? null : project.location;
    final String detail = <String>[project.code, ?location].join(' · ');
    return LedgerListTile(
      leading: const Icon(Icons.apartment_outlined, size: N360IconSize.lg, color: N360Colors.ink2),
      title: project.name,
      subtitle: detail,
      status: isCurrent ? StatusPill(kind: StampKind.synced, label: currentLabel) : null,
      semanticsLabel: <String>[project.name, detail, if (isCurrent) currentLabel].join(', '),
      onTap: onTap,
    );
  }
}

/// What a new owner sees: the crane at work on an empty site, and one
/// invitation in the thumb zone.
class _FirstProject extends StatelessWidget {
  const _FirstProject();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.xxl, vertical: N360Space.sm),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: ProjectPickerScreen.artMaxHeight),
                child: LoopingArt.craneHoist(semanticLabel: t.shellPickerFirstTitle),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.md),
          child: LedgerCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(t.shellPickerFirstTitle, style: N360Type.fit(N360Type.title, t.shellPickerFirstTitle)),
                const SizedBox(height: N360Space.xxs),
                Text(
                  t.shellPickerFirstBody,
                  style: N360Type.fit(N360Type.body, t.shellPickerFirstBody).copyWith(color: N360Colors.ink2),
                ),
                const SizedBox(height: N360Space.md),
                GloveButton(
                  label: t.shellPickerFirstButton,
                  icon: Icons.add_home_work_outlined,
                  large: true,
                  fullWidth: true,
                  onPressed: () => context.push(Routes.projectForm),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// Signed in, but on no project yet. The crane keeps working while the office
/// adds them; the button asks the server again.
class _EmptyProjects extends StatelessWidget {
  const _EmptyProjects({required this.syncing});

  final bool syncing;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Center(
      child: SingleChildScrollView(
        child: EmptyLedger(
          title: syncing ? t.shellPickerLoading : t.shellPickerEmptyTitle,
          message: syncing ? null : t.shellPickerEmptyBody,
          action: syncing
              ? null
              : GloveButton(
                  label: t.commonRetry,
                  icon: Icons.refresh,
                  onPressed: context.read<AppServices>().syncCenter.syncNow,
                ),
        ),
      ),
    );
  }
}
