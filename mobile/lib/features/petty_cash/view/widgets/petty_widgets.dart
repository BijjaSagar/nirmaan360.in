import 'dart:typed_data';

import 'package:flutter/material.dart';

import 'package:intl/intl.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../attendance/view/widgets/field_kit.dart';
import '../../bloc/expense_form_cubit.dart' show formatTypedAmount;
import '../../data/petty_cash_repository.dart' show LocalSyncState;

/// Nothing once sent; a stamp saying where the entry is otherwise.
class PettySyncStamp extends StatelessWidget {
  const PettySyncStamp({super.key, required this.state});

  final LocalSyncState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return switch (state) {
      LocalSyncState.synced => const SizedBox.shrink(),
      LocalSyncState.queued => RubberStamp(
        kind: StampKind.queued,
        label: l.statusQueued,
        size: StampSize.small,
      ),
      LocalSyncState.sending => RubberStamp(
        kind: StampKind.inReview,
        label: l.pettyCashSyncSending,
        size: StampSize.small,
      ),
      LocalSyncState.attention => RubberStamp(
        kind: StampKind.rejected,
        label: l.pettyCashSyncAttention,
        size: StampSize.small,
      ),
    };
  }
}

/// A small bill preview: the picture when it can be shown, otherwise a labelled
/// placeholder. Decoding is capped with `cacheWidth` so a list of receipts stays
/// cheap on a low-end phone.
class BillThumbnail extends StatelessWidget {
  const BillThumbnail({
    super.key,
    required this.load,
    required this.size,
  });

  /// Bytes of the image, or null when there is nothing to show.
  final Future<Uint8List>? load;
  final double size;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final int cacheWidth =
        (size * MediaQuery.devicePixelRatioOf(context)).round().clamp(40, 400);

    final Widget placeholder = ColoredBox(
      color: N360Colors.paper2,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: <Widget>[
          const Icon(Icons.receipt_long, size: 20, color: N360Colors.ink2),
          Text(
            l.pettyCashBillLabel,
            style: N360Type.caption.copyWith(fontSize: 11, height: 1.1, color: N360Colors.ink2),
          ),
        ],
      ),
    );

    return SizedBox.square(
      dimension: size,
      child: ClipRRect(
        borderRadius: N360Radius.sm,
        child: load == null
            ? placeholder
            : FutureBuilder<Uint8List>(
                future: load,
                builder: (context, snapshot) {
                  final Uint8List? bytes = snapshot.data;
                  if (bytes == null || bytes.length < 16) return placeholder;
                  return Image.memory(
                    bytes,
                    fit: BoxFit.cover,
                    cacheWidth: cacheWidth,
                    gaplessPlayback: true,
                    errorBuilder: (_, _, _) => placeholder,
                  );
                },
              ),
      ),
    );
  }
}

/// The amount being typed on the keypad, as the screen's main figure.
class PettyAmountCard extends StatelessWidget {
  const PettyAmountCard({
    super.key,
    required this.label,
    required this.typed,
    this.footer,
    this.color = N360Colors.ink,
  });

  final String label;

  /// Exactly what the keypad holds.
  final String typed;

  /// A line under the figure: a balance, a helper or a warning.
  final Widget? footer;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(label, style: N360Type.heading),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: RollingMoney(
              value: formatTypedAmount(typed),
              style: N360Type.figureHero.copyWith(color: color),
            ),
          ),
          if (footer != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            footer!,
          ],
        ],
      ),
    );
  }
}

/// Today or yesterday, default today.
class PettyDateChoice extends StatelessWidget {
  const PettyDateChoice({
    super.key,
    required this.today,
    required this.yesterday,
    required this.value,
    required this.onChanged,
  });

  final String today;
  final String yesterday;
  final String value;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).toString();
    String shortDay(String iso) => DateFormat('d MMM', locale).format(DateTime.parse(iso));

    return Row(
      children: <Widget>[
        Expanded(
          child: FieldChoiceTile(
            width: double.infinity,
            label: l.pettyCashDayToday,
            detail: shortDay(today),
            selected: value == today,
            onTap: () => onChanged(today),
          ),
        ),
        const SizedBox(width: N360Space.sm),
        Expanded(
          child: FieldChoiceTile(
            width: double.infinity,
            label: l.pettyCashDayYesterday,
            detail: shortDay(yesterday),
            selected: value == yesterday,
            onTap: () => onChanged(yesterday),
          ),
        ),
      ],
    );
  }
}
