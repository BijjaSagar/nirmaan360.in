import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/back_header.dart';
import '../../../app/widgets/press_row.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';

/// Pick the site you are on. Only ever shown with two or more projects, or
/// while the first pull is still bringing them in. The rows sit in the lower
/// half of the screen, in thumb reach.
class ProjectPickerScreen extends StatelessWidget {
  const ProjectPickerScreen({super.key});

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
              child: PageHeader(title: welcome ? t.shellSignInWelcome : t.shellPickerTitle, showBack: canGoBack),
            ),
            Expanded(
              child: projects.isEmpty
                  ? (welcome ? const _FirstProject() : _EmptyProjects(syncing: syncing))
                  : LayoutBuilder(
                      builder: (context, constraints) => Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[
                          // The art takes the spare room, which keeps the rows low.
                          const Expanded(
                            child: Padding(
                              padding: EdgeInsets.symmetric(horizontal: N360Space.xl, vertical: N360Space.sm),
                              child: Center(
                                child: ExcludeSemantics(
                                  child: BuildingProgressArt(fraction: 0.62, animateEntry: false),
                                ),
                              ),
                            ),
                          ),
                          ConstrainedBox(
                            constraints: BoxConstraints(maxHeight: constraints.maxHeight * 0.68),
                            child: Padding(
                              padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.md),
                              child: LedgerCard(
                                padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
                                child: ListView.separated(
                                  shrinkWrap: true,
                                  padding: EdgeInsets.zero,
                                  itemCount: projects.length,
                                  separatorBuilder: (context, index) => const Padding(
                                    padding: EdgeInsets.symmetric(horizontal: N360Space.lg),
                                    child: RuledDivider(),
                                  ),
                                  itemBuilder: (context, index) {
                                    final project = projects[index];
                                    return StaggeredRise(
                                      index: index,
                                      child: _ProjectRow(
                                        project: project,
                                        isCurrent: project.id == selected,
                                        currentLabel: t.shellPickerCurrent,
                                        onTap: () => _choose(context, project),
                                      ),
                                    );
                                  },
                                ),
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
    return Semantics(
      container: true,
      button: true,
      label: <String>[project.name, detail, if (isCurrent) currentLabel].join(', '),
      onTap: onTap,
      child: ExcludeSemantics(
        child: PressRow(
          onTap: onTap,
          minHeight: 76,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.md, vertical: N360Space.sm),
            child: Row(
              children: <Widget>[
                IconTile(icon: Icons.apartment_outlined, semanticLabel: project.name, highlight: isCurrent),
                const SizedBox(width: N360Space.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(project.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: N360Type.heading),
                      Text(detail, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
                    ],
                  ),
                ),
                if (isCurrent) ...<Widget>[
                  const SizedBox(width: N360Space.xs),
                  RubberStamp(kind: StampKind.synced, label: currentLabel, size: StampSize.small),
                ] else
                  const Icon(Icons.chevron_right, size: 28, color: N360Colors.ink2),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// What a new owner sees: an invitation, with the action in the thumb zone.
class _FirstProject extends StatelessWidget {
  const _FirstProject();

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        const Expanded(
          child: Padding(
            padding: EdgeInsets.symmetric(horizontal: N360Space.xl, vertical: N360Space.sm),
            child: Center(child: ExcludeSemantics(child: BuildingProgressArt(fraction: 0.36))),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.md),
          child: StaggeredRise(
            index: 0,
            child: LedgerCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(t.shellPickerFirstTitle, style: N360Type.title),
                  const SizedBox(height: N360Space.xxs),
                  Text(t.shellPickerFirstBody, style: N360Type.body.copyWith(color: N360Colors.ink2)),
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
        ),
      ],
    );
  }
}

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
