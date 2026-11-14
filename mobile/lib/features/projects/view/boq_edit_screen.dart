import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show BoqItemRow;
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/boq_edit_cubit.dart';

/// Edits one BOQ line, online only, with the reason the BOQ history keeps.
/// The company owner also finds "Delete this item" at the foot, behind a
/// confirmation that names the line.
class BoqEditScreen extends StatelessWidget {
  const BoqEditScreen({super.key, required this.boqItemId});

  final int boqItemId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<BoqEditCubit>(
      create: (context) => BoqEditCubit(context.read<AppServices>(), boqItemId),
      child: const _BoqEditView(),
    );
  }
}

class _BoqEditView extends StatefulWidget {
  const _BoqEditView();

  @override
  State<_BoqEditView> createState() => _BoqEditViewState();
}

class _BoqEditViewState extends State<_BoqEditView> {
  final TextEditingController _code = TextEditingController();
  final TextEditingController _description = TextEditingController();
  final TextEditingController _qty = TextEditingController();
  final TextEditingController _rate = TextEditingController();
  final TextEditingController _reason = TextEditingController();

  @override
  void dispose() {
    _code.dispose();
    _description.dispose();
    _qty.dispose();
    _rate.dispose();
    _reason.dispose();
    super.dispose();
  }

  /// The line was (re)loaded: the fields show it. The reason stays as typed.
  void _fill(BoqEditCubit cubit) {
    _code.text = cubit.code;
    _description.text = cubit.description;
    _qty.text = cubit.qtyText;
    _rate.text = cubit.rateText;
  }

  void _onChange(BuildContext context, BoqEditState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    if (state.saved || state.deleted) {
      N360Haptics.success();
      final String code = state.line?.code ?? '';
      showPaperToast(
        context,
        state.deleted ? l.boqDeleteDone(code) : l.boqEditSaved,
        kind: PaperToastKind.success,
        icon: Icons.task_alt,
      );
      Navigator.of(context).pop();
      return;
    }
    _fill(context.read<BoqEditCubit>());
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocConsumer<BoqEditCubit, BoqEditState>(
      listenWhen: (a, b) => a.formVersion != b.formVersion || (!a.saved && b.saved) || (!a.deleted && b.deleted),
      listener: _onChange,
      builder: (context, state) {
        final BoqItemRow? line = state.line;
        return Scaffold(
          body: LedgerPaper(
            child: SafeArea(
              top: false,
              bottom: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.boqEditTitle, subtitle: line == null ? null : l.boqItemCode(line.code)),
                  if (!state.online)
                    Padding(
                      padding: const EdgeInsets.only(bottom: N360Space.sm),
                      child: OfflineBanner(label: l.boqOfflineTitle, detail: l.boqEditOfflineDetail),
                    ),
                  Expanded(child: _body(context, l, state)),
                  if (state.load == BoqEditLoad.ready)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('boq-edit-save'),
                        label: l.boqEditSave,
                        icon: Icons.check,
                        large: true,
                        fullWidth: true,
                        loading: state.saving,
                        onPressed: state.online && !state.saving && !state.deleting
                            ? () => context.read<BoqEditCubit>().save()
                            : null,
                      ),
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _body(BuildContext context, AppLocalizations l, BoqEditState state) {
    switch (state.load) {
      case BoqEditLoad.loading:
        return Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          child: LedgerSkeletonList(lines: 3, semanticsLabel: l.boqLoading),
        );
      case BoqEditLoad.gone:
        return EmptyLedger(title: l.boqEditGone);
      case BoqEditLoad.ready:
        return _Form(
          state: state,
          code: _code,
          description: _description,
          qty: _qty,
          rate: _rate,
          reason: _reason,
        );
    }
  }
}

class _Form extends StatelessWidget {
  const _Form({
    required this.state,
    required this.code,
    required this.description,
    required this.qty,
    required this.rate,
    required this.reason,
  });

  final BoqEditState state;
  final TextEditingController code;
  final TextEditingController description;
  final TextEditingController qty;
  final TextEditingController rate;
  final TextEditingController reason;

  String? _error(String field, BoqEditProblem problem, String text) =>
      state.serverErrors[field] ?? (state.problem == problem ? text : null);

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final BoqEditCubit cubit = context.read<BoqEditCubit>();
    final BoqItemRow line = state.line!;
    final String? unitError = _error('unit', BoqEditProblem.unitRequired, l.boqFormUnitRequired);
    final String? notice = switch (state.problem) {
      BoqEditProblem.changed => l.boqEditChanged,
      BoqEditProblem.offline => l.boqEditOfflineDetail,
      BoqEditProblem.notAllowed => l.boqEditNotAllowed,
      BoqEditProblem.gone => l.boqEditGone,
      BoqEditProblem.failed => state.serverMessage ?? l.boqFormFailed,
      _ => state.serverMessage,
    };

    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        if (notice != null) ...<Widget>[
          ErrorSlip(key: const ValueKey<String>('boq-edit-notice'), title: notice),
          const SizedBox(height: N360Space.md),
        ],
        GloveTextField(
          key: const ValueKey<String>('boq-edit-code'),
          label: l.boqEditCode,
          controller: code,
          textCapitalization: TextCapitalization.characters,
          textInputAction: TextInputAction.next,
          errorText: _error('code', BoqEditProblem.codeRequired, l.boqEditCodeRequired),
          onChanged: cubit.setCode,
        ),
        const SizedBox(height: N360Space.lg),
        GloveTextField(
          key: const ValueKey<String>('boq-edit-description'),
          label: l.boqFormDescription,
          controller: description,
          maxLines: 3,
          textInputAction: TextInputAction.next,
          errorText: _error('description', BoqEditProblem.descriptionRequired, l.boqFormDescriptionRequired),
          onChanged: cubit.setDescription,
        ),
        const SizedBox(height: N360Space.lg),
        Text(l.boqFormUnit, style: N360Type.fit(N360Type.label, l.boqFormUnit)),
        const SizedBox(height: N360Space.xs),
        Wrap(
          spacing: N360Space.sm,
          children: <Widget>[
            for (final String unit in state.units)
              IntrinsicWidth(
                child: N360Chip(label: unit, selected: unit == state.unit, onTap: () => cubit.setUnit(unit)),
              ),
          ],
        ),
        if (unitError != null) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          FieldInlineError(unitError),
        ],
        const SizedBox(height: N360Space.lg),
        GloveTextField(
          key: const ValueKey<String>('boq-edit-qty'),
          label: state.unit == null ? l.boqFormQty : '${l.boqFormQty} (${state.unit})',
          controller: qty,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textInputAction: TextInputAction.next,
          helper: line.measuredQty > Qty.zero ? l.boqEditMeasured(line.measuredQty.format(), line.unit) : null,
          errorText: _error('qty', BoqEditProblem.qtyInvalid, l.boqEditQtyInvalid),
          onChanged: cubit.setQty,
        ),
        if (cubit.canSeeMoney) ...<Widget>[
          const SizedBox(height: N360Space.lg),
          GloveTextField(
            key: const ValueKey<String>('boq-edit-rate'),
            label: '${l.boqFormRate} (₹)',
            controller: rate,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textInputAction: TextInputAction.next,
            errorText: _error('rate', BoqEditProblem.rateInvalid, l.boqEditRateInvalid),
            onChanged: cubit.setRate,
          ),
        ],
        const SizedBox(height: N360Space.lg),
        GloveTextField(
          key: const ValueKey<String>('boq-edit-reason'),
          label: l.boqEditReason,
          controller: reason,
          hint: l.boqEditReasonHint,
          helper: l.boqEditReasonHelper,
          maxLines: 2,
          textInputAction: TextInputAction.done,
          errorText: _error('reason', BoqEditProblem.reasonRequired, l.boqEditReasonRequired),
          onChanged: cubit.setReason,
        ),
        if (cubit.isOwner) ...<Widget>[
          const SizedBox(height: N360Space.xl),
          const RuledDivider(),
          const SizedBox(height: N360Space.lg),
          _DeleteSection(state: state),
        ],
      ],
    );
  }
}

/// For the company owner only: what deleting means, the button, and why the
/// server refused when it did.
class _DeleteSection extends StatelessWidget {
  const _DeleteSection({required this.state});

  final BoqEditState state;

  Future<void> _confirm(BuildContext context) async {
    final BoqEditCubit cubit = context.read<BoqEditCubit>();
    final BoqItemRow line = state.line!;
    final String? reason = await showLedgerSheet<String>(
      context,
      isScrollControlled: true,
      builder: (context) => _DeleteSheet(line: line),
    );
    if (reason == null || !context.mounted) return;
    await cubit.delete(reason);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? problem = switch (state.deleteProblem) {
      BoqDeleteProblem.offline => l.boqDeleteOffline,
      BoqDeleteProblem.unsent => l.boqDeleteUnsent,
      BoqDeleteProblem.ownerOnly => l.boqDeleteOwnerOnly,
      BoqDeleteProblem.failed => l.boqDeleteFailed,
      BoqDeleteProblem.refused => l.boqDeleteRefusedTitle,
      null => null,
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(
          l.boqDeleteOwnerNote,
          style: N360Type.fit(N360Type.caption, l.boqDeleteOwnerNote).copyWith(color: N360Colors.ink2),
        ),
        const SizedBox(height: N360Space.sm),
        GloveButton(
          key: const ValueKey<String>('boq-edit-delete'),
          label: l.boqDeleteAction,
          icon: Icons.delete_outline,
          kind: GloveButtonKind.danger,
          fullWidth: true,
          loading: state.deleting,
          onPressed: state.online && !state.deleting && !state.saving ? () => _confirm(context) : null,
        ),
        if (problem != null) ...<Widget>[
          const SizedBox(height: N360Space.sm),
          ErrorSlip(
            key: const ValueKey<String>('boq-delete-problem'),
            title: problem,
            message: state.deleteProblem == BoqDeleteProblem.refused ? state.refusals.join('\n') : null,
          ),
        ],
      ],
    );
  }
}

/// Names the line, says what deleting does, and takes the reason. Pops with
/// the reason when confirmed, with nothing when kept.
class _DeleteSheet extends StatefulWidget {
  const _DeleteSheet({required this.line});

  final BoqItemRow line;

  @override
  State<_DeleteSheet> createState() => _DeleteSheetState();
}

class _DeleteSheetState extends State<_DeleteSheet> {
  final TextEditingController _reason = TextEditingController();
  bool _missing = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  void _delete() {
    final String reason = _reason.text.trim();
    if (reason.isEmpty) {
      setState(() => _missing = true);
      return;
    }
    Navigator.of(context).pop(reason);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String title = l.boqDeleteTitle(widget.line.code);
    final String what = '${widget.line.description} · ${widget.line.qty.format()} ${widget.line.unit}';
    return SafeArea(
      top: false,
      child: Padding(
        padding: ledgerSheetPadding.add(EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom)),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(title, style: N360Type.fit(N360Type.title, title)),
              const SizedBox(height: N360Space.xs),
              Text(
                what,
                key: const ValueKey<String>('boq-delete-what'),
                style: N360Type.fit(N360Type.bodyStrong, what),
              ),
              const SizedBox(height: N360Space.sm),
              Text(
                l.boqDeleteBody,
                style: N360Type.fit(N360Type.body, l.boqDeleteBody).copyWith(color: N360Colors.ink2),
              ),
              const SizedBox(height: N360Space.lg),
              GloveTextField(
                key: const ValueKey<String>('boq-delete-reason'),
                label: l.boqDeleteReason,
                controller: _reason,
                textInputAction: TextInputAction.done,
                errorText: _missing ? l.boqDeleteReasonRequired : null,
                onChanged: (_) {
                  if (_missing) setState(() => _missing = false);
                },
                onSubmitted: (_) => _delete(),
              ),
              const SizedBox(height: N360Space.lg),
              GloveButton(
                key: const ValueKey<String>('boq-delete-confirm'),
                label: l.boqDeleteConfirm,
                kind: GloveButtonKind.danger,
                icon: Icons.delete_outline,
                fullWidth: true,
                onPressed: _delete,
              ),
              const SizedBox(height: N360Space.sm),
              GloveButton(
                key: const ValueKey<String>('boq-delete-keep'),
                label: l.boqDeleteKeep,
                kind: GloveButtonKind.secondary,
                large: true,
                fullWidth: true,
                onPressed: () => Navigator.of(context).pop(),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
