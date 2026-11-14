import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../../site_progress/view/widgets/progress_widgets.dart' show ProgressPhotoRow;
import '../bloc/quote_form_cubit.dart';
import '../data/tender_repository.dart';
import 'tender_sheets.dart';

/// One contractor's quote for a tender: who, the rate for each line, and what
/// comes with it.
class QuoteFormScreen extends StatelessWidget {
  const QuoteFormScreen({super.key, required this.boqItemId, required this.tenderId});

  final int boqItemId;
  final int tenderId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<QuoteFormCubit>(
      create: (context) => QuoteFormCubit(context.read<AppServices>(), boqItemId: boqItemId, tenderId: tenderId),
      child: const _QuoteFormView(),
    );
  }
}

class _QuoteFormView extends StatefulWidget {
  const _QuoteFormView();

  @override
  State<_QuoteFormView> createState() => _QuoteFormViewState();
}

class _QuoteFormViewState extends State<_QuoteFormView> {
  final Map<int, TextEditingController> _rates = <int, TextEditingController>{};
  final TextEditingController _amount = TextEditingController();
  final TextEditingController _days = TextEditingController();
  final TextEditingController _terms = TextEditingController();
  final TextEditingController _remarks = TextEditingController();
  final ScrollController _scroll = ScrollController();

  @override
  void dispose() {
    _scroll.dispose();
    for (final TextEditingController controller in <TextEditingController>[
      ..._rates.values,
      _amount,
      _days,
      _terms,
      _remarks,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  TextEditingController _rate(int lineId) => _rates.putIfAbsent(lineId, TextEditingController.new);

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool saved = await context.read<QuoteFormCubit>().submit(
      QuoteText(
        rates: <int, String>{for (final MapEntry<int, TextEditingController> e in _rates.entries) e.key: e.value.text},
        amount: _amount.text,
        days: _days.text,
        paymentTerms: _terms.text,
        remarks: _remarks.text,
      ),
    );
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      // Who quoted and the rates sit at the top, out of sight once the form is scrolled.
      final QuoteProblem? problem = context.read<QuoteFormCubit>().state.problem;
      if (problem == QuoteProblem.contractorRequired ||
          problem == QuoteProblem.rateInvalid ||
          problem == QuoteProblem.amountInvalid) {
        _toTop();
      }
      return;
    }
    N360Haptics.success();
    showPaperToast(context, l.tenderQuoteSaved, kind: PaperToastKind.success);
    Navigator.of(context).pop(true);
  }

  Future<void> _pickContractor(QuoteFormState state) async {
    final QuoteFormCubit cubit = context.read<QuoteFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.tenderContractorSearch,
      emptyTitle: l.tenderContractorNone,
      options: <FieldPickOption>[
        for (final Subcontractor row in state.contractors)
          FieldPickOption(id: row.id, title: row.name, subtitle: row.detail),
      ],
    );
    if (id != null) cubit.choose(id);
  }

  Future<void> _addContractor() async {
    final QuoteFormCubit cubit = context.read<QuoteFormCubit>();
    final Subcontractor? added = await showLedgerSheet<Subcontractor>(
      context,
      isScrollControlled: true,
      builder: (_) => NewContractorSheet(repository: cubit.repository),
    );
    if (added == null) return;
    cubit.added(added);
    _toTop();
  }

  /// Back to the contractor and the rates, so what changed there is in view.
  void _toTop() {
    if (!_scroll.hasClients) return;
    final bool still = MediaQuery.of(context).disableAnimations;
    if (still) {
      _scroll.jumpTo(0);
    } else {
      _scroll.animateTo(0, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
    }
  }

  Future<void> _pickReceived(QuoteFormState state) async {
    final DateTime last = DateTime.parse(state.today);
    final DateTime initial = DateTime.tryParse(state.receivedOn) ?? last;
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isAfter(last) ? last : initial,
      firstDate: DateTime(last.year - 1),
      lastDate: last,
    );
    if (picked == null || !mounted) return;
    context.read<QuoteFormCubit>().setReceived(isoDay(picked));
  }

  Future<void> _pickValid(QuoteFormState state) async {
    final DateTime first = DateTime.parse(state.receivedOn);
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: DateTime.tryParse(state.validUntil ?? '') ?? first.add(const Duration(days: 30)),
      firstDate: first,
      lastDate: DateTime(first.year + 3),
    );
    if (picked == null || !mounted) return;
    context.read<QuoteFormCubit>().setValidUntil(isoDay(picked));
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
          child: BlocBuilder<QuoteFormCubit, QuoteFormState>(
            builder: (context, state) {
              final bool ready = state.load == QuoteFormLoad.ready;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.tenderQuoteTitle, subtitle: state.tender?.title),
                  Expanded(child: ready ? _form(context, l, state) : _waiting(l, state)),
                  if (state.problem == QuoteProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: state.message ?? l.tenderSaveFailed),
                    ),
                  if (ready)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('quote-form-save'),
                        label: l.tenderSaveQuote,
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

  Widget _waiting(AppLocalizations l, QuoteFormState state) {
    return switch (state.load) {
      QuoteFormLoad.loading => Padding(
        padding: const EdgeInsets.all(N360Space.gutter),
        child: LedgerSkeletonList(lines: 3, semanticsLabel: l.tenderLoading),
      ),
      QuoteFormLoad.offline => EmptyLedger(title: l.tenderOfflineTitle, message: l.tenderOfflineMessage),
      QuoteFormLoad.closed => EmptyLedger(title: l.tenderTitle, message: l.tenderClosed),
      _ => EmptyLedger(title: l.tenderTitle, message: l.tenderLoadFailed),
    };
  }

  /// A row of choices. A `Row` would overflow at 360dp in Hindi and Marathi.
  Widget _chips(List<Widget> chips) => Wrap(
    spacing: N360Space.sm,
    runSpacing: N360Space.sm,
    children: <Widget>[for (final Widget chip in chips) IntrinsicWidth(child: chip)],
  );

  Widget _form(BuildContext context, AppLocalizations l, QuoteFormState state) {
    final QuoteFormCubit cubit = context.read<QuoteFormCubit>();
    final LineTender tender = state.tender!;
    final QuoteProblem? problem = state.problem;
    const Widget gap = SizedBox(height: N360Space.lg);
    final Subcontractor? contractor = state.contractor;
    final String yesterday = isoDay(DateTime.parse(state.today).subtract(const Duration(days: 1)));
    final bool otherDay = state.receivedOn != state.today && state.receivedOn != yesterday;
    final String? valid = state.validUntil;

    return ListView(
      controller: _scroll,
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.sm),
      children: <Widget>[
        FieldBlock(
          label: l.tenderContractor,
          error: problem == QuoteProblem.contractorRequired ? l.tenderContractorRequired : null,
          child: FieldPickerTile(
            key: const ValueKey<String>('quote-form-contractor'),
            icon: Icons.engineering_outlined,
            text: contractor?.name ?? l.tenderContractorChoose,
            muted: contractor == null,
            semanticsLabel: l.tenderContractor,
            onTap: () => _pickContractor(state),
          ),
        ),
        if (state.contractorsFailed) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          Text(l.tenderContractorsFailed, style: N360Type.caption.copyWith(color: N360Colors.stampRed)),
        ],
        const SizedBox(height: N360Space.sm),
        Align(
          alignment: Alignment.centerLeft,
          child: GloveButton(
            key: const ValueKey<String>('quote-form-add-contractor'),
            label: l.tenderContractorAdd,
            icon: Icons.person_add_alt,
            kind: GloveButtonKind.ghost,
            onPressed: _addContractor,
          ),
        ),
        gap,
        if (state.byAmount)
          GloveTextField(
            key: const ValueKey<String>('quote-form-amount'),
            label: l.tenderQuotedAmount,
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            errorText: problem == QuoteProblem.amountInvalid ? l.tenderAmountError : null,
            onChanged: (_) => cubit.clearProblem(),
          )
        else ...<Widget>[
          for (final TenderLine row in tender.lines) ...<Widget>[
            _RateField(
              row: row,
              controller: _rate(row.id),
              error: state.badRates.contains(row.id) ? l.tenderRateError : null,
              onChanged: () {
                cubit.clearProblem();
                setState(() {});
              },
            ),
            const SizedBox(height: N360Space.md),
          ],
          if (tender.lines.length > 1) _Total(lines: tender.lines, rates: _rates),
        ],
        gap,
        FieldBlock(
          label: l.tenderReceivedOn,
          child: _chips(<Widget>[
            N360Chip(
              label: l.commonToday,
              selected: state.receivedOn == state.today,
              onTap: () => cubit.setReceived(state.today),
            ),
            N360Chip(
              label: dayLabel(context, yesterday, state.today),
              selected: state.receivedOn == yesterday,
              onTap: () => cubit.setReceived(yesterday),
            ),
            N360Chip(
              key: const ValueKey<String>('quote-form-received-other'),
              label: otherDay ? dayLabel(context, state.receivedOn, state.today) : l.tenderPickDate,
              selected: otherDay,
              onTap: () => _pickReceived(state),
            ),
          ]),
        ),
        gap,
        FieldBlock(
          label: l.tenderValidTill,
          error: problem == QuoteProblem.validBeforeReceived ? l.tenderValidError : null,
          child: _chips(<Widget>[
            N360Chip(label: l.tenderNoDate, selected: valid == null, onTap: () => cubit.setValidUntil(null)),
            N360Chip(
              key: const ValueKey<String>('quote-form-valid'),
              label: valid == null ? l.tenderPickDate : dayLabel(context, valid, state.today),
              selected: valid != null,
              onTap: () => _pickValid(state),
            ),
          ]),
        ),
        gap,
        FieldBlock(
          label: l.tenderIncludesMaterial,
          child: _chips(<Widget>[
            N360Chip(
              key: const ValueKey<String>('quote-form-labour'),
              label: l.tenderMaterialOut,
              selected: !state.includesMaterial,
              onTap: () => cubit.setMaterial(false),
            ),
            N360Chip(
              key: const ValueKey<String>('quote-form-material'),
              label: l.tenderMaterialIn,
              selected: state.includesMaterial,
              onTap: () => cubit.setMaterial(true),
            ),
          ]),
        ),
        gap,
        GloveTextField(
          key: const ValueKey<String>('quote-form-days'),
          label: l.tenderCompletionDays,
          helper: l.tenderOptional,
          controller: _days,
          keyboardType: TextInputType.number,
          errorText: problem == QuoteProblem.daysInvalid ? l.tenderDaysError : null,
          onChanged: (_) => cubit.clearProblem(),
        ),
        gap,
        GloveTextField(
          key: const ValueKey<String>('quote-form-terms'),
          label: l.tenderPaymentTerms,
          helper: l.tenderOptional,
          controller: _terms,
          maxLines: 2,
        ),
        gap,
        GloveTextField(label: l.tenderRemarks, helper: l.tenderOptional, controller: _remarks, maxLines: 2),
        gap,
        ProgressPhotoRow(
          max: 1,
          label: l.tenderQuotationPhoto,
          photos: <String>[?state.quotation],
          onAdd: cubit.setQuotation,
          onRemove: (_) => cubit.setQuotation(null),
        ),
      ],
    );
  }
}

/// The rate for one line, with what it comes to.
class _RateField extends StatelessWidget {
  const _RateField({required this.row, required this.controller, required this.onChanged, this.error});

  final TenderLine row;
  final TextEditingController controller;
  final VoidCallback onChanged;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = row.unit ?? '';
    final Money? rate = QuoteFormCubit.rateOf(controller.text);
    final String? worked = rate == null
        ? null
        : l.tenderLineAmount(row.qty.format(), unit, Money.fromQtyRate(row.qty, rate).inr());
    final String name = row.displayName;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (name.isNotEmpty) Text(name, maxLines: 2, overflow: TextOverflow.ellipsis, style: N360Type.fit(N360Type.label, name)),
        const SizedBox(height: N360Space.xs),
        GloveTextField(
          key: ValueKey<String>('quote-form-rate-${row.id}'),
          label: l.tenderRatePer(unit),
          controller: controller,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          errorText: error,
          onChanged: (_) => onChanged(),
        ),
        if (worked != null) ...<Widget>[
          const SizedBox(height: N360Space.xxs),
          Text(worked, style: N360Type.fit(N360Type.caption, worked)),
        ],
      ],
    );
  }
}

/// The quoted total across lines, once every rate is a rate.
class _Total extends StatelessWidget {
  const _Total({required this.lines, required this.rates});

  final List<TenderLine> lines;
  final Map<int, TextEditingController> rates;

  @override
  Widget build(BuildContext context) {
    Money total = Money.zero;
    for (final TenderLine row in lines) {
      final Money? rate = QuoteFormCubit.rateOf(rates[row.id]?.text ?? '');
      if (rate == null) return const SizedBox.shrink();
      total = Money.of(total.value + Money.fromQtyRate(row.qty, rate).value);
    }
    final String text = AppLocalizations.of(context).tenderTotal(total.inr());
    return Text(text, key: const ValueKey<String>('quote-form-total'), style: N360Type.fit(N360Type.bodyStrong, text));
  }
}
