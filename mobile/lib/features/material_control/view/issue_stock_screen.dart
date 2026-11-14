import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show BoqItemRow;
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/bloc/measurement_book.dart';
import '../bloc/issue_stock_cubit.dart';
import '../data/material_repository.dart';

/// Material leaving a store, one or more items at a time.
class IssueStockScreen extends StatelessWidget {
  const IssueStockScreen({super.key, this.materialId});

  final int? materialId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<IssueStockCubit>(
      create: (context) => IssueStockCubit(context.read<AppServices>(), materialId: materialId),
      child: const _IssueView(),
    );
  }
}

class _IssueView extends StatefulWidget {
  const _IssueView();

  @override
  State<_IssueView> createState() => _IssueViewState();
}

class _IssueViewState extends State<_IssueView> {
  final Map<int, TextEditingController> _qty = <int, TextEditingController>{};
  final Map<int, TextEditingController> _activity = <int, TextEditingController>{};
  final TextEditingController _issuedTo = TextEditingController();

  @override
  void dispose() {
    for (final TextEditingController controller in <TextEditingController>[..._qty.values, ..._activity.values, _issuedTo]) {
      controller.dispose();
    }
    super.dispose();
  }

  TextEditingController _qtyFor(int key) => _qty.putIfAbsent(key, TextEditingController.new);

  TextEditingController _activityFor(int key) => _activity.putIfAbsent(key, TextEditingController.new);

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final IssueStockCubit cubit = context.read<IssueStockCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(
      IssueText(
        issuedTo: _issuedTo.text,
        lines: <int, IssueLineText>{
          for (final IssueLine line in cubit.state.lines)
            line.key: IssueLineText(qty: _qtyFor(line.key).text, activity: _activityFor(line.key).text),
        },
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

  Future<void> _pickMaterial(IssueStockState state, IssueLine line) async {
    final IssueStockCubit cubit = context.read<IssueStockCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final MaterialOverview overview = state.overview!;
    final int? storeId = state.storeId;
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.materialSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final MaterialInfo info in overview.materials)
          FieldPickOption(
            id: info.id,
            title: info.name,
            subtitle: storeId == null
                ? overview.baseUnit(info.id)
                : '${l.materialInStock} ${formatQty(overview.stockIn(storeId, info.id))} ${overview.baseUnit(info.id)}'.trim(),
          ),
      ],
    );
    if (id != null) cubit.setMaterial(line.key, id);
  }

  Future<void> _pickBoq(IssueStockState state, IssueLine line) async {
    final IssueStockCubit cubit = context.read<IssueStockCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.progressFormItemSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final BoqItemRow row in state.boqLines) FieldPickOption(id: row.id, title: '${row.code} · ${row.description}'),
      ],
    );
    if (id != null) cubit.setBoqItem(line.key, id);
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
          child: BlocBuilder<IssueStockCubit, IssueStockState>(
            builder: (context, state) {
              final bool noData = state.ready && state.overview == null;
              final bool noStore = state.ready && state.overview != null && state.overview!.stores.isEmpty;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.procurementIssueStock, subtitle: l.materialTitle),
                  Expanded(
                    child: noData
                        ? EmptyLedger(title: l.progressNoDataTitle, message: l.materialNoDataMessage)
                        : noStore
                        ? EmptyLedger(title: l.procurementNoStoreTitle, message: l.procurementNoStoreMessage)
                        : SingleChildScrollView(
                            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                            padding: const EdgeInsets.fromLTRB(
                              N360Space.gutter,
                              N360Space.lg,
                              N360Space.gutter,
                              N360Space.sm,
                            ),
                            // A column, not a lazy list: a form is a few cards, and every box
                            // keeps what was typed in it whether or not it is on screen.
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: _fields(context, l, state),
                            ),
                          ),
                  ),
                  if (state.problem == IssueProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: l.progressSaveFailed),
                    ),
                  if (!noData && !noStore)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('issue-stock-save'),
                        label: l.commonSave,
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

  List<Widget> _fields(BuildContext context, AppLocalizations l, IssueStockState state) {
    final IssueStockCubit cubit = context.read<IssueStockCubit>();
    const Widget gap = SizedBox(height: N360Space.lg);
    final MaterialOverview? overview = state.overview;
    if (overview == null) return const <Widget>[];

    return <Widget>[
      if (overview.stores.length > 1) ...<Widget>[
        FieldBlock(
          label: l.materialStore,
          child: Wrap(
            spacing: N360Space.sm,
            children: <Widget>[
              for (final MaterialStoreInfo store in overview.stores)
                IntrinsicWidth(
                  child: N360Chip(
                    key: ValueKey<String>('issue-store-${store.id}'),
                    label: store.name,
                    selected: state.storeId == store.id,
                    onTap: () => cubit.setStore(store.id),
                  ),
                ),
            ],
          ),
        ),
        gap,
      ],
      for (final (index, line) in state.lines.indexed) ...<Widget>[
        _LineCard(
          key: ValueKey<String>('issue-line-${line.key}'),
          number: index + 1,
          line: line,
          state: state,
          qty: _qtyFor(line.key),
          activity: _activityFor(line.key),
          canRemove: state.lines.length > 1,
          onPickMaterial: () => _pickMaterial(state, line),
          onPickBoq: () => _pickBoq(state, line),
        ),
        const SizedBox(height: N360Space.md),
      ],
      if (state.lines.length < MaterialRepository.issueLinesMax)
        Align(
          alignment: Alignment.centerLeft,
          child: GloveButton(
            key: const ValueKey<String>('issue-add-line'),
            label: l.procurementAddItem,
            icon: Icons.add,
            kind: GloveButtonKind.secondary,
            onPressed: cubit.addLine,
          ),
        ),
      gap,
      FieldBlock(
        label: l.progressFormDate,
        child: Wrap(
          spacing: N360Space.sm,
          children: <Widget>[
            IntrinsicWidth(
              child: N360Chip(
                label: l.progressFormToday,
                selected: state.date == state.today,
                onTap: () => cubit.setDate(state.today),
              ),
            ),
            IntrinsicWidth(
              child: N360Chip(
                label: l.progressFormYesterday,
                selected: state.date == state.yesterday,
                onTap: () => cubit.setDate(state.yesterday),
              ),
            ),
          ],
        ),
      ),
      gap,
      GloveTextField(
        key: const ValueKey<String>('issue-issued-to'),
        label: l.procurementIssuedTo,
        hint: l.procurementIssuedToHint,
        controller: _issuedTo,
        inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MaterialRepository.issuedToMax)],
      ),
    ];
  }
}

/// One item being issued: the material, how much, and what it is for.
class _LineCard extends StatelessWidget {
  const _LineCard({
    super.key,
    required this.number,
    required this.line,
    required this.state,
    required this.qty,
    required this.activity,
    required this.canRemove,
    required this.onPickMaterial,
    required this.onPickBoq,
  });

  final int number;
  final IssueLine line;
  final IssueStockState state;
  final TextEditingController qty;
  final TextEditingController activity;
  final bool canRemove;
  final VoidCallback onPickMaterial;
  final VoidCallback onPickBoq;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final IssueStockCubit cubit = context.read<IssueStockCubit>();
    final MaterialOverview overview = state.overview!;
    final int? materialId = line.materialId;
    final MaterialInfo? material = materialId == null ? null : overview.material(materialId);
    final BoqItemRow? boq = state.boqLine(line.boqItemId);
    final IssueLineProblem? problem = state.lineProblems[line.key];
    final int? storeId = state.storeId;
    final Qty held = materialId == null || storeId == null ? Qty.zero : overview.stockIn(storeId, materialId);
    final String unit = materialId == null ? '' : overview.unitLabel(materialId, line.unitId);
    final List<int> units = material?.unitIds() ?? const <int>[];
    final String heading = l.procurementItemN(number);

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(child: Text(heading, style: N360Type.fit(N360Type.label, heading))),
              if (canRemove)
                GloveButton(
                  key: ValueKey<String>('issue-remove-${line.key}'),
                  label: l.procurementRemoveItem,
                  icon: Icons.close,
                  kind: GloveButtonKind.ghost,
                  onPressed: () => cubit.removeLine(line.key),
                ),
            ],
          ),
          const SizedBox(height: N360Space.xs),
          FieldBlock(
            label: l.materialName,
            error: problem == IssueLineProblem.materialRequired ? l.materialChoose : null,
            child: FieldPickerTile(
              key: ValueKey<String>('issue-material-${line.key}'),
              icon: Icons.inventory_2_outlined,
              text: material?.name ?? l.materialChoose,
              muted: material == null,
              semanticsLabel: l.materialName,
              onTap: onPickMaterial,
            ),
          ),
          if (units.length > 1) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Wrap(
              spacing: N360Space.sm,
              children: <Widget>[
                for (final int id in units)
                  IntrinsicWidth(
                    child: N360Chip(
                      key: ValueKey<String>('issue-unit-${line.key}-$id'),
                      label: overview.units[id] ?? '',
                      selected: (line.unitId ?? material?.baseUnitId) == id,
                      onTap: () => cubit.setUnit(line.key, id == material?.baseUnitId ? null : id),
                    ),
                  ),
              ],
            ),
          ],
          const SizedBox(height: N360Space.md),
          ValueListenableBuilder<TextEditingValue>(
            valueListenable: qty,
            builder: (context, value, _) {
              final Qty? typed = Qty.tryParse(value.text);
              final Qty? base = typed == null || material == null ? null : material.toBase(typed, line.unitId);
              final bool over = base != null && base > held;
              final String? error = switch (problem) {
                IssueLineProblem.qtyRequired => l.materialQtyRequired,
                IssueLineProblem.tooManyDecimals => l.procurementTooManyDecimals,
                _ => null,
              };
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  GloveTextField(
                    key: ValueKey<String>('issue-qty-${line.key}'),
                    label: unit.isEmpty ? l.materialQtyIssued : '${l.materialQtyIssued} ($unit)',
                    controller: qty,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp(r'[0-9.,०-९]'))],
                    helper: materialId == null
                        ? null
                        : l.procurementInStore('${formatQty(held)} ${overview.baseUnit(materialId)}'.trim()),
                    errorText: error,
                    onChanged: (_) => cubit.clearLineProblem(line.key),
                  ),
                  if (over)
                    Text(
                      l.materialOverStock,
                      style: N360Type.fit(N360Type.caption, l.materialOverStock).copyWith(color: N360Colors.laterite),
                    ),
                ],
              );
            },
          ),
          const SizedBox(height: N360Space.md),
          FieldBlock(
            label: l.procurementForBoq,
            error: problem == IssueLineProblem.workRequired ? l.procurementWorkRequired : null,
            child: FieldPickerTile(
              key: ValueKey<String>('issue-boq-${line.key}'),
              icon: Icons.format_list_numbered,
              text: boq == null ? l.progressFormItemChoose : '${boq.code} · ${boq.description}',
              muted: boq == null,
              semanticsLabel: l.procurementForBoq,
              onTap: onPickBoq,
            ),
          ),
          const SizedBox(height: N360Space.md),
          GloveTextField(
            key: ValueKey<String>('issue-activity-${line.key}'),
            label: l.procurementActivity,
            hint: l.procurementActivityHint,
            controller: activity,
            inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MaterialRepository.activityMax)],
            onChanged: (_) => cubit.clearLineProblem(line.key),
          ),
        ],
      ),
    );
  }
}
