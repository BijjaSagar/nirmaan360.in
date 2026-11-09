import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../measurement/bloc/measurement_book.dart';
import '../../../measurement/view/widgets/book_kit.dart';
import '../../data/progress_models.dart';

/// One entry on a floor: the line it counts toward, the day, who wrote it, and
/// what is counted for it now. Tapping it, for someone who may record progress,
/// opens the correction.
class RecentEntryTile extends StatelessWidget {
  const RecentEntryTile({super.key, required this.entry, required this.item, required this.today, this.onCorrect});

  final FloorEntry entry;

  /// The BOQ line, when this phone has it.
  final ProgressItem? item;
  final String today;

  /// Null for someone who may not record progress.
  final VoidCallback? onCorrect;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ProgressItem? line = item;
    final String title = line == null ? l.progressFormItem : '${line.code} · ${line.description}';
    final String unit = line?.unit ?? '';
    final String? date = entry.workDate == null ? null : dayLabel(context, entry.workDate!, today);
    final String meta = <String>[?date, if (entry.enteredBy != null) l.progressEntryBy(entry.enteredBy!), ?entry.remark].join(' · ');
    final String figure = '${formatQty(entry.shown)} $unit'.trim();
    final String? corrected = entry.wasCorrected ? l.progressEntryCorrectedFrom(formatQty(entry.entered)) : null;

    final Widget body = ConstrainedBox(
      constraints: const BoxConstraints(minHeight: N360Size.tap),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.tile),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: N360Type.fit(N360Type.bodyStrong, title)),
                  if (meta.isNotEmpty)
                    Text(meta, maxLines: 2, overflow: TextOverflow.ellipsis, style: N360Type.fit(N360Type.caption, meta)),
                  if (corrected != null) Text(corrected, style: N360Type.fit(N360Type.caption, corrected)),
                  if (entry.pending != null)
                    Text(
                      l.progressCorrectionNotSent,
                      style: N360Type.fit(N360Type.caption, l.progressCorrectionNotSent).copyWith(
                        color: N360Colors.laterite,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(figure, style: N360Type.figureSmall),
                if (onCorrect != null)
                  Text(
                    l.progressCorrect,
                    style: N360Type.fit(N360Type.label, l.progressCorrect).copyWith(color: N360Colors.laterite),
                  ),
              ],
            ),
          ],
        ),
      ),
    );

    final VoidCallback? tap = onCorrect;
    if (tap == null) return body;
    return PressTarget(
      onTap: tap,
      haptic: N360Haptics.select,
      semanticsLabel: '$title, $figure, ${l.progressCorrect}',
      builder: (context, depth, child) => Opacity(opacity: 1 - depth * 0.3, child: child),
      child: body,
    );
  }
}

/// What the person typed into the correction sheet.
class CorrectionAnswer {
  const CorrectionAnswer(this.qty, this.remark);

  final Qty qty;
  final String remark;
}

/// Asks what an entry should have been, and why.
Future<CorrectionAnswer?> showCorrectionSheet(BuildContext context, {required FloorEntry entry, required String unit}) {
  return showLedgerSheet<CorrectionAnswer>(
    context,
    isScrollControlled: true,
    builder: (_) => _CorrectionSheet(entry: entry, unit: unit),
  );
}

class _CorrectionSheet extends StatefulWidget {
  const _CorrectionSheet({required this.entry, required this.unit});

  final FloorEntry entry;
  final String unit;

  @override
  State<_CorrectionSheet> createState() => _CorrectionSheetState();
}

class _CorrectionSheetState extends State<_CorrectionSheet> {
  late final TextEditingController _qty = TextEditingController(text: widget.entry.shown.value.toString());
  final TextEditingController _remark = TextEditingController();
  String? _qtyError;
  String? _remarkError;

  /// The server refuses a quantity above this.
  static final Qty _most = Qty.fromInt(99999999);

  @override
  void dispose() {
    _qty.dispose();
    _remark.dispose();
    super.dispose();
  }

  void _save() {
    final AppLocalizations l = AppLocalizations.of(context);
    final Qty? right = Qty.tryParse(_qty.text);
    final String note = _remark.text.trim();
    String? qtyError;
    if (right == null || right.isNegative || right > _most) {
      qtyError = l.progressCorrectQtyInvalid;
    } else if (right == widget.entry.shown) {
      qtyError = l.progressCorrectSame;
    }
    final String? remarkError = note.isEmpty ? l.progressCorrectRemarkRequired : null;
    if (qtyError != null || remarkError != null) {
      N360Haptics.warning();
      setState(() {
        _qtyError = qtyError;
        _remarkError = remarkError;
      });
      return;
    }
    Navigator.of(context).pop(CorrectionAnswer(right!, note));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String now = '${formatQty(widget.entry.net)} ${widget.unit}'.trim();
    return Padding(
      padding: EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.sm,
        N360Space.gutter,
        N360Space.lg + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(l.progressCorrectTitle, style: N360Type.fit(N360Type.heading, l.progressCorrectTitle)),
            Text(l.progressCorrectHint, style: N360Type.fit(N360Type.caption, l.progressCorrectHint)),
            const SizedBox(height: N360Space.md),
            Text(l.progressCorrectNow(now), style: N360Type.fit(N360Type.bodyStrong, l.progressCorrectNow(now))),
            const SizedBox(height: N360Space.md),
            GloveTextField(
              key: const ValueKey<String>('progress-correct-qty'),
              label: widget.unit.isEmpty ? l.progressCorrectQty : '${l.progressCorrectQty} (${widget.unit})',
              controller: _qty,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              textInputAction: TextInputAction.next,
              errorText: _qtyError,
              onChanged: (_) {
                if (_qtyError != null) setState(() => _qtyError = null);
              },
            ),
            const SizedBox(height: N360Space.md),
            GloveTextField(
              key: const ValueKey<String>('progress-correct-remark'),
              label: l.progressCorrectRemark,
              controller: _remark,
              minLines: 2,
              maxLines: 3,
              errorText: _remarkError,
              inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(1000)],
              onChanged: (_) {
                if (_remarkError != null) setState(() => _remarkError = null);
              },
            ),
            const SizedBox(height: N360Space.lg),
            GloveButton(
              key: const ValueKey<String>('progress-correct-save'),
              label: l.progressCorrectSave,
              icon: Icons.check,
              large: true,
              fullWidth: true,
              onPressed: _save,
            ),
          ],
        ),
      ),
    );
  }
}
