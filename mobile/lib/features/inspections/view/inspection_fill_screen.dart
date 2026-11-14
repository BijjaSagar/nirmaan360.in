import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../bloc/inspection_fill_cubit.dart';
import '../data/inspection_repository.dart';
import 'inspection_words.dart';

/// Marks a checklist item by item. Every mark is kept on the phone as it is made.
/// Finishing needs a result on every item, and a written reason on every failed
/// one, because that is what the server asks for; it is then queued as one piece.
class InspectionFillScreen extends StatelessWidget {
  const InspectionFillScreen({super.key, required this.uuid});

  final String uuid;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<InspectionFillCubit>(
      create: (context) => InspectionFillCubit(context.read<AppServices>(), uuid),
      child: const _FillView(),
    );
  }
}

class _FillView extends StatefulWidget {
  const _FillView();

  @override
  State<_FillView> createState() => _FillViewState();
}

class _FillViewState extends State<_FillView> {
  final TextEditingController _location = TextEditingController();
  final Map<int, TextEditingController> _remarks = <int, TextEditingController>{};
  final Map<int, GlobalKey> _itemKeys = <int, GlobalKey>{};
  bool _filled = false;

  @override
  void dispose() {
    _location.dispose();
    for (final TextEditingController controller in _remarks.values) {
      controller.dispose();
    }
    super.dispose();
  }

  TextEditingController _remarkFor(InspectionDraft draft, int position) {
    return _remarks.putIfAbsent(position, () => TextEditingController(text: draft.remarkAt(position)));
  }

  GlobalKey _keyFor(int position) => _itemKeys.putIfAbsent(position, GlobalKey.new);

  Future<void> _finish() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final InspectionFillCubit cubit = context.read<InspectionFillCubit>();
    final bool online = context.read<AppServices>().connectivity.isOnline;
    final bool saved = await cubit.finish();
    if (!mounted) return;
    if (!saved) {
      N360Haptics.warning();
      await _showFirstProblem(cubit.state.draft);
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

  /// Brings the first item that stops the inspection into view.
  Future<void> _showFirstProblem(InspectionDraft? draft) async {
    if (draft == null) return;
    final List<int> stuck = <int>[...draft.unmarked, ...draft.needRemark]..sort();
    if (stuck.isEmpty) return;
    await WidgetsBinding.instance.endOfFrame;
    final BuildContext? target = _keyFor(stuck.first).currentContext;
    if (target != null && target.mounted) {
      await Scrollable.ensureVisible(target, duration: Duration.zero, alignment: 0.1);
    }
  }

  Future<void> _discard() async {
    final AppLocalizations l = AppLocalizations.of(context);
    final InspectionFillCubit cubit = context.read<InspectionFillCubit>();
    final NavigatorState navigator = Navigator.of(context);
    final bool? yes = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        top: false,
        child: Padding(
          padding: ledgerSheetPadding,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(l.inspectionDiscardTitle, style: N360Type.fit(N360Type.title, l.inspectionDiscardTitle)),
              const SizedBox(height: N360Space.xs),
              Text(
                l.inspectionDiscardBody,
                style: N360Type.fit(N360Type.body, l.inspectionDiscardBody).copyWith(color: N360Colors.ink2),
              ),
              const SizedBox(height: N360Space.lg),
              GloveButton(
                key: const ValueKey<String>('inspection-discard-confirm'),
                label: l.inspectionDiscard,
                kind: GloveButtonKind.danger,
                fullWidth: true,
                icon: Icons.delete_outline,
                onPressed: () => Navigator.of(context).pop(true),
              ),
              const SizedBox(height: N360Space.sm),
              GloveButton(
                label: l.commonCancel,
                kind: GloveButtonKind.secondary,
                large: true,
                fullWidth: true,
                onPressed: () => Navigator.of(context).pop(false),
              ),
            ],
          ),
        ),
      ),
    );
    if (yes != true) return;
    await cubit.discard();
    navigator.pop();
  }

  Future<void> _pickOtherDate(FillState state) async {
    final InspectionFillCubit cubit = context.read<InspectionFillCubit>();
    final DateTime last = DateTime.parse(state.today);
    final DateTime initial = DateTime.tryParse(state.draft?.inspectedOn ?? '') ?? last;
    final DateTime? picked = await showDatePicker(
      context: context,
      useRootNavigator: true,
      initialDate: initial.isAfter(last) ? last : initial,
      firstDate: DateTime(last.year - 2),
      lastDate: last,
    );
    if (picked == null || !mounted) return;
    String two(int value) => value.toString().padLeft(2, '0');
    cubit.setDate('${picked.year}-${two(picked.month)}-${two(picked.day)}');
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
          child: BlocBuilder<InspectionFillCubit, FillState>(
            builder: (context, state) {
              final InspectionDraft? draft = state.draft;
              if (draft != null && !_filled) {
                _location.text = draft.location;
                _filled = true;
              }
              final bool gone = state.ready && draft == null;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(
                    title: draft?.templateName ?? l.inspectionsTitle,
                    subtitle: draft == null ? null : l.inspectionFillMarked(draft.markedCount, draft.items.length),
                    trailing: draft == null
                        ? null
                        : N360TopTile(
                            key: const ValueKey<String>('inspection-discard'),
                            icon: Icons.delete_outline,
                            label: l.inspectionDiscard,
                            onTap: _discard,
                          ),
                  ),
                  Expanded(
                    child: gone
                        ? EmptyLedger(title: l.inspectionGoneTitle, message: l.inspectionGoneMessage)
                        : draft == null
                        ? const SizedBox.shrink()
                        // Not a lazy list: all the items are built, so the first one that
                        // stops the inspection can be scrolled to wherever it is.
                        : SingleChildScrollView(
                            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                            padding: const EdgeInsets.fromLTRB(
                              N360Space.gutter,
                              N360Space.lg,
                              N360Space.gutter,
                              N360Space.sm,
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: _children(context, l, state, draft),
                            ),
                          ),
                  ),
                  if (state.problem != null)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, 0),
                      child: switch (state.problem!) {
                        FillProblem.saveFailed => ErrorSlip(title: l.progressSaveFailed),
                        FillProblem.unmarked => FieldInlineError(l.inspectionFillUnmarked(draft?.unmarked.length ?? 0)),
                        FillProblem.needRemark => FieldInlineError(
                          l.inspectionFillNeedRemark(draft?.needRemark.length ?? 0),
                        ),
                      },
                    ),
                  if (draft != null)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('inspection-finish'),
                        label: l.inspectionFinish,
                        icon: Icons.check,
                        large: true,
                        fullWidth: true,
                        loading: state.submitting,
                        onPressed: state.submitting ? null : _finish,
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

  /// A row of choices. A `Row` would overflow at 360dp in Hindi and Marathi.
  Widget _chips(List<Widget> chips) => Wrap(
    spacing: N360Space.sm,
    runSpacing: N360Space.sm,
    children: <Widget>[for (final Widget chip in chips) IntrinsicWidth(child: chip)],
  );

  List<Widget> _children(BuildContext context, AppLocalizations l, FillState state, InspectionDraft draft) {
    final InspectionFillCubit cubit = context.read<InspectionFillCubit>();
    final bool other = draft.inspectedOn != state.today && draft.inspectedOn != state.yesterday;
    return <Widget>[
      GloveTextField(
        key: const ValueKey<String>('inspection-location'),
        label: l.inspectionFillLocation,
        helper: l.inspectionFillLocationHint,
        controller: _location,
        inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(kInspectionLocationMax)],
        onChanged: cubit.setLocation,
      ),
      const SizedBox(height: N360Space.lg),
      FieldBlock(
        label: l.inspectionFillDate,
        child: _chips(<Widget>[
          N360Chip(
            key: const ValueKey<String>('inspection-date-today'),
            label: l.progressFormToday,
            selected: draft.inspectedOn == state.today,
            onTap: () => cubit.setDate(state.today),
          ),
          N360Chip(
            key: const ValueKey<String>('inspection-date-yesterday'),
            label: l.progressFormYesterday,
            selected: draft.inspectedOn == state.yesterday,
            onTap: () => cubit.setDate(state.yesterday),
          ),
          N360Chip(
            key: const ValueKey<String>('inspection-date-other'),
            label: other ? dayLabel(context, draft.inspectedOn, state.today) : l.contractorOtherDate,
            selected: other,
            onTap: () => _pickOtherDate(state),
          ),
        ]),
      ),
      const SizedBox(height: N360Space.lg),
      for (var position = 1; position <= draft.items.length; position++) ...<Widget>[
        _ItemCard(
          key: _keyFor(position),
          position: position,
          text: draft.items[position - 1],
          result: draft.resultAt(position),
          remark: _remarkFor(draft, position),
          checked: state.checked,
          onResult: (ItemResult result) => cubit.setResult(position, result),
          onRemark: (String text) => cubit.setRemark(position, text),
        ),
        const SizedBox(height: N360Space.sm),
      ],
      const SizedBox(height: N360Space.sm),
      Text(l.inspectionFillLockedNote, style: N360Type.fit(N360Type.caption, l.inspectionFillLockedNote)),
    ];
  }
}

/// One check point: its words, three choices that each say what they are, and
/// for a failure the reason the server insists on.
class _ItemCard extends StatelessWidget {
  const _ItemCard({
    super.key,
    required this.position,
    required this.text,
    required this.result,
    required this.remark,
    required this.checked,
    required this.onResult,
    required this.onRemark,
  });

  final int position;
  final String text;
  final ItemResult result;
  final TextEditingController remark;

  /// Finishing was tried, so what is missing is pointed out.
  final bool checked;
  final ValueChanged<ItemResult> onResult;
  final ValueChanged<String> onRemark;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool missing = checked && result == ItemResult.pending;
    final bool needsWords = checked && result == ItemResult.fail && remark.text.trim().isEmpty;

    Widget choice(ItemResult value) => IntrinsicWidth(
      child: N360Chip(
        key: ValueKey<String>('inspection-item-$position-${value.wire}'),
        label: l.inspectionResultWord(value),
        icon: itemResultIcon(value),
        selected: result == value,
        onTap: () => onResult(value),
      ),
    );

    return DecoratedBox(
      key: ValueKey<String>('inspection-item-$position'),
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.md,
        border: Border.all(color: missing || needsWords ? N360Colors.stampRed : N360Colors.rule),
      ),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                SizedBox(width: 30, child: Text('$position.', style: N360Type.figureSmall)),
                Expanded(child: Text(text, style: N360Type.fit(N360Type.bodyStrong, text))),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            Wrap(
              spacing: N360Space.sm,
              runSpacing: N360Space.sm,
              children: <Widget>[choice(ItemResult.pass), choice(ItemResult.fail), choice(ItemResult.na)],
            ),
            if (missing) ...<Widget>[
              const SizedBox(height: N360Space.xs),
              FieldInlineError(l.inspectionFillChoose),
            ],
            if (result == ItemResult.fail) ...<Widget>[
              const SizedBox(height: N360Space.md),
              GloveTextField(
                key: ValueKey<String>('inspection-item-$position-remark'),
                label: l.inspectionFillWrong,
                controller: remark,
                maxLines: 3,
                errorText: needsWords ? l.inspectionFillWrongRequired : null,
                inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(kInspectionRemarkMax)],
                onChanged: onRemark,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
