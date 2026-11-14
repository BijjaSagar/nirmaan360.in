import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../app/app_gate.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/progress_cubit.dart';
import '../data/progress_repository.dart';
import 'widgets/progress_widgets.dart';

/// One floor or flat, one trade: each stage in the order the work is done.
class StageRowScreen extends StatelessWidget {
  const StageRowScreen({super.key, required this.floorId, required this.tradeKey});

  final int floorId;
  final String tradeKey;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ProgressCubit>(
      create: (context) => ProgressCubit(context.read<AppServices>()),
      child: _StageRowView(floorId: floorId, tradeKey: tradeKey),
    );
  }
}

class _StageRowView extends StatelessWidget {
  const _StageRowView({required this.floorId, required this.tradeKey});

  final int floorId;
  final String tradeKey;

  Future<void> _edit(BuildContext context, StageRow row, StageTrade trade, StageDef stage, StageMatrix matrix) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final AppServices services = context.read<AppServices>();
    final int? projectId = context.read<ProgressCubit>().state.project?.id;
    if (projectId == null) return;
    final StageCell cell = row.cell(trade.key, stage.key);
    final _StageChoice? choice = await showLedgerSheet<_StageChoice>(
      context,
      isScrollControlled: true,
      builder: (context) => _StageSheet(stage: stage, matrix: matrix, cell: cell),
    );
    if (choice == null) return;
    await services.progress.setStage(
      projectId: projectId,
      floorId: row.id,
      trade: trade.key,
      stage: stage.key,
      status: choice.status,
      remark: choice.remark.trim().isEmpty ? null : choice.remark.trim(),
      photoPaths: choice.photos,
      title: stage.label,
      subtitle: '${row.label} · ${trade.label}',
    );
    if (context.mounted) {
      N360Haptics.success();
      showPaperToast(context, l.progressStageSaved, kind: PaperToastKind.success);
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canEdit = context.select((AppGate gate) => gate.state.can('progress.create'));
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<ProgressCubit, ProgressState>(
            builder: (context, state) {
              final StageMatrix? matrix = state.overview?.matrix;
              final StageRow? row = matrix?.row(floorId);
              final StageTrade? trade = matrix?.trade(tradeKey);
              if (matrix == null || row == null || trade == null) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    FieldTopBar(title: l.progressTabStages),
                    Expanded(child: EmptyLedger(title: l.progressNoDataTitle, message: l.progressNoDataMessage)),
                  ],
                );
              }
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: trade.label, subtitle: row.label),
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.md, N360Space.gutter, N360Space.lg),
                      children: <Widget>[
                        LedgerCard(
                          padding: EdgeInsets.zero,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            mainAxisSize: MainAxisSize.min,
                            children: <Widget>[
                              for (final (index, stage) in trade.stages.indexed) ...<Widget>[
                                if (index > 0) const RuledDivider(indent: N360Space.card),
                                _tile(context, l, matrix, row, trade, stage, canEdit),
                              ],
                            ],
                          ),
                        ),
                      ],
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

  Widget _tile(
    BuildContext context,
    AppLocalizations l,
    StageMatrix matrix,
    StageRow row,
    StageTrade trade,
    StageDef stage,
    bool canEdit,
  ) {
    final StageCell cell = row.cell(trade.key, stage.key);
    final String? line = cell.warning ? l.progressStageEarlierPending : (cell.remark ?? stage.hint);
    return LedgerListTile(
      key: ValueKey<String>('progress-stage-${stage.key}'),
      leading: Icon(stageIcon(cell.status), size: N360IconSize.lg, color: N360Colors.ink2),
      title: '${stage.no}. ${stage.label}',
      subtitle: line,
      status: StatusPill(kind: stageKind(cell.status), label: matrix.statusLabel(cell.status)),
      onTap: canEdit ? () => _edit(context, row, trade, stage, matrix) : null,
    );
  }
}

class _StageChoice {
  const _StageChoice({required this.status, required this.remark, required this.photos});

  final String status;
  final String remark;
  final List<String> photos;
}

class _StageSheet extends StatefulWidget {
  const _StageSheet({required this.stage, required this.matrix, required this.cell});

  final StageDef stage;
  final StageMatrix matrix;
  final StageCell cell;

  @override
  State<_StageSheet> createState() => _StageSheetState();
}

class _StageSheetState extends State<_StageSheet> {
  late String _status = widget.cell.status;
  late final TextEditingController _remark = TextEditingController(text: widget.cell.remark ?? '');
  List<String> _photos = const <String>[];

  @override
  void dispose() {
    _remark.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final List<StageStatusOption> options = <StageStatusOption>[
      for (final StageStatusOption option in widget.matrix.statuses)
        if (option.key != 'na' || widget.stage.optional) option,
    ];
    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.sm,
        N360Space.gutter,
        N360Space.lg + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(widget.stage.label, style: N360Type.fit(N360Type.heading, widget.stage.label)),
          if (widget.stage.hint != null) Text(widget.stage.hint!, style: N360Type.fit(N360Type.caption, widget.stage.hint!)),
          const SizedBox(height: N360Space.md),
          Text(l.progressStageStatus, style: N360Type.fit(N360Type.label, l.progressStageStatus)),
          const SizedBox(height: N360Space.xs),
          Wrap(
            spacing: N360Space.sm,
            children: <Widget>[
              for (final StageStatusOption option in options)
                IntrinsicWidth(
                  child: N360Chip(
                    key: ValueKey<String>('progress-status-${option.key}'),
                    label: option.label,
                    icon: stageIcon(option.key),
                    selected: _status == option.key,
                    onTap: () => setState(() => _status = option.key),
                  ),
                ),
            ],
          ),
          const SizedBox(height: N360Space.md),
          GloveTextField(label: l.progressStageNote, controller: _remark, maxLines: 2),
          const SizedBox(height: N360Space.md),
          ProgressPhotoRow(
            photos: _photos,
            onAdd: (path) => setState(() => _photos = <String>[..._photos, path]),
            onRemove: (path) => setState(() => _photos = _photos.where((photo) => photo != path).toList()),
          ),
          const SizedBox(height: N360Space.md),
          GloveButton(
            key: const ValueKey<String>('progress-stage-save'),
            label: l.progressStageSave,
            icon: Icons.check,
            fullWidth: true,
            large: true,
            onPressed: () => Navigator.of(context).pop(
              _StageChoice(status: _status, remark: _remark.text, photos: _photos),
            ),
          ),
        ],
      ),
    );
  }
}
