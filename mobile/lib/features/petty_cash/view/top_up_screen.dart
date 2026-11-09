import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/top_up_cubit.dart';
import '../data/petty_cash_repository.dart';
import 'widgets/petty_widgets.dart';

/// Records money put into a wallet: amount on the keypad, then note and date.
/// Works offline and says so.
class TopUpScreen extends StatelessWidget {
  const TopUpScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<TopUpCubit>(
      create: (context) => TopUpCubit(context.read<AppServices>()),
      child: const _TopUpView(),
    );
  }
}

class _TopUpView extends StatelessWidget {
  const _TopUpView();

  void _onSaved(BuildContext context, TopUpState state) {
    final AppLocalizations l = AppLocalizations.of(context);
    N360Haptics.success();
    showPaperToast(
      context,
      state.savedWhileOffline ? l.pettyCashSavedOffline : l.pettyCashSavedOnline,
      kind: PaperToastKind.success,
      icon: state.savedWhileOffline ? Icons.phone_android : Icons.task_alt,
      duration: const Duration(milliseconds: 3200),
    );
    Navigator.of(context).maybePop();
  }

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return BlocConsumer<TopUpCubit, TopUpState>(
      listenWhen: (a, b) => !a.saved && b.saved,
      listener: _onSaved,
      builder: (context, state) {
        final TopUpCubit cubit = context.read<TopUpCubit>();
        return PopScope(
          canPop: state.step == 0,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop) cubit.backToAmount();
          },
          child: Scaffold(
            body: LedgerPaper(
              child: SafeArea(
                bottom: false,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    FieldTopBar(
                      title: l.pettyCashAddMoney,
                      subtitle: l.pettyCashTopUpEyebrow,
                      onBack: state.step == 0 ? null : cubit.backToAmount,
                    ),
                    Expanded(
                      child: state.step == 0
                          ? _AmountStep(state: state)
                          : _DetailsStep(state: state),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _AmountStep extends StatelessWidget {
  const _AmountStep({required this.state});

  final TopUpState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final TopUpCubit cubit = context.read<TopUpCubit>();
    final Money? balance = state.wallet?.previewBalance;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (state.wallets.length > 1)
          SizedBox(
            height: 68,
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
                    selected: line.wallet.id == state.walletId,
                    onTap: () => cubit.setWallet(line.wallet.id),
                  ),
                );
              },
            ),
          ),
        Expanded(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(
                N360Space.gutter,
                N360Space.xs,
                N360Space.gutter,
                N360Space.sm,
              ),
              child: PettyAmountCard(
                label: l.pettyCashAmount,
                typed: state.amountText,
                footer: state.problem == TopUpProblem.amountRequired
                    ? FieldInlineError(l.pettyCashAmountRequired)
                    : balance == null
                    ? null
                    : Text(
                        '${l.pettyCashBalance}: ${balance.inr()}',
                        style: N360Type.body.copyWith(color: N360Colors.ink2),
                      ),
              ),
            ),
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              0,
              N360Space.gutter,
              N360Space.gutter,
            ),
            child: GloveKeypad(
              value: state.amountText,
              decimalPlaces: 2,
              maxIntegerDigits: 7,
              onChanged: cubit.setAmount,
              onNext: state.amountReady ? cubit.next : null,
              nextLabel: l.designKeypadNext,
              backspaceLabel: l.designKeypadDelete,
            ),
          ),
        ),
      ],
    );
  }
}

class _DetailsStep extends StatelessWidget {
  const _DetailsStep({required this.state});

  final TopUpState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final TopUpCubit cubit = context.read<TopUpCubit>();
    final Money? amount = state.amount;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(
              N360Space.gutter,
              N360Space.xs,
              N360Space.gutter,
              N360Space.sm,
            ),
            children: <Widget>[
              LedgerCard(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                onTap: cubit.backToAmount,
                semanticsLabel: '${l.pettyCashAmount} ${amount?.inr() ?? ''}. ${l.commonBack}',
                child: ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 44),
                  child: Row(
                    children: <Widget>[
                      Text(l.pettyCashAmount, style: N360Type.heading),
                      const SizedBox(width: N360Space.sm),
                      Expanded(
                        child: Align(
                          alignment: Alignment.centerRight,
                          child: FittedBox(
                            fit: BoxFit.scaleDown,
                            child: RollingMoney(
                              value: amount == null ? '0.00' : amount.indian(),
                              style: N360Type.figureLarge,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: N360Space.md),
              LedgerCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    GloveTextField(
                      label: l.pettyCashNote,
                      hint: l.pettyCashTopUpNoteHint,
                      onChanged: cubit.setNote,
                      textInputAction: TextInputAction.done,
                    ),
                    const SizedBox(height: N360Space.md),
                    Text(l.pettyCashDate, style: N360Type.label),
                    const SizedBox(height: N360Space.xs),
                    PettyDateChoice(
                      today: state.today,
                      yesterday: state.yesterday,
                      value: state.entryDate,
                      onChanged: cubit.setDate,
                    ),
                  ],
                ),
              ),
              if (state.problem == TopUpProblem.failed) ...<Widget>[
                const SizedBox(height: N360Space.md),
                ErrorSlip(title: l.pettyCashTopUpFailed),
              ],
            ],
          ),
        ),
        FieldActionBar(
          child: GloveButton(
            label: l.pettyCashTopUpSave,
            icon: Icons.check,
            large: true,
            fullWidth: true,
            loading: state.saving,
            onPressed: state.saving ? null : cubit.submit,
          ),
        ),
      ],
    );
  }
}
