import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/record_sync.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../projects/data/project_repository.dart';
import '../bloc/measurement_book.dart';
import '../bloc/measurement_detail_cubit.dart';
import 'measurement_list_screen.dart' show entryTitle;
import 'widgets/book_kit.dart';

/// One entry, in full, with what can still be done to it.
class MeasurementDetailScreen extends StatelessWidget {
  const MeasurementDetailScreen({super.key, required this.clientUuid});

  final String clientUuid;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MeasurementDetailCubit>(
      create: (context) =>
          MeasurementDetailCubit(services: context.read<AppServices>(), clientUuid: clientUuid)..start(),
      child: _DetailView(clientUuid: clientUuid),
    );
  }
}

class _DetailView extends StatelessWidget {
  const _DetailView({required this.clientUuid});

  final String clientUuid;

  void _leave(BuildContext context) {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go(Routes.measurements);
    }
  }

  Future<void> _send(BuildContext context, MeasurementDetailState state) async {
    final MeasurementDetailCubit cubit = context.read<MeasurementDetailCubit>();
    final AppLocalizations l = AppLocalizations.of(context);
    final bool online = state.online;
    final DetailSendResult result = await cubit.send();
    if (!context.mounted) return;
    switch (result) {
      case DetailSendResult.sent:
        await showStampThud(
          context,
          online ? StampKind.inReview : StampKind.queued,
          online ? l.measurementStampSent : l.statusQueued,
        );
        if (context.mounted && !online) {
          showPaperToast(context, l.measurementSendsWhenOnline);
        }
      case DetailSendResult.locked:
        showPaperToast(context, l.measurementProblemLocked, kind: PaperToastKind.warning);
      case DetailSendResult.failed:
        showPaperToast(context, l.measurementProblemUnknown, kind: PaperToastKind.error);
      case DetailSendResult.ignored:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocBuilder<MeasurementDetailCubit, MeasurementDetailState>(
      builder: (context, state) {
        final SyncedRecord<MeasurementRow>? synced = state.entry;
        if (state.loading) return const Scaffold(backgroundColor: Colors.transparent);
        if (synced == null) return _gone(context, l);
        final MeasurementRow entry = synced.record;

        return Scaffold(
          backgroundColor: Colors.transparent,
          body: LedgerPaper(
            child: SafeArea(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  if (!state.online) OfflineBanner(label: l.measurementOffline, detail: l.measurementOfflineDetail),
                  BookTitleBar(title: l.measurementDetailTitle, onBack: () => _leave(context)),
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 6, N360Space.gutter, N360Space.lg),
                      children: <Widget>[
                        LedgerHero(
                          tag: 'measurement-${entry.clientUuid}',
                          child: _HeroCard(row: synced, line: state.line),
                        ),
                        ..._notes(context, l, state, synced),
                        const SizedBox(height: N360Space.md),
                        _DimensionCard(entry: entry),
                        if (state.line != null) ...<Widget>[
                          const SizedBox(height: N360Space.md),
                          _BoqCard(line: state.line!),
                        ],
                        const SizedBox(height: N360Space.md),
                        _FactsCard(entry: entry, line: state.line),
                        const SizedBox(height: N360Space.sm),
                        Text(l.measurementPreviewNote, style: N360Type.caption),
                      ],
                    ),
                  ),
                  _Actions(
                    state: state,
                    onEdit: () => context.push('${Routes.measurementForm}?uuid=$clientUuid'),
                    onSend: () => _send(context, state),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  List<Widget> _notes(
    BuildContext context,
    AppLocalizations l,
    MeasurementDetailState state,
    SyncedRecord<MeasurementRow> synced,
  ) {
    final MeasurementRow entry = synced.record;
    final String? remark = entry.returnRemark;
    const Widget gap = SizedBox(height: N360Space.md);
    return <Widget>[
      if (remark != null && remark.isNotEmpty) ...<Widget>[
        gap,
        TintedNote(
          icon: Icons.reply,
          ink: N360Colors.haldiInk,
          fill: N360Colors.goldTint,
          title: l.measurementApproverSaid,
          body: remark,
        ),
      ],
      if (entry.deviation) ...<Widget>[
        gap,
        TintedNote(
          icon: Icons.warning_amber,
          ink: N360Colors.ink,
          fill: N360Colors.accent,
          title: l.measurementDeviation,
          body: l.measurementDeviationDetail,
        ),
      ],
      if (entry.status == DocStatus.approved) ...<Widget>[
        gap,
        TintedNote(
          icon: Icons.lock_outline,
          ink: N360Colors.mossInk,
          fill: N360Colors.paper2,
          title: l.measurementApprovedLockedTitle,
          body: l.measurementApprovedLockedBody,
        ),
      ],
      if (state.submitWaiting) ...<Widget>[
        gap,
        TintedNote(
          icon: Icons.schedule,
          ink: N360Colors.ink2,
          fill: N360Colors.paper2,
          title: l.measurementSubmitWaitingTitle,
          body: state.online ? l.measurementSavedOnPhone : l.measurementSendsWhenOnline,
        ),
      ],
      if (synced.syncState == LocalSyncState.attention) ...<Widget>[
        gap,
        ErrorSlip(title: l.measurementAttentionTitle, message: synced.attentionReason ?? l.measurementAttentionBody),
      ],
    ];
  }

  Widget _gone(BuildContext context, AppLocalizations l) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(N360Space.gutter),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: <Widget>[
                EmptyLedger(title: l.measurementGoneTitle, message: l.measurementGoneBody),
                GloveButton(
                  label: l.commonBack,
                  large: true,
                  fullWidth: true,
                  kind: GloveButtonKind.secondary,
                  onPressed: () => _leave(context),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// The entry at a glance: what it is, how much, and its status.
class _HeroCard extends StatelessWidget {
  const _HeroCard({required this.row, required this.line});

  final SyncedRecord<MeasurementRow> row;
  final BoqLine? line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MeasurementRow entry = row.record;
    final BoqLine? boq = line;
    final String unit = boq?.item.unit ?? '';
    final String eyebrow = boq == null ? l.measurementItem : '${l.measurementItem} ${boq.item.code} · $unit';
    final Color qtyInk = entry.qty.isNegative ? N360Colors.laterite : N360Colors.ink;
    final String? formula = bookFormula(
      nos: entry.nos,
      length: entry.length,
      breadth: entry.breadth,
      depth: entry.depth,
      deduction: entry.isDeduction,
      result: entry.qty,
      unit: unit,
    );
    final String? percent = boq == null ? null : shareLabel(entry.qty, boq.item.qty);
    final double? fraction = boq == null ? null : shareFraction(entry.qty, boq.item.qty);

    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              IconTile(icon: Icons.architecture, semanticLabel: eyebrow),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(eyebrow, maxLines: 1, overflow: TextOverflow.ellipsis, style: N360Type.caption),
                    Text(
                      entryTitle(entry, boq?.item),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.heading.copyWith(height: 1.3),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: N360Space.xs),
              RubberStamp(kind: stampKindFor(entry.status), label: statusWord(l, entry.status), size: StampSize.small),
            ],
          ),
          const SizedBox(height: N360Space.sm),
          Row(
            children: <Widget>[
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text.rich(
                    key: const ValueKey<String>('detail-figure'),
                    TextSpan(
                      text: formatQty(entry.qty),
                      children: <InlineSpan>[
                        if (unit.isNotEmpty)
                          TextSpan(
                            text: ' $unit',
                            style: N360Type.heading.copyWith(color: qtyInk, fontWeight: FontWeight.w500),
                          ),
                      ],
                    ),
                    style: N360Type.figureHero.copyWith(color: qtyInk, fontSize: 40),
                  ),
                ),
              ),
              if (percent != null && fraction != null) ...<Widget>[
                const SizedBox(width: N360Space.sm),
                Semantics(
                  label: '$percent ${l.measurementShareOfBoq}',
                  child: ExcludeSemantics(
                    child: SquarePercent(fraction: fraction, label: percent, size: 60),
                  ),
                ),
              ],
            ],
          ),
          if (formula != null) ...<Widget>[
            const SizedBox(height: 2),
            FittedBox(
              alignment: Alignment.centerLeft,
              fit: BoxFit.scaleDown,
              child: Text(
                formula,
                style: N360Type.figureSmall.copyWith(color: entry.isDeduction ? N360Colors.laterite : N360Colors.ink2),
              ),
            ),
          ],
          if (row.isUnsent) ...<Widget>[const SizedBox(height: N360Space.xs), SyncMark(state: row.syncState)],
        ],
      ),
    );
  }
}

/// Nos, L, B and D side by side, the way they were taken on site.
class _DimensionCard extends StatelessWidget {
  const _DimensionCard({required this.entry});

  final MeasurementRow entry;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    String dim(Qty? value) => value == null ? '—' : bookFigure(value);
    Widget tile(String label, String value) => Expanded(
      child: _MiniTile(label: label, value: value),
    );
    return LedgerCard(
      padding: const EdgeInsets.all(N360Space.sm + 2),
      child: Row(
        children: <Widget>[
          tile(l.measurementNos, dim(entry.nos)),
          const SizedBox(width: 6),
          tile(l.measurementLength, dim(entry.length)),
          const SizedBox(width: 6),
          tile(l.measurementBreadth, dim(entry.breadth)),
          const SizedBox(width: 6),
          tile(l.measurementDepth, dim(entry.depth)),
        ],
      ),
    );
  }
}

class _MiniTile extends StatelessWidget {
  const _MiniTile({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: '$label $value',
      child: ExcludeSemantics(
        child: DecoratedBox(
          decoration: const BoxDecoration(color: N360Colors.paper2, borderRadius: N360Radius.md),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.sm, vertical: N360Space.sm),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.caption.copyWith(fontSize: 13),
                ),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(value, maxLines: 1, style: N360Type.figure),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// How far the BOQ line has got, with this phone's unsent rows included.
class _BoqCard extends StatelessWidget {
  const _BoqCard({required this.line});

  final BoqLine line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = line.item.unit;
    final Qty used = line.item.qty - line.previewBalance;
    final double fraction = used.isNegative ? 0 : (shareFraction(used, line.item.qty) ?? 0);
    final String percent = used.isNegative ? '0%' : (shareLabel(used, line.item.qty) ?? '');
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(l.measurementBoqProgress, style: N360Type.heading),
          HatchedProgressBar(
            fraction: fraction,
            markerLabel: percent,
            height: 28,
            semanticLabel: l.measurementBoqUsed(percent),
          ),
          const SizedBox(height: N360Space.sm),
          Row(
            children: <Widget>[
              Expanded(
                child: _Figure(label: l.measurementBoqQty, value: '${formatQty(line.item.qty)} $unit'),
              ),
              Expanded(
                child: _Figure(
                  label: l.measurementBalance,
                  value: '${formatQty(line.previewBalance)} $unit',
                  end: true,
                  ink: line.previewBalance.isNegative ? N360Colors.laterite : N360Colors.ink,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, this.end = false, this.ink = N360Colors.ink});

  final String label;
  final String value;
  final bool end;
  final Color ink;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: end ? CrossAxisAlignment.end : CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.caption),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(value, maxLines: 1, style: N360Type.figure.copyWith(color: ink, fontSize: 17)),
        ),
      ],
    );
  }
}

class _FactsCard extends StatelessWidget {
  const _FactsCard({required this.entry, required this.line});

  final MeasurementRow entry;
  final BoqLine? line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final BoqLine? boq = line;
    final bool hasLocation = entry.location?.isNotEmpty ?? false;
    final bool hasDescription = entry.description?.isNotEmpty ?? false;
    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.xs, N360Space.lg, N360Space.xs),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          BookRow.words(label: l.measurementDateLabel, value: dayLabel(context, entry.measuredOn, today)),
          if (boq != null) BookRow(label: l.measurementItem, value: boq.item.code, hint: boq.item.description),
          if (hasLocation) BookRow.words(label: l.measurementLocationLabel, value: entry.location!),
          if (hasDescription)
            BookRow.words(label: l.measurementDescriptionLabel, value: entry.description!, ruled: false),
        ],
      ),
    );
  }
}

class _Actions extends StatelessWidget {
  const _Actions({required this.state, required this.onEdit, required this.onSend});

  final MeasurementDetailState state;
  final VoidCallback onEdit;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    if (!state.canChange) return const SizedBox.shrink();
    final bool returned = state.entry?.record.status == DocStatus.returned;
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.sm, 0, N360Space.sm, N360Space.sm),
      child: LedgerCard(
        padding: const EdgeInsets.all(N360Space.sm),
        child: Row(
          children: <Widget>[
            Expanded(
              flex: 4,
              child: GloveButton(
                key: const ValueKey<String>('measurement-edit'),
                label: l.measurementEdit,
                icon: Icons.edit,
                kind: GloveButtonKind.secondary,
                large: true,
                fullWidth: true,
                onPressed: onEdit,
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              flex: 5,
              child: GloveButton(
                key: const ValueKey<String>('measurement-detail-send'),
                label: returned ? l.measurementSendAgain : l.measurementSendForApproval,
                large: true,
                fullWidth: true,
                loading: state.busy,
                onPressed: onSend,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
