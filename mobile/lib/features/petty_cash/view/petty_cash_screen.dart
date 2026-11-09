import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../app/routes.dart';
import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
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
          bottom: false,
          child: BlocBuilder<PettyCashCubit, PettyCashState>(
            builder: (context, state) {
              final AppLocalizations l = AppLocalizations.of(context);
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Expanded(child: _Scroll(state: state)),
                  if (state.load == PettyCashLoad.ready)
                    FieldActionBar(
                      child: GloveButton(
                        label: l.pettyCashAddExpense,
                        icon: Icons.add,
                        large: true,
                        fullWidth: true,
                        onPressed: () => context.push(Routes.expenseForm),
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
  const _Scroll({required this.state});

  final PettyCashState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return CustomScrollView(
      slivers: <Widget>[
        SliverToBoxAdapter(
          child: FieldTopBar(
            title: l.pettyCashTitle,
            subtitle: state.project?.code ?? l.pettyCashEyebrow,
          ),
        ),
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
              N360Space.gutter + 4,
              N360Space.lg,
              N360Space.gutter + 4,
              N360Space.sm,
            ),
            child: Text(l.pettyCashEntries, style: N360Type.heading),
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
          FieldGroupSliver(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            sliver: SliverList.builder(
              itemCount: entries.length,
              itemBuilder: (context, index) {
                final record = entries[index];
                return StaggeredRise(
                  key: ValueKey<String>(record.record.clientUuid),
                  index: index,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (index > 0) const RuledDivider(),
                      _EntryRow(record: record),
                    ],
                  ),
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
    return Padding(
      padding: const EdgeInsets.only(bottom: N360Space.sm),
      child: SizedBox(
        height: 66,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: N360Space.gutter),
          itemCount: state.wallets.length,
          separatorBuilder: (_, _) => const SizedBox(width: N360Space.sm),
          itemBuilder: (context, index) {
            final WalletLine line = state.wallets[index];
            return Center(
              child: FieldChoiceTile(
                label: line.wallet.name,
                detail: line.previewBalance.inr(),
                selected: line.wallet.id == state.selectedWalletId,
                onTap: () => cubit.selectWallet(line.wallet.id),
              ),
            );
          },
        ),
      ),
    );
  }
}

/// The main figure of the screen. While expenses wait on this phone it is
/// plainly provisional: a stamp, what is confirmed, what is not sent yet, and a
/// bar whose hatched part is the money still on its way.
class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.wallet});

  final WalletLine wallet;

  /// A layout value only: how much of the confirmed balance is left once the
  /// unsent expenses count.
  double _remainingShare() {
    final Money confirmed = wallet.serverBalance;
    if (confirmed <= Money.zero) return 0;
    final double share = (wallet.previewBalance.value / confirmed.value).toDouble();
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
                      Text(l.pettyCashBalance, style: N360Type.heading),
                      if (name.isNotEmpty) Text(name, style: N360Type.caption),
                    ],
                  ),
                ),
                if (provisional)
                  RubberStamp(
                    kind: StampKind.queued,
                    label: l.pettyCashProvisional,
                    size: StampSize.medium,
                  )
                else
                  IconTile(
                    icon: Icons.account_balance_wallet_outlined,
                    semanticLabel: l.pettyCashWallet,
                  ),
              ],
            ),
            const SizedBox(height: N360Space.sm),
            Align(
              alignment: Alignment.centerLeft,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: RollingMoney(value: balance.indian(), style: N360Type.figureHero),
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
                height: 36,
                semanticLabel: l.pettyCashFinalAfterSync,
              ),
              const SizedBox(height: N360Space.xs),
              Text(
                l.pettyCashFinalAfterSync,
                style: N360Type.caption.copyWith(color: N360Colors.ink2),
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
      crossAxisAlignment: end ? CrossAxisAlignment.end : CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(label, style: N360Type.caption),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(value, maxLines: 1, style: N360Type.button.copyWith(fontSize: 16)),
        ),
      ],
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
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: <Widget>[
          DecoratedBox(
            decoration: BoxDecoration(
              color: expense ? N360Colors.paper2 : N360Colors.moss,
              borderRadius: N360Radius.md,
            ),
            child: SizedBox.square(
              dimension: 48,
              child: Icon(
                expense ? picture.icon : Icons.south_west,
                size: 24,
                color: expense ? N360Colors.ink : N360Colors.sheet,
              ),
            ),
          ),
          const SizedBox(width: 12),
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
                Text(
                  entry.description == null || entry.description!.isEmpty
                      ? date
                      : '$date · ${entry.description}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: N360Type.caption.copyWith(color: N360Colors.ink2),
                ),
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
                  fontSize: 16,
                  color: expense ? N360Colors.ink : N360Colors.mossInk,
                ),
              ),
              if (entry.hasBill) ...<Widget>[
                const SizedBox(height: 4),
                BillThumbnail(load: cubit.billBytes(entry), size: 44),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
