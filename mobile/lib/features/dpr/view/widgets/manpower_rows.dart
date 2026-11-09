import 'package:flutter/material.dart';

import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';

/// One trade: its picture and name on the left, a glove stepper on the right.
///
/// Side by side rather than stacked, so a full muster takes a third less
/// scrolling. Rows with a count read in full ink; the rest stay quiet.
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

  static const double _labelWidth = 76;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final Pictogram picture = N360Pictograms.trade(trade);
    final String label = l.pictogramLabel(picture);
    final Color ink = count > 0 ? N360Colors.ink : N360Colors.inkLabel;

    return MergeSemantics(
      child: Row(
        children: <Widget>[
          SizedBox(
            width: _labelWidth,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Icon(picture.icon, size: N360IconSize.lg, color: ink),
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
    );
  }
}

/// The big, confident total at the top of the workers card.
class DprWorkerTotal extends StatelessWidget {
  const DprWorkerTotal({super.key, required this.total, this.yesterday});

  final int total;

  /// Yesterday's headcount, to compare against once today has counts.
  final int? yesterday;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final int? before = total > 0 ? yesterday : null;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: <Widget>[
        Expanded(
          child: RollingNumber(
            value: '$total',
            style: N360Type.figureHero.copyWith(
              color: total > 0 ? N360Colors.ink : N360Colors.inkLabel,
            ),
            animateEntry: true,
            suffix: ' ${l.dprWorkersWord(total)}',
            suffixStyle: N360Type.bodyStrong.copyWith(color: N360Colors.ink2, letterSpacing: 0),
            semanticsLabel: l.dprWorkersCount(total),
          ),
        ),
        if (before != null)
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(l.dprYesterdayLabel, style: N360Type.caption),
              Text('$before', style: N360Type.heading),
            ],
          ),
      ],
    );
  }
}
