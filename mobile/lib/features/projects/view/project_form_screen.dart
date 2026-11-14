import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/back_header.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/project_form_cubit.dart';
import '../data/project_detail.dart';
import 'widgets/value_plan_editor.dart';

/// One calm screen to start a new site, and with a [projectId] to change one.
/// Needs network: the server names the project, enforces the plan's limit and
/// keeps a value split into phases equal to its phases.
class ProjectFormScreen extends StatelessWidget {
  const ProjectFormScreen({super.key, this.projectId});

  /// The project to change. Null for a new one.
  final int? projectId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProjectFormCubit>(
      create: (context) {
        final cubit = ProjectFormCubit(services: context.read<AppServices>(), projectId: projectId);
        if (projectId != null) cubit.load();
        return cubit;
      },
      child: const _ProjectFormView(),
    );
  }
}

class _ProjectFormView extends StatefulWidget {
  const _ProjectFormView();

  @override
  State<_ProjectFormView> createState() => _ProjectFormViewState();
}

class _ProjectFormViewState extends State<_ProjectFormView> {
  final TextEditingController _name = TextEditingController();
  final TextEditingController _location = TextEditingController();
  final ValuePlanController _value = ValuePlanController();
  String? _startDate;
  String? _endDate;
  bool _nameMissing = false;
  bool _endBeforeStart = false;

  @override
  void dispose() {
    _name.dispose();
    _location.dispose();
    _value.dispose();
    super.dispose();
  }

  Future<void> _pickDate({required bool start}) async {
    FocusScope.of(context).unfocus();
    final DateTime today = DateTime.parse(context.read<AppServices>().context.today);
    final String? current = start ? _startDate : _endDate;
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: current == null ? today : DateTime.parse(current),
      firstDate: DateTime(today.year - 10),
      lastDate: DateTime(today.year + 15),
    );
    if (picked == null || !mounted) return;
    String two(int value) => value.toString().padLeft(2, '0');
    final String iso = '${picked.year}-${two(picked.month)}-${two(picked.day)}';
    setState(() {
      if (start) {
        _startDate = iso;
      } else {
        _endDate = iso;
      }
      _endBeforeStart = false;
    });
  }

  /// The form, filled from the project as the server has it.
  void _fill(ProjectDetail detail) {
    setState(() {
      _name.text = detail.name;
      _location.text = detail.location ?? '';
      _startDate = detail.startDate;
      _endDate = detail.endDate;
    });
    _value.load(value: detail.contractValue, phases: detail.phases ?? const <ValuePhase>[]);
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    final ProjectFormCubit cubit = context.read<ProjectFormCubit>();
    final String name = _name.text.trim();
    final bool badDates = _startDate != null && _endDate != null && _endDate!.compareTo(_startDate!) < 0;
    setState(() {
      _nameMissing = name.isEmpty;
      _endBeforeStart = badDates;
    });
    // Someone the server shows no money to is not asked for it either.
    final bool withMoney = !cubit.isEdit || (cubit.state.detail?.seesMoney ?? false);
    final ValuePlan? plan = withMoney ? _value.read() : const ValuePlan();
    if (name.isEmpty || badDates || plan == null) return;

    final String location = _location.text.trim();
    if (cubit.isEdit) {
      cubit.save(
        name: name,
        location: location.isEmpty ? null : location,
        startDate: _startDate,
        endDate: _endDate,
        contractValue: plan.single,
        phases: plan.phases,
      );
    } else {
      cubit.submit(
        name: name,
        location: location.isEmpty ? null : location,
        startDate: _startDate,
        endDate: _endDate,
        contractValue: plan.single,
        phases: plan.phases.isEmpty ? null : plan.phases,
      );
    }
  }

  Future<void> _finish(BuildContext context, ProjectFormState state) async {
    await context.read<AppGate>().adoptProject(state.created!);
    if (context.mounted) context.go(Routes.home);
  }

  void _listen(BuildContext context, ProjectFormState state) {
    if (state.created != null) {
      _finish(context, state);
    } else if (state.saved != null) {
      if (context.canPop()) {
        context.pop();
      } else {
        context.go(Routes.home);
      }
    } else if (state.detail != null) {
      _fill(state.detail!);
    }
  }

  ({String title, String body})? _message(ProjectFormState state, bool online, bool edit, AppLocalizations t) {
    final String offline = edit ? t.projectEditOffline : t.shellProjectOffline;
    return switch (state.problem ?? state.loadProblem) {
      ProjectFormProblem.offline => (title: t.shellSignInNetworkTitle, body: offline),
      // In the app's own words: the server's text invites an upgrade, which the app never does.
      ProjectFormProblem.planLimit => (title: t.shellProjectPlanTitle, body: t.shellProjectPlanBody),
      ProjectFormProblem.server => (title: t.shellSignInServerTitle, body: t.shellSignInServerBody),
      null => online ? null : (title: t.shellSignInNetworkTitle, body: offline),
    };
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final bool online = context.select((SyncStatusCubit s) => s.state.online);
    final bool edit = context.read<ProjectFormCubit>().isEdit;

    return BlocConsumer<ProjectFormCubit, ProjectFormState>(
      listenWhen: (previous, next) =>
          (previous.created == null && next.created != null) ||
          (previous.saved == null && next.saved != null) ||
          (previous.detail == null && next.detail != null),
      listener: _listen,
      builder: (context, state) {
        final message = _message(state, online, edit, t);
        final bool waiting = edit && state.detail == null;
        final bool busy = state.submitting || state.created != null || state.saved != null || waiting;
        final bool showValue = !edit || (state.detail?.seesMoney ?? false);
        final Map<String, String> errors = state.fieldErrors;
        final String action = edit
            ? (state.submitting ? t.projectEditSaving : t.projectEditSave)
            : (busy ? t.shellProjectCreating : t.shellProjectCreate);
        return Scaffold(
          // A form is one continuous white sheet, from the bar to the action bar.
          backgroundColor: N360Colors.sheet,
          body: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              PageHeader(title: edit ? t.projectEditTitle : t.shellProjectFormTitle),
              if (state.loading) ProgressRule(semanticsLabel: t.projectEditLoading),
              Expanded(
                child: waiting
                    ? _Waiting(loading: state.loading, onRetry: context.read<ProjectFormCubit>().load)
                    : SingleChildScrollView(
                        physics: const ClampingScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.md),
                        child: StaggeredRise(
                          index: 0,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: <Widget>[
                              GloveTextField(
                                label: t.shellProjectName,
                                controller: _name,
                                textInputAction: TextInputAction.next,
                                textCapitalization: TextCapitalization.words,
                                enabled: !busy,
                                errorText: _nameMissing ? t.shellProjectNameRequired : errors['name'],
                              ),
                              const SizedBox(height: N360Space.md),
                              GloveTextField(
                                label: t.shellProjectLocation,
                                controller: _location,
                                textInputAction: TextInputAction.done,
                                textCapitalization: TextCapitalization.words,
                                enabled: !busy,
                                errorText: errors['location'],
                              ),
                              const SizedBox(height: N360Space.md),
                              IntrinsicHeight(
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.stretch,
                                  children: <Widget>[
                                    Expanded(
                                      child: ProjectTapField(
                                        label: t.shellProjectStart,
                                        value: _startDate == null ? null : AppFormat.dayMonthYear(_startDate!, locale),
                                        placeholder: t.shellProjectChoose,
                                        icon: Icons.calendar_month_outlined,
                                        errorText: errors['start_date'],
                                        onTap: busy ? null : () => _pickDate(start: true),
                                      ),
                                    ),
                                    const SizedBox(width: N360Space.sm),
                                    Expanded(
                                      child: ProjectTapField(
                                        label: t.shellProjectEnd,
                                        value: _endDate == null ? null : AppFormat.dayMonthYear(_endDate!, locale),
                                        placeholder: t.shellProjectChoose,
                                        icon: Icons.event_available_outlined,
                                        errorText: _endBeforeStart ? t.shellProjectEndBeforeStart : errors['end_date'],
                                        onTap: busy ? null : () => _pickDate(start: false),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              if (showValue) ...<Widget>[
                                const SizedBox(height: N360Space.md),
                                ValuePlanEditor(controller: _value, enabled: !busy, errors: errors),
                              ],
                            ],
                          ),
                        ),
                      ),
              ),
              N360ActionBar(
                above: message == null ? null : ErrorSlip(title: message.title, message: message.body),
                child: GloveButton(
                  label: action,
                  onPressed: waiting ? null : _submit,
                  loading: state.submitting || state.created != null || state.saved != null,
                  large: true,
                  fullWidth: true,
                  icon: edit ? Icons.check : Icons.add_home_work_outlined,
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

/// While the project is read, and after reading it failed: a way to try again.
class _Waiting extends StatelessWidget {
  const _Waiting({required this.loading, required this.onRetry});

  final bool loading;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    if (loading) return const SizedBox.shrink();
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: GloveButton(label: t.commonRetry, icon: Icons.refresh, onPressed: onRetry),
      ),
    );
  }
}
