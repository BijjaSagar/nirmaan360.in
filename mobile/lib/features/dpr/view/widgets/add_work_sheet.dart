import 'dart:async';

import 'package:flutter/material.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart' show BoqLine;
import '../../../projects/view/boq_missing_prompt.dart';
import '../../data/dpr_repository.dart';
import 'dpr_format.dart';
import 'dpr_ui.dart';
import 'work_rows.dart' show DprBoqPercent;

/// Opens the work picker. Returns the row to put on the sheet, null if closed.
///
/// With [existing] it opens straight on the keypad to change that row.
Future<WorkLine?> showDprAddWorkSheet(
  BuildContext context, {
  required AppServices services,
  required int projectId,
  required Set<int> yesterdayIds,
  required Map<int, Qty> added,
  WorkLine? existing,
}) {
  return showLedgerSheet<WorkLine>(
    context,
    isScrollControlled: true,
    builder: (_) => DprAddWorkSheet(
      services: services,
      projectId: projectId,
      yesterdayIds: yesterdayIds,
      added: added,
      existing: existing,
    ),
  );
}

/// Two steps on one sheet: find the BOQ line, then enter the quantity on the
/// glove keypad. The system keyboard only ever appears for the search box.
class DprAddWorkSheet extends StatefulWidget {
  const DprAddWorkSheet({
    super.key,
    required this.services,
    required this.projectId,
    required this.yesterdayIds,
    required this.added,
    this.existing,
  });

  final AppServices services;
  final int projectId;

  /// Lines worked on yesterday float to the top: most days continue the same work.
  final Set<int> yesterdayIds;
  final Map<int, Qty> added;
  final WorkLine? existing;

  @override
  State<DprAddWorkSheet> createState() => _DprAddWorkSheetState();
}

class _DprAddWorkSheetState extends State<DprAddWorkSheet> {
  final TextEditingController _search = TextEditingController();
  Timer? _debounce;
  String _query = '';
  late Stream<List<BoqLine>> _lines = _watch('');

  int? _pickedId;
  String? _pickedText;
  String _typed = '';

  bool get _editing => widget.existing != null;

  @override
  void initState() {
    super.initState();
    final existing = widget.existing;
    if (existing != null) {
      _pickedId = existing.boqItemId;
      _pickedText = existing.description;
      _typed = dprKeypadText(existing.qty);
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Stream<List<BoqLine>> _watch(String query) =>
      widget.services.projects.watchBoqItems(widget.projectId, search: query);

  void _onSearch(String text) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 180), () {
      if (!mounted) return;
      setState(() {
        _query = text.trim();
        _lines = _watch(_query);
      });
    });
  }

  void _pick(BoqLine line) {
    setState(() {
      _pickedId = line.item.id;
      _pickedText = null;
      final Qty? already = widget.added[line.item.id];
      _typed = already == null ? '' : dprKeypadText(already);
    });
  }

  void _backToList() => setState(() {
    _pickedId = null;
    _pickedText = null;
    _typed = '';
  });

  void _finish() {
    final Qty? qty = Qty.tryParse(_typed);
    if (qty == null || qty.isZero || qty.isNegative) return;
    N360Haptics.success();
    Navigator.of(context).pop(
      WorkLine(
        boqItemId: _pickedId,
        description: _pickedId == null ? _pickedText : null,
        qty: qty,
      ),
    );
  }

  bool get _onKeypad => _pickedId != null || _pickedText != null;

  @override
  Widget build(BuildContext context) {
    final double keyboard = MediaQuery.viewInsetsOf(context).bottom;
    final Key stepKey = ValueKey<bool>(_onKeypad);

    final Widget step = _onKeypad
        ? _KeypadStep(
            key: stepKey,
            services: widget.services,
            boqItemId: _pickedId,
            fallbackText: _pickedText,
            typed: _typed,
            editing: _editing,
            onTyped: (value) => setState(() => _typed = value),
            onDone: _finish,
            onBack: _editing ? () => Navigator.of(context).pop() : _backToList,
          )
        : _PickStep(
            key: stepKey,
            search: _search,
            services: widget.services,
            query: _query,
            lines: _lines,
            onSearch: _onSearch,
            onPick: _pick,
            yesterdayIds: widget.yesterdayIds,
            added: widget.added,
          );

    // Below the theme's drag handle the sheet takes all the height it is given,
    // so the keypad sits at the foot, under the thumb, on any phone.
    return LayoutBuilder(
      builder: (context, box) => SizedBox(
        height: box.maxHeight,
        child: Padding(
          padding: EdgeInsets.only(bottom: keyboard),
          child: ClipRect(
            child: AnimatedSwitcher(
              duration: context.motion.rise,
              switchInCurve: N360Curves.rise,
              switchOutCurve: N360Curves.exit,
              transitionBuilder: (child, animation) {
                final bool incoming = child.key == stepKey;
                final tween = Tween<Offset>(
                  begin: Offset(incoming ? 1 : -1, 0),
                  end: Offset.zero,
                );
                return SlideTransition(position: animation.drive(tween), child: child);
              },
              child: step,
            ),
          ),
        ),
      ),
    );
  }
}

class _PickStep extends StatelessWidget {
  const _PickStep({
    super.key,
    required this.search,
    required this.services,
    required this.query,
    required this.lines,
    required this.onSearch,
    required this.onPick,
    required this.yesterdayIds,
    required this.added,
  });

  final TextEditingController search;
  final AppServices services;
  final String query;
  final Stream<List<BoqLine>> lines;
  final ValueChanged<String> onSearch;
  final ValueChanged<BoqLine> onPick;
  final Set<int> yesterdayIds;
  final Map<int, Qty> added;

  List<BoqLine> _ordered(List<BoqLine> found) {
    if (query.isNotEmpty || yesterdayIds.isEmpty) return found;
    return <BoqLine>[
      ...found.where((line) => yesterdayIds.contains(line.item.id)),
      ...found.where((line) => !yesterdayIds.contains(line.item.id)),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.xs, N360Space.lg, N360Space.sm),
          child: Text(l.dprAddWork, style: N360Type.title),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.lg),
          child: GloveTextField(
            controller: search,
            label: l.dprSearchBoq,
            hint: l.dprSearchHint,
            prefixIcon: Icons.search,
            onChanged: onSearch,
            textInputAction: TextInputAction.search,
          ),
        ),
        const SizedBox(height: N360Space.md),
        Expanded(
          child: StreamBuilder<List<BoqLine>>(
            stream: lines,
            builder: (context, snapshot) {
              final found = snapshot.data;
              if (found == null) return const SizedBox.shrink();
              if (found.isEmpty && query.isEmpty) {
                return BoqMissingPrompt(services: services);
              }
              if (found.isEmpty) {
                return Padding(
                  padding: const EdgeInsets.all(N360Space.xl),
                  child: Text(l.dprBoqEmpty, textAlign: TextAlign.center, style: N360Type.bodyLarge),
                );
              }
              final rows = _ordered(found);
              return ListView.builder(
                keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                itemCount: rows.length,
                itemBuilder: (context, index) {
                  final BoqLine line = rows[index];
                  return _BoqRow(
                    line: line,
                    yesterday: yesterdayIds.contains(line.item.id),
                    added: added[line.item.id],
                    onTap: () => onPick(line),
                  );
                },
              );
            },
          ),
        ),
      ],
    );
  }
}

class _BoqRow extends StatelessWidget {
  const _BoqRow({
    required this.line,
    required this.yesterday,
    required this.added,
    required this.onTap,
  });

  final BoqLine line;
  final bool yesterday;
  final Qty? added;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final item = line.item;
    final Qty? already = added;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        LedgerListTile(
          title: item.description,
          subtitle: <String>[
            '${item.code} · ${l.dprBalance} ${dprQty(line.previewBalance)} ${item.unit}',
            if (already != null) '${l.dprAddedTag} ${dprQty(already)}'
            else if (yesterday) l.dprYesterdayTag,
          ].join(' · '),
          leading: DprBoqPercent(line: line),
          onTap: onTap,
          semanticsLabel: '${item.code} ${item.description}',
        ),
        const RuledDivider(),
      ],
    );
  }
}

class _KeypadStep extends StatelessWidget {
  const _KeypadStep({
    super.key,
    required this.services,
    required this.boqItemId,
    required this.fallbackText,
    required this.typed,
    required this.editing,
    required this.onTyped,
    required this.onDone,
    required this.onBack,
  });

  final AppServices services;
  final int? boqItemId;
  final String? fallbackText;
  final String typed;
  final bool editing;
  final ValueChanged<String> onTyped;
  final VoidCallback onDone;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final int? id = boqItemId;
    return StreamBuilder<BoqLine?>(
      stream: id == null ? null : services.projects.watchBoqLine(id),
      builder: (context, snapshot) {
        final BoqLine? boq = snapshot.data;
        final String unit = boq?.item.unit ?? '';
        final Qty? parsed = Qty.tryParse(typed);
        final bool valid = parsed != null && !parsed.isZero && !parsed.isNegative;
        final bool over = valid && boq != null && parsed > boq.previewBalance;

        return Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.lg, 0, N360Space.lg, N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Align(
                alignment: Alignment.centerLeft,
                child: DprTapPad(
                  onTap: onBack,
                  semanticsLabel: editing ? l.commonBack : l.dprChooseAnother,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      const Icon(Icons.arrow_back, size: N360IconSize.md, color: N360Colors.ink),
                      const SizedBox(width: 6),
                      Text(editing ? l.commonBack : l.dprChooseAnother, style: N360Type.bodyStrong),
                    ],
                  ),
                ),
              ),
              if (boq != null) ...<Widget>[
                SectionLabel('${boq.item.code} · ${boq.item.unit}'),
                Text(
                  boq.item.description,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.title,
                ),
                const SizedBox(height: N360Space.md),
                HatchedProgressBar(
                  fraction: dprBoqDone(boq).fraction,
                  semanticLabel: l.dprBoqDonePercent(dprBoqDone(boq).percent),
                ),
                const SizedBox(height: N360Space.xs),
                Row(
                  children: <Widget>[
                    Text('${dprBoqDone(boq).percent}%', style: N360Type.bodyStrong),
                    const SizedBox(width: N360Space.sm),
                    Expanded(
                      child: Text(
                        '${l.dprBalance} ${dprQty(boq.previewBalance)} ${boq.item.unit}',
                        textAlign: TextAlign.end,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.body.copyWith(color: N360Colors.ink2),
                      ),
                    ),
                  ],
                ),
              ] else if (fallbackText != null)
                Text(fallbackText!, maxLines: 3, overflow: TextOverflow.ellipsis, style: N360Type.title),
              const Spacer(),
              Row(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: <Widget>[
                  Text(l.dprQuantity, style: N360Type.heading),
                  const SizedBox(width: N360Space.md),
                  Expanded(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerRight,
                      child: RollingNumber(
                        value: typed.isEmpty ? '0' : typed,
                        style: N360Type.figureHero.copyWith(
                          color: typed.isEmpty ? N360Colors.inkLabel : N360Colors.ink,
                        ),
                        suffix: unit.isEmpty ? '' : ' $unit',
                        suffixStyle: N360Type.bodyStrong.copyWith(color: N360Colors.ink2, letterSpacing: 0),
                      ),
                    ),
                  ),
                ],
              ),
              SizedBox(
                height: 30,
                child: over
                    ? Row(
                        children: <Widget>[
                          const Icon(Icons.warning_amber, size: N360IconSize.sm, color: N360Colors.haldiInk),
                          const SizedBox(width: 4),
                          Expanded(
                            child: Text(
                              l.dprOverBalance,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: N360Type.caption.copyWith(
                                color: N360Colors.haldiInk,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                      )
                    : null,
              ),
              GloveKeypad(
                value: typed,
                onChanged: onTyped,
                onNext: valid ? onDone : null,
                nextLabel: editing ? l.commonDone : l.dprAdd,
                backspaceLabel: l.designKeypadDelete,
                nextIcon: Icons.check,
              ),
            ],
          ),
        );
      },
    );
  }
}
