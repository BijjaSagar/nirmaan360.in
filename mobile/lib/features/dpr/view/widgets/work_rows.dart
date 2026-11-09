import 'package:flutter/material.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart' show BoqLine;
import '../../data/dpr_repository.dart';
import 'dpr_format.dart';
import 'dpr_ui.dart';

/// A work-done row: what, how much, and a way to take it off the sheet.
///
/// The figure is a tile, so it reads as something that can be changed, and it
/// opens the keypad at the current value.
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
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: <Widget>[
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    if (boq != null)
                      Text(
                        boq.item.code,
                        style: N360Type.figureSmall.copyWith(
                          fontWeight: FontWeight.w500,
                          color: N360Colors.ink2,
                        ),
                      ),
                    Text(
                      description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.body,
                    ),
                    if (boq != null)
                      Text(
                        '${l.dprBalance} ${dprQty(boq.previewBalance)} $unit',
                        style: N360Type.caption,
                      ),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.sm),
              SizedBox(
                width: 104,
                child: DimensionTile(
                  label: l.dprQtyShort,
                  unit: unit.isEmpty ? null : unit,
                  value: dprQty(widget.line.qty),
                  onTap: widget.onChange,
                ),
              ),
              const SizedBox(width: N360Space.xs),
              DprTapPad(
                onTap: widget.onRemove,
                semanticsLabel: l.dprRemove,
                minWidth: 60,
                minHeight: 72,
                child: SizedBox(
                  width: 60,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      const Icon(Icons.close, size: 26, color: N360Colors.stampRed),
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
              ),
            ],
          ),
        );
      },
    );
  }
}
