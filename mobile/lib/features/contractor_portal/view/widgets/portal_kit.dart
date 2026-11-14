import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/app_format.dart';
import '../../../../app/routes.dart';
import '../../../../core/app_services.dart';
import '../../../../core/design/design.dart';
import '../../../../core/money/money.dart';
import '../../../../l10n/generated/app_localizations.dart';
import '../../../attendance/view/widgets/field_kit.dart' show FieldTopBar;
import '../../../contractor_control/view/widgets/contractor_kit.dart' show ContractorWords;
import '../../../measurement/view/widgets/book_kit.dart';
import '../../../site_progress/data/progress_models.dart' show percentText;
import '../../../site_progress/view/widgets/progress_widgets.dart' show percentFraction;
import '../../bloc/portal_cubit.dart';
import '../../data/portal_models.dart';

/// A page of the portal: the top bar, pull to refresh, and the three states
/// every page shares (loading, nothing on the phone yet, switched off). The
/// page itself only says what it shows once it has something to show.
class PortalPage extends StatelessWidget {
  const PortalPage({
    super.key,
    required this.title,
    required this.body,
    this.subtitle,
    this.root = false,
    this.available,
    this.footer,
  });

  /// The bar's title, which may depend on what has been read.
  final String Function(BuildContext context, PortalSnapshot? snapshot) title;
  final String? Function(BuildContext context, PortalSnapshot? snapshot)? subtitle;

  /// Home has no way back: it is where a contractor lives. It carries Settings instead.
  final bool root;

  /// The rows of the page, given a copy that is open to this login.
  final List<Widget> Function(BuildContext context, PortalSnapshot snapshot) body;

  /// False when this copy does not hold the record the page is about.
  final bool Function(PortalSnapshot snapshot)? available;
  final Widget? footer;

  Future<void> _refresh(BuildContext context) async {
    final AppLocalizations l = AppLocalizations.of(context);
    final bool reached = await context.read<PortalCubit>().refresh();
    if (reached || !context.mounted) return;
    showPaperToast(context, l.progressRefreshFailed, kind: PaperToastKind.info);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<PortalCubit, PortalState>(
            builder: (context, state) {
              final PortalSnapshot? snapshot = state.snapshot;
              final String heading = title(context, snapshot);
              final String? sub = subtitle?.call(context, snapshot);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  root
                      ? N360TopBar(
                          backLabel: l.commonBack,
                          showBack: false,
                          centerTitle: false,
                          title: heading,
                          subtitle: sub,
                          trailing: N360TopTile(
                            icon: Icons.settings_outlined,
                            label: l.shellSettings,
                            onTap: () => openSettings(context),
                          ),
                        )
                      : FieldTopBar(title: heading, subtitle: sub),
                  Expanded(
                    child: LedgerRefresh(
                      onRefresh: () => _refresh(context),
                      semanticsLabel: l.portalLoading,
                      child: ListView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(
                          N360Space.gutter,
                          N360Space.md,
                          N360Space.gutter,
                          N360Space.lg,
                        ),
                        children: _children(context, l, state),
                      ),
                    ),
                  ),
                  ?footer,
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  List<Widget> _children(BuildContext context, AppLocalizations l, PortalState state) {
    Widget empty(String heading, String message) => EmptyLedger(title: heading, message: message);
    final PortalSnapshot? snapshot = state.snapshot;
    if (state.load == PortalLoad.loading) {
      return <Widget>[LedgerSkeletonList(lines: 3, semanticsLabel: l.portalLoading)];
    }
    if (snapshot == null) {
      return <Widget>[
        if (state.refreshing)
          LedgerSkeletonList(lines: 3, semanticsLabel: l.portalLoading)
        else
          empty(l.progressNoDataTitle, l.portalNoDataMessage),
      ];
    }
    if (snapshot.refused) return <Widget>[empty(l.portalRefusedTitle, l.portalRefusedMessage)];
    if (available != null && !available!(snapshot)) {
      return <Widget>[
        if (state.refreshing)
          LedgerSkeletonList(lines: 3, semanticsLabel: l.portalLoading)
        else
          empty(l.portalNotFoundTitle, l.portalNotFoundMessage),
      ];
    }
    return body(context, snapshot);
  }
}

/// Settings is the portal's only other page: language, who is signed in, sign out.
void openSettings(BuildContext context) => context.push(Routes.settings);

/// How long ago this copy was read, in plain words.
String savedAgo(BuildContext context, PortalSnapshot snapshot) {
  final AppLocalizations l = AppLocalizations.of(context);
  final DateTime? at = snapshot.fetchedAt;
  if (at == null) return '';
  return l.portalSavedAgo(AppFormat.ago(l, at, context.read<AppServices>().context.clock.now()));
}

/// Where a contract stands against its date, in words, or null for no date.
String? deadlineWords(BuildContext context, PortalContract contract) {
  final AppLocalizations l = AppLocalizations.of(context);
  final String today = context.read<AppServices>().context.today;
  final String? label = contract.deadlineLabel;
  final int? days = contract.daysLeft(today);
  final String? main;
  if (contract.time == PortalTime.closed) {
    main = l.contractorClosed;
  } else if (contract.time == PortalTime.done) {
    main = l.portalWorkComplete;
  } else if (contract.time == PortalTime.noDate || days == null) {
    main = null;
  } else if (days < 0) {
    main = l.portalOverdueBy(-days);
  } else if (days == 0) {
    main = l.portalDueToday;
  } else {
    main = l.portalDaysLeft(days);
  }
  if (main == null) return null;
  return <String>[main, ?label].join(' · ');
}

/// "Paid 56% · Work done 47%", the one comparison a contractor needs.
String? paidVersusWork(AppLocalizations l, PortalFigures figures) {
  if (figures.paidPct == null || figures.workPct == null) return null;
  return l.portalPaidVsWork(percentText(figures.paidPct), percentText(figures.workPct));
}

/// Work done, then what is paid against it, as the portal's summary shows
/// one contract or all of them.
class PortalFigureCard extends StatelessWidget {
  const PortalFigureCard({super.key, required this.figures, this.heading, this.subheading, this.extra = const <Widget>[]});

  final PortalFigures figures;
  final String? heading;
  final String? subheading;

  /// Rows under the card's own, for the date or a count.
  final List<Widget> extra;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? versus = paidVersusWork(l, figures);
    final List<(String, Money, bool)> rows = <(String, Money, bool)>[
      ?_row(l.contractorValue, figures.contractValue),
      ?_row(l.contractorPaid, figures.paid),
      if (figures.advance != null && !figures.advance!.isZero) (l.contractorAdvance, figures.advance!, false),
      ?_row(l.contractorBalance, figures.balanceMoney, total: true),
      ?_row(l.contractorWorkLeftValue, figures.balanceWorkValue),
    ];
    return LedgerCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (heading != null) ...<Widget>[
            Text(heading!, style: N360Type.fit(N360Type.heading, heading!)),
            if (subheading != null) Text(subheading!, style: N360Type.fit(N360Type.caption, subheading!)),
            const SizedBox(height: N360Space.sm),
          ],
          if (figures.workPct != null) ...<Widget>[
            Row(
              children: <Widget>[
                Expanded(child: Text(l.contractorWorkDone, style: N360Type.fit(N360Type.label, l.contractorWorkDone))),
                Text(percentText(figures.workPct), style: N360Type.figureSmall),
              ],
            ),
            const SizedBox(height: N360Space.xxs),
            HatchedProgressBar(
              fraction: percentFraction(figures.workPct),
              height: 6,
              semanticLabel: '${l.contractorWorkDone}, ${percentText(figures.workPct)}',
            ),
          ],
          if (versus != null) ...<Widget>[
            const SizedBox(height: N360Space.sm),
            Text(versus, style: N360Type.fit(N360Type.body, versus)),
          ],
          if (figures.paidAhead) ...<Widget>[
            const SizedBox(height: N360Space.xxs),
            Align(
              alignment: Alignment.centerLeft,
              child: StatusPill(kind: StampKind.returned, label: l.contractorPaidAhead),
            ),
          ],
          if (rows.isNotEmpty) const SizedBox(height: N360Space.xs),
          for (final (int index, (String label, Money value, bool total)) in rows.indexed)
            BookRow(label: label, value: AppFormat.rupees(value), total: total, ruled: index < rows.length - 1),
          ...extra,
        ],
      ),
    );
  }

  (String, Money, bool)? _row(String label, Money? value, {bool total = false}) =>
      value == null ? null : (label, value, total);
}

/// One payment the company has counted as paid. Not tappable: the portal has
/// no page for a single payment, and everything about it is on the row.
class PortalPaymentTile extends StatelessWidget {
  const PortalPaymentTile({super.key, required this.payment, this.showContract = true});

  final PortalPayment payment;

  /// A list of one contract's payments does not repeat the contract.
  final bool showContract;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String today = context.read<AppServices>().context.today;
    final String? date = payment.paidOn == null ? null : dayLabel(context, payment.paidOn!, today);
    final String mode = payment.mode == null ? '' : l.paymentModeWord(payment.mode!);
    final String modeWord = mode.isNotEmpty && mode != payment.mode ? mode : (payment.modeLabel ?? payment.mode ?? '');
    final String detail = <String>[
      if (payment.number.isNotEmpty) payment.number,
      ?date,
      if (modeWord.isNotEmpty) modeWord,
    ].join(' · ');
    final String contract = <String>[
      if (showContract) ?payment.workOrderNumber,
      if (showContract) ?payment.project,
    ].join(' · ');
    final String? reference = payment.reference == null ? null : '${l.contractorReference}: ${payment.reference}';
    final String? against = payment.against == null ? null : '${l.contractorAgainst}: ${payment.against}';
    final Money? amount = payment.amount;
    final String spoken = <String>[if (amount != null) AppFormat.rupees(amount), detail, contract].join('. ');
    return Semantics(
      key: ValueKey<String>('portal-payment-${payment.id}'),
      container: true,
      label: spoken,
      child: ExcludeSemantics(
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: N360Size.tap),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        detail.isEmpty ? l.portalPaymentsTitle : detail,
                        style: N360Type.fit(N360Type.bodyStrong, detail),
                      ),
                    ),
                    if (amount != null) ...<Widget>[
                      const SizedBox(width: N360Space.sm),
                      Text(AppFormat.rupees(amount), style: N360Type.figureSmall),
                    ],
                  ],
                ),
                if (contract.isNotEmpty) Text(contract, style: N360Type.fit(N360Type.caption, contract)),
                if (reference != null) Text(reference, style: N360Type.fit(N360Type.caption, reference)),
                if (against != null) Text(against, style: N360Type.fit(N360Type.caption, against)),
                if (payment.isAdvance || payment.isReversal) ...<Widget>[
                  const SizedBox(height: N360Space.xs),
                  Wrap(
                    spacing: N360Space.sm,
                    runSpacing: N360Space.xs,
                    children: <Widget>[
                      if (payment.isAdvance) StatusPill(kind: StampKind.draft, label: l.contractorAdvance),
                      if (payment.isReversal) StatusPill(kind: StampKind.returned, label: l.contractorReversal),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A bill as one row: its number, its period, what is payable and where it stands.
class PortalBillTile extends StatelessWidget {
  const PortalBillTile({super.key, required this.bill, required this.onTap, this.showContract = true});

  final PortalBill bill;
  final VoidCallback onTap;
  final bool showContract;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String? period = billPeriod(context, bill);
    final String subtitle = <String>[
      if (showContract) ?bill.project,
      ?period,
    ].join(' · ');
    final Money? net = bill.net;
    return LedgerListTile(
      key: ValueKey<String>('portal-bill-${bill.id}'),
      title: bill.number.isEmpty ? l.portalBillsTitle : bill.number,
      subtitle: subtitle.isEmpty ? null : subtitle,
      status: billPill(l, bill),
      trailing: net == null
          ? null
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 110),
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(AppFormat.rupees(net), style: N360Type.figureSmall),
                  ),
                ),
                const SizedBox(width: N360Space.xxs),
                const Icon(Icons.chevron_right, size: N360IconSize.lg, color: N360Colors.ink3),
              ],
            ),
      onTap: onTap,
    );
  }
}

String? billPeriod(BuildContext context, PortalBill bill) {
  final AppLocalizations l = AppLocalizations.of(context);
  final String? from = bill.periodFrom;
  final String? to = bill.periodTo;
  if (from == null && to == null) return null;
  final String today = context.read<AppServices>().context.today;
  if (from == null || to == null) return dayLabel(context, (from ?? to)!, today);
  return l.portalBillPeriodRange(dayLabel(context, from, today), dayLabel(context, to, today));
}

/// Paid is a word, and approved is a word: the colour only repeats them.
StatusPill billPill(AppLocalizations l, PortalBill bill) => bill.isPaid
    ? StatusPill(kind: StampKind.synced, label: l.contractorPaid)
    : StatusPill(kind: StampKind.approved, label: l.statusApproved);

/// A header over a list.
Widget portalHeader(String text) => Text(text, style: N360Type.fit(N360Type.label, text));
