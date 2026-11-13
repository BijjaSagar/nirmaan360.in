import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/floor_setup_cubit.dart';
import '../data/floor_setup_repository.dart';
import 'floor_setup_screen.dart';

/// What is meant to be done on one floor or segment, BOQ line by BOQ line.
/// The floor's percentage is measured against these quantities. Online only.
/// Needs `progress.manage`.
class FloorPlanScreen extends StatelessWidget {
  const FloorPlanScreen({super.key, required this.floorId});

  final int floorId;

  @override
  Widget build(BuildContext context) {
    final int? projectId = context.select((AppGate gate) => gate.state.currentProject?.id);
    if (projectId == null) return const Scaffold(body: LedgerPaper(child: SizedBox.expand()));
    return BlocProvider<FloorPlanCubit>(
      key: ValueKey<String>('$projectId-$floorId'),
      create: (context) => FloorPlanCubit(floorSetupRepository(context), projectId, floorId)..load(),
      child: const _PlanView(),
    );
  }
}

class _PlanView extends StatelessWidget {
  const _PlanView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              BlocBuilder<FloorPlanCubit, FloorPlanState>(
                buildWhen: (a, b) => a.plan?.floor.name != b.plan?.floor.name,
                builder: (context, state) => FieldTopBar(title: l.floorPlanTitle, subtitle: state.plan?.floor.name),
              ),
              const Expanded(child: _PlanBody()),
            ],
          ),
        ),
      ),
    );
  }
}

class _PlanBody extends StatelessWidget {
  const _PlanBody();

  Future<void> _edit(BuildContext context, ProgressItem item, Qty? planned) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final FloorPlanCubit cubit = context.read<FloorPlanCubit>();
    final bool? saved = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (_) => _QtySheet(item: item, planned: planned, cubit: cubit),
    );
    if (saved != true || !context.mounted) return;
    showPaperToast(context, l.floorPlanSaved, kind: PaperToastKind.success, icon: Icons.task_alt);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final BuildContext screen = context;
    return BlocBuilder<FloorPlanCubit, FloorPlanState>(
      builder: (context, state) {
        final FloorPlanCubit cubit = context.read<FloorPlanCubit>();
        final FloorPlan? plan = state.plan;
        if (plan == null) {
          return switch (state.load) {
            SetupLoad.offline => SetupLoadProblem(title: l.floorSetupOffline, onRetry: cubit.load),
            SetupLoad.failed => SetupLoadProblem(title: l.floorSetupLoadFailed, onRetry: cubit.load),
            _ => Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(lines: 4, semanticsLabel: l.floorSetupLoading),
            ),
          };
        }
        if (state.items.isEmpty) {
          return SingleChildScrollView(
            child: EmptyLedger(title: l.floorPlanEmptyTitle, message: l.floorPlanEmptyMessage, animated: false),
          );
        }
        final List<ProgressItem> shown = state.shown;
        final String floorDone = l.floorPlanFloorDone(percentText(plan.floor.percent));
        return ListView(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
          children: <Widget>[
            Text(
              floorDone,
              key: const ValueKey<String>('plan-floor-done'),
              style: N360Type.fit(N360Type.label, floorDone).copyWith(color: N360Colors.ink2),
            ),
            const SizedBox(height: N360Space.sm),
            GloveTextField(
              key: const ValueKey<String>('plan-search'),
              label: l.floorPlanSearch,
              prefixIcon: Icons.search,
              textInputAction: TextInputAction.search,
              onChanged: cubit.search,
            ),
            const SizedBox(height: N360Space.md),
            if (shown.isEmpty)
              EmptyLedger(title: l.floorPlanNoMatch, animated: false)
            else
              LedgerCard(
                padding: EdgeInsets.zero,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    for (final (int index, ProgressItem item) in shown.indexed) ...<Widget>[
                      if (index > 0) const RuledDivider(indent: N360Space.card),
                      _PlanTile(
                        item: item,
                        planned: plan.planned[item.id],
                        done: plan.done[item.id] ?? Qty.zero,
                        saving: state.saving == item.id,
                        onTap: state.saving != null ? null : () => _edit(screen, item, plan.planned[item.id]),
                      ),
                    ],
                  ],
                ),
              ),
          ],
        );
      },
    );
  }
}

class _PlanTile extends StatelessWidget {
  const _PlanTile({required this.item, required this.planned, required this.done, required this.saving, required this.onTap});

  final ProgressItem item;
  final Qty? planned;
  final Qty done;
  final bool saving;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String title = '${item.code} ${item.description}'.trim();
    final String boq = l.floorPlanBoqQty(item.qty.format(), item.unit);
    final String here = planned == null ? l.floorPlanNotPlanned : l.floorPlanPlanned(planned!.format(), item.unit, done.format());
    return LedgerListTile(
      key: ValueKey<String>('plan-line-${item.id}'),
      title: title,
      subtitle: '$here\n$boq',
      leading: Icon(
        planned == null ? Icons.radio_button_unchecked : Icons.check_circle_outline,
        size: N360IconSize.lg,
        color: planned == null ? N360Colors.ink2 : N360Colors.primary,
      ),
      trailing: saving
          ? const SizedBox.square(dimension: N360IconSize.md, child: CircularProgressIndicator(strokeWidth: 2))
          : null,
      onTap: onTap,
    );
  }
}

/// Takes the planned quantity for one BOQ line on this floor. Stays open with
/// the reason when the server refuses; pops true when saved.
class _QtySheet extends StatefulWidget {
  const _QtySheet({required this.item, required this.planned, required this.cubit});

  final ProgressItem item;
  final Qty? planned;
  final FloorPlanCubit cubit;

  @override
  State<_QtySheet> createState() => _QtySheetState();
}

class _QtySheetState extends State<_QtySheet> {
  late final TextEditingController _qty = TextEditingController(text: widget.planned?.toWire() ?? '');
  String? _error;
  bool _saving = false;

  /// The server refuses a quantity above this.
  static final Qty _max = Qty.fromInt(99999999);

  @override
  void dispose() {
    _qty.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final Qty? planned = Qty.tryParse(_qty.text);
    if (planned == null || planned.isNegative || planned > _max || Qty.hasExtraDecimals(_qty.text)) {
      setState(() => _error = l.floorPlanQtyInvalid);
      return;
    }
    if (_saving) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    final SetupProblem? problem = await widget.cubit.setPlanned(widget.item.id, planned);
    if (!mounted) return;
    if (problem == null) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _saving = false;
      _error = setupProblemText(l, problem);
    });
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressItem item = widget.item;
    final String title = '${item.code} ${item.description}'.trim();
    final String boq = l.floorPlanBoqQty(item.qty.format(), item.unit);
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
              Text(boq, style: N360Type.fit(N360Type.body, boq).copyWith(color: N360Colors.ink2)),
              const SizedBox(height: N360Space.md),
              GloveTextField(
                key: const ValueKey<String>('plan-qty'),
                label: l.floorPlanQty(item.unit),
                helper: l.floorPlanQtyHelp,
                controller: _qty,
                enabled: !_saving,
                errorText: _error,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                textInputAction: TextInputAction.done,
                onChanged: (_) {
                  if (_error != null) setState(() => _error = null);
                },
                onSubmitted: (_) => _save(),
              ),
              const SizedBox(height: N360Space.lg),
              GloveButton(
                key: const ValueKey<String>('plan-qty-save'),
                label: l.floorSetupSave,
                icon: Icons.check,
                fullWidth: true,
                loading: _saving,
                onPressed: _saving ? null : _save,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
