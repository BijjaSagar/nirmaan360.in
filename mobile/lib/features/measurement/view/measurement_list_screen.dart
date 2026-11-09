import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/db/app_database.dart';
import '../../../core/design/design.dart';
import '../../../core/design/internal/press_target.dart';
import '../../../core/sync/record_sync.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../bloc/measurement_book.dart';
import '../bloc/measurement_list_cubit.dart';
import 'widgets/book_kit.dart';

/// The measurement book: entries by date, in ledger columns.
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

  void _add(BuildContext context) => context.push(Routes.measurementForm);

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: LedgerPaper(
        child: SafeArea(
          child: BlocBuilder<MeasurementListCubit, MeasurementListState>(
            builder: (context, state) {
              final List<SyncedRecord<MeasurementRow>> visible = state.visible;
              final List<_Item> items = _items(visible);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  if (!state.online) OfflineBanner(label: l.measurementOffline, detail: l.measurementOfflineDetail),
                  _Title(state: state),
                  Expanded(child: _body(context, l, state, items)),
                  _Footer(state: state, onAdd: () => _add(context)),
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
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.xs, N360Space.gutter, N360Space.md),
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

class _Title extends StatelessWidget {
  const _Title({required this.state});

  final MeasurementListState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool canGoBack = Navigator.of(context).canPop();
    return Padding(
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          if (canGoBack) ...<Widget>[LedgerBackKey(onTap: () => context.pop()), const SizedBox(width: N360Space.sm)],
          Expanded(
            child: SheetHeader(eyebrow: state.projectName, title: l.measurementBookTitle),
          ),
        ],
      ),
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
      padding: const EdgeInsets.only(top: N360Space.md, bottom: N360Space.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: <Widget>[
          Text(dayLabel(context, date, today), style: N360Type.heading),
          const SizedBox(width: N360Space.sm),
          Text(l.measurementRowCount(count), style: N360Type.caption),
        ],
      ),
    );
  }
}

/// One row of the book. A narrow column for the BOQ code, a double red rule,
/// then the description and formula with the quantity ruled off at the right.
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
      builder: (context, depth, child) {
        return CustomPaint(
          painter: _RowPainter(first: first, last: last),
          child: Padding(
            padding: EdgeInsets.only(bottom: last ? N360Size.edge : 0),
            child: Transform.translate(offset: Offset(0, depth * 1.5), child: child),
          ),
        );
      },
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
    final String code = boq?.code ?? '#${entry.boqItemId}';

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SizedBox(
            width: 52,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.sm, N360Space.md, 2, N360Space.sm),
              child: Text(
                code,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: N360Type.figureSmall.copyWith(fontWeight: FontWeight.w500),
              ),
            ),
          ),
          const _MarginRule(),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(N360Space.sm, N360Space.sm, N360Space.sm, 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Expanded(
                        child: Text(
                          entryTitle(entry, boq),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: N360Type.bodyStrong.copyWith(height: 1.32),
                        ),
                      ),
                      const SizedBox(width: N360Space.sm),
                      Text.rich(
                        TextSpan(
                          text: formatQty(entry.qty),
                          children: <InlineSpan>[
                            if (boq != null)
                              TextSpan(
                                text: ' ${boq!.unit}',
                                style: N360Type.caption.copyWith(color: qtyInk),
                              ),
                          ],
                        ),
                        style: N360Type.figure.copyWith(color: qtyInk, fontSize: 17),
                      ),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: <Widget>[
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: <Widget>[
                            if (factors != null)
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerLeft,
                                child: Text(
                                  factors,
                                  maxLines: 1,
                                  style: N360Type.figureSmall.copyWith(
                                    color: negative ? N360Colors.laterite : N360Colors.ink2,
                                  ),
                                ),
                              ),
                            if (row.isUnsent) ...<Widget>[const SizedBox(height: 3), SyncMark(state: row.syncState)],
                          ],
                        ),
                      ),
                      const SizedBox(width: N360Space.xs),
                      RubberStamp(
                        kind: stampKindFor(entry.status),
                        label: statusWord(l, entry.status),
                        size: StampSize.small,
                      ),
                    ],
                  ),
                ],
              ),
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

class _MarginRule extends StatelessWidget {
  const _MarginRule();

  @override
  Widget build(BuildContext context) {
    return const SizedBox(
      width: 4,
      child: Row(
        children: <Widget>[
          SizedBox(width: 1, child: ColoredBox(color: N360Colors.marginRed)),
          SizedBox(width: 2),
          SizedBox(width: 1, child: ColoredBox(color: N360Colors.marginRed)),
        ],
      ),
    );
  }
}

class _RowPainter extends CustomPainter {
  const _RowPainter({required this.first, required this.last});

  final bool first;
  final bool last;

  @override
  void paint(Canvas canvas, Size size) {
    const edge = N360Size.edge;
    final double bottom = last ? size.height - edge : size.height;
    final Rect face = Rect.fromLTRB(0, 0, size.width, bottom);
    if (last) {
      canvas.drawRect(Rect.fromLTRB(0, edge, size.width, size.height), Paint()..color = N360Colors.paperEdge);
    }
    canvas.drawRect(face, Paint()..color = N360Colors.sheet);

    final Paint side = Paint()
      ..color = N360Colors.rule
      ..strokeWidth = 1;
    canvas.drawLine(const Offset(0.5, 0), Offset(0.5, bottom), side);
    canvas.drawLine(Offset(size.width - 0.5, 0), Offset(size.width - 0.5, bottom), side);
    if (first) canvas.drawLine(const Offset(0, 0.5), Offset(size.width, 0.5), side);
    canvas.drawLine(Offset(0, bottom - 0.5), Offset(size.width, bottom - 0.5), side);
  }

  @override
  bool shouldRepaint(_RowPainter oldDelegate) => oldDelegate.first != first || oldDelegate.last != last;
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

    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.paper,
        border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
      ),
      child: Padding(
        padding: const EdgeInsets.only(top: N360Space.sm, bottom: N360Space.sm),
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
                    child: _FilterChip(
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
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({required this.label, required this.count, required this.selected, required this.onTap});

  final String label;
  final int count;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final Color ink = selected ? N360Colors.sheet : N360Colors.ink;
    return PressTarget(
      onTap: onTap,
      haptic: N360Haptics.select,
      minWidth: 72,
      selected: selected,
      semanticsLabel: '$label $count',
      builder: (context, depth, child) => CustomPaint(
        painter: SheetEdgePainter(
          sink: depth * (N360Size.edge - 1),
          fill: selected ? N360Colors.ink : N360Colors.sheet,
          edge: selected ? N360Colors.inkEdge : N360Colors.paperEdge,
          border: selected ? N360Colors.ink : N360Colors.ruleStrong,
        ),
        child: Padding(
          padding: const EdgeInsets.only(bottom: N360Size.edge),
          child: Transform.translate(offset: Offset(0, depth * (N360Size.edge - 1)), child: child),
        ),
      ),
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: N360Size.tap - N360Size.edge),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: N360Space.md),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              Text(label, style: N360Type.bodyStrong.copyWith(color: ink, height: 1.3)),
              const SizedBox(width: 6),
              Text('$count', style: N360Type.figureSmall.copyWith(color: ink)),
            ],
          ),
        ),
      ),
    );
  }
}
