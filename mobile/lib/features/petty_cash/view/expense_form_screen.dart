import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';

import '../../../core/app_services.dart';
import '../../../core/design/design.dart';
import '../../../core/money/money.dart';
import '../../../l10n/generated/app_localizations.dart';
import '../../attendance/view/widgets/field_kit.dart';
import '../bloc/expense_form_cubit.dart';
import '../data/petty_cash_repository.dart';

/// Amount on the keypad, then what it was for. An expense over the balance
/// stops at the first step, in plain words.
class ExpenseFormScreen extends StatelessWidget {
  const ExpenseFormScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<ExpenseFormCubit>(
      create: (context) => ExpenseFormCubit(context.read<AppServices>()),
      child: const _ExpenseFormView(),
    );
  }
}

class _ExpenseFormView extends StatelessWidget {
  const _ExpenseFormView();

  void _onSaved(BuildContext context, ExpenseFormState state) {
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
    return BlocListener<ExpenseFormCubit, ExpenseFormState>(
      listenWhen: (a, b) => !a.saved && b.saved,
      listener: _onSaved,
      child: Scaffold(
        body: LedgerPaper(
          child: SafeArea(
            bottom: false,
            child: BlocBuilder<ExpenseFormCubit, ExpenseFormState>(
              builder: (context, state) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    FieldTopBar(
                      title: l.pettyCashFormTitle,
                      subtitle: l.pettyCashFormEyebrow,
                      onBack: state.step == 0
                          ? () => Navigator.of(context).maybePop()
                          : context.read<ExpenseFormCubit>().backToAmount,
                    ),
                    Expanded(
                      child: state.step == 0
                          ? _AmountStep(state: state)
                          : _DetailsStep(state: state),
                    ),
                    if (state.step == 1) _SaveBar(state: state),
                  ],
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}

class _AmountStep extends StatelessWidget {
  const _AmountStep({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    final Money? limit = state.available;
    final bool over = state.isOverBalance;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        if (state.wallets.length > 1)
          Padding(
            padding: const EdgeInsets.only(top: N360Space.sm),
            child: _WalletChoice(state: state),
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
              child: LedgerCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Text(l.pettyCashAmount, style: N360Type.heading),
                    FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: RollingMoney(
                        value: formatTypedAmount(state.amountText),
                        style: N360Type.figureHero.copyWith(
                          color: over ? N360Colors.stampRed : N360Colors.ink,
                        ),
                      ),
                    ),
                    const SizedBox(height: N360Space.xs),
                    if (over)
                      _OverBalanceNote(balance: limit!)
                    else if (state.problem == ExpenseProblem.amountRequired)
                      _Warn(text: l.pettyCashAmountRequired)
                    else if (state.balanceAfter != null)
                      Text(
                        l.pettyCashBalanceAfter(state.balanceAfter!.inr()),
                        style: N360Type.body.copyWith(color: N360Colors.ink2),
                      )
                    else if (limit != null)
                      Text(
                        '${l.pettyCashBalance}: ${limit.inr()}',
                        style: N360Type.body.copyWith(color: N360Colors.ink2),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(
            N360Space.gutter,
            0,
            N360Space.gutter,
            N360Space.gutter,
          ),
          child: SafeArea(
            top: false,
            child: GloveKeypad(
              value: state.amountText,
              decimalPlaces: 2,
              maxIntegerDigits: 7,
              onChanged: cubit.setAmount,
              onNext: state.amountReady ? cubit.continueFromAmount : null,
              nextLabel: l.designKeypadNext,
              backspaceLabel: l.designKeypadDelete,
            ),
          ),
        ),
      ],
    );
  }
}

class _OverBalanceNote extends StatelessWidget {
  const _OverBalanceNote({required this.balance});

  final Money balance;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    return _Warn(text: l.pettyCashOverBalance(balance.inr()));
  }
}

class _Warn extends StatelessWidget {
  const _Warn({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Padding(
          padding: EdgeInsets.only(top: 2, right: 6),
          child: Icon(Icons.warning_amber, size: 20, color: N360Colors.stampRed),
        ),
        Expanded(
          child: Text(
            text,
            style: N360Type.bodyStrong.copyWith(color: N360Colors.stampRed),
          ),
        ),
      ],
    );
  }
}

class _WalletChoice extends StatelessWidget {
  const _WalletChoice({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    return SizedBox(
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
    );
  }
}

class _DetailsStep extends StatelessWidget {
  const _DetailsStep({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    final String locale = Localizations.localeOf(context).toString();
    String shortDay(String iso) =>
        DateFormat('d MMM', locale).format(DateTime.parse(iso));
    final Money? amount = state.amount;

    return ListView(
      padding: const EdgeInsets.fromLTRB(
        N360Space.gutter,
        N360Space.md,
        N360Space.gutter,
        N360Space.gutter,
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
        Text(l.pettyCashCategory, style: N360Type.label),
        const SizedBox(height: N360Space.xs),
        PictoChoice<String>(
          columns: 4,
          value: state.category,
          semanticsLabel: l.pettyCashCategory,
          onChanged: cubit.setCategory,
          options: <PictoOption<String>>[
            for (final p in N360Pictograms.expenseCategories)
              PictoOption<String>(
                value: p.key,
                icon: p.icon,
                label: l.pictogramLabel(p),
              ),
          ],
        ),
        if (state.problem == ExpenseProblem.categoryRequired) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          _Warn(text: l.pettyCashCategoryRequired),
        ],
        const SizedBox(height: N360Space.md),
        GloveButton(
          label: state.billPath == null ? l.pettyCashPhotoBill : l.pettyCashBillAdded,
          icon: state.billPath == null ? Icons.photo_camera : Icons.task_alt,
          kind: GloveButtonKind.secondary,
          large: true,
          fullWidth: true,
          onPressed: cubit.takeBillPhoto,
        ),
        if (state.problem == ExpenseProblem.billFailed) ...<Widget>[
          const SizedBox(height: N360Space.xs),
          _Warn(text: l.pettyCashBillFailed),
        ],
        if (state.billPath != null) ...<Widget>[
          Center(
            child: GloveButton(
              label: l.pettyCashBillRetake,
              kind: GloveButtonKind.ghost,
              onPressed: cubit.takeBillPhoto,
            ),
          ),
        ],
        const SizedBox(height: N360Space.md),
        GloveTextField(
          label: l.pettyCashNote,
          hint: l.pettyCashNoteHint,
          onChanged: cubit.setNote,
          textInputAction: TextInputAction.done,
        ),
        const SizedBox(height: N360Space.md),
        Text(l.pettyCashDate, style: N360Type.label),
        const SizedBox(height: N360Space.xs),
        Row(
          children: <Widget>[
            Expanded(
              child: FieldChoiceTile(
                width: double.infinity,
                label: l.pettyCashDayToday,
                detail: shortDay(state.today),
                selected: state.entryDate == state.today,
                onTap: () => cubit.setDate(state.today),
              ),
            ),
            const SizedBox(width: N360Space.sm),
            Expanded(
              child: FieldChoiceTile(
                width: double.infinity,
                label: l.pettyCashDayYesterday,
                detail: shortDay(state.yesterday),
                selected: state.entryDate == state.yesterday,
                onTap: () => cubit.setDate(state.yesterday),
              ),
            ),
          ],
        ),
        if (state.problem == ExpenseProblem.saveFailed) ...<Widget>[
          const SizedBox(height: N360Space.md),
          ErrorSlip(title: l.pettyCashSaveFailed),
        ],
      ],
    );
  }
}

class _SaveBar extends StatelessWidget {
  const _SaveBar({required this.state});

  final ExpenseFormState state;

  @override
  Widget build(BuildContext context) {
    final AppLocalizations l = AppLocalizations.of(context);
    final ExpenseFormCubit cubit = context.read<ExpenseFormCubit>();
    return FieldActionBar(
      child: GloveButton(
        label: l.pettyCashSave,
        icon: Icons.check,
        large: true,
        fullWidth: true,
        loading: state.submitting,
        onPressed: state.submitting ? null : () => _save(cubit),
      ),
    );
  }

  Future<void> _save(ExpenseFormCubit cubit) async {
    final bool saved = await cubit.submit();
    if (!saved) N360Haptics.warning();
  }
}
