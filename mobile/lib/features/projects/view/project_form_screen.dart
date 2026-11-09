import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../app/shell/sync_status_cubit.dart';
import '../../../app/widgets/back_header.dart';
import '../../../app/widgets/press_row.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/project_form_cubit.dart';

/// One calm screen to start a new site. Needs network: the server names the
/// project and enforces the plan's limit.
class ProjectFormScreen extends StatelessWidget {
  const ProjectFormScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProjectFormCubit>(
      create: (context) => ProjectFormCubit(services: context.read<AppServices>()),
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
  String? _startDate;
  String? _endDate;
  String _value = '';
  bool _nameMissing = false;
  bool _endBeforeStart = false;

  @override
  void dispose() {
    _name.dispose();
    _location.dispose();
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

  Future<void> _enterValue() async {
    FocusScope.of(context).unfocus();
    final String? result = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _ValueSheet(initial: _value),
    );
    if (result != null && mounted) setState(() => _value = result);
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    final String name = _name.text.trim();
    final bool badDates = _startDate != null && _endDate != null && _endDate!.compareTo(_startDate!) < 0;
    setState(() {
      _nameMissing = name.isEmpty;
      _endBeforeStart = badDates;
    });
    if (name.isEmpty || badDates) return;

    final String location = _location.text.trim();
    final Money? value = Money.tryParse(_value);
    context.read<ProjectFormCubit>().submit(
          name: name,
          location: location.isEmpty ? null : location,
          startDate: _startDate,
          endDate: _endDate,
          contractValue: value == null || value.isZero ? null : value,
        );
  }

  Future<void> _finish(BuildContext context, ProjectFormState state) async {
    await context.read<AppGate>().adoptProject(state.created!);
    if (context.mounted) context.go(Routes.home);
  }

  ({String title, String body})? _message(ProjectFormState state, bool online, AppLocalizations t) {
    return switch (state.problem) {
      ProjectFormProblem.offline => (title: t.shellSignInNetworkTitle, body: t.shellProjectOffline),
      ProjectFormProblem.planLimit => (title: t.shellProjectPlanTitle, body: state.planMessage ?? ''),
      ProjectFormProblem.server => (title: t.shellSignInServerTitle, body: t.shellSignInServerBody),
      null => online ? null : (title: t.shellSignInNetworkTitle, body: t.shellProjectOffline),
    };
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).languageCode;
    final bool online = context.select((SyncStatusCubit s) => s.state.online);
    final Money? value = Money.tryParse(_value);

    return BlocConsumer<ProjectFormCubit, ProjectFormState>(
      listenWhen: (previous, next) => previous.created == null && next.created != null,
      listener: _finish,
      builder: (context, state) {
        final message = _message(state, online, t);
        final bool busy = state.submitting || state.created != null;
        final Map<String, String> errors = state.fieldErrors;
        return Scaffold(
          body: SafeArea(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                Expanded(
                  child: SingleChildScrollView(
                    physics: const ClampingScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: <Widget>[
                        PageHeader(title: t.shellProjectFormTitle),
                        const SizedBox(height: N360Space.md),
                        StaggeredRise(
                          index: 0,
                          child: LedgerCard(
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
                                const SizedBox(height: N360Space.sm),
                                GloveTextField(
                                  label: t.shellProjectLocation,
                                  controller: _location,
                                  textInputAction: TextInputAction.done,
                                  textCapitalization: TextCapitalization.words,
                                  enabled: !busy,
                                  errorText: errors['location'],
                                ),
                                const SizedBox(height: N360Space.sm),
                                IntrinsicHeight(
                                  child: Row(
                                    crossAxisAlignment: CrossAxisAlignment.stretch,
                                    children: <Widget>[
                                      Expanded(
                                        child: _TapField(
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
                                        child: _TapField(
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
                                const SizedBox(height: N360Space.sm),
                                _TapField(
                                  label: t.shellProjectValue,
                                  value: value == null || value.isZero ? null : value.inr(),
                                  placeholder: t.shellProjectValueEmpty,
                                  icon: Icons.currency_rupee,
                                  errorText: errors['contract_value'],
                                  onTap: busy ? null : _enterValue,
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (message != null) ...<Widget>[
                        ErrorSlip(title: message.title, message: message.body),
                        const SizedBox(height: N360Space.sm),
                      ],
                      GloveButton(
                        label: busy ? t.shellProjectCreating : t.shellProjectCreate,
                        onPressed: _submit,
                        loading: busy,
                        large: true,
                        fullWidth: true,
                        icon: Icons.add_home_work_outlined,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

/// A field that opens a picker: label above, value in a box the same size as
/// a text field.
class _TapField extends StatelessWidget {
  const _TapField({
    required this.label,
    required this.value,
    required this.placeholder,
    required this.icon,
    required this.onTap,
    this.errorText,
  });

  final String label;
  final String? value;
  final String placeholder;
  final IconData icon;
  final VoidCallback? onTap;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    final String? shown = value;
    final String? error = errorText;
    final Widget box = DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.md,
        border: Border.all(color: error == null ? N360Colors.ruleStrong : N360Colors.stampRed, width: error == null ? 1 : 2),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tapLarge),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md),
          child: Row(
            children: <Widget>[
              Icon(icon, size: 22, color: N360Colors.ink2),
              const SizedBox(width: N360Space.sm),
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    shown ?? placeholder,
                    maxLines: 1,
                    style: N360Type.bodyLarge.copyWith(color: shown == null ? N360Colors.inkLabel : N360Colors.ink),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );

    final Widget field = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, maxLines: 2, style: N360Type.label),
        const SizedBox(height: N360Space.xs),
        box,
        if (error != null) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          Text(error, style: N360Type.caption.copyWith(color: N360Colors.stampRed, fontWeight: FontWeight.w600)),
        ],
      ],
    );

    final tap = onTap;
    return Semantics(
      container: true,
      button: true,
      enabled: tap != null,
      label: '$label, ${shown ?? placeholder}${error == null ? '' : '. $error'}',
      onTap: tap,
      child: ExcludeSemantics(
        child: tap == null ? field : PressRow(onTap: tap, child: field),
      ),
    );
  }
}

/// The contract value, entered on the glove keypad.
class _ValueSheet extends StatefulWidget {
  const _ValueSheet({required this.initial});

  final String initial;

  @override
  State<_ValueSheet> createState() => _ValueSheetState();
}

class _ValueSheetState extends State<_ValueSheet> {
  late String _text = widget.initial;

  void _done() {
    // A trailing point is unfinished typing, not part of the figure.
    final String clean = _text.endsWith('.') ? _text.substring(0, _text.length - 1) : _text;
    Navigator.of(context).pop(clean);
  }

  @override
  Widget build(BuildContext context) {
    final t = AppLocalizations.of(context);
    final Money amount = Money.tryParse(_text.endsWith('.') ? _text.substring(0, _text.length - 1) : _text) ?? Money.zero;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(N360Space.lg),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Text(t.shellProjectValue, style: N360Type.heading),
            Align(
              alignment: Alignment.centerRight,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: RollingMoney(value: amount.inr(), style: N360Type.figureHero.copyWith(fontSize: 36)),
              ),
            ),
            const SizedBox(height: N360Space.sm),
            GloveKeypad(
              value: _text,
              decimalPlaces: 2,
              maxIntegerDigits: 11,
              onChanged: (next) => setState(() => _text = next),
              onNext: _done,
              nextLabel: t.commonDone,
              nextIcon: Icons.check,
              backspaceLabel: t.designKeypadDelete,
            ),
          ],
        ),
      ),
    );
  }
}
