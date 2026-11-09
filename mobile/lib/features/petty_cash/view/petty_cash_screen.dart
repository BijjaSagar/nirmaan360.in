import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
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
          bottom: false,
          child: BlocBuilder<PettyCashCubit, PettyCashState>(
            builder: (context, state) {
              final AppLocalizations l = AppLocalizations.of(context);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Expanded(child: _Scroll(state: state)),
                  if (state.load == PettyCashLoad.ready)
                    _AddBar(label: l.pettyCashAddExpense),
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
  const _Scroll({required this.state});

  final PettyCashState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return CustomScrollView(
      slivers: <Widget>[
        SliverToBoxAdapter(child: _Header(state: state)),
        switch (state.load) {
          PettyCashLoad.loading => const SliverToBoxAdapter(child: SizedBox(height: 160)),
          PettyCashLoad.noProject => SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.pettyCashNoProjectTitle,
              message: l.pettyCashNoProjectMessage,
            ),
          ),
          PettyCashLoad.noWallet => SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.pettyCashNoWalletTitle,
              message: l.pettyCashNoWalletMessage,
            ),
          ),
          PettyCashLoad.ready => _Wallet(state: state),
        },
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.state});

  final PettyCashState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.xs,
        N360Space.gutter,
        N360Space.sm,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          if (Navigator.of(context).canPop())
            Align(
              alignment: Alignment.centerLeft,
              child: GloveButton(
                label: l.commonBack,
                kind: GloveButtonKind.ghost,
                icon: Icons.arrow_back,
                onPressed: () => Navigator.of(context).maybePop(),
              ),
            ),
          SheetHeader(
            eyebrow: state.project?.code ?? l.pettyCashEyebrow,
            title: l.pettyCashTitle,
          ),
        ],
      ),
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
      return const SliverToBoxAdapter(child: SizedBox(height: 120));
    }
    final entries = state.entries;

    return SliverMainAxisGroup(
      slivers: <Widget>[
        if (state.wallets.length > 1)
          SliverToBoxAdapter(child: _WalletSwitcher(state: state)),
        SliverToBoxAdapter(child: _BalanceCard(wallet: wallet)),
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.lg,
              N360Space.gutter,
              N360Space.xs,
            ),
            child: SectionLabel(l.pettyCashEntries),
          ),
        ),
        if (entries.isEmpty)
          SliverToBoxAdapter(
            child: EmptyLedger(
              title: l.pettyCashEntriesEmptyTitle,
              message: l.pettyCashEntriesEmptyMessage,
            ),
          )
        else
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              0,
              N360Space.gutter,
              N360Space.xl,
            ),
            sliver: SliverList.builder(
              itemCount: entries.length,
              itemBuilder: (context, index) {
                final record = entries[index];
                return StaggeredRise(
                  key: ValueKey<String>(record.record.clientUuid),
                  index: index,
                  child: _EntryRow(record: record),
                );
              },
            ),
          ),
      ],
    );
  }
}

class _WalletSwitcher extends StatelessWidget {
  const _WalletSwitcher({required this.state});

  final PettyCashState state;

  @override
  Widget build(BuildContext context) {
    final PettyCashCubit cubit = context.read<PettyCashCubit>();
    return SizedBox(
      height: 72,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
        itemCount: state.wallets.length,
        separatorBuilder: (_, _) => const SizedBox(width: N360Space.sm),
        itemBuilder: (context, index) {
          final WalletLine line = state.wallets[index];
          return Center(
            child: PettyChoiceTile(
              label: line.wallet.name,
              detail: line.previewBalance.inr(),
              selected: line.wallet.id == state.selectedWalletId,
              onTap: () => cubit.selectWallet(line.wallet.id),
            ),
          );
        },
      ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.wallet});

  final WalletLine wallet;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final Money balance = wallet.previewBalance;
    final bool provisional = !wallet.unsentExpenses.isZero;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
      child: LedgerCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Text(
                    wallet.wallet.name.isEmpty
                        ? l.pettyCashBalance
                        : '${l.pettyCashBalance} · ${wallet.wallet.name}',
                    style: N360Type.label,
                  ),
                ),
                if (provisional)
                  RubberStamp(
                    kind: StampKind.queued,
                    label: l.pettyCashProvisional,
                    size: StampSize.small,
                  ),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            Align(
              alignment: Alignment.centerLeft,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: RollingMoney(
                  value: balance.indian(),
                  style: N360Type.figureHero.copyWith(fontSize: 42),
                ),
              ),
            ),
            if (provisional) ...<Widget>[
              const SizedBox(height: N360Space.xs),
              Text(
                l.pettyCashProvisionalNote(wallet.unsentExpenses.inr()),
                style: N360Type.caption.copyWith(color: N360Colors.ink2),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _EntryRow extends StatelessWidget {
  const _EntryRow({required this.record});

  final SyncedRecord<PettyCashEntryRow> record;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final PettyCashCubit cubit = context.read<PettyCashCubit>();
    final PettyCashEntryRow entry = record.record;
    final bool expense = entry.type == 'expense';
    final Pictogram picture = N360Pictograms.expense(entry.category ?? 'Other');
    final String title = expense ? l.pictogramLabel(picture) : l.pettyCashTopup;
    final String locale = Localizations.localeOf(context).toString();
    final String date = DateFormat('d MMM', locale).format(DateTime.parse(entry.entryDate));

    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.sm),
      child: LedgerCard(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: <Widget>[
            DecoratedBox(
              decoration: BoxDecoration(
                color: expense ? N360Colors.paper2 : N360Colors.moss,
                borderRadius: N360Radius.sm,
              ),
              child: SizedBox.square(
                dimension: 44,
                child: Icon(
                  expense ? picture.icon : Icons.south_west,
                  size: 24,
                  color: expense ? N360Colors.ink : N360Colors.sheet,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: N360Type.bodyStrong.copyWith(fontSize: 17, height: 1.25),
                  ),
                  if (entry.description != null && entry.description!.isNotEmpty)
                    Text(
                      entry.description!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: N360Type.caption.copyWith(color: N360Colors.ink2),
                    ),
                  Text(date, style: N360Type.figureSmall.copyWith(fontSize: 13)),
                  if (record.syncState != LocalSyncState.synced) ...<Widget>[
                    const SizedBox(height: 3),
                    PettySyncStamp(state: record.syncState),
                  ],
                ],
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Column(
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
                  const SizedBox(height: 4),
                  BillThumbnail(
                    load: cubit.billBytes(entry),
                    size: 44,
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _AddBar extends StatelessWidget {
  const _AddBar({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: N360Colors.sheet,
        border: Border(top: BorderSide(color: N360Colors.ruleStrong)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            N360Space.sm,
            N360Space.gutter,
            N360Space.sm,
          ),
          child: GloveButton(
            label: label,
            icon: Icons.add,
            large: true,
            fullWidth: true,
            onPressed: () => context.push(Routes.expenseForm),
          ),
        ),
      ),
    );
  }
}
