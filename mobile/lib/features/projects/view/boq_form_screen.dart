import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/boq_form_cubit.dart';

/// Adds BOQ lines. What the line is comes first, then quantity and rate on the
/// keypad with the amount working itself out. Built for many lines in a row.
class BoqFormScreen extends StatelessWidget {
  const BoqFormScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<BoqFormCubit>(
      create: (context) => BoqFormCubit(context.read<AppServices>()),
      child: const _BoqFormView(),
    );
  }
}

class _BoqFormView extends StatefulWidget {
  const _BoqFormView();

  @override
  State<_BoqFormView> createState() => _BoqFormViewState();
}

class _BoqFormViewState extends State<_BoqFormView> {
  final TextEditingController _description = TextEditingController();
  final TextEditingController _section = TextEditingController();
  final TextEditingController _code = TextEditingController();

  @override
  void dispose() {
    _description.dispose();
    _section.dispose();
    _code.dispose();
    super.dispose();
  }

  void _onSaved(BuildContext context, BoqFormState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    N360Haptics.success();
    if (state.done) {
      showPaperToast(
        context,
        l.boqLinesAdded(state.addedCount),
        kind: PaperToastKind.success,
        icon: Icons.task_alt,
      );
      // A plain pop: maybePop would be vetoed by the step-back PopScope below.
      Navigator.of(context).pop();
      return;
    }
    _description.clear();
    _code.clear();
    showPaperToast(
      context,
      l.boqFormAdded,
      kind: PaperToastKind.success,
      icon: Icons.task_alt,
      // Clears the action bar, which is a large button on a card.
      bottomOffset: N360Size.tapLarge + N360Space.xxl + N360Space.md,
      duration: const Duration(milliseconds: 1800),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocConsumer<BoqFormCubit, BoqFormState>(
      listenWhen: (a, b) => a.formVersion != b.formVersion || (!a.done && b.done),
      listener: _onSaved,
      builder: (context, state) {
        final BoqFormCubit cubit = context.read<BoqFormCubit>();
        return PopScope(
          canPop: state.step == 0,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop) cubit.backToDetails();
          },
          child: Scaffold(
            body: LedgerPaper(
              child: SafeArea(
                top: false,
                bottom: false,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    FieldTopBar(
                      title: l.boqFormTitle,
                      subtitle: state.project?.name,
                      onBack: state.step == 0 ? null : cubit.backToDetails,
                    ),
                    if (!state.online)
                      Padding(
                        padding: const EdgeInsets.only(bottom: N360Space.sm),
                        child: OfflineBanner(label: l.boqOfflineTitle, detail: l.boqOfflineDetail),
                      ),
                    if (state.addedCount > 0) _AddedCount(count: state.addedCount),
                    Expanded(
                      child: state.step == 0
                          ? _DetailsStep(
                              state: state,
                              description: _description,
                              section: _section,
                              code: _code,
                            )
                          : _FiguresStep(state: state, description: _description.text),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// A plain running count, so nobody loses track of what is already in.
class _AddedCount extends StatelessWidget {
  const _AddedCount({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String text = l.boqLinesAdded(count);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: LedgerCard(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.tile),
        child: Row(
          children: <Widget>[
            const Icon(Icons.task_alt, size: N360IconSize.md, color: N360Colors.mossInk),
            const SizedBox(width: N360Space.sm),
            Expanded(child: Text(text, style: N360Type.fit(N360Type.bodyStrong, text))),
          ],
        ),
      ),
    );
  }
}

class _DetailsStep extends StatelessWidget {
  const _DetailsStep({
    required this.state,
    required this.description,
    required this.section,
    required this.code,
  });

  final BoqFormState state;
  final TextEditingController description;
  final TextEditingController section;
  final TextEditingController code;

  String? _descriptionError(AppLocalizations l) {
    return state.serverErrors['description'] ??
        (state.problem == BoqFormProblem.descriptionRequired ? l.boqFormDescriptionRequired : null);
  }

  String? _unitError(AppLocalizations l) {
    return state.serverErrors['unit'] ??
        (state.problem == BoqFormProblem.unitRequired ? l.boqFormUnitRequired : null);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final BoqFormCubit cubit = context.read<BoqFormCubit>();
    final String? unitError = _unitError(l);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.xs,
              N360Space.gutter,
              N360Space.sm,
            ),
            children: <Widget>[
              StaggeredRise(
                index: 0,
                child: GloveTextField(
                  label: l.boqFormDescription,
                  controller: description,
                  hint: l.boqFormDescriptionHint,
                  maxLines: 3,
                  textInputAction: TextInputAction.done,
                  errorText: _descriptionError(l),
                  onChanged: cubit.setDescription,
                ),
              ),
              const SizedBox(height: N360Space.lg),
              StaggeredRise(
                index: 1,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(l.boqFormUnit, style: N360Type.fit(N360Type.label, l.boqFormUnit)),
                    const SizedBox(height: N360Space.xs),
                    _UnitChips(units: state.units, value: state.unit, onChanged: cubit.setUnit),
                    if (unitError != null) ...<Widget>[
                      const SizedBox(height: N360Space.xs),
                      FieldInlineError(unitError),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: N360Space.lg),
              StaggeredRise(
                index: 2,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    GloveTextField(
                      label: l.boqFormSection,
                      controller: section,
                      hint: l.boqFormSectionHint,
                      textInputAction: TextInputAction.next,
                      errorText: state.serverErrors['section'],
                      onChanged: cubit.setSection,
                    ),
                    _SectionSuggestions(
                      sections: state.sections,
                      controller: section,
                      onChosen: cubit.setSection,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: N360Space.lg),
              StaggeredRise(
                index: 3,
                child: GloveTextField(
                  label: l.boqFormCode,
                  controller: code,
                  helper: l.boqFormCodeHelper,
                  textCapitalization: TextCapitalization.characters,
                  textInputAction: TextInputAction.done,
                  errorText: state.serverErrors['code'],
                  onChanged: cubit.setCode,
                ),
              ),
              if (state.serverMessage != null) ...<Widget>[
                const SizedBox(height: N360Space.md),
                ErrorSlip(title: state.serverMessage!),
              ],
            ],
          ),
        ),
        FieldActionBar(
          child: GloveButton(
            label: l.designKeypadNext,
            icon: Icons.arrow_forward,
            large: true,
            fullWidth: true,
            onPressed: cubit.next,
          ),
        ),
      ],
    );
  }
}

/// Units as chips, one tap each. They wrap; every chip keeps its 56dp target.
class _UnitChips extends StatelessWidget {
  const _UnitChips({required this.units, required this.value, required this.onChanged});

  final List<String> units;
  final String? value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: N360Space.sm,
      children: <Widget>[
        for (final String unit in units)
          // A chip fills the width it is given, so it is asked for only what
          // its own word needs.
          IntrinsicWidth(
            child: N360Chip(label: unit, selected: unit == value, onTap: () => onChanged(unit)),
          ),
      ],
    );
  }
}

/// Sections already on this BOQ, so a name is typed once and tapped after that.
class _SectionSuggestions extends StatelessWidget {
  const _SectionSuggestions({
    required this.sections,
    required this.controller,
    required this.onChosen,
  });

  final List<String> sections;
  final TextEditingController controller;
  final ValueChanged<String> onChosen;

  static const int _maxShown = 8;

  @override
  Widget build(BuildContext context) {
    if (sections.isEmpty) return const SizedBox.shrink();
    return ValueListenableBuilder<TextEditingValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        final String typed = value.text.trim().toLowerCase();
        final List<String> matches = <String>[
          for (final String section in sections)
            if (typed.isEmpty || section.toLowerCase().contains(typed)) section,
        ].take(_maxShown).toList();
        if (matches.isEmpty) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(top: N360Space.xs),
          child: Wrap(
            spacing: N360Space.sm,
            children: <Widget>[
              for (final String section in matches)
                IntrinsicWidth(
                  child: N360Chip(
                    label: section,
                    selected: section.toLowerCase() == typed,
                    onTap: () {
                      controller.text = section;
                      onChosen(section);
                    },
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _FiguresStep extends StatelessWidget {
  const _FiguresStep({required this.state, required this.description});

  final BoqFormState state;
  final String description;

  String? _error(AppLocalizations l) {
    return state.serverErrors['qty'] ??
        state.serverErrors['rate'] ??
        switch (state.problem) {
          BoqFormProblem.qtyRequired => l.boqFormQtyRequired,
          BoqFormProblem.rateRequired => l.boqFormRateRequired,
          BoqFormProblem.failed => l.boqFormFailed,
          _ => state.serverMessage,
        };
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final BoqFormCubit cubit = context.read<BoqFormCubit>();
    final String? error = _error(l);
    final bool canSave = state.online && !state.saving;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          // The line and its figures sit in the middle of the room above the
          // keypad, as an amount does on a payment app: no empty end.
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.xs,
                N360Space.gutter,
                N360Space.sm,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    description,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.fit(N360Type.bodyStrong, description),
                  ),
                  const SizedBox(height: N360Space.sm),
                  Row(
                    children: <Widget>[
                      Expanded(
                        child: DimensionTile(
                          label: l.boqFormQty,
                          value: state.qtyText,
                          unit: state.unit,
                          active: state.active == BoqFigure.qty,
                          onTap: () => cubit.selectFigure(BoqFigure.qty),
                        ),
                      ),
                      const SizedBox(width: N360Space.sm),
                      Expanded(
                        child: DimensionTile(
                          label: l.boqFormRate,
                          value: state.rateText,
                          unit: '₹',
                          active: state.active == BoqFigure.rate,
                          onTap: () => cubit.selectFigure(BoqFigure.rate),
                        ),
                      ),
                    ],
                  ),
                  if (error != null) ...<Widget>[
                    const SizedBox(height: N360Space.xs),
                    FieldInlineError(error),
                  ],
                  const SizedBox(height: N360Space.sm),
                  LedgerCard(
                    padding: const EdgeInsets.symmetric(horizontal: N360Space.lg, vertical: N360Space.sm),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(minHeight: N360Size.control),
                      child: Row(
                        children: <Widget>[
                          Text(l.boqFormAmount, style: N360Type.fit(N360Type.heading, l.boqFormAmount)),
                          const SizedBox(width: N360Space.sm),
                          Expanded(
                            child: Align(
                              alignment: Alignment.centerRight,
                              child: FittedBox(
                                fit: BoxFit.scaleDown,
                                child: RollingMoney(
                                  value: state.amount.indian(),
                                  style: N360Type.figureLarge,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: GloveKeypad(
            value: state.activeText,
            decimalPlaces: state.active == BoqFigure.qty ? 3 : 2,
            onChanged: cubit.setActiveText,
            onNext: cubit.nextFigure,
            nextLabel: l.designKeypadNext,
            backspaceLabel: l.designKeypadDelete,
          ),
        ),
        FieldActionBar(
          child: Row(
            children: <Widget>[
              Expanded(
                flex: 3,
                child: GloveButton(
                  label: l.boqFormSaveAnother,
                  icon: Icons.add,
                  large: true,
                  fullWidth: true,
                  loading: state.saving,
                  onPressed: canSave ? () => cubit.save(addAnother: true) : null,
                ),
              ),
              const SizedBox(width: N360Space.sm),
              Expanded(
                flex: 2,
                child: GloveButton(
                  label: l.boqFormFinish,
                  kind: GloveButtonKind.secondary,
                  large: true,
                  fullWidth: true,
                  onPressed: canSave ? () => cubit.save(addAnother: false) : null,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
