import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../projects/data/project_repository.dart' show BoqLine;
import '../bloc/dpr_detail_cubit.dart';
import '../data/dpr_repository.dart';
import 'dpr_list_screen.dart' show DprReportFace;
import 'widgets/dpr_format.dart';
import 'widgets/dpr_ui.dart';
import 'widgets/photo_strip.dart';
import 'widgets/work_rows.dart' show DprBoqPercent;

/// One report, read-only: the same cards as the form, nothing left to fill in.
class DprDetailScreen extends StatelessWidget {
  const DprDetailScreen({super.key, required this.clientUuid});

  final String clientUuid;

  @override
  Widget build(BuildContext context) {
    final services = context.read<AppServices>();
    return BlocProvider<DprDetailCubit>(
      key: ValueKey<String>('detail-$clientUuid'),
      create: (_) => DprDetailCubit(services, clientUuid)..start(),
      child: const _DprDetailBody(),
    );
  }
}

class _DprDetailBody extends StatelessWidget {
  const _DprDetailBody();

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return BlocBuilder<DprDetailCubit, DprDetailState>(
      builder: (context, state) {
        final DprDetails? details = state.details;
        final bool canCorrect =
            details != null && details.dpr.status == DocStatus.returned && dprOpensForm(details.record);
        return Scaffold(
          body: LedgerPaper(
            child: SafeArea(
              bottom: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  DprTopBar(title: l.dprFormTitle),
                  Expanded(
                    child: details == null
                        ? (state.loading
                              ? Padding(
                                  padding: const EdgeInsets.all(N360Space.gutter),
                                  child: LedgerSkeletonList(rows: 3, semanticsLabel: l.dprLoading),
                                )
                              : Center(child: EmptyLedger(title: l.dprEmptyTitle)))
                        : DprActionLayout(
                            body: _body(context, state, details),
                            action: canCorrect
                                ? GloveButton(
                                    label: l.dprCorrectAndSend,
                                    icon: Icons.edit_note,
                                    large: true,
                                    fullWidth: true,
                                    onPressed: () => dprOpenForm(context, uuid: details.dpr.clientUuid),
                                  )
                                : null,
                          ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _body(BuildContext context, DprDetailState state, DprDetails details) {
    final l = AppLocalizations.of(context);
    final services = context.read<AppServices>();
    final DprRow dpr = details.dpr;
    final SyncedRecord<DprRow> record = details.record;
    final int total = dprWorkerTotal(dpr);
    final cards = <Widget>[];

    void add(Widget child) {
      cards.add(
        Padding(
          padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.cardGap),
          child: child,
        ),
      );
    }

    Widget card(Widget child) => LedgerCard(padding: const EdgeInsets.all(N360Space.card), child: child);

    Widget nothing() => Padding(
      padding: const EdgeInsets.only(top: N360Space.sm),
      child: Text(l.dprNothingRecorded, style: N360Type.body.copyWith(color: N360Colors.inkLabel)),
    );

    // Masthead: the day, where it stands, and the weather on one line.
    final String? weather = dpr.weather;
    add(
      card(
        Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            if (state.project != null) ...<Widget>[
              SectionLabel(state.project!.name),
              const SizedBox(height: N360Space.xs),
            ],
            LedgerHero(
              tag: dprHeroTag(dpr.clientUuid),
              child: DprReportFace(record: record, large: true),
            ),
            if (weather != null) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Row(
                children: <Widget>[
                  Icon(N360Pictograms.weather(weather).icon, size: N360IconSize.md, color: N360Colors.ink2),
                  const SizedBox(width: N360Space.xs),
                  Text(l.pictogramLabel(N360Pictograms.weather(weather)), style: N360Type.label),
                ],
              ),
            ],
          ],
        ),
      ),
    );

    // Where it stands, honestly, and the way out when something is stuck.
    if (record.syncState == LocalSyncState.attention) {
      add(
        ErrorSlip(
          title: l.dprSyncAttention,
          message: l.dprAttentionBody,
          actionLabel: l.dprOpenOutbox,
          onAction: () => dprGoOutbox(context),
        ),
      );
    } else if (record.syncState != LocalSyncState.synced) {
      add(
        PaperToast(
          message: state.online ? l.dprSavedSendingNow : l.dprSavedWillSend,
          kind: PaperToastKind.info,
          icon: state.online ? Icons.sync : Icons.cloud_off,
        ),
      );
    }

    final String? remark = dpr.returnRemark;
    if (remark != null && remark.isNotEmpty) {
      add(DprRemarkCard(title: l.dprApproverRemark, message: remark));
    } else if (dpr.status == DocStatus.returned) {
      add(DprRemarkCard(title: l.dprReturnedTitle, message: l.dprReturnedNoRemark));
    }

    // Workers: the screen's one big figure, then who made it up.
    final List<ManpowerLine> crew = dpr.manpower.toList()
      ..sort((a, b) => b.count.compareTo(a.count));
    add(
      card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(icon: Icons.groups, title: l.dprWorkersOnSite),
            const SizedBox(height: N360Space.xs),
            RollingNumber(
              value: '$total',
              style: N360Type.figureHero,
              animateEntry: true,
              suffix: ' ${l.dprWorkersWord(total)}',
              suffixStyle: N360Type.bodyStrong.copyWith(color: N360Colors.ink2, letterSpacing: 0),
              semanticsLabel: l.dprWorkersCount(total),
            ),
            if (crew.isEmpty)
              nothing()
            else ...<Widget>[
              const SizedBox(height: N360Space.xs),
              for (var i = 0; i < crew.length; i++)
                _TradeLine(line: crew[i], ruled: i != crew.length - 1),
            ],
          ],
        ),
      ),
    );

    // Work done.
    add(
      card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.assignment_turned_in_outlined,
              title: l.dprSectionWork,
              trailing: dpr.workItems.isEmpty
                  ? null
                  : Text(l.dprRowsCount(dpr.workItems.length), style: N360Type.label),
            ),
            if (dpr.workItems.isEmpty)
              nothing()
            else
              for (final WorkLine line in dpr.workItems)
                Padding(
                  padding: const EdgeInsets.only(top: N360Space.md),
                  child: _WorkLine(
                    line: line,
                    boq: line.boqItemId == null ? null : state.boq[line.boqItemId],
                  ),
                ),
          ],
        ),
      ),
    );

    // Photos.
    add(
      card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(
              icon: Icons.photo_library_outlined,
              title: l.dprSectionPhotos,
              trailing: details.photos.isEmpty
                  ? null
                  : Text(l.dprPhotosCount(details.photos.length), style: N360Type.label),
            ),
            if (details.photos.isEmpty)
              nothing()
            else ...<Widget>[
              const SizedBox(height: N360Space.md),
              DprPhotoStrip(
                photos: details.photos,
                repository: services.dpr,
                landed: <Object>{},
                onRemove: null,
              ),
            ],
          ],
        ),
      ),
    );

    // Hindrances and notes.
    final String hindrances = (dpr.hindrances ?? '').trim();
    final String notes = (dpr.summary ?? '').trim();
    add(
      card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            DprCardTitle(icon: Icons.edit_note, title: l.dprSectionNotes),
            if (hindrances.isEmpty && notes.isEmpty) nothing(),
            if (hindrances.isNotEmpty) ...<Widget>[
              const SizedBox(height: N360Space.md),
              Text(l.dprHindrances, style: N360Type.label),
              Text(hindrances, style: N360Type.body),
            ],
            if (notes.isNotEmpty) ...<Widget>[
              const SizedBox(height: N360Space.md),
              Text(l.dprNotes, style: N360Type.label),
              Text(notes, style: N360Type.body),
            ],
          ],
        ),
      ),
    );

    return ListView(
      padding: EdgeInsets.only(
        top: N360Space.xs,
        bottom: DprActionLayout.clearance(context),
      ),
      children: cards,
    );
  }
}

class _TradeLine extends StatelessWidget {
  const _TradeLine({required this.line, required this.ruled});

  final ManpowerLine line;
  final bool ruled;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final Pictogram picture = N360Pictograms.trade(line.trade);
    return LedgerRow(
      label: l.pictogramLabel(picture),
      value: '${line.count}',
      ruled: ruled,
    );
  }
}

class _WorkLine extends StatelessWidget {
  const _WorkLine({required this.line, required this.boq});

  final WorkLine line;
  final BoqLine? boq;

  @override
  Widget build(BuildContext context) {
    final BoqLine? item = boq;
    return Row(
      children: <Widget>[
        if (item != null) ...<Widget>[
          DprBoqPercent(line: item),
          const SizedBox(width: N360Space.tile),
        ],
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(
                item?.item.description ?? line.description ?? '',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.bodyStrong,
              ),
              if (item != null) Text(item.item.code, style: N360Type.caption),
            ],
          ),
        ),
        const SizedBox(width: N360Space.sm),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(dprQty(line.qty), style: N360Type.figure),
            if (item != null) Text(item.item.unit, style: N360Type.caption),
          ],
        ),
      ],
    );
  }
}
