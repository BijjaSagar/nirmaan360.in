import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// One trade: its picture and name on the left, a glove stepper on the right.
///
/// Laid out side by side rather than stacked, so a full muster takes a third
/// less scrolling. Rows with a count read in full ink; the rest stay quiet.
class DprTradeRow extends StatelessWidget {
  const DprTradeRow({
    super.key,
    required this.trade,
    required this.count,
    required this.onChanged,
  });

  final String trade;
  final int count;
  final ValueChanged<int> onChanged;

  static const double _labelWidth = 88;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final Pictogram picture = N360Pictograms.trade(trade);
    final String label = l.pictogramLabel(picture);
    final Color ink = count > 0 ? N360Colors.ink : N360Colors.inkLabel;

    return MergeSemantics(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          children: <Widget>[
            SizedBox(
              width: _labelWidth,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Icon(picture.icon, size: 26, color: ink),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      label,
                      maxLines: 1,
                      style: N360Type.label.copyWith(color: ink, height: 1.3),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: N360Space.xs),
            Expanded(
              child: GloveStepper(
                value: count,
                max: 999,
                onChanged: onChanged,
                decreaseLabel: l.designStepperDecrease,
                increaseLabel: l.designStepperIncrease,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The workers section's headline figure.
class DprWorkerTotal extends StatelessWidget {
  const DprWorkerTotal({super.key, required this.total});

  final int total;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return RollingNumber(
      value: '$total',
      style: N360Type.figureLarge.copyWith(
        color: total > 0 ? N360Colors.ink : N360Colors.inkLabel,
      ),
      suffix: ' ${l.dprWorkersWord(total)}',
      suffixStyle: N360Type.label.copyWith(color: N360Colors.ink2),
      semanticsLabel: l.dprWorkersCount(total),
    );
  }
}
