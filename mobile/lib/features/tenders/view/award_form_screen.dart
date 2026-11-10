import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../bloc/award_form_cubit.dart';
import '../data/tender_repository.dart';
import 'tender_sheets.dart';

/// "Select this contractor": the contract terms, then a work order at the quote's rates.
class AwardFormScreen extends StatelessWidget {
  const AwardFormScreen({super.key, required this.boqItemId, required this.tenderId, required this.bidId});

  final int boqItemId;
  final int tenderId;
  final int bidId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<AwardFormCubit>(
      create: (context) =>
          AwardFormCubit(context.read<AppServices>(), boqItemId: boqItemId, tenderId: tenderId, bidId: bidId),
      child: const _AwardFormView(),
    );
  }
}

String deductionOrderWord(AppLocalizations l, String order) => switch (order) {
  'advance,retention,material,tds' => l.tenderOrderAdvanceFirst,
  'tds,retention,advance,material' => l.tenderOrderTdsFirst,
  _ => l.tenderOrderRetentionFirst,
};

class _AwardFormView extends StatefulWidget {
  const _AwardFormView();

  @override
  State<_AwardFormView> createState() => _AwardFormViewState();
}

class _AwardFormViewState extends State<_AwardFormView> {
  // The website's starting values.
  final TextEditingController _retention = TextEditingController(text: '5');
  final TextEditingController _tds = TextEditingController(text: '1');
  final TextEditingController _gst = TextEditingController(text: '18');
  final TextEditingController _advance = TextEditingController(text: '0');
  final TextEditingController _recovery = TextEditingController(text: '0');
  final TextEditingController _note = TextEditingController();

  @override
  void dispose() {
    for (final TextEditingController controller in <TextEditingController>[
      _retention,
      _tds,
      _gst,
      _advance,
      _recovery,
      _note,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final AwardFormCubit cubit = context.read<AwardFormCubit>();
    final bool saved = await cubit.submit(
      AwardText(
        retention: _retention.text,
        tds: _tds.text,
        gst: _gst.text,
        advance: _advance.text,
        recovery: _recovery.text,
        note: _note.text,
      ),
    );
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      return;
    }
    N360Haptics.success();
    showPaperToast(context, l.tenderAwarded, kind: PaperToastKind.success, duration: const Duration(seconds: 3));
    Navigator.of(context).pop(cubit.workOrderId);
  }

  Future<void> _pickDate(AwardFormState state, {required bool end}) async {
    final AwardFormCubit cubit = context.read<AwardFormCubit>();
    final DateTime today = DateTime.parse(state.today);
    final DateTime? start = DateTime.tryParse(state.startDate ?? '');
    final DateTime first = end && start != null ? start : DateTime(today.year - 1);
    final DateTime? current = DateTime.tryParse((end ? state.endDate : state.startDate) ?? '');
    final DateTime initial = current ?? (end && start != null ? start : today);
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isBefore(first) ? first : initial,
      firstDate: first,
      lastDate: DateTime(today.year + 5),
    );
    if (picked == null || !mounted) return;
    end ? cubit.setEnd(isoDay(picked)) : cubit.setStart(isoDay(picked));
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
          child: BlocBuilder<AwardFormCubit, AwardFormState>(
            builder: (context, state) {
              final bool ready = state.load == AwardFormLoad.ready;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.tenderAwardTitle, subtitle: state.tender?.title),
                  Expanded(child: ready ? _form(context, l, state) : _waiting(l, state)),
                  if (state.failed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: state.message ?? l.tenderSaveFailed),
                    ),
                  if (ready)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('award-form-send'),
                        label: l.tenderAwardSend,
                        icon: Icons.handshake_outlined,
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

  Widget _waiting(AppLocalizations l, AwardFormState state) {
    return switch (state.load) {
      AwardFormLoad.loading => Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.tenderLoading),
      ),
      AwardFormLoad.offline => EmptyLedger(title: l.tenderOfflineTitle, message: l.tenderOfflineMessage),
      AwardFormLoad.gone => EmptyLedger(title: l.tenderTitle, message: l.tenderQuoteGone),
      _ => EmptyLedger(title: l.tenderTitle, message: l.tenderLoadFailed),
    };
  }

  Widget _chips(List<Widget> chips) => Wrap(
    spacing: N360Space.sm,
    runSpacing: N360Space.sm,
    children: <Widget>[for (final Widget chip in chips) IntrinsicWidth(child: chip)],
  );

  Widget _pct(String key, String label, TextEditingController controller, AwardField field, AwardFormState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    return GloveTextField(
      key: ValueKey<String>(key),
      label: label,
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      errorText: state.bad.contains(field) ? (field == AwardField.advance ? l.tenderAdvanceError : l.tenderPctError) : null,
      onChanged: (_) => context.read<AwardFormCubit>().clearProblem(field),
    );
  }

  Widget _form(BuildContext context, AppLocalizations l, AwardFormState state) {
    final AwardFormCubit cubit = context.read<AwardFormCubit>();
    final TenderQuote quote = state.quote!;
    final String unit = state.line?.unit ?? '';
    final String? start = state.startDate;
    final String? end = state.endDate;
    const Widget gap = SizedBox(height: N360Space.lg);
    final String figures = <String>[
      if (quote.rate != null) l.tenderPerUnit(quote.rate!.inr(), unit),
      if (quote.amount != null) quote.amount!.inr(),
    ].join(' · ');

    return ListView(
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.sm),
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(quote.contractor, style: N360Type.fit(N360Type.heading, quote.contractor)),
              if (figures.isNotEmpty) Text(figures, style: N360Type.figureSmall),
              const SizedBox(height: N360Space.xs),
              Text(l.tenderAwardExplain, style: N360Type.fit(N360Type.caption, l.tenderAwardExplain)),
            ],
          ),
        ),
        gap,
        FieldBlock(
          label: l.tenderStartDate,
          child: _chips(<Widget>[
            N360Chip(label: l.tenderNoDate, selected: start == null, onTap: () => cubit.setStart(null)),
            N360Chip(
              key: const ValueKey<String>('award-form-start'),
              label: start == null ? l.tenderPickDate : dayLabel(context, start, state.today),
              selected: start != null,
              onTap: () => _pickDate(state, end: false),
            ),
          ]),
        ),
        gap,
        FieldBlock(
          label: l.tenderEndDate,
          error: state.endBeforeStart ? l.tenderEndError : null,
          child: _chips(<Widget>[
            N360Chip(label: l.tenderNoDate, selected: end == null, onTap: () => cubit.setEnd(null)),
            N360Chip(
              key: const ValueKey<String>('award-form-end'),
              label: end == null ? l.tenderPickDate : dayLabel(context, end, state.today),
              selected: end != null,
              onTap: () => _pickDate(state, end: true),
            ),
          ]),
        ),
        gap,
        _pct('award-form-retention', l.tenderRetention, _retention, AwardField.retention, state),
        gap,
        _pct('award-form-tds', l.tenderTds, _tds, AwardField.tds, state),
        gap,
        _pct('award-form-gst', l.tenderGst, _gst, AwardField.gst, state),
        gap,
        _pct('award-form-advance', l.tenderAdvance, _advance, AwardField.advance, state),
        gap,
        _pct('award-form-recovery', l.tenderAdvanceRecovery, _recovery, AwardField.recovery, state),
        gap,
        FieldBlock(
          label: l.tenderDeductionBasis,
          child: _chips(<Widget>[
            for (final String basis in kDeductionBases)
              N360Chip(
                key: ValueKey<String>('award-form-basis-$basis'),
                label: basis == 'running' ? l.tenderBasisRunning : l.tenderBasisGross,
                selected: state.basis == basis,
                onTap: () => cubit.setBasis(basis),
              ),
          ]),
        ),
        gap,
        FieldBlock(
          label: l.tenderDeductionOrder,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              for (final String order in kDeductionOrders) ...<Widget>[
                N360Chip(
                  key: ValueKey<String>('award-form-order-${kDeductionOrders.indexOf(order)}'),
                  label: deductionOrderWord(l, order),
                  selected: state.order == order,
                  onTap: () => cubit.setOrder(order),
                ),
                const SizedBox(height: N360Space.sm),
              ],
            ],
          ),
        ),
        gap,
        GloveTextField(label: l.tenderNote, helper: l.tenderOptional, controller: _note, maxLines: 2),
      ],
    );
  }
}
