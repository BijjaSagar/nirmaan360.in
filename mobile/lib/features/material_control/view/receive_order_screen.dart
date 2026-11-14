import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/bloc/measurement_book.dart';
import '../bloc/receive_order_cubit.dart';
import '../data/material_repository.dart';

/// Goods arriving against an issued purchase order. Quantities only: the order's
/// rates are never read, kept or drawn.
class ReceiveOrderScreen extends StatelessWidget {
  const ReceiveOrderScreen({super.key, this.orderId});

  final int? orderId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ReceiveOrderCubit>(
      create: (context) => ReceiveOrderCubit(context.read<AppServices>(), orderId: orderId),
      child: const _ReceiveView(),
    );
  }
}

class _ReceiveView extends StatefulWidget {
  const _ReceiveView();

  @override
  State<_ReceiveView> createState() => _ReceiveViewState();
}

class _ReceiveViewState extends State<_ReceiveView> {
  final Map<int, TextEditingController> _accepted = <int, TextEditingController>{};
  final Map<int, TextEditingController> _rejected = <int, TextEditingController>{};
  final TextEditingController _challanNo = TextEditingController();
  final TextEditingController _vehicleNo = TextEditingController();
  final TextEditingController _remarks = TextEditingController();

  @override
  void dispose() {
    for (final TextEditingController controller in <TextEditingController>[
      ..._accepted.values,
      ..._rejected.values,
      _challanNo,
      _vehicleNo,
      _remarks,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  TextEditingController _acceptedFor(int lineId) => _accepted.putIfAbsent(lineId, TextEditingController.new);

  TextEditingController _rejectedFor(int lineId) => _rejected.putIfAbsent(lineId, TextEditingController.new);

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final ReceiveOrderCubit cubit = context.read<ReceiveOrderCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.submit(
      ReceiveOrderText(
        lines: <int, ReceiveLineText>{
          for (final int id in <int>{..._accepted.keys, ..._rejected.keys})
            id: ReceiveLineText(accepted: _accepted[id]?.text ?? '', rejected: _rejected[id]?.text ?? ''),
        },
        challanNo: _challanNo.text,
        vehicleNo: _vehicleNo.text,
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

  Future<void> _pickOrder(ReceiveOrderState state) async {
    final ReceiveOrderCubit cubit = context.read<ReceiveOrderCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final int? id = await showFieldSearchPicker(
      context,
      searchLabel: l.procurementOrderSearch,
      emptyTitle: l.materialSearchNone,
      options: <FieldPickOption>[
        for (final OpenOrder order in state.overview!.ordersDue)
          FieldPickOption(
            id: order.id,
            title: order.number,
            subtitle: <String>[?order.vendor, l.procurementOrderDue(order.due.length)].join(' · '),
          ),
      ],
    );
    if (id != null) cubit.setOrder(id);
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
          child: BlocBuilder<ReceiveOrderCubit, ReceiveOrderState>(
            builder: (context, state) {
              final bool noData = state.ready && state.overview == null;
              final bool noOrders = state.ready && state.overview != null && state.overview!.ordersDue.isEmpty;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.procurementReceiveOrder, subtitle: l.materialTitle),
                  Expanded(
                    child: noData
                        ? EmptyLedger(title: l.progressNoDataTitle, message: l.materialNoDataMessage)
                        : noOrders
                        ? EmptyLedger(title: l.procurementNoOrdersTitle, message: l.procurementNoOrdersMessage)
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
                  if (state.problem == ReceiveProblem.saveFailed)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: ErrorSlip(title: l.progressSaveFailed),
                    ),
                  if (!noData && !noOrders)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('receive-order-save'),
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

  List<Widget> _fields(BuildContext context, AppLocalizations l, ReceiveOrderState state) {
    final ReceiveOrderCubit cubit = context.read<ReceiveOrderCubit>();
    const Widget gap = SizedBox(height: N360Space.lg);
    final MaterialOverview? overview = state.overview;
    final OpenOrder? order = state.order;
    final String? lineError = switch (state.problem) {
      ReceiveProblem.nothingReceived => l.procurementNothingReceived,
      _ => null,
    };

    return <Widget>[
      FieldBlock(
        label: l.procurementOrder,
        error: state.problem == ReceiveProblem.orderRequired ? l.procurementOrderRequired : null,
        child: FieldPickerTile(
          key: const ValueKey<String>('receive-order-pick'),
          icon: Icons.assignment_outlined,
          text: order == null ? l.procurementOrderChoose : <String>[order.number, ?order.vendor].join(' · '),
          muted: order == null,
          semanticsLabel: l.procurementOrder,
          onTap: () {
            if (overview != null) _pickOrder(state);
          },
        ),
      ),
      if (order != null && overview != null) ...<Widget>[
        const SizedBox(height: N360Space.sm),
        Text(l.procurementPartialNote, style: N360Type.fit(N360Type.caption, l.procurementPartialNote)),
        gap,
        for (final OrderLine line in order.due) ...<Widget>[
          _LineCard(
            key: ValueKey<String>('receive-line-${line.id}'),
            line: line,
            overview: overview,
            state: state,
            accepted: _acceptedFor(line.id),
            rejected: _rejectedFor(line.id),
            onChanged: () => cubit.clearLineProblem(line.id),
          ),
          const SizedBox(height: N360Space.md),
        ],
        if (lineError != null)
          Padding(
            padding: const EdgeInsets.only(bottom: N360Space.md),
            child: Text(lineError, style: N360Type.fit(N360Type.body, lineError).copyWith(color: N360Colors.laterite)),
          ),
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
        if (overview.stores.length > 1) ...<Widget>[
          gap,
          FieldBlock(
            label: l.materialStore,
            child: Wrap(
              spacing: N360Space.sm,
              children: <Widget>[
                for (final MaterialStoreInfo store in overview.stores)
                  IntrinsicWidth(
                    child: N360Chip(
                      label: store.name,
                      selected: (state.storeId ?? order.storeId ?? overview.stores.first.id) == store.id,
                      onTap: () => cubit.setStore(store.id),
                    ),
                  ),
              ],
            ),
          ),
        ],
        gap,
        GloveTextField(
          key: const ValueKey<String>('receive-challan-no'),
          label: l.materialChallanNo,
          controller: _challanNo,
          inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MaterialRepository.challanNoMax)],
        ),
        gap,
        GloveTextField(
          label: l.materialVehicleNo,
          controller: _vehicleNo,
          textCapitalization: TextCapitalization.characters,
          inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MaterialRepository.vehicleNoMax)],
        ),
        gap,
        GloveTextField(
          key: const ValueKey<String>('receive-remarks'),
          label: l.procurementRemarks,
          hint: l.procurementRemarksHint,
          controller: _remarks,
          maxLines: 2,
          inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(MaterialRepository.remarksMax)],
        ),
      ],
    ];
  }
}

/// One line of the order: what was ordered, what is still due, and two boxes.
class _LineCard extends StatelessWidget {
  const _LineCard({
    super.key,
    required this.line,
    required this.overview,
    required this.state,
    required this.accepted,
    required this.rejected,
    required this.onChanged,
  });

  final OrderLine line;
  final MaterialOverview overview;
  final ReceiveOrderState state;
  final TextEditingController accepted;
  final TextEditingController rejected;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ReceiveOrderCubit cubit = context.read<ReceiveOrderCubit>();
    final MaterialInfo? material = overview.material(line.materialId);
    final int? unitId = state.unitOf(line);
    final String unit = overview.unitLabel(line.materialId, unitId);
    final Qty due = material?.fromBase(line.remaining, unitId) ?? line.remaining;
    final String ordered = '${formatQty(line.ordered)} ${overview.unitLabel(line.materialId, line.unitId)}'.trim();
    final LineProblem? problem = state.lineProblems[line.id];
    final String? error = switch (problem?.kind) {
      LineProblemKind.invalid => l.procurementQtyInvalid,
      LineProblemKind.tooManyDecimals => l.procurementTooManyDecimals,
      LineProblemKind.overDue => l.procurementOverDue('${formatQty(problem!.due ?? due)} $unit'.trim()),
      LineProblemKind.rejectedOnly => l.procurementRejectedOnly,
      null => null,
    };
    final List<int> units = material?.unitIds() ?? const <int>[];
    final String name = overview.materialName(line.materialId);
    final String figures = '${l.procurementOrdered(ordered)} · ${l.procurementStillDue('${formatQty(due)} $unit'.trim())}';

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(name, style: N360Type.fit(N360Type.heading, name)),
          const SizedBox(height: N360Space.xs),
          Text(figures, style: N360Type.fit(N360Type.caption, figures)),
          if (units.length > 1) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Wrap(
              spacing: N360Space.sm,
              children: <Widget>[
                for (final int id in units)
                  IntrinsicWidth(
                    child: N360Chip(
                      key: ValueKey<String>('receive-unit-${line.id}-$id'),
                      label: overview.units[id] ?? '',
                      selected: (unitId ?? material?.baseUnitId) == id,
                      onTap: () => cubit.setUnit(line.id, id),
                    ),
                  ),
              ],
            ),
          ],
          const SizedBox(height: N360Space.md),
          GloveTextField(
            key: ValueKey<String>('receive-accepted-${line.id}'),
            label: unit.isEmpty ? l.procurementAccepted : '${l.procurementAccepted} ($unit)',
            controller: accepted,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp(r'[0-9.,०-९]'))],
            errorText: error,
            onChanged: (_) => onChanged(),
          ),
          const SizedBox(height: N360Space.md),
          GloveTextField(
            key: ValueKey<String>('receive-rejected-${line.id}'),
            label: unit.isEmpty ? l.procurementRejected : '${l.procurementRejected} ($unit)',
            helper: l.procurementRejectedHint,
            controller: rejected,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp(r'[0-9.,०-९]'))],
            onChanged: (_) => onChanged(),
          ),
        ],
      ),
    );
  }
}
