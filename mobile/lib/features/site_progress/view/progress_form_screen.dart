import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/progress_form_cubit.dart';
import '../data/progress_repository.dart';
import 'widgets/progress_widgets.dart';

/// Today's work in a few taps: the floor and item come back from last time,
/// so most days it is a number and Save.
class ProgressFormScreen extends StatelessWidget {
  const ProgressFormScreen({super.key, this.floorId});

  final int? floorId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProgressFormCubit>(
      create: (context) => ProgressFormCubit(context.read<AppServices>(), floorId: floorId),
      child: const _ProgressFormView(),
    );
  }
}

class _ProgressFormView extends StatefulWidget {
  const _ProgressFormView();

  @override
  State<_ProgressFormView> createState() => _ProgressFormViewState();
}

class _ProgressFormViewState extends State<_ProgressFormView> {
  final TextEditingController _qty = TextEditingController();
  final TextEditingController _remark = TextEditingController();

  @override
  void dispose() {
    _qty.dispose();
    _remark.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressFormCubit cubit = context.read<ProgressFormCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(qtyText: _qty.text, remark: _remark.text);
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

  Future<void> _pickFloor(ProgressFormState state) async {
    final ProgressFormCubit cubit = context.read<ProgressFormCubit>();
    final List<FloorChoice> choices = state.overview?.floorChoices ?? const <FloorChoice>[];
    final _FloorPick? pick = await showLedgerSheet<_FloorPick>(
      context,
      isScrollControlled: true,
      builder: (context) => _FloorSheet(choices: choices, current: state.floorId),
    );
    if (pick != null) cubit.setFloor(pick.id);
  }

  Future<void> _pickItem(ProgressFormState state) async {
    final ProgressFormCubit cubit = context.read<ProgressFormCubit>();
    final int? id = await showLedgerSheet<int>(
      context,
      isScrollControlled: true,
      builder: (context) => _ItemSheet(items: state.overview?.items ?? const <ProgressItem>[]),
    );
    if (id != null) cubit.setItem(id);
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
          child: BlocBuilder<ProgressFormCubit, ProgressFormState>(
            builder: (context, state) {
              final ProgressFormCubit cubit = context.read<ProgressFormCubit>();
              final bool pendingOnly = state.kind == ProgressKind.pendingOnly;
              final ProgressItem? item = state.item;
              final bool noData = state.ready && state.overview == null;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.progressFormTitle, subtitle: l.progressTitle),
                  Expanded(
                    child: noData
                        ? EmptyLedger(title: l.progressNoDataTitle, message: l.progressNoDataMessage)
                        : ListView(
                            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                            padding: const EdgeInsets.fromLTRB(
                              N360Space.gutter,
                              N360Space.lg,
                              N360Space.gutter,
                              N360Space.sm,
                            ),
                            children: <Widget>[
                              _Block(
                                label: l.progressFormFloor,
                                child: _PickerField(
                                  key: const ValueKey<String>('progress-form-floor'),
                                  icon: Icons.layers_outlined,
                                  text: state.floor?.label ?? l.progressFormFloorNone,
                                  semanticsLabel: l.progressFormFloor,
                                  onTap: () => _pickFloor(state),
                                ),
                              ),
                              const SizedBox(height: N360Space.lg),
                              _Block(
                                label: l.progressFormItem,
                                error: state.problem == ProgressFormProblem.itemRequired
                                    ? l.progressFormItemChoose
                                    : null,
                                child: _PickerField(
                                  key: const ValueKey<String>('progress-form-item'),
                                  icon: Icons.format_list_numbered,
                                  text: item == null ? l.progressFormItemChoose : '${item.code} · ${item.description}',
                                  muted: item == null,
                                  semanticsLabel: l.progressFormItem,
                                  onTap: () => _pickItem(state),
                                ),
                              ),
                              const SizedBox(height: N360Space.lg),
                              Wrap(
                                spacing: N360Space.sm,
                                children: <Widget>[
                                  IntrinsicWidth(
                                    child: N360Chip(
                                      label: l.progressFormKindWork,
                                      selected: !pendingOnly,
                                      onTap: () => cubit.setKind(ProgressKind.work),
                                    ),
                                  ),
                                  IntrinsicWidth(
                                    child: N360Chip(
                                      key: const ValueKey<String>('progress-form-pending'),
                                      label: l.progressFormKindPending,
                                      selected: pendingOnly,
                                      onTap: () => cubit.setKind(ProgressKind.pendingOnly),
                                    ),
                                  ),
                                ],
                              ),
                              const SizedBox(height: N360Space.lg),
                              if (state.asksContractor && item != null) ...<Widget>[
                                _Block(
                                  label: l.progressFormWhose,
                                  error: state.problem == ProgressFormProblem.contractorRequired
                                      ? l.progressFormWhoseRequired
                                      : null,
                                  child: Wrap(
                                    spacing: N360Space.sm,
                                    children: <Widget>[
                                      for (final ProgressWorkOrder order in item.workOrders)
                                        IntrinsicWidth(
                                          child: N360Chip(
                                            key: ValueKey<String>('progress-form-contractor-${order.id}'),
                                            label: order.label,
                                            selected: state.workOrderId == order.id,
                                            onTap: () => cubit.setWorkOrder(order.id),
                                          ),
                                        ),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: N360Space.lg),
                              ],
                              if (!pendingOnly) ...<Widget>[
                                _QtyField(controller: _qty, item: item, problem: state.problem, onChanged: cubit.clearProblem),
                                const SizedBox(height: N360Space.lg),
                              ],
                              GloveTextField(
                                key: const ValueKey<String>('progress-form-remark'),
                                label: pendingOnly ? l.progressFormPendingRemark : l.progressFormRemark,
                                controller: _remark,
                                maxLines: 3,
                                errorText: state.problem == ProgressFormProblem.remarkRequired
                                    ? l.progressFormRemarkRequired
                                    : null,
                                onChanged: (_) => cubit.clearProblem(),
                              ),
                              const SizedBox(height: N360Space.lg),
                              _Block(
                                label: l.progressFormDate,
                                child: Wrap(
                                  spacing: N360Space.sm,
                                  children: <Widget>[
                                    IntrinsicWidth(
                                      child: N360Chip(
                                        label: l.progressFormToday,
                                        selected: state.workDate == state.today,
                                        onTap: () => cubit.setDate(state.today),
                                      ),
                                    ),
                                    IntrinsicWidth(
                                      child: N360Chip(
                                        label: l.progressFormYesterday,
                                        selected: state.workDate == state.yesterday,
                                        onTap: () => cubit.setDate(state.yesterday),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: N360Space.lg),
                              ProgressPhotoRow(photos: state.photos, onAdd: cubit.addPhoto, onRemove: cubit.removePhoto),
                            ],
                          ),
                  ),
                  if (state.problem == ProgressFormProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: l.progressSaveFailed),
                    ),
                  if (!noData)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('progress-form-save'),
                        label: l.progressFormSave,
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
}

/// The quantity, with the BOQ balance under it. Going over the balance is
/// allowed, because the server allows it too, but it is said out loud.
class _QtyField extends StatelessWidget {
  const _QtyField({required this.controller, required this.item, required this.problem, required this.onChanged});

  final TextEditingController controller;
  final ProgressItem? item;
  final ProgressFormProblem? problem;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressItem? line = item;
    return ValueListenableBuilder<TextEditingValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        final Qty? typed = Qty.tryParse(value.text);
        final bool over = line != null && typed != null && typed > line.balance;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            GloveTextField(
              key: const ValueKey<String>('progress-form-qty'),
              label: line == null || line.unit.isEmpty ? l.progressFormQty : '${l.progressFormQty} (${line.unit})',
              controller: controller,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              errorText: problem == ProgressFormProblem.qtyRequired ? l.progressFormQtyRequired : null,
              helper: line == null ? null : l.progressFormBalance('${line.balance.indian()} ${line.unit}'.trim()),
              onChanged: (_) => onChanged(),
            ),
            if (over)
              Text(
                l.progressFormOverBalance,
                style: N360Type.fit(N360Type.caption, l.progressFormOverBalance).copyWith(color: N360Colors.laterite),
              ),
          ],
        );
      },
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.label, required this.child, this.error});

  final String label;
  final Widget child;
  final String? error;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.fit(N360Type.label, label)),
        const SizedBox(height: N360Space.xs),
        child,
        if (error != null)
          Text(error!, style: N360Type.fit(N360Type.caption, error!).copyWith(color: N360Colors.laterite)),
      ],
    );
  }
}

class _PickerField extends StatelessWidget {
  const _PickerField({
    super.key,
    required this.icon,
    required this.text,
    required this.semanticsLabel,
    required this.onTap,
    this.muted = false,
  });

  final IconData icon;
  final String text;
  final String semanticsLabel;
  final VoidCallback onTap;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      semanticsLabel: '$semanticsLabel: $text',
      builder: (context, depth, child) => Transform.scale(
        scale: pressedScale(depth),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: N360Colors.sheet,
            borderRadius: N360Radius.md,
            border: Border.all(color: N360Colors.ruleStrong),
          ),
          child: child,
        ),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.sm),
          child: Row(
            children: <Widget>[
              Icon(icon, size: N360IconSize.lg, color: N360Colors.ink2),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Text(
                  text,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.fit(N360Type.bodyLarge, text).copyWith(
                    color: muted ? N360Colors.inkLabel : N360Colors.ink,
                  ),
                ),
              ),
              const Icon(Icons.expand_more, size: N360IconSize.lg, color: N360Colors.ink2),
            ],
          ),
        ),
      ),
    );
  }
}

/// Null [id] is the whole project. Wrapped so a dismissed sheet is told apart.
class _FloorPick {
  const _FloorPick(this.id);

  final int? id;
}

class _FloorSheet extends StatelessWidget {
  const _FloorSheet({required this.choices, required this.current});

  final List<FloorChoice> choices;
  final int? current;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.7),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.only(bottom: N360Space.lg),
        children: <Widget>[
          LedgerListTile(
            title: l.progressFormFloorNone,
            trailing: current == null ? const Icon(Icons.check, color: N360Colors.primary) : const SizedBox.shrink(),
            onTap: () => Navigator.of(context).pop(const _FloorPick(null)),
          ),
          for (final FloorChoice choice in choices)
            LedgerListTile(
              key: ValueKey<String>('progress-floor-choice-${choice.floor.id}'),
              title: choice.label,
              trailing: current == choice.floor.id
                  ? const Icon(Icons.check, color: N360Colors.primary)
                  : const SizedBox.shrink(),
              onTap: () => Navigator.of(context).pop(_FloorPick(choice.floor.id)),
            ),
        ],
      ),
    );
  }
}

class _ItemSheet extends StatefulWidget {
  const _ItemSheet({required this.items});

  final List<ProgressItem> items;

  @override
  State<_ItemSheet> createState() => _ItemSheetState();
}

class _ItemSheetState extends State<_ItemSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String query = _query.trim().toLowerCase();
    final List<ProgressItem> shown = query.isEmpty
        ? widget.items
        : widget.items
              .where((item) => item.code.toLowerCase().contains(query) || item.description.toLowerCase().contains(query))
              .toList();
    return SizedBox(
      height: MediaQuery.sizeOf(context).height * 0.8,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
            child: GloveTextField(
              label: l.progressFormItemSearch,
              prefixIcon: Icons.search,
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          Expanded(
            child: shown.isEmpty
                ? EmptyLedger(title: l.progressFormItemNone, message: l.progressFormItemSearch)
                : ListView.builder(
                    padding: EdgeInsets.only(bottom: N360Space.lg + MediaQuery.viewInsetsOf(context).bottom),
                    itemCount: shown.length,
                    itemBuilder: (context, index) {
                      final ProgressItem item = shown[index];
                      return LedgerListTile(
                        key: ValueKey<String>('progress-item-choice-${item.id}'),
                        title: '${item.code} · ${item.description}',
                        subtitle: l.progressFormBalance('${item.balance.indian()} ${item.unit}'.trim()),
                        trailing: const SizedBox.shrink(),
                        onTap: () => Navigator.of(context).pop(item.id),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
