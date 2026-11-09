import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show UserRow;
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/issue_form_cubit.dart';
import 'widgets/issue_widgets.dart';

/// Raising an issue takes under half a minute: a title, an urgency, done.
/// Everything else is optional.
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
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(
                        N360Space.gutter,
                        N360Space.xs,
                        N360Space.gutter,
                        N360Space.gutter,
                      ),
                      children: <Widget>[
                        Align(
                          alignment: Alignment.centerLeft,
                          child: GloveButton(
                            label: l.commonBack,
                            kind: GloveButtonKind.ghost,
                            icon: Icons.arrow_back,
                            onPressed: () => Navigator.of(context).maybePop(),
                          ),
                        ),
                        SheetHeader(
                          eyebrow: l.issuesFormEyebrow,
                          title: l.issuesFormTitle,
                        ),
                        const SizedBox(height: N360Space.md),
                        StaggeredRise(
                          index: 0,
                          child: GloveTextField(
                            label: l.issuesFormWhat,
                            controller: _title,
                            hint: l.issuesFormWhatHint,
                            textInputAction: TextInputAction.next,
                            errorText: state.problem == IssueFormProblem.titleRequired
                                ? l.issuesFormWhatRequired
                                : null,
                            onChanged: (_) => cubit.clearTitleProblem(),
                          ),
                        ),
                        const SizedBox(height: N360Space.md),
                        StaggeredRise(
                          index: 1,
                          child: _LabelledBlock(
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
                        ),
                        const SizedBox(height: N360Space.md),
                        StaggeredRise(
                          index: 2,
                          child: GloveTextField(
                            label: l.issuesFormLocation,
                            controller: _location,
                            hint: l.issuesFormLocationHint,
                            prefixIcon: Icons.place_outlined,
                            textInputAction: TextInputAction.done,
                          ),
                        ),
                        const SizedBox(height: N360Space.md),
                        StaggeredRise(
                          index: 3,
                          child: _LabelledBlock(
                            label: l.issuesFormAssignee,
                            child: SelectTile(
                              icon: Icons.person_outline,
                              label: _assigneeName(state) ?? l.issuesUnassigned,
                              selected: false,
                              semanticsLabel:
                                  '${l.issuesFormAssignee}: ${_assigneeName(state) ?? l.issuesUnassigned}',
                              onTap: () => _pickAssignee(state),
                            ),
                          ),
                        ),
                        const SizedBox(height: N360Space.md),
                        StaggeredRise(
                          index: 4,
                          child: _LabelledBlock(
                            label: l.issuesFormDue,
                            child: _DueChips(
                              value: state.due,
                              onChanged: cubit.setDue,
                            ),
                          ),
                        ),
                        if (state.problem == IssueFormProblem.saveFailed ||
                            state.problem == IssueFormProblem.noProject) ...<Widget>[
                          const SizedBox(height: N360Space.md),
                          ErrorSlip(
                            title: state.problem == IssueFormProblem.noProject
                                ? l.issuesNoProjectTitle
                                : l.issuesSaveFailed,
                          ),
                        ],
                        const SizedBox(height: N360Space.sm),
                      ],
                    ),
                  ),
                  _SaveBar(
                    label: l.issuesFormSave,
                    busy: state.submitting,
                    onPressed: _save,
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
        Text(label, style: N360Type.label),
        const SizedBox(height: N360Space.xs),
        child,
      ],
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
    return Wrap(
      spacing: N360Space.sm,
      runSpacing: N360Space.sm,
      children: <Widget>[
        for (final (choice, label) in options)
          SelectTile(
            label: label,
            selected: value == choice,
            onTap: () => onChanged(value == choice ? DueChoice.none : choice),
          ),
        SelectTile(
          label: l.issuesFormNoDue,
          selected: value == DueChoice.none,
          onTap: () => onChanged(DueChoice.none),
        ),
      ],
    );
  }
}

class _SaveBar extends StatelessWidget {
  const _SaveBar({required this.label, required this.busy, required this.onPressed});

  final String label;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.sheet,
        border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            N360Space.sm,
            N360Space.gutter,
            N360Space.sm,
          ),
          child: GloveButton(
            label: label,
            icon: Icons.check,
            large: true,
            fullWidth: true,
            loading: busy,
            onPressed: busy ? null : onPressed,
          ),
        ),
      ),
    );
  }
}
