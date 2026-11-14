import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../core/sync/clock.dart' show dateString;
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/petty_cash_cubit.dart';
import '../data/petty_cash_repository.dart';
import 'widgets/petty_widgets.dart';

class PettyCashScreen extends StatelessWidget {
  const PettyCashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<PettyCashCubit>(
      create: (context) => PettyCashCubit(context.read<AppServices>()),
      child: const _PettyCashView(),
    );
  }
}

class _PettyCashView extends StatelessWidget {
  const _PettyCashView();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: LedgerPaper(
        child: SafeArea(
          top: false,
          bottom: false,
          child: BlocBuilder<PettyCashCubit, PettyCashState>(
            builder: (context, state) {
              final AppLocalizations l = AppLocalizations.of(context);
              final bool canManage = context.read<AppServices>().auth.can(
                'petty_cash.manage',
              );
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  FieldTopBar(
                    title: l.pettyCashTitle,
                    subtitle: state.project?.code ?? l.pettyCashEyebrow,
                  ),
                  Expanded(
                    child: _Scroll(state: state, canManage: canManage),
                  ),
                  if (state.load == PettyCashLoad.ready)
                    FieldActionBar(
                      child: Row(
                        children: <Widget>[
                          Expanded(
                            child: GloveButton(
                              label: l.pettyCashAddExpense,
                              icon: Icons.add,
                              large: true,
                              fullWidth: true,
                              onPressed: () => context.push(Routes.expenseForm),
                            ),
                          ),
                          if (canManage) ...<Widget>[
                            const SizedBox(width: N360Space.sm),
                            FieldSideTile(
                              icon: Icons.savings_outlined,
                              label: l.pettyCashAddMoney,
                              onTap: () => context.push(Routes.topUp),
                            ),
                          ],
                        ],
                      ),
                    )
                  else if (state.load == PettyCashLoad.noWallet && canManage)
                    FieldActionBar(
                      child: GloveButton(
                        label: l.pettyCashCreateWallet,
                        icon: Icons.account_balance_wallet_outlined,
                        large: true,
                        fullWidth: true,
                        onPressed: () => context.push(Routes.walletForm),
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
}

class _Scroll extends StatelessWidget {
  const _Scroll({required this.state, required this.canManage});

  final PettyCashState state;
  final bool canManage;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return CustomScrollView(
      slivers: <Widget>[
        const SliverToBoxAdapter(child: SizedBox(height: N360Space.xs)),
        switch (state.load) {
          PettyCashLoad.loading => SliverToBoxAdapter(
            child: _WalletSkeleton(label: l.pettyCashLoading),
          ),
          PettyCashLoad.noProject => SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.pettyCashNoProjectTitle,
              message: l.pettyCashNoProjectMessage,
              action: GloveButton(
                label: l.pettyCashChooseProject,
                icon: Icons.swap_horiz,
                onPressed: () => context.push(Routes.projectsSwitch),
              ),
            ),
          ),
          PettyCashLoad.noWallet => SliverToBoxAdapter(
            child: EmptyLedger(
              title: canManage
                  ? l.pettyCashNoWalletManageTitle
                  : l.pettyCashNoWalletTitle,
              message: canManage
                  ? l.pettyCashNoWalletManageMessage
                  : l.pettyCashNoWalletMessage,
            ),
          ),
          PettyCashLoad.ready => _Wallet(state: state),
        },
      ],
    );
  }
}

class _Wallet extends StatelessWidget {
  const _Wallet({required this.state});

  final PettyCashState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final WalletLine? wallet = state.selected;
    if (wallet == null) {
      return SliverToBoxAdapter(
        child: _WalletSkeleton(label: l.pettyCashLoading),
      );
    }
    final List<_EntryDay> days = _groupByDay(state.entries);

    return SliverMainAxisGroup(
      slivers: <Widget>[
        if (state.wallets.length > 1)
          SliverToBoxAdapter(child: _WalletSwitcher(state: state)),
        SliverToBoxAdapter(child: _BalanceCard(wallet: wallet)),
        if (days.isEmpty)
          SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.pettyCashEntriesEmptyTitle,
              message: l.pettyCashEntriesEmptyMessage,
            ),
          )
        else
          // One list per day, built as it scrolls into view, so a long
          // ledger reads bills only for the days on screen.
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.lg,
              N360Space.gutter,
              N360Space.lg,
            ),
            sliver: SliverList.separated(
              itemCount: days.length,
              separatorBuilder: (_, _) =>
                  const SizedBox(height: N360Space.cardGap),
              itemBuilder: (context, index) => StaggeredRise(
                key: ValueKey<String>(days[index].date),
                index: index,
                child: _EntryDayList(day: days[index]),
              ),
            ),
          ),
      ],
    );
  }
}

/// The entries of one day, newest day first as the cubit hands them over.
class _EntryDay {
  const _EntryDay({required this.date, required this.entries});

  final String date;
  final List<SyncedRecord<PettyCashEntryRow>> entries;
}

List<_EntryDay> _groupByDay(List<SyncedRecord<PettyCashEntryRow>> entries) {
  final Map<String, List<SyncedRecord<PettyCashEntryRow>>> byDate =
      <String, List<SyncedRecord<PettyCashEntryRow>>>{};
  for (final record in entries) {
    byDate
        .putIfAbsent(
          record.record.entryDate,
          () => <SyncedRecord<PettyCashEntryRow>>[],
        )
        .add(record);
  }
  return <_EntryDay>[
    for (final entry in byDate.entries)
      _EntryDay(date: entry.key, entries: entry.value),
  ];
}

class _WalletSwitcher extends StatelessWidget {
  const _WalletSwitcher({required this.state});

  final PettyCashState state;

  @override
  Widget build(BuildContext context) {
    final PettyCashCubit cubit = context.read<PettyCashCubit>();
    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.sm),
      child: FieldChoiceStrip(
        itemCount: state.wallets.length,
        itemBuilder: (context, index) {
          final WalletLine line = state.wallets[index];
          return N360Chip(
            label: line.wallet.name,
            count: line.previewBalance.inr(),
            selected: line.wallet.id == state.selectedWalletId,
            onTap: () => cubit.selectWallet(line.wallet.id),
          );
        },
      ),
    );
  }
}

/// The wallet's shape while it loads: the balance card, then a few entries.
class _WalletSkeleton extends StatelessWidget {
  const _WalletSkeleton({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          LedgerSkeleton(figure: true, lines: 2, semanticsLabel: label),
          const SizedBox(height: N360Space.cardGap),
          const ExcludeSemantics(child: LedgerSkeletonList(rows: 3, lines: 1)),
        ],
      ),
    );
  }
}

/// The main figure of the screen. While expenses wait on this phone it is
/// plainly provisional: a pill, what is confirmed, what is not sent yet, and a
/// thin bar for how much of the confirmed balance is left.
class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.wallet});

  final WalletLine wallet;

  /// A layout value only: how much of the confirmed balance is left once the
  /// unsent expenses count.
  double _remainingShare() {
    final Money confirmed = wallet.serverBalance;
    if (confirmed <= Money.zero) return 0;
    final double share = (wallet.previewBalance.value / confirmed.value)
        .toDouble();
    return share.clamp(0.0, 1.0);
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Money balance = wallet.previewBalance;
    final bool provisional = !wallet.unsentExpenses.isZero;
    final String name = wallet.wallet.name;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: LedgerCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      Text(
                        l.pettyCashBalance,
                        style: N360Type.fit(
                          N360Type.heading,
                          l.pettyCashBalance,
                        ),
                      ),
                      if (name.isNotEmpty)
                        Text(name, style: N360Type.fit(N360Type.caption, name)),
                    ],
                  ),
                ),
                // A word, never a bare picture: the figure below is either
                // final or it says so here.
                if (provisional)
                  StatusPill(
                    kind: StampKind.queued,
                    label: l.pettyCashProvisional,
                  ),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            Align(
              alignment: Alignment.centerLeft,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: RollingMoney(
                  value: balance.indian(),
                  style: N360Type.figureHero,
                  animateEntry: true,
                ),
              ),
            ),
            if (provisional) ...<Widget>[
              const SizedBox(height: N360Space.sm),
              Row(
                children: <Widget>[
                  Expanded(
                    child: _Figure(
                      label: l.pettyCashConfirmed,
                      value: wallet.serverBalance.inr(),
                    ),
                  ),
                  Expanded(
                    child: _Figure(
                      label: l.pettyCashUnsent,
                      value: wallet.unsentExpenses.inr(),
                      end: true,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: N360Space.xs),
              HatchedProgressBar(
                fraction: _remainingShare(),
                semanticLabel: l.pettyCashFinalAfterSync,
              ),
              const SizedBox(height: N360Space.xs),
              Text(
                l.pettyCashFinalAfterSync,
                style: N360Type.fit(
                  N360Type.caption,
                  l.pettyCashFinalAfterSync,
                ).copyWith(color: N360Colors.ink2),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, this.end = false});

  final String label;
  final String value;

  /// Right-aligned, for the second column.
  final bool end;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: end
          ? CrossAxisAlignment.end
          : CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.fit(N360Type.caption, label)),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(value, maxLines: 1, style: N360Type.figure),
        ),
      ],
    );
  }
}

/// One day as a [LedgerList]: the day and how many entries it holds, then a
/// row per entry with a hairline between rows.
class _EntryDayList extends StatelessWidget {
  const _EntryDayList({required this.day});

  final _EntryDay day;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final String locale = Localizations.localeOf(context).toString();
    final String today = context.read<AppServices>().context.today;
    final DateTime date = DateTime.parse(day.date);
    final String short = DateFormat('d MMM', locale).format(date);
    final String yesterday = dateString(
      DateTime.parse(today).subtract(const Duration(days: 1)),
    );
    final String heading = day.date == today
        ? '${l.pettyCashDayToday} · $short'
        : day.date == yesterday
        ? '${l.pettyCashDayYesterday} · $short'
        : short;

    return LedgerList(
      header: Row(
        children: <Widget>[
          Expanded(
            child: Semantics(
              header: true,
              child: Text(
                heading,
                style: N360Type.fit(N360Type.heading, heading),
              ),
            ),
          ),
          Text(
            '${day.entries.length}',
            style: N360Type.figureSmall.copyWith(color: N360Colors.ink2),
          ),
        ],
      ),
      children: <Widget>[
        for (final record in day.entries)
          _EntryTile(
            key: ValueKey<String>(record.record.clientUuid),
            record: record,
          ),
      ],
    );
  }
}

/// One entry: what it was for, the note on one caption line, a pill while it
/// is not yet with the office, and the amount.
class _EntryTile extends StatelessWidget {
  const _EntryTile({super.key, required this.record});

  final SyncedRecord<PettyCashEntryRow> record;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final PettyCashCubit cubit = context.read<PettyCashCubit>();
    final PettyCashEntryRow entry = record.record;
    final bool expense = entry.type == 'expense';
    final Pictogram picture = N360Pictograms.expense(entry.category ?? 'Other');
    final String title = expense ? l.pictogramLabel(picture) : l.pettyCashTopup;
    final String? note = entry.description;

    return LedgerListTile(
      leading: Icon(
        expense ? picture.icon : Icons.south_west,
        size: N360IconSize.lg,
        color: expense ? N360Colors.ink2 : N360Colors.mossInk,
      ),
      title: title,
      subtitle: note == null || note.isEmpty ? null : note,
      status: record.syncState == LocalSyncState.synced
          ? null
          : PettySyncStamp(state: record.syncState),
      trailing: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text(
            expense ? entry.amount.inr() : '+${entry.amount.inr()}',
            style: N360Type.figure.copyWith(
              color: expense ? N360Colors.ink : N360Colors.mossInk,
            ),
          ),
          if (entry.hasBill) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            BillThumbnail(load: cubit.billBytes(entry), size: N360Size.tile),
          ],
        ],
      ),
    );
  }
}
