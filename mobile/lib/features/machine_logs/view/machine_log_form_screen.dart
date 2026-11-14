import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../bloc/machine_log_form_cubit.dart';
import '../data/machine_repository.dart';
import 'machine_words.dart';

/// A quick entry: the machine, the day, and the hours or readings, as the
/// server's rules want them. It works from the last copy of the machines on the
/// phone, and the log waits there until it can be sent.
class MachineLogFormScreen extends StatelessWidget {
  const MachineLogFormScreen({super.key, this.machineId});

  final int? machineId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MachineLogFormCubit>(
      create: (context) => MachineLogFormCubit(context.read<AppServices>(), machineId: machineId),
      child: const _FormView(),
    );
  }
}

class _FormView extends StatefulWidget {
  const _FormView();

  @override
  State<_FormView> createState() => _FormViewState();
}

class _FormViewState extends State<_FormView> {
  final TextEditingController _hours = TextEditingController();
  final TextEditingController _km = TextEditingController();
  final TextEditingController _opening = TextEditingController();
  final TextEditingController _closing = TextEditingController();
  final TextEditingController _breakdown = TextEditingController();
  final TextEditingController _operator = TextEditingController();
  final TextEditingController _remarks = TextEditingController();

  @override
  void dispose() {
    for (final TextEditingController controller in <TextEditingController>[
      _hours,
      _km,
      _opening,
      _closing,
      _breakdown,
      _operator,
      _remarks,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final MachineLogFormCubit cubit = context.read<MachineLogFormCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(
      MachineFormText(
        hours: _hours.text,
        km: _km.text,
        breakdown: _breakdown.text,
        opening: _opening.text,
        closing: _closing.text,
        operatorName: _operator.text,
        remarks: _remarks.text,
      ),
    );
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      return;
    }
    N360Haptics.success();
    showPaperToast(
      context,
      online ? l.progressSavedOnline : l.progressSavedOffline,
      kind: PaperToastKind.success,
      duration: const Duration(seconds: 3),
    );
    Navigator.of(context).pop();
  }

  Future<void> _pickMachine(MachineFormState state) async {
    final MachineLogFormCubit cubit = context.read<MachineLogFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.machineFormSearch,
      emptyTitle: l.machineFormNoneMatch,
      options: <FieldPickOption>[
        for (final Machine row in state.machines)
          FieldPickOption(
            id: row.id,
            title: row.displayName,
            subtitle: <String>[
              ?l.machineCategoryWord(row.category),
              ?row.registrationNo,
              if (row.underRepair) l.machinesUnderRepair,
            ].join(' · '),
          ),
      ],
    );
    if (id != null) cubit.setMachine(id);
  }

  Future<void> _pickOtherDate(MachineFormState state) async {
    final MachineLogFormCubit cubit = context.read<MachineLogFormCubit>();
    final DateTime last = DateTime.parse(state.today);
    final DateTime initial = DateTime.tryParse(state.date) ?? last;
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isAfter(last) ? last : initial,
      firstDate: DateTime(last.year - 2),
      lastDate: last,
    );
    if (picked == null || !mounted) return;
    String two(int value) => value.toString().padLeft(2, '0');
    cubit.setDate('${picked.year}-${two(picked.month)}-${two(picked.day)}');
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      backgroundColor: N360Colors.sheet,
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<MachineLogFormCubit, MachineFormState>(
            builder: (context, state) {
              final bool noData = state.ready && (state.overview == null || state.overview!.machines.isEmpty);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.machinesAdd, subtitle: state.machine?.code),
                  Expanded(
                    child: noData
                        ? EmptyLedger(
                            title: state.overview == null ? l.progressNoDataTitle : l.machineFormNoMachinesTitle,
                            message: state.overview == null ? l.machinesNoDataMessage : l.machinesNoMachinesMessage,
                          )
                        : ListView(
                            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                            padding: const EdgeInsets.fromLTRB(
                              N360Space.gutter,
                              N360Space.lg,
                              N360Space.gutter,
                              N360Space.sm,
                            ),
                            children: _fields(context, l, state),
                          ),
                  ),
                  if (state.problem == MachineFormProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: l.progressSaveFailed),
                    ),
                  if (!noData)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('machine-form-save'),
                        label: l.machineFormSave,
                        icon: Icons.check,
                        large: true,
                        fullWidth: true,
                        loading: state.submitting,
                        onPressed: state.submitting || !state.ready ? null : _save,
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

  /// A row of choices. A `Row` would overflow at 360dp in Hindi and Marathi.
  Widget _chips(List<Widget> chips) => Wrap(
    spacing: N360Space.sm,
    runSpacing: N360Space.sm,
    children: <Widget>[for (final Widget chip in chips) IntrinsicWidth(child: chip)],
  );

  /// A number box: digits and one point, which the phone's own keypad offers.
  Widget _number(
    Key key,
    String label,
    TextEditingController controller, {
    String? helper,
    String? error,
    required MachineLogFormCubit cubit,
  }) {
    return GloveTextField(
      key: key,
      label: label,
      controller: controller,
      helper: helper,
      errorText: error,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp(r'[0-9.,०-९]'))],
      onChanged: (_) => cubit.clearProblem(),
    );
  }

  List<Widget> _fields(BuildContext context, AppLocalizations l, MachineFormState state) {
    final MachineLogFormCubit cubit = context.read<MachineLogFormCubit>();
    const Widget gap = SizedBox(height: N360Space.lg);
    final Machine? machine = state.machine;
    final MachineFormProblem? problem = state.problem;
    final bool other = state.date != state.today && state.date != state.yesterday;
    final Hours? logged = state.loggedToday;
    final String? readingsHint = machine == null
        ? null
        : machine.meterIsHours
        ? l.machineFormReadingsHintHours
        : machine.meterIsKm
        ? l.machineFormReadingsHintKm
        : null;

    return <Widget>[
      FieldBlock(
        label: l.machineFormMachine,
        error: problem == MachineFormProblem.machineRequired ? l.machineFormMachineRequired : null,
        child: FieldPickerTile(
          key: const ValueKey<String>('machine-form-machine'),
          icon: Icons.precision_manufacturing_outlined,
          text: machine?.displayName ?? l.machineFormChoose,
          muted: machine == null,
          semanticsLabel: l.machineFormMachine,
          onTap: () => _pickMachine(state),
        ),
      ),
      if (machine != null) ...<Widget>[
        const SizedBox(height: N360Space.xs),
        Builder(
          builder: (context) {
            final String line = <String>[
              ?l.machineCategoryWord(machine.category),
              ?machine.registrationNo,
              if (machine.underRepair) l.machinesUnderRepair,
            ].join(' · ');
            return line.isEmpty ? const SizedBox.shrink() : Text(line, style: N360Type.fit(N360Type.caption, line));
          },
        ),
      ],
      gap,
      FieldBlock(
        label: l.machineFormDate,
        child: _chips(<Widget>[
          N360Chip(
            key: const ValueKey<String>('machine-form-date-today'),
            label: l.progressFormToday,
            selected: state.date == state.today,
            onTap: () => cubit.setDate(state.today),
          ),
          N360Chip(
            key: const ValueKey<String>('machine-form-date-yesterday'),
            label: l.progressFormYesterday,
            selected: state.date == state.yesterday,
            onTap: () => cubit.setDate(state.yesterday),
          ),
          N360Chip(
            key: const ValueKey<String>('machine-form-date-other'),
            label: other ? dayLabel(context, state.date, state.today) : l.contractorOtherDate,
            selected: other,
            onTap: () => _pickOtherDate(state),
          ),
        ]),
      ),
      if (logged != null) ...<Widget>[
        const SizedBox(height: N360Space.sm),
        FieldInlineNote(key: const ValueKey<String>('machine-form-logged'), message: l.machineFormLogged(logged.toWire())),
      ],
      gap,
      _number(
        const ValueKey<String>('machine-form-hours'),
        l.machineFormHours,
        _hours,
        helper: l.machineFormHoursHint,
        error: problem == MachineFormProblem.hoursInvalid ? l.machineFormHoursError : null,
        cubit: cubit,
      ),
      if (machine?.meterIsKm ?? false) ...<Widget>[
        gap,
        _number(
          const ValueKey<String>('machine-form-km'),
          l.machineFormKm,
          _km,
          error: problem == MachineFormProblem.kmInvalid ? l.machineFormKmError : null,
          cubit: cubit,
        ),
      ],
      gap,
      _number(
        const ValueKey<String>('machine-form-opening'),
        l.machineFormOpening,
        _opening,
        error: problem == MachineFormProblem.openingInvalid ? l.machineFormReadingError : null,
        cubit: cubit,
      ),
      gap,
      _number(
        const ValueKey<String>('machine-form-closing'),
        l.machineFormClosing,
        _closing,
        helper: readingsHint,
        error: switch (problem) {
          MachineFormProblem.closingInvalid => l.machineFormReadingError,
          MachineFormProblem.closingBelowOpening => l.machineFormClosingBelow,
          _ => null,
        },
        cubit: cubit,
      ),
      gap,
      _number(
        const ValueKey<String>('machine-form-breakdown'),
        l.machineFormBreakdown,
        _breakdown,
        helper: l.contractorOptional,
        error: problem == MachineFormProblem.breakdownInvalid ? l.machineFormHoursError : null,
        cubit: cubit,
      ),
      if (problem == MachineFormProblem.nothingEntered) ...<Widget>[
        const SizedBox(height: N360Space.md),
        FieldInlineError(l.machineFormNothing),
      ],
      gap,
      GloveTextField(
        key: const ValueKey<String>('machine-form-operator'),
        label: l.machineFormOperator,
        helper: l.contractorOptional,
        controller: _operator,
        inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MachineRepository.operatorMax)],
      ),
      gap,
      GloveTextField(
        key: const ValueKey<String>('machine-form-remarks'),
        label: l.machineFormRemarks,
        helper: l.contractorOptional,
        controller: _remarks,
        maxLines: 2,
        inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MachineRepository.remarksMax)],
      ),
    ];
  }
}

/// A short, calm note under a field, with a mark so it is not colour alone.
class FieldInlineNote extends StatelessWidget {
  const FieldInlineNote({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: message,
      child: ExcludeSemantics(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            const Padding(
              padding: EdgeInsets.only(top: N360Space.xxs, right: N360Space.sm),
              child: Icon(Icons.info_outline, size: N360IconSize.md, color: N360Colors.ink2),
            ),
            Expanded(child: Text(message, style: N360Type.fit(N360Type.label, message))),
          ],
        ),
      ),
    );
  }
}
