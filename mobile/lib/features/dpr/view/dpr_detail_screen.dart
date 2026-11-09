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

/// One report, read-only: the same ledger sheet, with nothing left to fill in.
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
        return Scaffold(
          body: LedgerPaper(
            child: SafeArea(
              bottom: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  DprTopBar(title: l.dprFormTitle),
                  if (details != null) ...<Widget>[
                    Expanded(child: _sheet(context, state, details)),
                    if (details.dpr.status == DocStatus.returned &&
                        dprOpensForm(details.record))
                      DprBottomAction(
                        label: l.dprCorrectAndSend,
                        icon: Icons.edit_note,
                        onPressed: () => dprOpenForm(context, uuid: details.dpr.clientUuid),
                      ),
                  ] else if (!state.loading)
                    Expanded(
                      child: Center(child: EmptyLedger(title: l.dprEmptyTitle)),
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _sheet(BuildContext context, DprDetailState state, DprDetails details) {
    final l = AppLocalizations.of(context);
    final services = context.read<AppServices>();
    final DprRow dpr = details.dpr;
    final SyncedRecord<DprRow> record = details.record;
    final items = <Widget>[];

    void slice(Widget child, {double top = 0, double bottom = 0}) {
      items.add(DprSheetSlice(first: items.isEmpty, top: top, bottom: bottom, child: child));
    }

    void head(int number, String title, {Widget? trailing}) {
      slice(DprSectionHead(number: number, title: title, trailing: trailing), top: N360Space.xl);
    }

    // Masthead.
    slice(
      Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (state.project != null) SectionLabel(state.project!.name),
          LedgerHero(
            tag: dprHeroTag(dpr.clientUuid),
            child: DprReportFace(record: record, large: true),
          ),
          const SizedBox(height: N360Space.sm),
          const SizedBox(height: 1.5, child: ColoredBox(color: N360Colors.ink)),
          const SizedBox(height: 2),
          const RuledDivider(),
        ],
      ),
      top: N360Space.md,
    );

    // Where it stands, honestly.
    if (record.syncState == LocalSyncState.attention) {
      slice(
        ErrorSlip(title: l.dprSyncAttention, message: l.dprAttentionBody),
        top: N360Space.md,
      );
    } else if (record.syncState != LocalSyncState.synced) {
      slice(
        PaperToast(
          message: state.online ? l.dprSavedSendingNow : l.dprSavedWillSend,
          kind: PaperToastKind.info,
          icon: state.online ? Icons.sync : Icons.cloud_off,
        ),
        top: N360Space.md,
      );
    }

    final String? remark = dpr.returnRemark;
    if (remark != null && remark.isNotEmpty) {
      slice(
        DprRemarkSlip(title: l.dprApproverRemark, message: remark),
        top: N360Space.md,
      );
    } else if (dpr.status == DocStatus.returned) {
      slice(
        DprRemarkSlip(title: l.dprReturnedTitle, message: l.dprReturnedNoRemark),
        top: N360Space.md,
      );
    }

    // 1. Weather.
    head(1, l.dprSectionWeather);
    final String? weather = dpr.weather;
    slice(
      weather == null
          ? Padding(
              padding: const EdgeInsets.only(top: N360Space.sm),
              child: Text(l.dprNothingRecorded, style: N360Type.body),
            )
          : Padding(
              padding: const EdgeInsets.only(top: N360Space.sm),
              child: Row(
                children: <Widget>[
                  Icon(N360Pictograms.weather(weather).icon, size: 30, color: N360Colors.ink),
                  const SizedBox(width: N360Space.sm),
                  Text(l.pictogramLabel(N360Pictograms.weather(weather)), style: N360Type.bodyLarge),
                ],
              ),
            ),
    );

    // 2. Workers.
    final int total = dprWorkerTotal(dpr);
    head(
      2,
      l.dprSectionWorkers,
      trailing: Text('$total', style: N360Type.figureLarge),
    );
    final List<ManpowerLine> crew = dpr.manpower.toList()
      ..sort((a, b) => b.count.compareTo(a.count));
    if (crew.isEmpty) {
      slice(Text(l.dprNothingRecorded, style: N360Type.body), top: N360Space.sm);
    } else {
      for (var i = 0; i < crew.length; i++) {
        final ManpowerLine line = crew[i];
        final Pictogram picture = N360Pictograms.trade(line.trade);
        slice(
          LedgerRow(
            label: l.pictogramLabel(picture),
            value: '${line.count}',
            ruled: i != crew.length - 1,
          ),
        );
      }
      slice(
        LedgerRow(label: l.dprTotalWorkers, value: '$total', total: true),
      );
    }

    // 3. Work done.
    head(3, l.dprSectionWork);
    if (dpr.workItems.isEmpty) {
      slice(Text(l.dprNothingRecorded, style: N360Type.body), top: N360Space.sm);
    } else {
      for (var i = 0; i < dpr.workItems.length; i++) {
        final WorkLine line = dpr.workItems[i];
        final BoqLine? boq = line.boqItemId == null ? null : state.boq[line.boqItemId];
        slice(
          LedgerRow(
            label: boq?.item.description ?? line.description ?? '',
            hint: boq?.item.code,
            value: '${dprQty(line.qty)} ${boq?.item.unit ?? ''}'.trim(),
            valueStyle: N360Type.figure.copyWith(fontSize: 17),
            ruled: i != dpr.workItems.length - 1,
          ),
        );
      }
    }

    // 4. Photos.
    head(
      4,
      l.dprSectionPhotos,
      trailing: details.photos.isEmpty
          ? null
          : Text(l.dprPhotosCount(details.photos.length), style: N360Type.label),
    );
    if (details.photos.isEmpty) {
      slice(Text(l.dprNothingRecorded, style: N360Type.body), top: N360Space.sm);
    } else {
      slice(
        DprPhotoStrip(
          photos: details.photos,
          repository: services.dpr,
          landed: <Object>{},
          onRemove: null,
        ),
        top: N360Space.sm,
      );
    }

    // 5. Hindrances and notes.
    final String hindrances = (dpr.hindrances ?? '').trim();
    final String notes = (dpr.summary ?? '').trim();
    head(5, l.dprSectionNotes);
    if (hindrances.isEmpty && notes.isEmpty) {
      slice(Text(l.dprNothingRecorded, style: N360Type.body), top: N360Space.sm);
    } else {
      if (hindrances.isNotEmpty) {
        slice(
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(l.dprHindrances, style: N360Type.label),
              Text(hindrances, style: N360Type.bodyLarge),
            ],
          ),
          top: N360Space.sm,
        );
      }
      if (notes.isNotEmpty) {
        slice(
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(l.dprNotes, style: N360Type.label),
              Text(notes, style: N360Type.bodyLarge),
            ],
          ),
          top: N360Space.sm,
        );
      }
    }

    items.add(const DprSheetSlice(last: true, child: SizedBox(height: N360Space.lg)));

    return ListView.builder(
      padding: const EdgeInsets.only(bottom: N360Space.md),
      itemCount: items.length,
      itemBuilder: (context, index) => items[index],
    );
  }
}
