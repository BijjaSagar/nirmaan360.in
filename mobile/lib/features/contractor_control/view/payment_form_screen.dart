import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../../site_progress/data/progress_models.dart' show percentText;
import '../../site_progress/view/widgets/progress_widgets.dart' show ProgressPhotoRow;
import '../bloc/payment_form_cubit.dart';
import '../data/contractor_repository.dart';
import 'widgets/contractor_kit.dart';

/// Asks for a payment against a contract. The request waits on this phone until
/// it can be sent, then goes for approval.
class PaymentFormScreen extends StatelessWidget {
  const PaymentFormScreen({super.key, this.workOrderId});

  final int? workOrderId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PaymentFormCubit>(
      create: (context) => PaymentFormCubit(context.read<AppServices>(), workOrderId: workOrderId),
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
  final TextEditingController _amount = TextEditingController();
  final TextEditingController _paidTo = TextEditingController();
  final TextEditingController _reference = TextEditingController();
  final TextEditingController _notes = TextEditingController();

  @override
  void dispose() {
    for (final TextEditingController controller in <TextEditingController>[_amount, _paidTo, _reference, _notes]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final PaymentFormCubit cubit = context.read<PaymentFormCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(
      PaymentFormText(amount: _amount.text, paidTo: _paidTo.text, reference: _reference.text, notes: _notes.text),
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

  Future<void> _pickContract(PaymentFormState state) async {
    final PaymentFormCubit cubit = context.read<PaymentFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.contractorContractSearch,
      emptyTitle: l.contractorSearchNone,
      options: <FieldPickOption>[
        for (final WorkContract row in state.openContracts)
          FieldPickOption(id: row.workOrderId, title: row.displayName, subtitle: '${row.number} · ${row.title ?? ''}'.trim()),
      ],
    );
    if (id != null) cubit.setContract(id);
  }

  Future<void> _pickLine(PaymentFormState state) async {
    final PaymentFormCubit cubit = context.read<PaymentFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.progressFormItemSearch,
      emptyTitle: l.contractorSearchNone,
      options: <FieldPickOption>[
        for (final ContractLine row in state.detail?.lines ?? const <ContractLine>[])
          if (row.boqItemId != null) FieldPickOption(id: row.boqItemId!, title: row.displayName),
      ],
    );
    if (id != null) cubit.setLine(id);
  }

  Future<void> _pickPart(PaymentFormState state) async {
    final PaymentFormCubit cubit = context.read<PaymentFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.contractorContractSearch,
      emptyTitle: l.contractorSearchNone,
      options: <FieldPickOption>[
        for (final ContractPart row in state.detail?.parts ?? const <ContractPart>[])
          FieldPickOption(id: row.id, title: row.title),
      ],
    );
    if (id != null) cubit.setPart(id);
  }

  Future<void> _pickOtherDate(PaymentFormState state) async {
    final PaymentFormCubit cubit = context.read<PaymentFormCubit>();
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
          child: BlocBuilder<PaymentFormCubit, PaymentFormState>(
            builder: (context, state) {
              final bool noData = state.ready && state.openContracts.isEmpty;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.contractorRequest, subtitle: state.contract?.number),
                  Expanded(
                    child: noData
                        ? EmptyLedger(
                            title: state.overview == null ? l.progressNoDataTitle : l.contractorEmptyTitle,
                            message: state.overview == null ? l.contractorNoDataMessage : l.contractorEmptyMessage,
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
                  if (state.problem == PaymentFormProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: l.progressSaveFailed),
                    ),
                  if (!noData)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('payment-form-send'),
                        label: l.contractorSend,
                        icon: Icons.send,
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

  List<Widget> _fields(BuildContext context, AppLocalizations l, PaymentFormState state) {
    final PaymentFormCubit cubit = context.read<PaymentFormCubit>();
    const Widget gap = SizedBox(height: N360Space.lg);
    final WorkContract? contract = state.contract;
    final ContractDetail? detail = state.detail;
    final bool hasLines = detail?.lines.any((ContractLine line) => line.boqItemId != null) ?? false;
    final bool hasParts = detail?.parts.isNotEmpty ?? false;
    final bool other = state.date != state.today && state.date != state.yesterday;
    final PaymentFormProblem? problem = state.problem;
    final ContractLine? line = state.line;
    final ContractPart? part = state.part;

    return <Widget>[
      FieldBlock(
        label: l.contractorContract,
        error: switch (problem) {
          PaymentFormProblem.contractRequired => l.contractorContractChoose,
          PaymentFormProblem.contractClosed => l.contractorContractClosed,
          _ => null,
        },
        child: FieldPickerTile(
          key: const ValueKey<String>('payment-form-contract'),
          icon: Icons.handshake_outlined,
          text: contract?.displayName ?? l.contractorContractChoose,
          muted: contract == null,
          semanticsLabel: l.contractorContract,
          onTap: () => _pickContract(state),
        ),
      ),
      if (contract != null) ...<Widget>[
        const SizedBox(height: N360Space.xs),
        Builder(
          builder: (context) {
            // Someone without money.view is sent no paid figure to quote.
            final String line = contract.figures?.label ?? '${l.contractorWorkDone} ${percentText(contract.workPct)}';
            return Text(line, style: N360Type.fit(N360Type.caption, line));
          },
        ),
      ],
      gap,
      GloveTextField(
        key: const ValueKey<String>('payment-form-amount'),
        label: l.contractorAmount,
        controller: _amount,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        errorText: problem == PaymentFormProblem.amountInvalid ? l.contractorAmountError : null,
        onChanged: (_) => cubit.clearProblem(),
      ),
      gap,
      FieldBlock(
        label: l.contractorMode,
        error: problem == PaymentFormProblem.modeRequired ? l.contractorModeRequired : null,
        child: _chips(<Widget>[
          for (final String mode in kPaymentModes)
            N360Chip(
              key: ValueKey<String>('payment-form-mode-$mode'),
              label: l.paymentModeWord(mode),
              selected: state.mode == mode,
              onTap: () => cubit.setMode(mode),
            ),
        ]),
      ),
      gap,
      FieldBlock(
        label: l.contractorPaidOn,
        child: _chips(<Widget>[
          N360Chip(label: l.progressFormToday, selected: state.date == state.today, onTap: () => cubit.setDate(state.today)),
          N360Chip(
            label: l.progressFormYesterday,
            selected: state.date == state.yesterday,
            onTap: () => cubit.setDate(state.yesterday),
          ),
          N360Chip(
            key: const ValueKey<String>('payment-form-other-date'),
            label: other ? dayLabel(context, state.date, state.today) : l.contractorOtherDate,
            selected: other,
            onTap: () => _pickOtherDate(state),
          ),
        ]),
      ),
      gap,
      FieldBlock(
        label: l.contractorKind,
        child: _chips(<Widget>[
          N360Chip(label: l.contractorKindWork, selected: !state.isAdvance, onTap: () => cubit.setAdvance(false)),
          N360Chip(
            key: const ValueKey<String>('payment-form-advance'),
            label: l.contractorKindAdvance,
            selected: state.isAdvance,
            onTap: () => cubit.setAdvance(true),
          ),
        ]),
      ),
      if (hasLines || hasParts) ...<Widget>[
        gap,
        FieldBlock(
          label: l.contractorAgainst,
          child: _chips(<Widget>[
            N360Chip(
              label: l.contractorAgainstWhole,
              selected: state.against == PaymentAgainst.whole,
              onTap: () => cubit.setAgainst(PaymentAgainst.whole),
            ),
            if (hasLines)
              N360Chip(
                key: const ValueKey<String>('payment-form-against-line'),
                label: l.contractorAgainstLine,
                selected: state.against == PaymentAgainst.line,
                onTap: () => cubit.setAgainst(PaymentAgainst.line),
              ),
            if (hasParts)
              N360Chip(
                key: const ValueKey<String>('payment-form-against-part'),
                label: l.contractorAgainstPart,
                selected: state.against == PaymentAgainst.part,
                onTap: () => cubit.setAgainst(PaymentAgainst.part),
              ),
          ]),
        ),
        if (state.against == PaymentAgainst.line) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          FieldBlock(
            label: l.contractorAgainstLine,
            error: problem == PaymentFormProblem.lineRequired ? l.contractorLineChoose : null,
            child: FieldPickerTile(
              key: const ValueKey<String>('payment-form-line'),
              icon: Icons.format_list_numbered,
              text: line?.displayName ?? l.contractorLineChoose,
              muted: line == null,
              semanticsLabel: l.contractorAgainstLine,
              onTap: () => _pickLine(state),
            ),
          ),
        ],
        if (state.against == PaymentAgainst.part) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          FieldBlock(
            label: l.contractorAgainstPart,
            error: problem == PaymentFormProblem.partRequired ? l.contractorPartChoose : null,
            child: FieldPickerTile(
              key: const ValueKey<String>('payment-form-part'),
              icon: Icons.flag_outlined,
              text: part?.title ?? l.contractorPartChoose,
              muted: part == null,
              semanticsLabel: l.contractorAgainstPart,
              onTap: () => _pickPart(state),
            ),
          ),
        ],
      ] else if (contract != null && detail == null) ...<Widget>[
        const SizedBox(height: N360Space.sm),
        Text(l.contractorLinesLoadOnline, style: N360Type.fit(N360Type.caption, l.contractorLinesLoadOnline)),
      ],
      gap,
      GloveTextField(label: l.contractorPaidTo, helper: l.contractorPaidToHint, controller: _paidTo),
      gap,
      GloveTextField(label: l.contractorReference, helper: l.contractorOptional, controller: _reference),
      gap,
      GloveTextField(label: l.contractorNotes, helper: l.contractorOptional, controller: _notes, maxLines: 2),
      gap,
      ProgressPhotoRow(
        max: 1,
        label: l.contractorBillPhoto,
        photos: <String>[?state.bill],
        onAdd: cubit.setBill,
        onRemove: (_) => cubit.setBill(null),
      ),
      const SizedBox(height: N360Space.sm),
      Text(l.contractorApprovalNote, style: N360Type.fit(N360Type.caption, l.contractorApprovalNote)),
    ];
  }
}
