import 'package:flutter/material.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart' show BoqLine;
import '../../data/dpr_repository.dart';
import 'dpr_format.dart';
import 'dpr_ui.dart';

/// A work-done row: how far that BOQ line has got, what it is, and how much
/// was done today. The figure is a tile, so it reads as something to change.
class DprWorkRow extends StatefulWidget {
  const DprWorkRow({
    super.key,
    required this.line,
    required this.services,
    required this.onChange,
    required this.onRemove,
  });

  final WorkLine line;
  final AppServices services;
  final VoidCallback onChange;
  final VoidCallback onRemove;

  @override
  State<DprWorkRow> createState() => _DprWorkRowState();
}

class _DprWorkRowState extends State<DprWorkRow> {
  Stream<BoqLine?>? _live;
  int? _watching;

  @override
  void initState() {
    super.initState();
    _subscribe();
  }

  @override
  void didUpdateWidget(covariant DprWorkRow oldWidget) {
    super.didUpdateWidget(oldWidget);
    _subscribe();
  }

  void _subscribe() {
    final int? id = widget.line.boqItemId;
    if (id == _watching && _live != null) return;
    _watching = id;
    _live = id == null ? null : widget.services.projects.watchBoqLine(id);
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return StreamBuilder<BoqLine?>(
      stream: _live,
      builder: (context, snapshot) {
        final BoqLine? boq = snapshot.data;
        final String unit = boq?.item.unit ?? '';
        final String description = boq?.item.description ?? widget.line.description ?? '';
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                if (boq != null) ...<Widget>[
                  DprBoqPercent(line: boq),
                  const SizedBox(width: N360Space.md),
                ],
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        description,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.bodyStrong,
                      ),
                      if (boq != null)
                        Text(
                          '${boq.item.code} · ${l.dprBalance} ${dprQty(boq.previewBalance)} $unit',
                          maxLines: 2,
                          style: N360Type.caption,
                        ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            Row(
              children: <Widget>[
                Expanded(
                  child: DimensionTile(
                    label: l.dprQtyShort,
                    unit: unit.isEmpty ? null : unit,
                    value: dprQty(widget.line.qty),
                    onTap: widget.onChange,
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                DprTapPad(
                  onTap: widget.onRemove,
                  semanticsLabel: l.dprRemove,
                  minWidth: 72,
                  minHeight: 72,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      const Icon(Icons.close, size: 24, color: N360Colors.stampRed),
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: Text(
                          l.dprRemove,
                          maxLines: 1,
                          style: N360Type.caption.copyWith(
                            color: N360Colors.stampRed,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ],
        );
      },
    );
  }
}

/// The rounded-square progress mark for a BOQ line, measured against its
/// full quantity. Says so out loud, because a bare number means nothing.
class DprBoqPercent extends StatelessWidget {
  const DprBoqPercent({super.key, required this.line, this.size = 56});

  final BoqLine line;
  final double size;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final done = dprBoqDone(line);
    return Semantics(
      container: true,
      label: l.dprBoqDonePercent(done.percent),
      child: ExcludeSemantics(
        child: SquarePercent(
          fraction: done.fraction,
          label: '${done.percent}%',
          size: size,
        ),
      ),
    );
  }
}
