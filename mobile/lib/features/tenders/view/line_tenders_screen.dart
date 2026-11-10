import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../../measurement/view/widgets/book_kit.dart' show dayLabel;
import '../bloc/line_tenders_cubit.dart';
import '../data/tender_repository.dart';
import 'tender_sheets.dart';

/// A BOQ line's tenders: who quoted what, and, for those allowed, starting a
/// tender, adding a quote and choosing the contractor.
class LineTendersScreen extends StatelessWidget {
  const LineTendersScreen({super.key, required this.boqItemId});

  final int boqItemId;

  @override
  Widget build(BuildContext context) {
    return BlocProvider<LineTendersCubit>(
      create: (context) => LineTendersCubit(context.read<AppServices>(), boqItemId: boqItemId),
      child: const _LineTendersView(),
    );
  }
}

/// The status word and its pill.
StampKind tenderStampKind(String status) => switch (status) {
  'AWARDED' => StampKind.approved,
  'CANCELLED' => StampKind.rejected,
  _ => StampKind.inReview,
};

String tenderStatusWord(AppLocalizations l, String status) => switch (status) {
  'AWARDED' => l.tenderStatusAwarded,
  'CANCELLED' => l.tenderStatusCancelled,
  _ => l.tenderStatusOpen,
};

/// "+4.5" or "-3.0": the server's percent with a sign, so a cheaper quote reads as one.
String signedPct(String pct) => pct.startsWith('-') || pct.startsWith('+') ? pct : '+$pct';

class _LineTendersView extends StatelessWidget {
  const _LineTendersView();

  Future<void> _newTender(BuildContext context, LineTenders line) async {
    final LineTendersCubit cubit = context.read<LineTendersCubit>();
    final bool? started = await showLedgerSheet<bool>(
      context,
      isScrollControlled: true,
      builder: (_) => NewTenderSheet(
        repository: cubit.repository,
        boqItemId: line.boqItemId,
        suggestedTitle: '${line.code} · ${line.description}',
        today: context.read<AppServices>().context.today,
      ),
    );
    if (started == true) await cubit.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<LineTendersCubit, LineTendersState>(
            builder: (context, state) {
              final LineTenders? line = state.line;
              final bool canStart = context.read<LineTendersCubit>().repository.canStart;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(title: l.tenderTitle, subtitle: line?.code),
                  Expanded(
                    child: LedgerRefresh(
                      onRefresh: () => context.read<LineTendersCubit>().refresh(),
                      semanticsLabel: l.tenderLoading,
                      child: _body(context, l, state),
                    ),
                  ),
                  if (line != null && canStart)
                    FieldActionBar(
                      child: GloveButton(
                        key: const ValueKey<String>('tender-new'),
                        label: l.tenderNew,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => _newTender(context, line),
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

  Widget _body(BuildContext context, AppLocalizations l, LineTendersState state) {
    Widget calm(String title, String message) => ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: <Widget>[EmptyLedger(title: title, message: message)],
    );
    final LineTenders? line = state.line;
    if (line == null) {
      return switch (state.load) {
        LineTendersLoad.loading => ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(N360Space.gutter),
          children: <Widget>[LedgerSkeletonList(lines: 3, semanticsLabel: l.tenderLoading)],
        ),
        LineTendersLoad.offline => calm(l.tenderOfflineTitle, l.tenderOfflineMessage),
        LineTendersLoad.refused => calm(l.tenderRefusedTitle, l.tenderRefusedMessage),
        _ => calm(l.tenderTitle, l.tenderLoadFailed),
      };
    }
    final bool seesMoney = context.read<LineTendersCubit>().repository.seesMoney;
    final bool canStart = context.read<LineTendersCubit>().repository.canStart;
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(N360Space.gutter, N360Space.sm, N360Space.gutter, N360Space.lg),
      children: <Widget>[
        _LineHeader(line: line, seesMoney: seesMoney),
        const SizedBox(height: N360Space.md),
        if (line.tenders.isEmpty)
          EmptyLedger(title: l.tenderNoneTitle, message: canStart ? l.tenderNoneStart : l.tenderNoneAsk)
        else
          for (final LineTender tender in line.tenders) ...<Widget>[
            _TenderCard(line: line, tender: tender, seesMoney: seesMoney),
            const SizedBox(height: N360Space.md),
          ],
      ],
    );
  }
}

class _LineHeader extends StatelessWidget {
  const _LineHeader({required this.line, required this.seesMoney});

  final LineTenders line;
  final bool seesMoney;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String qty = l.tenderBoqQty(line.qty.format(), line.unit);
    final Money? rate = line.boqRate;
    final Money? amount = line.boqAmount;
    final Money? lowest = line.lowestRate;
    final String? lowestBy = lowest == null
        ? null
        : (line.lowestContractor == null
              ? l.tenderLowest(l.tenderPerUnit(lowest.inr(), line.unit))
              : l.tenderLowestBy(l.tenderPerUnit(lowest.inr(), line.unit), line.lowestContractor!));
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (line.code.isNotEmpty) Text(line.code, style: N360Type.fit(N360Type.caption, line.code)),
          Text(line.description, style: N360Type.fit(N360Type.bodyStrong, line.description)),
          const SizedBox(height: N360Space.xs),
          Text(qty, style: N360Type.fit(N360Type.caption, qty)),
          if (seesMoney && rate != null)
            Text(
              <String>[l.tenderBoqRate(rate.inr()), if (amount != null) l.tenderBoqAmount(amount.inr())].join(' · '),
              style: N360Type.caption,
            ),
          if (lowestBy != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(lowestBy, style: N360Type.fit(N360Type.bodyStrong, lowestBy).copyWith(color: N360Colors.laterite)),
          ],
          if (line.noQuantity) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(l.tenderNoQuantity, style: N360Type.fit(N360Type.caption, l.tenderNoQuantity)),
          ],
          if (!seesMoney && line.quoteCount > 0) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(l.tenderMoneyHidden, style: N360Type.fit(N360Type.caption, l.tenderMoneyHidden)),
          ],
        ],
      ),
    );
  }
}

class _TenderCard extends StatelessWidget {
  const _TenderCard({required this.line, required this.tender, required this.seesMoney});

  final LineTenders line;
  final LineTender tender;
  final bool seesMoney;

  Future<void> _addQuote(BuildContext context) async {
    final LineTendersCubit cubit = context.read<LineTendersCubit>();
    await context.push(Routes.tenderQuote(line.boqItemId, tender.id));
    if (!cubit.isClosed) await cubit.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final String? due = tender.dueOn;
    final String meta = <String>[
      ?tender.number,
      if (due != null) l.tenderDue(dayLabel(context, due, today)),
    ].join(' · ');
    final int others = tender.lines.where((TenderLine row) => row.boqItemId != line.boqItemId).length;
    final String? workOrder = tender.workOrderNumber;
    final int? workOrderId = tender.workOrderId;
    final AppServices services = context.read<AppServices>();
    final bool canOpenOrder = workOrderId != null && services.auth.can('contractor.view');

    return LedgerCard(
      key: ValueKey<String>('tender-${tender.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Expanded(child: Text(tender.title, style: N360Type.fit(N360Type.heading, tender.title))),
              const SizedBox(width: N360Space.sm),
              StatusPill(kind: tenderStampKind(tender.status), label: tenderStatusWord(l, tender.status)),
            ],
          ),
          if (meta.isNotEmpty) Text(meta, style: N360Type.fit(N360Type.caption, meta)),
          if (others > 0) Text(l.tenderOtherLines(others), style: N360Type.caption),
          if (workOrder != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            if (canOpenOrder)
              Align(
                alignment: Alignment.centerLeft,
                child: GloveButton(
                  key: ValueKey<String>('tender-${tender.id}-work-order'),
                  label: l.tenderWorkOrder(workOrder),
                  icon: Icons.handshake_outlined,
                  kind: GloveButtonKind.ghost,
                  onPressed: () => context.push(Routes.contractorDetail(workOrderId)),
                ),
              )
            else
              Text(l.tenderWorkOrder(workOrder), style: N360Type.bodyStrong),
          ],
          const SizedBox(height: N360Space.sm),
          const RuledDivider(),
          if (tender.quotes.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: N360Space.md),
              child: Text(l.tenderQuotesNone, style: N360Type.fit(N360Type.body, l.tenderQuotesNone)),
            )
          else
            for (final (int index, TenderQuote quote) in tender.quotes.indexed) ...<Widget>[
              if (index > 0) const RuledDivider(),
              _QuoteRow(line: line, tender: tender, quote: quote, seesMoney: seesMoney),
            ],
          if (line.canQuote && tender.isOpen) ...<Widget>[
            const RuledDivider(),
            const SizedBox(height: N360Space.sm),
            GloveButton(
              key: ValueKey<String>('tender-${tender.id}-add-quote'),
              label: l.tenderAddQuote,
              icon: Icons.post_add,
              kind: GloveButtonKind.secondary,
              fullWidth: true,
              onPressed: () => _addQuote(context),
            ),
          ],
        ],
      ),
    );
  }
}

class _QuoteRow extends StatelessWidget {
  const _QuoteRow({required this.line, required this.tender, required this.quote, required this.seesMoney});

  final LineTenders line;
  final LineTender tender;
  final TenderQuote quote;
  final bool seesMoney;

  Future<void> _select(BuildContext context) async {
    final LineTendersCubit cubit = context.read<LineTendersCubit>();
    await context.push(Routes.tenderAward(line.boqItemId, tender.id, quote.bidId));
    if (!cubit.isClosed) await cubit.refresh();
  }

  void _viewQuotation(BuildContext context) {
    final LineTendersCubit cubit = context.read<LineTendersCubit>();
    showLedgerSheet<void>(
      context,
      isScrollControlled: true,
      builder: (_) => QuotationSheet(load: cubit.repository.quotation(quote.bidId), name: quote.quotationName),
    );
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final Color ink = quote.current ? N360Colors.ink : N360Colors.ink3;
    final Money? rate = quote.rate;
    final Money? amount = quote.amount;
    final String? pct = quote.diffPct;
    final String? received = quote.receivedOn;
    final String? valid = quote.validUntil;
    final int? days = quote.completionDays;
    final bool? material = quote.includesMaterial;
    final String facts = <String>[
      if (received != null) l.tenderReceived(dayLabel(context, received, today)),
      if (valid != null) l.tenderValidUntil(dayLabel(context, valid, today)),
      if (material != null) material ? l.tenderMaterialIn : l.tenderMaterialOut,
      if (days != null) l.tenderDays(days),
    ].join(' · ');
    final String? terms = quote.paymentTerms;
    final String? remarks = quote.remarks;
    final String? enteredBy = quote.enteredBy;
    final bool canSelect = line.canAward && tender.isOpen && quote.current;

    return Padding(
      key: ValueKey<String>('quote-${quote.bidId}'),
      padding: const EdgeInsets.symmetric(vertical: N360Space.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Wrap(
            spacing: N360Space.sm,
            runSpacing: N360Space.xs,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: <Widget>[
              Text(quote.contractor, style: N360Type.fit(N360Type.bodyStrong, quote.contractor).copyWith(color: ink)),
              if (quote.selected) StatusPill(kind: StampKind.approved, label: l.tenderTagSelected),
              if (quote.lowest && quote.current && !quote.selected) StatusPill(kind: StampKind.synced, label: l.tenderTagLowest),
              if (!quote.current) StatusPill(kind: StampKind.draft, label: l.tenderTagReplaced),
            ],
          ),
          if (seesMoney && rate != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: <Widget>[
                Expanded(
                  child: Text(
                    l.tenderPerUnit(rate.inr(), line.unit),
                    style: N360Type.figure.copyWith(color: ink),
                  ),
                ),
                if (amount != null) Text(amount.inr(), style: N360Type.figureSmall.copyWith(color: ink)),
              ],
            ),
            if (pct != null)
              Text(
                l.tenderVsBoq(signedPct(pct)),
                style: N360Type.caption.copyWith(color: pct.startsWith('-') ? N360Colors.mossInk : N360Colors.stampRed),
              ),
          ] else if (seesMoney && amount != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(amount.inr(), style: N360Type.figure.copyWith(color: ink)),
          ],
          if (facts.isNotEmpty) ...<Widget>[
            const SizedBox(height: N360Space.xxs),
            Text(facts, style: N360Type.fit(N360Type.caption, facts)),
          ],
          if (terms != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text('${l.tenderPaymentTerms}: $terms', style: N360Type.fit(N360Type.body, terms)),
          ],
          if (remarks != null) ...<Widget>[
            const SizedBox(height: N360Space.xxs),
            Text('${l.tenderRemarks}: $remarks', style: N360Type.fit(N360Type.body, remarks)),
          ],
          if (enteredBy != null) Text(l.tenderEnteredBy(enteredBy), style: N360Type.caption),
          if ((seesMoney && quote.hasQuotation) || canSelect) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Wrap(
              spacing: N360Space.sm,
              runSpacing: N360Space.sm,
              children: <Widget>[
                if (seesMoney && quote.hasQuotation)
                  GloveButton(
                    key: ValueKey<String>('quote-${quote.bidId}-file'),
                    label: l.tenderViewQuotation,
                    icon: Icons.description_outlined,
                    kind: GloveButtonKind.ghost,
                    onPressed: () => _viewQuotation(context),
                  ),
                if (canSelect)
                  GloveButton(
                    key: ValueKey<String>('quote-${quote.bidId}-select'),
                    label: l.tenderSelect,
                    icon: Icons.check_circle_outline,
                    kind: GloveButtonKind.secondary,
                    onPressed: () => _select(context),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The quotation file. A photo shows here; a PDF goes to another app.
class QuotationSheet extends StatelessWidget {
  const QuotationSheet({super.key, required this.load, this.name});

  final Future<Uint8List> load;
  final String? name;

  Future<void> _openElsewhere(Uint8List bytes) async {
    final String dir = (await getTemporaryDirectory()).path;
    final String fileName = name == null || name!.isEmpty ? 'quotation.pdf' : p.basename(name!);
    final File file = File(p.join(dir, fileName));
    await file.writeAsBytes(bytes, flush: true);
    await SharePlus.instance.share(ShareParams(files: <XFile>[XFile(file.path)], title: fileName));
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    Widget message(String text) => Padding(
      padding: const EdgeInsets.all(N360Space.gutter),
      child: Text(text, style: N360Type.fit(N360Type.body, text)),
    );
    final bool looksPdf = (name ?? '').toLowerCase().endsWith('.pdf');
    return SafeArea(
      top: false,
      child: FutureBuilder<Uint8List>(
        future: load,
        builder: (context, snapshot) {
          if (snapshot.hasError) return message(l.tenderQuotationFailed);
          final Uint8List? bytes = snapshot.data;
          if (bytes == null) {
            return Padding(
              padding: const EdgeInsets.all(N360Space.gutter),
              child: LedgerSkeletonList(semanticsLabel: l.tenderLoading),
            );
          }
          final Widget elsewhere = Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              message(l.tenderQuotationNotPicture),
              Padding(
                padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.lg),
                child: GloveButton(
                  key: const ValueKey<String>('quotation-open-elsewhere'),
                  label: l.tenderOpenElsewhere,
                  icon: Icons.open_in_new,
                  fullWidth: true,
                  onPressed: () => _openElsewhere(bytes),
                ),
              ),
            ],
          );
          if (looksPdf) return elsewhere;
          return ConstrainedBox(
            constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(N360Space.gutter, 0, N360Space.gutter, N360Space.lg),
              child: Image.memory(bytes, fit: BoxFit.contain, errorBuilder: (_, _, _) => elsewhere),
            ),
          );
        },
      ),
    );
  }
}
