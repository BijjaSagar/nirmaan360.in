import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show UserRow;
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/issue_form_cubit.dart';
import 'widgets/issue_widgets.dart';

/// Raising an issue takes under half a minute: a title, an urgency, done.
/// Everything else is optional, and says so.
class IssueFormScreen extends StatelessWidget {
  const IssueFormScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<IssueFormCubit>(
      create: (context) => IssueFormCubit(context.read<AppServices>()),
      child: const _IssueFormView(),
    );
  }
}

class _IssueFormView extends StatefulWidget {
  const _IssueFormView();

  @override
  State<_IssueFormView> createState() => _IssueFormViewState();
}

class _IssueFormViewState extends State<_IssueFormView> {
  final TextEditingController _title = TextEditingController();
  final TextEditingController _location = TextEditingController();

  @override
  void dispose() {
    _title.dispose();
    _location.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final IssueFormCubit cubit = context.read<IssueFormCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(title: _title.text, location: _location.text);
    if (!mounted || !saved) {
      if (mounted && !saved) N360Haptics.warning();
      return;
    }
    N360Haptics.success();
    showPaperToast(
      context,
      online ? l.issuesSavedOnline : l.issuesSavedOffline,
      kind: PaperToastKind.success,
      duration: const Duration(seconds: 3),
    );
    Navigator.of(context).maybePop();
  }

  Future<void> _pickAssignee(IssueFormState state) async {
    final IssueFormCubit cubit = context.read<IssueFormCubit>();
    final AssigneeChoice? choice = await showAssigneeSheet(
      context,
      users: state.users,
      current: state.assignedTo,
    );
    if (choice != null) cubit.setAssignee(choice.userId);
  }

  String? _assigneeName(IssueFormState state) {
    for (final UserRow user in state.users) {
      if (user.id == state.assignedTo) return user.name;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          bottom: false,
          child: BlocBuilder<IssueFormCubit, IssueFormState>(
            builder: (context, state) {
              final IssueFormCubit cubit = context.read<IssueFormCubit>();
              final bool failed =
                  state.problem == IssueFormProblem.saveFailed ||
                  state.problem == IssueFormProblem.noProject;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.issuesFormTitle, subtitle: l.issuesFormEyebrow),
                  Expanded(
                    child: ListView(
                      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                      padding: const EdgeInsets.fromLTRB(
                        N360Space.gutter,
                        N360Space.xs,
                        N360Space.gutter,
                        N360Space.sm,
                      ),
                      children: <Widget>[
                        StaggeredRise(
                          index: 0,
                          child: LedgerCard(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              mainAxisSize: MainAxisSize.min,
                              children: <Widget>[
                                GloveTextField(
                                  label: l.issuesFormWhat,
                                  controller: _title,
                                  hint: l.issuesFormWhatHint,
                                  textInputAction: TextInputAction.next,
                                  errorText: state.problem == IssueFormProblem.titleRequired
                                      ? l.issuesFormWhatRequired
                                      : null,
                                  onChanged: (_) => cubit.clearTitleProblem(),
                                ),
                                const SizedBox(height: N360Space.md),
                                _LabelledBlock(
                                  label: l.issuesFormPriority,
                                  child: PictoChoice<String>(
                                    columns: 4,
                                    value: state.priority,
                                    semanticsLabel: l.issuesFormPriority,
                                    onChanged: cubit.setPriority,
                                    options: <PictoOption<String>>[
                                      for (final p in N360Pictograms.issuePriorities)
                                        PictoOption<String>(
                                          value: p.key,
                                          icon: p.icon,
                                          label: l.pictogramLabel(p),
                                        ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(height: N360Space.cardGap),
                        StaggeredRise(
                          index: 1,
                          child: LedgerCard(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              mainAxisSize: MainAxisSize.min,
                              children: <Widget>[
                                GloveTextField(
                                  label: l.issuesFormLocation,
                                  controller: _location,
                                  hint: l.issuesFormLocationHint,
                                  prefixIcon: Icons.place_outlined,
                                  textInputAction: TextInputAction.done,
                                ),
                                const SizedBox(height: N360Space.md),
                                _LabelledBlock(
                                  label: l.issuesFormAssignee,
                                  child: _AssigneeField(
                                    name: _assigneeName(state),
                                    semanticsLabel:
                                        '${l.issuesFormAssignee}: ${_assigneeName(state) ?? l.issuesUnassigned}',
                                    onTap: () => _pickAssignee(state),
                                  ),
                                ),
                                const SizedBox(height: N360Space.md),
                                _LabelledBlock(
                                  label: l.issuesFormDue,
                                  child: _DueChips(
                                    value: state.due,
                                    onChanged: cubit.setDue,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  // A failure sits just above the button that caused it, where
                  // it cannot be scrolled out of sight.
                  if (failed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(
                        N360Space.gutter,
                        N360Space.xs,
                        N360Space.gutter,
                        0,
                      ),
                      child: ErrorSlip(
                        title: state.problem == IssueFormProblem.noProject
                            ? l.issuesNoProjectTitle
                            : l.issuesSaveFailed,
                      ),
                    ),
                  FieldActionBar(
                    child: GloveButton(
                      label: l.issuesFormSave,
                      icon: Icons.check,
                      large: true,
                      fullWidth: true,
                      loading: state.submitting,
                      onPressed: state.submitting ? null : _save,
                    ),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _LabelledBlock extends StatelessWidget {
  const _LabelledBlock({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.fit(N360Type.label, label)),
        const SizedBox(height: N360Space.xs),
        child,
      ],
    );
  }
}

/// The person chosen, drawn like the fields above it with a chevron, so it
/// reads as something that opens a list rather than a button that is already
/// pressed.
class _AssigneeField extends StatelessWidget {
  const _AssigneeField({required this.name, required this.semanticsLabel, required this.onTap});

  final String? name;
  final String semanticsLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String shown = name ?? l.issuesUnassigned;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: semanticsLabel,
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.md,
            border: Border.all(color: N360Colors.ruleStrong),
          ),
          child: child,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.sm),
        child: Row(
          children: <Widget>[
            const Icon(Icons.person_outline, size: N360IconSize.lg, color: N360Colors.ink2),
            const SizedBox(width: N360Space.md),
            Expanded(
              child: Text(
                shown,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: N360Type.fit(N360Type.bodyLarge, shown).copyWith(
                  color: name == null ? N360Colors.inkLabel : N360Colors.ink,
                ),
              ),
            ),
            const Icon(Icons.expand_more, size: N360IconSize.lg, color: N360Colors.ink2),
          ],
        ),
      ),
    );
  }
}

class _DueChips extends StatelessWidget {
  const _DueChips({required this.value, required this.onChanged});

  final DueChoice value;
  final ValueChanged<DueChoice> onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<(DueChoice, String)> options = <(DueChoice, String)>[
      (DueChoice.today, l.issuesFormDueToday),
      (DueChoice.threeDays, l.issuesFormDueThree),
      (DueChoice.week, l.issuesFormDueWeek),
    ];
    // Tapping the chosen chip again clears it, so no "no date" chip is needed.
    // The line under the chips says so once a day is chosen.
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Row(
          children: <Widget>[
            for (final (index, (choice, label)) in options.indexed) ...<Widget>[
              if (index > 0) const SizedBox(width: N360Space.sm),
              Expanded(
                child: FieldChoiceTile(
                  width: double.infinity,
                  label: label,
                  selected: value == choice,
                  onTap: () => onChanged(value == choice ? DueChoice.none : choice),
                ),
              ),
            ],
          ],
        ),
        if (value != DueChoice.none) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          Text(
            l.issuesFormDueClear,
            style: N360Type.fit(N360Type.caption, l.issuesFormDueClear),
          ),
        ],
      ],
    );
  }
}
