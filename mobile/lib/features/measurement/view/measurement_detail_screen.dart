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
                  Padding(
                    padding: const EdgeInsets.fromLTRB(2, N360Space.xs, N360Space.gutter, 0),
                    child: Row(
                      children: <Widget>[
                        LedgerBackKey(onTap: () => _leave(context)),
                        const SizedBox(width: N360Space.xs),
                        Expanded(child: Text(l.measurementDetailTitle, style: N360Type.title)),
                        if (synced.isUnsent) SyncMark(state: synced.syncState),
                      ],
                    ),
                  ),
                  Expanded(
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(
                        N360Space.gutter,
                        N360Space.sm,
                        N360Space.gutter,
                        N360Space.lg,
                      ),
                      children: <Widget>[
                        LedgerHero(
                          tag: 'measurement-${entry.clientUuid}',
                          child: _SheetHead(entry: entry, line: state.line),
                        ),
                        ..._notes(context, l, state, synced),
                        const SizedBox(height: N360Space.md),
                        _Ledger(entry: entry, line: state.line),
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
    return <Widget>[
      if (remark != null && remark.isNotEmpty) ...<Widget>[
        const SizedBox(height: N360Space.md),
        _Note(icon: Icons.reply, ink: N360Colors.haldiInk, title: l.measurementApproverSaid, body: remark),
      ],
      if (entry.deviation) ...<Widget>[
        const SizedBox(height: N360Space.md),
        _Note(
          icon: Icons.warning_amber,
          ink: N360Colors.stampRed,
          title: l.measurementDeviation,
          body: l.measurementDeviationDetail,
        ),
      ],
      if (entry.status == DocStatus.approved) ...<Widget>[
        const SizedBox(height: N360Space.md),
        _Note(
          icon: Icons.lock_outline,
          ink: N360Colors.mossInk,
          title: l.measurementApprovedLockedTitle,
          body: l.measurementApprovedLockedBody,
        ),
      ],
      if (state.submitWaiting) ...<Widget>[
        const SizedBox(height: N360Space.md),
        _Note(
          icon: Icons.schedule,
          ink: N360Colors.ink2,
          title: l.measurementSubmitWaitingTitle,
          body: state.online ? l.measurementSavedOnPhone : l.measurementSendsWhenOnline,
        ),
      ],
      if (synced.syncState == LocalSyncState.attention) ...<Widget>[
        const SizedBox(height: N360Space.md),
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

class _SheetHead extends StatelessWidget {
  const _SheetHead({required this.entry, required this.line});

  final MeasurementRow entry;
  final BoqLine? line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? code = line?.item.code;
    final String unit = line?.item.unit ?? '';
    final String eyebrow = code == null ? l.measurementItem : '${l.measurementItem} $code · $unit';
    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.md, N360Space.md, N360Space.md),
      child: SheetHeader(
        eyebrow: eyebrow,
        title: entryTitle(entry, line?.item),
        ruled: false,
        trailing: RubberStamp(
          kind: stampKindFor(entry.status),
          label: statusWord(l, entry.status),
          size: StampSize.medium,
        ),
      ),
    );
  }
}

class _Ledger extends StatelessWidget {
  const _Ledger({required this.entry, required this.line});

  final MeasurementRow entry;
  final BoqLine? line;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String unit = line?.item.unit ?? '';
    final today = context.read<AppServices>().context.today;
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
    String dim(Qty? value) => value == null ? '—' : bookFigure(value);
    final BoqLine? boq = line;

    return LedgerCard(
      padding: const EdgeInsets.fromLTRB(N360Space.lg, N360Space.sm, N360Space.lg, N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          BookRow.words(label: l.measurementDateLabel, value: dayLabel(context, entry.measuredOn, today)),
          if (boq != null) BookRow(label: l.measurementItem, value: boq.item.code, hint: boq.item.description),
          if (entry.location?.isNotEmpty ?? false)
            BookRow.words(label: l.measurementLocationLabel, value: entry.location!),
          if (entry.description?.isNotEmpty ?? false)
            BookRow.words(label: l.measurementDescriptionLabel, value: entry.description!),
          BookRow(label: l.measurementNos, value: dim(entry.nos)),
          BookRow(label: l.measurementLength, value: dim(entry.length)),
          BookRow(label: l.measurementBreadth, value: dim(entry.breadth)),
          BookRow(label: l.measurementDepth, value: dim(entry.depth)),
          if (formula != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: N360Space.sm),
              child: FittedBox(
                alignment: Alignment.centerLeft,
                fit: BoxFit.scaleDown,
                child: Text(
                  formula,
                  style: N360Type.figureSmall.copyWith(
                    color: entry.isDeduction ? N360Colors.laterite : N360Colors.ink2,
                  ),
                ),
              ),
            ),
          BookRow(
            label: l.measurementQuantity,
            total: true,
            value: '${formatQty(entry.qty)}${unit.isEmpty ? '' : ' $unit'}',
            valueStyle: N360Type.figure.copyWith(fontSize: 22, color: qtyInk),
          ),
          if (boq != null) ...<Widget>[
            BookRow(label: l.measurementBoqQty, value: '${formatQty(boq.item.qty)} $unit'),
            BookRow(label: l.measurementBalance, value: '${formatQty(boq.previewBalance)} $unit', ruled: false),
          ],
        ],
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.ink, required this.title, required this.body});

  final IconData icon;
  final Color ink;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: N360Colors.sheet,
        borderRadius: N360Radius.sm,
        border: Border.all(color: ink, width: 1.25),
      ),
      child: Padding(
        padding: const EdgeInsets.all(N360Space.md),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Icon(icon, size: 24, color: ink),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(title, style: N360Type.bodyStrong.copyWith(color: ink)),
                  Text(body, style: N360Type.body),
                ],
              ),
            ),
          ],
        ),
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
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.paper,
        border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.md),
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
