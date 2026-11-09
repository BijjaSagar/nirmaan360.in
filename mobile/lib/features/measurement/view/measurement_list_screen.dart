import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../core/sync/record_sync.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/measurement_book.dart';
import '../bloc/measurement_list_cubit.dart';
import 'widgets/book_kit.dart';

/// The measurement book: entries in white cards, a card to a day.
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

sealed class _Item {
  const _Item();
}

class _DateHead extends _Item {
  const _DateHead(this.date, this.count);

  final String date;
  final int count;
}

class _Entry extends _Item {
  const _Entry(this.row, {required this.first, required this.last, required this.order});

  final SyncedRecord<MeasurementRow> row;
  final bool first;
  final bool last;

  /// Position among entries, for the entrance stagger.
  final int order;
}

class _BookView extends StatelessWidget {
  const _BookView();

  List<_Item> _items(List<SyncedRecord<MeasurementRow>> rows) {
    final List<_Item> items = <_Item>[];
    var order = 0;
    var index = 0;
    while (index < rows.length) {
      final String date = rows[index].record.measuredOn;
      var end = index;
      while (end < rows.length && rows[end].record.measuredOn == date) {
        end++;
      }
      items.add(_DateHead(date, end - index));
      for (var i = index; i < end; i++) {
        items.add(_Entry(rows[i], first: i == index, last: i == end - 1, order: order++));
      }
      index = end;
    }
    return items;
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canGoBack = Navigator.of(context).canPop();
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          child: BlocBuilder<MeasurementListCubit, MeasurementListState>(
            builder: (context, state) {
              final List<_Item> items = _items(state.visible);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  if (!state.online && canGoBack) OfflineBanner(label: l.measurementOffline, detail: l.measurementOfflineDetail),
                  BookTitleBar(
                    eyebrow: state.projectName,
                    title: l.measurementBookTitle,
                    onBack: canGoBack ? () => context.pop() : null,
                  ),
                  Expanded(child: _body(context, l, state, items)),
                  _Footer(state: state, onAdd: () => context.push(Routes.measurementForm)),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _body(BuildContext context, AppLocalizations l, MeasurementListState state, List<_Item> items) {
    if (state.loading) return const SizedBox.shrink();
    if (items.isEmpty) {
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
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.md),
      itemCount: items.length,
      itemBuilder: (context, index) => switch (items[index]) {
        _DateHead(:final date, :final count) => _DateHeader(date: date, count: count, today: state.today),
        final _Entry entry => StaggeredRise(
          index: entry.order,
          child: _EntryRow(
            key: ValueKey<String>(entry.row.record.clientUuid),
            row: entry.row,
            boq: state.boq[entry.row.record.boqItemId],
            first: entry.first,
            last: entry.last,
          ),
        ),
      },
    );
  }
}

class _DateHeader extends StatelessWidget {
  const _DateHeader({required this.date, required this.count, required this.today});

  final String date;
  final int count;
  final String today;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.xs, N360Space.sm, N360Space.xs, N360Space.sm),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(dayLabel(context, date, today), style: N360Type.heading)),
          Text(l.measurementRowCount(count), style: N360Type.caption),
        ],
      ),
    );
  }
}

/// One row of a day's card. The first and last rows carry the card's corners.
class _EntryRow extends StatelessWidget {
  const _EntryRow({super.key, required this.row, required this.boq, required this.first, required this.last});

  final SyncedRecord<MeasurementRow> row;
  final BoqItemRow? boq;
  final bool first;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MeasurementRow entry = row.record;
    final String unit = boq?.unit ?? '';
    final String qty = '${formatQty(entry.qty)} $unit'.trim();
    final String status = statusWord(l, entry.status);

    return PressTarget(
      onTap: () => context.push(Routes.measurementDetail(entry.clientUuid)),
      haptic: N360Haptics.tap,
      minHeight: 76,
      semanticsLabel: '${boq?.code ?? ''} ${entryTitle(entry, boq)}. $qty. $status',
      builder: (context, depth, child) => Transform.scale(
        scale: pressScale(depth * 0.4),
        child: CustomPaint(
          painter: _GroupRowPainter(first: first, last: last),
          child: child,
        ),
      ),
      child: LedgerHero(
        tag: 'measurement-${entry.clientUuid}',
        child: MeasurementRowFace(row: row, boq: boq),
      ),
    );
  }
}

/// The row's words and figures, shared with the flight into the detail page.
class MeasurementRowFace extends StatelessWidget {
  const MeasurementRowFace({super.key, required this.row, required this.boq});

  final SyncedRecord<MeasurementRow> row;
  final BoqItemRow? boq;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final MeasurementRow entry = row.record;
    final String? factors = bookFactors(
      nos: entry.nos,
      length: entry.length,
      breadth: entry.breadth,
      depth: entry.depth,
      deduction: entry.isDeduction,
    );
    final bool negative = entry.qty.isNegative;
    final Color qtyInk = negative ? N360Colors.laterite : N360Colors.ink;
    final BoqItemRow? item = boq;
    final String? percent = item == null ? null : shareLabel(entry.qty, item.qty);
    final double? fraction = item == null ? null : shareFraction(entry.qty, item.qty);

    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.md, N360Space.md, N360Space.md, 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            children: <Widget>[
              CodeTile(code: item?.code ?? '#${entry.boqItemId}'),
              const SizedBox(width: N360Space.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(
                      entryTitle(entry, item),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.bodyStrong.copyWith(height: 1.3),
                    ),
                    if (factors != null)
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: Alignment.centerLeft,
                        child: Text(
                          factors,
                          maxLines: 1,
                          style: N360Type.figureSmall.copyWith(color: negative ? N360Colors.laterite : N360Colors.ink2),
                        ),
                      ),
                    if (row.isUnsent) ...<Widget>[const SizedBox(height: 2), SyncMark(state: row.syncState)],
                  ],
                ),
              ),
              if (percent != null && fraction != null) ...<Widget>[
                const SizedBox(width: N360Space.sm),
                Semantics(
                  label: '$percent ${l.measurementShareOfBoq}',
                  child: ExcludeSemantics(
                    child: SquarePercent(fraction: fraction, label: percent, size: 52),
                  ),
                ),
              ],
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(left: 64, top: 2),
            child: Row(
              children: <Widget>[
                Expanded(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text.rich(
                      TextSpan(
                        text: formatQty(entry.qty),
                        children: <InlineSpan>[
                          if (item != null)
                            TextSpan(
                              text: ' ${item.unit}',
                              style: N360Type.caption.copyWith(color: qtyInk),
                            ),
                        ],
                      ),
                      style: N360Type.figure.copyWith(color: qtyInk, fontSize: 17),
                    ),
                  ),
                ),
                const SizedBox(width: N360Space.sm),
                RubberStamp(
                  kind: stampKindFor(entry.status),
                  label: statusWord(l, entry.status),
                  size: StampSize.small,
                ),
              ],
            ),
          ),
        ],
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

/// Paints a day's rows as one white card. Rows meet without a seam except for
/// a faint hairline; only the first and last carry the rounded corners.
class _GroupRowPainter extends CustomPainter {
  const _GroupRowPainter({required this.first, required this.last});

  final bool first;
  final bool last;

  static const Radius _corner = Radius.circular(N360Radius.card);

  @override
  void paint(Canvas canvas, Size size) {
    final RRect face = RRect.fromRectAndCorners(
      Offset.zero & size,
      topLeft: first ? _corner : Radius.zero,
      topRight: first ? _corner : Radius.zero,
      bottomLeft: last ? _corner : Radius.zero,
      bottomRight: last ? _corner : Radius.zero,
    );
    canvas.drawRRect(face, Paint()..color = N360Colors.sheet);
    if (!last) {
      canvas.drawLine(
        Offset(N360Space.md, size.height - 0.5),
        Offset(size.width - N360Space.md, size.height - 0.5),
        Paint()
          ..color = N360Colors.rule
          ..strokeWidth = 1,
      );
    }
  }

  @override
  bool shouldRepaint(_GroupRowPainter oldDelegate) => oldDelegate.first != first || oldDelegate.last != last;
}

/// Filter chips and the Add button, down where the thumb is.
class _Footer extends StatelessWidget {
  const _Footer({required this.state, required this.onAdd});

  final MeasurementListState state;
  final VoidCallback onAdd;

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

    return Padding(
      padding: const EdgeInsets.only(top: N360Space.xs, bottom: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          SizedBox(
            height: N360Size.tap,
            child: ListView.builder(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
              itemCount: filters.length,
              itemBuilder: (context, index) {
                final (String? status, String label) = filters[index];
                return Padding(
                  padding: EdgeInsets.only(right: index == filters.length - 1 ? 0 : N360Space.sm),
                  child: ChoiceTile(
                    label: label,
                    count: state.countOf(status),
                    selected: state.filter == status,
                    onTap: () => cubit.setFilter(status),
                  ),
                );
              },
            ),
          ),
          const SizedBox(height: N360Space.sm),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
            child: GloveButton(
              key: const ValueKey<String>('measurement-add'),
              label: l.measurementAddEntry,
              icon: Icons.add,
              large: true,
              fullWidth: true,
              onPressed: onAdd,
            ),
          ),
        ],
      ),
    );
  }
}
