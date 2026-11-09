import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../projects/data/project_repository.dart' show BoqLine;
import '../../bloc/dpr_form_cubit.dart';
import '../../bloc/dpr_streams.dart';
import '../../data/dpr_repository.dart';
import 'dpr_format.dart';
import 'dpr_ui.dart';

/// The last look before the report goes: what counts, one big Send.
///
/// Returns how the send ended, or null if the engineer went back to edit.
Future<DprSendResult?> showDprReviewSheet(
  BuildContext context, {
  required DprFormCubit cubit,
  required AppServices services,
}) {
  return showLedgerSheet<DprSendResult>(
    context,
    isScrollControlled: true,
    builder: (_) => BlocProvider<DprFormCubit>.value(
      value: cubit,
      child: DprReviewSheet(services: services),
    ),
  );
}

class DprReviewSheet extends StatefulWidget {
  const DprReviewSheet({super.key, required this.services});

  final AppServices services;

  @override
  State<DprReviewSheet> createState() => _DprReviewSheetState();
}

class _DprReviewSheetState extends State<DprReviewSheet> {
  Map<int, BoqLine> _boq = const {};

  @override
  void initState() {
    super.initState();
    final projectId = context.read<DprFormCubit>().state.project?.id;
    if (projectId != null) {
      unawaited(_loadBoq(projectId));
    }
  }

  Future<void> _loadBoq(int projectId) async {
    final lines = await dprFirst(widget.services.projects.watchBoqItems(projectId));
    if (!mounted) return;
    setState(() => _boq = {for (final line in lines) line.item.id: line});
  }

  Future<void> _send(DprFormCubit cubit) async {
    final result = await cubit.send();
    if (!mounted) return;
    Navigator.of(context).pop(result);
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final cubit = context.watch<DprFormCubit>();
    final DprFormState state = cubit.state;
    final String? weather = state.weather;
    final bool offline = !widget.services.connectivity.isOnline;
    final List<WorkLine> work = state.work;
    const int shownRows = 3;

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.lg, 0, N360Space.lg, N360Space.md),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(child: Text(l.dprReviewTitle, style: N360Type.title)),
                DprTapPad(
                  onTap: () => Navigator.of(context).pop(),
                  semanticsLabel: l.dprEdit,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      const Icon(Icons.edit, size: N360IconSize.md, color: N360Colors.ink),
                      const SizedBox(width: 6),
                      Text(l.dprEdit, style: N360Type.bodyStrong),
                    ],
                  ),
                ),
              ],
            ),
            Row(
              children: <Widget>[
                if (weather != null) ...<Widget>[
                  Icon(N360Pictograms.weather(weather).icon, size: N360IconSize.sm, color: N360Colors.ink2),
                  const SizedBox(width: 4),
                  Text(
                    l.pictogramLabel(N360Pictograms.weather(weather)),
                    style: N360Type.label,
                  ),
                  const SizedBox(width: N360Space.sm),
                ],
                Expanded(
                  child: Text(
                    '${dprDateLabel(context, state.reportDate)} · ${state.project?.name ?? ''}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.caption,
                  ),
                ),
              ],
            ),
            const SizedBox(height: N360Space.md),
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Expanded(
                    child: StatTile(
                      title: l.dprSectionWorkers,
                      value: '${state.totalWorkers}',
                      emphasized: true,
                    ),
                  ),
                  const SizedBox(width: N360Space.sm),
                  Expanded(
                    child: StatTile(title: l.dprSectionWork, value: '${work.length}'),
                  ),
                  const SizedBox(width: N360Space.sm),
                  Expanded(
                    child: StatTile(title: l.dprSectionPhotos, value: '${state.photos.length}'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: N360Space.md),
            for (final line in work.take(shownRows))
              Padding(
                padding: const EdgeInsets.only(bottom: 2),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        _boq[line.boqItemId]?.item.description ?? line.description ?? '',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: N360Type.caption,
                      ),
                    ),
                    const SizedBox(width: N360Space.sm),
                    Text(
                      '${dprQty(line.qty)} ${_boq[line.boqItemId]?.item.unit ?? ''}',
                      style: N360Type.bodyStrong,
                    ),
                  ],
                ),
              ),
            if (work.length > shownRows)
              Text(l.dprMoreRows(work.length - shownRows), style: N360Type.caption),
            if (work.isNotEmpty) const SizedBox(height: N360Space.md),
            // The last chance to notice something was forgotten. Never a block.
            if (work.isEmpty || state.photos.isEmpty) ...<Widget>[
              _Missing(
                text: <String>[
                  if (work.isEmpty) l.dprNoWorkYet,
                  if (state.photos.isEmpty) l.dprNoPhotosYet,
                ].join(' '),
                stillOk: l.dprCanStillSend,
              ),
              const SizedBox(height: N360Space.md),
            ],
            if (offline) ...<Widget>[
              PaperToast(message: l.dprReviewOffline, kind: PaperToastKind.warning),
              const SizedBox(height: N360Space.sm),
            ],
            GloveButton(
              label: state.isReturned ? l.dprSendAgain : l.dprSend,
              icon: Icons.send,
              large: true,
              fullWidth: true,
              loading: state.sending,
              onPressed: state.sending ? null : () => unawaited(_send(cubit)),
            ),
          ],
        ),
      ),
    );
  }
}

class _Missing extends StatelessWidget {
  const _Missing({required this.text, required this.stillOk});

  final String text;
  final String stillOk;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Padding(
          padding: EdgeInsets.only(top: N360Space.xxs, right: N360Space.xs),
          child: Icon(Icons.info_outline, size: N360IconSize.sm, color: N360Colors.haldiInk),
        ),
        Expanded(
          child: Text(
            '$text $stillOk',
            style: N360Type.label.copyWith(color: N360Colors.haldiInk),
          ),
        ),
      ],
    );
  }
}
