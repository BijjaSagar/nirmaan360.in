import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../app/widgets/brand_mark.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../core/sync/record_sync.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/measurement_book.dart';
import '../bloc/measurement_list_cubit.dart';
import 'widgets/book_kit.dart';
import 'widgets/sheet_pdf_sheet.dart';

/// The measurement book: one list to a day.
class MeasurementListScreen extends StatelessWidget {
  const MeasurementListScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<MeasurementListCubit>(
      create: (context) => MeasurementListCubit(services: context.read<AppServices>())..start(),
      child: const _BookView(),
    );
  }
}

class _Day {
  const _Day(this.date, this.rows);

  final String date;
  final List<SyncedRecord<MeasurementRow>> rows;
}

class _BookView extends StatelessWidget {
  const _BookView();

  List<_Day> _days(List<SyncedRecord<MeasurementRow>> rows) {
    final List<_Day> days = <_Day>[];
    for (final SyncedRecord<MeasurementRow> row in rows) {
      if (days.isEmpty || days.last.date != row.record.measuredOn) {
        days.add(_Day(row.record.measuredOn, <SyncedRecord<MeasurementRow>>[]));
      }
      days.last.rows.add(row);
    }
    return days;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canGoBack = Navigator.of(context).canPop();
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<MeasurementListCubit, MeasurementListState>(
            builder: (context, state) {
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  BookTitleBar(
                    eyebrow: state.projectName,
                    title: l.measurementBookTitle,
                    onBack: canGoBack ? () => context.pop() : null,
                    leading: canGoBack ? null : const BrandMark(size: 28),
                    trailing: _sheetTile(context, l, state),
                  ),
                  if (!state.online) OfflineBanner(label: l.measurementOffline, detail: l.measurementOfflineDetail),
                  _Filters(state: state),
                  Expanded(child: _body(context, l, state, _days(state.visible))),
                  N360ActionBar(
                    child: GloveButton(
                      key: const ValueKey<String>('measurement-add'),
                      label: l.measurementAddEntry,
                      icon: Icons.add,
                      large: true,
                      fullWidth: true,
                      onPressed: () => context.push(Routes.measurementForm),
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

  /// The website's measurement sheet PDF, on a plan with reports.
  Widget? _sheetTile(BuildContext context, AppLocalizations l, MeasurementListState state) {
    final int? projectId = state.projectId;
    if (projectId == null || !measurementSheetAllowed(context.read<AppServices>())) return null;
    return N360TopTile(
      key: const ValueKey<String>('measurement-sheet-pdf'),
      icon: Icons.picture_as_pdf_outlined,
      label: l.measurementSheetAction,
      onTap: () => showMeasurementSheetPdf(context, projectId: projectId, today: state.today),
    );
  }

  Widget _body(BuildContext context, AppLocalizations l, MeasurementListState state, List<_Day> days) {
    if (state.loading) {
      return ListView.builder(
        padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: 4,
        itemBuilder: (context, index) => Padding(
          padding: const EdgeInsets.only(bottom: N360Space.cardGap),
          child: LedgerSkeleton(flat: true, semanticsLabel: l.measurementLoading),
        ),
      );
    }
    if (days.isEmpty) {
      final bool all = state.filter == null;
      return Center(
        child: SingleChildScrollView(
          child: EmptyLedger(
            title: all ? l.measurementEmptyTitle : l.measurementEmptyFilteredTitle,
            message: all ? l.measurementEmptyBody : l.measurementEmptyFilteredBody,
          ),
        ),
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
      itemCount: days.length,
      itemBuilder: (context, index) {
        final _Day day = days[index];
        return Padding(
          padding: const EdgeInsets.only(bottom: N360Space.cardGap),
          child: StaggeredRise(
            index: index,
            child: LedgerList(
              header: _DayHeader(date: day.date, count: day.rows.length, today: state.today),
              children: <Widget>[
                for (final SyncedRecord<MeasurementRow> row in day.rows)
                  _EntryTile(
                    key: ValueKey<String>(row.record.clientUuid),
                    row: row,
                    boq: state.boq[row.record.boqItemId],
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _DayHeader extends StatelessWidget {
  const _DayHeader({required this.date, required this.count, required this.today});

  final String date;
  final int count;
  final String today;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Expanded(child: Text(dayLabel(context, date, today), style: N360Type.heading)),
        Text('$count', style: N360Type.figureSmall.copyWith(color: N360Colors.ink2)),
      ],
    );
  }
}

/// One entry: the figure first, as a ledger line reads, then where and how.
class _EntryTile extends StatelessWidget {
  const _EntryTile({super.key, required this.row, required this.boq});

  final SyncedRecord<MeasurementRow> row;
  final BoqItemRow? boq;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MeasurementRow entry = row.record;
    final BoqItemRow? item = boq;
    final String qty = '${formatQty(entry.qty)} ${item?.unit ?? ''}'.trim();
    final String code = item?.code ?? '#${entry.boqItemId}';
    final String where = entryTitle(entry, item);
    final String title = where.isEmpty ? code : where;
    final String? factors = bookFactors(
      nos: entry.nos,
      length: entry.length,
      breadth: entry.breadth,
      depth: entry.depth,
      deduction: entry.isDeduction,
    );
    final String? sync = syncWord(l, row.syncState);
    final String meta = <String>[code, ?factors, ?sync].join(' · ');
    final String status = statusWord(l, entry.status);

    return LedgerHero(
      tag: 'measurement-${entry.clientUuid}',
      child: LedgerListTile(
        title: title,
        subtitle: meta,
        semanticsLabel: '$title, $qty, $status, $meta',
        status: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(
              qty,
              maxLines: 1,
              style: N360Type.figure.copyWith(color: entry.qty.isNegative ? N360Colors.laterite : N360Colors.ink),
            ),
            const SizedBox(height: N360Space.xxs),
            StatusPill(kind: stampKindFor(entry.status), label: status),
          ],
        ),
        onTap: () => context.push(Routes.measurementDetail(entry.clientUuid)),
      ),
    );
  }
}

/// What a person calls this entry: where it is, or what it is, or its BOQ line.
String entryTitle(MeasurementRow entry, BoqItemRow? boq) {
  final String? location = entry.location?.trim();
  if (location != null && location.isNotEmpty) return location;
  final String? description = entry.description?.trim();
  if (description != null && description.isNotEmpty) return description;
  return boq?.description ?? '';
}

class _Filters extends StatelessWidget {
  const _Filters({required this.state});

  final MeasurementListState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MeasurementListCubit cubit = context.read<MeasurementListCubit>();
    final List<(String?, String)> filters = <(String?, String)>[
      (null, l.measurementFilterAll),
      (DocStatus.returned, l.statusReturned),
      (DocStatus.draft, l.statusDraft),
      (DocStatus.inReview, l.statusInReview),
      (DocStatus.approved, l.statusApproved),
      (DocStatus.rejected, l.statusRejected),
    ];
    return SizedBox(
      height: N360Size.tap,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
        itemCount: filters.length,
        itemBuilder: (context, index) {
          final (String? status, String label) = filters[index];
          return Padding(
            padding: EdgeInsets.only(right: index == filters.length - 1 ? 0 : N360Space.sm),
            child: UnconstrainedBox(
              child: N360Chip(
                label: label,
                count: '${state.countOf(status)}',
                selected: state.filter == status,
                onTap: () => cubit.setFilter(status),
              ),
            ),
          );
        },
      ),
    );
  }
}
