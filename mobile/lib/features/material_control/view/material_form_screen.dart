import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart' show BoqItemRow;
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../site_progress/view/widgets/progress_widgets.dart';
import '../bloc/material_form_cubit.dart';
import '../data/material_repository.dart';

/// One screen for the three things that happen to material on site: it comes,
/// it gets used, or someone counts what is left.
class MaterialFormScreen extends StatelessWidget {
  const MaterialFormScreen({super.key, required this.kind, this.materialId});

  final MaterialFormKind kind;
  final int? materialId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MaterialFormCubit>(
      create: (context) => MaterialFormCubit(context.read<AppServices>(), kind, materialId: materialId),
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
  final TextEditingController _qty = TextEditingController();
  final TextEditingController _challanQty = TextEditingController();
  final TextEditingController _supplier = TextEditingController();
  final TextEditingController _challanNo = TextEditingController();
  final TextEditingController _vehicleNo = TextEditingController();
  final TextEditingController _stage = TextEditingController();
  final TextEditingController _extraWork = TextEditingController();
  final TextEditingController _remarks = TextEditingController();

  @override
  void dispose() {
    for (final TextEditingController controller in <TextEditingController>[
      _qty,
      _challanQty,
      _supplier,
      _challanNo,
      _vehicleNo,
      _stage,
      _extraWork,
      _remarks,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(
      MaterialFormText(
        qty: _qty.text,
        challanQty: _challanQty.text,
        supplier: _supplier.text,
        challanNo: _challanNo.text,
        vehicleNo: _vehicleNo.text,
        stage: _stage.text,
        extraWork: _extraWork.text,
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

  String _title(AppLocalizations l, MaterialFormKind kind) => switch (kind) {
    MaterialFormKind.receive => l.materialReceived,
    MaterialFormKind.use => l.materialUsed,
    MaterialFormKind.count => l.materialCount,
  };

  String _qtyLabel(AppLocalizations l, MaterialFormKind kind) => switch (kind) {
    MaterialFormKind.receive => l.materialQtyReceived,
    MaterialFormKind.use => l.materialQtyUsed,
    MaterialFormKind.count => l.materialQtyCounted,
  };

  Future<void> _pickMaterial(MaterialFormState state) async {
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final MaterialOverview overview = state.overview!;
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.materialSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final MaterialInfo info in overview.materials)
          FieldPickOption(
            id: info.id,
            title: info.name,
            subtitle: overview.row(info.id) == null
                ? overview.baseUnit(info.id)
                : '${l.materialInStock} ${overview.row(info.id)!.stock.indian()} ${overview.baseUnit(info.id)}'.trim(),
          ),
      ],
    );
    if (id != null) cubit.setMaterial(id);
  }

  Future<void> _pickSupplier(MaterialFormState state) async {
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.materialSupplierSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final SupplierOption option in state.overview!.suppliers)
          FieldPickOption(id: option.id, title: option.name, subtitle: option.warning),
      ],
    );
    if (id != null) cubit.setSupplier(id);
  }

  Future<void> _pickBoq(MaterialFormState state) async {
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.progressFormItemSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final BoqItemRow line in state.boqLines)
          FieldPickOption(id: line.id, title: '${line.code} · ${line.description}'),
      ],
    );
    if (id != null) cubit.setBoqItem(id);
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
          child: BlocBuilder<MaterialFormCubit, MaterialFormState>(
            builder: (context, state) {
              final bool noData = state.ready && state.overview == null;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: _title(l, state.kind), subtitle: l.materialTitle),
                  Expanded(
                    child: noData
                        ? EmptyLedger(title: l.progressNoDataTitle, message: l.materialNoDataMessage)
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
                  if (state.problem == MaterialFormProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: l.progressSaveFailed),
                    ),
                  if (!noData)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('material-form-save'),
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

  List<Widget> _fields(BuildContext context, AppLocalizations l, MaterialFormState state) {
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    const Widget gap = SizedBox(height: N360Space.lg);
    final MaterialInfo? material = state.material;
    final MaterialOverview? overview = state.overview;

    return <Widget>[
      FieldBlock(
        label: l.materialName,
        error: state.problem == MaterialFormProblem.materialRequired ? l.materialChoose : null,
        child: FieldPickerTile(
          key: const ValueKey<String>('material-form-material'),
          icon: Icons.inventory_2_outlined,
          text: material?.name ?? l.materialChoose,
          muted: material == null,
          semanticsLabel: l.materialName,
          onTap: () {
            if (overview != null) _pickMaterial(state);
          },
        ),
      ),
      gap,
      if (state.kind == MaterialFormKind.receive) ...<Widget>[
        _supplierField(context, l, state),
        gap,
      ],
      _QtyField(
        key: const ValueKey<String>('material-form-qty'),
        controller: _qty,
        label: state.unitLabel.isEmpty ? _qtyLabel(l, state.kind) : '${_qtyLabel(l, state.kind)} (${state.unitLabel})',
        state: state,
        onChanged: cubit.clearProblem,
      ),
      if (material != null && material.factors.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.sm),
        Wrap(
          spacing: N360Space.sm,
          children: <Widget>[
            IntrinsicWidth(
              child: N360Chip(
                label: overview!.baseUnit(material.id),
                selected: state.unitId == null || state.unitId == material.baseUnitId,
                onTap: () => cubit.setUnit(null),
              ),
            ),
            for (final int unitId in material.factors.keys)
              IntrinsicWidth(
                child: N360Chip(
                  label: overview.units[unitId] ?? '',
                  selected: state.unitId == unitId,
                  onTap: () => cubit.setUnit(unitId),
                ),
              ),
          ],
        ),
      ],
      gap,
      if (state.kind == MaterialFormKind.receive) ...<Widget>[
        GloveTextField(
          label: l.materialQtyOnChallan,
          helper: l.materialQtyOnChallanHint,
          controller: _challanQty,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
        ),
        gap,
        GloveTextField(label: l.materialChallanNo, controller: _challanNo),
        gap,
        GloveTextField(
          label: l.materialVehicleNo,
          controller: _vehicleNo,
          textCapitalization: TextCapitalization.characters,
        ),
        gap,
      ],
      if (state.kind == MaterialFormKind.use) ...<Widget>[..._useFields(context, l, state), gap],
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
      if ((overview?.stores.length ?? 0) > 1) ...<Widget>[
        gap,
        FieldBlock(
          label: l.materialStore,
          child: Wrap(
            spacing: N360Space.sm,
            children: <Widget>[
              for (final (index, store) in overview!.stores.indexed)
                IntrinsicWidth(
                  child: N360Chip(
                    label: store.name,
                    selected: state.storeId == store.id || (state.storeId == null && index == 0),
                    onTap: () => cubit.setStore(store.id),
                  ),
                ),
            ],
          ),
        ),
      ],
      if (state.kind == MaterialFormKind.receive) ...<Widget>[
        gap,
        ProgressPhotoRow(
          max: 1,
          label: l.materialChallanPhoto,
          photos: <String>[?state.photo],
          onAdd: cubit.setPhoto,
          onRemove: (_) => cubit.setPhoto(null),
        ),
      ],
      if (state.kind == MaterialFormKind.count) ...<Widget>[
        gap,
        GloveTextField(label: l.progressStageNote, controller: _remarks, maxLines: 2),
        const SizedBox(height: N360Space.sm),
        Text(l.materialCountHint, style: N360Type.fit(N360Type.caption, l.materialCountHint)),
      ],
    ];
  }

  Widget _supplierField(BuildContext context, AppLocalizations l, MaterialFormState state) {
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    final String? error = state.problem == MaterialFormProblem.supplierRequired ? l.materialSupplierRequired : null;
    final SupplierOption? supplier = state.supplier;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        if (state.typingSupplier)
          GloveTextField(
            key: const ValueKey<String>('material-form-supplier-name'),
            label: l.materialSupplier,
            hint: l.materialSupplierName,
            controller: _supplier,
            errorText: error,
            onChanged: (_) => cubit.clearProblem(),
          )
        else
          FieldBlock(
            label: l.materialSupplier,
            error: error,
            child: FieldPickerTile(
              key: const ValueKey<String>('material-form-supplier'),
              icon: Icons.storefront_outlined,
              text: supplier?.name ?? l.materialSupplierChoose,
              muted: supplier == null,
              semanticsLabel: l.materialSupplier,
              onTap: () => _pickSupplier(state),
            ),
          ),
        Align(
          alignment: Alignment.centerLeft,
          child: GloveButton(
            key: const ValueKey<String>('material-form-supplier-switch'),
            label: state.typingSupplier ? l.materialSupplierFromList : l.materialSupplierNew,
            kind: GloveButtonKind.ghost,
            onPressed: state.typingSupplier ? cubit.chooseSupplierFromList : cubit.typeSupplier,
          ),
        ),
        if (supplier?.warning != null)
          Text(
            supplier!.warning!,
            style: N360Type.fit(N360Type.caption, supplier.warning!).copyWith(color: N360Colors.laterite),
          ),
      ],
    );
  }

  List<Widget> _useFields(BuildContext context, AppLocalizations l, MaterialFormState state) {
    final MaterialFormCubit cubit = context.read<MaterialFormCubit>();
    final BoqItemRow? line = state.boqLine;
    return <Widget>[
      Wrap(
        spacing: N360Space.sm,
        children: <Widget>[
          IntrinsicWidth(
            child: N360Chip(
              label: l.materialOnBoq,
              selected: !state.extraWork,
              onTap: () => cubit.setExtraWork(false),
            ),
          ),
          IntrinsicWidth(
            child: N360Chip(
              key: const ValueKey<String>('material-form-extra'),
              label: l.materialOnExtra,
              selected: state.extraWork,
              onTap: () => cubit.setExtraWork(true),
            ),
          ),
        ],
      ),
      const SizedBox(height: N360Space.lg),
      if (state.extraWork)
        GloveTextField(
          key: const ValueKey<String>('material-form-extra-text'),
          label: l.materialExtraWhat,
          controller: _extraWork,
          maxLines: 2,
          errorText: state.problem == MaterialFormProblem.extraRequired ? l.materialExtraRequired : null,
          onChanged: (_) => cubit.clearProblem(),
        )
      else ...<Widget>[
        FieldBlock(
          label: l.progressFormItem,
          error: state.problem == MaterialFormProblem.workRequired ? l.progressFormItemChoose : null,
          child: FieldPickerTile(
            key: const ValueKey<String>('material-form-boq'),
            icon: Icons.format_list_numbered,
            text: line == null ? l.progressFormItemChoose : '${line.code} · ${line.description}',
            muted: line == null,
            semanticsLabel: l.progressFormItem,
            onTap: () => _pickBoq(state),
          ),
        ),
        const SizedBox(height: N360Space.lg),
        GloveTextField(label: l.materialStage, hint: l.materialStageHint, controller: _stage),
      ],
    ];
  }
}

/// The quantity. When using material it also says what the phone thinks is in
/// stock, because the server refuses more than there is.
class _QtyField extends StatelessWidget {
  const _QtyField({super.key, required this.controller, required this.label, required this.state, required this.onChanged});

  final TextEditingController controller;
  final String label;
  final MaterialFormState state;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final int? materialId = state.materialId;
    final bool using = state.kind == MaterialFormKind.use;
    final MaterialRow? row = !using || materialId == null ? null : state.overview?.row(materialId);
    return ValueListenableBuilder<TextEditingValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        final Qty? typed = Qty.tryParse(value.text);
        final Qty? base = typed == null || materialId == null
            ? null
            : state.overview?.material(materialId)?.toBase(typed, state.unitId);
        final Qty stock = row?.stock ?? Qty.zero;
        final bool over = using && materialId != null && base != null && base > stock;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            GloveTextField(
              label: label,
              controller: controller,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              errorText: state.problem == MaterialFormProblem.qtyRequired ? l.materialQtyRequired : null,
              helper: !using || materialId == null
                  ? null
                  : '${l.materialInStock} ${stock.indian()} ${state.overview!.baseUnit(materialId)}'.trim(),
              onChanged: (_) => onChanged(),
            ),
            if (over)
              Text(
                l.materialOverStock,
                style: N360Type.fit(N360Type.caption, l.materialOverStock).copyWith(color: N360Colors.laterite),
              ),
          ],
        );
      },
    );
  }
}
