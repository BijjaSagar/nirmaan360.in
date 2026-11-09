import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/back_header.dart';
import '../../../app/widgets/ledger_sheet.dart';
import '../../../app/widgets/press_row.dart';
import '../../../app/widgets/site_plan.dart';
import '../../../app/widgets/stamp_mark.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';

/// Pick the site you are on. Only ever shown with two or more projects, or
/// while the first pull is still bringing them in.
///
/// The rows sit at the bottom of the screen, in thumb reach, on the same sheet
/// as the sign-in form.
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

    final Widget body = projects.isEmpty
        ? _EmptyProjects(syncing: syncing)
        : ListView.separated(
            shrinkWrap: true,
            padding: EdgeInsets.zero,
            itemCount: projects.length,
            separatorBuilder: (context, index) => const RuledDivider(),
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
          );

    return Scaffold(
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                if (syncing) ProgressRule(semanticsLabel: t.shellPickerLoading),
                Expanded(
                  child: Stack(
                    fit: StackFit.expand,
                    children: <Widget>[
                      const SitePlan(),
                      const Center(child: StampMark(size: 88)),
                      if (canGoBack)
                        const Positioned(top: N360Space.xs, left: N360Space.gutter, child: BackHeader()),
                    ],
                  ),
                ),
                ConstrainedBox(
                  constraints: BoxConstraints(maxHeight: constraints.maxHeight * 0.76),
                  child: LedgerSheet(
                    marginX: _codeColumn,
                    padding: const EdgeInsets.fromLTRB(0, 22, 0, N360Space.md),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        Padding(
                          padding: const EdgeInsets.fromLTRB(_codeColumn + N360Space.md, 0, N360Space.gutter, N360Space.sm),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: <Widget>[
                              SectionLabel(t.shellPickerEyebrow),
                              Text(t.shellPickerTitle, style: N360Type.title),
                            ],
                          ),
                        ),
                        const SizedBox(height: 1.5, child: ColoredBox(color: N360Colors.ink)),
                        Flexible(child: body),
                      ],
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// Width of the column left of the red margin, where the project code sits.
const double _codeColumn = 84;

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
    return Semantics(
      container: true,
      button: true,
      label: <String>[project.code, project.name, ?location, if (isCurrent) currentLabel].join(', '),
      onTap: onTap,
      child: ExcludeSemantics(
        child: PressRow(
          onTap: onTap,
          minHeight: 76,
          child: Row(
            children: <Widget>[
              SizedBox(
                width: _codeColumn,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.xs, N360Space.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        project.code,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.figureSmall.copyWith(color: N360Colors.laterite, fontWeight: FontWeight.w500),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(width: N360Space.md + 3),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(project.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: N360Type.heading),
                      if (location != null)
                        Row(
                          children: <Widget>[
                            const Icon(Icons.place_outlined, size: 18, color: N360Colors.inkLabel),
                            const SizedBox(width: 2),
                            Flexible(child: Text(location, style: N360Type.body.copyWith(color: N360Colors.ink2))),
                          ],
                        ),
                    ],
                  ),
                ),
              ),
              if (isCurrent)
                Padding(
                  padding: const EdgeInsets.only(right: N360Space.xs),
                  child: RubberStamp(kind: StampKind.synced, label: currentLabel, size: StampSize.small),
                ),
              const Padding(
                padding: EdgeInsets.only(right: N360Space.sm),
                child: Icon(Icons.chevron_right, size: 28, color: N360Colors.ink2),
              ),
            ],
          ),
        ),
      ),
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
