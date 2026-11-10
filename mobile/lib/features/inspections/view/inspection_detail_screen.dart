import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show TintedNote, dayLabel;
import '../../sync_center/data/sync_repository.dart' show OutboxItem;
import '../../sync_center/view/sync_labels.dart';
import '../bloc/inspections_cubit.dart';
import '../data/inspection_repository.dart';
import 'inspection_words.dart';

/// One inspection with every item and its result in words. It is read only: the
/// server locks an inspection once it is completed and refuses any change.
class InspectionDetailScreen extends StatelessWidget {
  const InspectionDetailScreen({super.key, required this.inspectionKey});

  /// The server's id, or the uuid of one finished here and waiting to be sent.
  final String inspectionKey;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<InspectionDetailCubit>(
      create: (context) => InspectionDetailCubit(context.read<AppServices>(), inspectionKey),
      child: const _DetailView(),
    );
  }
}

class _DetailView extends StatelessWidget {
  const _DetailView();

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<InspectionDetailCubit, InspectionDetailState>(
            builder: (context, state) {
              final Inspection? inspection = state.inspection;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(
                    title: inspection?.title ?? l.inspectionsTitle,
                    subtitle: inspection == null || inspection.inspectedOn.isEmpty
                        ? null
                        : dayLabel(context, inspection.inspectedOn, today),
                  ),
                  Expanded(
                    child: !state.loaded
                        ? const SizedBox.shrink()
                        : inspection == null
                        ? EmptyLedger(
                            title: state.refused ? l.inspectionsRefusedTitle : l.inspectionGoneTitle,
                            message: state.refused ? l.inspectionsRefusedMessage : l.inspectionNotOnPhone,
                          )
                        : _Body(inspection: inspection),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.inspection});

  final Inspection inspection;

  String? _refusal(AppLocalizations l) {
    final OutboxRow? outbox = inspection.unsent;
    if (outbox == null || !inspection.needsAttention) return null;
    if (outbox.attentionCode == AttentionCode.validation && (outbox.attentionReason?.isNotEmpty ?? false)) {
      return outbox.attentionReason;
    }
    return l.syncReasonFor(OutboxItem(row: outbox, isBlocked: false));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? refusal = _refusal(l);
    final String? category = l.checklistCategoryWord(inspection.category);
    final String summary = <String>[
      if (inspection.count(ItemResult.pass) > 0) '${l.inspectionResultPass} ${inspection.count(ItemResult.pass)}',
      if (inspection.count(ItemResult.fail) > 0) '${l.inspectionResultFail} ${inspection.count(ItemResult.fail)}',
      if (inspection.count(ItemResult.na) > 0) '${l.inspectionResultNa} ${inspection.count(ItemResult.na)}',
      if (inspection.count(ItemResult.pending) > 0) '${l.inspectionResultPending} ${inspection.count(ItemResult.pending)}',
    ].join(' · ');

    return ListView(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.lg, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        LedgerCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Align(
                alignment: Alignment.centerLeft,
                child: StatusPill(
                  key: const ValueKey<String>('inspection-status'),
                  kind: inspectionStampKind(inspection),
                  label: l.inspectionStatusWord(inspection),
                ),
              ),
              if (category != null || inspection.location != null) ...<Widget>[
                const SizedBox(height: N360Space.sm),
                Text(
                  <String>[?category, ?inspection.location].join(' · '),
                  style: N360Type.fit(N360Type.body, inspection.location ?? category ?? ''),
                ),
              ],
              if (summary.isNotEmpty) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Text(summary, style: N360Type.fit(N360Type.label, summary)),
              ],
            ],
          ),
        ),
        const SizedBox(height: N360Space.cardGap),
        if (refusal != null) ...<Widget>[
          ErrorSlip(
            title: l.shellPillAttention,
            message: refusal,
            actionLabel: l.inspectionOpenOutbox,
            onAction: () => context.go(Routes.outbox),
          ),
          const SizedBox(height: N360Space.cardGap),
        ] else if (inspection.notSent) ...<Widget>[
          TintedNote(icon: Icons.schedule, ink: N360Colors.ink2, body: l.inspectionWaitingNote),
          const SizedBox(height: N360Space.cardGap),
        ] else if (inspection.isOpen) ...<Widget>[
          TintedNote(icon: Icons.info_outline, ink: N360Colors.ink2, body: l.inspectionOpenNote),
          const SizedBox(height: N360Space.cardGap),
        ],
        if (inspection.items.isEmpty)
          Text(l.inspectionItemsNotLoaded, style: N360Type.fit(N360Type.body, l.inspectionItemsNotLoaded))
        else
          LedgerList(
            children: <Widget>[for (final InspectionItem item in inspection.items) _ItemRow(item: item)],
          ),
      ],
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({required this.item});

  final InspectionItem item;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String word = l.inspectionResultWord(item.result);
    return Semantics(
      container: true,
      label: '${item.position}. ${item.text}. $word${item.remark == null ? '' : '. ${item.remark}'}',
      child: ExcludeSemantics(
        child: Padding(
          key: ValueKey<String>('inspection-row-${item.position}'),
          padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  SizedBox(width: 30, child: Text('${item.position}.', style: N360Type.figureSmall)),
                  Expanded(child: Text(item.text, style: N360Type.fit(N360Type.bodyStrong, item.text))),
                ],
              ),
              const SizedBox(height: N360Space.xs),
              Padding(
                padding: const EdgeInsets.only(left: 30),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: StatusPill(kind: itemStampKind(item.result), label: word),
                ),
              ),
              if (item.remark != null) ...<Widget>[
                const SizedBox(height: N360Space.xs),
                Padding(
                  padding: const EdgeInsets.only(left: 30),
                  child: Text(item.remark!, style: N360Type.fit(N360Type.body, item.remark!)),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
