import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart' show ImageSource;

import '../../../app/app_format.dart';
import '../../../app/app_gate.dart';
import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/bloc/measurement_book.dart' show formatQty;
import '../../measurement/view/widgets/book_kit.dart';
import '../bloc/material_loop_cubits.dart';
import '../data/material_loop_repository.dart';
import '../data/material_models.dart' show MaterialFlag;

// The online half of the Material page: answering a flag, one challan with its
// bill and shorts, the bills list, and wastage against the BOQ norms.

String _isoDay(DateTime day) {
  String two(int value) => value.toString().padLeft(2, '0');
  return '${day.year}-${two(day.month)}-${two(day.day)}';
}

String _dayBefore(String today) => _isoDay(DateTime.parse(today).subtract(const Duration(days: 1)));

/// What to say when a send did not go through. The server's words win.
String _problemText(
  AppLocalizations l,
  LoopProblem problem,
  String? message, {
  required String invalid,
  required String empty,
  required String tooLong,
}) {
  if (message != null && message.trim().isNotEmpty) return message;
  return switch (problem) {
    LoopProblem.empty => empty,
    LoopProblem.tooLong => tooLong,
    LoopProblem.invalid => invalid,
    LoopProblem.offline => l.materialNeedsNetwork,
    LoopProblem.refused => l.materialNotAllowed,
    LoopProblem.failed => l.materialSendFailed,
  };
}

Widget _figure(String text) => Text(text, style: N360Type.figureSmall);

Widget _heading(String text) => Padding(
  padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.xs),
  child: Text(text, style: N360Type.fit(N360Type.label, text)),
);

/// Rows on one white card, ruled between, as the Material page lists them.
class _Group extends StatelessWidget {
  const _Group({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
      child: LedgerCard(
        padding: EdgeInsets.zero,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            for (final (int index, Widget child) in children.indexed) ...<Widget>[
              if (index > 0) const RuledDivider(indent: N360Space.card),
              child,
            ],
          ],
        ),
      ),
    );
  }
}

/// A file from the server. A photo shows here; anything else says where to find it.
class _FileSheet extends StatelessWidget {
  const _FileSheet({required this.load, required this.failed, required this.notPicture, required this.loading});

  final Future<Uint8List> load;
  final String failed;
  final String notPicture;
  final String loading;

  @override
  Widget build(BuildContext context) {
    Widget message(String text) => Padding(
      padding: const EdgeInsets.all(N360Space.gutter),
      child: Text(text, style: N360Type.fit(N360Type.body, text)),
    );
    return SafeArea(
      top: false,
      child: FutureBuilder<Uint8List>(
        future: load,
        builder: (context, snapshot) {
          if (snapshot.hasError) return message(failed);
          final Uint8List? bytes = snapshot.data;
          if (bytes == null) {
            return Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(semanticsLabel: loading),
            );
          }
          return ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.lg),
              child: Image.memory(bytes, fit: BoxFit.contain, errorBuilder: (_, _, _) => message(notPicture)),
            ),
          );
        },
      ),
    );
  }
}

/// Today, yesterday, or a day picked from the calendar, never after today.
class _DayChips extends StatelessWidget {
  const _DayChips({required this.value, required this.today, required this.onChanged, this.optional = false});

  final String? value;
  final String today;
  final ValueChanged<String?> onChanged;

  /// A chosen day can be tapped again to clear it.
  final bool optional;

  Future<void> _pick(BuildContext context) async {
    final DateTime last = DateTime.parse(today);
    final DateTime initial = DateTime.tryParse(value ?? '') ?? last;
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isAfter(last) ? last : initial,
      firstDate: DateTime(last.year - 2),
      lastDate: last,
    );
    if (picked != null) onChanged(_isoDay(picked));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String yesterday = _dayBefore(today);
    final bool other = value != null && value != today && value != yesterday;
    void choose(String day) => onChanged(optional && value == day ? null : day);
    return Wrap(
      spacing: N360Space.sm,
      runSpacing: N360Space.sm,
      children: <Widget>[
        IntrinsicWidth(
          child: N360Chip(label: l.progressFormToday, selected: value == today, onTap: () => choose(today)),
        ),
        IntrinsicWidth(
          child: N360Chip(label: l.progressFormYesterday, selected: value == yesterday, onTap: () => choose(yesterday)),
        ),
        IntrinsicWidth(
          child: N360Chip(
            label: other ? dayLabel(context, value!, today) : l.materialDate,
            selected: other,
            onTap: () => _pick(context),
          ),
        ),
      ],
    );
  }
}

// A reason on a flag -----------------------------------------------------------

/// Opens the reason box for [flag]. True when a reason was stored.
Future<bool> showFlagReasonSheet(
  BuildContext context, {
  required int projectId,
  required int materialId,
  required String materialName,
  required MaterialFlag flag,
}) async {
  final AppServices services = context.read<AppServices>();
  final bool? saved = await showLedgerSheet<bool>(
    context,
    isScrollControlled: true,
    builder: (sheet) => BlocProvider<FlagReasonCubit>(
      create: (_) => FlagReasonCubit(services, projectId: projectId, materialId: materialId, flagType: flag.type),
      child: _FlagReasonSheet(materialName: materialName, flag: flag),
    ),
  );
  return saved ?? false;
}

class _FlagReasonSheet extends StatefulWidget {
  const _FlagReasonSheet({required this.materialName, required this.flag});

  final String materialName;
  final MaterialFlag flag;

  @override
  State<_FlagReasonSheet> createState() => _FlagReasonSheetState();
}

class _FlagReasonSheetState extends State<_FlagReasonSheet> {
  final TextEditingController _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool saved = await context.read<FlagReasonCubit>().send(_reason.text);
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      return;
    }
    N360Haptics.success();
    showPaperToast(context, l.materialReasonSaved, kind: PaperToastKind.success);
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
        child: BlocBuilder<FlagReasonCubit, FlagReasonState>(
          builder: (context, state) {
            final LoopProblem? problem = state.problem;
            final String? error = problem == null
                ? null
                : _problemText(
                    l,
                    problem,
                    state.message,
                    invalid: l.materialSendFailed,
                    empty: l.materialReasonEmpty,
                    tooLong: l.materialReasonTooLong,
                  );
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(widget.materialName, style: N360Type.fit(N360Type.heading, widget.materialName)),
                const SizedBox(height: N360Space.xs),
                Text(
                  widget.flag.message,
                  style: N360Type.fit(N360Type.body, widget.flag.message).copyWith(color: N360Colors.laterite),
                ),
                const SizedBox(height: N360Space.md),
                GloveTextField(
                  key: const ValueKey<String>('flag-reason-text'),
                  label: l.materialReasonLabel,
                  helper: l.materialReasonHint,
                  controller: _reason,
                  minLines: 3,
                  maxLines: 6,
                  errorText: error,
                ),
                const SizedBox(height: N360Space.md),
                GloveButton(
                  key: const ValueKey<String>('flag-reason-save'),
                  label: l.materialReasonSend,
                  icon: Icons.check,
                  large: true,
                  fullWidth: true,
                  loading: state.sending,
                  onPressed: state.sending ? null : _save,
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

// One challan ------------------------------------------------------------------

/// A delivery challan in full: who brought what, its photo, its bill, and any
/// shortage found on it. Read from the server each time.
class ChallanScreen extends StatelessWidget {
  const ChallanScreen({super.key, required this.challanId});

  final int? challanId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ChallanCubit>(
      create: (context) => ChallanCubit(context.read<AppServices>(), challanId: challanId ?? 0)..load(),
      child: const _ChallanView(),
    );
  }
}

class _ChallanView extends StatelessWidget {
  const _ChallanView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<ChallanCubit, ChallanState>(
            builder: (context, state) {
              final ChallanDetail? detail = state.detail;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.materialChallanTitle, subtitle: detail?.number),
                  Expanded(
                    child: LedgerRefresh(
                      onRefresh: () => context.read<ChallanCubit>().load(),
                      semanticsLabel: l.materialLoading,
                      child: detail == null ? _waiting(l, state) : _ChallanBody(detail: detail),
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

  Widget _waiting(AppLocalizations l, ChallanState state) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: <Widget>[
        switch (state.load) {
          ChallanLoad.loading || ChallanLoad.ready => Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: LedgerSkeletonList(lines: 4, semanticsLabel: l.materialLoading),
          ),
          ChallanLoad.offline => EmptyLedger(title: l.materialChallanTitle, message: l.materialChallanOffline),
          ChallanLoad.refused => EmptyLedger(title: l.materialChallanTitle, message: l.materialChallanRefused),
          ChallanLoad.failed => EmptyLedger(title: l.materialChallanTitle, message: l.materialChallanLoadFailed),
        },
      ],
    );
  }
}

class _ChallanBody extends StatelessWidget {
  const _ChallanBody({required this.detail});

  final ChallanDetail detail;

  String _qty(Qty qty) => '${formatQty(qty)} ${detail.unit ?? ''}'.trim();

  void _viewPhoto(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ChallanCubit cubit = context.read<ChallanCubit>();
    showLedgerSheet<void>(
      context,
      isScrollControlled: true,
      builder: (_) => _FileSheet(
        load: cubit.repository.challanPhoto(detail.id),
        failed: l.materialPhotoFailed,
        notPicture: l.materialPhotoFailed,
        loading: l.materialLoading,
      ),
    );
  }

  void _viewBill(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ChallanCubit cubit = context.read<ChallanCubit>();
    showLedgerSheet<void>(
      context,
      isScrollControlled: true,
      builder: (_) => _FileSheet(
        load: cubit.repository.billFile(detail.id),
        failed: l.materialBillFailed,
        notPicture: l.materialBillNotPicture,
        loading: l.materialLoading,
      ),
    );
  }

  Future<void> _attachBill(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final ChallanCubit cubit = context.read<ChallanCubit>()..clearProblem();
    final bool seesMoney = context.read<AppGate>().state.can('money.view');
    final bool? saved = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (_) => BlocProvider<ChallanCubit>.value(
        value: cubit,
        child: _BillForm(seesMoney: seesMoney, replaces: !detail.billPending),
      ),
    );
    if (saved == true && context.mounted) showPaperToast(context, l.materialBillSaved, kind: PaperToastKind.success);
  }

  Future<void> _reportShort(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final ChallanCubit cubit = context.read<ChallanCubit>()..clearProblem();
    final bool? saved = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (_) => BlocProvider<ChallanCubit>.value(
        value: cubit,
        child: _ShortForm(unit: detail.unit ?? ''),
      ),
    );
    if (saved == true && context.mounted) showPaperToast(context, l.materialShortSaved, kind: PaperToastKind.success);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final GateState gate = context.select((AppGate gate) => gate.state);
    final String today = context.read<ChallanCubit>().today;
    final ChallanBill? bill = detail.bill;
    final bool canBills = gate.can('material_control.bills') && !detail.reversed;
    final bool canShort = gate.can('material_control.challan') && !detail.reversed;
    final String? warning = detail.supplierWarning;
    const EdgeInsets cardPadding = EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, 0);

    // A column, not a lazy list: the page is short, and every part of it should exist at once.
    return SingleChildScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.only(bottom: N360Space.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (warning != null)
            Padding(
              padding: cardPadding,
              child: Text(
                '${detail.supplier ?? ''}: $warning',
                key: const ValueKey<String>('challan-supplier-warning'),
                style: N360Type.fit(N360Type.bodyStrong, warning).copyWith(color: N360Colors.laterite),
              ),
            ),
          Padding(
            padding: cardPadding,
            child: LedgerCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Wrap(
                    spacing: N360Space.sm,
                    runSpacing: N360Space.xs,
                    children: <Widget>[
                      if (detail.reversed)
                        StatusPill(kind: StampKind.rejected, label: l.procurementReversed)
                      else if (detail.billPending)
                        StatusPill(kind: StampKind.inReview, label: l.materialBillPending)
                      else
                        StatusPill(kind: StampKind.approved, label: l.materialBillAttached),
                    ],
                  ),
                  const SizedBox(height: N360Space.sm),
                  if (detail.supplier != null) BookRow.words(label: l.materialSupplier, value: detail.supplier!),
                  if (detail.material != null) BookRow.words(label: l.materialName, value: detail.material!),
                  BookRow(label: l.materialReceivedShort, value: _qty(detail.qty)),
                  if (detail.challanQty != null) BookRow(label: l.materialOnChallan, value: _qty(detail.challanQty!)),
                  if (detail.date != null) BookRow.words(label: l.materialDate, value: dayLabel(context, detail.date!, today)),
                  if (detail.challanNo != null) BookRow.words(label: l.materialChallanNoShort, value: detail.challanNo!),
                  if (detail.vehicleNo != null) BookRow.words(label: l.materialVehicle, value: detail.vehicleNo!),
                  if (detail.store != null) BookRow.words(label: l.materialStore, value: detail.store!),
                  if (detail.createdBy != null) BookRow.words(label: l.materialEnteredBy, value: detail.createdBy!),
                  if (detail.remarks != null) BookRow.words(label: l.materialRemarks, value: detail.remarks!, ruled: false),
                  if (detail.reversed && detail.reversedAt != null) ...<Widget>[
                    const SizedBox(height: N360Space.sm),
                    Text(
                      l.materialReversedNote(
                        detail.reversedBy ?? '',
                        dayLabel(context, detail.reversedAt!, today),
                        detail.reversalReason ?? '',
                      ),
                      style: N360Type.fit(N360Type.body, l.materialChallanTitle).copyWith(color: N360Colors.laterite),
                    ),
                  ],
                  const SizedBox(height: N360Space.md),
                  if (detail.hasPhoto)
                    GloveButton(
                      key: const ValueKey<String>('challan-view-photo'),
                      label: l.materialViewPhoto,
                      icon: Icons.photo_outlined,
                      kind: GloveButtonKind.secondary,
                      fullWidth: true,
                      onPressed: () => _viewPhoto(context),
                    )
                  else
                    Text(l.materialNoPhoto, style: N360Type.fit(N360Type.caption, l.materialNoPhoto)),
                ],
              ),
            ),
          ),
          if (!detail.reversed)
            Padding(
              padding: cardPadding,
              child: LedgerCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(l.materialBillHeading, style: N360Type.fit(N360Type.label, l.materialBillHeading)),
                    const SizedBox(height: N360Space.xs),
                    if (detail.billPending)
                      Text(l.materialBillWaiting, style: N360Type.fit(N360Type.body, l.materialBillWaiting))
                    else if (detail.billAttachedBy != null)
                      Text(
                        l.materialBillAttachedBy(detail.billAttachedBy!),
                        style: N360Type.fit(N360Type.body, l.materialBillHeading),
                      )
                    else
                      Text(l.materialBillAttached, style: N360Type.fit(N360Type.body, l.materialBillAttached)),
                    // Sent only to money.view, so nobody else has anything to draw here.
                    if (bill != null) ...<Widget>[
                      if (bill.number != null) BookRow.words(label: l.materialBillNo, value: bill.number!),
                      if (bill.date != null)
                        BookRow.words(label: l.materialBillDate, value: dayLabel(context, bill.date!, today)),
                      if (bill.amount != null)
                        BookRow(label: l.materialBillAmount, total: true, value: AppFormat.rupees(bill.amount!)),
                      if (bill.hasFile) ...<Widget>[
                        const SizedBox(height: N360Space.sm),
                        GloveButton(
                          key: const ValueKey<String>('challan-view-bill'),
                          label: l.materialBillView,
                          icon: Icons.receipt_long_outlined,
                          kind: GloveButtonKind.secondary,
                          fullWidth: true,
                          onPressed: () => _viewBill(context),
                        ),
                      ],
                    ],
                    if (canBills) ...<Widget>[
                      const SizedBox(height: N360Space.sm),
                      GloveButton(
                        key: const ValueKey<String>('challan-attach-bill'),
                        label: detail.billPending ? l.materialBillAttach : l.materialBillReplace,
                        icon: Icons.add_a_photo_outlined,
                        kind: detail.billPending ? GloveButtonKind.primary : GloveButtonKind.ghost,
                        fullWidth: true,
                        onPressed: () => _attachBill(context),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          if (detail.shorts.isNotEmpty) ...<Widget>[
            _heading(l.materialShortHeading),
            _Group(
              children: <Widget>[
                for (final ChallanShort short in detail.shorts)
                  LedgerListTile(
                    key: ValueKey<String>('challan-short-${short.id}'),
                    title: _qty(short.qty),
                    subtitle: <String>[
                      ?(short.foundOn == null ? null : dayLabel(context, short.foundOn!, today)),
                      short.atEntry ? l.materialShortAtGate : l.materialShortLater,
                      ?short.createdBy,
                      ?short.note,
                    ].join(' · '),
                    status: short.reversed ? StatusPill(kind: StampKind.rejected, label: l.procurementReversed) : null,
                  ),
              ],
            ),
          ],
          if (canShort)
            Padding(
              padding: cardPadding,
              child: GloveButton(
                key: const ValueKey<String>('challan-report-short'),
                label: l.materialShortAsk,
                icon: Icons.report_gmailerrorred_outlined,
                kind: GloveButtonKind.secondary,
                fullWidth: true,
                onPressed: () => _reportShort(context),
              ),
            ),
        ],
      ),
    );
  }
}

/// The supplier's bill: a photo, and for `money.view` its number, date and amount.
class _BillForm extends StatefulWidget {
  const _BillForm({required this.seesMoney, required this.replaces});

  final bool seesMoney;
  final bool replaces;

  @override
  State<_BillForm> createState() => _BillFormState();
}

class _BillFormState extends State<_BillForm> {
  final TextEditingController _number = TextEditingController();
  final TextEditingController _amount = TextEditingController();
  String? _photo;
  String? _date;

  @override
  void dispose() {
    _number.dispose();
    _amount.dispose();
    super.dispose();
  }

  Future<void> _take(ImageSource source) async {
    final String? path = await context.read<AppServices>().photos.capture(source: source);
    if (path == null || !mounted) return;
    context.read<ChallanCubit>().clearProblem();
    setState(() => _photo = path);
  }

  Future<void> _save() async {
    final bool saved = await context.read<ChallanCubit>().attachBill(
      photoPath: _photo,
      number: widget.seesMoney ? _number.text : null,
      date: widget.seesMoney ? _date : null,
      amountText: widget.seesMoney ? _amount.text : null,
    );
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      return;
    }
    N360Haptics.success();
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<ChallanCubit>().today;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
        child: BlocBuilder<ChallanCubit, ChallanState>(
          builder: (context, state) {
            final bool sending = state.sending == ChallanSend.bill;
            final LoopProblem? problem = state.problem;
            final bool amountWrong = problem == LoopProblem.invalid && state.message == null;
            final String? error = problem == null || amountWrong
                ? null
                : _problemText(
                    l,
                    problem,
                    state.message,
                    invalid: l.materialSendFailed,
                    empty: widget.seesMoney ? l.materialBillNeededOrNumber : l.materialBillNeeded,
                    tooLong: l.materialSendFailed,
                  );
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(l.materialBillAttach, style: N360Type.fit(N360Type.heading, l.materialBillAttach)),
                if (widget.replaces) ...<Widget>[
                  const SizedBox(height: N360Space.xs),
                  Text(l.materialBillReplaceNote, style: N360Type.fit(N360Type.caption, l.materialBillReplaceNote)),
                ],
                const SizedBox(height: N360Space.md),
                FieldBlock(
                  label: l.materialBillPhoto,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (_photo != null) ...<Widget>[
                        Row(
                          children: <Widget>[
                            ClipRRect(
                              borderRadius: N360Radius.md,
                              child: Image.file(
                                File(_photo!),
                                key: const ValueKey<String>('bill-form-photo'),
                                width: 72,
                                height: 72,
                                fit: BoxFit.cover,
                                errorBuilder: (context, error, stack) => const SizedBox(
                                  width: 72,
                                  height: 72,
                                  child: Icon(Icons.receipt_long_outlined, color: N360Colors.ink2),
                                ),
                              ),
                            ),
                            IconButton(
                              tooltip: l.materialBillRemovePhoto,
                              onPressed: sending ? null : () => setState(() => _photo = null),
                              icon: const Icon(Icons.close, size: N360IconSize.md, color: N360Colors.ink2),
                            ),
                          ],
                        ),
                        const SizedBox(height: N360Space.sm),
                      ],
                      Row(
                        children: <Widget>[
                          Expanded(
                            child: GloveButton(
                              key: const ValueKey<String>('bill-form-camera'),
                              label: l.materialBillTakePhoto,
                              icon: Icons.photo_camera_outlined,
                              kind: GloveButtonKind.secondary,
                              fullWidth: true,
                              onPressed: sending ? null : () => _take(ImageSource.camera),
                            ),
                          ),
                          const SizedBox(width: N360Space.sm),
                          Expanded(
                            child: GloveButton(
                              key: const ValueKey<String>('bill-form-gallery'),
                              label: l.materialBillFromGallery,
                              icon: Icons.photo_library_outlined,
                              kind: GloveButtonKind.ghost,
                              fullWidth: true,
                              onPressed: sending ? null : () => _take(ImageSource.gallery),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                // The server reads these only from money.view, so nobody else is asked for them.
                if (widget.seesMoney) ...<Widget>[
                  const SizedBox(height: N360Space.md),
                  GloveTextField(
                    key: const ValueKey<String>('bill-form-number'),
                    label: l.materialBillNo,
                    helper: l.materialOptional,
                    controller: _number,
                    textCapitalization: TextCapitalization.characters,
                    onChanged: (_) => context.read<ChallanCubit>().clearProblem(),
                  ),
                  const SizedBox(height: N360Space.md),
                  GloveTextField(
                    key: const ValueKey<String>('bill-form-amount'),
                    label: l.materialBillAmount,
                    helper: l.materialOptional,
                    controller: _amount,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    errorText: amountWrong ? l.materialBillAmountInvalid : null,
                    onChanged: (_) => context.read<ChallanCubit>().clearProblem(),
                  ),
                  const SizedBox(height: N360Space.md),
                  FieldBlock(
                    label: l.materialBillDate,
                    child: _DayChips(value: _date, today: today, optional: true, onChanged: (day) => setState(() => _date = day)),
                  ),
                ],
                if (error != null) ...<Widget>[
                  const SizedBox(height: N360Space.md),
                  ErrorSlip(key: const ValueKey<String>('bill-form-error'), title: error),
                ],
                const SizedBox(height: N360Space.md),
                GloveButton(
                  key: const ValueKey<String>('bill-form-save'),
                  label: l.materialBillSend,
                  icon: Icons.check,
                  large: true,
                  fullWidth: true,
                  loading: sending,
                  onPressed: sending ? null : _save,
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// Less arrived than the challan said, found after it was taken into stock.
class _ShortForm extends StatefulWidget {
  const _ShortForm({required this.unit});

  final String unit;

  @override
  State<_ShortForm> createState() => _ShortFormState();
}

class _ShortFormState extends State<_ShortForm> {
  final TextEditingController _qty = TextEditingController();
  final TextEditingController _note = TextEditingController();
  late String _foundOn = context.read<ChallanCubit>().today;

  @override
  void dispose() {
    _qty.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final bool saved = await context.read<ChallanCubit>().reportShort(qtyText: _qty.text, foundOn: _foundOn, note: _note.text);
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      return;
    }
    N360Haptics.success();
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<ChallanCubit>().today;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
        child: BlocBuilder<ChallanCubit, ChallanState>(
          builder: (context, state) {
            final bool sending = state.sending == ChallanSend.short;
            final LoopProblem? problem = state.problem;
            // Typed wrong, or refused by the server against the quantity: both belong under the field.
            final bool qtyWrong = problem == LoopProblem.invalid;
            final String? error = problem == null || qtyWrong
                ? null
                : _problemText(
                    l,
                    problem,
                    state.message,
                    invalid: l.materialShortQtyInvalid,
                    empty: l.materialShortQtyInvalid,
                    tooLong: l.materialShortNoteTooLong,
                  );
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(l.materialShortAsk, style: N360Type.fit(N360Type.heading, l.materialShortAsk)),
                const SizedBox(height: N360Space.xs),
                Text(l.materialShortHint, style: N360Type.fit(N360Type.caption, l.materialShortHint)),
                const SizedBox(height: N360Space.md),
                GloveTextField(
                  key: const ValueKey<String>('short-form-qty'),
                  label: l.materialShortBy(widget.unit),
                  controller: _qty,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  errorText: qtyWrong ? (state.message ?? l.materialShortQtyInvalid) : null,
                  onChanged: (_) => context.read<ChallanCubit>().clearProblem(),
                ),
                const SizedBox(height: N360Space.md),
                FieldBlock(
                  label: l.materialShortFoundOn,
                  child: _DayChips(value: _foundOn, today: today, onChanged: (day) => setState(() => _foundOn = day ?? today)),
                ),
                const SizedBox(height: N360Space.md),
                GloveTextField(
                  key: const ValueKey<String>('short-form-note'),
                  label: l.materialShortNote,
                  helper: l.materialOptional,
                  controller: _note,
                  maxLines: 2,
                ),
                if (error != null) ...<Widget>[
                  const SizedBox(height: N360Space.md),
                  ErrorSlip(key: const ValueKey<String>('short-form-error'), title: error),
                ],
                const SizedBox(height: N360Space.md),
                GloveButton(
                  key: const ValueKey<String>('short-form-save'),
                  label: l.materialShortSend,
                  icon: Icons.check,
                  kind: GloveButtonKind.danger,
                  large: true,
                  fullWidth: true,
                  loading: sending,
                  onPressed: sending ? null : _save,
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

// Bills waiting ----------------------------------------------------------------

/// Challans taken on site whose bill has not come, for whoever chases them.
class BillsScreen extends StatelessWidget {
  const BillsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<BillsCubit>(
      create: (context) => BillsCubit(context.read<AppServices>())..load(),
      child: const _BillsView(),
    );
  }
}

class _BillsView extends StatelessWidget {
  const _BillsView();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<BillsCubit>().load();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  Future<void> _open(BuildContext context, BillRow row) async {
    final BillsCubit cubit = context.read<BillsCubit>();
    await context.push(Routes.materialChallanAt(row.id));
    // A bill attached there takes the challan off this list.
    if (!cubit.isClosed) await cubit.load();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<BillsCubit, BillsState>(
            builder: (context, state) {
              final BillsCubit cubit = context.read<BillsCubit>();
              final String today = context.read<AppServices>().context.today;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.materialBillsTitle),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.sm),
                    child: Row(
                      children: <Widget>[
                        N360Chip(
                          key: const ValueKey<String>('bills-tab-pending'),
                          label: l.materialBillPending,
                          selected: !state.attached,
                          onTap: () => cubit.showAttached(false),
                        ),
                        const SizedBox(width: N360Space.sm),
                        N360Chip(
                          key: const ValueKey<String>('bills-tab-attached'),
                          label: l.materialBillAttached,
                          selected: state.attached,
                          onTap: () => cubit.showAttached(true),
                        ),
                      ],
                    ),
                  ),
                  Expanded(
                    child: LedgerRefresh(
                      onRefresh: () => _refresh(context),
                      semanticsLabel: l.materialLoading,
                      child: ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.only(bottom: N360Space.xl),
                        children: _rows(context, l, state, today),
                      ),
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

  List<Widget> _rows(BuildContext context, AppLocalizations l, BillsState state, String today) {
    switch (state.load) {
      case ListLoad.loading:
        return <Widget>[
          Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: LedgerSkeletonList(lines: 3, semanticsLabel: l.materialLoading),
          ),
        ];
      case ListLoad.noProject:
        return <Widget>[EmptyLedger(title: l.progressNoProjectTitle, message: l.progressNoProjectMessage)];
      case ListLoad.offline:
        return <Widget>[EmptyLedger(title: l.materialBillsTitle, message: l.materialBillsOffline)];
      case ListLoad.refused:
        return <Widget>[EmptyLedger(title: l.materialBillsTitle, message: l.materialBillsRefused)];
      case ListLoad.failed:
        return <Widget>[EmptyLedger(title: l.materialBillsTitle, message: l.materialBillsLoadFailed)];
      case ListLoad.ready:
        break;
    }
    final BillsCubit cubit = context.read<BillsCubit>();
    final int? pending = state.pendingCount;
    final String note = l.materialBillsAlertNote(state.alertAfterDays);
    return <Widget>[
      // The count belongs to the waiting list; over the attached ones it would read as theirs.
      if (pending != null && !state.attached)
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                l.materialBillsWaitingCount(pending),
                key: const ValueKey<String>('bills-pending-count'),
                style: N360Type.fit(N360Type.bodyStrong, l.materialBillsTitle),
              ),
              Text(note, style: N360Type.fit(N360Type.caption, note)),
            ],
          ),
        ),
      if (state.rows.isEmpty)
        EmptyLedger(
          title: state.attached ? l.materialBillsNoneAttached : l.materialBillsNonePending,
          message: l.materialBillsNoneMessage,
        ),
      if (state.rows.isNotEmpty)
        _Group(
          children: <Widget>[
            for (final BillRow row in state.rows)
              LedgerListTile(
                key: ValueKey<String>('bill-row-${row.id}'),
                title: row.supplier ?? row.number ?? '',
                subtitle: <String>[
                  ?row.material,
                  ?row.challanNo ?? row.number,
                  ?(row.date == null ? null : dayLabel(context, row.date!, today)),
                ].join(' · '),
                status: row.overdue ? StatusPill(kind: StampKind.returned, label: l.materialBillsOverdue) : null,
                trailing: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: <Widget>[
                    _figure('${formatQty(row.qty)} ${row.unit ?? ''}'.trim()),
                    if (row.billPending && row.daysWaiting != null)
                      Text(
                        l.materialBillsDays(row.daysWaiting!),
                        style: N360Type.fit(
                          N360Type.caption,
                          l.materialBillsTitle,
                        ).copyWith(color: row.overdue ? N360Colors.laterite : N360Colors.ink2),
                      ),
                    // Sent only to money.view.
                    if (row.amount != null) Text(AppFormat.rupees(row.amount!), style: N360Type.caption),
                  ],
                ),
                onTap: () => _open(context, row),
              ),
          ],
        ),
      if (state.moreFailed)
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
          child: Text(l.materialMoreFailed, style: N360Type.fit(N360Type.caption, l.materialMoreFailed)),
        ),
      if (state.hasMore)
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, 0),
          child: GloveButton(
            key: const ValueKey<String>('bills-more'),
            label: l.materialShowMore,
            kind: GloveButtonKind.ghost,
            fullWidth: true,
            loading: state.loadingMore,
            onPressed: state.loadingMore ? null : cubit.more,
          ),
        ),
    ];
  }
}

// Wastage against the BOQ norms ------------------------------------------------

/// Material used on each BOQ line against the work done times its norm, with
/// the wastage the norm allows. Read only: norms are entered on the website.
class WastageScreen extends StatelessWidget {
  const WastageScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<WastageCubit>(
      create: (context) => WastageCubit(context.read<AppServices>())..load(),
      child: const _WastageView(),
    );
  }
}

String _statusWord(AppLocalizations l, WastageStatus status) => switch (status) {
  WastageStatus.wastage => l.materialStatusWastage,
  WastageStatus.under => l.materialStatusUnder,
  WastageStatus.noWork => l.materialStatusNoWork,
  WastageStatus.noNorm => l.materialStatusNoNorm,
  WastageStatus.ok => l.materialStatusOk,
};

StampKind _statusKind(WastageStatus status) => switch (status) {
  WastageStatus.wastage => StampKind.rejected,
  WastageStatus.under => StampKind.inReview,
  WastageStatus.noWork => StampKind.returned,
  WastageStatus.noNorm => StampKind.draft,
  WastageStatus.ok => StampKind.approved,
};

String _basisWord(AppLocalizations l, WastageBasis basis) => switch (basis) {
  WastageBasis.measured => l.materialBasisMeasured,
  WastageBasis.approved => l.materialBasisApproved,
  WastageBasis.dpr => l.materialBasisDpr,
};

class _WastageView extends StatelessWidget {
  const _WastageView();

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<WastageCubit>().load();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<WastageCubit, WastageState>(
            builder: (context, state) {
              final WastageCubit cubit = context.read<WastageCubit>();
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.materialWastageTitle),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, 0),
                    child: Text(l.materialWastageBasis, style: N360Type.fit(N360Type.label, l.materialWastageBasis)),
                  ),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.sm),
                    child: Row(
                      children: <Widget>[
                        for (final (int index, WastageBasis basis) in WastageBasis.values.indexed) ...<Widget>[
                          if (index > 0) const SizedBox(width: N360Space.sm),
                          N360Chip(
                            key: ValueKey<String>('wastage-basis-${basis.wire}'),
                            label: _basisWord(l, basis),
                            selected: state.basis == basis,
                            onTap: () => cubit.load(basis: basis),
                          ),
                        ],
                      ],
                    ),
                  ),
                  Expanded(
                    child: LedgerRefresh(
                      onRefresh: () => _refresh(context),
                      semanticsLabel: l.materialLoading,
                      child: ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.only(bottom: N360Space.xl),
                        children: _rows(context, l, state),
                      ),
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

  List<Widget> _rows(BuildContext context, AppLocalizations l, WastageState state) {
    final WastageReport? report = state.report;
    if (report == null) {
      return <Widget>[
        switch (state.load) {
          ListLoad.loading || ListLoad.ready => Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: LedgerSkeletonList(lines: 3, semanticsLabel: l.materialLoading),
          ),
          ListLoad.noProject => EmptyLedger(title: l.progressNoProjectTitle, message: l.progressNoProjectMessage),
          ListLoad.offline => EmptyLedger(title: l.materialWastageTitle, message: l.materialWastageOffline),
          ListLoad.refused => EmptyLedger(title: l.materialWastageTitle, message: l.materialWastageRefused),
          ListLoad.failed => EmptyLedger(title: l.materialWastageTitle, message: l.materialWastageLoadFailed),
        },
      ];
    }
    final List<WastageRow> rows = report.ordered;
    final int over = report.count(WastageStatus.wastage);
    final String overText = l.materialWastageOverCount(over);
    return <Widget>[
      Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.sm),
        child: LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                overText,
                key: const ValueKey<String>('wastage-over-count'),
                style: N360Type.fit(
                  N360Type.bodyStrong,
                  overText,
                ).copyWith(color: over > 0 ? N360Colors.laterite : N360Colors.ink),
              ),
              const SizedBox(height: N360Space.xs),
              Text(l.materialWastageHint, style: N360Type.fit(N360Type.caption, l.materialWastageHint)),
            ],
          ),
        ),
      ),
      if (rows.isEmpty) EmptyLedger(title: l.materialWastageEmptyTitle, message: l.materialWastageEmptyMessage),
      if (rows.isNotEmpty) _Group(children: <Widget>[for (final WastageRow row in rows) _WastageTile(row: row)]),
      if (report.unplanned.isNotEmpty) ...<Widget>[
        _heading(l.materialUnplannedHeading),
        _Group(
          children: <Widget>[
            for (final UnplannedIssue issue in report.unplanned)
              LedgerListTile(
                title: issue.materialName ?? '',
                subtitle: issue.activity,
                trailing: _figure('${formatQty(issue.actual)} ${issue.unit ?? ''}'.trim()),
              ),
          ],
        ),
      ],
      if (report.belowReorder.isNotEmpty) ...<Widget>[
        _heading(l.materialReorderHeading),
        _Group(
          children: <Widget>[
            for (final ReorderGap gap in report.belowReorder)
              LedgerListTile(
                title: gap.materialName ?? '',
                subtitle: gap.store,
                trailing: _figure(
                  l.materialReorderLine(formatQty(gap.balance), formatQty(gap.reorderLevel), gap.unit ?? '').trim(),
                ),
              ),
          ],
        ),
      ],
    ];
  }
}

class _WastageTile extends StatelessWidget {
  const _WastageTile({required this.row});

  final WastageRow row;

  String _qty(Qty qty) => '${formatQty(qty)} ${row.unit ?? ''}'.trim();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Qty? beyond = row.beyondAllowed;
    final String line = <String>[?row.boqCode, ?row.boqDescription].join(' ');
    return LedgerListTile(
      key: ValueKey<String>('wastage-row-${row.boqItemId}-${row.materialId}'),
      title: row.materialName ?? '',
      subtitle: <String>[if (line.isNotEmpty) line, if (beyond != null) l.materialBeyondAllowed(_qty(beyond))].join(' · '),
      status: StatusPill(kind: _statusKind(row.status), label: _statusWord(l, row.status)),
      trailing: _figure(_qty(row.actual)),
      onTap: () => showLedgerSheet<void>(context, isScrollControlled: true, builder: (_) => _WastageSheet(row: row)),
    );
  }
}

/// One BOQ line and material, figure by figure.
class _WastageSheet extends StatelessWidget {
  const _WastageSheet({required this.row});

  final WastageRow row;

  String _qty(Qty qty) => '${formatQty(qty)} ${row.unit ?? ''}'.trim();

  String _trim(String number) => number.contains('.') ? number.replaceFirst(RegExp(r'\.?0+$'), '') : number;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String title = row.materialName ?? '';
    final String line = <String>[?row.boqCode, ?row.boqDescription].join(' ');
    final Qty? beyond = row.beyondAllowed;
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.lg),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(title, style: N360Type.fit(N360Type.heading, title)),
            if (line.isNotEmpty) Text(line, style: N360Type.fit(N360Type.body, line)),
            const SizedBox(height: N360Space.xs),
            Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(kind: _statusKind(row.status), label: _statusWord(l, row.status)),
            ),
            const SizedBox(height: N360Space.sm),
            BookRow(label: l.materialWorkDone, value: '${formatQty(row.executed)} ${row.boqUnit ?? ''}'.trim()),
            if (row.norm != null)
              BookRow.words(label: l.materialNorm, value: l.materialNormPer(_trim(row.norm!), row.unit ?? '', row.boqUnit ?? '')),
            if (row.allowedPercent != null) BookRow(label: l.materialAllowedWastage, value: '${_trim(row.allowedPercent!)}%'),
            if (row.theoretical != null) BookRow(label: l.materialAsPerNorm, value: _qty(row.theoretical!)),
            if (row.allowed != null) BookRow(label: l.materialAllowed, value: _qty(row.allowed!)),
            BookRow(label: l.materialActualUsed, total: true, value: _qty(row.actual)),
            if (row.variance != null)
              BookRow(
                label: l.materialAgainstNorm,
                value: <String>[_qty(row.variance!), if (row.variancePercent != null) '${row.variancePercent}%'].join(' · '),
                ruled: false,
              ),
            if (beyond != null) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Text(
                l.materialBeyondAllowed(_qty(beyond)),
                key: const ValueKey<String>('wastage-beyond'),
                style: N360Type.fit(N360Type.bodyStrong, l.materialWastageTitle).copyWith(color: N360Colors.laterite),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
